<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Shift;
use App\Models\ShiftChecklistItem;
use App\Models\ShiftChecklistResult;
use App\Models\User;
use App\Services\ClubTelegramNotifier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Смены менеджеров и чек-листы к ним.
 *
 * Галочка в чек-листе означает «проверил», а не «всё в порядке»: открыть
 * смену можно только отметив все пункты, а о проблемах менеджер пишет в
 * комментарии. Иначе половина пунктов остаётся пустой без объяснений и
 * админ не понимает, проверяли их или забыли.
 */
class ShiftService
{
    /**
     * Открытая смена менеджера в этом клубе, если есть.
     * Смены персональные: чужая открытая смена своей не считается.
     */
    public function currentShift(Club $club, User $user): ?Shift
    {
        return Shift::open()
            ->where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->orderByDesc('opened_at')
            ->first();
    }

    /** Нужно ли гнать менеджера на чек-лист: смены нет, а пункты заданы. */
    public function needsOpening(Club $club, User $user): bool
    {
        if ($this->currentShift($club, $user)) {
            return false;
        }

        // Клуб не завёл пунктов — не блокируем работу.
        return ShiftChecklistItem::forChecklist($club->id, 'opening')->exists();
    }

    /**
     * Открыть смену.
     *
     * @param  array<int, array{done?: bool, comment?: string|null}> $marks отметки по id пункта
     *
     * @throws RuntimeException если смена уже идёт или отмечены не все пункты.
     */
    public function open(Club $club, User $user, array $marks): Shift
    {
        if ($this->currentShift($club, $user)) {
            throw new RuntimeException('Смена уже открыта');
        }

        $items = ShiftChecklistItem::forChecklist($club->id, 'opening')->get();
        $this->assertAllChecked($items, $marks);

        $shift = DB::transaction(function () use ($club, $user, $items, $marks) {
            $shift = Shift::create([
                'club_id' => $club->id,
                'user_id' => $user->id,
                'opened_at' => now(),
            ]);

            $this->saveResults($shift, $items, $marks, 'opening');

            return $shift;
        });

        $this->notify($shift, $club, $user, 'opening');

        return $shift;
    }

    /**
     * Закрыть смену.
     *
     * @param  array<int, array{done?: bool, comment?: string|null}> $marks
     *
     * @throws RuntimeException если смена уже закрыта или отмечены не все пункты.
     */
    public function close(Shift $shift, array $marks): void
    {
        if (!$shift->isOpen()) {
            throw new RuntimeException('Смена уже закрыта');
        }

        $items = ShiftChecklistItem::forChecklist($shift->club_id, 'closing')->get();
        $this->assertAllChecked($items, $marks);

        DB::transaction(function () use ($shift, $items, $marks) {
            $this->saveResults($shift, $items, $marks, 'closing');
            $shift->update(['closed_at' => now()]);
        });

        $shift->loadMissing(['club', 'user']);
        $this->notify($shift, $shift->club, $shift->user, 'closing');
    }

    /**
     * Сообщить клубу в Telegram, что смена открылась или закрылась.
     *
     * Замечания идут прямо в сообщение: ради них админ журнал и открывает,
     * а так узнает о проблеме сразу, никуда не заходя.
     */
    private function notify(Shift $shift, ?Club $club, ?User $user, string $type): void
    {
        if (!$club || !$club->telegramNotifyReady()) {
            return;
        }

        $isOpening = $type === 'opening';
        $rows = $shift->results()->where('type', $type)->get();
        $done = $rows->where('is_done', true)->count();

        $lines = [
            $isOpening ? '🌅 <b>Смена открыта</b>' : '🌙 <b>Смена закрыта</b>',
            e($club->name),
            'Менеджер: ' . e($user?->name ?? '—'),
        ];

        if ($isOpening) {
            $lines[] = 'Время: ' . $shift->openedAtLocal()->format('d.m.Y, H:i');
        } else {
            $minutes = (int) ($shift->durationMinutes() ?? 0);
            $lines[] = 'Смена: ' . $shift->openedAtLocal()->format('H:i')
                . ' — ' . $shift->closedAtLocal()?->format('H:i')
                . ' (' . intdiv($minutes, 60) . ' ч ' . $minutes % 60 . ' мин)';
        }

        $lines[] = 'Чек-лист: ' . $done . ' из ' . $rows->count();

        $withComment = $rows->filter(fn ($r) => filled($r->comment));
        if ($withComment->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '⚠️ <b>Замечания:</b>';
            foreach ($withComment as $row) {
                $lines[] = '• ' . e($row->title_snapshot) . ' — ' . e($row->comment);
            }
        }

        ClubTelegramNotifier::send($club, implode("\n", $lines));
    }

    /**
     * Все пункты должны быть отмечены.
     *
     * @throws RuntimeException с перечнем пропущенного — менеджеру нужно
     *                          понимать, что именно осталось.
     */
    private function assertAllChecked($items, array $marks): void
    {
        $missed = [];
        foreach ($items as $item) {
            if (empty($marks[$item->id]['done'])) {
                $missed[] = $item->title;
            }
        }

        if ($missed) {
            throw new RuntimeException(
                'Отметьте все пункты. Не отмечено: ' . implode(', ', $missed)
            );
        }
    }

    /**
     * Записать отметки со снимком текста: админ переформулирует пункт —
     * история прошлых смен должна остаться прежней.
     */
    private function saveResults(Shift $shift, $items, array $marks, string $type): void
    {
        foreach ($items as $item) {
            $comment = trim((string) ($marks[$item->id]['comment'] ?? ''));

            ShiftChecklistResult::create([
                'shift_id' => $shift->id,
                'item_id' => $item->id,
                'type' => $type,
                'title_snapshot' => $item->title,
                'is_done' => !empty($marks[$item->id]['done']),
                'comment' => $comment !== '' ? $comment : null,
            ]);
        }
    }
}

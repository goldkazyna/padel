<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Tournament;
use App\Models\TournamentPayment;
use App\Models\TournamentRegistrationLog;
use App\Models\TournamentSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Оплата участия в турнире: ссылка Plexy → деньги → место в турнире.
 *
 * Модерация тут не нужна: клуб уже получил деньги, и держать оплатившего
 * в «заявках» — верный способ получить возврат. Поэтому после успешной
 * оплаты участник попадает сразу в основной список.
 */
class TournamentPaymentService
{
    /**
     * Начать оплату: проверить, что человека вообще можно записать, и
     * создать ссылку. Место держится, пока ссылка жива (HOLD_MINUTES).
     *
     * @throws RuntimeException с текстом для игрока
     */
    public function start(Tournament $tournament, User $user, ?User $friend = null): TournamentPayment
    {
        $club = $tournament->club;

        if (!$tournament->requiresOnlinePayment() || !$club) {
            throw new RuntimeException('Для этого турнира онлайн-оплата не нужна');
        }

        if ($tournament->status !== 'open') {
            throw new RuntimeException('Турнир не открыт для регистрации');
        }

        $this->assertEligible($tournament, $user);
        if ($friend) {
            if ($friend->id === $user->id) {
                throw new RuntimeException('Нельзя записать самого себя как друга');
            }
            $this->assertEligible($tournament, $friend, true);
        }

        $players = $friend ? 2 : 1;

        // Незавершённый платёж того же человека переиспользовать нельзя:
        // сумма могла измениться, ссылка протухнуть. Гасим и делаем новый.
        TournamentPayment::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->where('status', TournamentPayment::STATUS_PENDING)
            ->update(['status' => TournamentPayment::STATUS_CANCELLED]);

        $amount = round((float) $tournament->price * $players, 2);
        $expiresAt = now()->addMinutes(TournamentPayment::HOLD_MINUTES);

        $payment = DB::transaction(function () use ($tournament, $user, $friend, $players, $amount, $expiresAt) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            // Считаем места под замком: две одновременные оплаты последнего
            // места не должны обе получить ссылку.
            if ($tournament->takenSlotsCount() + $players > $tournament->max_participants) {
                throw new RuntimeException($players === 2 ? 'Не хватает мест для двоих' : 'Все места заняты');
            }

            return TournamentPayment::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'friend_user_id' => $friend?->id,
                'players_count' => $players,
                'amount' => $amount,
                'status' => TournamentPayment::STATUS_PENDING,
                'expires_at' => $expiresAt,
            ]);
        });

        try {
            $link = (new PlexyService($club->plexyApiKey()))->createPaymentLink(
                // Plexy принимает сумму в тиынах: 14 000 ₸ → 1 400 000.
                amount: (int) round($amount * 100),
                description: mb_substr('Турнир: ' . $tournament->name, 0, 200),
                orderReference: $payment->orderReference(),
                expiresAt: $expiresAt,
                metadata: [
                    'tournament_payment_id' => $payment->id,
                    'tournament_id' => $tournament->id,
                    'club_id' => $club->id,
                ],
            );
        } catch (\Throwable $e) {
            // Ссылки нет — место держать не за что.
            $payment->update(['status' => TournamentPayment::STATUS_FAILED]);
            Log::error('Оплата турнира: не удалось создать ссылку', [
                'tournament' => $tournament->id, 'user' => $user->id, 'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Не удалось открыть оплату. Попробуйте ещё раз');
        }

        $payment->update([
            'plexy_link_id' => $link['id'] ?? null,
            'plexy_url' => $link['url'] ?? null,
        ]);

        return $payment->fresh();
    }

    /**
     * Деньги пришли — сажаем в турнир.
     *
     * Идемпотентно: вебхук и опрос статуса из приложения приходят наперегонки,
     * и второй вызов не должен ни записать второй раз, ни слать пуш заново.
     */
    public function complete(TournamentPayment $payment): void
    {
        if ($payment->isPaid()) {
            return;
        }

        $tournament = $payment->tournament;
        $user = $payment->user;
        $friend = $payment->friend;

        if (!$tournament || !$user) {
            Log::warning('Оплата турнира: нет турнира или игрока', ['payment' => $payment->id]);
            return;
        }

        DB::transaction(function () use ($payment, $tournament, $user, $friend) {
            $payment->update([
                'status' => TournamentPayment::STATUS_PAID,
                'paid_at' => now(),
            ]);

            foreach (array_filter([$user, $friend]) as $player) {
                // Отменённая раньше запись мешает attach: уникальный индекс.
                $tournament->participants()->wherePivot('status', 'cancelled')->detach($player->id);

                $already = $tournament->participants()
                    ->wherePivotIn('status', ['registered', 'pending', 'waiting'])
                    ->where('user_id', $player->id)
                    ->exists();

                if ($already) {
                    // Успел попасть в турнир другим путём (клуб добавил руками)
                    // — поднимаем в основной список, деньги уже получены.
                    $tournament->participants()->updateExistingPivot($player->id, [
                        'status' => 'registered',
                        'moderation_deadline' => null,
                    ]);
                    continue;
                }

                // Оплата и есть подтверждение — сразу в основной список.
                $tournament->participants()->attach($player->id, ['status' => 'registered']);
                TournamentRegistrationLog::record($tournament->id, $player->id, 'registered');
            }
        });

        TournamentSubscription::where('tournament_id', $tournament->id)
            ->whereIn('user_id', array_filter([$user->id, $friend?->id]))
            ->delete();

        if ($friend) {
            $date = $tournament->start_date->format('d.m.Y H:i');
            Notification::create([
                'user_id' => $friend->id,
                'title' => 'Вас записали на турнир',
                'body' => "{$user->name} записал(а) вас на «{$tournament->name}» — {$date}. Участие оплачено.",
                'type' => 'registered_by_friend',
                'category' => 'tournament',
                'data' => ['tournament_id' => $tournament->id],
            ]);
        }

        Log::info('Оплата турнира: игрок в основном списке', [
            'payment' => $payment->id,
            'tournament' => $tournament->id,
            'players' => $payment->players_count,
        ]);
    }

    /**
     * Спросить Plexy о состоянии платежа — приложение опрашивает статус
     * само и не ждёт вебхук. Возвращает true, если оплачено.
     */
    public function sync(TournamentPayment $payment): bool
    {
        if ($payment->isPaid()) {
            return true;
        }

        $key = $payment->tournament?->club?->plexyApiKey();
        if (!$key || !$payment->plexy_link_id) {
            return false;
        }

        try {
            $info = (new PlexyService($key))->getPaymentLink($payment->plexy_link_id);
        } catch (\Throwable $e) {
            Log::warning('Оплата турнира: не удалось узнать статус', [
                'payment' => $payment->id, 'error' => $e->getMessage(),
            ]);
            return false;
        }

        $status = strtolower((string) ($info['status'] ?? ''));

        if (in_array($status, ['paid', 'charged', 'authorized', 'success', 'completed'], true)) {
            $this->complete($payment);
            return true;
        }

        if ($status === 'expired' && $payment->status === TournamentPayment::STATUS_PENDING) {
            $payment->update(['status' => TournamentPayment::STATUS_EXPIRED]);
        }

        return false;
    }

    /**
     * Можно ли вообще записать этого игрока.
     *
     * @throws RuntimeException
     */
    private function assertEligible(Tournament $tournament, User $player, bool $isFriend = false): void
    {
        if ($tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending', 'waiting'])
            ->where('user_id', $player->id)->exists()) {
            throw new RuntimeException($isFriend
                ? "{$player->name} уже записан на этот турнир"
                : 'Вы уже записаны на этот турнир');
        }

        if ($tournament->verified_only && !$player->level_verified) {
            throw new RuntimeException($isFriend
                ? "{$player->name} не верифицирован, а турнир только для верифицированных"
                : 'Турнир только для верифицированных игроков');
        }

        if ($player->level < $tournament->min_level || $player->level > $tournament->max_level) {
            throw new RuntimeException($isFriend
                ? "Уровень {$player->name} ({$player->level}) не подходит. Требуется: {$tournament->min_level} – {$tournament->max_level}"
                : "Ваш уровень ({$player->level}) не подходит. Требуется: {$tournament->min_level} – {$tournament->max_level}");
        }
    }
}

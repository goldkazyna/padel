<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Tournament;
use App\Models\User;

/**
 * Рассылка push-уведомления «Новый турнир!» всем подходящим пользователям.
 * Единая логика для веба (Club\TournamentController) и мобильного API
 * (MobileAdminTournamentDetailController) — чтобы поведение не расходилось.
 */
class TournamentPushService
{
    /**
     * Сколько раз можно разослать пуш по одному турниру.
     *
     * Организаторы жали колокольчик по многу раз, и одно и то же
     * объявление прилетало людям снова и снова. Лимит живёт здесь, а не
     * в контроллере, чтобы действовал и в вебе, и в мобильной админке —
     * они шлют одним и тем же методом.
     */
    public const MAX_SENDS = 3;

    /** Сколько рассылок по турниру уже сделано. */
    public function sentCount(Tournament $tournament): int
    {
        return (int) ($tournament->push_sent_count ?? 0);
    }

    /** Сколько отправок осталось. */
    public function remaining(Tournament $tournament): int
    {
        return max(0, self::MAX_SENDS - $this->sentCount($tournament));
    }

    /** Можно ли ещё отправлять. */
    public function canSend(Tournament $tournament): bool
    {
        return $this->remaining($tournament) > 0;
    }

    /** Заголовок по умолчанию — он же заготовка в форме отправки. */
    public function defaultTitle(): string
    {
        return 'Новый турнир!';
    }

    /** Текст по умолчанию — он же заготовка в форме отправки. */
    public function defaultBody(Tournament $tournament): string
    {
        return $tournament->name . ' — ' . $tournament->start_date->format('d.m.Y H:i');
    }

    /**
     * Отправить push о турнире.
     *
     * $title и $body — текст, поправленный организатором в форме. Пустые
     * значения (в том числе пробелы из формы) заменяются заготовкой, чтобы
     * не ушёл пустой пуш.
     *
     * Возвращает ['total' => int, 'sent' => int, 'filtered' => int].
     * Если лимит исчерпан — ['limit_reached' => true] и ничего не шлём.
     */
    public function send(Tournament $tournament, ?string $title = null, ?string $body = null): array
    {
        if (!$this->canSend($tournament)) {
            return [
                'total' => 0, 'sent' => 0, 'filtered' => 0,
                'test_mode' => false, 'limit_reached' => true,
            ];
        }

        $tournament->loadMissing('club');
        $club = $tournament->club;

        $fcm = app(FCMNotificationService::class);
        $title = trim((string) $title) !== '' ? trim($title) : $this->defaultTitle();
        $body = trim((string) $body) !== '' ? trim($body) : $this->defaultBody($tournament);
        $data = [
            'type' => 'tournament',
            'tournament_id' => (string) $tournament->id,
        ];

        $testPhones = $this->testPhones();

        if ($testPhones) {
            // Тестовый режим: шлём строго на свои номера, минуя фильтры города
            // и личных настроек. Смысл режима — «пришли мне»; если прогонять
            // его через обычные фильтры, получателей окажется 0 и будет
            // непонятно, номер не тот или настройки не пускают.
            $users = User::whereHas('deviceTokens')->with('deviceTokens')
                ->get(['id', 'phone', 'city', 'level', 'notify_only_my_level', 'notify_club_ids'])
                ->filter(fn ($user) => in_array($this->normalizePhone($user->phone), $testPhones, true));
            $recipients = $users;
        } else {
            // Базовая выборка: пользователи с устройствами, с учётом города клуба.
            $query = User::whereHas('deviceTokens')->with('deviceTokens');

            if ($club && $club->city) {
                if ($club->city === 'Алматы') {
                    $query->where(fn ($q) => $q->where('city', 'Алматы')->orWhereNull('city'));
                } else {
                    $query->where('city', $club->city);
                }
            }

            $users = $query->get(['id', 'phone', 'city', 'level', 'notify_only_my_level', 'notify_club_ids']);

            // Персональные фильтры пользователя.
            $recipients = $users->filter(function ($user) use ($tournament) {
                if (!empty($user->notify_club_ids) && !in_array($tournament->club_id, $user->notify_club_ids)) {
                    return false;
                }
                if ($user->notify_only_my_level) {
                    return $user->level >= $tournament->min_level && $user->level <= $tournament->max_level;
                }
                return true;
            });
        }

        // Один multicast на все токены.
        $tokens = $recipients->flatMap(fn ($user) => $user->deviceTokens->pluck('token'))->toArray();
        if (!empty($tokens)) {
            $fcm->sendMulticastToTokens($tokens, $title, $body, $data);
        }

        // Записи в «колокольчик» одним запросом.
        $now = now();
        $notifications = $recipients->map(fn ($user) => [
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => 'tournament',
            'category' => 'tournament',
            'data' => json_encode(['tournament_id' => $tournament->id]),
            'created_at' => $now,
            'updated_at' => $now,
        ])->toArray();

        if (!empty($notifications)) {
            Notification::insert($notifications);
        }

        // Тестовый режим лимит не тратит: он шлёт только на свои номера,
        // и отладка не должна съедать отправки, положенные игрокам.
        if (!$testPhones) {
            $tournament->increment('push_sent_count');
        }

        $total = $users->count();
        $sent = $recipients->count();

        return [
            'total' => $total,
            'sent' => $sent,
            'filtered' => $total - $sent,
            'test_mode' => (bool) $testPhones,
            'limit_reached' => false,
            'remaining' => $this->remaining($tournament->fresh()),
        ];
    }

    /**
     * Телефоны тестового режима из .env, приведённые к одним цифрам.
     * Пустой массив — режим выключен, рассылка идёт всем.
     *
     * @return array<int, string>
     */
    public function testPhones(): array
    {
        $raw = (string) config('mobile_app.push_test_phones', '');

        return collect(explode(',', $raw))
            ->map(fn ($phone) => $this->normalizePhone($phone))
            ->filter()
            ->values()
            ->all();
    }

    /** Номер к сравнимому виду: только цифры, ведущая 8 → 7. */
    private function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7' . substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Push участникам турнира о новом сообщении организатора в чате.
     * Шлём только тем, у кого включена настройка notify_organizer_chat,
     * есть device-токен, и кто не автор сообщения.
     */
    public function sendChatMessage(Tournament $tournament, User $author, string $text): void
    {
        $participantIds = $this->chatParticipantUserIds($tournament)
            ->reject(fn ($id) => (int) $id === (int) $author->id)
            ->unique()
            ->values();
        if ($participantIds->isEmpty()) {
            return;
        }

        $users = User::whereIn('id', $participantIds)
            ->where('notify_organizer_chat', true)
            ->whereHas('deviceTokens')
            ->with('deviceTokens')
            ->get(['id']);

        $tokens = $users->flatMap(fn ($u) => $u->deviceTokens->pluck('token'))->toArray();
        if (empty($tokens)) {
            return;
        }

        $fcm = app(FCMNotificationService::class);
        $title = $tournament->name;
        $body = \Illuminate\Support\Str::limit(trim($text), 100);
        $data = [
            'type' => 'tournament_chat',
            'tournament_id' => (string) $tournament->id,
        ];

        $fcm->sendMulticastToTokens($tokens, $title, $body, $data);
    }

    /** id участников турнира (одиночная регистрация + командные пары). */
    private function chatParticipantUserIds(Tournament $tournament): \Illuminate\Support\Collection
    {
        if ($tournament->usesSoloRegistration()) {
            return $tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending', 'approved'])
                ->pluck('users.id');
        }

        $ids = collect();
        $tournament->teams()
            ->whereIn('status', ['approved', 'pending'])
            ->get(['player1_id', 'player2_id'])
            ->each(function ($t) use ($ids) {
                if ($t->player1_id) $ids->push($t->player1_id);
                if ($t->player2_id) $ids->push($t->player2_id);
            });
        return $ids;
    }
}

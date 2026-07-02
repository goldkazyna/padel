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
     * Отправить push о турнире.
     * Возвращает ['total' => int, 'sent' => int, 'filtered' => int].
     */
    public function send(Tournament $tournament): array
    {
        $tournament->loadMissing('club');
        $club = $tournament->club;

        $fcm = app(FCMNotificationService::class);
        $date = $tournament->start_date->format('d.m.Y H:i');
        $title = 'Новый турнир!';
        $body = "{$tournament->name} — {$date}";
        $data = [
            'type' => 'tournament',
            'tournament_id' => (string) $tournament->id,
        ];

        // Базовая выборка: пользователи с устройствами, с учётом города клуба.
        $query = User::whereHas('deviceTokens')->with('deviceTokens');

        if ($club && $club->city) {
            if ($club->city === 'Алматы') {
                $query->where(fn ($q) => $q->where('city', 'Алматы')->orWhereNull('city'));
            } else {
                $query->where('city', $club->city);
            }
        }

        $users = $query->get(['id', 'city', 'level', 'notify_only_my_level', 'notify_club_ids']);

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

        $total = $users->count();
        $sent = $recipients->count();

        return [
            'total' => $total,
            'sent' => $sent,
            'filtered' => $total - $sent,
        ];
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

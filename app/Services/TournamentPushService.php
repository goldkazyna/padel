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
}

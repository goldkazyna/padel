<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;

/**
 * Оповещение участников об отмене турнира (пуш + колокольчик).
 * Используется и из приложения, и из веб-админки при переводе турнира
 * в статус «cancelled».
 */
class TournamentCancellationNotifier
{
    public function notify(Tournament $tournament): void
    {
        // Соло-участники (кроме отменивших запись) + игроки команд (кроме отклонённых).
        $soloIds = $tournament->participants()
            ->wherePivotNotIn('status', ['cancelled'])
            ->pluck('users.id');

        $teamPlayerIds = TournamentTeam::where('tournament_id', $tournament->id)
            ->whereNotIn('status', ['rejected'])
            ->get(['player1_id', 'player2_id'])
            ->flatMap(fn ($t) => [$t->player1_id, $t->player2_id]);

        $ids = $soloIds->merge($teamPlayerIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $title = 'Турнир отменён';
        $body = "ВНИМАНИЕ! Турнир «{$tournament->name}» отменён организатором.";

        foreach (User::whereIn('id', $ids)->get() as $user) {
            Notification::create([
                'user_id' => $user->id,
                'title' => $title,
                'body' => $body,
                'type' => 'tournament_cancelled',
                'category' => 'tournament',
                'data' => ['tournament_id' => $tournament->id],
            ]);
            try {
                app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
                    'type' => 'tournament',
                    'tournament_id' => (string) $tournament->id,
                ]);
            } catch (\Throwable $e) {
                // пуш не критичен
            }
        }
    }
}

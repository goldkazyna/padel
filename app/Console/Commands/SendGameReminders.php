<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Notification;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendGameReminders extends Command
{
    protected $signature = 'games:send-reminders';
    protected $description = 'Напоминания участникам о начале игры за сутки, за 2 часа и за час';

    public function handle(): int
    {
        $now = now();
        $games = Game::whereIn('status', [Game::STATUS_OPEN, Game::STATUS_FULL, Game::STATUS_IN_PROGRESS])
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', (clone $now)->addDay())
            ->get();

        foreach ($games as $game) {
            $seconds = $game->starts_at->getTimestamp() - $now->getTimestamp();
            if ($seconds < 0) {
                continue;
            }

            // Порог определяется ТЕКУЩИМ окном до старта (а не просто "флаг ещё не стоял"),
            // иначе после срабатывания 1ч следующий прогон провалится в elseif 2ч/1сутки
            // и отправит их задним числом, хотя момент для них уже прошёл.
            $threshold = null;   // ['column', 'kind']
            if ($seconds <= 3600) {
                if (!$game->reminded_1h_at) {
                    $threshold = ['reminded_1h_at', '1h'];
                }
            } elseif ($seconds <= 7200) {
                if (!$game->reminded_2h_at) {
                    $threshold = ['reminded_2h_at', '2h'];
                }
            } elseif ($seconds <= 86400) {
                if (!$game->reminded_1d_at) {
                    $threshold = ['reminded_1d_at', '1d'];
                }
            }
            if ($threshold === null) {
                continue;
            }

            $game->update([$threshold[0] => $now]);

            $accepted = $game->players()
                ->where('status', GamePlayer::STATUS_ACCEPTED)
                ->with('user')
                ->get();
            foreach ($accepted as $gp) {
                if (!$gp->user) {
                    continue;
                }
                $this->send($gp->user, $game, $threshold[1]);
            }
        }

        return self::SUCCESS;
    }

    private function send(User $user, Game $game, string $kind): void
    {
        $title = 'Скоро игра';
        $body = 'Ваша игра скоро начнётся';

        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => 'game_reminder',
            'category' => 'game',
            'data' => ['game_id' => $game->id, 'kind' => $kind],
        ]);

        try {
            app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
                'type' => 'game_reminder',
                'game_id' => (string) $game->id,
                'kind' => $kind,
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->warning('Game reminder FCM failed: ' . $e->getMessage());
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Console\Command;

class SendTournamentReminders extends Command
{
    protected $signature = 'tournaments:send-reminders';
    protected $description = 'Напоминания участникам о турнире за день и за 2 часа';

    public function handle(): int
    {
        $now = now();
        $tournaments = Tournament::whereIn('status', ['open', 'closed'])
            ->where('type', '!=', 'team')
            ->where('start_date', '>', $now)
            ->where('start_date', '<=', $now->copy()->addDay())
            ->with('club')
            ->get();

        foreach ($tournaments as $t) {
            $secondsUntil = $t->start_date->getTimestamp() - $now->getTimestamp();

            $participants = $t->participants()
                ->wherePivot('status', 'registered')
                ->get();

            foreach ($participants as $p) {
                if (!$p->notify_tournament_reminders) continue;

                if ($secondsUntil <= 86400 && !$p->pivot->reminded_1d_at) {
                    $t->participants()->updateExistingPivot($p->id, ['reminded_1d_at' => $now]);
                    $this->send($p, $t, '1d');
                }
                if ($secondsUntil <= 7200 && !$p->pivot->reminded_2h_at) {
                    $t->participants()->updateExistingPivot($p->id, ['reminded_2h_at' => $now]);
                    $this->send($p, $t, '2h');
                }
            }
        }

        return self::SUCCESS;
    }

    private function send(User $user, Tournament $t, string $kind): void
    {
        $club = $t->club->name ?? '';
        $time = $t->start_date->copy()->timezone('Asia/Almaty')->format('H:i');
        $date = $t->start_date->copy()->timezone('Asia/Almaty')->format('d.m');

        if ($kind === '1d') {
            $title = 'Турнир завтра';
            $body = "Напоминаем: «{$t->name}» {$date} в {$time}" . ($club ? ", {$club}" : '') . '. Не забудьте!';
        } else {
            $title = 'Турнир через 2 часа';
            $body = "«{$t->name}» сегодня в {$time}" . ($club ? ", {$club}" : '') . '.';
        }

        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => 'tournament_reminder',
            'category' => 'tournament',
            'data' => ['tournament_id' => $t->id],
        ]);

        try {
            app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
                'type' => 'tournament_reminder',
                'tournament_id' => (string) $t->id,
            ]);
        } catch (\Throwable $e) {
            // пуш не критичен
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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

            $sent1d = 0;
            $sent2h = 0;

            foreach ($participants as $p) {
                if (!$p->notify_tournament_reminders) continue;

                if ($secondsUntil <= 86400 && !$p->pivot->reminded_1d_at) {
                    $t->participants()->updateExistingPivot($p->id, ['reminded_1d_at' => $now]);
                    $this->send($p, $t, '1d');
                    $sent1d++;
                }
                if ($secondsUntil <= 7200 && !$p->pivot->reminded_2h_at) {
                    $t->participants()->updateExistingPivot($p->id, ['reminded_2h_at' => $now]);
                    $this->send($p, $t, '2h');
                    $sent2h++;
                }
            }

            if ($sent1d > 0) $this->logReminder($t, '24-часовое', $sent1d);
            if ($sent2h > 0) $this->logReminder($t, '2-часовое', $sent2h);
        }

        return self::SUCCESS;
    }

    /**
     * Запись в лог: id турнира, тип напоминания, кол-во получателей,
     * время отправки по Алматы (+5, app.timezone=UTC).
     */
    private function logReminder(Tournament $t, string $kind, int $count): void
    {
        $almaty = now()->timezone('Asia/Almaty')->format('Y-m-d H:i:s');

        Log::channel('tournament_reminders')->info(sprintf(
            'Турнир #%d «%s» | %s напоминание | отправлено %d участникам | время (Алматы, +5): %s',
            $t->id,
            $t->name,
            $kind,
            $count,
            $almaty
        ));
    }

    private function send(User $user, Tournament $t, string $kind): void
    {
        // start_date хранится как местное время (Алматы) — форматируем как есть,
        // без timezone-конвертации (так же делает весь остальной апп).
        $club = $t->club->name ?? '';
        $time = $t->start_date->format('H:i');
        $date = $t->start_date->format('d.m');

        if ($kind === '1d') {
            $title = 'Напоминание о турнире';
            $body = "Турнир «{$t->name}» {$date} в {$time}" . ($club ? ", {$club}" : '') . '. Не забудьте!';
        } else {
            $title = 'Турнир скоро';
            $body = "«{$t->name}» начнётся в {$time}" . ($club ? ", {$club}" : '') . ' — меньше чем через 2 часа.';
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

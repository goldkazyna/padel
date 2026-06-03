<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FCMNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendTournamentReminders extends Command
{
    protected $signature = 'tournaments:send-reminders';
    protected $description = 'Напоминания участникам о турнире за день, за 2 часа и за час';

    public function handle(): int
    {
        // start_date хранится как настенное время Алматы (app.timezone=UTC),
        // поэтому и «сейчас» берём в Алматы — иначе сравнение уезжает на +5 часов.
        $tz = 'Asia/Almaty';
        $now = now()->timezone($tz);

        $tournaments = Tournament::whereIn('status', ['open', 'closed'])
            ->where('type', '!=', 'team')
            ->where('start_date', '>', $now->format('Y-m-d H:i:s'))
            ->where('start_date', '<=', $now->copy()->addDay()->format('Y-m-d H:i:s'))
            ->with('club')
            ->get();

        foreach ($tournaments as $t) {
            // Трактуем настенное время старта как Алматы, чтобы получить верный момент.
            $start = Carbon::parse($t->start_date->format('Y-m-d H:i:s'), $tz);
            $secondsUntil = $start->getTimestamp() - $now->getTimestamp();

            $participants = $t->participants()
                ->wherePivot('status', 'registered')
                ->get();

            $sent1d = 0;
            $sent2h = 0;
            $sent1h = 0;

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
                if ($secondsUntil <= 3600 && !$p->pivot->reminded_1h_at) {
                    $t->participants()->updateExistingPivot($p->id, ['reminded_1h_at' => $now]);
                    $this->send($p, $t, '1h');
                    $sent1h++;
                }
            }

            if ($sent1d > 0) $this->logReminder($t, '24-часовое', $sent1d);
            if ($sent2h > 0) $this->logReminder($t, '2-часовое', $sent2h);
            if ($sent1h > 0) $this->logReminder($t, '1-часовое', $sent1h);
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
        } elseif ($kind === '2h') {
            $title = 'Турнир скоро';
            $body = "«{$t->name}» начнётся в {$time}" . ($club ? ", {$club}" : '') . ' — меньше чем через 2 часа.';
        } else {
            $title = 'Турнир через час';
            $body = "«{$t->name}» начнётся в {$time}" . ($club ? ", {$club}" : '') . ' — меньше чем через час. Пора собираться!';
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

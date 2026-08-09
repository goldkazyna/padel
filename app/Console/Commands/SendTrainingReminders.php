<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Training;
use App\Models\TrainingParticipant;
use App\Models\User;
use App\Services\FCMNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendTrainingReminders extends Command
{
    protected $signature = 'trainings:send-reminders';
    protected $description = 'Напоминания записавшимся о тренировке за день, за 2 часа и за час';

    public function handle(): int
    {
        // starts_at хранится как настенное время Алматы (app.timezone=UTC),
        // поэтому и «сейчас» берём в Алматы — иначе сравнение уезжает на +5 часов.
        $tz = 'Asia/Almaty';
        $now = now()->timezone($tz);

        $trainings = Training::where('status', 'planned')
            ->where('starts_at', '>', $now->format('Y-m-d H:i:s'))
            ->where('starts_at', '<=', $now->copy()->addDay()->format('Y-m-d H:i:s'))
            ->with(['club', 'participants.user'])
            ->get();

        foreach ($trainings as $training) {
            $start = Carbon::parse($training->starts_at->format('Y-m-d H:i:s'), $tz);
            $secondsUntil = $start->getTimestamp() - $now->getTimestamp();

            foreach ($training->participants as $participant) {
                $user = $participant->user;
                if (!$user || !$user->notify_tournament_reminders) {
                    continue;
                }

                if ($secondsUntil <= 86400 && !$participant->reminded_1d_at) {
                    $participant->update(['reminded_1d_at' => $now]);
                    $this->send($user, $training, '1d');
                }
                if ($secondsUntil <= 7200 && !$participant->reminded_2h_at) {
                    $participant->update(['reminded_2h_at' => $now]);
                    $this->send($user, $training, '2h');
                }
                if ($secondsUntil <= 3600 && !$participant->reminded_1h_at) {
                    $participant->update(['reminded_1h_at' => $now]);
                    $this->send($user, $training, '1h');
                }
            }
        }

        return self::SUCCESS;
    }

    private function send(User $user, Training $training, string $kind): void
    {
        $club = $training->club->name ?? '';
        $where = $club !== '' ? ", {$club}" : '';
        $time = $training->starts_at->format('H:i');
        $date = $training->starts_at->format('d.m');

        if ($kind === '1d') {
            $title = 'Напоминание о тренировке';
            $body = "Тренировка {$date} в {$time}{$where}. Не забудьте!";
        } elseif ($kind === '2h') {
            $title = 'Тренировка скоро';
            $body = "Тренировка начнётся в {$time}{$where} — меньше чем через 2 часа.";
        } else {
            $title = 'Тренировка через час';
            $body = "Тренировка начнётся в {$time}{$where} — пора собираться!";
        }

        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => 'training_reminder',
            'category' => 'training',
            'data' => ['training_id' => $training->id],
        ]);

        try {
            app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
                'type' => 'training_reminder',
                'training_id' => (string) $training->id,
            ]);
        } catch (\Throwable $e) {
            // пуш не критичен
        }
    }
}

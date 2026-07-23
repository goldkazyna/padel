<?php

namespace App\Console\Commands;

use App\Models\CourtBooking;
use App\Models\Notification;
use App\Models\User;
use App\Services\FCMNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders';
    protected $description = 'Напоминания клиенту о брони корта за сутки, за 2 часа и за час';

    public function handle(): int
    {
        // Время старта хранится как настенное Алматы (app.timezone=UTC),
        // поэтому «сейчас» тоже берём в Алматы — иначе сравнение уезжает на +5.
        $tz = 'Asia/Almaty';
        $now = now()->timezone($tz);
        $todayStr = $now->toDateString();
        $tomorrowStr = $now->copy()->addDay()->toDateString();

        // Подтверждённые НЕ групповые брони на сегодня/завтра.
        $bookings = CourtBooking::where('status', 'confirmed')
            ->where(fn($q) => $q->where('booking_type', '!=', 'group')->orWhereNull('booking_type'))
            ->whereDate('date', '>=', $todayStr)
            ->whereDate('date', '<=', $tomorrowStr)
            ->with('court.club')
            ->get();

        foreach ($bookings as $b) {
            $dateStr = $b->date instanceof Carbon ? $b->date->format('Y-m-d') : (string) $b->date;
            $start = Carbon::parse($dateStr . ' ' . $b->start_time, $tz);
            $secondsUntil = $start->getTimestamp() - $now->getTimestamp();

            // Только будущие в пределах 24 часов.
            if ($secondsUntil <= 0 || $secondsUntil > 86400) {
                continue;
            }

            // Получатель — app-пользователь по телефону брони (как мгновенный пуш).
            $phone = $this->normalizePhone($b->client_phone);
            if (strlen($phone) !== 11) {
                continue;
            }
            $user = User::where('phone', $phone)->first();
            if (!$user || !$user->notify_booking_reminders) {
                continue;
            }

            if ($secondsUntil <= 86400 && !$b->reminded_1d_at) {
                $b->update(['reminded_1d_at' => $now]);
                $this->send($user, $b, '1d');
            }
            if ($secondsUntil <= 7200 && !$b->reminded_2h_at) {
                $b->update(['reminded_2h_at' => $now]);
                $this->send($user, $b, '2h');
            }
            if ($secondsUntil <= 3600 && !$b->reminded_1h_at) {
                $b->update(['reminded_1h_at' => $now]);
                $this->send($user, $b, '1h');
            }
        }

        return self::SUCCESS;
    }

    private function send(User $user, CourtBooking $b, string $kind): void
    {
        $club = $b->court?->club?->name ?? '';
        $court = $b->court?->name ?? 'корт';
        $time = Carbon::parse($b->start_time)->format('H:i');
        $dateStr = $b->date instanceof Carbon ? $b->date->format('d.m') : (string) $b->date;
        $where = $court . ($club ? ", {$club}" : '');

        if ($kind === '1d') {
            $title = 'Напоминание о брони';
            $body = "Завтра бронь: {$where}, {$dateStr} в {$time}. Не забудьте!";
        } elseif ($kind === '2h') {
            $title = 'Бронь скоро';
            $body = "{$where} — начало в {$time}, меньше чем через 2 часа.";
        } else {
            $title = 'Бронь через час';
            $body = "{$where} в {$time} — меньше чем через час. Пора выезжать!";
        }

        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => 'booking_reminder',
            'category' => 'booking',
            'data' => ['booking_id' => $b->id],
        ]);

        try {
            app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
                'type' => 'booking_reminder',
                'booking_id' => (string) $b->id,
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->warning('Booking reminder push error: ' . $e->getMessage());
        }
    }

    /** Нормализация телефона к 11 цифрам (как в CourtController::findUserByPhone). */
    private function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        return $digits;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\CourtBooking;
use App\Services\ClubCardService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ChargeDueCards extends Command
{
    protected $signature = 'cards:charge-due';
    protected $description = 'Списать часы клубных карт за завершённые брони (после окончания брони)';

    public function handle(ClubCardService $service): int
    {
        $now = now();

        // Кандидаты: есть карта, ещё не списано, бронь подтверждена, дата не в будущем.
        $bookings = CourtBooking::whereNotNull('club_card_id')
            ->whereNull('card_charged_at')
            ->where('status', 'confirmed')
            ->whereDate('date', '<=', $now->toDateString())
            ->get()
            ->filter(fn ($b) => $this->ended($b, $now));

        $charged = 0;
        foreach ($bookings as $booking) {
            if ($service->chargeBooking($booking)) {
                $charged++;
            }
        }

        $this->info("Завершённых броней с картой: {$bookings->count()}, списаний: {$charged}");

        return self::SUCCESS;
    }

    /**
     * Бронь действительно завершилась: дата+время окончания уже в прошлом.
     * Время брони хранится в МЕСТНОМ времени клуба (app.timezone=UTC), поэтому
     * трактуем его как local TZ и сравниваем с now() (Carbon учитывает смещение).
     */
    private function ended(CourtBooking $booking, Carbon $now): bool
    {
        $date = $booking->date instanceof Carbon
            ? $booking->date->format('Y-m-d')
            : (string) $booking->date;
        $tz = config('app.schedule_timezone', 'Asia/Almaty');
        $end = Carbon::parse($date . ' ' . substr((string) $booking->end_time, 0, 5), $tz);

        return $end->lessThanOrEqualTo($now);
    }
}

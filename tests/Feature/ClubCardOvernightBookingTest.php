<?php

namespace Tests\Feature;

use App\Models\CourtBooking;
use App\Services\ClubCardService;
use Carbon\Carbon;
use Tests\TestCase;

class ClubCardOvernightBookingTest extends TestCase
{
    private function svc(): ClubCardService
    {
        return app(ClubCardService::class);
    }

    public function test_overnight_booking_not_ended_before_midnight(): void
    {
        $tz = 'Asia/Almaty';
        // Бронь 21:00–00:00 (через полночь) на 24 июня
        $b = new CourtBooking(['date' => '2026-06-24', 'start_time' => '21:00:00', 'end_time' => '00:00:00']);

        // 17:50 того же дня — ещё НЕ завершена
        $this->assertFalse($this->svc()->bookingEnded($b, Carbon::parse('2026-06-24 17:50', $tz)));
        // 23:30 того же дня — ещё идёт
        $this->assertFalse($this->svc()->bookingEnded($b, Carbon::parse('2026-06-24 23:30', $tz)));
        // 00:30 следующего дня — завершена
        $this->assertTrue($this->svc()->bookingEnded($b, Carbon::parse('2026-06-25 00:30', $tz)));
    }

    public function test_regular_booking_ended_logic(): void
    {
        $tz = 'Asia/Almaty';
        $b = new CourtBooking(['date' => '2026-06-24', 'start_time' => '10:00:00', 'end_time' => '11:00:00']);

        $this->assertFalse($this->svc()->bookingEnded($b, Carbon::parse('2026-06-24 10:30', $tz)));
        $this->assertTrue($this->svc()->bookingEnded($b, Carbon::parse('2026-06-24 12:00', $tz)));
    }
}

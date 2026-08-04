<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCoach;
use App\Models\CoachSchedule;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\CourtBookingCoach;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Мультитренерская бронь (клиент берёт 2+ тренеров) должна попадать
 * в расписание КАЖДОГО тренера, а не только основного (coach_id).
 */
class MultiCoachScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_coach_booking_shows_in_every_coach_day_schedule(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);

        $userA = User::factory()->create(['role' => 'coach']);
        $userB = User::factory()->create(['role' => 'coach']);
        $coachA = ClubCoach::create(['club_id' => $club->id, 'user_id' => $userA->id]);
        $coachB = ClubCoach::create(['club_id' => $club->id, 'user_id' => $userB->id]);

        // Оба тренера работают в субботу (2026-08-16) 08:00–22:00.
        $dow = Carbon::parse('2026-08-16')->dayOfWeekIso; // 6
        foreach ([$coachA, $coachB] as $cc) {
            CoachSchedule::create(['club_coach_id' => $cc->id, 'day_of_week' => $dow, 'start_time' => '08:00', 'end_time' => '22:00']);
        }

        // Бронь: основной тренер A (coach_id), пивот содержит обоих.
        $booking = CourtBooking::create([
            'court_id' => $court->id, 'date' => '2026-08-16', 'start_time' => '18:00', 'end_time' => '19:00',
            'client_name' => 'Денис Дудников', 'client_phone' => '+77774333822', 'status' => 'confirmed',
            'coach_id' => $userA->id, 'booked_by' => User::factory()->create()->id, 'price' => 32000,
        ]);
        CourtBookingCoach::create(['court_booking_id' => $booking->id, 'coach_id' => $userA->id, 'coach_price' => 15000]);
        CourtBookingCoach::create(['court_booking_id' => $booking->id, 'coach_id' => $userB->id, 'coach_price' => 20000]);

        // Основной тренер A — видит бронь (как и раньше).
        $schedA = $coachA->daySchedule('2026-08-16')['schedule'];
        $this->assertSame('booked', $schedA['18:00']['status']);
        $this->assertSame($booking->id, $schedA['18:00']['booking']->id);

        // Второй тренер B — раньше НЕ видел (баг). Теперь должен видеть.
        $schedB = $coachB->daySchedule('2026-08-16')['schedule'];
        $this->assertSame('booked', $schedB['18:00']['status']);
        $this->assertSame($booking->id, $schedB['18:00']['booking']->id);
    }

    public function test_for_coach_scope_matches_primary_and_pivot(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $userA = User::factory()->create(['role' => 'coach']);
        $userB = User::factory()->create(['role' => 'coach']);

        $booking = CourtBooking::create([
            'court_id' => $court->id, 'date' => '2026-08-16', 'start_time' => '18:00', 'end_time' => '19:00',
            'client_name' => 'X', 'status' => 'confirmed',
            'coach_id' => $userA->id, 'booked_by' => User::factory()->create()->id, 'price' => 0,
        ]);
        CourtBookingCoach::create(['court_booking_id' => $booking->id, 'coach_id' => $userA->id]);
        CourtBookingCoach::create(['court_booking_id' => $booking->id, 'coach_id' => $userB->id]);

        $this->assertTrue(CourtBooking::forCoach($userA->id)->where('id', $booking->id)->exists());
        $this->assertTrue(CourtBooking::forCoach($userB->id)->where('id', $booking->id)->exists());
        // Без дублей: scope на одну бронь возвращает одну строку.
        $this->assertCount(1, CourtBooking::forCoach($userB->id)->get());
    }
}

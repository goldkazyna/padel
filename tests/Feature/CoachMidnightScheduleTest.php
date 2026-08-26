<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCoach;
use App\Models\CoachSchedule;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Смена «14:00 — 00:00» превращалась в интервал 840..0 минут: пустой.
 * Тренер со сменой до полуночи считался занятым в любое своё рабочее время,
 * и в списке замены его нельзя было выбрать.
 */
class CoachMidnightScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private ClubCoach $coach;

    /** Среда. */
    private string $date = '2026-08-26';

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Pulse', 'address' => 'А', 'city' => 'Алматы']);
        $user = User::factory()->create(['role' => 'coach', 'name' => 'Егор Платонов']);

        $this->coach = ClubCoach::create([
            'club_id' => $this->club->id,
            'user_id' => $user->id,
            'rate_individual' => 10000,
        ]);
    }

    private function shift(string $start, string $end, int $dayOfWeek = 3): void
    {
        CoachSchedule::create([
            'club_coach_id' => $this->coach->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    public function test_смена_до_полуночи_не_делает_тренера_занятым(): void
    {
        $this->shift('14:00:00', '00:00:00');

        $this->assertTrue($this->coach->isAvailableAt($this->date, '15:00', '16:00'),
            '15:00 внутри смены 14:00–00:00');
        $this->assertTrue($this->coach->isFreeAt($this->date, '15:00', '16:00'));
    }

    public function test_последний_час_смены_до_полуночи_доступен(): void
    {
        $this->shift('14:00:00', '00:00:00');

        $this->assertTrue($this->coach->isAvailableAt($this->date, '23:00', '00:00'),
            '23:00–00:00 — последний час смены');
    }

    public function test_время_до_начала_смены_остаётся_недоступным(): void
    {
        $this->shift('14:00:00', '00:00:00');

        $this->assertFalse($this->coach->isAvailableAt($this->date, '12:00', '13:00'),
            'до начала смены тренер по-прежнему недоступен');
    }

    public function test_обычная_смена_работает_как_раньше(): void
    {
        $this->shift('09:00:00', '18:00:00');

        $this->assertTrue($this->coach->isAvailableAt($this->date, '10:00', '11:00'));
        $this->assertFalse($this->coach->isAvailableAt($this->date, '18:00', '19:00'),
            'после конца смены — занят');
    }

    public function test_дневная_сетка_показывает_слоты_смены_до_полуночи(): void
    {
        $this->shift('22:00:00', '00:00:00');

        $slots = $this->coach->daySchedule($this->date)['timeSlots'];

        $this->assertSame(['22:00', '23:00'], $slots, 'два часа до полуночи');
    }

    public function test_бронь_до_полуночи_занимает_тренера(): void
    {
        $this->shift('14:00:00', '00:00:00');

        $court = Court::create([
            'club_id' => $this->club->id, 'name' => 'Корт 1', 'price_per_hour' => 10000,
        ]);
        CourtBooking::create([
            'court_id' => $court->id, 'date' => $this->date,
            'start_time' => '23:00:00', 'end_time' => '00:00:00',
            'client_name' => 'Группа', 'status' => 'confirmed', 'booked_by' => $this->coach->user_id, 'price' => 10000,
            'coach_id' => $this->coach->user_id,
        ]);

        $this->assertFalse($this->coach->isFreeAt($this->date, '23:00', '00:00'),
            'на это время он уже занят');
        $this->assertTrue($this->coach->isFreeAt($this->date, '15:00', '16:00'),
            'а днём свободен');
    }
}

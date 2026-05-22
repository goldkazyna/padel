<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\ClubCoach;
use App\Models\CoachRate;
use App\Models\User;
use App\Reports\CoachesReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CoachesReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_uses_coach_rate_then_hourly(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $booker = User::factory()->create();
        $coachUser = User::factory()->create(['first_name' => 'Тренер', 'name' => 'Тренер Один']);
        $cc = ClubCoach::create(['club_id' => $club->id, 'user_id' => $coachUser->id, 'hourly_rate' => 4000]);
        CoachRate::create(['club_coach_id' => $cc->id, 'hours' => 1, 'rate' => 5000]); // 1h => 5000

        // 1h booking with coach => uses coach_rate 5000
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-03', 'start_time' => '08:00', 'end_time' => '09:00', 'client_name' => 'Клиент', 'price' => 7000, 'discount' => 0, 'status' => 'confirmed', 'coach_id' => $coachUser->id, 'coach_paid' => true, 'is_paid' => true, 'booked_by' => $booker->id]);

        $svc = new CoachesReportService();
        $salary = $svc->salary($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($salary->rows)->firstWhere(0, 'Тренер Один');
        $this->assertEquals(1, $row[1]);    // sessions
        $this->assertEquals(1.0, $row[2]);  // hours
        $this->assertEquals(5000, $row[3]); // to pay

        $usage = $svc->usage($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $u = collect($usage->rows)->firstWhere(0, 'Тренер Один');
        $this->assertEquals(1, $u[1]);      // sessions
        $this->assertEquals(7000, $u[3]);   // club income

        $sessions = $svc->sessions($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $this->assertCount(1, $sessions->rows);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCoach;
use App\Models\ClubGroup;
use App\Models\ClubGroupSession;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Reports\CoachesReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachPayoutsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_payouts_split_group_and_individual(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'K1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $date = '2026-06-10';
        $coach = User::factory()->create();
        // rate_group 3000 ₸/час, индивид. (базовая) 5000 ₸/час
        ClubCoach::create([
            'club_id' => $club->id, 'user_id' => $coach->id,
            'hourly_rate' => 5000, 'rate_group' => 3000,
        ]);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'status' => 'active']);

        // Групповое проведённое занятие 1ч → 3000×1 = 3000
        ClubGroupSession::create([
            'group_id' => $group->id, 'court_id' => $court->id, 'date' => $date,
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'held', 'coach_id' => $coach->id,
        ]);
        // Запланированное (не held) — не платим
        ClubGroupSession::create([
            'group_id' => $group->id, 'court_id' => $court->id, 'date' => $date,
            'start_time' => '11:00', 'end_time' => '12:00', 'status' => 'planned', 'coach_id' => $coach->id,
        ]);

        // Индивидуальная бронь 1ч без coach_price → ставка 5000
        CourtBooking::create([
            'court_id' => $court->id, 'date' => $date, 'start_time' => '14:00', 'end_time' => '15:00',
            'client_name' => 'X', 'booked_by' => $coach->id, 'status' => 'confirmed',
            'price' => 8000, 'discount' => 0, 'coach_id' => $coach->id, 'booking_type' => 'individual',
        ]);
        // Индивидуальная с coach_price 2000 → 2000
        CourtBooking::create([
            'court_id' => $court->id, 'date' => $date, 'start_time' => '15:00', 'end_time' => '16:00',
            'client_name' => 'Y', 'booked_by' => $coach->id, 'status' => 'confirmed',
            'price' => 8000, 'discount' => 0, 'coach_id' => $coach->id, 'booking_type' => 'individual', 'coach_price' => 2000,
        ]);
        // Групповая бронь — НЕ должна попасть в индивидуальные
        CourtBooking::create([
            'court_id' => $court->id, 'date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
            'client_name' => 'Группа: G', 'booked_by' => $coach->id, 'status' => 'confirmed',
            'price' => 0, 'discount' => 0, 'coach_id' => $coach->id, 'booking_type' => 'group',
        ]);

        $sheet = app(CoachesReportService::class)->payouts(
            $club, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')
        );

        $this->assertCount(1, $sheet->rows);
        [$name, $grp, $ind, $sum] = $sheet->rows[0];
        $this->assertSame(3000.0, (float) $grp, 'групповые: 3000×1');
        $this->assertSame(7000.0, (float) $ind, 'индивид.: 5000 + 2000');
        $this->assertSame(10000.0, (float) $sum);
    }
}

<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Reports\ManagersReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ManagersReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_by_manager_and_day(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $mgr = User::factory()->create(['name' => 'Менеджер А']);
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-06', 'start_time' => '08:00', 'end_time' => '09:00', 'client_name' => 'К1', 'price' => 5000, 'discount' => 0, 'is_paid' => true, 'status' => 'confirmed', 'booked_by' => $mgr->id]);
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-06', 'start_time' => '09:00', 'end_time' => '10:00', 'client_name' => 'К2', 'price' => 5000, 'discount' => 0, 'is_paid' => false, 'status' => 'confirmed', 'booked_by' => $mgr->id]);

        $sheet = (new ManagersReportService())->sales($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($sheet->rows)->first(fn ($r) => $r[0] === 'Менеджер А' && $r[1] === '06.05.2026');
        $this->assertEquals(2, $row[2]);     // bookings
        $this->assertEquals(10000, $row[3]); // sum
        $this->assertEquals(5000, $row[4]);  // paid
    }
}

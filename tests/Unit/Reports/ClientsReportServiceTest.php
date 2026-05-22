<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\ClubClient;
use App\Models\User;
use App\Reports\ClientsReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClientsReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_visits_counts_matching_bookings(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $u = User::factory()->create();
        ClubClient::create(['club_id' => $club->id, 'name' => 'Иван', 'phone' => '77011112233']);

        // matching booking stored without leading 7
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-02', 'start_time' => '08:00', 'end_time' => '09:00', 'client_name' => 'Иван', 'client_phone' => '7011112233', 'price' => 5000, 'discount' => 0, 'status' => 'confirmed', 'is_paid' => true, 'booked_by' => $u->id]);
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-05', 'start_time' => '08:00', 'end_time' => '09:00', 'client_name' => 'Иван', 'client_phone' => '77011112233', 'price' => 5000, 'discount' => 1000, 'status' => 'confirmed', 'is_paid' => true, 'booked_by' => $u->id]);

        $sheet = (new ClientsReportService())->visits($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($sheet->rows)->firstWhere(0, 'Иван');
        $this->assertEquals(2, $row[2]);          // visits
        $this->assertEquals(9000, $row[3]);       // amount (5000 + (5000-1000))
        $this->assertEquals('05.05.2026', $row[4]); // last visit
    }
}

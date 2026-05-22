<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Reports\FinanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FinanceReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private Club $club; private Court $court; private User $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'C', 'address' => 'A']);
        $this->court = Court::create(['club_id' => $this->club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $this->u = User::factory()->create();
        CourtBooking::create(['court_id' => $this->court->id, 'date' => '2026-05-04', 'start_time' => '08:00', 'end_time' => '09:00', 'client_name' => 'К1', 'price' => 5000, 'discount' => 0, 'payment_method' => 'cash', 'is_paid' => true, 'status' => 'confirmed', 'booked_by' => $this->u->id]);
        CourtBooking::create(['court_id' => $this->court->id, 'date' => '2026-05-04', 'start_time' => '09:00', 'end_time' => '10:00', 'client_name' => 'К2', 'price' => 6000, 'discount' => 1000, 'payment_method' => 'card', 'is_paid' => false, 'status' => 'confirmed', 'booked_by' => $this->u->id]);
    }

    public function test_sales_lists_rows_and_totals(): void
    {
        $sheet = (new FinanceReportService())->sales($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $this->assertCount(2, $sheet->rows);
        $this->assertEquals(10000, $sheet->totals[5]); // amount total (5000 + 5000)
    }

    public function test_by_days_aggregates(): void
    {
        $sheet = (new FinanceReportService())->byDays($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($sheet->rows)->firstWhere(0, '04.05.2026');
        $this->assertEquals(2, $row[1]);      // count
        $this->assertEquals(10000, $row[2]);  // sum
    }

    public function test_debts_only_unpaid(): void
    {
        $sheet = (new FinanceReportService())->debts($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $this->assertCount(1, $sheet->rows);
        $this->assertEquals(5000, $sheet->totals[4]); // debt total (6000-1000)
    }
}

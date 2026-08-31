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
        // 5000 + 6000: price уже итоговый, скидка показана отдельной колонкой.
        $this->assertEquals(11000, $sheet->totals[5]);
    }

    public function test_by_days_aggregates(): void
    {
        $sheet = (new FinanceReportService())->byDays($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($sheet->rows)->firstWhere(0, '04.05.2026');
        $this->assertEquals(2, $row[1]);      // count
        // price уже за вычетом скидки: 5000 + 6000.
        $this->assertEquals(11000, $row[2]);
    }

    public function test_debts_only_unpaid(): void
    {
        $sheet = (new FinanceReportService())->debts($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $this->assertCount(1, $sheet->rows);
        // Колонки: Дата, Время, Корт, Клиент, Телефон, Сумма, Менеджер.
        $this->assertEquals(6000, $sheet->totals[5], 'должен ровно столько, сколько стоит бронь');
        $this->assertMatchesRegularExpression('/^\d\d:\d\d–\d\d:\d\d$/u', $sheet->rows[0][1],
            'во второй колонке должно быть время брони');
    }

    public function test_sales_amount_includes_coach_cost(): void
    {
        $coachUser = \App\Models\User::factory()->create();
        $cc = \App\Models\ClubCoach::create(['club_id' => $this->club->id, 'user_id' => $coachUser->id, 'hourly_rate' => 4000]);
        \App\Models\CoachRate::create(['club_coach_id' => $cc->id, 'hours' => 1, 'rate' => 5000]);
        // 1h booking with coach: court 5000 + coach 5000 = 10000
        \App\Models\CourtBooking::create([
            'court_id' => $this->court->id, 'date' => '2026-05-04', 'start_time' => '15:00', 'end_time' => '16:00',
            'client_name' => 'CoachClient', 'price' => 5000, 'discount' => 0, 'payment_method' => 'cash',
            'is_paid' => true, 'status' => 'confirmed', 'booked_by' => $this->u->id,
            'coach_id' => $coachUser->id, 'coach_paid' => true,
        ]);

        $sheet = (new FinanceReportService())->sales($this->club, \Carbon\Carbon::parse('2026-05-01'), \Carbon\Carbon::parse('2026-05-31'));
        $row = collect($sheet->rows)->firstWhere(3, 'CoachClient'); // client name column index 3
        $this->assertEquals(10000, $row[5]); // amount = court 5000 + coach 5000
    }
}

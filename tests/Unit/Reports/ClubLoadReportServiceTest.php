<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBlock;
use App\Models\CourtBooking;
use App\Models\User;
use App\Reports\ClubLoadReportService;
use App\Reports\ReportSheet;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubLoadReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->club = Club::create(['name' => 'C', 'address' => 'A']);
        $this->court = Court::create([
            'club_id' => $this->club->id, 'name' => 'K1',
            'open_time' => '08:00:00', 'close_time' => '10:00:00', 'slot_duration' => 60,
        ]);
        // 1 confirmed booking 08:00-09:00 on 2026-05-01 (Friday)
        CourtBooking::create([
            'court_id' => $this->court->id, 'date' => '2026-05-01',
            'start_time' => '08:00:00', 'end_time' => '09:00:00',
            'price' => 3000, 'status' => 'confirmed', 'is_paid' => true,
            'client_name' => 'Test User', 'booked_by' => $user->id,
        ]);
        // cancelled booking must be ignored
        CourtBooking::create([
            'court_id' => $this->court->id, 'date' => '2026-05-01',
            'start_time' => '09:00:00', 'end_time' => '10:00:00',
            'price' => 3000, 'status' => 'cancelled',
            'client_name' => 'Test User', 'booked_by' => $user->id,
        ]);
    }

    public function test_by_hours_returns_sheet_with_occupied_hour(): void
    {
        $svc = new ClubLoadReportService();
        $sheet = $svc->byHours($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'));
        $this->assertInstanceOf(ReportSheet::class, $sheet);
        // hour 08:00 row: occupied 1h, available 1 court * 1 day = 1h, load 100%
        $row08 = collect($sheet->rows)->firstWhere(0, '08:00');
        $this->assertEquals(1.0, $row08[1]);   // occupied
        $this->assertEquals(1.0, $row08[2]);   // available
        $this->assertEquals(1.0, $row08[3]);   // load fraction (formatted as 0%)
    }

    public function test_by_weekdays_groups_friday(): void
    {
        $svc = new ClubLoadReportService();
        $sheet = $svc->byWeekdays($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'));
        $friday = collect($sheet->rows)->firstWhere(0, 'Пт');
        $this->assertEquals(1.0, $friday[1]); // occupied hours
        $this->assertEquals(1, $friday[4]);   // bookings count
    }

    public function test_by_months_groups_may(): void
    {
        $svc = new ClubLoadReportService();
        $sheet = $svc->byMonths($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $may = collect($sheet->rows)->firstWhere(0, '05.2026');
        $this->assertEquals(1.0, $may[1]);
        $this->assertEquals(1, $may[4]);
    }

    public function test_court_block_reduces_available_hours_in_by_hours(): void
    {
        // Block 09:00-10:00 on 2026-05-01 — the only court's last open hour
        CourtBlock::create([
            'court_id'   => $this->court->id,
            'date'       => '2026-05-01',
            'start_time' => '09:00:00',
            'end_time'   => '10:00:00',
        ]);

        $svc = new ClubLoadReportService();
        $sheet = $svc->byHours($this->club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'));

        // 08:00 row: 1 court open, no block → available = 1.0
        $row08 = collect($sheet->rows)->firstWhere(0, '08:00');
        $this->assertEquals(1.0, $row08[2], '08:00 available should still be 1.0');

        // 09:00 row: 1 court open but fully blocked → available = 0.0
        $row09 = collect($sheet->rows)->firstWhere(0, '09:00');
        $this->assertEquals(0.0, $row09[2], '09:00 available should be 0.0 (blocked)');
    }

    public function test_by_hours_handles_overnight_and_24h_courts(): void
    {
        // Овернайт-корт: открыт 07:00, закрывается в 02:00 следующего дня
        $user = User::factory()->create();
        $club = Club::create(['name' => 'Night', 'address' => 'A']);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'N1',
            'open_time' => '07:00:00', 'close_time' => '02:00:00', 'slot_duration' => 60,
        ]);
        // Бронь 23:00–00:00 (1 час) на 2026-05-10
        CourtBooking::create([
            'court_id' => $court->id, 'date' => '2026-05-10',
            'start_time' => '23:00:00', 'end_time' => '00:00:00',
            'price' => 1000, 'status' => 'confirmed', 'is_paid' => true,
            'client_name' => 'X', 'booked_by' => $user->id,
        ]);

        $sheet = (new ClubLoadReportService())->byHours($club, Carbon::parse('2026-05-10'), Carbon::parse('2026-05-10'));

        // До фикса возвращалось 0 строк (окно minOpen..maxClose было пустым)
        $this->assertNotEmpty($sheet->rows);

        $row23 = collect($sheet->rows)->firstWhere(0, '23:00');
        $this->assertNotNull($row23, '23:00 должен присутствовать (корт открыт ночью)');
        $this->assertEquals(1.0, $row23[1]); // занято 1 час
        $this->assertEquals(1.0, $row23[2]); // доступно (корт открыт в 23:00, 1 день)

        // Час после полуночи тоже доступен (закрытие в 02:00)
        $row01 = collect($sheet->rows)->firstWhere(0, '01:00');
        $this->assertNotNull($row01, '01:00 должен присутствовать (закрытие в 02:00)');
        $this->assertEquals(1.0, $row01[2]);

        // Час, когда корт закрыт (05:00), отсутствует
        $this->assertNull(collect($sheet->rows)->firstWhere(0, '05:00'));
    }
}

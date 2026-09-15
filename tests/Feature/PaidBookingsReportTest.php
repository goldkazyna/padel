<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Reports\FinanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отчёт «Оплаченные брони»: за что клуб получил деньги и кто это провёл.
 *
 * Групповые и турнирные брони в него не попадают: за группу платят пакетами
 * участников, за турнир — взносами, и в выручке по броням они дали бы суммы,
 * которых в кассе не было.
 */
class PaidBookingsReportTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Court $court;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'C', 'address' => 'A']);
        $this->court = Court::create([
            'club_id' => $this->club->id, 'name' => 'К1',
            'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60,
        ]);
        $this->manager = User::factory()->create(['role' => 'club_moderator', 'name' => 'Асель М.']);
    }

    private function booking(array $attrs = []): CourtBooking
    {
        return CourtBooking::create(array_merge([
            'court_id' => $this->court->id,
            'date' => '2026-09-05',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Иван Петров',
            'client_phone' => '77011112233',
            'price' => 10000,
            'status' => 'confirmed',
            'is_paid' => true,
            'payment_method' => 'kaspi',
            'booked_by' => $this->manager->id,
        ], $attrs));
    }

    private function sheet()
    {
        return app(FinanceReportService::class)->paidBookings(
            $this->club,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
        );
    }

    public function test_в_отчёт_идут_только_оплаченные_личные_брони(): void
    {
        $this->booking(['price' => 12000]);
        $this->booking(['price' => 8000, 'start_time' => '11:00', 'end_time' => '12:00', 'booking_type' => 'individual']);

        // Не должны попасть:
        $this->booking(['price' => 5000, 'is_paid' => false, 'start_time' => '12:00', 'end_time' => '13:00']);
        $this->booking(['price' => 40000, 'booking_type' => 'group', 'client_name' => 'Группа: Г10',
            'start_time' => '13:00', 'end_time' => '15:00']);
        $this->booking(['price' => 50000, 'booking_type' => 'tournament', 'client_name' => 'Турнир: Американо',
            'start_time' => '15:00', 'end_time' => '17:00']);

        $sheet = $this->sheet();
        $clients = array_column($sheet->rows, 3);

        $this->assertContains('Иван Петров', $clients);
        $this->assertNotContains('Группа: Г10', $clients);
        $this->assertNotContains('Турнир: Американо', $clients);
        $this->assertSame(20000.0, $sheet->totals[5], 'итог — только оплаченные корты');
    }

    public function test_видно_кто_из_менеджеров_провёл(): void
    {
        $other = User::factory()->create(['role' => 'club_moderator', 'name' => 'Дана К.']);

        $this->booking(['price' => 12000]);
        $this->booking(['price' => 3000, 'start_time' => '11:00', 'end_time' => '12:00', 'booked_by' => $other->id]);

        $sheet = $this->sheet();

        // Менеджер — последняя колонка каждой строки с бронью.
        $this->assertSame('Асель М.', $sheet->rows[0][9]);
        $this->assertSame('Дана К.', $sheet->rows[1][9]);

        // Свод по менеджерам идёт под таблицей, самый результативный — первым.
        $summary = array_slice($sheet->rows, 2);
        $labels = array_column($summary, 0);
        $this->assertContains('По менеджерам', $labels);
        $this->assertSame('Асель М.', $summary[2][0]);
        $this->assertSame(12000.0, $summary[2][5]);
        $this->assertSame(1, $summary[2][6]);
    }

    public function test_способ_оплаты_пишем_по_русски(): void
    {
        $this->booking(['payment_method' => 'kaspi']);
        $this->booking(['payment_method' => 'cash', 'start_time' => '11:00', 'end_time' => '12:00']);

        $sheet = $this->sheet();

        $this->assertSame('Kaspi', $sheet->rows[0][7]);
        $this->assertSame('Наличные', $sheet->rows[1][7]);
    }

    public function test_страница_отчётов_показывает_новый_отчёт(): void
    {
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($this->club->id);

        $this->actingAs($admin)
            ->get('/club/reports/extra')
            ->assertOk()
            ->assertSee('Оплаченные брони', false);

        $response = $this->actingAs($admin)
            ->get('/club/reports/extra/finance-paid?from=2026-09-01&to=2026-09-30')
            ->assertOk();

        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }
}

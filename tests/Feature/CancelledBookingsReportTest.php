<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Reports\CancelledBookingsReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отчёт «Отменённые брони»: когда, кто и почему снял корт.
 *
 * Причина лежит в самой броне, а кто нажал — только в журнале действий;
 * отчёт сводит это вместе.
 */
class CancelledBookingsReportTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Court $court;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Padel Hills', 'address' => 'А', 'city' => 'Алматы']);
        $this->court = Court::create([
            'club_id' => $this->club->id, 'name' => 'Корт 1',
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $this->manager = User::factory()->create(['role' => 'club_admin', 'name' => 'Айгерим М.']);
        $this->manager->adminClubs()->attach($this->club->id);
    }

    private function booking(array $over = []): CourtBooking
    {
        return CourtBooking::create(array_merge([
            'court_id' => $this->court->id,
            'date' => '2026-05-12',
            'start_time' => '19:00',
            'end_time' => '20:00',
            'client_name' => 'Дамир К.',
            'client_phone' => '77771112233',
            'price' => 12000,
            'status' => 'cancelled',
            'cancelled_at' => '2026-05-10 14:30:00',
            'cancel_reason' => 'Клиент заболел',
            'booked_by' => $this->manager->id,
        ], $over));
    }

    private function sheet(string $from = '2026-05-01', string $to = '2026-05-31')
    {
        return app(CancelledBookingsReportService::class)
            ->list($this->club, Carbon::parse($from), Carbon::parse($to));
    }

    public function test_строка_содержит_всё_нужное(): void
    {
        $booking = $this->booking();
        ActivityLog::create([
            'user_id' => $this->manager->id,
            'club_id' => $this->club->id,
            'action' => 'cancelled',
            'subject_type' => 'CourtBooking',
            'subject_id' => $booking->id,
            'description' => 'Отмена брони: Дамир К., Корт 1',
        ]);

        $row = $this->sheet()->rows[0];

        $this->assertSame('10.05.2026 14:30', $row[0], 'когда отменили');
        $this->assertSame('Айгерим М.', $row[1], 'кто отменил');
        $this->assertSame('Клуб', $row[2], 'откуда');
        $this->assertSame('12.05.2026', $row[3], 'дата брони');
        $this->assertSame('19:00–20:00', $row[4]);
        $this->assertSame('Корт 1', $row[5]);
        $this->assertSame('Дамир К.', $row[6]);
        $this->assertSame('77771112233', $row[7]);
        $this->assertSame(12000.0, $row[8], 'сумма');
        $this->assertSame('Клиент заболел', $row[9], 'причина');
    }

    public function test_отмена_из_приложения_видна_отдельно(): void
    {
        $booking = $this->booking(['cancel_reason' => null]);
        $client = User::factory()->create(['name' => 'Дамир Кульмагамбетов']);
        ActivityLog::create([
            'user_id' => $client->id,
            'club_id' => $this->club->id,
            'action' => 'cancelled',
            'subject_type' => 'CourtBooking',
            'subject_id' => $booking->id,
            'description' => 'Отмена брони из приложения: Дамир Кульмагамбетов, Корт 1, 19:00',
        ]);

        $row = $this->sheet()->rows[0];

        $this->assertSame('Дамир Кульмагамбетов', $row[1]);
        $this->assertSame('Приложение', $row[2]);
        $this->assertSame('', $row[9], 'из приложения причину не спрашиваем');
    }

    public function test_период_считается_по_дате_отмены(): void
    {
        // Бронь стояла на май, сняли в апреле — ищем по апрелю.
        $this->booking(['cancelled_at' => '2026-04-20 10:00:00']);

        $this->assertCount(0, $this->sheet('2026-05-01', '2026-05-31')->rows);
        $this->assertCount(1, $this->sheet('2026-04-01', '2026-04-30')->rows);
    }

    public function test_подтверждённые_брони_в_отчёт_не_попадают(): void
    {
        $this->booking(['status' => 'confirmed', 'cancelled_at' => null, 'cancel_reason' => null]);

        $this->assertCount(0, $this->sheet()->rows);
    }

    public function test_отчёт_скачивается(): void
    {
        $this->booking();

        $resp = $this->actingAs($this->manager)
            ->get('/club/reports/extra/bookings-cancelled?from=2026-05-01&to=2026-05-31');

        $resp->assertOk();
        $this->assertStringContainsString('spreadsheetml', $resp->headers->get('content-type'));
    }

    public function test_отчёт_есть_в_списке(): void
    {
        $this->actingAs($this->manager)
            ->get('/club/reports/extra')
            ->assertOk()
            ->assertSee('Отменённые брони');
    }
}

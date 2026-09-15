<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Долги в разрезе клиента: только то, за что человек правда должен.
 *
 * Групповые и турнирные брони попадали в список как долг, хотя за группу
 * платят пакетами участников, а за турнир — взносами. В разговоре «за что вы
 * должны» такие строки только мешали.
 */
class DebtsByClientReportTest extends TestCase
{
    use RefreshDatabase;

    private function booking(Court $court, User $author, array $attrs = []): CourtBooking
    {
        return CourtBooking::create(array_merge([
            'court_id' => $court->id,
            'date' => '2026-09-01',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Иван Петров',
            'client_phone' => '77011112233',
            'price' => 10000,
            'status' => 'confirmed',
            'is_paid' => false,
            'booked_by' => $author->id,
        ], $attrs));
    }

    public function test_группы_и_турниры_не_считаются_долгом(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'К1',
            'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60,
        ]);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        // Личная бронь — это долг.
        $this->booking($court, $admin, ['booking_type' => 'individual', 'price' => 12000]);
        // Старая бронь без типа — тоже личная.
        $this->booking($court, $admin, ['booking_type' => null, 'start_time' => '11:00', 'end_time' => '12:00', 'price' => 3000]);
        // Эти в отчёт попадать не должны.
        $this->booking($court, $admin, ['booking_type' => 'group', 'client_name' => 'Группа: Г10', 'price' => 40000]);
        $this->booking($court, $admin, ['booking_type' => 'tournament', 'client_name' => 'Турнир: Американо', 'price' => 50000]);

        $response = $this->actingAs($admin)
            ->get('/club/reports/debts-by-client?from=2026-08-01&to=2026-09-15')
            ->assertOk();

        $response->assertSee('Иван Петров');
        $response->assertDontSee('Группа: Г10');
        $response->assertDontSee('Турнир: Американо');

        // Итог — только личные брони: 12 000 + 3 000.
        $this->assertEquals(15000, $response->viewData('totalDebt'));
    }
}

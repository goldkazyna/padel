<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AdditionalReportsTest extends TestCase
{
    use RefreshDatabase;

    private function clubAdmin(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        return [$club, $admin];
    }

    public function test_each_report_downloads_xlsx(): void
    {
        [, $admin] = $this->clubAdmin();
        $slugs = ['club-hours','club-weekdays','club-months','clients-visits','coaches-usage','coaches-sessions','coaches-salary','finance-sales','finance-days','finance-weeks','finance-months','finance-debts','managers-sales','cards-sales','cards-charges'];
        foreach ($slugs as $slug) {
            $resp = $this->actingAs($admin)->get("/club/reports/extra/{$slug}?from=2026-05-01&to=2026-05-31");
            $resp->assertOk();
            $this->assertStringContainsString('spreadsheetml', $resp->headers->get('content-type'));
        }
    }

    public function test_each_report_downloads_pdf(): void
    {
        [, $admin] = $this->clubAdmin();
        $slugs = ['income-breakdown','club-hours','clients-visits','bookings-cancelled','coaches-salary','finance-paid','finance-sales','finance-debts','managers-sales','cards-sales'];
        foreach ($slugs as $slug) {
            $resp = $this->actingAs($admin)
                ->get("/club/reports/extra/{$slug}?from=2026-05-01&to=2026-05-31&format=pdf");

            $resp->assertOk();
            $this->assertSame('application/pdf', $resp->headers->get('content-type'), $slug);
            $this->assertStringStartsWith('%PDF', $resp->getContent(), "{$slug}: это не PDF");
        }
    }

    public function test_format_switch_is_remembered_on_page(): void
    {
        [, $admin] = $this->clubAdmin();

        $this->actingAs($admin)->get('/club/reports/extra?format=pdf')
            ->assertOk()
            ->assertSee('format=pdf', false);

        // Без параметра — как раньше, Excel.
        $this->actingAs($admin)->get('/club/reports/extra')
            ->assertOk()
            ->assertSee('format=xlsx', false);
    }

    public function test_unknown_report_404(): void
    {
        [, $admin] = $this->clubAdmin();
        $this->actingAs($admin)->get('/club/reports/extra/nope')->assertNotFound();
    }

    public function test_index_page_loads(): void
    {
        [, $admin] = $this->clubAdmin();
        $this->actingAs($admin)->get('/club/reports/extra')->assertOk()->assertSee('Дополнительные отчёты');
    }
}

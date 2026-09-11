<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отчёты менеджерам открывает сам клуб.
 *
 * В отчётах выручка, долги и зарплата тренеров, поэтому по умолчанию они
 * только у владельца; выключатель в настройках открывает их менеджерам.
 */
class ManagerReportsAccessTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Padel Hills', 'address' => 'А', 'city' => 'Алматы']);
        Court::create([
            'club_id' => $this->club->id, 'name' => 'Корт 1',
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);

        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        $this->manager = User::factory()->create(['role' => 'club_moderator']);
        $this->manager->moderatorClubs()->attach($this->club->id, [
            'tournaments_full_access' => true,
            'can_view_activity_log' => true,
        ]);
    }

    private const URLS = [
        '/club/reports',
        '/club/reports/extra',
        '/club/reports/extra/finance-sales',
        '/club/reports/debts-by-client',
    ];

    public function test_по_умолчанию_менеджер_в_отчёты_не_ходит(): void
    {
        foreach (self::URLS as $url) {
            $this->actingAs($this->manager)->get($url)->assertForbidden();
        }
    }

    public function test_клуб_может_открыть_отчёты_менеджерам(): void
    {
        $this->club->update(['moderators_can_view_reports' => true]);

        foreach (self::URLS as $url) {
            $this->actingAs($this->manager)->get($url)->assertOk();
        }
    }

    public function test_владельцу_отчёты_доступны_всегда(): void
    {
        foreach (self::URLS as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_выключатель_сохраняется_из_настроек(): void
    {
        $this->actingAs($this->admin)->put('/club/settings/club', [
            'moderators_can_view_reports' => '1',
            'booking_cancel_hours' => 2,
        ])->assertRedirect();

        $this->assertTrue((bool) $this->club->fresh()->moderators_can_view_reports);

        $this->actingAs($this->admin)->put('/club/settings/club', [
            'booking_cancel_hours' => 2,
        ])->assertRedirect();

        $this->assertFalse((bool) $this->club->fresh()->moderators_can_view_reports);
    }

    public function test_ссылка_в_меню_появляется_вместе_с_правом(): void
    {
        $html = $this->actingAs($this->manager)->get('/club/courts/schedule')->getContent();
        $this->assertStringNotContainsString('club.reports', $html);
        $this->assertStringNotContainsString('>Отчёты<', $html);

        $this->club->update(['moderators_can_view_reports' => true]);

        $html = $this->actingAs($this->manager)->get('/club/courts/schedule')->getContent();
        $this->assertStringContainsString('>Отчёты<', $html);
    }
}

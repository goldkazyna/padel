<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Настройки клуба разложены по вкладкам.
 *
 * Всё лежало одной простынёй: профиль, галочки записи, дизайн карты и
 * телеграм-бот в одной карточке — до нужного приходилось крутить.
 */
class ClubSettingsTabsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $club = Club::create(['name' => 'Padel Hills', 'address' => 'А', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        return $admin;
    }

    public function test_вкладки_и_панели_на_месте(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/club/settings')
            ->assertOk()
            ->getContent();

        foreach (['club', 'card', 'notify', 'profile', 'security'] as $tab) {
            $this->assertStringContainsString('data-tab="' . $tab . '"', $html, "вкладка $tab");
            $this->assertStringContainsString('data-panel="' . $tab . '"', $html, "панель $tab");
        }

        // Сразу открыт «Клуб», остальные скрыты до клика.
        $this->assertStringContainsString('settings-tab is-active" data-tab="club"', $html);
    }

    public function test_поля_никуда_не_делись(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/club/settings')
            ->assertOk()
            ->getContent();

        foreach ([
            'name="allow_booking_without_payment"',
            'name="tournament_payment_enabled"',
            'name="auto_conduct_group_sessions"',
            'name="booking_cancel_hours"',
            'name="card_bg_color"',
            'name="card_accent_color"',
            'name="card_progress_color"',
            'name="telegram_notify_enabled"',
            'name="telegram_bot_token"',
            'name="telegram_chat_ids"',
            'name="current_password"',
        ] as $field) {
            $this->assertStringContainsString($field, $html, "поле $field");
        }
    }

    public function test_сохранение_клуба_работает_с_любой_вкладки(): void
    {
        $admin = $this->admin();
        $club = $admin->adminClubs()->first();

        // Форма клуба одна на три вкладки: «Сохранить» на карте шлёт всё.
        $this->actingAs($admin)->put('/club/settings/club', [
            'allow_booking_without_payment' => '1',
            'booking_cancel_hours' => 24,
            'card_bg_color' => '#1E2A78',
            'card_accent_color' => '#3B82F6',
            'card_progress_color' => '#C0392B',
            'telegram_notify_enabled' => '0',
        ])->assertRedirect();

        $club->refresh();
        $this->assertSame(24, (int) $club->booking_cancel_hours);
        $this->assertSame('#1E2A78', $club->card_bg_color);
    }
}

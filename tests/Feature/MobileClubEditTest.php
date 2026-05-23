<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileClubEditTest extends TestCase
{
    use RefreshDatabase;

    private function club(): Club
    {
        return Club::create([
            'name' => 'Old', 'address' => 'Old addr', 'city' => 'Алматы',
            'is_active' => true, 'features' => ['tournaments' => true],
            'telegram_channel_id' => 'chan-1', 'telegram_bot_token' => 'tok-1',
        ]);
    }

    public function test_owner_can_show_and_update_club(): void
    {
        $club = $this->club();
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);

        $this->getJson("/api/mobile/admin/clubs/{$club->id}")
            ->assertOk()->assertJsonPath('club.name', 'Old');

        $this->putJson("/api/mobile/admin/clubs/{$club->id}", [
            'name' => 'New name', 'address' => 'New addr', 'city' => 'Астана',
            'phone' => '+7700', 'email' => 'a@b.kz', 'description' => 'desc',
            'payment_url' => 'https://pay.kz/x',
        ])->assertOk()->assertJsonPath('club.name', 'New name');

        $club->refresh();
        $this->assertSame('New name', $club->name);
        $this->assertSame('Астана', $club->city);
        $this->assertSame('https://pay.kz/x', $club->payment_url);
    }

    public function test_update_does_not_touch_superadmin_fields(): void
    {
        $club = $this->club();
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);

        $this->putJson("/api/mobile/admin/clubs/{$club->id}", [
            'name' => 'X', 'address' => 'Y',
            'is_active' => false, 'telegram_bot_token' => 'HACKED',
            'features' => ['tournaments' => false],
        ])->assertOk();

        $club->refresh();
        $this->assertTrue((bool) $club->is_active, 'is_active must be unchanged');
        $this->assertSame('tok-1', $club->telegram_bot_token, 'bot token unchanged');
        $this->assertSame(['tournaments' => true], $club->features, 'features unchanged');
    }

    public function test_validation_errors(): void
    {
        $club = $this->club();
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);

        $this->putJson("/api/mobile/admin/clubs/{$club->id}", ['name' => 'X', 'address' => 'Y', 'city' => 'Париж'])->assertStatus(422);
        $this->putJson("/api/mobile/admin/clubs/{$club->id}", ['name' => 'X', 'address' => 'Y', 'email' => 'not-email'])->assertStatus(422);
        $this->putJson("/api/mobile/admin/clubs/{$club->id}", ['name' => 'X', 'address' => 'Y', 'payment_url' => 'not-url'])->assertStatus(422);
    }

    public function test_owner_can_set_telegram_url_and_show_returns_it(): void
    {
        $club = $this->club();
        $admin = \App\Models\User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $this->putJson("/api/mobile/admin/clubs/{$club->id}", [
            'name' => 'X', 'address' => 'Y', 'telegram_url' => 'https://t.me/myclub',
        ])->assertOk()->assertJsonPath('club.telegram_url', 'https://t.me/myclub');

        $this->assertSame('https://t.me/myclub', $club->refresh()->telegram_url);

        $this->getJson("/api/mobile/admin/clubs/{$club->id}")
            ->assertOk()->assertJsonPath('club.telegram_url', 'https://t.me/myclub');

        $this->putJson("/api/mobile/admin/clubs/{$club->id}", [
            'name' => 'X', 'address' => 'Y', 'telegram_url' => 'not-a-url',
        ])->assertStatus(422);
    }

    public function test_moderator_forbidden(): void
    {
        $club = $this->club();
        $mod = User::factory()->create(['role' => 'club_moderator']);
        $mod->moderatorClubs()->attach($club->id);
        Sanctum::actingAs($mod);

        $this->getJson("/api/mobile/admin/clubs/{$club->id}")->assertStatus(403);
        $this->putJson("/api/mobile/admin/clubs/{$club->id}", ['name' => 'X', 'address' => 'Y'])->assertStatus(403);
    }
}

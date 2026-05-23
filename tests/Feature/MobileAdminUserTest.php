<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileAdminUserTest extends TestCase
{
    use RefreshDatabase;

    private function club(): Club
    {
        return Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
    }

    public function test_index_payload_has_avatar_url_and_no_phone(): void
    {
        $club = $this->club();
        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory()->create([
            'name' => 'Иван',
            'phone' => '77770001122',
            'avatar' => 'https://cdn.example.com/a.jpg',
        ]);

        Sanctum::actingAs($admin);

        $res = $this->getJson("/api/mobile/admin/clubs/{$club->id}/users")
            ->assertOk()
            ->assertJsonPath('users.0.avatar_url', 'https://cdn.example.com/a.jpg');

        $this->assertArrayNotHasKey('phone', $res->json('users.0'));
    }

    public function test_search_does_not_match_phone(): void
    {
        $club = $this->club();
        $admin = User::factory()->create(['role' => 'super_admin']);
        User::factory()->create(['name' => 'Пётр', 'phone' => '77771234567']);

        Sanctum::actingAs($admin);

        $this->getJson("/api/mobile/admin/clubs/{$club->id}/users?search=1234567")
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_update_verifies_only_with_avatar(): void
    {
        $club = $this->club();
        $admin = User::factory()->create(['role' => 'super_admin']);

        $withAvatar = User::factory()->create([
            'avatar' => 'https://cdn.example.com/a.jpg',
            'level_verified' => false,
        ]);
        $noAvatar = User::factory()->create([
            'avatar' => null,
            'level_verified' => false,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/mobile/admin/clubs/{$club->id}/users/{$withAvatar->id}", [
            'name' => $withAvatar->name,
            'level' => 3.0,
        ])->assertOk();

        $this->putJson("/api/mobile/admin/clubs/{$club->id}/users/{$noAvatar->id}", [
            'name' => $noAvatar->name,
            'level' => 3.0,
        ])->assertOk();

        $this->assertTrue((bool) $withAvatar->fresh()->level_verified);
        $this->assertFalse((bool) $noAvatar->fresh()->level_verified);
        $this->assertSame(3.0, (float) $noAvatar->fresh()->level);
    }
}

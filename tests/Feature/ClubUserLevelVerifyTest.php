<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubUserLevelVerifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_level_verifies_when_user_has_avatar(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $player = User::factory()->create([
            'avatar' => 'https://example.com/a.jpg',
            'level_verified' => false,
        ]);

        $this->actingAs($admin)
            ->put(route('club.users.update', $player), [
                'name' => $player->name,
                'level' => 3.0,
            ])
            ->assertRedirect();

        $this->assertTrue((bool) $player->fresh()->level_verified);
    }

    public function test_setting_level_does_not_verify_without_avatar(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $player = User::factory()->create([
            'avatar' => null,
            'level_verified' => false,
        ]);

        $this->actingAs($admin)
            ->put(route('club.users.update', $player), [
                'name' => $player->name,
                'level' => 3.0,
            ])
            ->assertRedirect();

        $fresh = $player->fresh();
        $this->assertFalse((bool) $fresh->level_verified);
        // Уровень при этом всё равно выставлен
        $this->assertSame(3.0, (float) $fresh->level);
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationTimerStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_admin_store_saves_moderation_hours(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);

        $payload = [
            'name' => 'Кубок',
            'type' => 'americano',
            'max_participants' => 8,
            'min_level' => 1,
            'max_level' => 5,
            'status' => 'open',
            'start_date' => now()->addDays(3)->toIso8601String(),
            'moderation_hours' => 48,
        ];
        $res = $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", $payload);
        $res->assertOk();

        $this->assertSame(48, (int) Tournament::first()->moderation_hours);
    }

    public function test_mobile_admin_can_edit_moderation_timer(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 8, 'min_level' => 1, 'max_level' => 5,
            'start_date' => now()->addDays(3), 'moderation_hours' => 48,
        ]);
        Sanctum::actingAs($admin);

        // Меняем таймер 48 → 49 и проверяем, что detail отдаёт новое значение.
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", [
            'name' => 'Кубок',
            'start_date' => now()->addDays(3)->toIso8601String(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
            'moderation_hours' => 49,
        ])
            ->assertOk()
            ->assertJsonPath('tournament.moderation_hours', 49);

        $this->assertSame(49, (int) $t->fresh()->moderation_hours);
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileAdminModeratorTest extends TestCase
{
    use RefreshDatabase;

    private function clubWithAdmin(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        return [$club, $admin];
    }

    public function test_add_moderator_by_user_id_sets_role_and_pivot(): void
    {
        [$club, $admin] = $this->clubWithAdmin();
        $player = User::factory()->create(['role' => 'player', 'phone' => '77770001122']);

        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/moderators", [
            'user_id' => $player->id,
            'tournaments_full_access' => true,
            'can_view_activity_log' => false,
        ])
            ->assertOk()
            ->assertJsonPath('moderator.id', $player->id)
            ->assertJsonPath('moderator.tournaments_full_access', true)
            ->assertJsonPath('moderator.can_view_activity_log', false);

        $this->assertSame('club_moderator', $player->fresh()->role);
        $this->assertTrue($club->moderators()->where('user_id', $player->id)->exists());
    }

    public function test_search_finds_by_phone_and_excludes_existing(): void
    {
        [$club, $admin] = $this->clubWithAdmin();
        $found = User::factory()->create(['role' => 'player', 'phone' => '77771234567', 'name' => 'Иван']);
        $already = User::factory()->create(['role' => 'player', 'phone' => '77771230000']);
        $club->moderators()->attach($already->id, [
            'tournaments_full_access' => false, 'can_view_activity_log' => false,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/mobile/admin/clubs/{$club->id}/moderators/search?phone=7777123")
            ->assertOk()
            ->assertJsonFragment(['id' => $found->id])
            ->assertJsonMissing(['id' => $already->id]);
    }

    public function test_duplicate_moderator_rejected(): void
    {
        [$club, $admin] = $this->clubWithAdmin();
        $player = User::factory()->create(['role' => 'player']);
        $club->moderators()->attach($player->id, [
            'tournaments_full_access' => false, 'can_view_activity_log' => false,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/moderators", [
            'user_id' => $player->id,
        ])->assertStatus(422);
    }

    public function test_update_permissions(): void
    {
        [$club, $admin] = $this->clubWithAdmin();
        $mod = User::factory()->create(['role' => 'club_moderator']);
        $club->moderators()->attach($mod->id, [
            'tournaments_full_access' => false, 'can_view_activity_log' => false,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/mobile/admin/clubs/{$club->id}/moderators/{$mod->id}/permissions", [
            'tournaments_full_access' => true,
            'can_view_activity_log' => true,
        ])
            ->assertOk()
            ->assertJsonPath('moderator.tournaments_full_access', true)
            ->assertJsonPath('moderator.can_view_activity_log', true);
    }

    public function test_destroy_detaches_and_reverts_role(): void
    {
        [$club, $admin] = $this->clubWithAdmin();
        $mod = User::factory()->create(['role' => 'club_moderator']);
        $club->moderators()->attach($mod->id, [
            'tournaments_full_access' => false, 'can_view_activity_log' => false,
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/mobile/admin/clubs/{$club->id}/moderators/{$mod->id}")
            ->assertOk();

        $this->assertFalse($club->moderators()->where('user_id', $mod->id)->exists());
        $this->assertSame('player', $mod->fresh()->role);
    }

    public function test_non_admin_forbidden(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $stranger = User::factory()->create(['role' => 'player']);

        Sanctum::actingAs($stranger);

        $this->getJson("/api/mobile/admin/clubs/{$club->id}/moderators")
            ->assertStatus(403);
    }
}

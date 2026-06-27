<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAdminKocPairsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTournament(int $count = 8): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $t = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'king_of_court',
            'is_paired' => true,
            'status' => 'open',
            'max_participants' => $count,
        ]);
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $u = User::factory()->create(['rating' => 1200 + $i * 30]);
            TournamentParticipant::create(['tournament_id' => $t->id, 'user_id' => $u->id, 'status' => 'registered']);
            $users[] = $u;
        }
        return [$club, $admin, $t, $users];
    }

    public function test_create_paired_koc_via_mobile(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", [
            'type' => 'king_of_court',
            'name' => 'КК пары',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1.0, 'max_level' => 5.0,
            'max_participants' => 8,
            'status' => 'open',
            'is_paired' => true,
        ])->assertOk()->assertJsonPath('success', true);

        $t = Tournament::where('type', 'king_of_court')->first();
        $this->assertTrue((bool) $t->is_paired);
    }

    public function test_start_requires_pairs(): void
    {
        [, $admin, $t] = $this->makeTournament(8);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('pairs_required', true);

        $this->assertSame('open', $t->fresh()->status);
    }

    public function test_save_pairs_then_start(): void
    {
        [, $admin, $t, $u] = $this->makeTournament(8);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/kingofcourt/pairs", [
            'pairs' => [
                [$u[0]->id, $u[1]->id], [$u[2]->id, $u[3]->id],
                [$u[4]->id, $u[5]->id], [$u[6]->id, $u[7]->id],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(4, $t->kingOfCourtPairs()->count());

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame('in_progress', $t->fresh()->status);
    }
}

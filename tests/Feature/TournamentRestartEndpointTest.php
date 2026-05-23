<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\AmericanoRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class TournamentRestartEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $roundStatus): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'americano',
            'status' => 'in_progress', 'max_participants' => 8,
            'start_date' => now()->addDay(), 'registration_deadline' => now()->addHour(),
        ]);
        $g = TournamentGroup::create(['tournament_id' => $t->id, 'name' => 'A']);
        AmericanoRound::create(['tournament_group_id' => $g->id, 'round_number' => 1, 'status' => $roundStatus]);
        return [$admin, $t];
    }

    public function test_restart_reopens_when_allowed(): void
    {
        [$admin, $t] = $this->make('in_progress');
        Sanctum::actingAs($admin);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/restart")->assertOk();
        $this->assertEquals('open', $t->refresh()->status);
    }

    public function test_restart_rejected_after_first_round(): void
    {
        [$admin, $t] = $this->make('completed');
        Sanctum::actingAs($admin);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/restart")->assertStatus(422);
        $this->assertEquals('in_progress', $t->refresh()->status);
    }

    public function test_restart_forbidden_for_non_manager(): void
    {
        [, $t] = $this->make('in_progress');
        $other = User::factory()->create(['role' => 'player']);
        Sanctum::actingAs($other);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/restart")->assertForbidden();
    }
}

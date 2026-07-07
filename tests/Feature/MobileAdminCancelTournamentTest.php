<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileAdminCancelTournamentTest extends TestCase
{
    use RefreshDatabase;

    private function adminAndTournament(string $status): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano',
            'status' => $status,
            'max_participants' => 8,
        ]);
        return [$admin, $t];
    }

    public function test_admin_can_cancel_in_progress_tournament(): void
    {
        [$admin, $t] = $this->adminAndTournament('in_progress');
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/cancel")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('tournament.status', 'cancelled');

        $this->assertSame('cancelled', $t->fresh()->status);
    }

    public function test_cannot_cancel_completed_tournament(): void
    {
        [$admin, $t] = $this->adminAndTournament('completed');
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('completed', $t->fresh()->status);
    }
}

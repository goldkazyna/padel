<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileJustPadelItLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_endpoint_returns_jpi_payload(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = Tournament::factory()->create([
            'club_id' => $club->id, 'type' => 'just_padel_it',
            'status' => 'open', 'max_participants' => 8, 'courts_count' => 2,
        ]);
        $players = [];
        for ($i = 1; $i <= 8; $i++) {
            $u = User::factory()->create(['rating' => 1000 + $i * 100]);
            $players[] = $u;
            TournamentParticipant::create(['tournament_id' => $t->id, 'user_id' => $u->id, 'status' => 'registered']);
        }
        app(\App\Services\JustPadelItService::class)->startTournament($t);

        Sanctum::actingAs($players[0]);
        $this->getJson("/api/mobile/tournaments/{$t->id}/live")
            ->assertOk()
            ->assertJsonPath('tournament.format', 'just_padel_it')
            ->assertJsonStructure(['success', 'tournament', 'leaderboard', 'rounds', 'playoff']);
    }
}

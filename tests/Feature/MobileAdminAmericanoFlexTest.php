<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\AmericanoFlexMatch;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileAdminAmericanoFlexTest extends TestCase
{
    use RefreshDatabase;

    private function setupTournament(int $playersCount = 10, int $courts = 2): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano_flex',
            'status' => 'open',
            'max_participants' => $playersCount,
            'courts_count' => $courts,
        ]);

        for ($i = 1; $i <= $playersCount; $i++) {
            $user = User::factory()->create(['rating' => 1500]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
        }

        return [$club, $admin, $tournament];
    }

    public function test_admin_creates_flex_tournament_via_mobile(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", [
            'type' => 'americano_flex',
            'name' => 'Flex турнир',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1.0,
            'max_level' => 5.0,
            'max_participants' => 12,
            'status' => 'open',
            'courts_count' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, Tournament::where('type', 'americano_flex')->count());
    }

    public function test_start_creates_first_round_and_matches_endpoint_returns_them(): void
    {
        [$club, $admin, $tournament] = $this->setupTournament(10, 2);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/start")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('in_progress', $tournament->fresh()->status);

        $resp = $this->getJson("/api/mobile/admin/tournaments/{$tournament->id}/matches");
        $resp->assertOk()
            ->assertJsonPath('type', 'americano_flex')
            ->assertJsonPath('groups.0.rounds.0.round_number', 1);

        $rounds = $resp->json('groups.0.rounds');
        $this->assertCount(1, $rounds);
        $this->assertCount(2, $rounds[0]['matches'], 'на 2 кортах 2 матча');
        $this->assertCount(2, $rounds[0]['byes'], '10 - 8 = 2 отдыхают');
    }

    public function test_save_score_then_next_round_then_finish(): void
    {
        [$club, $admin, $tournament] = $this->setupTournament(10, 2);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/start")->assertOk();

        $matches = AmericanoFlexMatch::query()
            ->whereHas('round', fn($q) => $q->where('tournament_id', $tournament->id))
            ->get();

        foreach ($matches as $m) {
            $this->postJson(
                "/api/mobile/admin/tournaments/{$tournament->id}/americano_flex/matches/{$m->id}/score",
                ['team1_score' => 24, 'team2_score' => 18]
            )->assertOk()->assertJsonPath('success', true);
        }

        // Можно сгенерить следующий раунд
        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/next-round")
            ->assertOk()
            ->assertJsonPath('groups.0.rounds.1.round_number', 2);

        // Доиграем второй раунд → завершим
        $r2matches = AmericanoFlexMatch::query()
            ->whereHas('round', fn($q) => $q->where('tournament_id', $tournament->id)->where('round_number', 2))
            ->get();
        foreach ($r2matches as $m) {
            $this->postJson(
                "/api/mobile/admin/tournaments/{$tournament->id}/americano_flex/matches/{$m->id}/score",
                ['team1_score' => 20, 'team2_score' => 22]
            )->assertOk();
        }

        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/finish")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $tournament->fresh()->status);
    }

    public function test_start_fails_when_not_enough_players(): void
    {
        [$club, $admin, $tournament] = $this->setupTournament(6, 2);  // нужно 8, есть 6
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/start")
            ->assertStatus(422);
    }
}

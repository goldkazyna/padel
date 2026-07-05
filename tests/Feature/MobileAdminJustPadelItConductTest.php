<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileAdminJustPadelItConductTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Club,1:User,2:Tournament} */
    private function makeTournament(bool $paired = false, int $players = 8, int $courts = 2): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $t = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'just_padel_it',
            'status' => 'open',
            'max_participants' => $players,
            'courts_count' => $courts,
            'is_paired' => $paired,
        ]);
        for ($i = 1; $i <= $players; $i++) {
            $u = User::factory()->create(['rating' => 1000 + $i * 100]);
            TournamentParticipant::create([
                'tournament_id' => $t->id,
                'user_id' => $u->id,
                'status' => 'registered',
            ]);
        }
        return [$club, $admin, $t];
    }

    public function test_solo_start_creates_first_round(): void
    {
        [$club, $admin, $t] = $this->makeTournament(false, 8, 2);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")
            ->assertOk()
            ->assertJsonPath('success', true);

        $t->refresh();
        $this->assertSame('in_progress', $t->status);
        $this->assertSame(1, $t->justPadelItRounds()->count());
    }

    public function test_seeding_endpoint_returns_participants_sorted_by_rating(): void
    {
        [$club, $admin, $t] = $this->makeTournament(false, 8, 2);
        Sanctum::actingAs($admin);

        $res = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/justpadelit/seeding")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('courts_count', 2);

        $ratings = array_column($res->json('participants'), 'rating');
        $sorted = $ratings;
        rsort($sorted);
        $this->assertSame($sorted, $ratings, 'participants must be sorted by rating desc');
    }

    public function test_paired_start_without_pairs_requires_pairs(): void
    {
        [$club, $admin, $t] = $this->makeTournament(true, 8, 2);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('pairs_required', true);
    }
}

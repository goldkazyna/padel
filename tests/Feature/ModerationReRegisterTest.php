<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationReRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_solo_is_not_reported_as_registered(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        $user = User::factory()->create(['level' => 3]);
        $t->participants()->attach($user->id, ['status' => 'cancelled']);
        Sanctum::actingAs($user);

        $this->getJson("/api/mobile/tournaments/{$t->id}")
            ->assertOk()
            ->assertJsonPath('tournament.is_registered', false)
            ->assertJsonPath('tournament.can_register', true);
    }

    public function test_can_reregister_team_after_rejected(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Team', 'type' => 'team',
            'status' => 'open', 'max_participants' => 4, 'waitlist_size' => 4, 'teams_advance' => 2,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        $me = User::factory()->create(['level' => 3]);
        $partner = User::factory()->create(['level' => 3]);
        TournamentTeam::create([
            'tournament_id' => $t->id, 'player1_id' => $me->id, 'player2_id' => $partner->id,
            'status' => 'rejected',
        ]);
        Sanctum::actingAs($me);

        // re-register the same pair
        $res = $this->postJson("/api/mobile/tournaments/{$t->id}/register-team", [
            'partner_id' => $partner->id,
        ]);

        // The prior `rejected` row must NOT block re-registration:
        // the endpoint succeeds and a fresh non-rejected team now exists for the pair.
        $res->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(
            TournamentTeam::where('tournament_id', $t->id)
                ->where('player1_id', $me->id)
                ->where('status', '!=', 'rejected')->exists(),
            'Pair should be able to re-register after rejected'
        );

        // The stale rejected row was cleaned up (no leftover duplicate).
        $this->assertFalse(
            TournamentTeam::where('tournament_id', $t->id)
                ->where('player1_id', $me->id)
                ->where('status', 'rejected')->exists(),
            'Stale rejected team row should be removed on re-registration'
        );
    }
}

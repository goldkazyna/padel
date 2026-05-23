<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\AmericanoRound;
use App\Models\TournamentTeamGroup;
use App\Models\TournamentGroupMatch;
use App\Models\TournamentTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TournamentRestartTest extends TestCase
{
    use RefreshDatabase;

    private function americano(string $status): Tournament
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        return Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'americano',
            'status' => $status, 'max_participants' => 8,
            'start_date' => now()->addDay(),
            'registration_deadline' => now()->addHour(),
        ]);
    }

    public function test_can_restart_true_when_in_progress_and_first_round_not_completed(): void
    {
        $t = $this->americano('in_progress');
        $g = TournamentGroup::create(['tournament_id' => $t->id, 'name' => 'A']);
        AmericanoRound::create(['tournament_group_id' => $g->id, 'round_number' => 1, 'status' => 'in_progress']);

        $this->assertFalse($t->firstRoundCompleted());
        $this->assertTrue($t->canRestart());
    }

    public function test_cannot_restart_after_first_round_completed(): void
    {
        $t = $this->americano('in_progress');
        $g = TournamentGroup::create(['tournament_id' => $t->id, 'name' => 'A']);
        AmericanoRound::create(['tournament_group_id' => $g->id, 'round_number' => 1, 'status' => 'completed']);

        $this->assertTrue($t->firstRoundCompleted());
        $this->assertFalse($t->canRestart());
    }

    public function test_cannot_restart_when_open_or_completed(): void
    {
        $this->assertFalse($this->americano('open')->canRestart());
        $this->assertFalse($this->americano('completed')->canRestart());
    }

    public function test_team_first_round_completed_via_group_match(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'team',
            'status' => 'in_progress', 'max_participants' => 8,
            'start_date' => now()->addDay(), 'registration_deadline' => now()->addHour(),
        ]);
        $group = TournamentTeamGroup::create([
            'tournament_id' => $t->id,
            'name' => 'A',
        ]);

        // No completed group match yet → can restart
        $this->assertFalse($t->firstRoundCompleted());
        $this->assertTrue($t->canRestart());

        // Need two users and a team for the FK constraints on team1_id / team2_id
        $user1 = User::create([
            'name' => 'Player1', 'first_name' => 'P1', 'last_name' => 'L1',
            'email' => 'p1@test.com', 'password' => bcrypt('secret'),
        ]);
        $user2 = User::create([
            'name' => 'Player2', 'first_name' => 'P2', 'last_name' => 'L2',
            'email' => 'p2@test.com', 'password' => bcrypt('secret'),
        ]);
        $team1 = TournamentTeam::create([
            'tournament_id' => $t->id,
            'player1_id' => $user1->id,
            'player2_id' => $user2->id,
        ]);
        $team2 = TournamentTeam::create([
            'tournament_id' => $t->id,
            'player1_id' => $user2->id,
            'player2_id' => $user1->id,
        ]);

        // Create a completed group match in this group → cannot restart
        TournamentGroupMatch::create([
            'group_id' => $group->id,
            'team1_id' => $team1->id,
            'team2_id' => $team2->id,
            'status' => 'completed',
        ]);

        $t->refresh();
        $this->assertTrue($t->firstRoundCompleted());
        $this->assertFalse($t->canRestart());
    }
}

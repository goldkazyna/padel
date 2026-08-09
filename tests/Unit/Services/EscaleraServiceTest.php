<?php

namespace Tests\Unit\Services;

use App\Models\Club;
use App\Models\EscaleraPlayer;
use App\Models\EscaleraRound;
use App\Models\EscaleraRoundCourt;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscaleraServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Турнир-эскалера на заданное число кортов (игроков = корты × 4). */
    private function makeTournament(int $courts = 3, string $mode = 'points', int $matchPoints = 12): Tournament
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);

        return Tournament::create([
            'club_id' => $club->id,
            'name' => 'Эскалера',
            'type' => 'escalera',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'courts_count' => $courts,
            'max_participants' => $courts * 4,
            'escalera_match_points' => $matchPoints,
            'escalera_rank_mode' => $mode,
        ]);
    }

    public function test_tournament_type_and_relations(): void
    {
        $t = $this->makeTournament();

        $this->assertTrue($t->isEscalera());
        $this->assertSame(12, $t->escalera_match_points);
        $this->assertSame('points', $t->escalera_rank_mode);

        $user = User::factory()->create(['rating' => 1500]);
        EscaleraPlayer::create([
            'tournament_id' => $t->id,
            'user_id' => $user->id,
            'start_court' => 1,
            'current_court' => 1,
        ]);

        $this->assertSame(1, $t->fresh()->escaleraPlayers->count());
        $this->assertSame(0, $t->fresh()->escaleraPlayers->first()->total_points);
    }

    public function test_round_court_holds_four_players_in_seating_order(): void
    {
        $t = $this->makeTournament();
        $round = EscaleraRound::create([
            'tournament_id' => $t->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);

        $players = User::factory()->count(4)->create();
        $court = EscaleraRoundCourt::create([
            'escalera_round_id' => $round->id,
            'court_number' => 1,
            'player1_id' => $players[0]->id,
            'player2_id' => $players[1]->id,
            'player3_id' => $players[2]->id,
            'player4_id' => $players[3]->id,
        ]);

        $this->assertSame($round->id, $court->fresh()->round->id);
        $this->assertSame($players[0]->id, $court->fresh()->player1_id);
        $this->assertSame(1, $t->fresh()->escaleraRounds->count());
    }
}

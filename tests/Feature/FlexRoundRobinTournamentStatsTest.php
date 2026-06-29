<?php

namespace Tests\Feature;

use App\Models\AmericanoFlexPlayer;
use App\Models\Club;
use App\Models\RoundRobinPlayer;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlexRoundRobinTournamentStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_americano_flex_counts_in_tournament_stats(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $user = User::factory()->create();
        $other = User::factory()->create();

        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Flex', 'type' => 'americano_flex',
            'status' => 'completed', 'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);

        // Чемпион — лучший средний (6.0 против 5.0), хотя очков меньше.
        AmericanoFlexPlayer::create([
            'tournament_id' => $t->id, 'user_id' => $user->id,
            'total_points' => 30, 'matches_played' => 5,
        ]);
        AmericanoFlexPlayer::create([
            'tournament_id' => $t->id, 'user_id' => $other->id,
            'total_points' => 40, 'matches_played' => 8,
        ]);

        $stats = $user->getTournamentStats();

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['by_type']['americano_flex'] ?? 0);
        $this->assertSame(1, $stats['wins']);
    }

    public function test_round_robin_counts_in_tournament_stats(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $user = User::factory()->create();
        $other = User::factory()->create();

        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'RR', 'type' => 'round_robin',
            'status' => 'completed', 'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);

        // Чемпион — больше побед.
        RoundRobinPlayer::create([
            'tournament_id' => $t->id, 'user_id' => $user->id,
            'wins' => 5, 'losses' => 2, 'points_for' => 40, 'points_against' => 20,
        ]);
        RoundRobinPlayer::create([
            'tournament_id' => $t->id, 'user_id' => $other->id,
            'wins' => 3, 'losses' => 4, 'points_for' => 30, 'points_against' => 35,
        ]);

        $stats = $user->getTournamentStats();

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['by_type']['round_robin'] ?? 0);
        $this->assertSame(1, $stats['wins']);
    }

    public function test_non_winner_round_robin_counted_without_win(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $user = User::factory()->create();
        $champ = User::factory()->create();

        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'RR', 'type' => 'round_robin',
            'status' => 'completed', 'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);

        RoundRobinPlayer::create([
            'tournament_id' => $t->id, 'user_id' => $user->id,
            'wins' => 2, 'losses' => 5, 'points_for' => 20, 'points_against' => 40,
        ]);
        RoundRobinPlayer::create([
            'tournament_id' => $t->id, 'user_id' => $champ->id,
            'wins' => 6, 'losses' => 1, 'points_for' => 45, 'points_against' => 15,
        ]);

        $stats = $user->getTournamentStats();

        $this->assertSame(1, $stats['total']);
        $this->assertSame(0, $stats['wins']);
    }
}

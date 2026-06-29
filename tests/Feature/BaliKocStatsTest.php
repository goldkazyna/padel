<?php

namespace Tests\Feature;

use App\Models\BaliKocMatch;
use App\Models\BaliKocPair;
use App\Models\BaliKocRound;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaliKocStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bali_koc_matches_count_in_profile_stats(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $user = User::factory()->create();
        $o1 = User::factory()->create();
        $o2 = User::factory()->create();
        $o3 = User::factory()->create();

        $t = Tournament::create([
            'club_id' => $club->id,
            'name' => 'Bali',
            'type' => 'bali_koc',
            'status' => 'completed',
            'start_date' => now()->subDay(),
            'min_level' => 1,
            'max_level' => 5,
            'max_participants' => 8,
            'is_paired' => true,
        ]);

        // Пара пользователя (A) и соперники (B).
        $pairA = BaliKocPair::create([
            'tournament_id' => $t->id,
            'player1_id' => $user->id,
            'player2_id' => $o1->id,
        ]);
        $pairB = BaliKocPair::create([
            'tournament_id' => $t->id,
            'player1_id' => $o2->id,
            'player2_id' => $o3->id,
        ]);

        $round = BaliKocRound::create([
            'tournament_id' => $t->id,
            'round_number' => 1,
            'status' => 'completed',
        ]);

        // Победа пары пользователя (A как pair1, 6:3).
        BaliKocMatch::create([
            'bali_koc_round_id' => $round->id,
            'court_number' => 1,
            'pair1_id' => $pairA->id,
            'pair2_id' => $pairB->id,
            'pair1_games' => 6,
            'pair2_games' => 3,
            'status' => 'completed',
        ]);

        // Поражение пары пользователя (A как pair2, проигрыш 2:6).
        BaliKocMatch::create([
            'bali_koc_round_id' => $round->id,
            'court_number' => 2,
            'pair1_id' => $pairB->id,
            'pair2_id' => $pairA->id,
            'pair1_games' => 6,
            'pair2_games' => 2,
            'status' => 'completed',
        ]);

        // Незавершённый матч не должен учитываться.
        BaliKocMatch::create([
            'bali_koc_round_id' => $round->id,
            'court_number' => 3,
            'pair1_id' => $pairA->id,
            'pair2_id' => $pairB->id,
            'pair1_games' => null,
            'pair2_games' => null,
            'status' => 'pending',
        ]);

        $stats = $user->getAllMatchesStats();

        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['won']);
        $this->assertSame(1, $stats['lost']);
    }

    public function test_bali_koc_counts_in_tournament_stats(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $user = User::factory()->create();
        $o1 = User::factory()->create();
        $o2 = User::factory()->create();
        $o3 = User::factory()->create();

        $t = Tournament::create([
            'club_id' => $club->id,
            'name' => 'Bali',
            'type' => 'bali_koc',
            'status' => 'completed',
            'start_date' => now()->subDay(),
            'min_level' => 1,
            'max_level' => 5,
            'max_participants' => 8,
            'is_paired' => true,
        ]);

        // Пара пользователя — победитель (больше очков).
        BaliKocPair::create([
            'tournament_id' => $t->id,
            'player1_id' => $user->id,
            'player2_id' => $o1->id,
            'points' => 9,
        ]);
        BaliKocPair::create([
            'tournament_id' => $t->id,
            'player1_id' => $o2->id,
            'player2_id' => $o3->id,
            'points' => 4,
        ]);

        $stats = $user->getTournamentStats();

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['by_type']['bali_koc'] ?? 0);
        $this->assertSame(1, $stats['wins']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\KingOfCourtMatch;
use App\Models\KingOfCourtPlayer;
use App\Models\KingOfCourtRound;
use App\Models\Tournament;
use App\Models\User;
use App\Support\KingOfCourtRanking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Единое ранжирование соло-КК: при полной ничьей решает личная встреча,
 * а place() всегда совпадает с порядком standings() (не «7 снаружи / 8 внутри»).
 */
class KingOfCourtRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_to_head_breaks_full_tie_and_place_matches_table(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'KOC', 'type' => 'king_of_court',
            'start_date' => now()->addDay(), 'min_level' => 1, 'max_level' => 5,
            'max_participants' => 8, 'status' => 'in_progress',
        ]);

        // A и B — полностью одинаковая статистика (очки/победы/мячи).
        $a = User::factory()->create(['rating' => 1500]);
        $b = User::factory()->create(['rating' => 1500]);
        // Партнёры/соперники для матча личной встречи.
        $c = User::factory()->create(['rating' => 1500]);
        $d = User::factory()->create(['rating' => 1500]);

        foreach ([$a, $b] as $u) {
            KingOfCourtPlayer::create([
                'tournament_id' => $t->id, 'user_id' => $u->id,
                'total_points' => 100, 'wins' => 6, 'losses' => 3,
                'points_for' => 60, 'points_against' => 40,
            ]);
        }
        foreach ([$c, $d] as $u) {
            KingOfCourtPlayer::create([
                'tournament_id' => $t->id, 'user_id' => $u->id,
                'total_points' => 50, 'wins' => 3, 'losses' => 6,
                'points_for' => 40, 'points_against' => 60,
            ]);
        }

        // Матч: команда A (a+c) обыграла команду B (b+d) → личная победа A над B.
        $round = KingOfCourtRound::create([
            'tournament_id' => $t->id, 'round_number' => 1, 'status' => 'completed',
        ]);
        KingOfCourtMatch::create([
            'kingofcourt_round_id' => $round->id, 'court_number' => 1,
            'team1_player1_id' => $a->id, 'team1_player2_id' => $c->id,
            'team2_player1_id' => $b->id, 'team2_player2_id' => $d->id,
            'team1_score' => 6, 'team2_score' => 2, 'status' => 'completed',
        ]);

        $order = array_map(fn ($r) => $r['id'], KingOfCourtRanking::standings($t));

        // A выше B — по личной встрече.
        $this->assertLessThan(
            array_search($b->id, $order, true),
            array_search($a->id, $order, true),
            'Победитель личной встречи (A) должен стоять выше B'
        );

        // place() совпадает с индексом в таблице — единый источник.
        $this->assertSame(array_search($a->id, $order, true) + 1,
            KingOfCourtRanking::place($t, $a->id));
        $this->assertSame(array_search($b->id, $order, true) + 1,
            KingOfCourtRanking::place($t, $b->id));
    }
}

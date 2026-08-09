<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MobileTournamentController;
use App\Models\Club;
use App\Models\KingOfCourtMatch;
use App\Models\KingOfCourtRound;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI-разбор для «Короля корта» должен получать реальные матчи игрока,
 * а не пустоту (иначе выдаёт «матчей не было, но рейтинг вырос»).
 */
class TournamentAiKocMatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_koc_matches_are_collected_for_ai(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'KOC', 'type' => 'king_of_court',
            'start_date' => now()->subDay(), 'min_level' => 1, 'max_level' => 5,
            'max_participants' => 8, 'status' => 'completed', 'is_rated' => true,
        ]);

        $me = User::factory()->create(['name' => 'Я']);
        $partner = User::factory()->create(['name' => 'Партнёр']);
        $o1 = User::factory()->create(['name' => 'Соперник1']);
        $o2 = User::factory()->create(['name' => 'Соперник2']);

        $round = KingOfCourtRound::create([
            'tournament_id' => $t->id, 'round_number' => 1, 'status' => 'completed',
        ]);
        // Моя команда выиграла 6:2.
        KingOfCourtMatch::create([
            'kingofcourt_round_id' => $round->id, 'court_number' => 1,
            'team1_player1_id' => $me->id, 'team1_player2_id' => $partner->id,
            'team2_player1_id' => $o1->id, 'team2_player2_id' => $o2->id,
            'team1_score' => 6, 'team2_score' => 2, 'status' => 'completed',
        ]);
        // Матч 0:0 не должен попасть.
        KingOfCourtMatch::create([
            'kingofcourt_round_id' => $round->id, 'court_number' => 2,
            'team1_player1_id' => $me->id, 'team1_player2_id' => $o1->id,
            'team2_player1_id' => $partner->id, 'team2_player2_id' => $o2->id,
            'team1_score' => 0, 'team2_score' => 0, 'status' => 'completed',
        ]);

        // «Король корта» собирается общим player-based сборщиком — тем же,
        // что американо и мексикано, поэтому дельты рейтинга считаются.
        $ctrl = new MobileTournamentController();
        $method = new \ReflectionMethod($ctrl, 'getMatchesForAnalysis');
        $method->setAccessible(true);
        $matches = $method->invoke($ctrl, $t->fresh(), $me->id);

        $this->assertCount(1, $matches, '0:0 не считается');
        $m = $matches[0];
        $this->assertSame('win', $m['result']);
        $this->assertSame(6, $m['score_my']);
        $this->assertSame(2, $m['score_opponent']);
        $this->assertNotSame(0, $m['rating_change'], 'дельта рейтинга посчитана');
        $this->assertNotNull($m['my_avg'], 'средний рейтинг своей пары');

        $partners = array_map(fn ($p) => $p['name'], $m['my_team']);
        $opps = array_map(fn ($p) => $p['name'], $m['opponent_team']);
        $this->assertContains('Партнёр', $partners);
        $this->assertContains('Соперник1', $opps);
        $this->assertContains('Соперник2', $opps);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\TournamentPlayoffMatch;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\TeamTournamentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия: в 8-командной сетке победитель ПФ2 (match_number 2, source «W2»)
 * не должен затирать слот ПФ1.team2 (тоже «W2» = победитель QF2).
 */
class TeamPlayoffAdvanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_semifinal_winner_does_not_overwrite_other_semifinal_slot(): void
    {
        $service = new TeamTournamentService();
        $t = Tournament::factory()->create([
            'type' => 'team', 'status' => 'in_progress',
            'has_playoff' => true, 'max_participants' => 16,
        ]);

        $teams = [];
        for ($i = 0; $i < 8; $i++) {
            $p1 = User::factory()->create();
            $p2 = User::factory()->create();
            $teams[$i] = TournamentTeam::create([
                'tournament_id' => $t->id,
                'player1_id' => $p1->id, 'player2_id' => $p2->id,
                'status' => 'approved', 'rating_avg' => 1500,
            ]);
        }

        // 8-командная сетка (как createPlayoffMatches stage 'quarter').
        $qf = [];
        $pairs = [[0, 1], [2, 3], [4, 5], [6, 7]];
        foreach ($pairs as $i => [$a, $b]) {
            $qf[$i] = TournamentPlayoffMatch::create([
                'tournament_id' => $t->id, 'court_number' => $i + 1,
                'stage' => 'quarter', 'match_number' => $i + 1,
                'team1_id' => $teams[$a]->id, 'team2_id' => $teams[$b]->id,
                'team1_source' => 'q' . ($i + 1) . 'a', 'team2_source' => 'q' . ($i + 1) . 'b',
                'status' => 'in_progress',
            ]);
        }
        $sf1 = TournamentPlayoffMatch::create([
            'tournament_id' => $t->id, 'court_number' => 1, 'stage' => 'semi',
            'match_number' => 1, 'team1_source' => 'W1', 'team2_source' => 'W2', 'status' => 'pending',
        ]);
        $sf2 = TournamentPlayoffMatch::create([
            'tournament_id' => $t->id, 'court_number' => 2, 'stage' => 'semi',
            'match_number' => 2, 'team1_source' => 'W3', 'team2_source' => 'W4', 'status' => 'pending',
        ]);
        $final = TournamentPlayoffMatch::create([
            'tournament_id' => $t->id, 'court_number' => 1, 'stage' => 'final',
            'match_number' => 1, 'team1_source' => 'W5', 'team2_source' => 'W6', 'status' => 'pending',
        ]);

        // Завершаем все QF (team1 побеждает) → заполняются полуфиналы.
        foreach ($qf as $m) {
            $service->savePlayoffMatchResult($m->fresh(), 6, 2);
        }

        // ПФ1 = QF1w(t0) vs QF2w(t2); ПФ2 = QF3w(t4) vs QF4w(t6).
        $this->assertSame($teams[0]->id, $sf1->fresh()->team1_id);
        $this->assertSame($teams[2]->id, $sf1->fresh()->team2_id, 'ПФ1.team2 = победитель QF2');
        $this->assertSame($teams[4]->id, $sf2->fresh()->team1_id);
        $this->assertSame($teams[6]->id, $sf2->fresh()->team2_id);

        // Завершаем ПФ2 РАНЬШЕ ПФ1 (t4 побеждает).
        $service->savePlayoffMatchResult($sf2->fresh(), 6, 1);

        // ГЛАВНОЕ: ПФ1.team2 остался победителем QF2 (t2), а не победителем ПФ2 (t4).
        $this->assertSame($teams[2]->id, $sf1->fresh()->team2_id,
            'ПФ2 не должен затирать слот ПФ1');
        // Победитель ПФ2 корректно ушёл в финал (W6).
        $this->assertSame($teams[4]->id, $final->fresh()->team2_id);
    }
}

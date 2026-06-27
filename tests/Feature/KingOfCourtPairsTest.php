<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\KingOfCourtMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\KingOfCourtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KingOfCourtPairsTest extends TestCase
{
    use RefreshDatabase;

    /** Создаёт KOC-турнир с $count зарегистрированными игроками. */
    private function scenario(bool $paired, int $count = 8): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id,
            'name' => 'KOC',
            'type' => 'king_of_court',
            'is_paired' => $paired,
            'start_date' => now()->addDay(),
            'min_level' => 1, 'max_level' => 5.75,
            'max_participants' => $count,
            'status' => 'open',
            'is_rated' => true,
        ]);
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $u = User::factory()->create(['rating' => 1000 + $i * 50]);
            $t->participants()->attach($u->id, ['status' => 'registered']);
            $users[] = $u;
        }
        return [$t, $users];
    }

    /** Набор {player1,player2} матча как множество для сравнения с парой. */
    private function teamSet(KingOfCourtMatch $m, int $team): array
    {
        $ids = $team === 1
            ? [$m->team1_player1_id, $m->team1_player2_id]
            : [$m->team2_player1_id, $m->team2_player2_id];
        sort($ids);
        return $ids;
    }

    public function test_create_pairs_validates_and_stores(): void
    {
        [$t, $u] = $this->scenario(true, 8);
        $svc = new KingOfCourtService();

        // Дубликат игрока в двух парах → ошибка.
        [$ok] = $svc->createPairs($t, [[$u[0]->id, $u[1]->id], [$u[0]->id, $u[2]->id], [$u[3]->id, $u[4]->id], [$u[5]->id, $u[6]->id]]);
        $this->assertFalse($ok);

        // Корректные 4 пары.
        [$ok2] = $svc->createPairs($t, [
            [$u[0]->id, $u[1]->id], [$u[2]->id, $u[3]->id], [$u[4]->id, $u[5]->id], [$u[6]->id, $u[7]->id],
        ]);
        $this->assertTrue($ok2);
        $this->assertSame(4, $t->kingOfCourtPairs()->count());
        $this->assertTrue($svc->arePairsCreated($t));
    }

    public function test_paired_start_requires_pairs(): void
    {
        [$t] = $this->scenario(true, 8);
        $svc = new KingOfCourtService();

        $this->assertFalse($svc->startTournament($t), 'без пар парный KOC не стартует');
        $this->assertSame('open', $t->fresh()->status);
    }

    public function test_paired_start_builds_round_of_intact_pairs(): void
    {
        [$t, $u] = $this->scenario(true, 8);
        $svc = new KingOfCourtService();
        $pairs = [[$u[0]->id, $u[1]->id], [$u[2]->id, $u[3]->id], [$u[4]->id, $u[5]->id], [$u[6]->id, $u[7]->id]];
        $svc->createPairs($t, $pairs);

        $this->assertTrue($svc->startTournament($t));
        $this->assertSame('in_progress', $t->fresh()->status);

        $round = $t->kingOfCourtRounds()->first();
        $this->assertSame(2, $round->matches->count()); // 8 игроков → 2 корта

        $pairSets = array_map(fn($p) => tap($p, fn(&$x) => sort($x)), $pairs);
        foreach ($round->matches as $m) {
            $this->assertContains($this->teamSet($m, 1), $pairSets, 'team1 должна быть целой парой');
            $this->assertContains($this->teamSet($m, 2), $pairSets, 'team2 должна быть целой парой');
        }
    }

    public function test_paired_rotation_keeps_pairs_together(): void
    {
        [$t, $u] = $this->scenario(true, 8);
        $svc = new KingOfCourtService();
        $pairs = [[$u[0]->id, $u[1]->id], [$u[2]->id, $u[3]->id], [$u[4]->id, $u[5]->id], [$u[6]->id, $u[7]->id]];
        $svc->createPairs($t, $pairs);
        $svc->startTournament($t);

        // Завершаем все матчи раунда 1.
        foreach ($t->kingOfCourtRounds()->first()->matches as $m) {
            $svc->saveMatchResult($m, 21, 15);
        }

        $this->assertTrue($svc->generateNextRound($t));
        $round2 = $t->kingOfCourtRounds()->reorder('round_number', 'desc')->first();

        $pairSets = array_map(fn($p) => tap($p, fn(&$x) => sort($x)), $pairs);
        foreach ($round2->matches as $m) {
            $this->assertContains($this->teamSet($m, 1), $pairSets, 'после ротации пары не распадаются');
            $this->assertContains($this->teamSet($m, 2), $pairSets, 'после ротации пары не распадаются');
        }
    }

    public function test_pair_standings_sum_player_stats(): void
    {
        [$t, $u] = $this->scenario(true, 8);
        $svc = new KingOfCourtService();
        $pairs = [[$u[0]->id, $u[1]->id], [$u[2]->id, $u[3]->id], [$u[4]->id, $u[5]->id], [$u[6]->id, $u[7]->id]];
        $svc->createPairs($t, $pairs);
        $svc->startTournament($t);

        foreach ($t->kingOfCourtRounds()->first()->matches as $m) {
            $svc->saveMatchResult($m, 21, 10);
        }

        $standings = $svc->getPairStandings($t->fresh());
        $this->assertCount(4, $standings);
        // Каждая строка — пара с суммарными очками двух игроков.
        foreach ($standings as $row) {
            $this->assertArrayHasKey('total_points', $row);
            $this->assertArrayHasKey('pair', $row);
        }
        // Лидер должен иметь не меньше очков, чем последний.
        $this->assertGreaterThanOrEqual(
            (int) end($standings)['total_points'],
            (int) $standings[0]['total_points']
        );
    }

    public function test_solo_koc_still_works(): void
    {
        [$t, $u] = $this->scenario(false, 8);
        $svc = new KingOfCourtService();

        $this->assertTrue($svc->startTournament($t), 'соло-KOC стартует без пар');
        $this->assertSame('in_progress', $t->fresh()->status);
        $this->assertSame(2, $t->kingOfCourtRounds()->first()->matches->count());
    }
}

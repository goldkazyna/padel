<?php

namespace Tests\Unit\Services;

use App\Services\JustPadelItScoring;
use PHPUnit\Framework\TestCase;

/**
 * Порядок таблицы Just Padel It:
 *  - по умолчанию: очки → победы → разница;
 *  - при $byWins: победы → очки → разница.
 */
class JustPadelItScoringTest extends TestCase
{
    private function rows(): array
    {
        // A: меньше очков, больше побед; B: больше очков, меньше побед.
        return [
            ['name' => 'A', 'total_points' => 30, 'wins' => 5, 'points_for' => 60, 'points_against' => 40],
            ['name' => 'B', 'total_points' => 40, 'wins' => 3, 'points_for' => 60, 'points_against' => 55],
        ];
    }

    public function test_default_ranks_by_points_first(): void
    {
        $sorted = JustPadelItScoring::sortStandings($this->rows(), false);
        $this->assertSame('B', $sorted[0]['name'], 'По очкам первым идёт B (больше очков)');
        $this->assertSame('A', $sorted[1]['name']);
    }

    public function test_by_wins_ranks_by_wins_first(): void
    {
        $sorted = JustPadelItScoring::sortStandings($this->rows(), true);
        $this->assertSame('A', $sorted[0]['name'], 'По победам первым идёт A (больше побед)');
        $this->assertSame('B', $sorted[1]['name']);
    }

    public function test_sort_key_order(): void
    {
        // По очкам: [points, wins, diff]; по победам: [wins, points, diff].
        $this->assertSame([40, 3, 5], JustPadelItScoring::sortKey(40, 3, 5, false));
        $this->assertSame([3, 40, 5], JustPadelItScoring::sortKey(40, 3, 5, true));
    }

    public function test_tiebreak_by_diff_when_primary_equal(): void
    {
        $rows = [
            ['name' => 'X', 'total_points' => 20, 'wins' => 4, 'points_for' => 50, 'points_against' => 45], // diff 5
            ['name' => 'Y', 'total_points' => 20, 'wins' => 4, 'points_for' => 50, 'points_against' => 30], // diff 20
        ];
        $sorted = JustPadelItScoring::sortStandings($rows, false);
        $this->assertSame('Y', $sorted[0]['name'], 'При равных очках и победах — по разнице мячей');
    }
}

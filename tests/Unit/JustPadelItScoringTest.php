<?php
namespace Tests\Unit;
use App\Services\JustPadelItScoring;
use PHPUnit\Framework\TestCase;

class JustPadelItScoringTest extends TestCase
{
    public function test_court_bonus(): void
    {
        $this->assertSame(3, JustPadelItScoring::courtBonus(1));
        $this->assertSame(2, JustPadelItScoring::courtBonus(2));
        $this->assertSame(1, JustPadelItScoring::courtBonus(3));
        $this->assertSame(1, JustPadelItScoring::courtBonus(5));
    }

    public function test_sort_standings_points_then_wins(): void
    {
        $rows = [
            ['name' => 'B', 'total_points' => 130, 'wins' => 8],
            ['name' => 'A', 'total_points' => 130, 'wins' => 9],
            ['name' => 'C', 'total_points' => 140, 'wins' => 1],
        ];
        $sorted = JustPadelItScoring::sortStandings($rows);
        $this->assertSame(['C', 'A', 'B'], array_column($sorted, 'name'));
    }

    public function test_sort_standings_breaks_points_and_wins_tie_by_ball_diff(): void
    {
        // Равные очки и победы — решает разница мячей (забитые − пропущенные).
        $rows = [
            ['name' => 'A', 'total_points' => 100, 'wins' => 5, 'points_for' => 60, 'points_against' => 55], // diff +5
            ['name' => 'B', 'total_points' => 100, 'wins' => 5, 'points_for' => 70, 'points_against' => 50], // diff +20
            ['name' => 'C', 'total_points' => 100, 'wins' => 5, 'diff' => 12],                                // diff +12 (готовый ключ)
        ];
        $sorted = JustPadelItScoring::sortStandings($rows);
        $this->assertSame(['B', 'C', 'A'], array_column($sorted, 'name'));
    }
}

<?php
namespace App\Services;

class JustPadelItScoring
{
    /** Бонус победителям по номеру корта: 1→+3, 2→+2, остальные→+1. */
    public static function courtBonus(int $courtNumber): int
    {
        return match ($courtNumber) {
            1 => 3,
            2 => 2,
            default => 1,
        };
    }

    /** Сортировка итоговой таблицы: очки ↓, при равенстве — победы ↓. */
    public static function sortStandings(array $rows): array
    {
        usort($rows, function ($a, $b) {
            $ap = $a['total_points'] ?? 0;
            $bp = $b['total_points'] ?? 0;
            if ($ap !== $bp) return $bp <=> $ap;
            return ($b['wins'] ?? 0) <=> ($a['wins'] ?? 0);
        });
        return $rows;
    }
}

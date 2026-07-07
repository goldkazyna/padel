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

    /**
     * Сортировка итоговой таблицы: очки ↓, при равенстве — победы ↓,
     * далее — разница мячей (забитые − пропущенные) ↓.
     * Разница берётся из ключа 'diff', иначе считается из
     * points_for − points_against.
     */
    public static function sortStandings(array $rows): array
    {
        usort($rows, function ($a, $b) {
            $ap = (int) ($a['total_points'] ?? 0);
            $bp = (int) ($b['total_points'] ?? 0);
            if ($ap !== $bp) return $bp <=> $ap;

            $aw = (int) ($a['wins'] ?? 0);
            $bw = (int) ($b['wins'] ?? 0);
            if ($aw !== $bw) return $bw <=> $aw;

            $ad = (int) ($a['diff'] ?? (($a['points_for'] ?? 0) - ($a['points_against'] ?? 0)));
            $bd = (int) ($b['diff'] ?? (($b['points_for'] ?? 0) - ($b['points_against'] ?? 0)));
            return $bd <=> $ad;
        });
        return $rows;
    }
}

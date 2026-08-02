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
     * Ключ сортировки таблицы (для сравнения по убыванию).
     *
     * По умолчанию (по очкам): очки → победы → разница мячей.
     * При $byWins (по победам): победы → очки → разница мячей.
     *
     * Единый источник порядка для всех экранов (веб, приложение, место игрока).
     */
    public static function sortKey(int $points, int $wins, int $diff, bool $byWins = false): array
    {
        return $byWins ? [$wins, $points, $diff] : [$points, $wins, $diff];
    }

    /**
     * Сортировка итоговой таблицы по строкам-массивам.
     * Разница берётся из ключа 'diff', иначе из points_for − points_against.
     */
    public static function sortStandings(array $rows, bool $byWins = false): array
    {
        usort($rows, function ($a, $b) use ($byWins) {
            $diffA = (int) ($a['diff'] ?? (($a['points_for'] ?? 0) - ($a['points_against'] ?? 0)));
            $diffB = (int) ($b['diff'] ?? (($b['points_for'] ?? 0) - ($b['points_against'] ?? 0)));
            return self::sortKey((int) ($b['total_points'] ?? 0), (int) ($b['wins'] ?? 0), $diffB, $byWins)
                <=> self::sortKey((int) ($a['total_points'] ?? 0), (int) ($a['wins'] ?? 0), $diffA, $byWins);
        });
        return $rows;
    }
}

<?php

namespace App\Support;

use App\Models\User;
use App\Services\PlayerMatchHistory;

/**
 * С кем игрок выходит на корт: партнёры и как с ними складывается.
 *
 * Считается по тем же матчам, что история и значки, — по id партнёра, а не
 * по имени: тёзки склеивались бы в одного человека, и нажать на такую строку
 * было нельзя.
 */
class PlayerPartners
{
    /** Меньше трёх матчей — это ещё не «лучший партнёр», а случайность. */
    public const MIN_GAMES = 3;

    /**
     * Партнёры игрока: сколько сыграно вместе и сколько выиграно.
     *
     * @return array<int, array<string, mixed>> отсортированы: сначала лучшие
     */
    public static function all(User $user): array
    {
        $byId = [];

        foreach (app(PlayerMatchHistory::class)->for($user) as $match) {
            $partner = $match['partner'] ?? null;
            $id = $partner['id'] ?? null;
            if (!$id) {
                continue;
            }

            $byId[$id] ??= [
                'user_id' => (int) $id,
                'name' => $partner['name'] ?? 'Игрок',
                'avatar' => $partner['avatar'] ?? null,
                'games' => 0,
                'wins' => 0,
                'losses' => 0,
                'draws' => 0,
            ];

            $byId[$id]['games']++;
            match ($match['result'] ?? '') {
                'win' => $byId[$id]['wins']++,
                'loss' => $byId[$id]['losses']++,
                default => $byId[$id]['draws']++,
            };
        }

        $rows = array_map(function (array $row) {
            // Ничьи в знаменатель не идут — как и везде в статистике игрока.
            $row['winrate'] = CountedMatches::winrate($row['wins'], $row['losses']);
            $row['score'] = self::score($row['wins'], $row['losses']);

            return $row;
        }, array_values($byId));

        usort($rows, function (array $a, array $b) {
            // Голый процент врал: три матча из трёх обгоняли семь побед из
            // восьми, хотя второе — куда весомее. Сортируем по оценке, где
            // редкие матчи тянут результат к середине.
            $ready = fn (array $r) => $r['games'] >= self::MIN_GAMES ? 1 : 0;

            return [$ready($b), $b['score'], $b['games']]
                <=> [$ready($a), $a['score'], $a['games']];
        });

        return $rows;
    }

    /**
     * Оценка пары «сколько выигрываем вместе» с поправкой на число матчей.
     *
     * Нижняя граница доверительного интервала Вильсона: чем меньше сыграно,
     * тем сильнее результат тянет к середине. 3 из 3 дают 0.44, а 7 из 8 —
     * 0.47, и восьмиматчевый партнёр обходит случайную серию, как и должен.
     */
    public static function score(int $wins, int $losses): float
    {
        $played = $wins + $losses;
        if ($played === 0) {
            return 0.0;
        }

        $z = 1.96;              // 95% — обычная планка для таких оценок
        $p = $wins / $played;
        $z2 = $z * $z;

        $numerator = $p + $z2 / (2 * $played)
            - $z * sqrt(($p * (1 - $p) + $z2 / (4 * $played)) / $played);

        return round($numerator / (1 + $z2 / $played), 4);
    }

    /**
     * Лучший партнёр — верхняя строка. null, если играл только один или
     * всегда в одиночных форматах без постоянного партнёра.
     *
     * @return array<string, mixed>|null
     */
    public static function best(User $user): ?array
    {
        return self::all($user)[0] ?? null;
    }
}

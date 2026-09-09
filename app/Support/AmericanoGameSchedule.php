<?php

namespace App\Support;

/**
 * Расписание Американо для игры на одном корте.
 *
 * Четверо играют классические три раунда — каждый с каждым в паре.
 * Больше четверых на корт не помещается, поэтому раунд играет четвёрка,
 * остальные отдыхают: очередь строится так, чтобы у всех вышло поровну
 * матчей, а партнёры и соперники повторялись как можно реже.
 */
class AmericanoGameSchedule
{
    /** Классика на четверых: каждый по разу в паре с каждым. */
    private const FOUR = [
        [[0, 1], [2, 3]],
        [[0, 2], [1, 3]],
        [[0, 3], [1, 2]],
    ];

    /** Сколько матчей достаётся каждому, когда игроков больше четырёх. */
    private const GAMES_PER_PLAYER = 4;

    /**
     * @param  array<int,int>  $userIds  игроки в том порядке, в каком садим за корт
     * @return array<int,array{a:array<int,int>,b:array<int,int>}> раунды по порядку
     */
    public static function build(array $userIds): array
    {
        $userIds = array_values($userIds);
        $n = count($userIds);

        if ($n < 4) {
            return [];
        }

        if ($n === 4) {
            return array_map(
                fn (array $slots) => [
                    'a' => [$userIds[$slots[0][0]], $userIds[$slots[0][1]]],
                    'b' => [$userIds[$slots[1][0]], $userIds[$slots[1][1]]],
                ],
                self::FOUR
            );
        }

        // По четыре матча на каждого: столько раундов, сколько игроков.
        $rounds = intdiv($n * self::GAMES_PER_PLAYER, 4);

        $played = array_fill(0, $n, 0);   // сыграно матчей
        $rested = array_fill(0, $n, 0);   // раундов подряд на скамейке
        $partner = [];                    // сколько раз i играл с j
        $rival = [];                      // сколько раз i играл против j

        $schedule = [];
        for ($r = 0; $r < $rounds; $r++) {
            $best = null;
            $bestCost = null;

            foreach (self::foursomes($n, $played) as $four) {
                foreach ([[0, 1, 2, 3], [0, 2, 1, 3], [0, 3, 1, 2]] as $split) {
                    [$a1, $a2, $b1, $b2] = array_map(fn ($i) => $four[$i], $split);
                    // Повтор партнёра неприятнее повтора соперника, отдых —
                    // мелкий довесок: при прочих равных выпускаем засидевшихся.
                    $cost = 1000 * (self::pairCount($partner, $a1, $a2) + self::pairCount($partner, $b1, $b2))
                          + 100 * (self::pairCount($rival, $a1, $b1) + self::pairCount($rival, $a1, $b2)
                                 + self::pairCount($rival, $a2, $b1) + self::pairCount($rival, $a2, $b2))
                          - array_sum(array_map(fn ($i) => $rested[$i], $four));
                    if ($bestCost === null || $cost < $bestCost) {
                        $bestCost = $cost;
                        $best = [$four, [$a1, $a2], [$b1, $b2]];
                    }
                }
            }

            [$four, [$a1, $a2], [$b1, $b2]] = $best;
            self::bump($partner, $a1, $a2);
            self::bump($partner, $b1, $b2);
            foreach ([[$a1, $b1], [$a1, $b2], [$a2, $b1], [$a2, $b2]] as [$x, $y]) {
                self::bump($rival, $x, $y);
            }
            for ($i = 0; $i < $n; $i++) {
                if (in_array($i, $four, true)) {
                    $played[$i]++;
                    $rested[$i] = 0;
                } else {
                    $rested[$i]++;
                }
            }

            $schedule[] = [
                'a' => [$userIds[$a1], $userIds[$a2]],
                'b' => [$userIds[$b1], $userIds[$b2]],
            ];
        }

        return $schedule;
    }

    /**
     * Четвёрки, которые можно выпустить в этом раунде: сначала те, кто сыграл
     * меньше всех — иначе кто-то так и просидит всю игру на скамейке.
     *
     * @param  array<int,int>  $played
     * @return array<int,array<int,int>>
     */
    private static function foursomes(int $n, array $played): array
    {
        $order = range(0, $n - 1);
        usort($order, fn ($x, $y) => [$played[$x], $x] <=> [$played[$y], $y]);
        $threshold = $played[$order[3]];

        $must = array_values(array_filter($order, fn ($i) => $played[$i] < $threshold));
        $free = array_values(array_filter($order, fn ($i) => $played[$i] === $threshold));

        $out = [];
        foreach (self::combinations($free, 4 - count($must)) as $rest) {
            $out[] = array_merge($must, $rest);
        }

        return $out;
    }

    /**
     * @param  array<int,int>  $items
     * @return array<int,array<int,int>>
     */
    private static function combinations(array $items, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        if (count($items) < $k) {
            return [];
        }

        $out = [];
        foreach ($items as $pos => $item) {
            foreach (self::combinations(array_slice($items, $pos + 1), $k - 1) as $tail) {
                $out[] = array_merge([$item], $tail);
            }
        }

        return $out;
    }

    private static function key(int $x, int $y): string
    {
        return $x < $y ? "$x-$y" : "$y-$x";
    }

    private static function pairCount(array $map, int $x, int $y): int
    {
        return $map[self::key($x, $y)] ?? 0;
    }

    private static function bump(array &$map, int $x, int $y): void
    {
        $k = self::key($x, $y);
        $map[$k] = ($map[$k] ?? 0) + 1;
    }
}

<?php

namespace App\Support;

/**
 * Расписание Американо для игры на одном корте.
 *
 * Больше четверых на корт не помещается, поэтому раунд играет четвёрка,
 * остальные отдыхают. Для 4–8 игроков берём готовую сетку Американо Флекс —
 * ту же, по которой играют турниры: партнёр не повторяется, отдых разложен
 * поровну. Для остальных чисел (сетки нет) собираем очередь алгоритмом: у
 * всех поровну матчей, партнёры и соперники повторяются как можно реже.
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

        // Сначала готовая сетка Американо Флекс на один корт — та же, по
        // которой играют турниры: партнёры там не повторяются, отдых
        // разложен поровну. Своим алгоритмом такое не собрать.
        if ($table = self::flexTable($userIds)) {
            return $table;
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
            [[$a1, $a2], [$b1, $b2]] = self::pickSlots($n, $played, $rested, $partner, $rival);

            $schedule[] = [
                'a' => [$userIds[$a1], $userIds[$a2]],
                'b' => [$userIds[$b1], $userIds[$b2]],
            ];
        }

        return $schedule;
    }

    /**
     * Следующий раунд поверх уже сыгранных — для игры, где раунды набирают
     * по кнопке и играют, пока хочется.
     *
     * Пока хватает готовой сетки, берём её раунд; дальше считаем по истории:
     * выпускаем тех, кто меньше играл, и избегаем повторов пар.
     *
     * @param  array<int,int>  $userIds  игроки в стабильном порядке (по месту за кортом)
     * @param  array<int,array{a:array<int,int>,b:array<int,int>}>  $history  уже созданные раунды
     * @return array{a:array<int,int>,b:array<int,int>}|null
     */
    public static function next(array $userIds, array $history): ?array
    {
        $userIds = array_values($userIds);
        $n = count($userIds);
        if ($n < 4) {
            return null;
        }

        $roundNo = count($history) + 1;

        // Готовая сетка кончилась не сразу: пока раунд в её пределах, берём его.
        $table = self::flexTable($userIds);
        if ($table !== null && isset($table[$roundNo - 1])) {
            return $table[$roundNo - 1];
        }

        // Дальше — по истории: кто сколько сыграл, с кем и против кого.
        $slotOf = array_flip($userIds);
        $played = array_fill(0, $n, 0);
        $rested = array_fill(0, $n, 0);
        $partner = [];
        $rival = [];

        foreach ($history as $round) {
            $a = array_values(array_filter(array_map(
                fn ($id) => $slotOf[$id] ?? null, $round['a'] ?? []), fn ($x) => $x !== null));
            $b = array_values(array_filter(array_map(
                fn ($id) => $slotOf[$id] ?? null, $round['b'] ?? []), fn ($x) => $x !== null));
            if (count($a) !== 2 || count($b) !== 2) {
                continue; // раунд собран вручную из тех, кого уже нет в составе
            }

            self::bump($partner, $a[0], $a[1]);
            self::bump($partner, $b[0], $b[1]);
            foreach ([[$a[0], $b[0]], [$a[0], $b[1]], [$a[1], $b[0]], [$a[1], $b[1]]] as [$x, $y]) {
                self::bump($rival, $x, $y);
            }

            $four = array_merge($a, $b);
            for ($i = 0; $i < $n; $i++) {
                if (in_array($i, $four, true)) {
                    $played[$i]++;
                    $rested[$i] = 0;
                } else {
                    $rested[$i]++;
                }
            }
        }

        [[$a1, $a2], [$b1, $b2]] = self::pickSlots($n, $played, $rested, $partner, $rival);

        return [
            'a' => [$userIds[$a1], $userIds[$a2]],
            'b' => [$userIds[$b1], $userIds[$b2]],
        ];
    }

    /**
     * Кого выпустить в следующем раунде: перебираем четвёрки из тех, кто
     * меньше играл, и разбиения на пары; счётчики обновляем на месте.
     *
     * @return array{0:array<int,int>,1:array<int,int>} пары слотов A и B
     */
    private static function pickSlots(
        int $n,
        array &$played,
        array &$rested,
        array &$partner,
        array &$rival
    ): array {
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

        return [[$a1, $a2], [$b1, $b2]];
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

    /**
     * Раунды из таблицы Американо Флекс для N игроков на одном корте.
     *
     * Таблицы лежат рядом с турнирными (`database/data`), ключ «N-1».
     * Нет таблицы для такого числа — возвращаем null, и дальше работает
     * алгоритм: игра не должна ломаться из-за отсутствия расклада.
     *
     * @param  array<int,int>  $userIds
     * @return array<int,array{a:array<int,int>,b:array<int,int>}>|null
     */
    private static function flexTable(array $userIds): ?array
    {
        $key = count($userIds) . '-1';
        $path = database_path('data/americano_flex_schedules.json');

        if (!is_file($path)) {
            return null;
        }

        $all = json_decode((string) file_get_contents($path), true);
        $schedule = $all[$key]['schedule'] ?? null;
        if (!is_array($schedule) || $schedule === []) {
            return null;
        }

        $rounds = [];
        foreach ($schedule as $round) {
            // На одном корте матч в раунде ровно один; отдыхающие (byes)
            // в игре отдельно не хранятся — это все, кого нет в парах.
            $match = $round['courts'][0] ?? null;
            if (!is_array($match) || count($match) !== 2) {
                return null;
            }

            [$a, $b] = $match;
            if (count($a) !== 2 || count($b) !== 2) {
                return null;
            }

            $rounds[] = [
                'a' => [$userIds[$a[0]], $userIds[$a[1]]],
                'b' => [$userIds[$b[0]], $userIds[$b[1]]],
            ];
        }

        return $rounds;
    }
}

<?php

namespace App\Services;

use App\Models\EscaleraRoundCourt;

/**
 * Логика формата «Эскалера».
 *
 * Корты выстроены сверху вниз, на каждом четыре игрока и три коротких матча
 * за раунд. В общую таблицу идут не очки, а позиция игрока в общем строю всех
 * участников — номер корта уже встроен в это число, поэтому единственный
 * способ улучшить результат — подняться на корт выше.
 */
class EscaleraService
{
    /** Позиция в общем строю: корты упорядочены по силе, на каждом четверо. */
    public function positionFor(int $courtNumber, int $place): int
    {
        return ($courtNumber - 1) * 4 + $place;
    }

    /** Баллы за позицию: первый в строю получает столько, сколько всего игроков. */
    public function pointsFor(int $position, int $totalPlayers): int
    {
        return $totalPlayers - $position + 1;
    }

    /**
     * Три матча из посадки: каждый играет в паре с каждым по разу.
     * Первым идёт самый ровный матч — сильнейший с четвёртым.
     *
     * @param  array<int, int> $seating четыре id в порядке посадки
     * @return array<int, array{0: array<int,int>, 1: array<int,int>}>
     */
    public function matchLineup(array $seating): array
    {
        [$p1, $p2, $p3, $p4] = $seating;

        return [
            [[$p1, $p4], [$p2, $p3]],
            [[$p1, $p3], [$p2, $p4]],
            [[$p1, $p2], [$p3, $p4]],
        ];
    }

    /**
     * Ранжировать четвёрку на корте. Возвращает id игроков в порядке мест
     * с первого по четвёртое.
     *
     * Режим 'points' — по сумме очков за три матча.
     * Режим 'wins' — по числу побед, затем по очкам, затем по личной встрече.
     * Полное равенство решается рейтингом.
     *
     * @return array<int, int>
     */
    public function rankCourt(EscaleraRoundCourt $court, string $mode): array
    {
        $ids = $court->playerIds();
        $stats = [];
        foreach ($ids as $id) {
            $stats[$id] = ['points' => 0, 'wins' => 0, 'rating' => 0];
        }

        // Рейтинги нужны как последний разделитель при полном равенстве.
        $ratings = \App\Models\User::whereIn('id', $ids)->pluck('rating', 'id');
        foreach ($ids as $id) {
            $stats[$id]['rating'] = (int) ($ratings[$id] ?? 0);
        }

        foreach ($court->matches as $match) {
            $team1 = [$match->team1_player1_id, $match->team1_player2_id];
            $team2 = [$match->team2_player1_id, $match->team2_player2_id];
            $s1 = (int) $match->team1_score;
            $s2 = (int) $match->team2_score;

            foreach ($team1 as $id) {
                $stats[$id]['points'] += $s1;
                if ($s1 > $s2) $stats[$id]['wins']++;
            }
            foreach ($team2 as $id) {
                $stats[$id]['points'] += $s2;
                if ($s2 > $s1) $stats[$id]['wins']++;
            }
        }

        $order = $ids;
        usort($order, function ($a, $b) use ($stats, $mode, $court) {
            if ($mode === 'wins') {
                // Сначала победы, затем сумма очков.
                if ($stats[$a]['wins'] !== $stats[$b]['wins']) {
                    return $stats[$b]['wins'] <=> $stats[$a]['wins'];
                }
                if ($stats[$a]['points'] !== $stats[$b]['points']) {
                    return $stats[$b]['points'] <=> $stats[$a]['points'];
                }
                // Личная встреча: очки в тех матчах, где эти двое были соперниками.
                $h2h = $this->headToHead($court, $a, $b);
                if ($h2h !== 0) {
                    return $h2h;
                }
            } elseif ($stats[$a]['points'] !== $stats[$b]['points']) {
                return $stats[$b]['points'] <=> $stats[$a]['points'];
            }

            // Полное равенство — выше игрок с большим рейтингом.
            return $stats[$b]['rating'] <=> $stats[$a]['rating'];
        });

        return $order;
    }

    /**
     * Личная встреча: сравнение по сумме очков в матчах, где игроки были
     * соперниками. Возвращает результат сравнения для usort (0 — равенство).
     */
    private function headToHead(EscaleraRoundCourt $court, int $a, int $b): int
    {
        $scoreA = 0;
        $scoreB = 0;

        foreach ($court->matches as $match) {
            $team1 = [$match->team1_player1_id, $match->team1_player2_id];
            $team2 = [$match->team2_player1_id, $match->team2_player2_id];

            $aIn1 = in_array($a, $team1, true);
            $bIn1 = in_array($b, $team1, true);
            $aIn2 = in_array($a, $team2, true);
            $bIn2 = in_array($b, $team2, true);

            // Интересуют только матчи, где они по разные стороны сетки.
            if ($aIn1 && $bIn2) {
                $scoreA += (int) $match->team1_score;
                $scoreB += (int) $match->team2_score;
            } elseif ($aIn2 && $bIn1) {
                $scoreA += (int) $match->team2_score;
                $scoreB += (int) $match->team1_score;
            }
        }

        return $scoreB <=> $scoreA;
    }

    /**
     * Куда поедут игроки после раунда.
     * Первый на корте вверх, четвёртый вниз, двое средних остаются.
     * На верхнем корте вниз уходит только четвёртый, на нижнем вверх — только первый.
     *
     * @param  array<int, array<int,int>> $courtRankings корт => четвёрка по местам
     * @return array<int, array<int,int>> корт => состав на следующий раунд
     */
    public function planMovements(array $courtRankings): array
    {
        ksort($courtRankings);
        $courts = array_keys($courtRankings);
        $top = min($courts);
        $bottom = max($courts);

        $next = [];
        foreach ($courts as $court) {
            $next[$court] = [];
        }

        foreach ($courtRankings as $court => $places) {
            [$first, $second, $third, $fourth] = $places;

            // Первый идёт наверх, но с верхнего корта уходить некуда.
            if ($court === $top) {
                $next[$court][] = $first;
            } else {
                $next[$court - 1][] = $first;
            }

            // Двое средних всегда остаются.
            $next[$court][] = $second;
            $next[$court][] = $third;

            // Четвёртый идёт вниз, но с нижнего корта опускаться некуда.
            if ($court === $bottom) {
                $next[$court][] = $fourth;
            } else {
                $next[$court + 1][] = $fourth;
            }
        }

        // Целостность: после всех перемещений на каждом корте ровно четверо.
        foreach ($next as $court => $players) {
            if (count($players) !== 4) {
                throw new \RuntimeException(
                    "После перемещений на корте {$court} оказалось " . count($players) . " игроков вместо четырёх"
                );
            }
        }

        return $next;
    }
}

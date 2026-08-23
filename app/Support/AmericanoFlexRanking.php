<?php

namespace App\Support;

use App\Models\Tournament;

/**
 * Единый порядок таблицы Americano Flex.
 *
 * Критерии, сверху вниз:
 *   1. среднее забитых за матч — в Flex у игроков разное число матчей из-за
 *      отдыхов, поэтому сумма очков как первый критерий не годится;
 *   2. процент побед — при равном среднем сравниваем качество, а не количество:
 *      абсолютное число побед наказывало бы отдыхавших ни за что;
 *   3. личная встреча — как в Американо (см. AmericanoTie);
 *   4. рейтинг игрока, затем id — чтобы порядок был определённым, а не
 *      «как повезло с планом запроса».
 *
 * Матчи со счётом 0:0 считаются несыгранными и не попадают ни в очки, ни в
 * число матчей: организаторы ставят 0:0 именно как отметку «не играли».
 *
 * Классом пользуются веб-CRM, мобильный API игрока и админский API — таблица
 * должна быть одна и та же везде, включая выгрузку картинкой.
 */
class AmericanoFlexRanking
{
    /**
     * Агрегаты по игрокам из сыгранных матчей.
     * user_id => [points_for, points_against, matches, wins, losses, draws]
     */
    public static function stats(Tournament $tournament): array
    {
        $stats = [];

        foreach (self::playedMatches($tournament) as $m) {
            $s1 = (int) $m->team1_score;
            $s2 = (int) $m->team2_score;

            $sides = [
                [[$m->team1_player1_id, $m->team1_player2_id], $s1, $s2],
                [[$m->team2_player1_id, $m->team2_player2_id], $s2, $s1],
            ];

            foreach ($sides as [$ids, $own, $rival]) {
                foreach ($ids as $uid) {
                    if (!$uid) continue;
                    $stats[$uid] ??= self::emptyStats();
                    $stats[$uid]['points_for'] += $own;
                    $stats[$uid]['points_against'] += $rival;
                    $stats[$uid]['matches']++;
                    if ($own > $rival) {
                        $stats[$uid]['wins']++;
                    } elseif ($own < $rival) {
                        $stats[$uid]['losses']++;
                    } else {
                        $stats[$uid]['draws']++;
                    }
                }
            }
        }

        return $stats;
    }

    public static function emptyStats(): array
    {
        return [
            'points_for' => 0,
            'points_against' => 0,
            'matches' => 0,
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
        ];
    }

    /** Карта личных встреч — та же, что у Американо. */
    public static function headToHead(Tournament $tournament): array
    {
        return AmericanoTie::fromMatches(self::playedMatches($tournament));
    }

    /**
     * Отсортировать строки и проставить position (нумерация с 1).
     *
     * Каждая строка обязана содержать: id, points_for, matches, wins, rating.
     * Для пар id — это игрок 1: партнёры всегда играют вместе, статистика и
     * личные встречи у них общие.
     */
    public static function sortRows(array $rows, array $h2h): array
    {
        usort($rows, fn($a, $b) => self::compare($a, $b, $h2h));

        $position = 1;
        foreach ($rows as &$row) {
            $row['position'] = $position++;
        }
        unset($row);

        return $rows;
    }

    /** Место игрока (или его пары) в таблице, null — если игрока в ней нет. */
    public static function place(Tournament $tournament, int $userId): ?int
    {
        foreach (self::soloRows($tournament) as $row) {
            if ((int) $row['id'] === $userId) {
                return (int) $row['position'];
            }
        }

        return null;
    }

    /**
     * Строки соло-таблицы: агрегаты + рейтинг, уже в нужном порядке.
     * Возвращает массивы с ключами id, position, points_for, points_against,
     * matches, wins, losses, draws, avg, win_percent.
     */
    public static function soloRows(Tournament $tournament): array
    {
        $stats = self::stats($tournament);
        $rows = [];

        foreach ($tournament->americanoFlexPlayers()->with('user')->get() as $fp) {
            if (!$fp->user) continue;
            $rows[] = self::row((int) $fp->user_id, $stats, (int) ($fp->user->rating ?? 0));
        }

        return self::sortRows($rows, self::headToHead($tournament));
    }

    /** Собрать строку таблицы из агрегатов игрока. */
    public static function row(int $userId, array $stats, int $rating = 0): array
    {
        $st = $stats[$userId] ?? self::emptyStats();
        $matches = $st['matches'];

        return $st + [
            'id' => $userId,
            'rating' => $rating,
            'avg' => $matches > 0 ? round($st['points_for'] / $matches, 2) : 0.0,
            'win_percent' => $matches > 0 ? (int) round($st['wins'] / $matches * 100) : 0,
        ];
    }

    /** Сравнение двух строк для сортировки по убыванию силы. */
    private static function compare(array $a, array $b, array $h2h): int
    {
        // Среднее и процент побед сравниваем дробями, без округления: иначе
        // два разных результата могли бы слипнуться на втором знаке.
        $byAvg = self::compareRatio(
            (int) $a['points_for'], (int) $a['matches'],
            (int) $b['points_for'], (int) $b['matches']
        );
        if ($byAvg !== 0) return $byAvg;

        $byWins = self::compareRatio(
            (int) $a['wins'], (int) $a['matches'],
            (int) $b['wins'], (int) $b['matches']
        );
        if ($byWins !== 0) return $byWins;

        $tie = AmericanoTie::compare($h2h, $a['id'], $b['id']);
        if ($tie !== 0) return $tie;

        $ratingA = (int) ($a['rating'] ?? 0);
        $ratingB = (int) ($b['rating'] ?? 0);
        if ($ratingA !== $ratingB) return $ratingB <=> $ratingA;

        return $a['id'] <=> $b['id'];
    }

    /**
     * Сравнить дроби n1/d1 и n2/d2 по убыванию, целочисленно.
     * Нулевой знаменатель (игрок не сыграл ни одного матча) — это доля 0.
     */
    private static function compareRatio(int $n1, int $d1, int $n2, int $d2): int
    {
        if ($d1 === 0 || $d2 === 0) {
            $left = $d1 > 0 ? $n1 / $d1 : 0.0;
            $right = $d2 > 0 ? $n2 / $d2 : 0.0;

            return $right <=> $left;
        }

        return ($n2 * $d1) <=> ($n1 * $d2);
    }

    /** Сыгранные матчи турнира: завершённые и не 0:0. */
    private static function playedMatches(Tournament $tournament): array
    {
        $matches = [];

        foreach ($tournament->americanoFlexRounds()->with('matches')->get() as $round) {
            foreach ($round->matches as $m) {
                if ($m->status !== 'completed') continue;
                if ((int) $m->team1_score === 0 && (int) $m->team2_score === 0) continue;
                $matches[] = $m;
            }
        }

        return $matches;
    }
}

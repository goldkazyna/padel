<?php

namespace App\Support;

use App\Models\Game;

/**
 * Порядок игроков Американо для ИГРЫ (не турнира):
 *   очки → победы → разница очков → user_id (детерминированный добор).
 *
 * Миррор логики App\Support\AmericanoRanking, но читает GameRound
 * (pair_a/pair_b/score_a/score_b). Турнирный AmericanoRanking/AmericanoTie НЕ трогаем.
 */
class GameAmericanoRanking
{
    /** Отсортированные строки статистики (1-е место первым). */
    private static function computeSorted(Game $game): array
    {
        $stats = [];
        $ensure = function (int $id) use (&$stats) {
            if (!isset($stats[$id])) {
                $stats[$id] = ['id' => $id, 'points' => 0, 'wins' => 0, 'for' => 0, 'against' => 0];
            }
        };

        // Все принятые игроки попадают в таблицу, даже сыгравшие 0 раундов.
        foreach ($game->acceptedPlayers()->pluck('user_id') as $uid) {
            $ensure((int) $uid);
        }

        $rounds = $game->relationLoaded('rounds') ? $game->rounds : $game->rounds()->get();
        foreach ($rounds as $round) {
            if (!$round->is_played || $round->score_a === null || $round->score_b === null) {
                continue;
            }
            $pairA = is_array($round->pair_a) ? $round->pair_a : [];
            $pairB = is_array($round->pair_b) ? $round->pair_b : [];
            $sa = (int) $round->score_a;
            $sb = (int) $round->score_b;

            foreach ($pairA as $uid) {
                $uid = (int) $uid;
                $ensure($uid);
                $stats[$uid]['points'] += $sa;
                $stats[$uid]['for'] += $sa;
                $stats[$uid]['against'] += $sb;
                if ($sa > $sb) $stats[$uid]['wins']++;
            }
            foreach ($pairB as $uid) {
                $uid = (int) $uid;
                $ensure($uid);
                $stats[$uid]['points'] += $sb;
                $stats[$uid]['for'] += $sb;
                $stats[$uid]['against'] += $sa;
                if ($sb > $sa) $stats[$uid]['wins']++;
            }
        }

        $list = array_values($stats);
        usort($list, function ($a, $b) {
            if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
            if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
            $da = $a['for'] - $a['against'];
            $db = $b['for'] - $b['against'];
            if ($da !== $db) return $db <=> $da;
            return $a['id'] <=> $b['id'];
        });

        return $list;
    }

    /** @return int[] user_id по местам (1-е место первым). */
    public static function orderedIds(Game $game): array
    {
        return array_map(fn ($s) => (int) $s['id'], self::computeSorted($game));
    }

    /** Место игрока (1-based) или null, если он не участвовал. */
    public static function place(Game $game, int $userId): ?int
    {
        $idx = array_search($userId, self::orderedIds($game), true);
        return $idx === false ? null : $idx + 1;
    }

    /** Таблица для сериализации: [{user_id, points, wins, diff, place}], в порядке мест. */
    public static function table(Game $game): array
    {
        $out = [];
        foreach (self::computeSorted($game) as $i => $s) {
            $out[] = [
                'user_id' => (int) $s['id'],
                'points' => (int) $s['points'],
                'wins' => (int) $s['wins'],
                'diff' => (int) ($s['for'] - $s['against']),
                'place' => $i + 1,
            ];
        }
        return $out;
    }
}

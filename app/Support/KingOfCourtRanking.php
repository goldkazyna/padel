<?php

namespace App\Support;

use App\Models\Tournament;

/**
 * Единый источник ранжирования «Короля корта» (соло).
 *
 * Порядок (по убыванию): очки → разница мячей → % побед → ЛИЧНАЯ ВСТРЕЧА →
 * рейтинг → id (стабильно). Личная встреча (head-to-head) — кто из двух
 * равных игроков чаще побеждал другого, когда они были в разных командах.
 * Последний критерий (рейтинг → id) детерминированный, чтобы порядок был
 * ОДИНАКОВЫМ на всех экранах (таблица, «место», история) — без «рандома»,
 * который скакал бы между запросами.
 *
 * ВАЖНО: используется ВЕЗДЕ, где нужен порядок/место соло-КК —
 * не заводить отдельные сортировки (иначе «7 снаружи / 8 внутри»).
 */
class KingOfCourtRanking
{
    /** Упорядоченные строки игроков (индекс 0 = 1-е место). */
    public static function standings(Tournament $tournament): array
    {
        $players = $tournament->kingOfCourtPlayers()->with('user')->get();

        $rows = [];
        foreach ($players as $kp) {
            $u = $kp->user;
            if (!$u) continue;
            $balls = (int) $kp->points_for + (int) $kp->points_against;
            $rows[] = [
                'kp' => $kp,
                'user' => $u,
                'id' => (int) $u->id,
                'total_points' => (int) $kp->total_points,
                'wins' => (int) $kp->wins,
                'losses' => (int) $kp->losses,
                'points_for' => (int) $kp->points_for,
                'points_against' => (int) $kp->points_against,
                'diff' => (int) $kp->points_for - (int) $kp->points_against,
                // % как в колонке таблицы — доля выигранных мячей.
                'ball_pct' => $balls > 0 ? $kp->points_for / $balls : 0.0,
                'rating' => (int) ($u->rating ?? 0),
            ];
        }

        $h2h = AmericanoTie::fromMatches(self::matches($tournament));

        usort($rows, function ($a, $b) use ($h2h) {
            if ($a['total_points'] !== $b['total_points']) {
                return $b['total_points'] <=> $a['total_points'];
            }
            if ($a['diff'] !== $b['diff']) {
                return $b['diff'] <=> $a['diff'];
            }
            if ($a['ball_pct'] != $b['ball_pct']) {
                return $b['ball_pct'] <=> $a['ball_pct'];
            }
            // Личная встреча.
            $tie = AmericanoTie::compare($h2h, $a['id'], $b['id']);
            if ($tie !== 0) return $tie;
            // Стабильный детерминированный хвост.
            if ($a['rating'] !== $b['rating']) {
                return $b['rating'] <=> $a['rating'];
            }
            return $a['id'] <=> $b['id'];
        });

        return $rows;
    }

    /** Место игрока (1-based) или null. */
    public static function place(Tournament $tournament, int $userId): ?int
    {
        foreach (self::standings($tournament) as $i => $row) {
            if ($row['id'] === $userId) return $i + 1;
        }
        return null;
    }

    /** Плоский список завершённых матчей КК (для head-to-head). */
    private static function matches(Tournament $tournament): array
    {
        $out = [];
        foreach ($tournament->kingOfCourtRounds()->with('matches')->get() as $round) {
            foreach ($round->matches as $m) {
                $out[] = $m;
            }
        }
        return $out;
    }
}

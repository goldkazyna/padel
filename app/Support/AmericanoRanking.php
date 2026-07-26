<?php

namespace App\Support;

use App\Models\Tournament;

/**
 * ЕДИНЫЙ порядок игроков для Американо/Мексикано:
 *   очки → победы → разница очков → личная встреча.
 *
 * Тот же порядок, что в «Таблице лидеров» (MobileTournamentController::getLeaderboard).
 * Используется для расчёта места в истории турниров И в своём профиле,
 * И в чужом (через рейтинг), чтобы место везде совпадало с таблицей.
 */
class AmericanoRanking
{
    /**
     * ID игроков в порядке занятых мест (1-е место — первым).
     * @return int[]
     */
    public static function orderedIds(Tournament $tournament): array
    {
        if (!in_array($tournament->type, ['americano', 'mexicano'], true)) {
            return [];
        }

        $stats = [];
        $h2h = [];

        if ($tournament->type === 'americano') {
            $groups = $tournament->groups()->with(['players', 'rounds.matches'])->get();
            $h2h = AmericanoTie::fromGroups($groups);

            foreach ($groups as $group) {
                foreach ($group->players as $player) {
                    if (!isset($stats[$player->id])) {
                        $stats[$player->id] = [
                            'id' => $player->id,
                            'total_points' => 0,
                            'wins' => 0,
                            'points_for' => 0,
                            'points_against' => 0,
                        ];
                    }
                    $stats[$player->id]['total_points'] += (int) ($player->pivot->total_points ?? 0);
                }
                foreach ($group->rounds as $round) {
                    foreach ($round->matches as $match) {
                        if ($match->status !== 'completed') continue;
                        self::count($stats, $match);
                    }
                }
            }
        } else { // mexicano
            foreach ($tournament->mexicanoPlayers()->get() as $mp) {
                if (!$mp->user_id) continue;
                $stats[$mp->user_id] = [
                    'id' => $mp->user_id,
                    'total_points' => (int) ($mp->total_points ?? 0),
                    'wins' => 0,
                    'points_for' => 0,
                    'points_against' => 0,
                ];
            }
            foreach ($tournament->mexicanoRounds()->with('matches')->get() as $round) {
                foreach ($round->matches as $match) {
                    if ($match->status !== 'completed') continue;
                    self::count($stats, $match);
                }
            }
        }

        usort($stats, function ($a, $b) use ($h2h) {
            if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
            if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
            $diffA = $a['points_for'] - $a['points_against'];
            $diffB = $b['points_for'] - $b['points_against'];
            if ($diffA !== $diffB) return $diffB <=> $diffA;
            return AmericanoTie::compare($h2h, $a['id'], $b['id']);
        });

        return array_map(fn($s) => (int) $s['id'], $stats);
    }

    /** Место игрока (1-based) или null, если он не участвовал. */
    public static function place(Tournament $tournament, int $userId): ?int
    {
        $index = array_search($userId, self::orderedIds($tournament), true);
        return $index === false ? null : $index + 1;
    }

    /** Подсчёт побед/забитых/пропущенных из матча (как countMatchStats). */
    private static function count(array &$stats, $match): void
    {
        $team1 = array_filter([$match->team1_player1_id, $match->team1_player2_id]);
        $team2 = array_filter([$match->team2_player1_id, $match->team2_player2_id]);

        foreach ($team1 as $pId) {
            if (!isset($stats[$pId])) continue;
            $stats[$pId]['points_for'] += (int) $match->team1_score;
            $stats[$pId]['points_against'] += (int) $match->team2_score;
            if ($match->team1_score > $match->team2_score) $stats[$pId]['wins']++;
        }
        foreach ($team2 as $pId) {
            if (!isset($stats[$pId])) continue;
            $stats[$pId]['points_for'] += (int) $match->team2_score;
            $stats[$pId]['points_against'] += (int) $match->team1_score;
            if ($match->team2_score > $match->team1_score) $stats[$pId]['wins']++;
        }
    }
}

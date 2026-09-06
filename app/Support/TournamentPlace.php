<?php

namespace App\Support;

use App\Models\Tournament;

/**
 * Какое место занял игрок в турнире.
 *
 * Раньше расчёт жил внутри контроллера рейтинга, а лента амигос и профиль
 * считали место по-своему — и цифры расходились. Теперь правило одно и
 * лежит здесь; звать его из новых мест, а не переписывать заново.
 */
class TournamentPlace
{
    /** @param  Tournament $tournament турнир с загруженными матчами плей-офф */
    public static function for($tournament, int $userId): ?int
    {
        // Команды игрока (для team-турниров)
        $myTeamIds = \App\Models\TournamentTeam::where('tournament_id', $tournament->id)
            ->where(function ($q) use ($userId) {
                $q->where('player1_id', $userId)->orWhere('player2_id', $userId);
            })
            ->pluck('id');

        // Проверяем финал (только верхняя сетка — чемпион/призёры из неё)
        $finalMatch = $tournament->playoffMatches()
            ->whereIn('stage', ['final', 'Финал'])
            ->where(function ($q) { $q->where('bracket', 'upper')->orWhereNull('bracket'); })
            ->where('status', 'completed')
            ->first();

        if ($finalMatch) {
            // Player-based (americano/mexicano)
            if ($finalMatch->team1_player1_id) {
                $inTeam1 = in_array($userId, [$finalMatch->team1_player1_id, $finalMatch->team1_player2_id]);
                $inTeam2 = in_array($userId, [$finalMatch->team2_player1_id, $finalMatch->team2_player2_id]);

                if ($inTeam1 || $inTeam2) {
                    $team1Won = $finalMatch->team1_score > $finalMatch->team2_score;
                    return ($inTeam1 && $team1Won) || ($inTeam2 && !$team1Won) ? 1 : 2;
                }
            }

            // Team-based (team)
            if ($finalMatch->team1_id && $myTeamIds->isNotEmpty()) {
                $inTeam1 = $myTeamIds->contains($finalMatch->team1_id);
                $inTeam2 = $myTeamIds->contains($finalMatch->team2_id);

                if ($inTeam1 || $inTeam2) {
                    if ($finalMatch->winner_id && $myTeamIds->contains($finalMatch->winner_id)) return 1;
                    return 2;
                }
            }

            // Матч за 3-е место (is_bronze) — если сыгран, именно он определяет
            // 3/4 место (приоритетнее эвристики по полуфиналу ниже). Работает
            // одинаково для формата с полуфиналами и для winners_final (без них).
            $bronzeMatch = $tournament->playoffMatches()
                ->where('is_bronze', true)
                ->whereIn('stage', ['final', 'Финал'])
                ->where('status', 'completed')
                ->first();

            if ($bronzeMatch) {
                // Player-based
                if ($bronzeMatch->team1_player1_id) {
                    $inTeam1 = in_array($userId, [$bronzeMatch->team1_player1_id, $bronzeMatch->team1_player2_id]);
                    $inTeam2 = in_array($userId, [$bronzeMatch->team2_player1_id, $bronzeMatch->team2_player2_id]);

                    if ($inTeam1 || $inTeam2) {
                        $team1Won = $bronzeMatch->team1_score > $bronzeMatch->team2_score;
                        return ($inTeam1 && $team1Won) || ($inTeam2 && !$team1Won) ? 3 : 4;
                    }
                }

                // Team-based
                if ($bronzeMatch->team1_id && $myTeamIds->isNotEmpty()) {
                    $inTeam1 = $myTeamIds->contains($bronzeMatch->team1_id);
                    $inTeam2 = $myTeamIds->contains($bronzeMatch->team2_id);

                    if ($inTeam1 || $inTeam2) {
                        if ($bronzeMatch->winner_id && $myTeamIds->contains($bronzeMatch->winner_id)) return 3;
                        return 4;
                    }
                }
            }

            // Полуфинал — 3-4 место (только верхняя сетка, fallback когда нет
            // отдельного/сыгранного матча за 3-е место)
            $semiMatches = $tournament->playoffMatches()
                ->whereIn('stage', ['semi', 'Полуфинал'])
                ->where(function ($q) { $q->where('bracket', 'upper')->orWhereNull('bracket'); })
                ->where('status', 'completed')
                ->get();

            foreach ($semiMatches as $semi) {
                // Player-based
                $inSemi = in_array($userId, [
                    $semi->team1_player1_id, $semi->team1_player2_id,
                    $semi->team2_player1_id, $semi->team2_player2_id,
                ]);
                if ($inSemi) return 3;

                // Team-based
                if ($myTeamIds->isNotEmpty() && ($myTeamIds->contains($semi->team1_id) || $myTeamIds->contains($semi->team2_id))) {
                    return 3;
                }
            }
        }

        // Место по лидерборду (americano/mexicano) — единый источник ранжирования
        // (очки → победы → разница → личная встреча), совпадает с «Таблицей».
        if (in_array($tournament->type, ['americano', 'mexicano'])) {
            $place = \App\Support\AmericanoRanking::place($tournament, $userId);
            if ($place !== null) return $place;
        }

        // Король корта — место по лидерборду
        if ($tournament->type === 'king_of_court') {
            // Фикс-пары: место по таблице ПАР (у обоих игроков пары оно одинаковое).
            if ($tournament->isPairedKingOfCourt()) {
                $standings = app(\App\Services\KingOfCourtService::class)->getPairStandings($tournament);
                foreach ($standings as $i => $row) {
                    $pair = $row['pair'];
                    if ((int) $pair->player1_id === $userId || (int) $pair->player2_id === $userId) {
                        return $i + 1;
                    }
                }
                return null;
            }
            // Единый порядок (очки → разница → % → личная встреча → рейтинг → id).
            return \App\Support\KingOfCourtRanking::place($tournament, $userId);
        }

        // Just Padel It — место по лидерборду
        if ($tournament->type === 'just_padel_it') {
            if ($tournament->isPairedJustPadelIt()) {
                $standings = app(\App\Services\JustPadelItService::class)->getPairStandings($tournament);
                foreach ($standings as $i => $row) {
                    $pair = $row['pair'];
                    if ((int) $pair->player1_id === $userId || (int) $pair->player2_id === $userId) {
                        return $i + 1;
                    }
                }
                return null;
            }
            $q = $tournament->justPadelItPlayers();
            if ($tournament->jpi_rank_by_wins) {
                $q->orderByDesc('wins')->orderByDesc('total_points');
            } else {
                $q->orderByDesc('total_points')->orderByDesc('wins');
            }
            foreach ($q->get() as $i => $player) {
                if ($player->user_id === $userId) return $i + 1;
            }
        }

        // Round Robin — место по стандингам (победы → разница → личные встречи)
        if ($tournament->type === 'round_robin') {
            $standings = app(\App\Services\RoundRobinService::class)->standings($tournament);
            foreach ($standings as $i => $row) {
                if ((int) $row['user_id'] === $userId) return $i + 1;
            }
        }

        // Bali Format — место по парам (стандинги с tiebreaker через сервис)
        if ($tournament->type === 'bali_koc') {
            $standings = app(\App\Services\BaliKocService::class)->getStandings($tournament);
            foreach ($standings as $i => $pair) {
                if ((int) $pair->player1_id === $userId || (int) $pair->player2_id === $userId) {
                    return $i + 1;
                }
            }
        }

        // Team турнир без плей-офф — место по группе
        if ($tournament->type === 'team' && $myTeamIds->isNotEmpty()) {
            $groups = $tournament->groups()->with('teams')->get();
            foreach ($groups as $group) {
                $sorted = $group->teams->sortByDesc(fn($t) => $t->pivot->points ?? 0)->values();
                foreach ($sorted as $i => $team) {
                    if ($myTeamIds->contains($team->id)) return $i + 1;
                }
            }
        }

        // Americano Flex — место по итоговой таблице формата
        // (среднее → % побед → личная встреча → рейтинг, AmericanoFlexRanking).
        if ($tournament->type === 'americano_flex') {
            // Парный: место по таблице ПАР (игрок — player1 или player2).
            if ($tournament->isPairedFlex()) {
                $pairRows = app(\App\Services\AmericanoFlexService::class)->getPairedLeaderboard($tournament);
                foreach ($pairRows as $r) {
                    $p1 = $r['player1']->id ?? null;
                    $p2 = $r['player2']->id ?? null;
                    if ($p1 === $userId || $p2 === $userId) {
                        return (int) $r['position'];
                    }
                }
                return null;
            }

            return \App\Support\AmericanoFlexRanking::place($tournament, $userId);
        }

        return null;
    }
}

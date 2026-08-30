<?php

namespace App\Support;

use App\Models\Tournament;
use App\Models\TournamentTeam;

/**
 * Победитель турнира — один ответ на вопрос «выиграл ли этот игрок».
 *
 * Раньше значки считали победы своим кодом внутри `User::getTournamentStats()`,
 * и он расходился с местом в профиле: у Американо победитель не определялся
 * вовсе, поэтому игрок с первым местом в карточке видел «Выиграть турнир 0/1».
 *
 * Порядок такой же, как у места игрока: если сыгран финал — решает он,
 * иначе первая строка итоговой таблицы формата (её порядок знает ранжирование
 * формата, а не этот класс).
 */
class TournamentChampion
{
    /** Выиграл ли игрок этот турнир. */
    public static function is(Tournament $tournament, int $userId): bool
    {
        if (self::hasPlayoff($tournament)) {
            return self::wonFinal($tournament, $userId);
        }

        return self::topOfTable($tournament, $userId);
    }

    /** Сыгран ли финал верхней сетки — тогда чемпион определяется им. */
    private static function hasPlayoff(Tournament $tournament): bool
    {
        return $tournament->playoffMatches()
            ->whereIn('stage', ['final', 'Финал'])
            ->where('is_bronze', false)
            ->where(fn ($q) => $q->where('bracket', 'upper')->orWhereNull('bracket'))
            ->where('status', 'completed')
            ->exists();
    }

    private static function wonFinal(Tournament $tournament, int $userId): bool
    {
        $final = $tournament->playoffMatches()
            ->whereIn('stage', ['final', 'Финал'])
            ->where('is_bronze', false)
            ->where(fn ($q) => $q->where('bracket', 'upper')->orWhereNull('bracket'))
            ->where('status', 'completed')
            ->first();

        if (!$final) {
            return false;
        }

        // Матч по игрокам (американо, мексикано и т.п.).
        if ($final->team1_player1_id) {
            $inTeam1 = in_array($userId, [$final->team1_player1_id, $final->team1_player2_id], true);
            $inTeam2 = in_array($userId, [$final->team2_player1_id, $final->team2_player2_id], true);
            $team1Won = (int) $final->team1_score > (int) $final->team2_score;

            return ($inTeam1 && $team1Won) || ($inTeam2 && !$team1Won);
        }

        // Матч по командам (групповой турнир).
        if ($final->winner_id) {
            $myTeamIds = TournamentTeam::where('tournament_id', $tournament->id)
                ->where(fn ($q) => $q->where('player1_id', $userId)->orWhere('player2_id', $userId))
                ->pluck('id');

            return $myTeamIds->contains($final->winner_id);
        }

        return false;
    }

    /** Первая строка итоговой таблицы формата. */
    private static function topOfTable(Tournament $tournament, int $userId): bool
    {
        return match (true) {
            in_array($tournament->type, ['americano', 'mexicano'], true)
                => AmericanoRanking::place($tournament, $userId) === 1,

            $tournament->isPairedFlex() => self::topPair(
                app(\App\Services\AmericanoFlexService::class)->getPairedLeaderboard($tournament),
                $userId
            ),

            $tournament->type === 'americano_flex'
                => AmericanoFlexRanking::place($tournament, $userId) === 1,

            $tournament->isPairedKingOfCourt() => self::topPair(
                app(\App\Services\KingOfCourtService::class)->getPairStandings($tournament),
                $userId
            ),

            $tournament->type === 'king_of_court'
                => KingOfCourtRanking::place($tournament, $userId) === 1,

            default => false,
        };
    }

    /**
     * Верхняя пара таблицы: победа засчитывается обоим игрокам пары.
     *
     * @param array<int, array<string, mixed>> $standings
     */
    private static function topPair(array $standings, int $userId): bool
    {
        $pair = $standings[0]['pair'] ?? ($standings[0]['player1'] ?? null ? $standings[0] : null);
        if (!$pair) {
            return false;
        }

        if (is_array($pair)) {
            return in_array($userId, [
                $pair['player1']->id ?? null,
                $pair['player2']->id ?? null,
            ], true);
        }

        return (int) $pair->player1_id === $userId || (int) $pair->player2_id === $userId;
    }
}

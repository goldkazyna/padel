<?php

namespace App\Services;

use App\Models\AmericanoFlexBye;
use App\Models\AmericanoFlexMatch;
use App\Models\AmericanoFlexPairHistory;
use App\Models\AmericanoFlexPlayer;
use App\Models\AmericanoFlexRound;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Traits\RatingCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AmericanoFlexService
{
    use RatingCalculator;

    /**
     * Запустить турнир: создать AmericanoFlexPlayer для каждого участника,
     * сгенерировать первый раунд.
     */
    public function startTournament(Tournament $tournament): void
    {
        DB::transaction(function () use ($tournament) {
            $participants = TournamentParticipant::where('tournament_id', $tournament->id)
                ->where('status', 'registered')
                ->with('user')
                ->get();

            foreach ($participants as $p) {
                AmericanoFlexPlayer::firstOrCreate(
                    ['tournament_id' => $tournament->id, 'user_id' => $p->user_id],
                    [
                        'rating_before' => $p->user->rating,
                        'total_points' => 0,
                        'matches_played' => 0,
                        'bye_count' => 0,
                        'bye_streak' => 0,
                    ]
                );
            }

            $tournament->update(['status' => 'in_progress']);
            $this->generateNextRound($tournament);
        });
    }

    /**
     * Сгенерировать следующий раунд.
     */
    public function generateNextRound(Tournament $tournament): AmericanoFlexRound
    {
        // реализовано в Task 2.3
        throw new \RuntimeException('not implemented');
    }

    /**
     * Сохранить счёт матча, обновить points/matches_played игроков и pair_history.
     */
    public function saveMatchResult(AmericanoFlexMatch $match, int $score1, int $score2): void
    {
        // реализовано в Task 2.4
        throw new \RuntimeException('not implemented');
    }

    /**
     * Текущий открытый раунд (последний по round_number).
     */
    public function getCurrentRound(Tournament $tournament): ?AmericanoFlexRound
    {
        return $tournament->americanoFlexRounds()
            ->orderByDesc('round_number')
            ->first();
    }

    /**
     * Все матчи раунда завершены?
     */
    public function isRoundCompleted(AmericanoFlexRound $round): bool
    {
        return $round->matches()->where('status', '!=', 'completed')->count() === 0;
    }

    /**
     * Завершить турнир: посчитать ELO для всех игроков, выставить статус.
     */
    public function completeTournament(Tournament $tournament): void
    {
        // реализовано в Task 2.5
        throw new \RuntimeException('not implemented');
    }

    /**
     * Лидерборд: коллекция AmericanoFlexPlayer, сортировка по среднему DESC.
     */
    public function getLeaderboard(Tournament $tournament): Collection
    {
        return $tournament->americanoFlexPlayers()
            ->with('user')
            ->get()
            ->sortByDesc(function ($p) {
                return $p->matches_played > 0
                    ? $p->total_points / $p->matches_played
                    : 0;
            })
            ->values();
    }
}

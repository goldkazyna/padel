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
        return DB::transaction(function () use ($tournament) {
            $lastRound = $this->getCurrentRound($tournament);
            $nextNumber = $lastRound ? $lastRound->round_number + 1 : 1;

            // 1. Выбираем играющих
            $playing = $this->selectPlayersForRound($tournament);
            $playingIds = array_map(fn($p) => $p->user_id, $playing);

            // 2. Остальные — отдыхают
            $allPlayers = $tournament->americanoFlexPlayers()->get();
            $resting = $allPlayers->whereNotIn('user_id', $playingIds);

            // 3. Создаём раунд
            $round = AmericanoFlexRound::create([
                'tournament_id' => $tournament->id,
                'round_number' => $nextNumber,
                'status' => 'in_progress',
            ]);

            // 4. Формируем пары и создаём матчи
            $matches = $this->generatePairsForRound($tournament, $playing);
            foreach ($matches as $m) {
                AmericanoFlexMatch::create([
                    'americano_flex_round_id' => $round->id,
                    'court_number' => $m['court'],
                    'team1_player1_id' => $m['team1'][0],
                    'team1_player2_id' => $m['team1'][1],
                    'team2_player1_id' => $m['team2'][0],
                    'team2_player2_id' => $m['team2'][1],
                    'status' => 'pending',
                ]);
            }

            // 5. Записываем отдыхающих
            foreach ($resting as $r) {
                AmericanoFlexBye::create([
                    'americano_flex_round_id' => $round->id,
                    'user_id' => $r->user_id,
                ]);
            }

            // 6. Обновляем bye_streak
            AmericanoFlexPlayer::where('tournament_id', $tournament->id)
                ->whereIn('user_id', $playingIds)
                ->update(['bye_streak' => 0]);
            AmericanoFlexPlayer::where('tournament_id', $tournament->id)
                ->whereNotIn('user_id', $playingIds)
                ->increment('bye_streak');
            AmericanoFlexPlayer::where('tournament_id', $tournament->id)
                ->whereNotIn('user_id', $playingIds)
                ->increment('bye_count');

            return $round;
        });
    }

    /**
     * Выбрать M*4 игроков для нового раунда.
     * Приоритеты: bye_streak DESC → matches_played ASC → рандом.
     */
    private function selectPlayersForRound(Tournament $tournament): array
    {
        $needed = $tournament->courts_count * 4;
        $players = $tournament->americanoFlexPlayers()
            ->with('user')
            ->get()
            ->shuffle()  // рандом для tie-breaker
            ->sortBy([
                ['bye_streak', 'desc'],
                ['matches_played', 'asc'],
            ])
            ->values();

        if ($players->count() <= $needed) {
            return $players->all();  // все играют, никто не отдыхает
        }

        return $players->take($needed)->all();
    }

    /**
     * Сформировать M матчей из массива M*4 AmericanoFlexPlayer.
     * Минимизирует повторы по системе штрафов из ТЗ:
     *   Пара (partners):    0=0, 1=100, 2+=100*N
     *   Соперник (opponents): 0=0, 1=1, 2=50, 3+=500
     * Возвращает массив матчей: [['team1' => [id1, id2], 'team2' => [id3, id4], 'court' => 1], ...]
     */
    private function generatePairsForRound(Tournament $tournament, array $players): array
    {
        $playerIds = array_map(fn($p) => $p->user_id, $players);
        $history = AmericanoFlexPairHistory::where('tournament_id', $tournament->id)
            ->whereIn('player1_id', $playerIds)
            ->whereIn('player2_id', $playerIds)
            ->get()
            ->keyBy(fn($h) => $h->player1_id . '-' . $h->player2_id);

        $row = function (int $a, int $b) use ($history) {
            [$lo, $hi] = AmericanoFlexPairHistory::normalizeIds($a, $b);
            return $history["{$lo}-{$hi}"] ?? null;
        };

        // Штраф за повторение пары (партнёры в одной команде).
        $partnerPenalty = function (int $a, int $b) use ($row) {
            $r = $row($a, $b);
            $n = $r ? (int) $r->times_as_partners : 0;
            return $n === 0 ? 0 : 100 * $n;
        };

        // Штраф за повторение соперников (игроки в разных командах).
        $opponentPenalty = function (int $a, int $b) use ($row) {
            $r = $row($a, $b);
            $n = $r ? (int) $r->times_as_opponents : 0;
            return match (true) {
                $n === 0 => 0,
                $n === 1 => 1,
                $n === 2 => 50,
                default  => 500,
            };
        };

        $matches = [];
        $remaining = $playerIds;
        $courtNum = 1;

        while (count($remaining) >= 4) {
            // Перебираем все возможные комбинации первой четвёрки, выбираем минимум cost
            $bestMatch = null;
            $bestCost = PHP_INT_MAX;

            for ($i = 0; $i < count($remaining); $i++) {
                for ($j = $i + 1; $j < count($remaining); $j++) {
                    for ($k = $j + 1; $k < count($remaining); $k++) {
                        for ($l = $k + 1; $l < count($remaining); $l++) {
                            $A = $remaining[$i]; $B = $remaining[$j];
                            $C = $remaining[$k]; $D = $remaining[$l];

                            // 3 варианта разделения 4 игроков на 2 команды
                            $variants = [
                                ['t1' => [$A, $B], 't2' => [$C, $D]],
                                ['t1' => [$A, $C], 't2' => [$B, $D]],
                                ['t1' => [$A, $D], 't2' => [$B, $C]],
                            ];

                            foreach ($variants as $v) {
                                // 2 партнёрские пары
                                $matchCost =
                                    $partnerPenalty($v['t1'][0], $v['t1'][1]) +
                                    $partnerPenalty($v['t2'][0], $v['t2'][1]) +
                                    // 4 кросса (соперники)
                                    $opponentPenalty($v['t1'][0], $v['t2'][0]) +
                                    $opponentPenalty($v['t1'][0], $v['t2'][1]) +
                                    $opponentPenalty($v['t1'][1], $v['t2'][0]) +
                                    $opponentPenalty($v['t1'][1], $v['t2'][1]);

                                if ($matchCost < $bestCost) {
                                    $bestCost = $matchCost;
                                    $bestMatch = ['indices' => [$i, $j, $k, $l], 'teams' => $v];
                                }
                            }
                        }
                    }
                }
            }

            if (!$bestMatch) break;

            $matches[] = [
                'team1' => $bestMatch['teams']['t1'],
                'team2' => $bestMatch['teams']['t2'],
                'court' => $courtNum++,
            ];

            // Удаляем использованных игроков
            rsort($bestMatch['indices']);
            foreach ($bestMatch['indices'] as $idx) {
                array_splice($remaining, $idx, 1);
            }
        }

        return $matches;
    }

    /**
     * Сохранить счёт матча, обновить points/matches_played игроков и pair_history.
     */
    public function saveMatchResult(AmericanoFlexMatch $match, int $score1, int $score2): void
    {
        DB::transaction(function () use ($match, $score1, $score2) {
            $match->update([
                'team1_score' => $score1,
                'team2_score' => $score2,
                'status' => 'completed',
            ]);

            $tournamentId = $match->round->tournament_id;
            $team1Ids = [$match->team1_player1_id, $match->team1_player2_id];
            $team2Ids = [$match->team2_player1_id, $match->team2_player2_id];

            // Очки игроков: команда получает свой счёт; matches_played +1
            $query1 = AmericanoFlexPlayer::where('tournament_id', $tournamentId)
                ->whereIn('user_id', $team1Ids);
            $query1->increment('total_points', $score1);
            $query1->increment('matches_played');

            $query2 = AmericanoFlexPlayer::where('tournament_id', $tournamentId)
                ->whereIn('user_id', $team2Ids);
            $query2->increment('total_points', $score2);
            $query2->increment('matches_played');

            // pair_history: +1 partners для команд, +1 opponents для крестов
            $this->incrementPairHistory($tournamentId, $team1Ids[0], $team1Ids[1], 'partners');
            $this->incrementPairHistory($tournamentId, $team2Ids[0], $team2Ids[1], 'partners');
            foreach ($team1Ids as $a) {
                foreach ($team2Ids as $b) {
                    $this->incrementPairHistory($tournamentId, $a, $b, 'opponents');
                }
            }

            // Если все матчи раунда завершены — пометить раунд completed
            if ($this->isRoundCompleted($match->round)) {
                $match->round->update(['status' => 'completed']);
            }
        });
    }

    private function incrementPairHistory(int $tournamentId, int $a, int $b, string $kind): void
    {
        [$lo, $hi] = AmericanoFlexPairHistory::normalizeIds($a, $b);
        $col = $kind === 'partners' ? 'times_as_partners' : 'times_as_opponents';
        $row = AmericanoFlexPairHistory::firstOrCreate(
            ['tournament_id' => $tournamentId, 'player1_id' => $lo, 'player2_id' => $hi],
            ['times_as_partners' => 0, 'times_as_opponents' => 0]
        );
        $row->increment($col);
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
        DB::transaction(function () use ($tournament) {
            // Идём по всем матчам в явном порядке: раунды по round_number, внутри раунда — по id.
            $roundIds = $tournament->americanoFlexRounds()
                ->orderBy('round_number')
                ->pluck('id');

            $matches = AmericanoFlexMatch::whereIn('americano_flex_round_id', $roundIds)
                ->where('status', 'completed')
                ->orderBy('americano_flex_round_id')
                ->orderBy('id')
                ->get();

            // Стартовые рейтинги — из AmericanoFlexPlayer.rating_before
            $players = $tournament->americanoFlexPlayers()->with('user')->get()->keyBy('user_id');
            $currentRatings = [];
            foreach ($players as $p) {
                $currentRatings[$p->user_id] = $p->rating_before ?? 1500;
            }

            foreach ($matches as $match) {
                $r11 = $currentRatings[$match->team1_player1_id];
                $r12 = $currentRatings[$match->team1_player2_id];
                $r21 = $currentRatings[$match->team2_player1_id];
                $r22 = $currentRatings[$match->team2_player2_id];

                $t1 = ($r11 + $r12) / 2;
                $t2 = ($r21 + $r22) / 2;

                $result = $this->calculateRatingChange($t1, $t2, $match->team1_score, $match->team2_score);

                $currentRatings[$match->team1_player1_id] = $this->applyRatingChange($r11, $result['change1']);
                $currentRatings[$match->team1_player2_id] = $this->applyRatingChange($r12, $result['change1']);
                $currentRatings[$match->team2_player1_id] = $this->applyRatingChange($r21, $result['change2']);
                $currentRatings[$match->team2_player2_id] = $this->applyRatingChange($r22, $result['change2']);
            }

            // Сохраняем rating_after в players + обновляем users.rating
            // Аналогично MexicanoService::finishTournament: применяем дельту к актуальному
            // рейтингу пользователя и пишем запись в rating_history для аналитики.
            foreach ($players as $userId => $player) {
                $calcFinal = (int) ($currentRatings[$userId] ?? $player->rating_before ?? 1500);
                $delta = $calcFinal - (int) $player->rating_before;
                $actualBefore = (int) $player->user->rating;
                $actualAfter = max($this->minRating, $actualBefore + $delta);

                $player->update(['rating_after' => $actualAfter]);
                $player->user->update(['rating' => $actualAfter]);
                $this->updateLevel($player->user->fresh());

                \App\Models\RatingHistory::create([
                    'user_id' => $player->user_id,
                    'tournament_id' => $tournament->id,
                    'rating_before' => $actualBefore,
                    'rating_after' => $actualAfter,
                    'change' => $delta,
                    'reason' => $tournament->name,
                ]);
            }

            $tournament->update(['status' => 'completed']);
        });
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

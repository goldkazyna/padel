<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\AmericanoRound;
use App\Models\AmericanoMatch;
use App\Models\User;

class AmericanoService
{
    /**
     * Запустить турнир Американо
     */
    public function startTournament(Tournament $tournament): bool
    {
        $participants = $tournament->participants()->orderBy('rating', 'desc')->get();
        
        if ($participants->count() !== $tournament->max_participants) {
            return false;
        }

        if ($tournament->groups()->count() > 0) {
            return false;
        }

        $groupsCount = $tournament->groups_count;
        $playersPerGroup = intval($participants->count() / $groupsCount);
        
        for ($i = 0; $i < $groupsCount; $i++) {
            $group = TournamentGroup::create([
                'tournament_id' => $tournament->id,
                'name' => 'Группа ' . chr(65 + $i),
            ]);

            $groupPlayers = $participants->slice($i * $playersPerGroup, $playersPerGroup);

            foreach ($groupPlayers as $player) {
                $group->players()->attach($player->id, [
                    'total_points' => 0,
                    'rating_before' => $player->rating, // Сохраняем исходный рейтинг
                    'rating_after' => null,
                ]);
            }

            $this->generateRounds($group, $groupPlayers->pluck('id')->toArray());
        }

        $tournament->update(['status' => 'in_progress']);

        return true;
    }

    /**
     * Генерация раундов
     */
    protected function generateRounds(TournamentGroup $group, array $playerIds): void
    {
        $players = $playerIds;
        $numPlayers = count($players);
        $numRounds = $numPlayers - 1;

        if ($numPlayers % 2 !== 0) {
            $players[] = null;
            $numPlayers++;
            $numRounds++;
        }

        for ($roundNum = 1; $roundNum <= $numRounds; $roundNum++) {
            $round = AmericanoRound::create([
                'tournament_group_id' => $group->id,
                'round_number' => $roundNum,
                'status' => $roundNum === 1 ? 'in_progress' : 'pending',
            ]);

            $pairs = $this->generatePairsForRound($players, $roundNum);
            $this->createMatches($round, $pairs);
            $players = $this->rotatePlayersForNextRound($players);
        }
    }

    protected function generatePairsForRound(array $players, int $roundNum): array
    {
        $pairs = [];
        $n = count($players);

        for ($i = 0; $i < $n / 2; $i++) {
            $player1 = $players[$i];
            $player2 = $players[$n - 1 - $i];

            if ($player1 !== null && $player2 !== null) {
                $pairs[] = [$player1, $player2];
            }
        }

        return $pairs;
    }

    protected function createMatches(AmericanoRound $round, array $pairs): void
    {
        for ($i = 0; $i < count($pairs); $i += 2) {
            if (isset($pairs[$i]) && isset($pairs[$i + 1])) {
                AmericanoMatch::create([
                    'americano_round_id' => $round->id,
                    'team1_player1_id' => $pairs[$i][0],
                    'team1_player2_id' => $pairs[$i][1],
                    'team2_player1_id' => $pairs[$i + 1][0],
                    'team2_player2_id' => $pairs[$i + 1][1],
                    'status' => 'pending',
                ]);
            }
        }
    }

    protected function rotatePlayersForNextRound(array $players): array
    {
        $first = array_shift($players);
        $last = array_pop($players);
        array_unshift($players, $last);
        array_unshift($players, $first);
        
        return $players;
    }

    /**
     * Сохранить результат матча (только очки, без Эло)
     */
    public function saveMatchResult(AmericanoMatch $match, int $team1Score, int $team2Score): void
    {
        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'status' => 'completed',
        ]);

        $group = $match->round->group;
        
        // Обновляем только очки в группе
        $this->addPlayerPoints($group, $match->team1_player1_id, $team1Score);
        $this->addPlayerPoints($group, $match->team1_player2_id, $team1Score);
        $this->addPlayerPoints($group, $match->team2_player1_id, $team2Score);
        $this->addPlayerPoints($group, $match->team2_player2_id, $team2Score);

        $this->checkRoundCompletion($match->round);
    }

    /**
     * Обновить результат матча
     */
    public function updateMatchResult(AmericanoMatch $match, int $team1Score, int $team2Score): void
    {
        $group = $match->round->group;
        
        // Откатываем старые очки
        if ($match->isCompleted()) {
            $this->addPlayerPoints($group, $match->team1_player1_id, -$match->team1_score);
            $this->addPlayerPoints($group, $match->team1_player2_id, -$match->team1_score);
            $this->addPlayerPoints($group, $match->team2_player1_id, -$match->team2_score);
            $this->addPlayerPoints($group, $match->team2_player2_id, -$match->team2_score);
        }

        // Сохраняем новые
        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'status' => 'completed',
        ]);

        $this->addPlayerPoints($group, $match->team1_player1_id, $team1Score);
        $this->addPlayerPoints($group, $match->team1_player2_id, $team1Score);
        $this->addPlayerPoints($group, $match->team2_player1_id, $team2Score);
        $this->addPlayerPoints($group, $match->team2_player2_id, $team2Score);
    }

    protected function addPlayerPoints(TournamentGroup $group, int $playerId, int $points): void
    {
        $group->players()->updateExistingPivot($playerId, [
            'total_points' => \DB::raw("total_points + {$points}")
        ]);
    }

    protected function checkRoundCompletion(AmericanoRound $round): void
    {
        $allCompleted = $round->matches()->where('status', 'pending')->count() === 0;

        if ($allCompleted) {
            $round->update(['status' => 'completed']);

            $nextRound = AmericanoRound::where('tournament_group_id', $round->tournament_group_id)
                ->where('round_number', $round->round_number + 1)
                ->first();

            if ($nextRound) {
                $nextRound->update(['status' => 'in_progress']);
            }
        }
    }

    /**
     * Проверить можно ли завершить турнир
     */
    public function canFinishTournament(Tournament $tournament): bool
    {
        foreach ($tournament->groups as $group) {
            foreach ($group->rounds as $round) {
                if (!$round->isCompleted()) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Завершить турнир и начислить Эло
     */
    public function finishTournament(Tournament $tournament): bool
    {
        if (!$this->canFinishTournament($tournament)) {
            return false;
        }

        // Собираем все изменения рейтинга
        $ratingChanges = [];

        foreach ($tournament->groups as $group) {
            // Инициализируем с исходными рейтингами
            foreach ($group->players as $player) {
                $ratingChanges[$player->id] = [
                    'rating_before' => $player->pivot->rating_before,
                    'current_rating' => $player->pivot->rating_before,
                ];
            }

            // Проходим по всем матчам и считаем Эло
            foreach ($group->rounds as $round) {
                foreach ($round->matches as $match) {
                    $this->calculateEloForMatch($match, $ratingChanges);
                }
            }

            // Сохраняем финальные рейтинги
            foreach ($group->players as $player) {
                $newRating = $ratingChanges[$player->id]['current_rating'];
                
                // Обновляем pivot
                $group->players()->updateExistingPivot($player->id, [
                    'rating_after' => $newRating,
                ]);

                // Обновляем рейтинг игрока
                $player->update(['rating' => $newRating]);
				
				// Записываем историю
				\App\Models\RatingHistory::create([
					'user_id' => $player->id,
					'tournament_id' => $tournament->id,
					'rating_before' => $ratingChanges[$player->id]['rating_before'],
					'rating_after' => $newRating,
					'change' => $newRating - $ratingChanges[$player->id]['rating_before'],
					'reason' => $tournament->name,
				]);
                
            }
        }

        $tournament->update(['status' => 'completed']);

        return true;
    }

    /**
     * Рассчитать Эло для матча (накопительно)
     */
    protected function calculateEloForMatch(AmericanoMatch $match, array &$ratingChanges): void
    {
        $p1_1 = $match->team1_player1_id;
        $p1_2 = $match->team1_player2_id;
        $p2_1 = $match->team2_player1_id;
        $p2_2 = $match->team2_player2_id;

        // Текущие рейтинги (накопленные)
        $team1Rating = ($ratingChanges[$p1_1]['current_rating'] + $ratingChanges[$p1_2]['current_rating']) / 2;
        $team2Rating = ($ratingChanges[$p2_1]['current_rating'] + $ratingChanges[$p2_2]['current_rating']) / 2;

        $expected1 = $this->expectedScore($team1Rating, $team2Rating);
        $expected2 = $this->expectedScore($team2Rating, $team1Rating);

        if ($match->team1_score > $match->team2_score) {
            $actual1 = 1;
            $actual2 = 0;
        } elseif ($match->team2_score > $match->team1_score) {
            $actual1 = 0;
            $actual2 = 1;
        } else {
            $actual1 = 0.5;
            $actual2 = 0.5;
        }

        $kFactor = 24;

        $change1 = round($kFactor * ($actual1 - $expected1));
        $change2 = round($kFactor * ($actual2 - $expected2));

        // Применяем изменения
        $ratingChanges[$p1_1]['current_rating'] = max(100, $ratingChanges[$p1_1]['current_rating'] + $change1);
        $ratingChanges[$p1_2]['current_rating'] = max(100, $ratingChanges[$p1_2]['current_rating'] + $change1);
        $ratingChanges[$p2_1]['current_rating'] = max(100, $ratingChanges[$p2_1]['current_rating'] + $change2);
        $ratingChanges[$p2_2]['current_rating'] = max(100, $ratingChanges[$p2_2]['current_rating'] + $change2);
    }

    protected function expectedScore(float $ratingA, float $ratingB): float
    {
        return 1 / (1 + pow(10, ($ratingB - $ratingA) / 400));
    }

    protected function updateLevel($player): void
    {
        $rating = $player->rating;
        
        $level = match(true) {
            $rating < 800 => 1.0,
            $rating < 900 => 1.25,
            $rating < 1000 => 1.5,
            $rating < 1100 => 1.75,
            $rating < 1200 => 2.0,
            $rating < 1300 => 2.25,
            $rating < 1400 => 2.5,
            $rating < 1500 => 2.75,
            $rating < 1600 => 3.0,
            $rating < 1700 => 3.25,
            $rating < 1800 => 3.5,
            $rating < 1900 => 3.75,
            $rating < 2000 => 4.0,
            $rating < 2100 => 4.25,
            $rating < 2200 => 4.5,
            $rating < 2300 => 4.75,
            $rating < 2400 => 5.0,
            $rating < 2500 => 5.25,
            $rating < 2600 => 5.5,
            default => 5.75,
        };

        $player->update(['level' => $level]);
    }
	
	/**
 * Превью расчёта рейтинга (без сохранения)
 */
public function previewRatingChanges(Tournament $tournament): array
{
    $preview = [];

    foreach ($tournament->groups as $group) {
        $ratingChanges = [];
        
        foreach ($group->players as $player) {
            $ratingBefore = (int) $player->pivot->rating_before;
            if ($ratingBefore <= 0) {
                $ratingBefore = (int) $player->rating;
            }
            
            $ratingChanges[$player->id] = [
                'name' => $player->full_name,
                'rating_before' => $ratingBefore,
                'current_rating' => $ratingBefore,
                'matches' => [],
            ];
        }

        foreach ($group->rounds()->orderBy('round_number')->get() as $round) {
            foreach ($round->matches as $match) {
                if (!$match->isCompleted()) continue;

                $p1_1 = $match->team1_player1_id;
                $p1_2 = $match->team1_player2_id;
                $p2_1 = $match->team2_player1_id;
                $p2_2 = $match->team2_player2_id;

                $team1RatingBefore = ($ratingChanges[$p1_1]['current_rating'] + $ratingChanges[$p1_2]['current_rating']) / 2;
                $team2RatingBefore = ($ratingChanges[$p2_1]['current_rating'] + $ratingChanges[$p2_2]['current_rating']) / 2;

                $expected1 = $this->expectedScore($team1RatingBefore, $team2RatingBefore);
                $expected2 = $this->expectedScore($team2RatingBefore, $team1RatingBefore);

                if ($match->team1_score > $match->team2_score) {
                    $actual1 = 1;
                    $actual2 = 0;
                } elseif ($match->team2_score > $match->team1_score) {
                    $actual1 = 0;
                    $actual2 = 1;
                } else {
                    $actual1 = 0.5;
                    $actual2 = 0.5;
                }

                $kFactor = 24;
                $change1 = round($kFactor * ($actual1 - $expected1));
                $change2 = round($kFactor * ($actual2 - $expected2));

                // Логируем
                $matchInfo = "Р{$round->round_number}: {$match->team1_score}:{$match->team2_score}";
                
                $ratingChanges[$p1_1]['matches'][] = "{$matchInfo} → {$change1}";
                $ratingChanges[$p1_2]['matches'][] = "{$matchInfo} → {$change1}";
                $ratingChanges[$p2_1]['matches'][] = "{$matchInfo} → {$change2}";
                $ratingChanges[$p2_2]['matches'][] = "{$matchInfo} → {$change2}";

                // Применяем
                $ratingChanges[$p1_1]['current_rating'] = max(100, $ratingChanges[$p1_1]['current_rating'] + $change1);
                $ratingChanges[$p1_2]['current_rating'] = max(100, $ratingChanges[$p1_2]['current_rating'] + $change1);
                $ratingChanges[$p2_1]['current_rating'] = max(100, $ratingChanges[$p2_1]['current_rating'] + $change2);
                $ratingChanges[$p2_2]['current_rating'] = max(100, $ratingChanges[$p2_2]['current_rating'] + $change2);
            }
        }

        $preview[$group->name] = $ratingChanges;
    }

    return $preview;
}
}
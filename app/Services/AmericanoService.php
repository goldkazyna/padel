<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\AmericanoRound;
use App\Models\AmericanoMatch;
use App\Models\User;
use App\Models\TournamentPlayoffMatch;

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
		// Проверяем все групповые матчи
		foreach ($tournament->groups as $group) {
			foreach ($group->rounds as $round) {
				if (!$round->isCompleted()) {
					return false;
				}
			}
		}
		
		// Если есть плей-офф — проверяем что финал сыгран
		if ($tournament->hasPlayoff()) {
			$final = $tournament->playoffMatches()
				->where('stage', 'Финал')
				->first();
			
			if (!$final || $final->status !== 'completed') {
				return false;
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

			// Сохраняем финальные рейтинги в pivot
			foreach ($group->players as $player) {
				$newRating = $ratingChanges[$player->id]['current_rating'];
				
				$group->players()->updateExistingPivot($player->id, [
					'rating_after' => $newRating,
				]);
			}
		}

		// Считаем Эло за плей-офф матчи
		if ($tournament->hasPlayoff()) {
			$playoffMatches = $tournament->playoffMatches()
				->where('status', 'completed')
				->get();

			foreach ($playoffMatches as $match) {
				$this->calculateEloForPlayoffMatch($match, $ratingChanges);
			}
		}

		// Сохраняем итоговые рейтинги игроков
		foreach ($ratingChanges as $playerId => $data) {
			$player = \App\Models\User::find($playerId);
			if ($player) {
				$player->update(['rating' => $data['current_rating']]);

				// Записываем историю
				\App\Models\RatingHistory::create([
					'user_id' => $playerId,
					'tournament_id' => $tournament->id,
					'rating_before' => $data['rating_before'],
					'rating_after' => $data['current_rating'],
					'change' => $data['current_rating'] - $data['rating_before'],
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
		$allRatingChanges = []; // Для плей-офф нужны все игроки
		
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
					
					$matchInfo = "Р{$round->round_number}: {$match->team1_score}:{$match->team2_score}";
					
					$ratingChanges[$p1_1]['matches'][] = "{$matchInfo} → {$change1}";
					$ratingChanges[$p1_2]['matches'][] = "{$matchInfo} → {$change1}";
					$ratingChanges[$p2_1]['matches'][] = "{$matchInfo} → {$change2}";
					$ratingChanges[$p2_2]['matches'][] = "{$matchInfo} → {$change2}";
					
					$ratingChanges[$p1_1]['current_rating'] = max(100, $ratingChanges[$p1_1]['current_rating'] + $change1);
					$ratingChanges[$p1_2]['current_rating'] = max(100, $ratingChanges[$p1_2]['current_rating'] + $change1);
					$ratingChanges[$p2_1]['current_rating'] = max(100, $ratingChanges[$p2_1]['current_rating'] + $change2);
					$ratingChanges[$p2_2]['current_rating'] = max(100, $ratingChanges[$p2_2]['current_rating'] + $change2);
				}
			}
			
			$preview[$group->name] = $ratingChanges;
			
			// Сохраняем для плей-офф
			foreach ($ratingChanges as $playerId => $data) {
				$allRatingChanges[$playerId] = $data;
			}
		}
		
		// Добавляем плей-офф матчи
		if ($tournament->hasPlayoff()) {
			$playoffMatches = $tournament->playoffMatches()
				->where('status', 'completed')
				->orderBy('stage')
				->orderBy('match_number')
				->get();
			
			if ($playoffMatches->count() > 0) {
				$playoffChanges = [];
				
				foreach ($playoffMatches as $match) {
					$p1_1 = $match->team1_player1_id;
					$p1_2 = $match->team1_player2_id;
					$p2_1 = $match->team2_player1_id;
					$p2_2 = $match->team2_player2_id;
					
					// Инициализируем если нет
					foreach ([$p1_1, $p1_2, $p2_1, $p2_2] as $pId) {
						if (!isset($allRatingChanges[$pId])) {
							$player = \App\Models\User::find($pId);
							if ($player) {
								$allRatingChanges[$pId] = [
									'name' => $player->full_name,
									'rating_before' => $player->rating,
									'current_rating' => $player->rating,
									'matches' => [],
								];
							}
						}
						if (!isset($playoffChanges[$pId])) {
							$playoffChanges[$pId] = $allRatingChanges[$pId];
							$playoffChanges[$pId]['matches'] = []; // Только плей-офф матчи
						}
					}
					
					$team1RatingBefore = ($allRatingChanges[$p1_1]['current_rating'] + $allRatingChanges[$p1_2]['current_rating']) / 2;
					$team2RatingBefore = ($allRatingChanges[$p2_1]['current_rating'] + $allRatingChanges[$p2_2]['current_rating']) / 2;
					
					$expected1 = $this->expectedScore($team1RatingBefore, $team2RatingBefore);
					$expected2 = $this->expectedScore($team2RatingBefore, $team1RatingBefore);
					
					if ($match->team1_score > $match->team2_score) {
						$actual1 = 1;
						$actual2 = 0;
					} else {
						$actual1 = 0;
						$actual2 = 1;
					}
					
					$kFactor = 20; // K-фактор для плей-офф
					$change1 = round($kFactor * ($actual1 - $expected1));
					$change2 = round($kFactor * ($actual2 - $expected2));
					
					$stageName = $match->stage === 'Полуфинал' ? 'ПФ' : 'Ф';
					$matchInfo = "{$stageName}{$match->match_number}: {$match->team1_score}:{$match->team2_score}";
					
					$playoffChanges[$p1_1]['matches'][] = "{$matchInfo} → {$change1}";
					$playoffChanges[$p1_2]['matches'][] = "{$matchInfo} → {$change1}";
					$playoffChanges[$p2_1]['matches'][] = "{$matchInfo} → {$change2}";
					$playoffChanges[$p2_2]['matches'][] = "{$matchInfo} → {$change2}";
					
					// Обновляем текущий рейтинг
					$allRatingChanges[$p1_1]['current_rating'] += $change1;
					$allRatingChanges[$p1_2]['current_rating'] += $change1;
					$allRatingChanges[$p2_1]['current_rating'] += $change2;
					$allRatingChanges[$p2_2]['current_rating'] += $change2;
					
					$playoffChanges[$p1_1]['current_rating'] = $allRatingChanges[$p1_1]['current_rating'];
					$playoffChanges[$p1_2]['current_rating'] = $allRatingChanges[$p1_2]['current_rating'];
					$playoffChanges[$p2_1]['current_rating'] = $allRatingChanges[$p2_1]['current_rating'];
					$playoffChanges[$p2_2]['current_rating'] = $allRatingChanges[$p2_2]['current_rating'];
				}
				
				// Добавляем только тех кто играл в плей-офф
				$playoffChanges = array_filter($playoffChanges, fn($p) => count($p['matches']) > 0);
				
				if (count($playoffChanges) > 0) {
					$preview['Плей-офф'] = $playoffChanges;
				}
			}
		}
		
		return $preview;
	}
	/**
	 * Можно ли сгенерировать плей-офф
	 */
	public function canGeneratePlayoff(Tournament $tournament): bool
	{
		// Турнир должен быть Американо с плей-офф
		if (!$tournament->isAmericano() || !$tournament->hasPlayoff()) {
			return false;
		}

		// Турнир должен быть в процессе
		if ($tournament->status !== 'in_progress') {
			return false;
		}

		// Плей-офф ещё не сгенерирован
		if ($tournament->playoffMatches()->count() > 0) {
			return false;
		}

		// Все групповые матчи должны быть сыграны
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
	 * Сгенерировать плей-офф для Американо
	 */
	public function generatePlayoff(Tournament $tournament): bool
	{
		if (!$this->canGeneratePlayoff($tournament)) {
			return false;
		}

		// Собираем топ-4 из каждой группы
		$leaders = [];
		
		foreach ($tournament->groups as $group) {
			$topPlayers = $group->players()
				->orderByPivot('total_points', 'desc')
				->limit(4)
				->get();
			
			$leaders[$group->name] = $topPlayers;
		}

		if ($tournament->isFinalOnly()) {
			$this->createFinalMatch($tournament, $leaders);
		} else {
			$this->createSemifinalMatches($tournament, $leaders);
		}

		return true;
	}

	/**
	 * Создать только финальный матч
	 */
	protected function createFinalMatch(Tournament $tournament, array $leaders): void
	{
		$groupNames = array_keys($leaders);
		
		if (count($groupNames) >= 2) {
			// 2 группы: A1+A2 vs B1+B2
			$A = $leaders[$groupNames[0]];
			$B = $leaders[$groupNames[1]];
			
			if ($A->count() >= 2 && $B->count() >= 2) {
				\App\Models\TournamentPlayoffMatch::create([
					'tournament_id' => $tournament->id,
					'stage' => 'Финал',
					'match_number' => 1,
					'team1_player1_id' => $A[0]->id,
					'team1_player2_id' => $A[1]->id,
					'team2_player1_id' => $B[0]->id,
					'team2_player2_id' => $B[1]->id,
					'status' => 'pending',
				]);
			}
		} elseif (count($groupNames) === 1) {
			// Одна группа: 1+4 vs 2+3
			$players = $leaders[$groupNames[0]];
			
			if ($players->count() >= 4) {
				\App\Models\TournamentPlayoffMatch::create([
					'tournament_id' => $tournament->id,
					'stage' => 'Финал',
					'match_number' => 1,
					'team1_player1_id' => $players[0]->id,
					'team1_player2_id' => $players[3]->id,
					'team2_player1_id' => $players[1]->id,
					'team2_player2_id' => $players[2]->id,
					'status' => 'pending',
				]);
			}
		}
	}

	/**
	 * Создать полуфиналы и финал
	 */
	protected function createSemifinalMatches(Tournament $tournament, array $leaders): void
	{
		$groupNames = array_keys($leaders);
		
		if (count($groupNames) < 2) {
			return;
		}
		
		// Группа A: A1, A2, A3, A4 (топ-4)
		// Группа B: B1, B2, B3, B4 (топ-4)
		$A = $leaders[$groupNames[0]];
		$B = $leaders[$groupNames[1]];
		
		if ($A->count() < 4 || $B->count() < 4) {
			return; // Недостаточно игроков
		}
		
		// Получаем формат плей-офф (по умолчанию mix)
		$format = $tournament->playoff_format ?? 'mix';
		
		// Формируем пары в зависимости от формата
		switch ($format) {
			case 'group_vs':
				// Группа vs Группа: A1+A2 vs B1+B2, A3+A4 vs B3+B4
				$semi1 = [
					'team1' => [$A[0]->id, $A[1]->id],
					'team2' => [$B[0]->id, $B[1]->id],
				];
				$semi2 = [
					'team1' => [$A[2]->id, $A[3]->id],
					'team2' => [$B[2]->id, $B[3]->id],
				];
				break;
				
			case 'tops':
				// Топы вместе: A1+B1 vs A3+B3, A2+B2 vs A4+B4
				$semi1 = [
					'team1' => [$A[0]->id, $B[0]->id],
					'team2' => [$A[2]->id, $B[2]->id],
				];
				$semi2 = [
					'team1' => [$A[1]->id, $B[1]->id],
					'team2' => [$A[3]->id, $B[3]->id],
				];
				break;
				
			case 'cross':
				// Крест: A1+B4 vs B1+A4, A2+B3 vs B2+A3
				$semi1 = [
					'team1' => [$A[0]->id, $B[3]->id],
					'team2' => [$B[0]->id, $A[3]->id],
				];
				$semi2 = [
					'team1' => [$A[1]->id, $B[2]->id],
					'team2' => [$B[1]->id, $A[2]->id],
				];
				break;
				
			case 'mix':
			default:
				// Микс: A1+B2 vs A3+B4, A2+B1 vs B3+A4
				$semi1 = [
					'team1' => [$A[0]->id, $B[1]->id],
					'team2' => [$A[2]->id, $B[3]->id],
				];
				$semi2 = [
					'team1' => [$A[1]->id, $B[0]->id],
					'team2' => [$B[2]->id, $A[3]->id],
				];
				break;
		}
		
		// Создаём полуфинал 1
		\App\Models\TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'stage' => 'Полуфинал',
			'match_number' => 1,
			'team1_player1_id' => $semi1['team1'][0],
			'team1_player2_id' => $semi1['team1'][1],
			'team2_player1_id' => $semi1['team2'][0],
			'team2_player2_id' => $semi1['team2'][1],
			'status' => 'pending',
		]);
		
		// Создаём полуфинал 2
		\App\Models\TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'stage' => 'Полуфинал',
			'match_number' => 2,
			'team1_player1_id' => $semi2['team1'][0],
			'team1_player2_id' => $semi2['team1'][1],
			'team2_player1_id' => $semi2['team2'][0],
			'team2_player2_id' => $semi2['team2'][1],
			'status' => 'pending',
		]);
		
		// Финал — пустой, заполнится после полуфиналов
		\App\Models\TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'stage' => 'Финал',
			'match_number' => 1,
			'status' => 'pending',
		]);
	}
	/**
	 * Обновить финал после полуфинала
	 */
	public function updateFinalAfterSemifinal(TournamentPlayoffMatch $semifinalMatch): void
	{
		$tournament = $semifinalMatch->tournament;
		
		// Проверяем что оба полуфинала сыграны
		$semifinals = $tournament->playoffMatches()
			->where('stage', 'Полуфинал')
			->get();
		
		$allCompleted = $semifinals->every(fn($m) => $m->status === 'completed');
		
		if (!$allCompleted) {
			return;
		}
		
		// Получаем победителей полуфиналов
		$winners = [];
		foreach ($semifinals as $semi) {
			if ($semi->team1_score > $semi->team2_score) {
				$winners[] = [
					'player1_id' => $semi->team1_player1_id,
					'player2_id' => $semi->team1_player2_id,
				];
			} else {
				$winners[] = [
					'player1_id' => $semi->team2_player1_id,
					'player2_id' => $semi->team2_player2_id,
				];
			}
		}
		
		// Обновляем финал
		$final = $tournament->playoffMatches()
			->where('stage', 'Финал')
			->first();
		
		if ($final && count($winners) >= 2) {
			$final->update([
				'team1_player1_id' => $winners[0]['player1_id'],
				'team1_player2_id' => $winners[0]['player2_id'],
				'team2_player1_id' => $winners[1]['player1_id'],
				'team2_player2_id' => $winners[1]['player2_id'],
				'status' => 'pending',
			]);
		}
	}
	/**
	 * Рассчитать Эло для плей-офф матча
	 */
	protected function calculateEloForPlayoffMatch($match, array &$ratingChanges): void
	{
		$player1Id = $match->team1_player1_id;
		$player2Id = $match->team1_player2_id;
		$player3Id = $match->team2_player1_id;
		$player4Id = $match->team2_player2_id;

		// Проверяем что все игроки есть в массиве
		foreach ([$player1Id, $player2Id, $player3Id, $player4Id] as $pId) {
			if (!isset($ratingChanges[$pId])) {
				$player = \App\Models\User::find($pId);
				if ($player) {
					$ratingChanges[$pId] = [
						'rating_before' => $player->rating,
						'current_rating' => $player->rating,
					];
				}
			}
		}

		// Текущие рейтинги
		$team1Rating = ($ratingChanges[$player1Id]['current_rating'] + $ratingChanges[$player2Id]['current_rating']) / 2;
		$team2Rating = ($ratingChanges[$player3Id]['current_rating'] + $ratingChanges[$player4Id]['current_rating']) / 2;

		// Определяем победителя
		$team1Won = $match->team1_score > $match->team2_score;

		// K-фактор для плей-офф (можно сделать выше чем в группах)
		$kFactor = 20;

		// Ожидаемый результат
		$expected1 = 1 / (1 + pow(10, ($team2Rating - $team1Rating) / 400));
		$expected2 = 1 - $expected1;

		// Фактический результат
		$actual1 = $team1Won ? 1 : 0;
		$actual2 = $team1Won ? 0 : 1;

		// Изменение рейтинга
		$change1 = round($kFactor * ($actual1 - $expected1));
		$change2 = round($kFactor * ($actual2 - $expected2));

		// Применяем изменения
		$ratingChanges[$player1Id]['current_rating'] += $change1;
		$ratingChanges[$player2Id]['current_rating'] += $change1;
		$ratingChanges[$player3Id]['current_rating'] += $change2;
		$ratingChanges[$player4Id]['current_rating'] += $change2;
	}
}
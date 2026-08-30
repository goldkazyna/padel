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
	use \App\Traits\RatingCalculator;
    /**
     * Запустить турнир Американо
     */
	public function startTournament(Tournament $tournament): bool
	{
		$participants = $tournament->participants()
			->wherePivot('status', 'registered')
			->orderBy('rating', 'desc')
			->get();
		
		if ($participants->count() !== $tournament->max_participants) {
			return false;
		}
		
		$groupsCount = $tournament->groups_count;
		$playersPerGroup = intval($participants->count() / $groupsCount);
		$courtsPerGroup = intval($playersPerGroup / 4);
		
		// Проверяем есть ли уже группы (созданы через редактор)
		$existingGroups = $tournament->groups()->with('players')->get();
		
		if ($existingGroups->count() > 0) {
			// Группы уже созданы — проверяем что все игроки распределены
			$assignedPlayerIds = $existingGroups->pluck('players')->flatten()->pluck('id')->toArray();
			$registeredPlayerIds = $participants->pluck('id')->toArray();
			
			// Проверяем что все зарегистрированные игроки в группах
			$unassigned = array_diff($registeredPlayerIds, $assignedPlayerIds);
			if (count($unassigned) > 0) {
				return false; // Не все игроки распределены
			}
			
			// Генерируем раунды для каждой группы
			foreach ($existingGroups as $index => $group) {
				// Проверяем что раунды ещё не созданы
				if ($group->rounds()->count() === 0) {
					$courtStartNumber = $index * $courtsPerGroup + 1;
					$groupPlayerIds = $group->players->pluck('id')->toArray();
					$this->generateRounds($group, $groupPlayerIds, $courtStartNumber);
				}
			}
		} else {
			// Создаём группы и распределяем игроков ЗМЕЙКОЙ по рейтингу,
			// чтобы силы групп были равны: ранги 1→A, 2→B, 3→B, 4→A, 5→A, 6→B, ...
			// (организатор при желании перераспределит вручную через редактор групп).
			$groups = [];
			for ($i = 0; $i < $groupsCount; $i++) {
				$groups[$i] = TournamentGroup::create([
					'tournament_id' => $tournament->id,
					'name' => 'Группа ' . chr(65 + $i),
				]);
			}

			// participants уже отсортированы по рейтингу DESC.
			$groupPlayerIds = array_fill(0, $groupsCount, []);
			foreach ($participants->values() as $index => $player) {
				$row = intdiv($index, $groupsCount);
				$pos = $index % $groupsCount;
				// Чётные ряды слева-направо, нечётные — справа-налево (змейка).
				$groupIndex = ($row % 2 === 0) ? $pos : ($groupsCount - 1 - $pos);

				$groups[$groupIndex]->players()->attach($player->id, [
					'total_points' => 0,
					'rating_before' => $player->rating,
					'rating_after' => null,
				]);
				$groupPlayerIds[$groupIndex][] = $player->id;
			}

			foreach ($groups as $i => $group) {
				$courtStartNumber = $i * $courtsPerGroup + 1;
				$this->generateRounds($group, $groupPlayerIds[$i], $courtStartNumber);
			}
		}
		
		$tournament->update(['status' => 'in_progress']);
		return true;
	}

	/**
	 * Генерация раундов
	 */
	protected function generateRounds(TournamentGroup $group, array $playerIds, int $courtStartNumber = 1): void
	{
		$players = array_values($playerIds);
		$numPlayers = count($players);
		
		$tournament = $group->tournament;
		$maxRounds = $numPlayers - 1;
		$numRounds = min($tournament->rounds_count ?? $maxRounds, $maxRounds);
		
		// Перемешиваем для разнообразия (кто будет под каким индексом)
		shuffle($players);
		
		// Пробуем оптимальное расписание
		$optimalSchedule = $this->getOptimalSchedule($numPlayers);
		
		if ($optimalSchedule) {
			\Log::info("Americano: оптимальное расписание для {$numPlayers} игроков");
			$this->generateFromOptimalSchedule($group, $players, $optimalSchedule, $numRounds, $courtStartNumber);
		} else {
			\Log::info("Americano: Round-Robin с балансировкой для {$numPlayers} игроков");
			$this->generateFromRoundRobinBalanced($group, $players, $numRounds, $courtStartNumber);
		}
	}
	/**
	 * Оптимальные расписания (0-based индексы)
	 * Проверены и гарантируют идеальный баланс
	 */
	protected function getOptimalSchedule(int $numPlayers): ?array
	{
		return \App\Support\PairingSchedules::forPlayers($numPlayers);
	}
	/**
	 * Генерация из оптимального расписания
	 */
	protected function generateFromOptimalSchedule(
		TournamentGroup $group,
		array $players,
		array $schedule,
		int $numRounds,
		int $courtStartNumber
	): void {
		for ($roundNum = 1; $roundNum <= $numRounds; $roundNum++) {
			if (!isset($schedule[$roundNum])) continue;
			
			$round = \App\Models\AmericanoRound::create([
				'tournament_group_id' => $group->id,
				'round_number' => $roundNum,
				'status' => $roundNum === 1 ? 'in_progress' : 'pending',
			]);
			
			// 1. Собираем матчи в массив
			$roundMatches = [];
			foreach ($schedule[$roundNum] as $match) {
				[$team1Indices, $team2Indices] = $match;
				
				$roundMatches[] = [
					'team1_player1_id' => $players[$team1Indices[0]],
					'team1_player2_id' => $players[$team1Indices[1]],
					'team2_player1_id' => $players[$team2Indices[0]],
					'team2_player2_id' => $players[$team2Indices[1]],
				];
			}
			
			// 2. Перемешиваем — рандомные корты!
			shuffle($roundMatches);
			
			// 3. Записываем в БД
			$courtNumber = $courtStartNumber;
			foreach ($roundMatches as $matchData) {
				\App\Models\AmericanoMatch::create([
					'americano_round_id' => $round->id,
					'court_number' => $courtNumber,
					'team1_player1_id' => $matchData['team1_player1_id'],
					'team1_player2_id' => $matchData['team1_player2_id'],
					'team2_player1_id' => $matchData['team2_player1_id'],
					'team2_player2_id' => $matchData['team2_player2_id'],
					'status' => 'pending',
				]);
				
				$courtNumber++;
			}
		}
	}

	/**
	 * Fallback: Round-Robin с балансировкой соперников
	 */
	protected function generateFromRoundRobinBalanced(
		TournamentGroup $group,
		array $players,
		int $numRounds,
		int $courtStartNumber
	): void {
		$n = count($players);
		
		// Генерируем пары Round-Robin
		$fixed = $players[0];
		$rotating = array_slice($players, 1);
		
		$allPairings = [];
		
		for ($round = 0; $round < $n - 1; $round++) {
			$roundPairs = [];
			$roundPairs[] = [$fixed, $rotating[0]];
			
			for ($i = 1; $i <= (($n - 2) / 2); $i++) {
				$roundPairs[] = [$rotating[$i], $rotating[$n - 1 - $i]];
			}
			
			$allPairings[$round + 1] = $roundPairs;
			
			$last = array_pop($rotating);
			array_unshift($rotating, $last);
		}
		
		// История соперников
		$opponentCount = [];
		
		for ($roundNum = 1; $roundNum <= $numRounds; $roundNum++) {
			$pairs = $allPairings[$roundNum];
			$matches = $this->balancePairsToMatches($pairs, $opponentCount);
			if (!empty($matches)) {
				shuffle($matches);
			}
			$round = \App\Models\AmericanoRound::create([
				'tournament_group_id' => $group->id,
				'round_number' => $roundNum,
				'status' => $roundNum === 1 ? 'in_progress' : 'pending',
			]);
			
			$courtNumber = $courtStartNumber;
			
			foreach ($matches as $match) {
				// Обновляем историю соперников
				foreach ($match['team1'] as $p1) {
					foreach ($match['team2'] as $p2) {
						$key = min($p1, $p2) . '-' . max($p1, $p2);
						$opponentCount[$key] = ($opponentCount[$key] ?? 0) + 1;
					}
				}
				
				\App\Models\AmericanoMatch::create([
					'americano_round_id' => $round->id,
					'court_number' => $courtNumber,
					'team1_player1_id' => $match['team1'][0],
					'team1_player2_id' => $match['team1'][1],
					'team2_player1_id' => $match['team2'][0],
					'team2_player2_id' => $match['team2'][1],
					'status' => 'pending',
				]);
				
				$courtNumber++;
			}
		}
	}

	/**
	 * Балансировка пар в матчи (жадный алгоритм)
	 */
	protected function balancePairsToMatches(array $pairs, array $opponentCount): array
	{
		$matches = [];
		$used = [];
		
		while (count($used) < count($pairs) - 1) {
			$firstIdx = null;
			for ($i = 0; $i < count($pairs); $i++) {
				if (!in_array($i, $used)) {
					$firstIdx = $i;
					break;
				}
			}
			if ($firstIdx === null) break;
			
			$used[] = $firstIdx;
			$firstPair = $pairs[$firstIdx];
			
			$bestIdx = null;
			$bestScore = PHP_INT_MAX;
			
			for ($j = 0; $j < count($pairs); $j++) {
				if (in_array($j, $used)) continue;
				
				$score = 0;
				foreach ($firstPair as $p1) {
					foreach ($pairs[$j] as $p2) {
						$key = min($p1, $p2) . '-' . max($p1, $p2);
						$score += ($opponentCount[$key] ?? 0);
					}
				}
				
				if ($score < $bestScore) {
					$bestScore = $score;
					$bestIdx = $j;
				}
			}
			
			if ($bestIdx !== null) {
				$used[] = $bestIdx;
				$matches[] = [
					'team1' => $firstPair,
					'team2' => $pairs[$bestIdx],
				];
			}
		}
		
		return $matches;
	}

	



	





protected function removeFromArray(array &$array, $value): void
{
    $key = array_search($value, $array);
    if ($key !== false) {
        unset($array[$key]);
        $array = array_values($array);
    }
}



protected function updateHistory(array &$history, int $player1, int $player2): void
{
    if (!isset($history[$player1][$player2])) {
        $history[$player1][$player2] = 0;
    }
    if (!isset($history[$player2][$player1])) {
        $history[$player2][$player1] = 0;
    }
    $history[$player1][$player2]++;
    $history[$player2][$player1]++;
}







    /**
     * Сохранить результат матча (только очки, без Эло)
     */
    public function saveMatchResult(AmericanoMatch $match, int $team1Score, int $team2Score): void
    {
        $group = $match->round->group;

        // Идемпотентность: если матч уже сыгран (повторный сабмит, двойной клик,
        // обрыв связи и повторная отправка) — сначала откатываем старые очки,
        // иначе total_points задвоится.
        if ($match->isCompleted()) {
            $this->addPlayerPoints($group, $match->team1_player1_id, -$match->team1_score);
            $this->addPlayerPoints($group, $match->team1_player2_id, -$match->team1_score);
            $this->addPlayerPoints($group, $match->team2_player1_id, -$match->team2_score);
            $this->addPlayerPoints($group, $match->team2_player2_id, -$match->team2_score);
        }

        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'status' => 'completed',
        ]);

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

        // Правка счёта тоже закрывает раунд: недоигранный матч часто добивают
        // именно редактированием (вбивают 0:0). Без этого раунд навсегда оставался
        // in_progress и кнопка «Завершить турнир» не появлялась.
        $this->checkRoundCompletion($match->round);
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

        if (!$allCompleted) {
            return;
        }

        $round->update(['status' => 'completed']);

        // Следующий раунд ЭТОЙ ЖЕ группы открываем автоматически — каждая
        // группа идёт независимо (не ждёт другие группы).
        $nextRound = AmericanoRound::where('tournament_group_id', $round->tournament_group_id)
            ->where('round_number', $round->round_number + 1)
            ->first();

        if (!$nextRound) {
            return;
        }

        // Счёт вносят в том порядке, в каком его приносят с кортов, поэтому
        // следующий раунд мог быть доигран раньше этого. Открывать его
        // заново нельзя: раунд навсегда оставался бы «идёт», и кнопка
        // «Завершить турнир» не появлялась (турнир 1278).
        $nextPlayed = $nextRound->matches()->exists()
            && $nextRound->matches()->where('status', 'pending')->count() === 0;

        if ($nextPlayed) {
            // Закрываем его и идём дальше по цепочке: доиграть «вперёд»
            // могли не один раунд.
            $this->checkRoundCompletion($nextRound);

            return;
        }

        $nextRound->update(['status' => 'in_progress']);
    }

	/**
	 * Проверить можно ли завершить турнир
	 */
	public function canFinishTournament(Tournament $tournament): bool
	{
		// Проверяем все групповые матчи — турнир завершается, когда все раунды
		// всех групп доиграны.
		foreach ($tournament->groups as $group) {
			foreach ($group->rounds as $round) {
				if (!$round->isCompleted()) {
					return false;
				}
			}
		}
		
		// Если есть плей-офф — проверяем что финал верхней сетки сыгран
		if ($tournament->hasPlayoff()) {
			$final = $tournament->playoffMatches()
				->where('stage', 'Финал')
				->where(function ($q) {
					$q->where('bracket', 'upper')->orWhereNull('bracket');
				})
				->first();

			if (!$final || $final->status !== 'completed') {
				return false;
			}

			// Если есть матч за 3-е место верхней сетки — он тоже должен быть сыгран
			if ($tournament->has_bronze_match) {
				$bronze = $tournament->playoffMatches()
					->where('is_bronze', true)
					->where(function ($q) {
						$q->where('bracket', 'upper')->orWhereNull('bracket');
					})
					->first();
				if (!$bronze || $bronze->status !== 'completed') {
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

		// Не рейтинговый турнир — завершаем без начисления рейтинга.
		if (!$tournament->is_rated) {
			$tournament->update(['status' => 'completed']);
			return true;
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
				$delta = $data['current_rating'] - $data['rating_before'];
				$actualBefore = $player->rating;
				$actualAfter = max($this->minRating, $actualBefore + $delta);

				$player->update(['rating' => $actualAfter]);
				$this->updateLevel($player->fresh());
				// Записываем историю
				\App\Models\RatingHistory::create([
					'user_id' => $playerId,
					'tournament_id' => $tournament->id,
					'rating_before' => $actualBefore,
					'rating_after' => $actualAfter,
					'change' => $delta,
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

		// Используем новую систему расчёта
		$result = $this->calculateRatingChange(
			$team1Rating,
			$team2Rating,
			$match->team1_score,
			$match->team2_score
		);

		$change1 = $result['change1'];
		$change2 = $result['change2'];

		// Применяем изменения (минимум 1000)
		$ratingChanges[$p1_1]['current_rating'] = $this->applyRatingChange($ratingChanges[$p1_1]['current_rating'], $change1);
		$ratingChanges[$p1_2]['current_rating'] = $this->applyRatingChange($ratingChanges[$p1_2]['current_rating'], $change1);
		$ratingChanges[$p2_1]['current_rating'] = $this->applyRatingChange($ratingChanges[$p2_1]['current_rating'], $change2);
		$ratingChanges[$p2_2]['current_rating'] = $this->applyRatingChange($ratingChanges[$p2_2]['current_rating'], $change2);
	}




	
	/**
 * Превью расчёта рейтинга (без сохранения) - ДЕТАЛЬНЫЙ
 */
public function previewRatingChanges(Tournament $tournament): array
{
    $preview = [];
    $allRatingChanges = [];
    
    foreach ($tournament->groups as $group) {
        $ratingChanges = [];
        
        foreach ($group->players as $player) {
            $ratingBefore = (int) $player->pivot->rating_before;
            if ($ratingBefore <= 0) {
                $ratingBefore = (int) $player->rating;
            }
            
            $ratingChanges[$player->id] = [
                'name' => $player->full_name,
                'phone' => $player->phone,
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
                
                // Рейтинги игроков
                $r1_1 = $ratingChanges[$p1_1]['current_rating'];
                $r1_2 = $ratingChanges[$p1_2]['current_rating'];
                $r2_1 = $ratingChanges[$p2_1]['current_rating'];
                $r2_2 = $ratingChanges[$p2_2]['current_rating'];
                
                // Средние рейтинги команд
                $team1Rating = ($r1_1 + $r1_2) / 2;
                $team2Rating = ($r2_1 + $r2_2) / 2;
                
                // Расчёт через trait
                $result = $this->calculateRatingChange(
                    $team1Rating,
                    $team2Rating,
                    $match->team1_score,
                    $match->team2_score
                );
                
                $change1 = $result['change1'];
                $change2 = $result['change2'];
                
                // Параметры для детального вывода
                $expected1 = $this->expectedScore($team1Rating, $team2Rating);
                $kFactor = $this->getMatchKFactor($team1Rating, $team2Rating);
                $multiplier = $this->getScoreMultiplier($match->team1_score, $match->team2_score);
                
                // Детальная строка для команды 1
                $matchInfo1 = sprintf(
                    "Р%d: %s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | Счёт %d:%d | K=%d | М=%.2f | Шанс=%.0f%% | %+d",
                    $round->round_number,
                    $ratingChanges[$p1_1]['name'], $r1_1,
                    $ratingChanges[$p1_2]['name'], $r1_2,
                    round($team1Rating),
                    $ratingChanges[$p2_1]['name'], $r2_1,
                    $ratingChanges[$p2_2]['name'], $r2_2,
                    round($team2Rating),
                    $match->team1_score, $match->team2_score,
                    $kFactor,
                    $multiplier,
                    $expected1 * 100,
                    $change1
                );
                
                // Детальная строка для команды 2
                $matchInfo2 = sprintf(
                    "Р%d: %s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | Счёт %d:%d | K=%d | М=%.2f | Шанс=%.0f%% | %+d",
                    $round->round_number,
                    $ratingChanges[$p2_1]['name'], $r2_1,
                    $ratingChanges[$p2_2]['name'], $r2_2,
                    round($team2Rating),
                    $ratingChanges[$p1_1]['name'], $r1_1,
                    $ratingChanges[$p1_2]['name'], $r1_2,
                    round($team1Rating),
                    $match->team2_score, $match->team1_score,
                    $kFactor,
                    $multiplier,
                    (1 - $expected1) * 100,
                    $change2
                );
                
                $ratingChanges[$p1_1]['matches'][] = $matchInfo1;
                $ratingChanges[$p1_2]['matches'][] = $matchInfo1;
                $ratingChanges[$p2_1]['matches'][] = $matchInfo2;
                $ratingChanges[$p2_2]['matches'][] = $matchInfo2;
                
                // Применяем изменения
                $ratingChanges[$p1_1]['current_rating'] = $this->applyRatingChange($r1_1, $change1);
                $ratingChanges[$p1_2]['current_rating'] = $this->applyRatingChange($r1_2, $change1);
                $ratingChanges[$p2_1]['current_rating'] = $this->applyRatingChange($r2_1, $change2);
                $ratingChanges[$p2_2]['current_rating'] = $this->applyRatingChange($r2_2, $change2);
            }
        }
        
        $preview[$group->name] = $ratingChanges;
        
        foreach ($ratingChanges as $playerId => $data) {
            $allRatingChanges[$playerId] = $data;
        }
    }
    
    // Плей-офф
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
                        $playoffChanges[$pId]['matches'] = [];
                    }
                }
                
                $r1_1 = $allRatingChanges[$p1_1]['current_rating'];
                $r1_2 = $allRatingChanges[$p1_2]['current_rating'];
                $r2_1 = $allRatingChanges[$p2_1]['current_rating'];
                $r2_2 = $allRatingChanges[$p2_2]['current_rating'];
                
                $team1Rating = ($r1_1 + $r1_2) / 2;
                $team2Rating = ($r2_1 + $r2_2) / 2;
                
                $result = $this->calculateRatingChange(
                    $team1Rating,
                    $team2Rating,
                    $match->team1_score,
                    $match->team2_score
                );
                
                $change1 = $result['change1'];
                $change2 = $result['change2'];
                
                $expected1 = $this->expectedScore($team1Rating, $team2Rating);
                $kFactor = $this->getMatchKFactor($team1Rating, $team2Rating);
                $multiplier = $this->getScoreMultiplier($match->team1_score, $match->team2_score);
                
                $stageName = $match->stage === 'Полуфинал' ? 'ПФ' : 'Ф';
                
                $matchInfo1 = sprintf(
                    "%s: %s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | Счёт %d:%d | K=%d | М=%.2f | Шанс=%.0f%% | %+d",
                    $stageName,
                    $allRatingChanges[$p1_1]['name'], $r1_1,
                    $allRatingChanges[$p1_2]['name'], $r1_2,
                    round($team1Rating),
                    $allRatingChanges[$p2_1]['name'], $r2_1,
                    $allRatingChanges[$p2_2]['name'], $r2_2,
                    round($team2Rating),
                    $match->team1_score, $match->team2_score,
                    $kFactor,
                    $multiplier,
                    $expected1 * 100,
                    $change1
                );
                
                $matchInfo2 = sprintf(
                    "%s: %s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | Счёт %d:%d | K=%d | М=%.2f | Шанс=%.0f%% | %+d",
                    $stageName,
                    $allRatingChanges[$p2_1]['name'], $r2_1,
                    $allRatingChanges[$p2_2]['name'], $r2_2,
                    round($team2Rating),
                    $allRatingChanges[$p1_1]['name'], $r1_1,
                    $allRatingChanges[$p1_2]['name'], $r1_2,
                    round($team1Rating),
                    $match->team2_score, $match->team1_score,
                    $kFactor,
                    $multiplier,
                    (1 - $expected1) * 100,
                    $change2
                );
                
                $playoffChanges[$p1_1]['matches'][] = $matchInfo1;
                $playoffChanges[$p1_2]['matches'][] = $matchInfo1;
                $playoffChanges[$p2_1]['matches'][] = $matchInfo2;
                $playoffChanges[$p2_2]['matches'][] = $matchInfo2;
                
                $allRatingChanges[$p1_1]['current_rating'] = $this->applyRatingChange($r1_1, $change1);
                $allRatingChanges[$p1_2]['current_rating'] = $this->applyRatingChange($r1_2, $change1);
                $allRatingChanges[$p2_1]['current_rating'] = $this->applyRatingChange($r2_1, $change2);
                $allRatingChanges[$p2_2]['current_rating'] = $this->applyRatingChange($r2_2, $change2);
                
                $playoffChanges[$p1_1]['current_rating'] = $allRatingChanges[$p1_1]['current_rating'];
                $playoffChanges[$p1_2]['current_rating'] = $allRatingChanges[$p1_2]['current_rating'];
                $playoffChanges[$p2_1]['current_rating'] = $allRatingChanges[$p2_1]['current_rating'];
                $playoffChanges[$p2_2]['current_rating'] = $allRatingChanges[$p2_2]['current_rating'];
            }
            
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

		// Три группы и больше обычные форматы не тянут: они разводят пары только
		// по группам A и B, а остальные группы в сетку не попадают. Там строим
		// сетку по общей таблице — она видит всех.
		if ($this->usesTableQfBracket($tournament)) {
			// Откатываться на форматы по группам нельзя: они просто выкинут
			// лишние группы из сетки. Не хватило игроков — не строим ничего.
			return $this->createTableQfBracket($tournament, 'upper');
		}

		$perGroupUpperSize = $this->upperBracketSize($tournament);
		$upperLeaders = $this->collectLeaders($tournament, 0, $perGroupUpperSize);

		if ($tournament->isFinalOnly()) {
			$this->createFinalMatch($tournament, $upperLeaders, 'upper');
		} else {
			$this->createSemifinalMatches($tournament, $upperLeaders, 'upper');
		}

		if ($tournament->has_lower_bracket) {
			$lowerLeaders = $this->collectLeaders($tournament, $perGroupUpperSize, $perGroupUpperSize);
			$enough = collect($lowerLeaders)->every(fn($c) => $c->count() >= $perGroupUpperSize);
			if ($enough) {
				if ($tournament->isFinalOnly()) {
					$this->createFinalMatch($tournament, $lowerLeaders, 'lower');
				} else {
					$this->createSemifinalMatches($tournament, $lowerLeaders, 'lower');
				}
			}
		}

		return true;
	}

	/** Строится ли плей-офф по общей таблице, а не по местам в группах. */
	protected function usesTableQfBracket(Tournament $tournament): bool
	{
		return $tournament->playoff_format === Tournament::PLAYOFF_FORMAT_TABLE_QF
			|| $tournament->groups->count() >= 3;
	}

	/** Сколько игроков из группы идёт в верхнюю сетку (на тир). */
	protected function upperBracketSize(Tournament $tournament): int
	{
		$groups = $tournament->groups->count();
		if ($tournament->isFinalOnly()) {
			return $groups === 1 ? 4 : 2;
		}
		return $groups === 1 ? 8 : 4;
	}

	/**
	 * Лидеры по группам: для каждой группы — Collection из $size игроков,
	 * начиная с позиции $offset (0-based) в отсортированном рейтинге группы.
	 * @return array<string, \Illuminate\Support\Collection>
	 */
	protected function collectLeaders(Tournament $tournament, int $offset, int $size): array
	{
		$leaders = [];
		foreach ($tournament->groups as $group) {
			$ranked = $this->rankGroupPlayers($group);
			$leaders[$group->name] = $ranked->slice($offset, $size)->values();
		}
		return $leaders;
	}

	/**
	 * Игроки группы, отсортированные: очки → победы → разница → личная встреча.
	 * @return \Illuminate\Support\Collection<int, \App\Models\User>
	 */
	protected function rankGroupPlayers(TournamentGroup $group): \Illuminate\Support\Collection
	{
		$playerStats = [];
		foreach ($group->players as $player) {
			$playerStats[$player->id] = [
				'player' => $player,
				'total_points' => $player->pivot->total_points,
				'wins' => 0, 'points_for' => 0, 'points_against' => 0,
			];
		}
		foreach ($group->rounds as $round) {
			foreach ($round->matches as $match) {
				if ($match->status !== 'completed') continue;
				$t1 = [$match->team1_player1_id, $match->team1_player2_id];
				$t2 = [$match->team2_player1_id, $match->team2_player2_id];
				foreach ($t1 as $pId) {
					if (isset($playerStats[$pId])) {
						$playerStats[$pId]['points_for'] += $match->team1_score;
						$playerStats[$pId]['points_against'] += $match->team2_score;
						if ($match->team1_score > $match->team2_score) $playerStats[$pId]['wins']++;
					}
				}
				foreach ($t2 as $pId) {
					if (isset($playerStats[$pId])) {
						$playerStats[$pId]['points_for'] += $match->team2_score;
						$playerStats[$pId]['points_against'] += $match->team1_score;
						if ($match->team2_score > $match->team1_score) $playerStats[$pId]['wins']++;
					}
				}
			}
		}
		$h2h = \App\Support\AmericanoTie::fromGroups([$group]);
		uasort($playerStats, function ($a, $b) use ($h2h) {
			if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
			if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
			$diffA = $a['points_for'] - $a['points_against'];
			$diffB = $b['points_for'] - $b['points_against'];
			if ($diffA !== $diffB) return $diffB <=> $diffA;
			$tie = \App\Support\AmericanoTie::compare($h2h, $a['player']->id, $b['player']->id);
			if ($tie !== 0) return $tie;

			// Полное равенство бывает между группами: личной встречи там нет, а
			// очки, победы и разница совпали — значит совпали и пропущенные.
			// Разводим рейтингом, других данных не осталось.
			return (int) $b['player']->rating <=> (int) $a['player']->rating;
		});
		return collect(array_values($playerStats))->map(fn($s) => $s['player']);
	}

	/**
	 * Создать только финальный матч
	 */
	protected function createFinalMatch(Tournament $tournament, array $leaders, string $bracket = 'upper'): void
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
					'bracket' => $bracket,
					'match_number' => 1,
					'team1_player1_id' => $A[0]->id,
					'team1_player2_id' => $A[1]->id,
					'team2_player1_id' => $B[0]->id,
					'team2_player2_id' => $B[1]->id,
					'status' => 'pending',
				]);
			}
		} elseif (count($groupNames) === 1) {
			// Одна группа — формат зависит от настроек
			$players = $leaders[$groupNames[0]];

			if ($players->count() >= 4) {
				$format = $tournament->playoff_format ?? 'cross';

				switch ($format) {
					case 'tops':
						// 1+2 vs 3+4
						$team1 = [$players[0]->id, $players[1]->id];
						$team2 = [$players[2]->id, $players[3]->id];
						break;

					case 'mix':
						// 1+3 vs 2+4
						$team1 = [$players[0]->id, $players[2]->id];
						$team2 = [$players[1]->id, $players[3]->id];
						break;

					case 'cross':
					default:
						// 1+4 vs 2+3
						$team1 = [$players[0]->id, $players[3]->id];
						$team2 = [$players[1]->id, $players[2]->id];
						break;
				}

				\App\Models\TournamentPlayoffMatch::create([
					'tournament_id' => $tournament->id,
					'stage' => 'Финал',
					'bracket' => $bracket,
					'match_number' => 1,
					'team1_player1_id' => $team1[0],
					'team1_player2_id' => $team1[1],
					'team2_player1_id' => $team2[0],
					'team2_player2_id' => $team2[1],
					'status' => 'pending',
				]);
			}
		}
	}

	/**
	 * Создать полуфиналы и финал
	 */
	protected function createSemifinalMatches(Tournament $tournament, array $leaders, string $bracket = 'upper'): void
	{
		$groupNames = array_keys($leaders);

		// === 1 группа: топ-8 → 2 полуфинала (форматы mix/tops/balanced) ===
		if (count($groupNames) === 1) {
			$p = $leaders[$groupNames[0]]->values();
			if ($p->count() < 8) {
				return; // недостаточно игроков на полуфинал
			}
			// Формат пар для топ-8 (отличается от топ-4 в createFinalMatch):
			// здесь распределяем 8 игроков на 2 полуфинала.
			$format = $tournament->playoff_format ?? 'mix';
			switch ($format) {
				case 'tops': // 1+2 vs 7+8 | 3+4 vs 5+6
					$semi1 = ['team1' => [$p[0]->id, $p[1]->id], 'team2' => [$p[6]->id, $p[7]->id]];
					$semi2 = ['team1' => [$p[2]->id, $p[3]->id], 'team2' => [$p[4]->id, $p[5]->id]];
					break;
				case 'balanced': // 1+4 vs 5+8 | 2+3 vs 6+7
					$semi1 = ['team1' => [$p[0]->id, $p[3]->id], 'team2' => [$p[4]->id, $p[7]->id]];
					$semi2 = ['team1' => [$p[1]->id, $p[2]->id], 'team2' => [$p[5]->id, $p[6]->id]];
					break;
				case 'mix':
				default: // 1+8 vs 4+5 | 2+7 vs 3+6
					$semi1 = ['team1' => [$p[0]->id, $p[7]->id], 'team2' => [$p[3]->id, $p[4]->id]];
					$semi2 = ['team1' => [$p[1]->id, $p[6]->id], 'team2' => [$p[2]->id, $p[5]->id]];
					break;
			}
			$this->persistSemifinalSet($tournament, $semi1, $semi2, $bracket);
			return;
		}

		// 0 групп (не должно случаться) — нечего генерировать
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

			case 'top_bottom':
				// Верх/низ: сильные одной группы против слабых другой.
				// Полуфинал 1: A1+B3 vs A2+B4, полуфинал 2: A3+B1 vs A4+B2.
				$semi1 = [
					'team1' => [$A[0]->id, $B[2]->id],
					'team2' => [$A[1]->id, $B[3]->id],
				];
				$semi2 = [
					'team1' => [$A[2]->id, $B[0]->id],
					'team2' => [$A[3]->id, $B[1]->id],
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

		$this->persistSemifinalSet($tournament, $semi1, $semi2, $bracket);
	}

	/**
	 * Записать пару полуфиналов + пустой финал + опц. матч за 3-е место для заданной сетки.
	 */
	protected function persistSemifinalSet(Tournament $tournament, array $semi1, array $semi2, string $bracket): void
	{
		\App\Models\TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'stage' => 'Полуфинал',
			'bracket' => $bracket,
			'match_number' => 1,
			'team1_player1_id' => $semi1['team1'][0],
			'team1_player2_id' => $semi1['team1'][1],
			'team2_player1_id' => $semi1['team2'][0],
			'team2_player2_id' => $semi1['team2'][1],
			'status' => 'pending',
		]);
		\App\Models\TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'stage' => 'Полуфинал',
			'bracket' => $bracket,
			'match_number' => 2,
			'team1_player1_id' => $semi2['team1'][0],
			'team1_player2_id' => $semi2['team1'][1],
			'team2_player1_id' => $semi2['team2'][0],
			'team2_player2_id' => $semi2['team2'][1],
			'status' => 'pending',
		]);
		\App\Models\TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'stage' => 'Финал',
			'bracket' => $bracket,
			'match_number' => 1,
			'status' => 'pending',
		]);
		if ($tournament->has_bronze_match) {
			\App\Models\TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'stage' => 'Матч за 3-е место',
				'bracket' => $bracket,
				'match_number' => 1,
				'is_bronze' => true,
				'status' => 'pending',
			]);
		}
	}
	/**
	 * Общая таблица турнира: все игроки всех групп в одном ряду.
	 *
	 * Критерии те же, что внутри группы — очки → победы → разница → личная встреча.
	 * Нужна форматам, где сетка строится по общему месту, а не по местам в группах.
	 *
	 * @return \Illuminate\Support\Collection<int, \App\Models\User>
	 */
	protected function rankAllPlayers(Tournament $tournament): \Illuminate\Support\Collection
	{
		$playerStats = [];
		foreach ($tournament->groups as $group) {
			foreach ($group->players as $player) {
				$playerStats[$player->id] = [
					'player' => $player,
					'total_points' => $player->pivot->total_points,
					'wins' => 0, 'points_for' => 0, 'points_against' => 0,
				];
			}
		}

		foreach ($tournament->groups as $group) {
			foreach ($group->rounds as $round) {
				foreach ($round->matches as $match) {
					if ($match->status !== 'completed') continue;
					$sides = [
						[[$match->team1_player1_id, $match->team1_player2_id], $match->team1_score, $match->team2_score],
						[[$match->team2_player1_id, $match->team2_player2_id], $match->team2_score, $match->team1_score],
					];
					foreach ($sides as [$ids, $scored, $conceded]) {
						foreach ($ids as $pId) {
							if (!isset($playerStats[$pId])) continue;
							$playerStats[$pId]['points_for'] += $scored;
							$playerStats[$pId]['points_against'] += $conceded;
							if ($scored > $conceded) $playerStats[$pId]['wins']++;
						}
					}
				}
			}
		}

		$h2h = \App\Support\AmericanoTie::fromGroups($tournament->groups->all());
		uasort($playerStats, function ($a, $b) use ($h2h) {
			if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
			if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
			$diffA = $a['points_for'] - $a['points_against'];
			$diffB = $b['points_for'] - $b['points_against'];
			if ($diffA !== $diffB) return $diffB <=> $diffA;
			$tie = \App\Support\AmericanoTie::compare($h2h, $a['player']->id, $b['player']->id);
			if ($tie !== 0) return $tie;

			// Полное равенство бывает между группами: личной встречи там нет, а
			// очки, победы и разница совпали — значит совпали и пропущенные.
			// Разводим рейтингом, других данных не осталось.
			return (int) $b['player']->rating <=> (int) $a['player']->rating;
		});

		return collect(array_values($playerStats))->map(fn($s) => $s['player']);
	}

	/**
	 * Сетка «общая таблица»: топ-4 ждут в полуфинале, места 5–12 играют четвертьфинал.
	 *
	 * Нужна, когда групп три и больше: обычные форматы разводят пары по группам A и B,
	 * а третья группа в сетку не попадает вовсе. Здесь все группы складываются в один ряд.
	 *
	 *   Ждут в полуфинале:  А = 1+4,  Б = 2+3
	 *   Четвертьфинал:      ЧФ 1 = (5+6) vs (11+12),  ЧФ 2 = (7+8) vs (9+10)
	 *   Полуфинал:          ПФ 1 = А vs победитель ЧФ 2,  ПФ 2 = Б vs победитель ЧФ 1
	 *
	 * В четвертьфинале пары собираются по соседям, а не змейкой. Змейка делала
	 * все четыре пары одинаковыми по силе, и место в общей таблице переставало
	 * что-либо значить: у пятого и у двенадцатого шансы совпадали до сотых.
	 * По соседям пятое место действительно сильнее двенадцатого.
	 *
	 * Сильнейшая пара четвертьфинала играет со слабейшей, а её победитель
	 * выходит на вторую пару таблицы, а не на первую.
	 *
	 * @return bool удалось ли построить сетку (нужно минимум 12 игроков)
	 */
	protected function createTableQfBracket(Tournament $tournament, string $bracket = 'upper'): bool
	{
		$ranked = $this->rankAllPlayers($tournament)->values();
		if ($ranked->count() < 12) {
			return false;
		}

		$id = fn(int $place) => $ranked[$place - 1]->id; // место в таблице, с единицы

		$quarters = [
			1 => [[$id(5), $id(6)], [$id(11), $id(12)]],
			2 => [[$id(7), $id(8)], [$id(9), $id(10)]],
		];
		foreach ($quarters as $number => [$team1, $team2]) {
			\App\Models\TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'stage' => 'Четвертьфинал',
				'bracket' => $bracket,
				'match_number' => $number,
				'team1_player1_id' => $team1[0],
				'team1_player2_id' => $team1[1],
				'team2_player1_id' => $team2[0],
				'team2_player2_id' => $team2[1],
				'status' => 'pending',
			]);
		}

		$semifinals = [
			1 => [[$id(1), $id(4)], 'Победитель ЧФ 2'],
			2 => [[$id(2), $id(3)], 'Победитель ЧФ 1'],
		];
		foreach ($semifinals as $number => [$team1, $source]) {
			\App\Models\TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'stage' => 'Полуфинал',
				'bracket' => $bracket,
				'match_number' => $number,
				'team1_player1_id' => $team1[0],
				'team1_player2_id' => $team1[1],
				'team2_source' => $source,
				'status' => 'pending',
			]);
		}

		\App\Models\TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'stage' => 'Финал',
			'bracket' => $bracket,
			'match_number' => 1,
			'status' => 'pending',
		]);

		if ($tournament->has_bronze_match) {
			\App\Models\TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'stage' => 'Матч за 3-е место',
				'bracket' => $bracket,
				'match_number' => 1,
				'is_bronze' => true,
				'status' => 'pending',
			]);
		}

		return true;
	}

	/**
	 * Провести матч плей-офф дальше по сетке: четвертьфинал наполняет полуфинал,
	 * полуфинал — финал. Один вход для всех мест, где сохраняется счёт.
	 */
	public function advancePlayoff(TournamentPlayoffMatch $match): void
	{
		if ($match->stage === 'Четвертьфинал') {
			$this->updateSemifinalAfterQuarterfinal($match);
			return;
		}
		if ($match->stage === 'Полуфинал') {
			$this->updateFinalAfterSemifinal($match);
		}
	}

	/**
	 * Подставить победителя четвертьфинала в его полуфинал.
	 * ЧФ 1 отдаёт победителя в ПФ 2, ЧФ 2 — в ПФ 1: пары разведены по разным половинам.
	 */
	public function updateSemifinalAfterQuarterfinal(TournamentPlayoffMatch $quarterMatch): void
	{
		if ($quarterMatch->stage !== 'Четвертьфинал' || $quarterMatch->status !== 'completed') {
			return;
		}
		if ($quarterMatch->team1_score === $quarterMatch->team2_score) {
			return; // ничья — победителя нет, ждём исправления счёта
		}

		$winner = $quarterMatch->team1_score > $quarterMatch->team2_score
			? [$quarterMatch->team1_player1_id, $quarterMatch->team1_player2_id]
			: [$quarterMatch->team2_player1_id, $quarterMatch->team2_player2_id];

		$semifinalNumber = ((int) $quarterMatch->match_number) === 1 ? 2 : 1;

		$semifinal = $quarterMatch->tournament->playoffMatches()
			->where('stage', 'Полуфинал')
			->where('bracket', $quarterMatch->bracket ?: 'upper')
			->where('match_number', $semifinalNumber)
			->first();

		if (!$semifinal) {
			return;
		}

		$semifinal->update([
			'team2_player1_id' => $winner[0],
			'team2_player2_id' => $winner[1],
		]);
	}

	/**
	 * Обновить финал после полуфинала
	 */
	public function updateFinalAfterSemifinal(TournamentPlayoffMatch $semifinalMatch): void
	{
		$tournament = $semifinalMatch->tournament;
		$bracket = $semifinalMatch->bracket ?? 'upper';

		// Проверяем что оба полуфинала сыграны (только в своей сетке)
		$semifinals = $tournament->playoffMatches()
			->where('stage', 'Полуфинал')
			->where('bracket', $bracket)
			->get();

		$allCompleted = $semifinals->every(fn($m) => $m->status === 'completed');

		if (!$allCompleted) {
			return;
		}

		// Получаем победителей и проигравших полуфиналов
		$winners = [];
		$losers = [];
		foreach ($semifinals as $semi) {
			if ($semi->team1_score > $semi->team2_score) {
				$winners[] = [
					'player1_id' => $semi->team1_player1_id,
					'player2_id' => $semi->team1_player2_id,
				];
				$losers[] = [
					'player1_id' => $semi->team2_player1_id,
					'player2_id' => $semi->team2_player2_id,
				];
			} else {
				$winners[] = [
					'player1_id' => $semi->team2_player1_id,
					'player2_id' => $semi->team2_player2_id,
				];
				$losers[] = [
					'player1_id' => $semi->team1_player1_id,
					'player2_id' => $semi->team1_player2_id,
				];
			}
		}

		// Обновляем финал той же сетки
		$final = $tournament->playoffMatches()
			->where('stage', 'Финал')
			->where('bracket', $bracket)
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

		// Обновляем матч за 3-е место той же сетки (если включён) — заполняем проигравшими
		if ($tournament->has_bronze_match && count($losers) >= 2) {
			$bronze = $tournament->playoffMatches()
				->where('is_bronze', true)
				->where('bracket', $bracket)
				->first();
			if ($bronze) {
				$bronze->update([
					'team1_player1_id' => $losers[0]['player1_id'],
					'team1_player2_id' => $losers[0]['player2_id'],
					'team2_player1_id' => $losers[1]['player1_id'],
					'team2_player2_id' => $losers[1]['player2_id'],
					'status' => 'pending',
				]);
			}
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

		// Используем новую систему расчёта
		$result = $this->calculateRatingChange(
			$team1Rating,
			$team2Rating,
			$match->team1_score,
			$match->team2_score
		);

		$change1 = $result['change1'];
		$change2 = $result['change2'];

		// Применяем изменения (минимум 1000)
		$ratingChanges[$player1Id]['current_rating'] = $this->applyRatingChange($ratingChanges[$player1Id]['current_rating'], $change1);
		$ratingChanges[$player2Id]['current_rating'] = $this->applyRatingChange($ratingChanges[$player2Id]['current_rating'], $change1);
		$ratingChanges[$player3Id]['current_rating'] = $this->applyRatingChange($ratingChanges[$player3Id]['current_rating'], $change2);
		$ratingChanges[$player4Id]['current_rating'] = $this->applyRatingChange($ratingChanges[$player4Id]['current_rating'], $change2);
	}
}
<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\TournamentTeamGroup;
use App\Models\TournamentTeamStanding;
use App\Models\TournamentGroupMatch;
use App\Models\TournamentPlayoffMatch;

class TeamTournamentService
{
	use \App\Traits\RatingCalculator;
    /**
     * Запустить турнир
     */
    public function startTournament(Tournament $tournament): bool
    {
        $teams = $tournament->teams()->orderBy('rating_avg', 'desc')->get();
        $maxTeams = $tournament->max_participants / 2;

        if ($teams->count() !== $maxTeams) {
            return false;
        }

        if ($tournament->teamGroups()->count() > 0) {
            return false;
        }

        // Сохраняем рейтинги
        foreach ($teams as $team) {
            $team->update([
                'rating_before' => $team->rating_avg,
            ]);
        }

        // Создаём группы и распределяем команды
        $this->createGroupsAndDistributeTeams($tournament, $teams);

        // Генерируем матчи группового этапа
        $this->generateGroupMatches($tournament);

        $tournament->update(['status' => 'in_progress']);

        return true;
    }

    /**
     * Создать группы и распределить команды (змейкой по рейтингу)
     */
    protected function createGroupsAndDistributeTeams(Tournament $tournament, $teams): void
    {
        $groupsCount = $tournament->groups_count;
        $groups = [];

        // Создаём группы
        for ($i = 0; $i < $groupsCount; $i++) {
            $groups[] = TournamentTeamGroup::create([
                'tournament_id' => $tournament->id,
                'name' => 'Группа ' . chr(65 + $i), // A, B, C, D...
            ]);
        }

        // Распределяем команды змейкой
        // 1 → A, 2 → B, 3 → B, 4 → A, 5 → A, 6 → B...
        $direction = 1;
        $groupIndex = 0;

        foreach ($teams as $index => $team) {
            // Присваиваем сеяный номер
            $team->update(['seed' => $index + 1]);

            // Создаём запись в standings
            TournamentTeamStanding::create([
                'group_id' => $groups[$groupIndex]->id,
                'team_id' => $team->id,
            ]);

            // Двигаемся змейкой
            $groupIndex += $direction;
            if ($groupIndex >= $groupsCount) {
                $groupIndex = $groupsCount - 1;
                $direction = -1;
            } elseif ($groupIndex < 0) {
                $groupIndex = 0;
                $direction = 1;
            }
        }
    }

	/**
	 * Генерация матчей группового этапа (круговая система)
	 */
	protected function generateGroupMatches(Tournament $tournament): void
	{
		$groups = $tournament->teamGroups()->orderBy('name')->get();
		$courtOffset = 0;
		
		foreach ($groups as $group) {
			$teams = $group->standings()->with('team')->get()->pluck('team');
			$teamIds = $teams->pluck('id')->toArray();
			$numTeams = count($teamIds);

			// Если нечётное — добавляем "пустую" команду
			if ($numTeams % 2 !== 0) {
				$teamIds[] = null;
				$numTeams++;
			}

			$rounds = $numTeams - 1;
			$matchesPerRound = $numTeams / 2;

			for ($round = 0; $round < $rounds; $round++) {
				$courtNumber = $courtOffset + 1; // Начинаем с offset
				
				for ($match = 0; $match < $matchesPerRound; $match++) {
					$home = $teamIds[$match];
					$away = $teamIds[$numTeams - 1 - $match];

					// Пропускаем если одна из команд "пустая"
					if ($home === null || $away === null) {
						continue;
					}

					TournamentGroupMatch::create([
						'group_id' => $group->id,
						'court_number' => $courtNumber,
						'team1_id' => $home,
						'team2_id' => $away,
						'round_number' => $round + 1,
						'status' => $round === 0 ? 'in_progress' : 'pending',
					]);
					
					$courtNumber++;
				}

				// Ротация (первый фиксирован)
				$last = array_pop($teamIds);
				array_splice($teamIds, 1, 0, [$last]);
			}
			
			// Смещаем offset для следующей группы
			// Количество кортов = количество матчей в раунде этой группы
			$courtsInGroup = floor($numTeams / 2);
			$courtOffset += $courtsInGroup;
		}
	}

    /**
     * Сохранить результат матча группового этапа
     */
    public function saveGroupMatchResult(TournamentGroupMatch $match, int $team1Score, int $team2Score): void
    {
        $oldStatus = $match->status;
        $wasCompleted = $match->isCompleted();

        // Откатываем старые результаты если редактируем
        if ($wasCompleted) {
            $this->rollbackMatchResult($match);
        }

        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'status' => 'completed',
        ]);

        // Обновляем standings
        $this->updateStandings($match);

        // Проверяем завершение раунда
        $this->checkRoundCompletion($match);
    }

    /**
     * Откатить результат матча
     */
    protected function rollbackMatchResult(TournamentGroupMatch $match): void
    {
        $group = $match->group;

        $standing1 = TournamentTeamStanding::where('group_id', $group->id)
            ->where('team_id', $match->team1_id)->first();
        $standing2 = TournamentTeamStanding::where('group_id', $group->id)
            ->where('team_id', $match->team2_id)->first();

        if ($standing1 && $standing2) {
            // Убираем очки
            $standing1->decrement('played');
            $standing2->decrement('played');
            $standing1->decrement('points_for', $match->team1_score);
            $standing1->decrement('points_against', $match->team2_score);
            $standing2->decrement('points_for', $match->team2_score);
            $standing2->decrement('points_against', $match->team1_score);

            if ($match->team1_score > $match->team2_score) {
				$standing1->decrement('won');
				$standing1->decrement('points', 1);
				$standing2->decrement('lost');
			} else {
				$standing2->decrement('won');
				$standing2->decrement('points', 1);
				$standing1->decrement('lost');
			}
        }
    }

    /**
     * Обновить standings после матча
     */
    protected function updateStandings(TournamentGroupMatch $match): void
    {
        $group = $match->group;

        $standing1 = TournamentTeamStanding::where('group_id', $group->id)
            ->where('team_id', $match->team1_id)->first();
        $standing2 = TournamentTeamStanding::where('group_id', $group->id)
            ->where('team_id', $match->team2_id)->first();

        if ($standing1 && $standing2) {
            $standing1->increment('played');
            $standing2->increment('played');
            $standing1->increment('points_for', $match->team1_score);
            $standing1->increment('points_against', $match->team2_score);
            $standing2->increment('points_for', $match->team2_score);
            $standing2->increment('points_against', $match->team1_score);

            if ($match->team1_score > $match->team2_score) {
				$standing1->increment('won');
				$standing1->increment('points', 1);
				$standing2->increment('lost');
			} else {
				$standing2->increment('won');
				$standing2->increment('points', 1);
				$standing1->increment('lost');
			}
        }
    }

    /**
     * Проверить завершение раунда
     */
    protected function checkRoundCompletion(TournamentGroupMatch $match): void
    {
        $group = $match->group;
        $roundNumber = $match->round_number;

        $pendingInRound = $group->matches()
            ->where('round_number', $roundNumber)
            ->where('status', '!=', 'completed')
            ->count();

        if ($pendingInRound === 0) {
            // Активируем следующий раунд
            $group->matches()
                ->where('round_number', $roundNumber + 1)
                ->where('status', 'pending')
                ->update(['status' => 'in_progress']);
        }
    }

    /**
     * Проверить завершён ли групповой этап
     */
    public function isGroupStageCompleted(Tournament $tournament): bool
    {
        foreach ($tournament->teamGroups as $group) {
            if (!$group->isCompleted()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Генерация плей-офф
     */
   public function generatePlayoff(Tournament $tournament): bool
	{
		if (!$this->isGroupStageCompleted($tournament)) {
			return false;
		}
		
		if ($tournament->playoffMatches()->count() > 0) {
			return false;
		}
		
		$teamsAdvance = $tournament->teams_advance;
		$groupsCount = $tournament->groups_count;
		$totalPlayoffTeams = $teamsAdvance * $groupsCount;
		
		// Определяем этап плей-офф
		$stage = match($totalPlayoffTeams) {
			2 => 'final',
			4 => 'semi',
			8 => 'quarter',
			default => 'quarter',
		};
		
		// Собираем команды из групп с правильной сортировкой
		$playoffTeams = [];
		foreach ($tournament->teamGroups as $group) {
			$sortedStandings = $this->getSortedStandings($group);
			$topTeams = array_slice($sortedStandings, 0, $teamsAdvance);
			
			foreach ($topTeams as $index => $standing) {
				$team = TournamentTeam::find($standing['team_id']);
				
				$playoffTeams[] = [
					'team' => $team,
					'group' => $group->name,
					'position' => $index + 1,
					'source' => substr($group->name, -1) . ($index + 1), // A1, A2, B1, B2...
				];
			}
		}
		
		// Создаём матчи плей-офф
		$this->createPlayoffMatches($tournament, $playoffTeams, $stage);
		
		return true;
	}

    /**
	 * Создание матчей плей-офф
	 */
	protected function createPlayoffMatches(Tournament $tournament, array $playoffTeams, string $stage): void
	{
		if ($stage === 'final') {
			// Финал: A1 vs B1
			TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 1,
				'stage' => 'final',
				'match_number' => 1,
				'team1_id' => $playoffTeams[0]['team']->id,
				'team2_id' => $playoffTeams[1]['team']->id,
				'team1_source' => $playoffTeams[0]['source'],
				'team2_source' => $playoffTeams[1]['source'],
				'status' => 'in_progress',
			]);
		} elseif ($stage === 'semi') {
			// Полуфиналы: A1 vs B2, B1 vs A2
			TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 1,
				'stage' => 'semi',
				'match_number' => 1,
				'team1_id' => $playoffTeams[0]['team']->id, // A1
				'team2_id' => $playoffTeams[3]['team']->id, // B2
				'team1_source' => $playoffTeams[0]['source'],
				'team2_source' => $playoffTeams[3]['source'],
				'status' => 'in_progress',
			]);
			TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 2,
				'stage' => 'semi',
				'match_number' => 2,
				'team1_id' => $playoffTeams[2]['team']->id, // B1
				'team2_id' => $playoffTeams[1]['team']->id, // A2
				'team1_source' => $playoffTeams[2]['source'],
				'team2_source' => $playoffTeams[1]['source'],
				'status' => 'in_progress',
			]);

			// Финал (пустой, команды определятся позже)
			TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 1,
				'stage' => 'final',
				'match_number' => 1,
				'team1_source' => 'W1',
				'team2_source' => 'W2',
				'status' => 'pending',
			]);
		} elseif ($stage === 'quarter') {
			// 1/4 финала для 8 команд
			    $matchups = [
					[0, 7], // Матч 1: A1 vs B4
					[5, 2], // Матч 2: B2 vs A3
					[4, 3], // Матч 3: B1 vs A4
					[1, 6], // Матч 4: A2 vs B3
				];

			foreach ($matchups as $i => $pair) {
				TournamentPlayoffMatch::create([
					'tournament_id' => $tournament->id,
					'court_number' => $i + 1,
					'stage' => 'quarter',
					'match_number' => $i + 1,
					'team1_id' => $playoffTeams[$pair[0]]['team']->id,
					'team2_id' => $playoffTeams[$pair[1]]['team']->id,
					'team1_source' => $playoffTeams[$pair[0]]['source'],
					'team2_source' => $playoffTeams[$pair[1]]['source'],
					'status' => 'in_progress',
				]);
			}

			// Полуфиналы (пустые)
			TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 1,
				'stage' => 'semi',
				'match_number' => 1,
				'team1_source' => 'W1',
				'team2_source' => 'W2',
				'status' => 'pending',
			]);
			TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 2,
				'stage' => 'semi',
				'match_number' => 2,
				'team1_source' => 'W3',
				'team2_source' => 'W4',
				'status' => 'pending',
			]);

			// Финал
			TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 1,
				'stage' => 'final',
				'match_number' => 1,
				'team1_source' => 'W5',
				'team2_source' => 'W6',
				'status' => 'pending',
			]);
		}
	}

    /**
     * Сохранить результат матча плей-офф
     */
    public function savePlayoffMatchResult(TournamentPlayoffMatch $match, int $team1Score, int $team2Score): void
    {
        $winnerId = $team1Score > $team2Score ? $match->team1_id : $match->team2_id;

        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'winner_id' => $winnerId,
            'status' => 'completed',
        ]);

        // Продвигаем победителя в следующий матч
        $this->advanceWinner($match);
    }

    /**
     * Продвинуть победителя в следующий матч
     */
    protected function advanceWinner(TournamentPlayoffMatch $match): void
    {
        $tournament = $match->tournament;
        $winnerId = $match->winner_id;

        if ($match->stage === 'quarter') {
            // Из 1/4 в полуфинал
            $semiMatch = $tournament->playoffMatches()
                ->where('stage', 'semi')
                ->where(function($q) use ($match) {
                    $q->where('team1_source', 'W' . $match->match_number)
                      ->orWhere('team2_source', 'W' . $match->match_number);
                })
                ->first();

            if ($semiMatch) {
                if ($semiMatch->team1_source === 'W' . $match->match_number) {
                    $semiMatch->update(['team1_id' => $winnerId]);
                } else {
                    $semiMatch->update(['team2_id' => $winnerId]);
                }

                // Активируем если обе команды определены
                if ($semiMatch->team1_id && $semiMatch->team2_id) {
                    $semiMatch->update(['status' => 'in_progress']);
                }
            }
        } elseif ($match->stage === 'semi') {
            // Из полуфинала в финал
            $finalMatch = $tournament->playoffMatches()
                ->where('stage', 'final')
                ->first();

            if ($finalMatch) {
                $sourceKey = 'W' . (4 + $match->match_number); // W5 или W6 для 8 команд
                if ($tournament->teams_advance * $tournament->groups_count === 4) {
                    $sourceKey = 'W' . $match->match_number; // W1 или W2 для 4 команд
                }

                if ($finalMatch->team1_source === $sourceKey || $finalMatch->team1_source === 'W' . $match->match_number) {
                    $finalMatch->update(['team1_id' => $winnerId]);
                } else {
                    $finalMatch->update(['team2_id' => $winnerId]);
                }

                if ($finalMatch->team1_id && $finalMatch->team2_id) {
                    $finalMatch->update(['status' => 'in_progress']);
                }
            }
        }
    }

    /**
     * Проверить можно ли завершить турнир
     */
    public function canFinishTournament(Tournament $tournament): bool
    {
        $finalMatch = $tournament->playoffMatches()->where('stage', 'final')->first();
        return $finalMatch && $finalMatch->isCompleted();
    }

    /**
	 * Завершить турнир
	 */
	public function finishTournament(Tournament $tournament): bool
	{
		if (!$this->canFinishTournament($tournament)) {
			return false;
		}

		// Рассчитываем Эло
		$ratingChanges = $this->previewRatingChanges($tournament);

		// Применяем рейтинги
		foreach ($tournament->teams as $team) {
			$player1NewRating = (int) $ratingChanges[$team->player1_id]['current_rating'];
			$player2NewRating = (int) $ratingChanges[$team->player2_id]['current_rating'];

			$team->update([
				'rating_after' => intval(($player1NewRating + $player2NewRating) / 2),
			]);

			$team->player1->update(['rating' => $player1NewRating]);
			$team->player2->update(['rating' => $player2NewRating]);
			$this->updateLevel($team->player1->fresh());
			$this->updateLevel($team->player2->fresh());
			// Записываем историю
			\App\Models\RatingHistory::create([
				'user_id' => $team->player1_id,
				'tournament_id' => $tournament->id,
				'rating_before' => $ratingChanges[$team->player1_id]['rating_before'],
				'rating_after' => $player1NewRating,
				'change' => $player1NewRating - $ratingChanges[$team->player1_id]['rating_before'],
				'reason' => $tournament->name,
			]);

			\App\Models\RatingHistory::create([
				'user_id' => $team->player2_id,
				'tournament_id' => $tournament->id,
				'rating_before' => $ratingChanges[$team->player2_id]['rating_before'],
				'rating_after' => $player2NewRating,
				'change' => $player2NewRating - $ratingChanges[$team->player2_id]['rating_before'],
				'reason' => $tournament->name,
			]);

		}

		$tournament->update(['status' => 'completed']);

		return true;
	}

	
	
	/**
	 * Превью изменений рейтинга
	 */
	public function previewRatingChanges(Tournament $tournament): array
	{
		$teams = $tournament->teams()->with(['player1', 'player2'])->get();
		$ratingChanges = [];

		// Инициализируем
		foreach ($teams as $team) {
			$ratingChanges[$team->player1_id] = [
				'name' => $team->player1->full_name,
				'rating_before' => $team->player1->rating,
				'current_rating' => $team->player1->rating,
				'matches' => [],
			];
			$ratingChanges[$team->player2_id] = [
				'name' => $team->player2->full_name,
				'rating_before' => $team->player2->rating,
				'current_rating' => $team->player2->rating,
				'matches' => [],
			];
		}

		// Групповой этап
		foreach ($tournament->teamGroups as $group) {
			foreach ($group->matches()->where('status', 'completed')->get() as $match) {
				$this->calculateMatchElo($match, $ratingChanges, $group->name);
			}
		}

		// Плей-офф
		foreach ($tournament->playoffMatches()->where('status', 'completed')->orderBy('id')->get() as $match) {
			$this->calculateMatchElo($match, $ratingChanges, $match->stage_name);
		}

		return $ratingChanges;
	}

	/**
	 * Рассчитать Эло для матча
	 */
	protected function calculateMatchElo($match, array &$ratingChanges, string $stageName): void
	{
		$team1 = $match->team1;
		$team2 = $match->team2;

		if (!$team1 || !$team2) return;

		$p1_1 = $team1->player1_id;
		$p1_2 = $team1->player2_id;
		$p2_1 = $team2->player1_id;
		$p2_2 = $team2->player2_id;

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

		// Логируем историю матчей
		$matchInfo = "{$stageName}: {$match->team1_score}:{$match->team2_score}";
		$ratingChanges[$p1_1]['matches'][] = "{$matchInfo} → {$change1}";
		$ratingChanges[$p1_2]['matches'][] = "{$matchInfo} → {$change1}";
		$ratingChanges[$p2_1]['matches'][] = "{$matchInfo} → {$change2}";
		$ratingChanges[$p2_2]['matches'][] = "{$matchInfo} → {$change2}";

		// Применяем изменения (минимум 1000)
		$ratingChanges[$p1_1]['current_rating'] = $this->applyRatingChange($ratingChanges[$p1_1]['current_rating'], $change1);
		$ratingChanges[$p1_2]['current_rating'] = $this->applyRatingChange($ratingChanges[$p1_2]['current_rating'], $change1);
		$ratingChanges[$p2_1]['current_rating'] = $this->applyRatingChange($ratingChanges[$p2_1]['current_rating'], $change2);
		$ratingChanges[$p2_2]['current_rating'] = $this->applyRatingChange($ratingChanges[$p2_2]['current_rating'], $change2);
	}

	/**
	 * Превью рейтинга сгруппированный по этапам (только для страницы preview)
	 */
	public function previewRatingChangesGrouped(Tournament $tournament): array
	{
		$preview = [];

		$teams = $tournament->teams()->with(['player1', 'player2'])->get();
		$allRatingChanges = [];

		foreach ($teams as $team) {
			$allRatingChanges[$team->player1_id] = [
				'name' => $team->player1->full_name,
				'rating_before' => $team->player1->rating,
				'current_rating' => $team->player1->rating,
			];
			$allRatingChanges[$team->player2_id] = [
				'name' => $team->player2->full_name,
				'rating_before' => $team->player2->rating,
				'current_rating' => $team->player2->rating,
			];
		}

		// Групповой этап — по группам
		foreach ($tournament->teamGroups as $group) {
			$stageMatches = [];

			foreach ($group->matches()->where('status', 'completed')->get() as $match) {
				$team1 = $match->team1;
				$team2 = $match->team2;
				if (!$team1 || !$team2) continue;

				$p1_1 = $team1->player1_id;
				$p1_2 = $team1->player2_id;
				$p2_1 = $team2->player1_id;
				$p2_2 = $team2->player2_id;

				$r1_1 = $allRatingChanges[$p1_1]['current_rating'];
				$r1_2 = $allRatingChanges[$p1_2]['current_rating'];
				$r2_1 = $allRatingChanges[$p2_1]['current_rating'];
				$r2_2 = $allRatingChanges[$p2_2]['current_rating'];

				$team1Rating = ($r1_1 + $r1_2) / 2;
				$team2Rating = ($r2_1 + $r2_2) / 2;

				$result = $this->calculateRatingChange($team1Rating, $team2Rating, $match->team1_score, $match->team2_score);
				$change1 = $result['change1'];
				$change2 = $result['change2'];

				$expected1 = $this->expectedScore($team1Rating, $team2Rating);
				$kFactor = $this->getMatchKFactor($team1Rating, $team2Rating);
				$multiplier = $this->getScoreMultiplier($match->team1_score, $match->team2_score);

				$matchInfo1 = sprintf(
					"%s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | %d:%d | K=%d М=%.2f Шанс=%.0f%% | %+d",
					$allRatingChanges[$p1_1]['name'], $r1_1, $allRatingChanges[$p1_2]['name'], $r1_2, round($team1Rating),
					$allRatingChanges[$p2_1]['name'], $r2_1, $allRatingChanges[$p2_2]['name'], $r2_2, round($team2Rating),
					$match->team1_score, $match->team2_score, $kFactor, $multiplier, $expected1 * 100, $change1
				);
				$matchInfo2 = sprintf(
					"%s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | %d:%d | K=%d М=%.2f Шанс=%.0f%% | %+d",
					$allRatingChanges[$p2_1]['name'], $r2_1, $allRatingChanges[$p2_2]['name'], $r2_2, round($team2Rating),
					$allRatingChanges[$p1_1]['name'], $r1_1, $allRatingChanges[$p1_2]['name'], $r1_2, round($team1Rating),
					$match->team2_score, $match->team1_score, $kFactor, $multiplier, (1 - $expected1) * 100, $change2
				);

				$stageMatches[] = ['players' => [$p1_1, $p1_2], 'info' => $matchInfo1];
				$stageMatches[] = ['players' => [$p2_1, $p2_2], 'info' => $matchInfo2];

				$allRatingChanges[$p1_1]['current_rating'] = $this->applyRatingChange($r1_1, $change1);
				$allRatingChanges[$p1_2]['current_rating'] = $this->applyRatingChange($r1_2, $change1);
				$allRatingChanges[$p2_1]['current_rating'] = $this->applyRatingChange($r2_1, $change2);
				$allRatingChanges[$p2_2]['current_rating'] = $this->applyRatingChange($r2_2, $change2);
			}

			if (count($stageMatches) > 0) {
				$groupPlayers = [];
				foreach ($stageMatches as $m) {
					foreach ($m['players'] as $pId) {
						if (!isset($groupPlayers[$pId])) {
							$groupPlayers[$pId] = $allRatingChanges[$pId];
							$groupPlayers[$pId]['matches'] = [];
						}
						$groupPlayers[$pId]['matches'][] = $m['info'];
					}
				}
				$preview[$group->name] = $groupPlayers;
			}
		}

		// Плей-офф
		$playoffMatches = $tournament->playoffMatches()->where('status', 'completed')->orderBy('id')->get();
		if ($playoffMatches->count() > 0) {
			$stageMatches = [];

			foreach ($playoffMatches as $match) {
				$team1 = $match->team1;
				$team2 = $match->team2;
				if (!$team1 || !$team2) continue;

				$p1_1 = $team1->player1_id;
				$p1_2 = $team1->player2_id;
				$p2_1 = $team2->player1_id;
				$p2_2 = $team2->player2_id;

				$r1_1 = $allRatingChanges[$p1_1]['current_rating'];
				$r1_2 = $allRatingChanges[$p1_2]['current_rating'];
				$r2_1 = $allRatingChanges[$p2_1]['current_rating'];
				$r2_2 = $allRatingChanges[$p2_2]['current_rating'];

				$team1Rating = ($r1_1 + $r1_2) / 2;
				$team2Rating = ($r2_1 + $r2_2) / 2;

				$result = $this->calculateRatingChange($team1Rating, $team2Rating, $match->team1_score, $match->team2_score);
				$change1 = $result['change1'];
				$change2 = $result['change2'];

				$expected1 = $this->expectedScore($team1Rating, $team2Rating);
				$kFactor = $this->getMatchKFactor($team1Rating, $team2Rating);
				$multiplier = $this->getScoreMultiplier($match->team1_score, $match->team2_score);

				$stageName = $match->stage_name ?? $match->stage ?? 'Плей-офф';
				$matchInfo1 = sprintf(
					"%s: %s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | %d:%d | K=%d М=%.2f Шанс=%.0f%% | %+d",
					$stageName,
					$allRatingChanges[$p1_1]['name'], $r1_1, $allRatingChanges[$p1_2]['name'], $r1_2, round($team1Rating),
					$allRatingChanges[$p2_1]['name'], $r2_1, $allRatingChanges[$p2_2]['name'], $r2_2, round($team2Rating),
					$match->team1_score, $match->team2_score, $kFactor, $multiplier, $expected1 * 100, $change1
				);
				$matchInfo2 = sprintf(
					"%s: %s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | %d:%d | K=%d М=%.2f Шанс=%.0f%% | %+d",
					$stageName,
					$allRatingChanges[$p2_1]['name'], $r2_1, $allRatingChanges[$p2_2]['name'], $r2_2, round($team2Rating),
					$allRatingChanges[$p1_1]['name'], $r1_1, $allRatingChanges[$p1_2]['name'], $r1_2, round($team1Rating),
					$match->team2_score, $match->team1_score, $kFactor, $multiplier, (1 - $expected1) * 100, $change2
				);

				$stageMatches[] = ['players' => [$p1_1, $p1_2], 'info' => $matchInfo1];
				$stageMatches[] = ['players' => [$p2_1, $p2_2], 'info' => $matchInfo2];

				$allRatingChanges[$p1_1]['current_rating'] = $this->applyRatingChange($r1_1, $change1);
				$allRatingChanges[$p1_2]['current_rating'] = $this->applyRatingChange($r1_2, $change1);
				$allRatingChanges[$p2_1]['current_rating'] = $this->applyRatingChange($r2_1, $change2);
				$allRatingChanges[$p2_2]['current_rating'] = $this->applyRatingChange($r2_2, $change2);
			}

			if (count($stageMatches) > 0) {
				$playoffPlayers = [];
				foreach ($stageMatches as $m) {
					foreach ($m['players'] as $pId) {
						if (!isset($playoffPlayers[$pId])) {
							$playoffPlayers[$pId] = $allRatingChanges[$pId];
							$playoffPlayers[$pId]['matches'] = [];
						}
						$playoffPlayers[$pId]['matches'][] = $m['info'];
					}
				}
				$preview['Плей-офф'] = $playoffPlayers;
			}
		}

		return $preview;
	}

	/**
	 * Отсортировать команды в группе по правилам
	 * 1. Очки
	 * 2. Если 2 команды равны — личная встреча
	 * 3. Если 3+ команды равны — разница мячей, потом рекурсивно для оставшихся
	 */
	public function getSortedStandings(TournamentTeamGroup $group): array
	{
		$standings = $group->standings()->with('team')->get()->toArray();
		
		// Получаем все матчи группы для личных встреч
		$matches = $group->matches()->where('status', 'completed')->get();
		
		// Строим карту личных встреч: [team1_id][team2_id] => winnerId
		$headToHead = [];
		foreach ($matches as $match) {
			if ($match->team1_score > $match->team2_score) {
				$headToHead[$match->team1_id][$match->team2_id] = $match->team1_id;
				$headToHead[$match->team2_id][$match->team1_id] = $match->team1_id;
			} elseif ($match->team2_score > $match->team1_score) {
				$headToHead[$match->team1_id][$match->team2_id] = $match->team2_id;
				$headToHead[$match->team2_id][$match->team1_id] = $match->team2_id;
			} else {
				$headToHead[$match->team1_id][$match->team2_id] = null;
				$headToHead[$match->team2_id][$match->team1_id] = null;
			}
		}
		
		// Группируем по очкам
		$byPoints = [];
		foreach ($standings as $standing) {
			$points = $standing['points'];
			$byPoints[$points][] = $standing;
		}
		
		// Сортируем по очкам (desc)
		krsort($byPoints);
		
		// Результат
		$sorted = [];
		
		foreach ($byPoints as $points => $teams) {
			$sortedGroup = $this->sortTeamGroup($teams, $headToHead);
			foreach ($sortedGroup as $team) {
				$sorted[] = $team;
			}
		}
		
		return $sorted;
	}

	/**
	 * Сортировка группы команд с равными очками
	 */
	protected function sortTeamGroup(array $teams, array $headToHead): array
	{
		if (count($teams) <= 1) {
			return $teams;
		}
		
		if (count($teams) === 2) {
			// Две команды — смотрим личную встречу
			$team1 = $teams[0];
			$team2 = $teams[1];
			$winner = $headToHead[$team1['team_id']][$team2['team_id']] ?? null;
			
			if ($winner === $team2['team_id']) {
				return [$team2, $team1];
			} elseif ($winner === $team1['team_id']) {
				return [$team1, $team2];
			} else {
				// Ничья или не играли — по разнице мячей
				$diff1 = $team1['points_for'] - $team1['points_against'];
				$diff2 = $team2['points_for'] - $team2['points_against'];
				
				if ($diff2 > $diff1) {
					return [$team2, $team1];
				}
				return [$team1, $team2];
			}
		}
		
		// 3+ команды — сортируем по разнице мячей
		usort($teams, function($a, $b) {
			$diffA = $a['points_for'] - $a['points_against'];
			$diffB = $b['points_for'] - $b['points_against'];
			return $diffB <=> $diffA;
		});
		
		// Берём первого (лучшая разница)
		$first = array_shift($teams);
		
		// Рекурсивно сортируем оставшихся
		$rest = $this->sortTeamGroup($teams, $headToHead);
		
		return array_merge([$first], $rest);
	}
}
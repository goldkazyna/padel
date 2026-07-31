<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\MexicanoPlayer;
use App\Models\MexicanoRound;
use App\Models\MexicanoMatch;
use App\Models\MexicanoPairHistory;
use App\Models\User;

class MexicanoService
{
	use \App\Traits\RatingCalculator;
    /**
     * Запустить турнир Мексикано
     */
    public function startTournament(Tournament $tournament): bool
    {
        $participants = $tournament->participants()->orderBy('rating', 'desc')->get();
        
        if ($participants->count() !== $tournament->max_participants) {
            return false;
        }

        if ($tournament->mexicanoPlayers()->count() > 0) {
            return false;
        }

        // Создаём записи игроков с сохранением рейтинга
        foreach ($participants as $player) {
            MexicanoPlayer::create([
                'tournament_id' => $tournament->id,
                'user_id' => $player->id,
                'total_points' => 0,
                'rating_before' => $player->rating,
            ]);
        }

        // Генерируем первый раунд по рейтингу
        $this->generateFirstRound($tournament, $participants->pluck('id')->toArray());

        $tournament->update(['status' => 'in_progress']);

        return true;
    }

    /**
     * Генерация первого раунда (по рейтингу)
     */
	protected function generateFirstRound(Tournament $tournament, array $playerIds): void
	{
		$round = MexicanoRound::create([
			'tournament_id' => $tournament->id,
			'round_number' => 1,
			'status' => 'in_progress',
		]);

		$courtNumber = 1;

		// Первый раунд — по РЕЙТИНГУ: $playerIds уже отсортированы по рейтингу DESC
		// (сильнейший — 1-е место, слабейший — последнее). Режем по 4 подряд:
		// корт 1 — четыре сильнейших, и т.д. Внутри четвёрки пары 1+4 vs 2+3 (баланс),
		// как в последующих раундах.
		for ($i = 0; $i < count($playerIds); $i += 4) {
			if (isset($playerIds[$i + 3])) {
				$p1 = $playerIds[$i];     // сильнейший в четвёрке
				$p2 = $playerIds[$i + 1]; // 2-й
				$p3 = $playerIds[$i + 2]; // 3-й
				$p4 = $playerIds[$i + 3]; // слабейший в четвёрке

				// Команда 1 = 1+4, команда 2 = 2+3.
				MexicanoMatch::create([
					'mexicano_round_id' => $round->id,
					'court_number' => $courtNumber,
					'team1_player1_id' => $p1,
					'team1_player2_id' => $p4,
					'team2_player1_id' => $p2,
					'team2_player2_id' => $p3,
					'status' => 'pending',
				]);

				// Записываем историю пар
				$this->recordPairHistory($tournament->id, $p1, $p4, true);
				$this->recordPairHistory($tournament->id, $p2, $p3, true);
				$this->recordPairHistory($tournament->id, $p1, $p2, false);
				$this->recordPairHistory($tournament->id, $p1, $p3, false);
				$this->recordPairHistory($tournament->id, $p4, $p2, false);
				$this->recordPairHistory($tournament->id, $p4, $p3, false);

				$courtNumber++;
			}
		}
	}

    /**
     * Записать историю пар
     */
    protected function recordPairHistory(int $tournamentId, int $player1Id, int $player2Id, bool $asPartners): void
    {
        // Всегда храним меньший ID первым для консистентности
        $p1 = min($player1Id, $player2Id);
        $p2 = max($player1Id, $player2Id);

        $history = MexicanoPairHistory::firstOrCreate([
            'tournament_id' => $tournamentId,
            'player1_id' => $p1,
            'player2_id' => $p2,
        ], [
            'times_as_partners' => 0,
            'times_as_opponents' => 0,
        ]);

        if ($asPartners) {
            $history->increment('times_as_partners');
        } else {
            $history->increment('times_as_opponents');
        }
    }


    /**
	 * Генерация следующего раунда (по очкам)
	 */
	public function generateNextRound(Tournament $tournament): ?MexicanoRound
	{
		$currentRoundNumber = $tournament->mexicanoRounds()->reorder('round_number', 'desc')->value('round_number') ?? 0;
		
		if ($currentRoundNumber >= $tournament->rounds_count) {
			return null; // Все раунды сыграны
		}

		// Получаем игроков и считаем статистику
		$players = $tournament->mexicanoPlayers()->with('user')->get();
		
		// Собираем статистику по матчам для правильной сортировки
		$playerStats = $this->calculatePlayerStats($tournament);
		
		// Сортируем игроков как в таблице лидеров: очки → разница → % → рейтинг.
		// Рейтинг обязателен финальным тай-брейком, иначе при полном равенстве
		// порядок разбивки на пары расходится с отображаемой таблицей.
		$sortedPlayers = $players->sort(function($a, $b) use ($playerStats) {
			$statsA = $playerStats[$a->user_id] ?? ['total_points' => 0, 'diff' => 0, 'pct' => 0];
			$statsB = $playerStats[$b->user_id] ?? ['total_points' => 0, 'diff' => 0, 'pct' => 0];

			// По очкам
			if ($statsA['total_points'] !== $statsB['total_points']) {
				return $statsB['total_points'] <=> $statsA['total_points'];
			}
			// По разнице мячей
			if ($statsA['diff'] !== $statsB['diff']) {
				return $statsB['diff'] <=> $statsA['diff'];
			}
			// По проценту
			if ($statsA['pct'] !== $statsB['pct']) {
				return $statsB['pct'] <=> $statsA['pct'];
			}
			// Финальный тай-брейк — по рейтингу (как в таблице лидеров).
			return (optional($b->user)->rating ?? 0) <=> (optional($a->user)->rating ?? 0);
		});

		$round = MexicanoRound::create([
			'tournament_id' => $tournament->id,
			'round_number' => $currentRoundNumber + 1,
			'status' => 'in_progress',
		]);

		// Формируем пары с учётом истории
		$this->generatePairsForRound($tournament, $round, $sortedPlayers);

		return $round;
	}

	/**
	 * Рассчитать статистику игроков для сортировки
	 */
	protected function calculatePlayerStats(Tournament $tournament): array
	{
		$stats = [];
		
		// Инициализируем
		foreach ($tournament->mexicanoPlayers as $mp) {
			$stats[$mp->user_id] = [
				'total_points' => $mp->total_points,
				'points_for' => 0,
				'points_against' => 0,
				'diff' => 0,
				'pct' => 0,
			];
		}
		
		// Собираем данные по матчам
		foreach ($tournament->mexicanoRounds as $round) {
			foreach ($round->matches as $match) {
				if (!$match->isCompleted()) continue;
				
				$team1 = [$match->team1_player1_id, $match->team1_player2_id];
				$team2 = [$match->team2_player1_id, $match->team2_player2_id];
				
				foreach ($team1 as $pId) {
					if (isset($stats[$pId])) {
						$stats[$pId]['points_for'] += $match->team1_score;
						$stats[$pId]['points_against'] += $match->team2_score;
					}
				}
				
				foreach ($team2 as $pId) {
					if (isset($stats[$pId])) {
						$stats[$pId]['points_for'] += $match->team2_score;
						$stats[$pId]['points_against'] += $match->team1_score;
					}
				}
			}
		}
		
		// Считаем разницу и процент
		foreach ($stats as $pId => &$s) {
			$s['diff'] = $s['points_for'] - $s['points_against'];
			$total = $s['points_for'] + $s['points_against'];
			$s['pct'] = $total > 0 ? $s['points_for'] / $total : 0;
		}
		
		return $stats;
	}

    /**
	 * Формирование пар для раунда (правильный Мексикано)
	 */
	protected function generatePairsForRound(Tournament $tournament, MexicanoRound $round, $players): void
	{
		$playerIds = $players->pluck('user_id')->toArray();
		$courtNumber = 1;

		// Разбиваем игроков на группы по 4 (они уже отсортированы по очкам)
		$groups = array_chunk($playerIds, 4);

		foreach ($groups as $group) {
			if (count($group) < 4) continue;

			// Игроки в группе отсортированы по очкам: [лучший, 2-й, 3-й, худший]
			// Формируем пары: 1+4 vs 2+3 для баланса
			$p1 = $group[0]; // лучший
			$p4 = $group[3]; // худший
			$p2 = $group[1]; // 2-й
			$p3 = $group[2]; // 3-й

			// Проверяем историю партнёрств и выбираем лучшую комбинацию
			$bestPairing = $this->findBestPairingForGroup($tournament->id, $p1, $p2, $p3, $p4);

			MexicanoMatch::create([
				'mexicano_round_id' => $round->id,
				'court_number' => $courtNumber,
				'team1_player1_id' => $bestPairing['team1'][0],
				'team1_player2_id' => $bestPairing['team1'][1],
				'team2_player1_id' => $bestPairing['team2'][0],
				'team2_player2_id' => $bestPairing['team2'][1],
				'status' => 'pending',
			]);

			// Записываем историю
			$this->recordPairHistory($tournament->id, $bestPairing['team1'][0], $bestPairing['team1'][1], true);
			$this->recordPairHistory($tournament->id, $bestPairing['team2'][0], $bestPairing['team2'][1], true);
			$this->recordPairHistory($tournament->id, $bestPairing['team1'][0], $bestPairing['team2'][0], false);
			$this->recordPairHistory($tournament->id, $bestPairing['team1'][0], $bestPairing['team2'][1], false);
			$this->recordPairHistory($tournament->id, $bestPairing['team1'][1], $bestPairing['team2'][0], false);
			$this->recordPairHistory($tournament->id, $bestPairing['team1'][1], $bestPairing['team2'][1], false);

			$courtNumber++;
		}
	}

	/**
	 * Найти лучшую комбинацию пар для группы из 4 игроков
	 * Мексикано: всегда 1+4 vs 2+3 для максимального баланса
	 */
	protected function findBestPairingForGroup(int $tournamentId, int $p1, int $p2, int $p3, int $p4): array
	{
		// Всегда самый сбалансированный вариант: 1+4 vs 2+3
		return [
			'team1' => [$p1, $p4],
			'team2' => [$p2, $p3],
		];
	}

    /**
     * Найти лучшего партнёра (с кем меньше всего играл)
     */
    protected function findBestPartner(int $tournamentId, int $playerId, array $allPlayers, array $excluded): ?int
    {
        $bestPartner = null;
        $minTimes = PHP_INT_MAX;

        foreach ($allPlayers as $candidateId) {
            if ($candidateId === $playerId || in_array($candidateId, $excluded)) {
                continue;
            }

            $times = $this->getTimesAsPartners($tournamentId, $playerId, $candidateId);
            
            if ($times < $minTimes) {
                $minTimes = $times;
                $bestPartner = $candidateId;
            }
        }

        return $bestPartner;
    }

    /**
     * Получить количество раз в паре
     */
    protected function getTimesAsPartners(int $tournamentId, int $player1Id, int $player2Id): int
    {
        $p1 = min($player1Id, $player2Id);
        $p2 = max($player1Id, $player2Id);

        $history = MexicanoPairHistory::where('tournament_id', $tournamentId)
            ->where('player1_id', $p1)
            ->where('player2_id', $p2)
            ->first();

        return $history ? $history->times_as_partners : 0;
    }

    /**
     * Сохранить результат матча
     */
    public function saveMatchResult(MexicanoMatch $match, int $team1Score, int $team2Score): void
    {
        $tournament = $match->round->tournament;

        // Идемпотентность: если матч уже сыгран (повторный сабмит, двойной клик,
        // обрыв связи и повторная отправка) — сначала откатываем старые очки,
        // иначе total_points задвоится.
        if ($match->isCompleted()) {
            $this->addPlayerPoints($tournament->id, $match->team1_player1_id, -$match->team1_score);
            $this->addPlayerPoints($tournament->id, $match->team1_player2_id, -$match->team1_score);
            $this->addPlayerPoints($tournament->id, $match->team2_player1_id, -$match->team2_score);
            $this->addPlayerPoints($tournament->id, $match->team2_player2_id, -$match->team2_score);
        }

        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'status' => 'completed',
        ]);

        // Обновляем очки игроков
        $this->addPlayerPoints($tournament->id, $match->team1_player1_id, $team1Score);
        $this->addPlayerPoints($tournament->id, $match->team1_player2_id, $team1Score);
        $this->addPlayerPoints($tournament->id, $match->team2_player1_id, $team2Score);
        $this->addPlayerPoints($tournament->id, $match->team2_player2_id, $team2Score);

        // Проверяем завершение раунда
        $this->checkRoundCompletion($match->round);
    }

    /**
     * Обновить результат матча
     */
    public function updateMatchResult(MexicanoMatch $match, int $team1Score, int $team2Score): void
    {
        $tournament = $match->round->tournament;

        // Откатываем старые очки
        if ($match->isCompleted()) {
            $this->addPlayerPoints($tournament->id, $match->team1_player1_id, -$match->team1_score);
            $this->addPlayerPoints($tournament->id, $match->team1_player2_id, -$match->team1_score);
            $this->addPlayerPoints($tournament->id, $match->team2_player1_id, -$match->team2_score);
            $this->addPlayerPoints($tournament->id, $match->team2_player2_id, -$match->team2_score);
        }

        // Сохраняем новые
        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'status' => 'completed',
        ]);

        $this->addPlayerPoints($tournament->id, $match->team1_player1_id, $team1Score);
        $this->addPlayerPoints($tournament->id, $match->team1_player2_id, $team1Score);
        $this->addPlayerPoints($tournament->id, $match->team2_player1_id, $team2Score);
        $this->addPlayerPoints($tournament->id, $match->team2_player2_id, $team2Score);
    }

    /**
     * Добавить очки игроку
     */
    protected function addPlayerPoints(int $tournamentId, int $userId, int $points): void
    {
        MexicanoPlayer::where('tournament_id', $tournamentId)
            ->where('user_id', $userId)
            ->increment('total_points', $points);
    }

    /**
     * Проверить завершение раунда
     */
	protected function checkRoundCompletion(MexicanoRound $round): void
	{
		$pendingCount = $round->matches()->where('status', 'pending')->count();
		
		if ($pendingCount === 0) {
			$round->update(['status' => 'completed']);
		}
	}
	/**
	 * Проверить можно ли завершить турнир
	 */
	public function canFinishTournament(Tournament $tournament): bool
	{
		// Все раунды должны быть сыграны
		$completedRounds = $tournament->mexicanoRounds()->where('status', 'completed')->count();
		if ($completedRounds < $tournament->rounds_count) {
			return false;
		}

		// Если есть плей-офф — проверяем что финал сыгран
		if ($tournament->hasPlayoff()) {
			$finalMatch = $tournament->playoffMatches()
				->where('stage', 'Финал')
				->first();
			
			if (!$finalMatch || $finalMatch->status !== 'completed') {
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

		// Не рейтинговый турнир — завершаем без начисления рейтинга.
		if (!$tournament->is_rated) {
			$tournament->update(['status' => 'completed']);
			return true;
		}

		$players = $tournament->mexicanoPlayers()->with('user')->get();
		$ratingChanges = [];

		// Инициализируем рейтинги
		foreach ($players as $player) {
			$ratingChanges[$player->user_id] = [
				'rating_before' => (int) $player->rating_before,
				'current_rating' => (int) $player->rating_before,
			];
		}

		// Проходим по всем матчам основных раундов
		foreach ($tournament->mexicanoRounds()->orderBy('round_number')->get() as $round) {
			foreach ($round->matches as $match) {
				$this->calculateEloForMatch($match, $ratingChanges);
			}
		}

		// Проходим по плей-офф матчам
		if ($tournament->hasPlayoff()) {
			$playoffMatches = $tournament->playoffMatches()
				->where('status', 'completed')
				->orderBy('stage')
				->orderBy('match_number')
				->get();

			foreach ($playoffMatches as $match) {
				$this->calculateEloForPlayoffMatch($match, $ratingChanges);
			}
		}

		// Сохраняем финальные рейтинги
		foreach ($players as $player) {
			$calcFinal = (int) $ratingChanges[$player->user_id]['current_rating'];
			$delta = $calcFinal - (int) $player->rating_before;
			$actualBefore = $player->user->rating;
			$actualAfter = max($this->minRating, $actualBefore + $delta);

			$player->update(['rating_after' => $actualAfter]);
			$player->user->update(['rating' => $actualAfter]);
			$this->updateLevel($player->user->fresh());
			// Записываем историю
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

		return true;
	}

	/**
	 * Пересчитать дельты рейтинга БЕЗ применения. Возвращает [user_id => delta].
	 * $includePlayoff=false — только группа (для изоляции вклада плей-офф).
	 * Используется для ремонта завершённых турниров.
	 */
	public function recomputeRatingDeltas(Tournament $tournament, bool $includePlayoff = true): array
	{
		$players = $tournament->mexicanoPlayers()->with('user')->get();
		$ratingChanges = [];
		foreach ($players as $player) {
			$ratingChanges[$player->user_id] = [
				'rating_before' => (int) $player->rating_before,
				'current_rating' => (int) $player->rating_before,
			];
		}

		foreach ($tournament->mexicanoRounds()->orderBy('round_number')->get() as $round) {
			foreach ($round->matches as $match) {
				$this->calculateEloForMatch($match, $ratingChanges);
			}
		}

		if ($includePlayoff) {
			$playoffMatches = $tournament->playoffMatches()
				->where('status', 'completed')
				->orderBy('stage')
				->orderBy('match_number')
				->get();
			foreach ($playoffMatches as $match) {
				$this->calculateEloForPlayoffMatch($match, $ratingChanges);
			}
		}

		$deltas = [];
		foreach ($players as $player) {
			$calcFinal = (int) $ratingChanges[$player->user_id]['current_rating'];
			$deltas[$player->user_id] = $calcFinal - (int) $player->rating_before;
		}
		return $deltas;
	}

	/**
	 * Рассчитать Эло для плей-офф матча
	 */
	protected function calculateEloForPlayoffMatch(\App\Models\TournamentPlayoffMatch $match, array &$ratingChanges): void
	{
		$p1_1 = $match->team1_player1_id;
		$p1_2 = $match->team1_player2_id;
		$p2_1 = $match->team2_player1_id;
		$p2_2 = $match->team2_player2_id;

		if (!$p1_1 || !$p1_2 || !$p2_1 || !$p2_2) return;

		// Инициализируем если нет
		foreach ([$p1_1, $p1_2, $p2_1, $p2_2] as $pId) {
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
	 * Рассчитать Эло для матча
	 */
	protected function calculateEloForMatch(MexicanoMatch $match, array &$ratingChanges): void
	{
		$p1_1 = $match->team1_player1_id;
		$p1_2 = $match->team1_player2_id;
		$p2_1 = $match->team2_player1_id;
		$p2_2 = $match->team2_player2_id;

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
		$players = $tournament->mexicanoPlayers()->with('user')->get();
		$ratingChanges = [];

		foreach ($players as $player) {
			$ratingBefore = (int) $player->rating_before;
			$ratingChanges[$player->user_id] = [
				'name' => $player->user->full_name,
				'phone' => $player->user->phone,
				'rating_before' => $ratingBefore,
				'current_rating' => $ratingBefore,
				'matches' => [],
			];
		}

		// Основные раунды
		foreach ($tournament->mexicanoRounds()->orderBy('round_number')->get() as $round) {
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

		// Плей-офф матчи
		if ($tournament->hasPlayoff()) {
			$playoffMatches = $tournament->playoffMatches()
				->where('status', 'completed')
				->orderBy('stage')
				->orderBy('match_number')
				->get();

			foreach ($playoffMatches as $match) {
				$p1_1 = $match->team1_player1_id;
				$p1_2 = $match->team1_player2_id;
				$p2_1 = $match->team2_player1_id;
				$p2_2 = $match->team2_player2_id;

				if (!$p1_1 || !$p1_2 || !$p2_1 || !$p2_2) continue;

				// Инициализируем если игрок не из основного турнира
				foreach ([$p1_1, $p1_2, $p2_1, $p2_2] as $pId) {
					if (!isset($ratingChanges[$pId])) {
						$player = \App\Models\User::find($pId);
						if ($player) {
							$ratingChanges[$pId] = [
								'name' => $player->full_name,
								'rating_before' => $player->rating,
								'current_rating' => $player->rating,
								'matches' => [],
							];
						}
					}
				}

				$r1_1 = $ratingChanges[$p1_1]['current_rating'];
				$r1_2 = $ratingChanges[$p1_2]['current_rating'];
				$r2_1 = $ratingChanges[$p2_1]['current_rating'];
				$r2_2 = $ratingChanges[$p2_2]['current_rating'];

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

				$matchInfo2 = sprintf(
					"%s: %s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | Счёт %d:%d | K=%d | М=%.2f | Шанс=%.0f%% | %+d",
					$stageName,
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

		return $ratingChanges;
	}
	/**
	 * Можно ли сгенерировать следующий раунд
	 */

	public function canGenerateNextRound(Tournament $tournament): bool
	{
		$currentRound = $tournament->mexicanoRounds()->reorder('round_number', 'desc')->first();
		
		// Нет раундов — нельзя (турнир не начат)
		if (!$currentRound) {
			return false;
		}
		
		// Проверяем что ВСЕ матчи текущего раунда завершены
		$pendingMatches = $currentRound->matches()->where('status', 'pending')->count();
		if ($pendingMatches > 0) {
			return false;
		}
		
		// Все раунды сыграны — нельзя
		if ($currentRound->round_number >= $tournament->rounds_count) {
			return false;
		}
		
		return true;
	}
	/**
	 * Можно ли сгенерировать плей-офф
	 */
	public function canGeneratePlayoff(Tournament $tournament): bool
	{
		// Турнир должен быть Мексикано с плей-офф
		if (!$tournament->isMexicano() || !$tournament->hasPlayoff()) {
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

		// Все раунды должны быть сыграны
		$completedRounds = $tournament->mexicanoRounds()->where('status', 'completed')->count();
		if ($completedRounds < $tournament->rounds_count) {
			return false;
		}

		return true;
	}

	/**
	 * Сгенерировать плей-офф для Мексикано
	 */
	public function generatePlayoff(Tournament $tournament): bool
	{
		if (!$this->canGeneratePlayoff($tournament)) {
			return false;
		}

		// Получаем топ игроков отсортированных по очкам, разнице, проценту
		$playerStats = $this->calculatePlayerStats($tournament);
		
		$sortedPlayers = $tournament->mexicanoPlayers()
			->with('user')
			->get()
			->sort(function($a, $b) use ($playerStats) {
				$statsA = $playerStats[$a->user_id] ?? ['total_points' => 0, 'diff' => 0, 'pct' => 0];
				$statsB = $playerStats[$b->user_id] ?? ['total_points' => 0, 'diff' => 0, 'pct' => 0];
				
				if ($statsA['total_points'] !== $statsB['total_points']) {
					return $statsB['total_points'] <=> $statsA['total_points'];
				}
				if ($statsA['diff'] !== $statsB['diff']) {
					return $statsB['diff'] <=> $statsA['diff'];
				}
				return $statsB['pct'] <=> $statsA['pct'];
			})
			->values();

		if ($tournament->isFinalOnly()) {
			$this->createMexicanoFinalMatch($tournament, $sortedPlayers);
		} else {
			$this->createMexicanoSemifinalMatches($tournament, $sortedPlayers);
		}

		return true;
	}

	/**
	 * Создать только финальный матч (топ-4: 1+4 vs 2+3)
	 */
	protected function createMexicanoFinalMatch(Tournament $tournament, $players): void
	{
		if ($players->count() < 4) {
			return;
		}

		// 1+4 vs 2+3 для баланса
		\App\Models\TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'stage' => 'Финал',
			'match_number' => 1,
			'team1_player1_id' => $players[0]->user_id,
			'team1_player2_id' => $players[3]->user_id,
			'team2_player1_id' => $players[1]->user_id,
			'team2_player2_id' => $players[2]->user_id,
			'status' => 'pending',
		]);
	}

	/**
	 * Создать полуфиналы и финал (топ-8)
	 */
	protected function createMexicanoSemifinalMatches(Tournament $tournament, $players): void
	{
		if ($players->count() < 8) {
			return;
		}

		$format = $tournament->playoff_format ?? 'mix';
		
		// Топ-8 игроков (индексы 0-7 = места 1-8)
		$p = [];
		for ($i = 0; $i < 8; $i++) {
			$p[$i + 1] = $players[$i]->user_id;
		}

		// Формируем пары в зависимости от формата
		switch ($format) {
			case 'tops':
				// Топы вместе: (1+2 vs 7+8), (3+4 vs 5+6)
				$semi1 = ['team1' => [$p[1], $p[2]], 'team2' => [$p[7], $p[8]]];
				$semi2 = ['team1' => [$p[3], $p[4]], 'team2' => [$p[5], $p[6]]];
				break;
				
			case 'balanced':
				// Сбалансированный: (1+4 vs 5+8), (2+3 vs 6+7)
				$semi1 = ['team1' => [$p[1], $p[4]], 'team2' => [$p[5], $p[8]]];
				$semi2 = ['team1' => [$p[2], $p[3]], 'team2' => [$p[6], $p[7]]];
				break;
				
			case 'mix':
			default:
				// Микс: (1+8 vs 4+5), (2+7 vs 3+6)
				$semi1 = ['team1' => [$p[1], $p[8]], 'team2' => [$p[4], $p[5]]];
				$semi2 = ['team1' => [$p[2], $p[7]], 'team2' => [$p[3], $p[6]]];
				break;
		}

		// Полуфинал 1
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

		// Полуфинал 2
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

		// Финал (пустой, заполнится после полуфиналов)
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
	public function updateFinalAfterSemifinal(\App\Models\TournamentPlayoffMatch $semifinalMatch): void
	{
		$tournament = $semifinalMatch->tournament;
		
		// Находим финальный матч
		$finalMatch = $tournament->playoffMatches()
			->where('stage', 'Финал')
			->first();
		
		if (!$finalMatch) {
			return;
		}

		// Определяем победителя полуфинала
		$winnerId1 = $semifinalMatch->team1_score > $semifinalMatch->team2_score 
			? [$semifinalMatch->team1_player1_id, $semifinalMatch->team1_player2_id]
			: [$semifinalMatch->team2_player1_id, $semifinalMatch->team2_player2_id];

		// Обновляем финал в зависимости от номера полуфинала
		if ($semifinalMatch->match_number === 1) {
			$finalMatch->update([
				'team1_player1_id' => $winnerId1[0],
				'team1_player2_id' => $winnerId1[1],
			]);
		} else {
			$finalMatch->update([
				'team2_player1_id' => $winnerId1[0],
				'team2_player2_id' => $winnerId1[1],
			]);
		}
	}
}
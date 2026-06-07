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
     * Запустить турнир (авто-распределение змейкой по рейтингу)
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
     * Запустить турнир с РУЧНЫМ распределением: $assignments = [team_id => group_index (0..N-1)]
     * Возвращает [success: bool, message: string].
     */
    public function startTournamentWithAssignments(Tournament $tournament, array $assignments): array
    {
        $teams = $tournament->teams()->orderBy('rating_avg', 'desc')->get();
        $maxTeams = $tournament->max_participants / 2;
        $groupsCount = (int) $tournament->groups_count;
        $perGroup = $maxTeams / $groupsCount;

        if ($teams->count() !== $maxTeams) {
            return [false, 'Не все пары зарегистрированы.'];
        }
        if ($tournament->teamGroups()->count() > 0) {
            return [false, 'Группы уже созданы.'];
        }

        // Валидация: все команды распределены
        $teamIds = $teams->pluck('id')->all();
        foreach ($teamIds as $tid) {
            if (!isset($assignments[$tid]) || $assignments[$tid] === null || $assignments[$tid] === '') {
                return [false, 'Не все пары распределены по группам.'];
            }
            $gi = (int) $assignments[$tid];
            if ($gi < 0 || $gi >= $groupsCount) {
                return [false, 'Неверный номер группы.'];
            }
        }

        // Валидация: равномерность
        $countsPerGroup = array_fill(0, $groupsCount, 0);
        foreach ($assignments as $tid => $gi) {
            if (!in_array((int) $tid, $teamIds, true)) continue;
            $countsPerGroup[(int) $gi]++;
        }
        foreach ($countsPerGroup as $idx => $cnt) {
            if ($cnt !== (int) $perGroup) {
                return [false, "В группе " . chr(65 + $idx) . " {$cnt} пар, ожидалось {$perGroup}."];
            }
        }

        // Сохраняем рейтинг и сид
        foreach ($teams as $idx => $team) {
            $team->update([
                'rating_before' => $team->rating_avg,
                'seed' => $idx + 1,
            ]);
        }

        // Создаём группы
        $groups = [];
        for ($i = 0; $i < $groupsCount; $i++) {
            $groups[] = TournamentTeamGroup::create([
                'tournament_id' => $tournament->id,
                'name' => 'Группа ' . chr(65 + $i),
            ]);
        }

        // Раскладываем по группам согласно ручному маппингу
        foreach ($teams as $team) {
            $gi = (int) $assignments[$team->id];
            TournamentTeamStanding::create([
                'group_id' => $groups[$gi]->id,
                'team_id' => $team->id,
            ]);
        }

        // Генерим матчи как обычно
        $this->generateGroupMatches($tournament);

        $tournament->update(['status' => 'in_progress']);

        return [true, 'Турнир начался!'];
    }

    // =====================================================================
    // Ручной сбор пар (pairing_mode = admin): игроки регистрируются поодиночке,
    // админ собирает пары до старта. После старта — обычный групповой турнир.
    // =====================================================================

    /**
     * Состояние сбора пар: нераспределённые игроки + собранные пары + can_start.
     */
    public function getPairingState(Tournament $tournament): array
    {
        $maxPairs = (int) ($tournament->max_participants / 2);

        $teams = $tournament->teams()->with(['player1', 'player2'])->get();
        $pairedIds = [];
        foreach ($teams as $t) {
            $pairedIds[] = $t->player1_id;
            $pairedIds[] = $t->player2_id;
        }

        $unpaired = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->get()
            ->reject(fn($u) => in_array($u->id, $pairedIds, true))
            ->sortByDesc('rating')
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'rating' => (int) $u->rating,
                'level' => $u->level,
            ])
            ->values()
            ->all();

        $fmtP = fn($u) => $u ? [
            'id' => $u->id,
            'name' => $u->name,
            'avatar' => $u->avatar,
            'rating' => (int) $u->rating,
        ] : null;

        $approvedCount = $tournament->approvedParticipantsCount();
        $pendingCount = $tournament->pendingParticipantsCount();
        // Полный состав: все места подтверждены (нет заявок на модерации).
        $rosterReady = $approvedCount >= (int) $tournament->max_participants;

        return [
            'max_pairs' => $maxPairs,
            'pairs_count' => $teams->count(),
            'approved_count' => $approvedCount,
            'pending_count' => $pendingCount,
            'max_participants' => (int) $tournament->max_participants,
            'roster_ready' => $rosterReady,
            'unpaired' => $unpaired,
            'teams' => $teams->map(fn($t) => [
                'id' => $t->id,
                'rating_avg' => (int) $t->rating_avg,
                'player1' => $fmtP($t->player1),
                'player2' => $fmtP($t->player2),
            ])->values()->all(),
            'can_start' => $teams->count() === $maxPairs && $maxPairs > 0,
        ];
    }

    /**
     * Создать пару из двух зарегистрированных игроков.
     * @return array{0: bool, 1: string}
     */
    public function createPair(Tournament $tournament, int $player1Id, int $player2Id): array
    {
        if (!$tournament->isAdminPairing()) {
            return [false, 'Сбор пар недоступен для этого турнира.'];
        }
        if ($player1Id === $player2Id) {
            return [false, 'Нельзя поставить игрока в пару с самим собой.'];
        }
        if ($tournament->teamGroups()->count() > 0) {
            return [false, 'Турнир уже стартовал.'];
        }
        if ($tournament->approvedParticipantsCount() < (int) $tournament->max_participants) {
            return [false, 'Собрать пары можно только при полном составе — сначала подтвердите всех участников.'];
        }

        $registeredIds = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->pluck('users.id')->all();
        foreach ([$player1Id, $player2Id] as $pid) {
            if (!in_array($pid, $registeredIds, true)) {
                return [false, 'Оба игрока должны быть записаны на турнир.'];
            }
        }

        $alreadyPaired = $tournament->teams()
            ->where(function ($q) use ($player1Id, $player2Id) {
                $q->whereIn('player1_id', [$player1Id, $player2Id])
                  ->orWhereIn('player2_id', [$player1Id, $player2Id]);
            })->exists();
        if ($alreadyPaired) {
            return [false, 'Один из игроков уже в паре.'];
        }

        $maxPairs = (int) ($tournament->max_participants / 2);
        if ($tournament->teams()->count() >= $maxPairs) {
            return [false, 'Все пары уже собраны.'];
        }

        $r1 = (int) (\App\Models\User::find($player1Id)?->rating ?? 0);
        $r2 = (int) (\App\Models\User::find($player2Id)?->rating ?? 0);

        TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $player1Id,
            'player2_id' => $player2Id,
            'status' => 'approved',
            'rating_avg' => (int) round(($r1 + $r2) / 2),
        ]);

        return [true, 'Пара создана.'];
    }

    /**
     * Удалить пару (только до старта турнира).
     * @return array{0: bool, 1: string}
     */
    public function deletePair(Tournament $tournament, TournamentTeam $team): array
    {
        if ((int) $team->tournament_id !== (int) $tournament->id) {
            return [false, 'Пара не из этого турнира.'];
        }
        if ($tournament->teamGroups()->count() > 0) {
            return [false, 'Турнир уже стартовал — пары менять нельзя.'];
        }
        $team->delete();
        return [true, 'Пара удалена.'];
    }

    /**
     * Авто-сбор: всех свободных игроков разбивает на пары, балансируя по рейтингу
     * (сильнейший + слабейший), чтобы средний рейтинг пар был ровнее.
     * @return array{0: bool, 1: string}
     */
    public function autoBalancePairs(Tournament $tournament): array
    {
        if (!$tournament->isAdminPairing()) {
            return [false, 'Недоступно для этого турнира.'];
        }
        if ($tournament->teamGroups()->count() > 0) {
            return [false, 'Турнир уже стартовал.'];
        }
        if ($tournament->approvedParticipantsCount() < (int) $tournament->max_participants) {
            return [false, 'Собрать пары можно только при полном составе — сначала подтвердите всех участников.'];
        }

        $teams = $tournament->teams()->get();
        $pairedIds = [];
        foreach ($teams as $t) {
            $pairedIds[] = $t->player1_id;
            $pairedIds[] = $t->player2_id;
        }

        $pool = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->get()
            ->reject(fn($u) => in_array($u->id, $pairedIds, true))
            ->sortByDesc('rating')
            ->values();

        if ($pool->count() < 2) {
            return [false, 'Недостаточно свободных игроков.'];
        }

        $i = 0;
        $j = $pool->count() - 1;
        while ($i < $j) {
            $a = $pool[$i];
            $b = $pool[$j];
            TournamentTeam::create([
                'tournament_id' => $tournament->id,
                'player1_id' => $a->id,
                'player2_id' => $b->id,
                'status' => 'approved',
                'rating_avg' => (int) round(((int) $a->rating + (int) $b->rating) / 2),
            ]);
            $i++;
            $j--;
        }

        return [true, 'Пары собраны по рейтингу.'];
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

		// Если админ ограничил количество кортов — перераспределяем матчи
		// групповой стадии в волны по courts_count одновременно.
		// Логика rebalance имеет смысл только когда групп ≥ 2 (синхронизирует
		// раунды между группами). Для одной группы matrix round-robin уже
		// создаёт правильные раунды по floor(N/2) матчей на разных кортах —
		// rebalance бы схлопнул их в N(N-1)/2 туров по 1 матчу, что
		// неправильно (теряется параллелизм по кортам).
		if (
			$tournament->courts_count
			&& $tournament->courts_count > 0
			&& $groups->count() >= 2
		) {
			$this->rebalanceGroupMatchesToCourts($tournament, (int) $tournament->courts_count);
		}
	}

	/**
	 * Распределяет уже созданные матчи группового этапа в раунды-волны
	 * так, чтобы в каждой волне играло максимум по 1 матчу из каждой группы
	 * (round-robin по группам — когда играет группа A, играет и B, и C,
	 * а внутри каждой группы оставшиеся пары отдыхают).
	 *
	 * Если courtsCount > числа групп — лишние корты пустуют, чтобы сохранить
	 * «честность» отдыха пар внутри одной группы.
	 */
	protected function rebalanceGroupMatchesToCourts(Tournament $tournament, int $courtsCount): void
	{
		$matches = TournamentGroupMatch::whereHas('group', function ($q) use ($tournament) {
			$q->where('tournament_id', $tournament->id);
		})
			->orderBy('round_number')
			->orderBy('id')
			->get();

		// Группируем матчи по group_id в отдельные очереди,
		// порядок внутри очереди — как сгенерировали (по round_number, id)
		$queues = [];
		foreach ($matches as $m) {
			$queues[$m->group_id][] = $m;
		}
		$groupIds = array_keys($queues);

		$waves = [];
		while (true) {
			$wave = [];
			$usedTeams = [];
			foreach ($groupIds as $gid) {
				if (count($wave) >= $courtsCount) break;
				if (empty($queues[$gid])) continue;
				$next = $queues[$gid][0];
				// На всякий случай — проверяем что пары не пересекаются
				// (между группами пары уникальны, но страхуемся).
				if (isset($usedTeams[$next->team1_id]) || isset($usedTeams[$next->team2_id])) {
					continue;
				}
				array_shift($queues[$gid]);
				$wave[] = $next;
				$usedTeams[$next->team1_id] = true;
				$usedTeams[$next->team2_id] = true;
			}

			if (empty($wave)) break;
			$waves[] = $wave;
		}

		foreach ($waves as $wIdx => $wave) {
			foreach ($wave as $cIdx => $m) {
				$m->update([
					'round_number' => $wIdx + 1,
					'court_number' => $cIdx + 1,
					'status' => $wIdx === 0 ? 'in_progress' : 'pending',
				]);
			}
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

		// Специальный формат: 3 группы × 2 advance (турнир на 24 пары).
		// Верхняя сетка: 2 bye (лучшие 1-е) + QF (худшее 1-е + 3 вторых).
		// Опционально — нижняя сетка (для 3-х и 4-х мест) и матчи за 3-е место.
		//
		// Триггерится при:
		//   - groups_count === 3 И teams_advance === 2 (классический 3×2 формат), ИЛИ
		//   - включены чекбоксы «Нижняя сетка» или «Матч за 3-е место» при 3 группах
		//     (явный признак нового формата даже если teams_advance выставлен иначе).
		$isThreeGroups = (int) $tournament->groups_count === 3;
		$newFormatFlags = $tournament->has_lower_bracket || $tournament->has_bronze_match;
		if ($isThreeGroups && ((int) $tournament->teams_advance === 2 || $newFormatFlags)) {
			$this->generatePlayoffForThreeGroups($tournament);
			return true;
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
	 * Плей-офф для турнира на 3 группы / 2 advance (24 пары).
	 *
	 * Собирает по 3 команды на каждое место, ранжирует между группами
	 * по разнице геймов и строит:
	 *   - Верхнюю сетку: SF bye = 2 лучших 1-х, QF = худшее 1-е + 3 вторых.
	 *   - Нижнюю (опционально): SF bye = 2 лучших 3-х, QF = худшее 3-е + 3 четвёртых.
	 *   - Матч за 3-е место (опционально) в каждой включённой сетке.
	 */
	protected function generatePlayoffForThreeGroups(Tournament $tournament): void
	{
		// Сортированные standings всех групп (позиции 1-4 в каждой)
		$groupStandings = [];
		foreach ($tournament->teamGroups as $group) {
			$groupStandings[$group->id] = $this->getSortedStandings($group);
		}

		// Сборка команд по позициям: position 1..4, по одной команде из каждой группы
		$byPosition = [1 => [], 2 => [], 3 => [], 4 => []];
		foreach ($groupStandings as $gid => $sorted) {
			foreach ($byPosition as $pos => &$_unused) {
				if (isset($sorted[$pos - 1])) {
					$byPosition[$pos][] = array_merge($sorted[$pos - 1], ['group_id' => $gid]);
				}
			}
			unset($_unused);
		}

		// Между группами ранжируем по: очкам → разнице геймов → забитым геймам
		$rankBetweenGroups = function (array $teams): array {
			usort($teams, function ($a, $b) {
				if ($a['points'] !== $b['points']) return $b['points'] - $a['points'];
				$diffA = $a['points_for'] - $a['points_against'];
				$diffB = $b['points_for'] - $b['points_against'];
				if ($diffA !== $diffB) return $diffB - $diffA;
				return $b['points_for'] - $a['points_for'];
			});
			return $teams;
		};

		$firsts = $rankBetweenGroups($byPosition[1]);   // 0 = лучший, 1 = средний, 2 = худший
		$seconds = $rankBetweenGroups($byPosition[2]);
		$thirds  = $rankBetweenGroups($byPosition[3]);
		$fourths = $rankBetweenGroups($byPosition[4]);

		$upperMatchNumber = 0;
		$sourceLabel = fn($name) => $name;

		// === Верхняя сетка ===
		// QF: худшее 1-е vs худшее 2-е; лучшее 2-е vs среднее 2-е
		$qf1 = TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'court_number' => 1,
			'stage' => 'quarter',
			'bracket' => 'upper',
			'match_number' => ++$upperMatchNumber,
			'team1_id' => $firsts[2]['team_id'],
			'team2_id' => $seconds[2]['team_id'],
			'team1_source' => $sourceLabel('1' . $this->groupLetter($firsts[2]['group_id'], $tournament)),
			'team2_source' => $sourceLabel('2' . $this->groupLetter($seconds[2]['group_id'], $tournament)),
			'status' => 'in_progress',
		]);
		$qf2 = TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'court_number' => 2,
			'stage' => 'quarter',
			'bracket' => 'upper',
			'match_number' => ++$upperMatchNumber,
			'team1_id' => $seconds[0]['team_id'],
			'team2_id' => $seconds[1]['team_id'],
			'team1_source' => $sourceLabel('2' . $this->groupLetter($seconds[0]['group_id'], $tournament)),
			'team2_source' => $sourceLabel('2' . $this->groupLetter($seconds[1]['group_id'], $tournament)),
			'status' => 'in_progress',
		]);

		// SF: разводим сильнейших — лучший 1-й vs слабейший QF-winner,
		// 2-й 1-й vs сильнейший QF-winner. Сильнейший QF-winner — тот,
		// чей QF содержал «лучшее 2-е» (QF2).
		$sf1 = TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'court_number' => 1,
			'stage' => 'semi',
			'bracket' => 'upper',
			'match_number' => ++$upperMatchNumber,
			'team1_id' => $firsts[0]['team_id'],
			'team1_source' => $sourceLabel('1' . $this->groupLetter($firsts[0]['group_id'], $tournament)),
			'team2_source' => 'W' . $qf1->match_number,
			'status' => 'pending',
		]);
		$sf2 = TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'court_number' => 2,
			'stage' => 'semi',
			'bracket' => 'upper',
			'match_number' => ++$upperMatchNumber,
			'team1_id' => $firsts[1]['team_id'],
			'team1_source' => $sourceLabel('1' . $this->groupLetter($firsts[1]['group_id'], $tournament)),
			'team2_source' => 'W' . $qf2->match_number,
			'status' => 'pending',
		]);

		// Финал
		TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id,
			'court_number' => 1,
			'stage' => 'final',
			'bracket' => 'upper',
			'match_number' => ++$upperMatchNumber,
			'team1_source' => 'W' . $sf1->match_number,
			'team2_source' => 'W' . $sf2->match_number,
			'status' => 'pending',
		]);

		// Матч за 3-е место верхней (лузеры SF)
		if ($tournament->has_bronze_match) {
			TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 2,
				'stage' => 'final',
				'bracket' => 'upper',
				'is_bronze' => true,
				'match_number' => ++$upperMatchNumber,
				'team1_source' => 'L' . $sf1->match_number,
				'team2_source' => 'L' . $sf2->match_number,
				'status' => 'pending',
			]);
		}

		// === Нижняя сетка (опционально) ===
		if ($tournament->has_lower_bracket && count($thirds) >= 3 && count($fourths) >= 3) {
			$lowerBase = 100; // отдельный счётчик match_number для нижней, чтобы не путать с верхней
			$lowerMatchNumber = $lowerBase;

			$lqf1 = TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 1,
				'stage' => 'quarter',
				'bracket' => 'lower',
				'match_number' => ++$lowerMatchNumber,
				'team1_id' => $thirds[2]['team_id'],
				'team2_id' => $fourths[2]['team_id'],
				'team1_source' => '3' . $this->groupLetter($thirds[2]['group_id'], $tournament),
				'team2_source' => '4' . $this->groupLetter($fourths[2]['group_id'], $tournament),
				'status' => 'in_progress',
			]);
			$lqf2 = TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 2,
				'stage' => 'quarter',
				'bracket' => 'lower',
				'match_number' => ++$lowerMatchNumber,
				'team1_id' => $fourths[0]['team_id'],
				'team2_id' => $fourths[1]['team_id'],
				'team1_source' => '4' . $this->groupLetter($fourths[0]['group_id'], $tournament),
				'team2_source' => '4' . $this->groupLetter($fourths[1]['group_id'], $tournament),
				'status' => 'in_progress',
			]);

			$lsf1 = TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 1,
				'stage' => 'semi',
				'bracket' => 'lower',
				'match_number' => ++$lowerMatchNumber,
				'team1_id' => $thirds[0]['team_id'],
				'team1_source' => '3' . $this->groupLetter($thirds[0]['group_id'], $tournament),
				'team2_source' => 'W' . $lqf1->match_number,
				'status' => 'pending',
			]);
			$lsf2 = TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 2,
				'stage' => 'semi',
				'bracket' => 'lower',
				'match_number' => ++$lowerMatchNumber,
				'team1_id' => $thirds[1]['team_id'],
				'team1_source' => '3' . $this->groupLetter($thirds[1]['group_id'], $tournament),
				'team2_source' => 'W' . $lqf2->match_number,
				'status' => 'pending',
			]);

			TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id,
				'court_number' => 1,
				'stage' => 'final',
				'bracket' => 'lower',
				'match_number' => ++$lowerMatchNumber,
				'team1_source' => 'W' . $lsf1->match_number,
				'team2_source' => 'W' . $lsf2->match_number,
				'status' => 'pending',
			]);

			if ($tournament->has_bronze_match) {
				TournamentPlayoffMatch::create([
					'tournament_id' => $tournament->id,
					'court_number' => 2,
					'stage' => 'final',
					'bracket' => 'lower',
					'is_bronze' => true,
					'match_number' => ++$lowerMatchNumber,
					'team1_source' => 'L' . $lsf1->match_number,
					'team2_source' => 'L' . $lsf2->match_number,
					'status' => 'pending',
				]);
			}
		}
	}

	/**
	 * Буква группы по её id внутри турнира (A, B, C, ...).
	 */
	protected function groupLetter(int $groupId, Tournament $tournament): string
	{
		$ids = $tournament->teamGroups()->orderBy('id')->pluck('id')->toArray();
		$idx = array_search($groupId, $ids, true);
		return $idx === false ? '?' : chr(65 + $idx);
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

			// Матч за 3-е место: лузеры SF (используем 'L5'/'L6' для совместимости
			// с QF-форматом — см. advanceWinner legacy alias)
			if ($tournament->has_bronze_match) {
				TournamentPlayoffMatch::create([
					'tournament_id' => $tournament->id,
					'court_number' => 2,
					'stage' => 'final',
					'bracket' => 'upper',
					'is_bronze' => true,
					'match_number' => 3,
					'team1_source' => 'L5',
					'team2_source' => 'L6',
					'status' => 'pending',
				]);
			}
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

			// Матч за 3-е место: лузеры SF.
			// Используем 'L5'/'L6' (а не 'L1'/'L2'), потому что match_number 1,2
			// также есть у QF — иначе advanceWinner после QF неправильно
			// заполнит bronze квартером-проигравшим. SF match_number 1,2 —
			// при разрешении мы расширяем loseSrcs до 'L'.(4+match#) (см. advanceWinner).
			if ($tournament->has_bronze_match) {
				TournamentPlayoffMatch::create([
					'tournament_id' => $tournament->id,
					'court_number' => 2,
					'stage' => 'final',
					'bracket' => 'upper',
					'is_bronze' => true,
					'match_number' => 3,
					'team1_source' => 'L5',
					'team2_source' => 'L6',
					'status' => 'pending',
				]);
			}
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
     * Продвинуть победителя (и проигравшего — для бронзы) в следующий матч.
     *
     * Универсальная логика: ищет матчи, у которых team{1,2}_source =
     * 'W' . match_number (победитель) или 'L' . match_number (проигравший),
     * и заполняет соответствующие места.
     *
     * Для совместимости со старой схемой 8-team турниров, где финал
     * ссылается на полуфиналы как 'W5'/'W6' — добавлены дополнительные
     * win-source алиасы 'W' . (4 + match_number) при стадии semi.
     */
    protected function advanceWinner(TournamentPlayoffMatch $match): void
    {
        $tournament = $match->tournament;
        $winnerId = $match->winner_id;
        $loserId = $match->team1_id === $winnerId ? $match->team2_id : $match->team1_id;

        $winSrcs = ['W' . $match->match_number];
        $loseSrcs = ['L' . $match->match_number];

        if ($match->stage === 'semi') {
            // Legacy 8-team: SF имеет match_number 1,2 — но Final ожидает 'W5'/'W6'
            // и Bronze — 'L5'/'L6'. Добавляем алиасы.
            $winSrcs[] = 'W' . (4 + $match->match_number);
            $loseSrcs[] = 'L' . (4 + $match->match_number);
        }

        $all = $tournament->playoffMatches()->get();
        foreach ($all as $next) {
            if ($next->id === $match->id) continue;
            $updated = false;

            // Для bronze-матча после semi разрешаем перезапись team_id
            // (защита от случая, когда bronze был ошибочно заполнен ранее
            // quarter-проигравшим из-за коллизии match_number QF vs SF).
            $bronzeOverwrite = $next->is_bronze && $match->stage === 'semi';

            // Победитель → следующий матч
            if (!$next->team1_id && in_array($next->team1_source, $winSrcs, true)) {
                $next->team1_id = $winnerId;
                $updated = true;
            } elseif (!$next->team2_id && in_array($next->team2_source, $winSrcs, true)) {
                $next->team2_id = $winnerId;
                $updated = true;
            }
            // Проигравший → bronze (с overwrite если это semi-источник)
            if ((!$next->team1_id || $bronzeOverwrite)
                && in_array($next->team1_source, $loseSrcs, true)) {
                $next->team1_id = $loserId;
                $updated = true;
            } elseif ((!$next->team2_id || $bronzeOverwrite)
                && in_array($next->team2_source, $loseSrcs, true)) {
                $next->team2_id = $loserId;
                $updated = true;
            }

            if ($updated) {
                if ($next->team1_id && $next->team2_id) {
                    $next->status = 'in_progress';
                }
                $next->save();
            }
        }
    }

    /**
     * Проверить можно ли завершить турнир
     */
    public function canFinishTournament(Tournament $tournament): bool
    {
        // Все финалы (верхней и нижней сеток + опциональные бронзовые матчи)
        // должны быть завершены.
        $finalMatches = $tournament->playoffMatches()->where('stage', 'final')->get();
        if ($finalMatches->isEmpty()) return false;
        foreach ($finalMatches as $fm) {
            if (!$fm->isCompleted()) return false;
        }
        return true;
    }

    /**
	 * Завершить турнир
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

		// Рассчитываем Эло
		$ratingChanges = $this->previewRatingChanges($tournament);

		// Применяем рейтинги
		foreach ($tournament->teams as $team) {
			$p1CalcFinal = (int) $ratingChanges[$team->player1_id]['current_rating'];
			$p2CalcFinal = (int) $ratingChanges[$team->player2_id]['current_rating'];
			$p1Delta = $p1CalcFinal - (int) $ratingChanges[$team->player1_id]['rating_before'];
			$p2Delta = $p2CalcFinal - (int) $ratingChanges[$team->player2_id]['rating_before'];

			$p1ActualBefore = $team->player1->rating;
			$p2ActualBefore = $team->player2->rating;
			$p1ActualAfter = max($this->minRating, $p1ActualBefore + $p1Delta);
			$p2ActualAfter = max($this->minRating, $p2ActualBefore + $p2Delta);

			$team->update([
				'rating_after' => intval(($p1ActualAfter + $p2ActualAfter) / 2),
			]);

			$team->player1->update(['rating' => $p1ActualAfter]);
			$team->player2->update(['rating' => $p2ActualAfter]);
			$this->updateLevel($team->player1->fresh());
			$this->updateLevel($team->player2->fresh());
			// Записываем историю
			\App\Models\RatingHistory::create([
				'user_id' => $team->player1_id,
				'tournament_id' => $tournament->id,
				'rating_before' => $p1ActualBefore,
				'rating_after' => $p1ActualAfter,
				'change' => $p1Delta,
				'reason' => $tournament->name,
			]);

			\App\Models\RatingHistory::create([
				'user_id' => $team->player2_id,
				'tournament_id' => $tournament->id,
				'rating_before' => $p2ActualBefore,
				'rating_after' => $p2ActualAfter,
				'change' => $p2Delta,
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
				'phone' => $team->player1->phone,
				'rating_before' => $team->player1->rating,
				'current_rating' => $team->player1->rating,
				'matches' => [],
			];
			$ratingChanges[$team->player2_id] = [
				'name' => $team->player2->full_name,
				'phone' => $team->player2->phone,
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
	 *
	 * 2 команды: личная встреча → разница мячей
	 * 3+ команды: разница мячей → забитые мячи
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

				if ($diff1 !== $diff2) {
					return $diff2 > $diff1 ? [$team2, $team1] : [$team1, $team2];
				}

				// Одинаковая разница — по забитым мячам
				return $team2['points_for'] > $team1['points_for'] ? [$team2, $team1] : [$team1, $team2];
			}
		}

		// 3+ команды — сортируем по разнице мячей, затем по забитым
		usort($teams, function($a, $b) {
			$diffA = $a['points_for'] - $a['points_against'];
			$diffB = $b['points_for'] - $b['points_against'];

			if ($diffB !== $diffA) {
				return $diffB <=> $diffA;
			}

			// Одинаковая разница — по забитым мячам
			return $b['points_for'] <=> $a['points_for'];
		});

		return $teams;
	}
}
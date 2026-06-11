<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\Club;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\AmericanoService;
use App\Models\TournamentPlayoffMatch;
use App\Models\TournamentParticipant;
use App\Http\Controllers\Api\MobileTournamentController;
use App\Support\PhoneVisibility;


class TournamentController extends Controller
{
    // Получить клуб текущего админа
	private function getClub()
	{
		$user = auth()->user();
		
		if ($user->isSuperAdmin()) {
			return null; // Супер-админ видит все
		}
		
		if ($user->isClubModerator()) {
			return $user->moderatorClubs()->first();
		}
		
		return $user->adminClubs()->first();
	}

	public function index()
	{
		$club = $this->getClub();
		$user = auth()->user();

		if ($club) {
			$query = Tournament::where('club_id', $club->id);

			// Обычный модератор видит только открытые турниры; full-access
			// модератор видит все статусы (как админ).
			if ($user->isClubModerator() && !$user->hasTournamentsFullAccess($club)) {
				$query->where('status', 'open');
			}

			$tournaments = $query->orderBy('start_date', 'desc')->get();
		} else {
			$tournaments = Tournament::with('club')
				->orderBy('start_date', 'desc')
				->get();
		}

		$groupedTournaments = $tournaments->groupBy(fn ($t) => $t->start_date->format('Y-m'));

		return view('club.tournaments.index', compact('groupedTournaments', 'club'));
	}

    public function create()
    {
		$user = auth()->user();
		if ($user->isClubModerator()) {
			$accessClub = $this->getClub();
			if (!$accessClub || !$user->hasTournamentsFullAccess($accessClub)) {
				abort(403, 'У вас нет прав на это действие. Обратитесь к админу клуба.');
			}
		}
        $club = $this->getClub();
        $clubs = auth()->user()->isSuperAdmin() ? Club::active()->get() : collect([$club]);
        
        return view('club.tournaments.create', compact('clubs', 'club'));
    }

    public function store(Request $request)
    {
		$user = auth()->user();
		if ($user->isClubModerator()) {
			$accessClub = $this->getClub();
			if (!$accessClub || !$user->hasTournamentsFullAccess($accessClub)) {
				abort(403, 'У вас нет прав на это действие. Обратитесь к админу клуба.');
			}
		}
        $club = $this->getClub();
        
		$validated = $request->validate([
			'club_id' => 'required|exists:clubs,id',
			'name' => 'required|string|max:255',
			'description' => 'nullable|string',
			'start_date' => 'required|date|after:now',
			'min_level' => 'required|numeric|min:1|max:5.75',
			'max_level' => 'required|numeric|min:1|max:5.75|gte:min_level',
			'max_participants' => 'required|integer|min:2|max:128',
			'price' => 'nullable|numeric|min:0',
			'status' => 'required|in:draft,open',
			'type' => 'required|in:americano,mexicano,team,king_of_court,bali_koc,americano_flex,round_robin',
			'points_to_win' => 'nullable|integer|in:16,21,24,32,42',
			'groups_count' => 'nullable|integer|in:1,2,3,4',
			'rounds_count' => 'nullable|integer|min:3|max:30',
			'teams_advance' => 'nullable|integer|in:1,2,3,4',
			'has_playoff' => 'nullable|boolean',
			'has_lower_bracket' => 'nullable|boolean',
			'has_bronze_match' => 'nullable|boolean',
			'playoff_type' => 'nullable|in:final_only,semifinal_final',
			'playoff_format' => 'nullable|in:mix,group_vs,tops,cross,balanced',
			'reserve_count' => 'nullable|integer|min:0|max:10',
			'waitlist_size' => 'nullable|integer|min:0|max:32',
			'moderation_hours' => 'nullable|integer|min:0|max:720',
			'moderation_minutes' => 'nullable|integer|min:0|max:1440',
			'courts' => 'nullable|array',
			'courts.*' => 'nullable|string|max:50',
			'courts_count' => 'nullable|integer|min:1|max:32',
			'flex_courts_count' => 'nullable|integer|min:1|max:8',
			'pairing_mode' => 'nullable|in:self,admin',
		]);

		// Americano Flex: количество кортов задаётся вручную отдельным полем,
		// перекладываем его в courts_count (а не авто ceil(игроки/4)).
		if (($validated['type'] ?? null) === 'americano_flex') {
			$validated['courts_count'] = $validated['flex_courts_count'] ?? 2;
		}
		unset($validated['flex_courts_count']);


		$validated['has_lower_bracket'] = $request->has('has_lower_bracket');
		$validated['has_bronze_match'] = $request->has('has_bronze_match');

		// Для парного турнира плей-офф всегда включён (парный без него бессмыслен).
		// Для остальных — по чекбоксу. Также включаем автоматически если
		// отмечены флаги нижней сетки / матча за 3-е место.
		$isTeamType = ($validated['type'] ?? null) === 'team';
		$validated['has_playoff'] = $isTeamType
			|| $request->has('has_playoff')
			|| $validated['has_lower_bracket']
			|| $validated['has_bronze_match'];

		if (!$validated['has_playoff']) {
			$validated['playoff_type'] = null;
			$validated['playoff_format'] = null;
			$validated['has_lower_bracket'] = false;
			$validated['has_bronze_match'] = false;
		}

		// Рейтинговый ли турнир. Чекбокс предотмечен — снят = не рейтинговый.
		$validated['is_rated'] = $request->has('is_rated');
		$validated['verified_only'] = $request->has('verified_only');


        // Проверяем доступ к клубу
        if ($club && $validated['club_id'] != $club->id) {
            abort(403);
        }

        $tournament = Tournament::create($validated);
		// Убираем пустые названия кортов
		if (isset($validated['courts'])) {
			$validated['courts'] = array_map(function($court) {
				return $court ?: null;
			}, $validated['courts']);
			
			// Если все пустые - убираем совсем
			if (empty(array_filter($validated['courts']))) {
				$validated['courts'] = null;
			}
		}
		// Добавляем резервных игроков
		$reserveCount = $validated['reserve_count'] ?? 0;
		if ($reserveCount > 0) {
			$reserves = \App\Models\User::where('role', 'reserve')
				->orderBy('id')
				->get();

			if ($tournament->type === 'team') {
				// Для командных турниров создаём резервные пары
				$needed = $reserveCount * 2;
				$reservePairs = $reserves->take($needed);
				for ($i = 0; $i + 1 < $reservePairs->count(); $i += 2) {
					\App\Models\TournamentTeam::create([
						'tournament_id' => $tournament->id,
						'player1_id' => $reservePairs[$i]->id,
						'player2_id' => $reservePairs[$i + 1]->id,
						'status' => 'approved',
					]);
				}
			} else {
				// Для одиночных форматов — как раньше
				foreach ($reserves->take($reserveCount) as $reserve) {
					$tournament->participants()->attach($reserve->id, [
						'status' => 'registered',
					]);
				}
			}
		}

        return redirect()->route('club.tournaments.index')->with('success', 'Турнир создан!');
    }

	public function show(Tournament $tournament)
	{
		$club = $this->getClub();
		
		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		// Мексикано — отдельный контроллер
		if ($tournament->isMexicano()) {
			return app(\App\Http\Controllers\Club\MexicanoController::class)->show($tournament);
		}

		// Король корта — отдельный контроллер
		if ($tournament->isKingOfCourt()) {
			return app(\App\Http\Controllers\Club\KingOfCourtController::class)->show($tournament);
		}

		// Король Корта (Bali Format) — отдельный контроллер
		if ($tournament->isBaliKoc()) {
			return app(\App\Http\Controllers\Club\BaliKocController::class)->show($tournament);
		}

		// Round Robin — отдельный контроллер
		if ($tournament->isRoundRobin()) {
			return app(\App\Http\Controllers\Club\RoundRobinController::class)->show($tournament);
		}

		$tournament->load(['club', 'participants']);
		return view('club.tournaments.show', compact('tournament'));
	}

    public function edit(Tournament $tournament)
    {
		$user = auth()->user();
		if ($user->isClubModerator()) {
			$accessClub = $this->getClub();
			if (!$accessClub || !$user->hasTournamentsFullAccess($accessClub)) {
				abort(403, 'У вас нет прав на это действие. Обратитесь к админу клуба.');
			}
		}
        $club = $this->getClub();
        
        if ($club && $tournament->club_id != $club->id) {
            abort(403);
        }

        $clubs = auth()->user()->isSuperAdmin() ? Club::active()->get() : collect([$club]);

        return view('club.tournaments.edit', compact('tournament', 'clubs', 'club'));
    }

    public function update(Request $request, Tournament $tournament)
	{
		$user = auth()->user();
		if ($user->isClubModerator()) {
			$accessClub = $this->getClub();
			if (!$accessClub || !$user->hasTournamentsFullAccess($accessClub)) {
				abort(403, 'У вас нет прав на это действие. Обратитесь к админу клуба.');
			}
		}
		$club = $this->getClub();
		
		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}
		$validated = $request->validate([
			'name' => 'required|string|max:255',
			'description' => 'nullable|string',
			'start_date' => 'required|date',
			'min_level' => 'required|numeric|min:1|max:5.75',
			'max_level' => 'required|numeric|min:1|max:5.75|gte:min_level',
			'max_participants' => 'required|integer|min:2|max:128',
			'price' => 'nullable|numeric|min:0',
			'status' => 'required|in:draft,open,closed,in_progress,completed,cancelled',
			'points_to_win' => 'nullable|integer|in:16,21,24,32,42',
			'has_playoff' => 'nullable|boolean',
			'has_lower_bracket' => 'nullable|boolean',
			'has_bronze_match' => 'nullable|boolean',
			'playoff_type' => 'nullable|in:final_only,semifinal_final',
			'playoff_format' => 'nullable|in:mix,group_vs,tops,cross,balanced',
			'waitlist_size' => 'nullable|integer|min:0|max:32',
			'moderation_hours' => 'nullable|integer|min:0|max:720',
			'moderation_minutes' => 'nullable|integer|min:0|max:1440',
		]);
		
		// Только для верифицированных игроков
		$validated['verified_only'] = $request->has('verified_only');

		// Обработка чекбоксов плей-офф
		$validated['has_playoff'] = $request->has('has_playoff');
		$validated['has_lower_bracket'] = $request->has('has_lower_bracket');
		$validated['has_bronze_match'] = $request->has('has_bronze_match');

		// Если плей-офф не включен, убираем тип, формат и сетки
		if (!$validated['has_playoff']) {
			$validated['playoff_type'] = null;
			$validated['playoff_format'] = null;
			$validated['has_lower_bracket'] = false;
			$validated['has_bronze_match'] = false;
		}
		
		// Если не полуфинал+финал, убираем формат
		if (($validated['playoff_type'] ?? null) !== 'semifinal_final') {
			$validated['playoff_format'] = null;
		}
		
		$tournament->update($validated);
		return redirect()->route('club.tournaments.index')->with('success', 'Турнир обновлён!');
	}

    public function destroy(Tournament $tournament)
    {
		$user = auth()->user();
		if ($user->isClubModerator()) {
			$accessClub = $this->getClub();
			if (!$accessClub || !$user->hasTournamentsFullAccess($accessClub)) {
				abort(403, 'У вас нет прав на это действие. Обратитесь к админу клуба.');
			}
		}
        $club = $this->getClub();
        
        if ($club && $tournament->club_id != $club->id) {
            abort(403);
        }

        // Можно удалить только черновик
        if ($tournament->status !== 'draft') {
            return back()->with('error', 'Можно удалить только черновик');
        }

        $tournament->delete();

        return redirect()->route('club.tournaments.index')->with('success', 'Турнир удалён!');
    }

    // Удалить участника
	public function removeParticipant(Tournament $tournament, $userId)
	{
		$club = $this->getClub();
    
		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		// Блокируем если группы уже сформированы (для Американо)
		if ($tournament->isAmericano() && $tournament->groups()->count() > 0) {
			return back()->with('error', 'Группы уже сформированы. Используйте редактор групп.');
		}

		// Запоминаем был ли участник в основном составе
		$participant = $tournament->participants()->where('user_id', $userId)->first();
		$wasMain = $participant ? in_array($participant->pivot->status, ['registered', 'pending'], true) : false;

		// Проверяем был ли турнир полным ДО удаления
		$takenSlots = $tournament->participants()
			->wherePivotIn('status', ['registered', 'pending'])
			->count();
		$wasFull = $takenSlots >= $tournament->max_participants;

		$tournament->participants()->detach($userId);

		// Подтягиваем из листа ожидания, если ушёл человек из основного состава
		$promoted = null;
		if ($wasMain && $tournament->status === 'open') {
			$promoted = MobileTournamentController::promoteNextFromWaitlist($tournament);
		}

		// Если турнир был полным и из waitlist никого не подтянули — оповещаем подписчиков
		if ($wasFull && $tournament->status === 'open' && !$promoted) {
			$channelService = new \App\Services\TelegramChannelService($tournament->club);
			if ($channelService->isConfigured()) {
				$channelService->postSlotAvailable($tournament);
			}
			MobileTournamentController::notifySubscribersSlotAvailable($tournament);
		}

		return back()->with('success', 'Участник удалён');
	}
	/**
	 * Запустить турнир Американо
	 */
	public function start(Tournament $tournament, \App\Services\AmericanoService $americanoService, \App\Services\MexicanoService $mexicanoService, \App\Services\TeamTournamentService $teamTournamentService, \App\Services\KingOfCourtService $kingOfCourtService, \App\Services\BaliKocService $baliKocService, \App\Services\RoundRobinService $roundRobinService)
	{
		$club = $this->getClub();

		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		if ($tournament->isAmericano()) {
			$result = $americanoService->startTournament($tournament);
		} elseif ($tournament->isMexicano()) {
			$result = $mexicanoService->startTournament($tournament);
		} elseif ($tournament->isTeamBased()) {
			$result = $teamTournamentService->startTournament($tournament);
		} elseif ($tournament->isKingOfCourt()) {
			$result = $kingOfCourtService->startTournament($tournament);
		} elseif ($tournament->isRoundRobin()) {
			$result = $roundRobinService->startTournament($tournament);
		} elseif ($tournament->isBaliKoc()) {
			if (!$baliKocService->arePairsCreated($tournament)) {
				return redirect()->route('club.bali-koc.pairs', $tournament)
					->with('error', 'Сначала создайте пары');
			}
			$result = $baliKocService->startTournament($tournament);
		} else {
			return back()->with('error', 'Неизвестный тип турнира');
		}

		if ($result) {
			return redirect()->route('club.tournaments.show', $tournament)
							->with('success', 'Турнир начался!');
		}

		return back()->with('error', 'Не удалось начать турнир. Проверьте количество участников/пар.');
	}

	/**
	 * Экран ручного распределения пар по группам (только для team-турниров)
	 */
	public function distribute(Tournament $tournament)
	{
		$club = $this->getClub();
		if ($club && $tournament->club_id != $club->id) abort(403);

		if (!$tournament->isTeamBased()) {
			return redirect()->route('club.tournaments.show', $tournament)
				->with('error', 'Ручное распределение доступно только для командных турниров');
		}

		if ($tournament->status !== 'open') {
			return redirect()->route('club.tournaments.show', $tournament)
				->with('error', 'Турнир уже запущен или завершён');
		}

		$teams = $tournament->teams()
			->with(['player1', 'player2'])
			->orderBy('rating_avg', 'desc')
			->get();
		$maxTeams = $tournament->max_participants / 2;
		if ($teams->count() !== $maxTeams) {
			return redirect()->route('club.tournaments.show', $tournament)
				->with('error', "Зарегистрировано {$teams->count()} пар из {$maxTeams}");
		}

		$groupsCount = (int) $tournament->groups_count;
		$perGroup = $maxTeams / $groupsCount;

		$groupNames = [];
		for ($i = 0; $i < $groupsCount; $i++) {
			$groupNames[] = 'Группа ' . chr(65 + $i);
		}

		return view('club.tournaments.distribute', compact('tournament', 'teams', 'groupsCount', 'groupNames', 'perGroup'));
	}

	/**
	 * Старт турнира с ручным распределением
	 */
	public function startWithGroups(Request $request, Tournament $tournament, \App\Services\TeamTournamentService $teamTournamentService)
	{
		$club = $this->getClub();
		if ($club && $tournament->club_id != $club->id) abort(403);

		if (!$tournament->isTeamBased()) {
			return back()->with('error', 'Доступно только для командных турниров');
		}

		// assignments[team_id] = group_index
		$assignments = $request->input('assignments', []);
		if (!is_array($assignments)) $assignments = [];

		// Приводим ключи и значения к int
		$normalized = [];
		foreach ($assignments as $teamId => $groupIdx) {
			$normalized[(int) $teamId] = (int) $groupIdx;
		}

		[$success, $message] = $teamTournamentService->startTournamentWithAssignments($tournament, $normalized);

		if ($success) {
			return redirect()->route('club.tournaments.show', $tournament)->with('success', $message);
		}
		return back()->with('error', $message)->withInput();
	}

	/**
	 * Добавить тестовых игроков
	 */
	public function addTestPlayers(Tournament $tournament)
	{
		$club = $this->getClub();
		
		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		if ($tournament->status !== 'open') {
			return back()->with('error', 'Турнир не открыт для регистрации');
		}

		$currentCount = $tournament->participants()->count();
		$needed = $tournament->max_participants - $currentCount;

		if ($needed <= 0) {
			return back()->with('error', 'Турнир уже заполнен');
		}

		// Берём только ТЕСТОВЫХ игроков (email: 1@gmail.com, 2@gmail.com, ...)
		$existingIds = $tournament->participants()->pluck('users.id')->toArray();

		$players = \App\Models\User::where('role', 'player')
			->whereNotIn('id', $existingIds)
			->whereRaw("email REGEXP '^[0-9]+@gmail\\.com$'")
			->inRandomOrder()
			->limit($needed)
			->get();

		foreach ($players as $player) {
			$tournament->participants()->attach($player->id, ['status' => 'registered']);
		}

		return back()->with('success', 'Добавлено ' . $players->count() . ' игроков');
	}
	/**
	 * Завершить турнир и начислить рейтинг
	 */
	public function finish(Tournament $tournament, \App\Services\AmericanoService $americanoService, \App\Services\MexicanoService $mexicanoService, \App\Services\TeamTournamentService $teamTournamentService, \App\Services\KingOfCourtService $kingOfCourtService, \App\Services\BaliKocService $baliKocService, \App\Services\RoundRobinService $roundRobinService)
	{
		$club = $this->getClub();

		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		if ($tournament->isAmericano()) {
			if (!$americanoService->canFinishTournament($tournament)) {
				return back()->with('error', 'Не все матчи сыграны');
			}
			$result = $americanoService->finishTournament($tournament);
		} elseif ($tournament->isMexicano()) {
			if (!$mexicanoService->canFinishTournament($tournament)) {
				return back()->with('error', 'Не все раунды сыграны');
			}
			$result = $mexicanoService->finishTournament($tournament);
		} elseif ($tournament->isTeamBased()) {
			if (!$teamTournamentService->canFinishTournament($tournament)) {
				return back()->with('error', 'Финал не сыгран');
			}
			$result = $teamTournamentService->finishTournament($tournament);
		} elseif ($tournament->isKingOfCourt()) {
			if (!$kingOfCourtService->canFinishTournament($tournament)) {
				return back()->with('error', 'Доиграйте текущий раунд');
			}
			$result = $kingOfCourtService->finishTournament($tournament);
		} elseif ($tournament->isRoundRobin()) {
			if (!$roundRobinService->canFinishTournament($tournament)) {
				return back()->with('error', 'Доиграйте текущий раунд');
			}
			$result = $roundRobinService->finishTournament($tournament);
		} elseif ($tournament->isBaliKoc()) {
			if (!$baliKocService->canFinishTournament($tournament)) {
				return back()->with('error', 'Доиграйте текущий раунд');
			}
			$result = $baliKocService->finishTournament($tournament);
		} else {
			return back()->with('error', 'Неизвестный тип турнира');
		}

		if ($result) {
			// Триггер #5 верификации — пересчитать level_verified у участников
			$tournament->recalculateParticipantsVerification(auth()->id(), $tournament->club_id);
			return redirect()->route('club.tournaments.show', $tournament)
							->with('success', 'Турнир завершён!');
		}

		return back()->with('error', 'Ошибка завершения турнира');
	}
	/**
	 * Сохранить счёт матча плей-офф
	 */
	public function savePlayoffScore(Request $request, TournamentPlayoffMatch $match, TeamTournamentService $service)
	{
		$validated = $request->validate([
			'team1_score' => 'required|integer|min:0',
			'team2_score' => 'required|integer|min:0|different:team1_score',
		]);

		$service->savePlayoffMatchResult($match, $validated['team1_score'], $validated['team2_score']);

		return back()->with('success', 'Счёт сохранён!');
	}

	/**
	 * Обновить счёт матча плей-офф
	 */
	public function updatePlayoffScore(Request $request, TournamentPlayoffMatch $match, TeamTournamentService $service)
	{
		$validated = $request->validate([
			'team1_score' => 'required|integer|min:0',
			'team2_score' => 'required|integer|min:0|different:team1_score',
		]);

		$service->savePlayoffMatchResult($match, $validated['team1_score'], $validated['team2_score']);

		return back()->with('success', 'Счёт обновлён!');
	}
	/**
	 * Одобрить заявку участника
	 */
	public function approveParticipant(Tournament $tournament, $userId)
	{
		$club = $this->getClub();
		
		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		if ($tournament->status !== 'open') {
			return back()->with('error', 'Турнир не открыт для регистрации');
		}

		$participant = $tournament->participants()->where('user_id', $userId)->first();
		
		if (!$participant) {
			return back()->with('error', 'Участник не найден');
		}

		if ($participant->pivot->status !== 'pending') {
			return back()->with('error', 'Заявка уже обработана');
		}

		// Атомарная проверка лимита + одобрение (защита от race condition)
		$approved = DB::transaction(function () use ($tournament, $userId) {
			Tournament::where('id', $tournament->id)->lockForUpdate()->first();

			$approvedCount = $tournament->participants()->wherePivot('status', 'registered')->count();
			if ($approvedCount >= $tournament->max_participants) {
				return false;
			}

			$tournament->participants()->updateExistingPivot($userId, [
				'status' => 'registered',
				'moderation_deadline' => null,
				'reminder_sent_at' => null,
			]);
			return true;
		});

		if (!$approved) {
			return back()->with('error', 'Достигнут лимит участников');
		}

		// Отправляем уведомления
		$user = \App\Models\User::find($userId);
		if ($user) {
			$notificationService = new \App\Services\TelegramNotificationService($tournament->club);
			$notificationService->notifyRegistrationApproved($user, $tournament);

			// FCM push
			$date = $tournament->start_date->format('d.m.Y H:i');
			$title = 'Заявка одобрена!';
			$body = "Вы записаны на турнир «{$tournament->name}» — {$date}";

			\App\Models\Notification::create([
				'user_id' => $user->id,
				'title' => $title,
				'body' => $body,
				'type' => 'registration_approved',
				'category' => 'tournament',
				'data' => ['tournament_id' => $tournament->id],
			]);

			$fcm = app(\App\Services\FCMNotificationService::class);
			$fcm->sendToUser($user, $title, $body, [
				'type' => 'registration_approved',
				'tournament_id' => (string) $tournament->id,
			]);
		}

		return back()->with('success', 'Заявка одобрена!');
	}

	/**
	 * Отклонить заявку участника
	 */
	public function rejectParticipant(Tournament $tournament, $userId)
	{
		$club = $this->getClub();
		
		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		// Получаем пользователя до удаления
		$user = \App\Models\User::find($userId);

		// Запоминаем был ли участник в основном составе (а не в waitlist)
		$participant = $tournament->participants()->where('user_id', $userId)->first();
		$wasMain = $participant ? in_array($participant->pivot->status, ['registered', 'pending'], true) : false;

		// Проверяем был ли турнир полным ДО удаления
		$takenSlots = $tournament->participants()
			->wherePivotIn('status', ['registered', 'pending'])
			->count();
		$wasFull = $takenSlots >= $tournament->max_participants;

		$tournament->participants()->detach($userId);

		// Подтягиваем из waitlist, если был в основном составе
		$promoted = null;
		if ($wasMain && $tournament->status === 'open') {
			$promoted = MobileTournamentController::promoteNextFromWaitlist($tournament);
		}

		// Отправляем уведомление об отклонении
		if ($user) {
			$notificationService = new \App\Services\TelegramNotificationService($tournament->club);
			$notificationService->notifyRegistrationRejected($user, $tournament);

			// FCM push
			$title = 'Заявка отклонена';
			$body = "К сожалению, ваша заявка на турнир «{$tournament->name}» была отклонена";

			\App\Models\Notification::create([
				'user_id' => $user->id,
				'title' => $title,
				'body' => $body,
				'type' => 'registration_rejected',
				'category' => 'tournament',
				'data' => ['tournament_id' => $tournament->id],
			]);

			$fcm = app(\App\Services\FCMNotificationService::class);
			$fcm->sendToUser($user, $title, $body, [
				'type' => 'registration_rejected',
				'tournament_id' => (string) $tournament->id,
			]);
		}

		// Если турнир был полным и никого не подтянули из waitlist — оповещаем подписчиков
		if ($wasFull && $tournament->status === 'open' && !$promoted) {
			$channelService = new \App\Services\TelegramChannelService($tournament->club);
			if ($channelService->isConfigured()) {
				$channelService->postSlotAvailable($tournament);
			}
			MobileTournamentController::notifySubscribersSlotAvailable($tournament);
		}

		return back()->with('success', 'Заявка отклонена');
	}

	/**
	 * Одобрить все заявки
	 */
	public function approveAllParticipants(Tournament $tournament)
	{
		$club = $this->getClub();
		
		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		if ($tournament->status !== 'open') {
			return back()->with('error', 'Турнир не открыт для регистрации');
		}

		$approvedCount = $tournament->participants()->wherePivot('status', 'registered')->count();
		$availableSlots = $tournament->max_participants - $approvedCount;

		if ($availableSlots <= 0) {
			return back()->with('error', 'Нет свободных мест');
		}

		// Получаем pending заявки
		$pendingParticipants = $tournament->participants()
			->wherePivot('status', 'pending')
			->limit($availableSlots)
			->get();

		$pendingIds = $pendingParticipants->pluck('id')->toArray();

		// Одобряем
		$tournament->participants()
			->wherePivot('status', 'pending')
			->whereIn('user_id', $pendingIds)
			->update(['tournament_participants.status' => 'registered']);

		// Отправляем уведомления всем одобренным
		$notificationService = new \App\Services\TelegramNotificationService($tournament->club);
		$fcm = app(\App\Services\FCMNotificationService::class);
		$date = $tournament->start_date->format('d.m.Y H:i');

		foreach ($pendingParticipants as $user) {
			$notificationService->notifyRegistrationApproved($user, $tournament);

			// FCM push
			$title = 'Заявка одобрена!';
			$body = "Вы записаны на турнир «{$tournament->name}» — {$date}";

			\App\Models\Notification::create([
				'user_id' => $user->id,
				'title' => $title,
				'body' => $body,
				'type' => 'registration_approved',
				'category' => 'tournament',
				'data' => ['tournament_id' => $tournament->id],
			]);

			$fcm->sendToUser($user, $title, $body, [
				'type' => 'registration_approved',
				'tournament_id' => (string) $tournament->id,
			]);
		}

		return back()->with('success', 'Одобрено заявок: ' . count($pendingIds));
	}
	/**
	 * Поиск игроков по телефону
	 */
	public function searchPlayers(Request $request, Tournament $tournament)
	{
		$query = $request->get('q', '');
		
		if (strlen($query) < 3) {
			return response()->json([]);
		}
		
		// Получаем ID уже добавленных участников
		$existingIds = $tournament->participants()->pluck('user_id')->toArray();
		
		$players = \App\Models\User::human()
			->where(function ($q) use ($query) {
				$q->where('phone', 'LIKE', "%{$query}%")
				  ->orWhere('name', 'LIKE', "%{$query}%");
			})
			->whereNotIn('id', $existingIds)
			->limit(10)
			->get(['id', 'name', 'phone', 'level', 'rating']);
		
		return response()->json($players->map(function ($player) {
			return [
				'id' => $player->id,
				'name' => $player->name,
				'phone' => PhoneVisibility::forExport($player->phone),
				'level' => $player->level,
				'rating' => $player->rating,
			];
		}));
	}

	/**
	 * Добавить участника вручную
	 */
	public function addParticipant(Request $request, Tournament $tournament)
	{
		// Блокируем если группы уже сформированы (для Американо)
		if ($tournament->isAmericano() && $tournament->groups()->count() > 0) {
			return back()->with('error', 'Группы уже сформированы. Используйте редактор групп.');
		}

		$validated = $request->validate([
			'user_id' => 'required|exists:users,id',
		]);
		
		// Атомарная проверка + добавление (защита от race condition)
		$user = \App\Models\User::find($validated['user_id']);

		$added = DB::transaction(function () use ($tournament, $validated) {
			Tournament::where('id', $tournament->id)->lockForUpdate()->first();

			$exists = $tournament->participants()->where('user_id', $validated['user_id'])->exists();
			if ($exists) {
				return 'exists';
			}

			if ($tournament->takenSlotsCount() >= $tournament->max_participants) {
				return 'full';
			}

			$tournament->participants()->attach($validated['user_id'], [
				'status' => 'registered',
			]);
			return 'ok';
		});

		if ($added === 'exists') {
			return back()->with('error', 'Игрок уже добавлен в турнир');
		}
		if ($added === 'full') {
			return back()->with('error', 'Достигнут лимит участников');
		}

		return back()->with('success', "Игрок {$user->full_name} добавлен!");
	}

	/**
	 * Заменить участника
	 */
	public function replaceParticipant(Request $request, Tournament $tournament, $userId)
	{
		// Блокируем если группы уже сформированы (для Американо)
		if ($tournament->isAmericano() && $tournament->groups()->count() > 0) {
			return back()->with('error', 'Группы уже сформированы. Используйте редактор групп.');
		}

		$validated = $request->validate([
			'new_user_id' => 'required|exists:users,id',
		]);
		
		// Проверяем что новый игрок ещё не добавлен
		$exists = $tournament->participants()->where('user_id', $validated['new_user_id'])->exists();
		if ($exists) {
			return back()->with('error', 'Этот игрок уже участвует в турнире');
		}
		
		// Удаляем старого участника
		$tournament->participants()->detach($userId);
		
		// Добавляем нового
		$tournament->participants()->attach($validated['new_user_id'], [
			'status' => 'registered',
		]);
		
		$newUser = \App\Models\User::find($validated['new_user_id']);
		
		return back()->with('success', "Участник заменён на {$newUser->full_name}!");
	}
	/**
	 * Отправить push-уведомление о турнире
	 */
	public function sendPush(Tournament $tournament)
	{
		$club = $this->getClub();

		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		// Для тестовых клубов push не рассылается — иначе они засветятся
		// у обычных пользователей.
		$tournament->loadMissing('club');
		if ($tournament->club && $tournament->club->is_test) {
			return back()->with('error', 'Push не отправляется для тестовых клубов');
		}

		// Единая логика рассылки (общая с мобильным API)
		$result = app(\App\Services\TournamentPushService::class)->send($tournament);
		$total = $result['total'];
		$sent = $result['sent'];
		$filtered = $result['filtered'];

		$cityLabel = ($club && $club->city) ? ", город: {$club->city}" : "";
		return back()->with('success', "Push отправлен ({$sent} из {$total} пользователей{$cityLabel}" . ($filtered ? ", {$filtered} отфильтровано по настройкам" : "") . ")");
	}

	/**
	 * Превью push — показать кому отправится, без реальной отправки
	 */
	public function pushPreview(Tournament $tournament)
	{
		$club = $this->getClub();
		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		$query = \App\Models\User::whereHas('deviceTokens');

		if ($club && $club->city) {
			if ($club->city === 'Алматы') {
				$query->where(fn($q) => $q->where('city', 'Алматы')->orWhereNull('city'));
			} else {
				$query->where('city', $club->city);
			}
		}

		$users = $query->get(['id', 'name', 'city', 'level', 'notify_only_my_level']);

		$recipients = $users->filter(function ($user) use ($tournament) {
			if (!$user->notify_only_my_level) return true;
			return $user->level >= $tournament->min_level && $user->level <= $tournament->max_level;
		});

		$filtered = $users->diff($recipients);
		$cityLabel = ($club && $club->city) ? " (город: {$club->city})" : "";

		return back()->with('success',
			"Превью push{$cityLabel}: получат {$recipients->count()} из {$users->count()} пользователей. " .
			"Отфильтровано по уровню: {$filtered->count()}." .
			($filtered->count() > 0 ? " Отфильтрованы: " . $filtered->map(fn($u) => "{$u->name} (L{$u->level})")->implode(', ') : "")
		);
	}

	/**
	 * Опубликовать турнир в Telegram канал
	 */
	public function publishToChannel(Tournament $tournament)
	{
		$club = $this->getClub();
		
		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		$service = new \App\Services\TelegramChannelService($tournament->club);

		if (!$service->isConfigured()) {
			return back()->with('error', 'Telegram канал не настроен для этого клуба');
		}

		$result = $service->postTournament($tournament);

		if ($result) {
			return back()->with('success', 'Турнир опубликован в канал!');
		}

		return back()->with('error', 'Ошибка публикации в канал');
	}
	/**
	 * Отменить турнир
	 */
	public function cancel(Tournament $tournament)
	{
		$user = auth()->user();

		if (!$tournament->isRegistered($user)) {
			return back()->with('error', 'Вы не зарегистрированы на этот турнир');
		}

		// Можно отменить только пока турнир не начался
		if ($tournament->status === 'in_progress' || $tournament->status === 'completed') {
			return back()->with('error', 'Нельзя отменить регистрацию после начала турнира');
		}

		// Проверяем был ли турнир полным ДО удаления
		$takenSlots = $tournament->participants()
			->wherePivotIn('status', ['registered', 'pending'])
			->count();
		$wasFull = $takenSlots >= $tournament->max_participants;

		$tournament->participants()->detach($user->id);

		// Если турнир был полным — уведомляем в канал и подписчиков
		if ($wasFull && $tournament->status === 'open') {
			$channelService = new \App\Services\TelegramChannelService($tournament->club);
			if ($channelService->isConfigured()) {
				$channelService->postSlotAvailable($tournament);
			}
			MobileTournamentController::notifySubscribersSlotAvailable($tournament);
		}

		return back()->with('success', 'Регистрация отменена');
	}
	
}
<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\Club;
use Illuminate\Http\Request;
use App\Services\AmericanoService;
use App\Models\TournamentPlayoffMatch;
use App\Models\TournamentParticipant;


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
			
			// Модератор видит только открытые турниры
			if ($user->isClubModerator()) {
				$query->where('status', 'open');
			}
			
			$tournaments = $query->orderBy('created_at', 'desc')->get();
		} else {
			$tournaments = Tournament::with('club')
				->orderBy('created_at', 'desc')
				->get();
		}

		return view('club.tournaments.index', compact('tournaments', 'club'));
	}

    public function create()
    {
		if (auth()->user()->isClubModerator()) {
			abort(403, 'Модераторам недоступно создание турниров');
		}
        $club = $this->getClub();
        $clubs = auth()->user()->isSuperAdmin() ? Club::active()->get() : collect([$club]);
        
        return view('club.tournaments.create', compact('clubs', 'club'));
    }

    public function store(Request $request)
    {
		if (auth()->user()->isClubModerator()) {
			abort(403, 'Модераторам недоступно создание турниров');
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
			'type' => 'required|in:classic,americano,mexicano,team',
			'points_to_win' => 'nullable|integer|in:16,21,24,32,42',
			'groups_count' => 'nullable|integer|in:1,2,4',
			'rounds_count' => 'nullable|integer|min:3|max:30',
			'teams_advance' => 'nullable|integer|in:1,2,3,4',
			'has_playoff' => 'nullable|boolean',
			'playoff_type' => 'nullable|in:final_only,semifinal_final',
			'playoff_format' => 'nullable|in:mix,group_vs,tops,cross,balanced',
			'reserve_count' => 'nullable|integer|min:0|max:10',
			'courts' => 'nullable|array',
			'courts.*' => 'nullable|string|max:50',
		]);


		$validated['has_playoff'] = $request->has('has_playoff');
		// Если плей-офф не включен, убираем тип и формат
		if (!$validated['has_playoff']) {
			$validated['playoff_type'] = null;
			$validated['playoff_format'] = null;
		}


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
				->limit($reserveCount)
				->get();
			
			foreach ($reserves as $reserve) {
				$tournament->participants()->attach($reserve->id, [
					'status' => 'registered',
				]);
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

		$tournament->load(['club', 'participants']);
		return view('club.tournaments.show', compact('tournament'));
	}

    public function edit(Tournament $tournament)
    {
		if (auth()->user()->isClubModerator()) {
			abort(403, 'Модераторам недоступно создание турниров');
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
		if (auth()->user()->isClubModerator()) {
			abort(403, 'Модераторам недоступно создание турниров');
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
			'playoff_type' => 'nullable|in:final_only,semifinal_final',
			'playoff_format' => 'nullable|in:mix,group_vs,tops,cross',
		]);
		
		// Обработка чекбокса плей-офф
		$validated['has_playoff'] = $request->has('has_playoff');
		
		// Если плей-офф не включен, убираем тип и формат
		if (!$validated['has_playoff']) {
			$validated['playoff_type'] = null;
			$validated['playoff_format'] = null;
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
		if (auth()->user()->isClubModerator()) {
			abort(403, 'Модераторам недоступно создание турниров');
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

		// Проверяем был ли турнир полным ДО удаления
		$takenSlots = $tournament->participants()
			->wherePivotIn('status', ['registered', 'pending'])
			->count();
		$wasFull = $takenSlots >= $tournament->max_participants;

		$tournament->participants()->detach($userId);

		// Если турнир был полным и открыт — уведомляем в канал
		if ($wasFull && $tournament->status === 'open') {
			$channelService = new \App\Services\TelegramChannelService();
			$channelService->postSlotAvailable($tournament);
		}

		return back()->with('success', 'Участник удалён');
	}
	/**
	 * Запустить турнир Американо
	 */
	public function start(Tournament $tournament, \App\Services\AmericanoService $americanoService, \App\Services\MexicanoService $mexicanoService, \App\Services\TeamTournamentService $teamTournamentService)
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
	public function finish(Tournament $tournament, \App\Services\AmericanoService $americanoService, \App\Services\MexicanoService $mexicanoService, \App\Services\TeamTournamentService $teamTournamentService)
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
		} else {
			return back()->with('error', 'Неизвестный тип турнира');
		}

		if ($result) {
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

		// Проверяем лимит одобренных участников
		$approvedCount = $tournament->participants()->wherePivot('status', 'registered')->count();
		if ($approvedCount >= $tournament->max_participants) {
			return back()->with('error', 'Достигнут лимит участников');
		}

		$tournament->participants()->updateExistingPivot($userId, ['status' => 'registered']);

		// Отправляем уведомление в Telegram
		$user = \App\Models\User::find($userId);
		if ($user) {
			$notificationService = new \App\Services\TelegramNotificationService();
			$notificationService->notifyRegistrationApproved($user, $tournament);
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

		// Проверяем был ли турнир полным ДО удаления
		$takenSlots = $tournament->participants()
			->wherePivotIn('status', ['registered', 'pending'])
			->count();
		$wasFull = $takenSlots >= $tournament->max_participants;
		
		$tournament->participants()->detach($userId);

		// Отправляем уведомление об отклонении
		if ($user) {
			$notificationService = new \App\Services\TelegramNotificationService();
			$notificationService->notifyRegistrationRejected($user, $tournament);
		}

		// Если турнир был полным — уведомляем в канал
		if ($wasFull && $tournament->status === 'open') {
			$channelService = new \App\Services\TelegramChannelService();
			$channelService->postSlotAvailable($tournament);
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
		$notificationService = new \App\Services\TelegramNotificationService();
		foreach ($pendingParticipants as $user) {
			$notificationService->notifyRegistrationApproved($user, $tournament);
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
		
		$players = \App\Models\User::where(function ($q) use ($query) {
				$q->where('phone', 'LIKE', "%{$query}%")
				  ->orWhere('name', 'LIKE', "%{$query}%");
			})
			->whereNotIn('id', $existingIds)
			->where('role', 'player')
			->limit(10)
			->get(['id', 'name', 'phone', 'level', 'rating']);
		
		return response()->json($players->map(function ($player) {
			return [
				'id' => $player->id,
				'name' => $player->name,
				'phone' => $player->phone,
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
		$validated = $request->validate([
			'user_id' => 'required|exists:users,id',
		]);
		
		// Проверяем что игрок ещё не добавлен
		$exists = $tournament->participants()->where('user_id', $validated['user_id'])->exists();
		if ($exists) {
			return back()->with('error', 'Игрок уже добавлен в турнир');
		}
		
		// Проверяем лимит участников
		if ($tournament->approvedParticipantsCount() >= $tournament->max_participants) {
			return back()->with('error', 'Достигнут лимит участников');
		}
		
		$user = \App\Models\User::find($validated['user_id']);
		
		// Добавляем сразу как одобренного
		$tournament->participants()->attach($validated['user_id'], [
			'status' => 'registered',
		]);
		
		return back()->with('success', "Игрок {$user->full_name} добавлен!");
	}

	/**
	 * Заменить участника
	 */
	public function replaceParticipant(Request $request, Tournament $tournament, $userId)
	{
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
	 * Опубликовать турнир в Telegram канал
	 */
	public function publishToChannel(Tournament $tournament)
	{
		$club = $this->getClub();
		
		if ($club && $tournament->club_id != $club->id) {
			abort(403);
		}

		$service = new \App\Services\TelegramChannelService();
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

		// Если турнир был полным — уведомляем в канал
		if ($wasFull && $tournament->status === 'open') {
			$channelService = new \App\Services\TelegramChannelService();
			$channelService->postSlotAvailable($tournament);
		}

		return back()->with('success', 'Регистрация отменена');
	}
	
}
<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\Club;
use Illuminate\Http\Request;
use App\Services\AmericanoService;
use App\Models\TournamentPlayoffMatch;



class TournamentController extends Controller
{
    // Получить клуб текущего админа
    private function getClub()
    {
        $user = auth()->user();
        
        if ($user->isSuperAdmin()) {
            return null; // Супер-админ видит все
        }
        
        return $user->adminClubs()->first();
    }

    public function index()
    {
        $club = $this->getClub();
        
        if ($club) {
            $tournaments = Tournament::where('club_id', $club->id)
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            $tournaments = Tournament::with('club')
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return view('club.tournaments.index', compact('tournaments', 'club'));
    }

    public function create()
    {
        $club = $this->getClub();
        $clubs = auth()->user()->isSuperAdmin() ? Club::active()->get() : collect([$club]);
        
        return view('club.tournaments.create', compact('clubs', 'club'));
    }

    public function store(Request $request)
    {
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
			'rounds_count' => 'nullable|integer|min:3|max:10',
			'teams_advance' => 'nullable|integer|in:1,2,3',
			'has_playoff' => 'nullable|boolean',
			'playoff_type' => 'nullable|in:final_only,semifinal_final',
		]);

		// Обработка чекбокса плей-офф
		$validated['has_playoff'] = $request->has('has_playoff');

		// Если плей-офф не включен, убираем тип
		if (!$validated['has_playoff']) {
			$validated['playoff_type'] = null;
		}

        // Проверяем доступ к клубу
        if ($club && $validated['club_id'] != $club->id) {
            abort(403);
        }

        Tournament::create($validated);

        return redirect()->route('club.tournaments.index')->with('success', 'Турнир создан!');
    }

    public function show(Tournament $tournament)
    {
        $club = $this->getClub();
        
        // Проверяем доступ
        if ($club && $tournament->club_id != $club->id) {
            abort(403);
        }

        $tournament->load(['club', 'participants']);

        return view('club.tournaments.show', compact('tournament'));
    }

    public function edit(Tournament $tournament)
    {
        $club = $this->getClub();
        
        if ($club && $tournament->club_id != $club->id) {
            abort(403);
        }

        $clubs = auth()->user()->isSuperAdmin() ? Club::active()->get() : collect([$club]);

        return view('club.tournaments.edit', compact('tournament', 'clubs', 'club'));
    }

    public function update(Request $request, Tournament $tournament)
	{
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
		]);

		// Обработка чекбокса плей-офф
		$validated['has_playoff'] = $request->has('has_playoff');
		
		// Если плей-офф не включен, убираем тип
		if (!$validated['has_playoff']) {
			$validated['playoff_type'] = null;
		}

		$tournament->update($validated);

		return redirect()->route('club.tournaments.index')->with('success', 'Турнир обновлён!');
	}

    public function destroy(Tournament $tournament)
    {
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

        $tournament->participants()->detach($userId);

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

		// Берём игроков которые ещё не в турнире
		$existingIds = $tournament->participants()->pluck('users.id')->toArray();
		
		$players = \App\Models\User::where('role', 'player')
			->whereNotIn('id', $existingIds)
			->orderBy('rating', 'desc')
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

        $tournament->participants()->detach($userId);

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

        // Одобряем заявки (сколько есть мест)
        $pendingIds = $tournament->participants()
            ->wherePivot('status', 'pending')
            ->limit($availableSlots)
            ->pluck('users.id');

        foreach ($pendingIds as $id) {
            $tournament->participants()->updateExistingPivot($id, ['status' => 'registered']);
        }

        return back()->with('success', "Одобрено заявок: {$pendingIds->count()}");
    }
}
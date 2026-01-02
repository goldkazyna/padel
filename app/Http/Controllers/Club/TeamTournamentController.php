<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\TournamentGroupMatch;
use App\Models\TournamentPlayoffMatch;
use App\Services\TeamTournamentService;


class TeamTournamentController extends Controller
{
    /**
     * Добавить пару в турнир
     */
    public function addTeam(Request $request, Tournament $tournament)
    {
        $validated = $request->validate([
            'player1_id' => 'required|exists:users,id',
            'player2_id' => 'required|exists:users,id|different:player1_id',
            'name' => 'nullable|string|max:255',
        ]);

        // Проверяем что турнир открыт
        if ($tournament->status !== 'open') {
            return back()->with('error', 'Турнир не открыт для регистрации');
        }

        // Проверяем лимит пар (max_participants / 2)
        $maxTeams = $tournament->max_participants / 2;
        if ($tournament->teams()->count() >= $maxTeams) {
            return back()->with('error', "Достигнут лимит пар ({$maxTeams})");
        }

        // Проверяем что игроки ещё не в турнире
        $existingPlayers = $tournament->teams()
            ->where(function($q) use ($validated) {
                $q->where('player1_id', $validated['player1_id'])
                  ->orWhere('player2_id', $validated['player1_id'])
                  ->orWhere('player1_id', $validated['player2_id'])
                  ->orWhere('player2_id', $validated['player2_id']);
            })
            ->exists();

        if ($existingPlayers) {
            return back()->with('error', 'Один или оба игрока уже зарегистрированы в турнире');
        }

        $player1 = User::find($validated['player1_id']);
        $player2 = User::find($validated['player2_id']);

        // Создаём пару
        TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $validated['player1_id'],
            'player2_id' => $validated['player2_id'],
            'name' => $validated['name'],
            'rating_avg' => intval(($player1->rating + $player2->rating) / 2),
        ]);

        return back()->with('success', "Пара {$player1->first_name} / {$player2->first_name} добавлена!");
    }

    /**
     * Удалить пару из турнира
     */
    public function removeTeam(Tournament $tournament, TournamentTeam $team)
    {
        if ($tournament->status !== 'open') {
            return back()->with('error', 'Турнир уже начат');
        }

        if ($team->tournament_id !== $tournament->id) {
            abort(403);
        }

        $team->delete();

        return back()->with('success', 'Пара удалена из турнира');
    }

    /**
     * Добавить тестовые пары
     */
    public function addTestTeams(Tournament $tournament)
    {
        if ($tournament->status !== 'open') {
            return back()->with('error', 'Турнир не открыт для регистрации');
        }

        $maxTeams = $tournament->max_participants / 2;
        $currentTeams = $tournament->teams()->count();
        $neededTeams = $maxTeams - $currentTeams;

        if ($neededTeams <= 0) {
            return back()->with('error', 'Турнир уже заполнен');
        }

        // Получаем игроков которые ещё не в турнире
        $existingPlayerIds = $tournament->teams()
            ->get()
            ->flatMap(fn($team) => [$team->player1_id, $team->player2_id])
            ->toArray();

        $availablePlayers = User::where('role', 'player')
            ->whereNotIn('id', $existingPlayerIds)
            ->orderBy('rating', 'desc')
            ->limit($neededTeams * 2)
            ->get();

        if ($availablePlayers->count() < 2) {
            return back()->with('error', 'Недостаточно свободных игроков');
        }

        $teamsAdded = 0;
        for ($i = 0; $i < $availablePlayers->count() - 1; $i += 2) {
            $player1 = $availablePlayers[$i];
            $player2 = $availablePlayers[$i + 1];

            TournamentTeam::create([
                'tournament_id' => $tournament->id,
                'player1_id' => $player1->id,
                'player2_id' => $player2->id,
                'rating_avg' => intval(($player1->rating + $player2->rating) / 2),
            ]);

            $teamsAdded++;
            if ($teamsAdded >= $neededTeams) break;
        }

        return back()->with('success', "Добавлено {$teamsAdded} тестовых пар!");
    }

    /**
     * Поиск игрока для добавления в пару
     */
    public function searchPlayer(Request $request)
    {
        $query = $request->get('q');
        
        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $players = User::where('role', 'player')
            ->where(function($q) use ($query) {
                $q->where('email', 'like', "%{$query}%")
                  ->orWhere('first_name', 'like', "%{$query}%")
                  ->orWhere('last_name', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'email', 'rating', 'level']);

        return response()->json($players);
    }
	/**
	 * Сохранить счёт матча группового этапа
	 */
	public function saveGroupMatchScore(Request $request, TournamentGroupMatch $match, TeamTournamentService $service)
	{
		$validated = $request->validate([
			'team1_score' => 'required|integer|min:0',
			'team2_score' => 'required|integer|min:0',
		]);

		$service->saveGroupMatchResult($match, $validated['team1_score'], $validated['team2_score']);

		return back()->with('success', 'Счёт сохранён!');
	}

	/**
	 * Обновить счёт матча группового этапа
	 */
	public function updateGroupMatchScore(Request $request, TournamentGroupMatch $match, TeamTournamentService $service)
	{
		$validated = $request->validate([
			'team1_score' => 'required|integer|min:0',
			'team2_score' => 'required|integer|min:0',
		]);

		$service->saveGroupMatchResult($match, $validated['team1_score'], $validated['team2_score']);

		return back()->with('success', 'Счёт обновлён!');
	}

	/**
	 * Сгенерировать плей-офф
	 */
	public function generatePlayoff(Tournament $tournament, TeamTournamentService $service)
	{
		if (!$service->isGroupStageCompleted($tournament)) {
			return back()->with('error', 'Групповой этап не завершён');
		}

		$result = $service->generatePlayoff($tournament);

		if ($result) {
			return back()->with('success', 'Плей-офф сгенерирован!');
		}

		return back()->with('error', 'Ошибка генерации плей-офф');
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
	
}
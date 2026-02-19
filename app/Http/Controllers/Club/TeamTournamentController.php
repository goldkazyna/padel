<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $player1 = User::find($validated['player1_id']);
        $player2 = User::find($validated['player2_id']);

        // Атомарная проверка лимита + создание (защита от race condition)
        $result = DB::transaction(function () use ($tournament, $validated, $player1, $player2) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            $maxTeams = $tournament->max_participants / 2;
            if ($tournament->teams()->count() >= $maxTeams) {
                return 'full';
            }

            $existingPlayers = $tournament->teams()
                ->where(function($q) use ($validated) {
                    $q->where('player1_id', $validated['player1_id'])
                      ->orWhere('player2_id', $validated['player1_id'])
                      ->orWhere('player1_id', $validated['player2_id'])
                      ->orWhere('player2_id', $validated['player2_id']);
                })
                ->exists();

            if ($existingPlayers) {
                return 'exists';
            }

            TournamentTeam::create([
                'tournament_id' => $tournament->id,
                'player1_id' => $validated['player1_id'],
                'player2_id' => $validated['player2_id'],
                'name' => $validated['name'],
                'rating_avg' => intval(($player1->rating + $player2->rating) / 2),
            ]);
            return 'ok';
        });

        $maxTeams = $tournament->max_participants / 2;
        if ($result === 'full') {
            return back()->with('error', "Достигнут лимит пар ({$maxTeams})");
        }
        if ($result === 'exists') {
            return back()->with('error', 'Один или оба игрока уже зарегистрированы в турнире');
        }

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
		$teamsToAdd = $maxTeams - $currentTeams;

		if ($teamsToAdd <= 0) {
			return back()->with('error', 'Все места заняты');
		}

		// Получаем ID игроков которые уже в парах
		$playersInTeams = $tournament->teams()
			->get()
			->flatMap(fn($team) => [$team->player1_id, $team->player2_id])
			->toArray();

		// Берём тестовых пользователей по email (1@gmail.com - 16@gmail.com)
		$testPlayers = User::whereIn('email', [
			'1@gmail.com', '2@gmail.com', '3@gmail.com', '4@gmail.com',
			'5@gmail.com', '6@gmail.com', '7@gmail.com', '8@gmail.com',
			'9@gmail.com', '10@gmail.com', '11@gmail.com', '12@gmail.com',
			'13@gmail.com', '14@gmail.com', '15@gmail.com', '16@gmail.com',
		])
		->whereNotIn('id', $playersInTeams)
		->get()
		->shuffle();

		if ($testPlayers->count() < 2) {
			return back()->with('error', 'Недостаточно свободных тестовых игроков');
		}

		$teamsAdded = 0;

		for ($i = 0; $i < $testPlayers->count() - 1 && $teamsAdded < $teamsToAdd; $i += 2) {
			$player1 = $testPlayers[$i];
			$player2 = $testPlayers[$i + 1];

			TournamentTeam::create([
				'tournament_id' => $tournament->id,
				'player1_id' => $player1->id,
				'player2_id' => $player2->id,
				'rating_avg' => intval(($player1->rating + $player2->rating) / 2),
				'status' => 'approved',
			]);

			$teamsAdded++;
		}

		if ($teamsAdded === 0) {
			return back()->with('error', 'Не удалось создать пары');
		}

		return back()->with('success', "Создано {$teamsAdded} тестовых пар!");
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
				$q->where('phone', 'like', "%{$query}%")
				  ->orWhere('name', 'like', "%{$query}%");
			})
			->limit(10)
			->get(['id', 'name', 'phone', 'rating', 'level']);

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
	
	/**
	 * Одобрить команду
	 */
	public function approveTeam(Tournament $tournament, TournamentTeam $team)
	{
		$team->update(['status' => 'approved']);
		
		return back()->with('success', 'Команда одобрена');
	}

	/**
	 * Отклонить команду
	 */
	public function rejectTeam(Tournament $tournament, TournamentTeam $team)
	{
		$team->delete();
		
		return back()->with('success', 'Заявка отклонена');
	}
	
}
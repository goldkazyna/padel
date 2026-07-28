<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\MexicanoMatch;
use App\Models\Tournament;
use App\Services\MexicanoService;
use Illuminate\Http\Request;

class MexicanoController extends Controller
{
	/**
     * Показать турнир Мексикано
     */
    public function show(Tournament $tournament)
    {
        $tournament->load([
            'club', 
            'participants', 
            'mexicanoPlayers.user',
            'mexicanoRounds.matches'
        ]);
        
        return view('club.tournaments.mexicano.show', compact('tournament'));
    }
    public function saveScore(Request $request, MexicanoMatch $match, MexicanoService $mexicanoService)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        $mexicanoService->saveMatchResult(
            $match,
            $validated['team1_score'],
            $validated['team2_score']
        );

        if ($request->ajax() || $request->wantsJson()) {
            return $this->jsonResponse($match, 'Счёт сохранён!');
        }

        return back()->with('success', 'Счёт сохранён!');
    }

    public function updateScore(Request $request, MexicanoMatch $match, MexicanoService $mexicanoService)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        $mexicanoService->updateMatchResult(
            $match,
            $validated['team1_score'],
            $validated['team2_score']
        );

        if ($request->ajax() || $request->wantsJson()) {
            return $this->jsonResponse($match, 'Счёт обновлён!');
        }

        return back()->with('success', 'Счёт обновлён!');
    }

    protected function jsonResponse(MexicanoMatch $match, string $message)
    {
        $match->refresh();
        $match->load(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2', 'round.tournament']);
        
        $tournament = $match->round->tournament;
        
        $leaderboard = $tournament->mexicanoPlayers()
            ->orderBy('total_points', 'desc')
            ->with('user')
            ->get()
            ->map(function($player) {
                return [
                    'id' => $player->user_id,
                    'name' => $player->user->full_name,
                    'initials' => mb_strtoupper(mb_substr($player->user->first_name, 0, 1) . mb_substr($player->user->last_name, 0, 1)),
                    'rating' => $player->user->rating,
                    'points' => $player->total_points,
                ];
            });

        // Проверяем есть ли новый раунд
        $nextRound = null;
        if ($match->round->status === 'completed') {
            $nextRoundModel = $tournament->mexicanoRounds()
                ->where('round_number', $match->round->round_number + 1)
                ->where('status', 'in_progress')
                ->with(['matches.team1Player1', 'matches.team1Player2', 'matches.team2Player1', 'matches.team2Player2'])
                ->first();
            
            if ($nextRoundModel) {
                $nextRound = [
                    'id' => $nextRoundModel->id,
                    'round_number' => $nextRoundModel->round_number,
                    'status' => $nextRoundModel->status,
                    'matches' => $nextRoundModel->matches->map(function($m) {
                        return [
                            'id' => $m->id,
                            'status' => $m->status,
                        ];
                    }),
                ];
            }
        }

        $data = [
            'success' => true,
            'match' => [
                'id' => $match->id,
                'team1_score' => $match->team1_score,
                'team2_score' => $match->team2_score,
                'winning_team' => $match->winning_team,
                'status' => $match->status,
            ],
            'round' => [
                'id' => $match->round->id,
                'status' => $match->round->status,
            ],
            'nextRound' => $nextRound,
            'leaderboard' => $leaderboard,
            'message' => $message,
        ];

        return response()->json($data, 200, [], JSON_UNESCAPED_UNICODE);
    }
	public function generateNextRound(\App\Models\Tournament $tournament, MexicanoService $mexicanoService)
	{
		// Проверяем текущий раунд
		$currentRound = $tournament->mexicanoRounds()->reorder('round_number', 'desc')->first();
		
		if ($currentRound) {
			$pendingMatches = $currentRound->matches()->where('status', 'pending')->count();
			if ($pendingMatches > 0) {
				return back()->with('error', "Не все матчи раунда {$currentRound->round_number} сыграны! Осталось: {$pendingMatches}");
			}
		}
		
		if (!$mexicanoService->canGenerateNextRound($tournament)) {
			return back()->with('error', 'Невозможно сгенерировать следующий раунд');
		}
		
		$round = $mexicanoService->generateNextRound($tournament);
		
		if ($round) {
			return back()->with('success', "Раунд {$round->round_number} сгенерирован!");
		}
		
		return back()->with('error', 'Ошибка генерации раунда');
	}
	/**
	 * Сгенерировать плей-офф
	 */
	public function generatePlayoff(Tournament $tournament, MexicanoService $mexicanoService)
	{
		if (!$mexicanoService->canGeneratePlayoff($tournament)) {
			return back()->with('error', 'Невозможно сгенерировать плей-офф. Проверьте что все раунды сыграны.');
		}

		$result = $mexicanoService->generatePlayoff($tournament);

		if ($result) {
			return back()->with('success', 'Плей-офф сгенерирован!');
		}

		return back()->with('error', 'Ошибка генерации плей-офф');
	}

	/**
	 * Досрочно завершить отборочный этап Мексикано (не доигрывая все раунды).
	 * Без плей-офф — завершаем турнир. С плей-офф — сразу генерим плей-офф.
	 * Доступно только когда текущий раунд доигран всеми парами.
	 */
	public function finishEarly(Tournament $tournament, MexicanoService $mexicanoService)
	{
		if (!$tournament->isMexicano() || $tournament->status !== 'in_progress') {
			return back()->with('error', 'Недоступно для этого турнира');
		}

		// Текущий раунд должен быть доигран.
		$last = $tournament->mexicanoRounds()->reorder('round_number', 'desc')->first();
		if (!$last || $last->matches()->where('status', 'pending')->exists()) {
			return back()->with('error', 'Сначала доиграйте текущий раунд');
		}
		if ($tournament->playoffMatches()->count() > 0) {
			return back()->with('error', 'Плей-офф уже сгенерирован');
		}

		// Обрезаем план раундов до фактически сыгранных — дальше штатная логика
		// (canFinish/canGeneratePlayoff проверяют completedRounds >= rounds_count).
		$completed = $tournament->mexicanoRounds()->where('status', 'completed')->count();
		$tournament->update(['rounds_count' => $completed]);

		if ($tournament->hasPlayoff()) {
			$ok = $mexicanoService->generatePlayoff($tournament);
			return $ok
				? back()->with('success', 'Отборочный этап завершён, плей-офф сгенерирован')
				: back()->with('error', 'Не удалось сгенерировать плей-офф');
		}

		$ok = $mexicanoService->finishTournament($tournament);
		return $ok
			? back()->with('success', 'Турнир завершён')
			: back()->with('error', 'Не удалось завершить турнир');
	}

	/**
	 * Сохранить счёт плей-офф матча
	 */
	public function savePlayoffScore(Request $request, \App\Models\TournamentPlayoffMatch $match, MexicanoService $mexicanoService)
	{
		$validated = $request->validate([
			'team1_score' => 'required|integer|min:0',
			'team2_score' => 'required|integer|min:0',
		]);

		$match->update([
			'team1_score' => $validated['team1_score'],
			'team2_score' => $validated['team2_score'],
			'status' => 'completed',
		]);

		// Если это полуфинал — обновляем финал
		if ($match->stage === 'Полуфинал') {
			$mexicanoService->updateFinalAfterSemifinal($match);
		}

		return back()->with('success', 'Счёт сохранён!');
	}

	/**
	 * Обновить счёт плей-офф матча
	 */
	public function updatePlayoffScore(Request $request, \App\Models\TournamentPlayoffMatch $match, MexicanoService $mexicanoService)
	{
		$validated = $request->validate([
			'team1_score' => 'required|integer|min:0',
			'team2_score' => 'required|integer|min:0',
		]);

		$match->update([
			'team1_score' => $validated['team1_score'],
			'team2_score' => $validated['team2_score'],
		]);

		// Если это полуфинал — обновляем финал
		if ($match->stage === 'Полуфинал') {
			$mexicanoService->updateFinalAfterSemifinal($match);
		}

		return back()->with('success', 'Счёт обновлён!');
	}
}
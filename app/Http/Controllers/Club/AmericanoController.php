<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\AmericanoMatch;
use App\Services\AmericanoService;
use Illuminate\Http\Request;
use App\Models\Tournament;
use App\Models\TournamentPlayoffMatch;

class AmericanoController extends Controller
{
    public function saveScore(Request $request, AmericanoMatch $match, AmericanoService $americanoService)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        $americanoService->saveMatchResult(
            $match,
            $validated['team1_score'],
            $validated['team2_score']
        );

        if ($request->ajax() || $request->wantsJson()) {
            return $this->jsonResponse($match, 'Счёт сохранён!');
        }

        return back()->with('success', 'Счёт сохранён!');
    }

    public function updateScore(Request $request, AmericanoMatch $match, AmericanoService $americanoService)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        $americanoService->updateMatchResult(
            $match,
            $validated['team1_score'],
            $validated['team2_score']
        );

        if ($request->ajax() || $request->wantsJson()) {
            return $this->jsonResponse($match, 'Счёт обновлён!');
        }

        return back()->with('success', 'Счёт обновлён!');
    }

    protected function jsonResponse(AmericanoMatch $match, string $message)
	{
		$match->refresh();
		$match->load(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2', 'round.group']);
		
		$leaderboard = $match->round->group->players()
			->orderByPivot('total_points', 'desc')
			->get()
			->map(function($player) {
				return [
					'id' => $player->id,
					'name' => $player->full_name,
					'initials' => mb_strtoupper(mb_substr($player->first_name, 0, 1) . mb_substr($player->last_name, 0, 1)),
					'rating' => $player->rating,
					'points' => $player->pivot->total_points,
				];
			});

		// Проверяем есть ли следующий раунд который стал активным
		$nextRound = null;
		if ($match->round->status === 'completed') {
			$nextRoundModel = \App\Models\AmericanoRound::where('tournament_group_id', $match->round->tournament_group_id)
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
	/**
	 * Сгенерировать плей-офф
	 */
	/**
	 * Раскрыть следующий раунд (генерация по кнопке).
	 */
	public function nextRound(Tournament $tournament, AmericanoService $americanoService)
	{
		if (!$americanoService->canGenerateNextRound($tournament)) {
			return back()->with('error', 'Сначала доиграйте текущий раунд');
		}

		if ($americanoService->generateNextRound($tournament)) {
			return back()->with('success', 'Следующий раунд сгенерирован');
		}

		return back()->with('error', 'Не удалось сгенерировать раунд');
	}

	public function generatePlayoff(Tournament $tournament, AmericanoService $americanoService)
	{
		if (!$americanoService->canGeneratePlayoff($tournament)) {
			return back()->with('error', 'Невозможно сгенерировать плей-офф. Проверьте что все матчи сыграны.');
		}

		$result = $americanoService->generatePlayoff($tournament);

		if ($result) {
			return back()->with('success', 'Плей-офф сгенерирован!');
		}

		return back()->with('error', 'Ошибка генерации плей-офф');
	}
	/**
	 * Сохранить счёт плей-офф матча
	 */
	public function savePlayoffScore(Request $request, TournamentPlayoffMatch $match, AmericanoService $americanoService)
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
			$americanoService->updateFinalAfterSemifinal($match);
		}

		return back()->with('success', 'Счёт сохранён!');
	}

	/**
	 * Обновить счёт плей-офф матча
	 */
	public function updatePlayoffScore(Request $request, TournamentPlayoffMatch $match, AmericanoService $americanoService)
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
			$americanoService->updateFinalAfterSemifinal($match);
		}

		return back()->with('success', 'Счёт обновлён!');
	}
}
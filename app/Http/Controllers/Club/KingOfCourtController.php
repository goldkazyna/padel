<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\KingOfCourtMatch;
use App\Models\Tournament;
use App\Services\KingOfCourtService;
use Illuminate\Http\Request;

class KingOfCourtController extends Controller
{
    public function show(Tournament $tournament)
    {
        $tournament->load([
            'club',
            'participants',
            'kingOfCourtPlayers.user',
            'kingOfCourtRounds.matches.team1Player1',
            'kingOfCourtRounds.matches.team1Player2',
            'kingOfCourtRounds.matches.team2Player1',
            'kingOfCourtRounds.matches.team2Player2',
        ]);

        return view('club.tournaments.kingofcourt.show', compact('tournament'));
    }

    public function saveScore(Request $request, KingOfCourtMatch $match, KingOfCourtService $service)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        if ($validated['team1_score'] === $validated['team2_score']) {
            return back()->with('error', 'В Король корта не может быть ничьей. Сыграйте до победы.');
        }

        $service->saveMatchResult(
            $match,
            $validated['team1_score'],
            $validated['team2_score']
        );

        return back()->with('success', 'Счёт сохранён!');
    }

    public function updateScore(Request $request, KingOfCourtMatch $match, KingOfCourtService $service)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        if ($validated['team1_score'] === $validated['team2_score']) {
            return back()->with('error', 'В Король корта не может быть ничьей.');
        }

        $service->saveMatchResult(
            $match,
            $validated['team1_score'],
            $validated['team2_score']
        );

        return back()->with('success', 'Счёт обновлён!');
    }

    public function generateNextRound(Tournament $tournament, KingOfCourtService $service)
    {
        if (!$service->canGenerateNextRound($tournament)) {
            return back()->with('error', 'Невозможно сгенерировать следующий раунд. Сначала доиграйте текущий.');
        }

        $ok = $service->generateNextRound($tournament);

        if ($ok) {
            return back()->with('success', 'Следующий раунд сгенерирован!');
        }

        return back()->with('error', 'Ошибка генерации раунда');
    }
}

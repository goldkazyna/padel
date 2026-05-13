<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\BaliKocMatch;
use App\Models\Tournament;
use App\Services\BaliKocService;
use Illuminate\Http\Request;

class BaliKocController extends Controller
{
    public function show(Tournament $tournament)
    {
        $tournament->load([
            'club',
            'participants',
            'baliKocPairs.player1',
            'baliKocPairs.player2',
            'baliKocRounds.matches.pair1.player1',
            'baliKocRounds.matches.pair1.player2',
            'baliKocRounds.matches.pair2.player1',
            'baliKocRounds.matches.pair2.player2',
        ]);

        $standings = app(BaliKocService::class)->getStandings($tournament);

        return view('club.tournaments.bali_koc.show', compact('tournament', 'standings'));
    }

    /**
     * Страница «Создать пары»: показывает зарегистрированных игроков и форму
     * с селектами для каждой пары.
     */
    public function pairs(Tournament $tournament)
    {
        if (!$tournament->isBaliKoc()) {
            return redirect()->route('club.tournaments.show', $tournament);
        }

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->orderBy('name')
            ->get();

        $existingPairs = $tournament->baliKocPairs()->get();

        return view('club.tournaments.bali_koc.pairs', compact('tournament', 'participants', 'existingPairs'));
    }

    /**
     * Сохранение пар, созданных админом.
     * Ожидаем pairs[i][0] = player1_id, pairs[i][1] = player2_id.
     */
    public function storePairs(Request $request, Tournament $tournament, BaliKocService $service)
    {
        $validated = $request->validate([
            'pairs' => 'required|array|min:2',
            'pairs.*.0' => 'required|integer|exists:users,id',
            'pairs.*.1' => 'required|integer|exists:users,id',
        ]);

        [$ok, $message] = $service->createPairs($tournament, $validated['pairs']);
        if (!$ok) {
            return back()->with('error', $message)->withInput();
        }

        return redirect()->route('club.tournaments.show', $tournament)->with('success', $message);
    }

    public function saveScore(Request $request, BaliKocMatch $match, BaliKocService $service)
    {
        $validated = $request->validate([
            'pair1_games' => 'required|integer|min:0',
            'pair2_games' => 'required|integer|min:0',
        ]);

        if ($validated['pair1_games'] === $validated['pair2_games']) {
            return back()->with('error', 'Ничья не допускается — играем до победы.');
        }

        $service->saveMatchResult($match, $validated['pair1_games'], $validated['pair2_games']);
        return back()->with('success', 'Счёт сохранён!');
    }

    public function updateScore(Request $request, BaliKocMatch $match, BaliKocService $service)
    {
        $validated = $request->validate([
            'pair1_games' => 'required|integer|min:0',
            'pair2_games' => 'required|integer|min:0',
        ]);

        if ($validated['pair1_games'] === $validated['pair2_games']) {
            return back()->with('error', 'Ничья не допускается.');
        }

        $service->saveMatchResult($match, $validated['pair1_games'], $validated['pair2_games']);
        return back()->with('success', 'Счёт обновлён!');
    }

    public function generateNextRound(Tournament $tournament, BaliKocService $service)
    {
        if (!$service->canGenerateNextRound($tournament)) {
            return back()->with('error', 'Невозможно сгенерировать следующий раунд. Сначала доиграйте текущий.');
        }

        $ok = $service->generateNextRound($tournament);
        if (!$ok) {
            return back()->with('error', 'Ошибка генерации раунда');
        }

        $newRoundNumber = $tournament->baliKocRounds()->max('round_number');
        return back()->with('success', "Раунд {$newRoundNumber} сгенерирован!");
    }
}

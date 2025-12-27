<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\Tournament;
use App\Services\EloService;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    protected EloService $eloService;

    public function __construct(EloService $eloService)
    {
        $this->eloService = $eloService;
    }

    // Создать матч
    public function create(Tournament $tournament)
    {
        $tournament->load('participants');
        
        return view('club.matches.create', compact('tournament'));
    }

    // Сохранить матч
    public function store(Request $request, Tournament $tournament)
    {
        $validated = $request->validate([
            'player1_id' => 'required|exists:users,id',
            'player2_id' => 'required|exists:users,id|different:player1_id',
            'winner_id' => 'required|in:' . $request->player1_id . ',' . $request->player2_id,
            'score' => 'required|string|max:50',
        ]);

        $match = GameMatch::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $validated['player1_id'],
            'player2_id' => $validated['player2_id'],
            'winner_id' => $validated['winner_id'],
            'score' => $validated['score'],
            'status' => 'completed',
        ]);

        // Рассчитываем Эло
        $ratings = $this->eloService->calculateMatch($match, $validated['winner_id']);
        $match->update($ratings);

        return redirect()->route('club.tournaments.show', $tournament)
                         ->with('success', 'Матч добавлен, рейтинг обновлён!');
    }

    // Удалить матч (откат рейтинга)
    public function destroy(Tournament $tournament, GameMatch $match)
    {
        // Откатываем рейтинги
        if ($match->isCompleted()) {
            $match->player1->update(['rating' => $match->player1_rating_before]);
            $match->player2->update(['rating' => $match->player2_rating_before]);
        }

        $match->delete();

        return redirect()->route('club.tournaments.show', $tournament)
                         ->with('success', 'Матч удалён, рейтинг откатен');
    }
}
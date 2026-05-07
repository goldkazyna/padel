<?php

namespace App\Http\Controllers;

use App\Models\User;

class PlayerController extends Controller
{
    public function show(User $player)
    {
        if ($player->role !== 'player') {
            abort(404);
        }

        $stats = $player->getAllMatchesStats();
        $matchHistory = $player->getMatchHistory();
        $ratingHistory = $player->ratingHistory()->take(20)->get();
        
        // Позиция в рейтинге
        $rank = User::human()
            ->where('rating', '>', $player->rating)
            ->count() + 1;

        return view('players.show', compact('player', 'stats', 'matchHistory', 'ratingHistory', 'rank'));
    }
}
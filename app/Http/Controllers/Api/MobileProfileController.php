<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class MobileProfileController extends Controller
{
    /**
     * Профиль текущего пользователя
     * GET /api/mobile/profile
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $matchStats = $user->getAllMatchesStats();
        $tournamentStats = $user->getTournamentStats();

        $place = null;
        if ($user->rating) {
            $place = User::where('role', 'player')
                ->where('rating', '>', $user->rating)
                ->count() + 1;
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
                'level_name' => $user->level_name,
                'place' => $place,
            ],
            'statistics' => [
                'matches_played' => $matchStats['total'],
                'wins' => $matchStats['won'],
                'losses' => $matchStats['lost'],
                'winrate' => $matchStats['total'] > 0
                    ? (int) round(($matchStats['won'] / $matchStats['total']) * 100)
                    : 0,
                'tournaments_count' => $tournamentStats['total'],
            ],
        ]);
    }
}

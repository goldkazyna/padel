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
            'user' => $this->formatUser($user, $place),
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

    /**
     * Обновление профиля
     * PUT /api/mobile/profile
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'first_name'  => 'nullable|string|max:100',
            'last_name'   => 'nullable|string|max:100',
            'patronymic'  => 'nullable|string|max:100',
            'city'        => 'nullable|string|in:Алматы,Астана,Шымкент,Караганда,Актобе',
            'gender'      => 'nullable|string|in:male,female',
            'age'         => 'nullable|integer|min:1|max:99',
            'hand'        => 'nullable|string|in:right,left',
            'position'    => 'nullable|string|in:right,left,any',
        ]);

        $user = $request->user();
        $user->fill($validated);

        // Пересобрать name при обновлении имени/фамилии
        $firstName = $user->first_name;
        $lastName = $user->last_name;
        if ($request->has('first_name') || $request->has('last_name')) {
            $user->name = trim("{$lastName} {$firstName}");
        }

        $user->save();

        $place = null;
        if ($user->rating) {
            $place = User::where('role', 'player')
                ->where('rating', '>', $user->rating)
                ->count() + 1;
        }

        return response()->json([
            'success' => true,
            'message' => 'Профиль обновлён',
            'user' => $this->formatUser($user, $place),
        ]);
    }

    private function formatUser(User $user, ?int $place): array
    {
        return [
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
            'patronymic' => $user->patronymic,
            'city' => $user->city,
            'gender' => $user->gender,
            'age' => $user->age,
            'hand' => $user->hand,
            'position' => $user->position,
        ];
    }
}

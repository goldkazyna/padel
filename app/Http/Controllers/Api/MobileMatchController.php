<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlayerMatchHistory;
use Illuminate\Http\Request;

class MobileMatchController extends Controller
{
    /**
     * История матчей
     * GET /api/mobile/matches/history
     */
    public function history(Request $request)
    {
        $user = $request->user();
        $perPage = min((int) $request->get('per_page', 20), 50);
        $page = max((int) $request->get('page', 1), 1);

        $matches = app(PlayerMatchHistory::class)->for($user);

        // Сортируем по дате (новые первыми)
        usort($matches, fn($a, $b) => $b['sort_date'] <=> $a['sort_date']);

        // Пагинация
        $total = count($matches);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $items = array_slice($matches, $offset, $perPage);

        // Наружу отдаём тот же набор, что и раньше: tournament_id, tournament_type,
        // club_id и sort_date нужны достижениям, а не приложению.
        $items = array_map(function ($m) {
            unset($m['sort_date'], $m['tournament_id'], $m['tournament_type'], $m['club_id']);
            return $m;
        }, $items);

        return response()->json([
            'success' => true,
            'matches' => array_values($items),
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }
}

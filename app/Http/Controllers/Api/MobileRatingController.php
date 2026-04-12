<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class MobileRatingController extends Controller
{
    /**
     * Рейтинг игроков
     * GET /api/mobile/rating
     *
     * Query params:
     *   level  — фильтр по уровню: 1, 2, 3, 4 или all (по умолчанию all)
     *   search — поиск по имени
     *   page   — страница (по умолчанию 1)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $level = $request->input('level', 'all');
        $search = $request->input('search');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;

        // Базовый запрос с фильтрами
        $query = User::where('role', 'player');
        $this->applyLevelFilter($query, $level);
        $this->applySearch($query, $search);

        // Общее количество (с фильтрами)
        $total = $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));

        // Топ игроков (пагинация)
        $players = (clone $query)
            ->orderBy('rating', 'desc')
            ->orderBy('id', 'asc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(function ($player, $index) use ($page, $perPage) {
                return $this->formatPlayer($player, ($page - 1) * $perPage + $index + 1);
            });

        // Карточка текущего пользователя
        $myCard = $this->getMyCard($user, $level, $search, $total);

        // Соседи по рейтингу (без фильтров — в общем рейтинге)
        $neighbors = $this->getNeighbors($user);

        return response()->json([
            'success' => true,
            'my_card' => $myCard,
            'players' => $players,
            'neighbors' => $neighbors,
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
            'filters' => [
                'level' => $level,
                'search' => $search,
            ],
        ]);
    }

    /**
     * Карточка текущего пользователя
     */
    private function getMyCard($user, string $level, ?string $search, int $totalFiltered): array
    {
        // Место в общем рейтинге (без фильтров)
        $globalPlace = User::where('role', 'player')
            ->where(function ($q) use ($user) {
                $q->where('rating', '>', $user->rating)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->where('rating', '=', $user->rating)
                         ->where('id', '<', $user->id);
                  });
            })
            ->count() + 1;

        $totalPlayers = User::where('role', 'player')->count();

        // Место с учётом текущего фильтра
        $filteredPlace = null;
        if ($level !== 'all' || $search) {
            $filteredQuery = User::where('role', 'player');
            $this->applyLevelFilter($filteredQuery, $level);
            $this->applySearch($filteredQuery, $search);

            $filteredPlace = (clone $filteredQuery)
                ->where(function ($q) use ($user) {
                    $q->where('rating', '>', $user->rating)
                      ->orWhere(function ($q2) use ($user) {
                          $q2->where('rating', '=', $user->rating)
                             ->where('id', '<', $user->id);
                      });
                })
                ->count() + 1;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'avatar' => $user->avatar,
            'rating' => $user->rating,
            'level' => $user->level,
            'level_name' => $user->level_name,
            'place' => $globalPlace,
            'filtered_place' => $filteredPlace,
            'total_players' => $totalPlayers,
        ];
    }

    /**
     * Соседи по рейтингу — 2 выше и 2 ниже текущего пользователя
     */
    private function getNeighbors($user): array
    {
        // Игроки выше (рейтинг больше или равный, но id меньше при равном рейтинге)
        $above = User::where('role', 'player')
            ->where(function ($q) use ($user) {
                $q->where('rating', '>', $user->rating)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->where('rating', '=', $user->rating)
                         ->where('id', '<', $user->id);
                  });
            })
            ->orderBy('rating', 'asc')
            ->orderBy('id', 'desc')
            ->limit(2)
            ->get()
            ->reverse()
            ->values();

        // Игроки ниже (рейтинг меньше или равный, но id больше при равном рейтинге)
        $below = User::where('role', 'player')
            ->where(function ($q) use ($user) {
                $q->where('rating', '<', $user->rating)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->where('rating', '=', $user->rating)
                         ->where('id', '>', $user->id);
                  });
            })
            ->orderBy('rating', 'desc')
            ->orderBy('id', 'asc')
            ->limit(2)
            ->get();

        // Считаем позицию текущего пользователя
        $myPlace = User::where('role', 'player')
            ->where(function ($q) use ($user) {
                $q->where('rating', '>', $user->rating)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->where('rating', '=', $user->rating)
                         ->where('id', '<', $user->id);
                  });
            })
            ->count() + 1;

        $result = [];

        // Добавляем игроков выше
        foreach ($above as $i => $player) {
            $result[] = $this->formatPlayer($player, $myPlace - ($above->count() - $i));
        }

        // Текущий пользователь
        $result[] = array_merge(
            $this->formatPlayer($user, $myPlace),
            ['is_me' => true]
        );

        // Добавляем игроков ниже
        foreach ($below as $i => $player) {
            $result[] = $this->formatPlayer($player, $myPlace + $i + 1);
        }

        return $result;
    }

    /**
     * Рост рейтинга
     * GET /api/mobile/rating/growth
     *
     * Query params:
     *   period — week, month, all (по умолчанию month)
     *   page   — страница
     */
    public function growth(Request $request)
    {
        $user = $request->user();
        $period = $request->input('period', 'month');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;

        $query = \App\Models\RatingHistory::selectRaw('user_id, SUM(change) as total_growth')
            ->groupBy('user_id')
            ->having('total_growth', '>', 0);

        if ($period === 'week') {
            $query->where('created_at', '>=', now()->subWeek());
        } elseif ($period === 'month') {
            $query->where('created_at', '>=', now()->subMonth());
        }

        $total = $query->get()->count();
        $totalPages = max(1, (int) ceil($total / $perPage));

        $results = (clone $query)
            ->orderBy('total_growth', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $userIds = $results->pluck('user_id');
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $players = [];
        $position = ($page - 1) * $perPage;
        foreach ($results as $row) {
            $position++;
            $u = $users[$row->user_id] ?? null;
            if (!$u) continue;
            $players[] = [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'rating' => $u->rating,
                'level' => $u->level,
                'position' => $position,
                'growth' => (int) $row->total_growth,
                'is_me' => $u->id === $user->id,
            ];
        }

        // Мой рост
        $myGrowthQuery = \App\Models\RatingHistory::where('user_id', $user->id);
        if ($period === 'week') {
            $myGrowthQuery->where('created_at', '>=', now()->subWeek());
        } elseif ($period === 'month') {
            $myGrowthQuery->where('created_at', '>=', now()->subMonth());
        }
        $myGrowth = (int) $myGrowthQuery->sum('change');

        // Моя позиция в росте
        $myPlaceQuery = \App\Models\RatingHistory::selectRaw('user_id, SUM(change) as total_growth')
            ->groupBy('user_id')
            ->having('total_growth', '>', $myGrowth > 0 ? $myGrowth : PHP_INT_MAX);
        if ($period === 'week') {
            $myPlaceQuery->where('created_at', '>=', now()->subWeek());
        } elseif ($period === 'month') {
            $myPlaceQuery->where('created_at', '>=', now()->subMonth());
        }
        $myPlace = $myPlaceQuery->get()->count() + 1;

        return response()->json([
            'success' => true,
            'players' => $players,
            'my_growth' => $myGrowth,
            'my_place' => $myPlace,
            'period' => $period,
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
        ]);
    }

    /**
     * Фильтрация по уровню
     */
    private function applyLevelFilter($query, string $level): void
    {
        match ($level) {
            '1' => $query->where('level', '>=', 1.0)->where('level', '<', 2.0),
            '2' => $query->where('level', '>=', 2.0)->where('level', '<', 3.0),
            '3' => $query->where('level', '>=', 3.0)->where('level', '<', 4.0),
            '4' => $query->where('level', '>=', 4.0),
            default => null,
        };
    }

    /**
     * Поиск по имени
     */
    private function applySearch($query, ?string $search): void
    {
        if (!$search) return;

        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('first_name', 'like', "%{$search}%")
              ->orWhere('last_name', 'like', "%{$search}%");
        });
    }

    /**
     * Формат игрока
     */
    private function formatPlayer($player, int $position): array
    {
        return [
            'id' => $player->id,
            'name' => $player->name,
            'first_name' => $player->first_name,
            'last_name' => $player->last_name,
            'avatar' => $player->avatar,
            'rating' => $player->rating,
            'level' => $player->level,
            'position' => $position,
        ];
    }
}

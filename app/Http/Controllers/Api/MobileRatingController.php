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
            'level_verified' => (bool) $user->level_verified,
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
        $search = $request->input('search');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;

        $query = \App\Models\RatingHistory::selectRaw('user_id, SUM(`change`) as total_growth')
            ->groupBy('user_id')
            ->having('total_growth', '>', 0);

        if ($period === 'week') {
            $query->where('created_at', '>=', now()->subWeek());
        } elseif ($period === 'month') {
            $query->where('created_at', '>=', now()->subMonth());
        }

        if ($search) {
            $userIds = User::where('role', 'player')
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%");
                })->pluck('id');
            $query->whereIn('user_id', $userIds);
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
                'level_verified' => (bool) $u->level_verified,
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
        $myPlaceQuery = \App\Models\RatingHistory::selectRaw('user_id, SUM(`change`) as total_growth')
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
     * Профиль игрока с историей рейтинга
     * GET /api/mobile/rating/player/{user}
     */
    public function player(User $user)
    {
        // Место в рейтинге
        $place = User::where('role', 'player')
            ->where(function ($q) use ($user) {
                $q->where('rating', '>', $user->rating)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->where('rating', '=', $user->rating)
                         ->where('id', '<', $user->id);
                  });
            })
            ->count() + 1;

        // Статистика как в профиле
        $matchStats = $user->getAllMatchesStats();
        $tournamentStats = $user->getTournamentStats();

        // Тренд рейтинга — одно значение на турнир
        $ratingTrend = \App\Models\RatingHistory::where('user_id', $user->id)
            ->whereNotNull('tournament_id')
            ->whereNotNull('rating_after')
            ->orderBy('id', 'asc')
            ->get(['tournament_id', 'rating_after'])
            ->groupBy('tournament_id')
            ->map(fn($group) => $group->last()->rating_after)
            ->values()
            ->toArray();
        $ratingTrend = array_slice($ratingTrend, -10);
        $ratingTrend = array_map('intval', $ratingTrend);

        // История рейтинга — показываем ТОЛЬКО записи с существующим турниром.
        // Если у записи tournament_id=NULL (FK обнулил при удалении турнира)
        // или сам турнир физически удалён — это руины, прячем.
        $history = \App\Models\RatingHistory::where('user_id', $user->id)
            ->with('tournament:id,name,type,has_playoff')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->filter(fn($h) => $h->tournament !== null)
            ->map(function($h) use ($user) {
                return [
                    'tournament_id' => $h->tournament->id,
                    'tournament_name' => $h->tournament->name,
                    'tournament_type' => $h->tournament->type,
                    'date' => $h->created_at->format('d.m.Y'),
                    'change' => $h->change,
                    'rating_after' => $h->rating_after,
                    'place' => $this->getTournamentPlace($h->tournament, $user->id),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'player' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
                'level_verified' => (bool) $user->level_verified,
                'level_verification' => $this->lastLevelVerification($user),
                'place' => $place,
                'matches_played' => $matchStats['total'],
                'wins' => $matchStats['won'],
                'losses' => $matchStats['lost'],
                'winrate' => $matchStats['total'] > 0
                    ? (int) round(($matchStats['won'] / $matchStats['total']) * 100)
                    : 0,
                'tournaments_count' => $tournamentStats['total'],
                'rating_trend' => $ratingTrend,
            ],
            'history' => $history,
        ]);
    }

    /**
     * GET /api/mobile/rating/player/{user}/verification
     * Лёгкий эндпоинт: только level_verification, без статистики и истории.
     * Используется для всплывающей модалки в рейтинге/мой-карточке.
     */
    public function verification(User $user)
    {
        $history = $this->levelVerificationHistory($user);

        return response()->json([
            'success' => true,
            'level' => $user->level !== null ? (float) $user->level : null,
            'level_verified' => (bool) $user->level_verified,
            // Последняя запись (для обратной совместимости и плашки в профиле).
            'level_verification' => $history[0] ?? null,
            // Полная история — для модалки.
            'level_verifications' => $history,
        ]);
    }

    /**
     * Все записи из user_level_history где new_verified=true,
     * отсортированные по дате убывания (новые сверху).
     */
    private function levelVerificationHistory(User $user): array
    {
        return \App\Models\UserLevelHistory::where('user_id', $user->id)
            ->where('new_verified', true)
            ->orderBy('created_at', 'desc')
            ->with(['changedBy:id,name,avatar', 'club:id,name'])
            ->get()
            ->map(fn($row) => [
                'verified_by' => $row->changedBy ? [
                    'id' => $row->changedBy->id,
                    'name' => $row->changedBy->name,
                    'avatar_url' => $row->changedBy->avatar
                        ? asset('storage/' . $row->changedBy->avatar) : null,
                ] : null,
                'club' => $row->club ? [
                    'id' => $row->club->id,
                    'name' => $row->club->name,
                ] : null,
                'verified_at' => $row->created_at?->toIso8601String(),
                'level_set_to' => $row->new_level !== null
                    ? (float) $row->new_level : null,
            ])
            ->toArray();
    }

    /**
     * Последняя запись из user_level_history где new_verified=true.
     * Используется в подробном /rating/player/{user} эндпоинте.
     */
    private function lastLevelVerification(User $user): ?array
    {
        $history = $this->levelVerificationHistory($user);
        return $history[0] ?? null;
    }

    /**
     * Активность по турнирам
     * GET /api/mobile/rating/tournaments
     *
     * Query params:
     *   period — week, month, all (по умолчанию month)
     *   page   — страница
     */
    public function tournaments(Request $request)
    {
        $user = $request->user();
        $period = $request->input('period', 'month');
        $search = $request->input('search');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;

        $query = \App\Models\RatingHistory::selectRaw('user_id, COUNT(DISTINCT tournament_id) as tournaments_count, SUM(`change`) as total_change')
            ->whereNotNull('tournament_id')
            ->groupBy('user_id')
            ->having('tournaments_count', '>', 0);

        if ($period === 'week') {
            $query->where('created_at', '>=', now()->subWeek());
        } elseif ($period === 'month') {
            $query->where('created_at', '>=', now()->subMonth());
        }

        if ($search) {
            $userIds = User::where('role', 'player')
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%");
                })->pluck('id');
            $query->whereIn('user_id', $userIds);
        }

        $total = $query->get()->count();
        $totalPages = max(1, (int) ceil($total / $perPage));

        $results = (clone $query)
            ->orderBy('tournaments_count', 'desc')
            ->orderBy('total_change', 'desc')
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
                'level_verified' => (bool) $u->level_verified,
                'position' => $position,
                'tournaments_count' => (int) $row->tournaments_count,
                'total_change' => (int) $row->total_change,
                'is_me' => $u->id === $user->id,
            ];
        }

        // Мои данные
        $myQuery = \App\Models\RatingHistory::where('user_id', $user->id)->whereNotNull('tournament_id');
        if ($period === 'week') {
            $myQuery->where('created_at', '>=', now()->subWeek());
        } elseif ($period === 'month') {
            $myQuery->where('created_at', '>=', now()->subMonth());
        }
        $myTournaments = $myQuery->distinct('tournament_id')->count('tournament_id');
        $myChange = (int) (clone $myQuery)->sum(\DB::raw('`change`'));

        // Моя позиция
        $myPlaceQuery = \App\Models\RatingHistory::selectRaw('user_id, COUNT(DISTINCT tournament_id) as tournaments_count')
            ->whereNotNull('tournament_id')
            ->groupBy('user_id')
            ->having('tournaments_count', '>', $myTournaments > 0 ? $myTournaments : PHP_INT_MAX);
        if ($period === 'week') {
            $myPlaceQuery->where('created_at', '>=', now()->subWeek());
        } elseif ($period === 'month') {
            $myPlaceQuery->where('created_at', '>=', now()->subMonth());
        }
        $myPlace = $myPlaceQuery->get()->count() + 1;

        return response()->json([
            'success' => true,
            'players' => $players,
            'my_tournaments' => $myTournaments,
            'my_change' => $myChange,
            'my_place' => $myPlace,
            'period' => $period,
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
        ]);
    }

    /**
     * Место игрока в турнире
     */
    private function getTournamentPlace($tournament, int $userId): ?int
    {
        // Команды игрока (для team-турниров)
        $myTeamIds = \App\Models\TournamentTeam::where('tournament_id', $tournament->id)
            ->where(function ($q) use ($userId) {
                $q->where('player1_id', $userId)->orWhere('player2_id', $userId);
            })
            ->pluck('id');

        // Проверяем финал
        $finalMatch = $tournament->playoffMatches()
            ->whereIn('stage', ['final', 'Финал'])
            ->where('status', 'completed')
            ->first();

        if ($finalMatch) {
            // Player-based (americano/mexicano)
            if ($finalMatch->team1_player1_id) {
                $inTeam1 = in_array($userId, [$finalMatch->team1_player1_id, $finalMatch->team1_player2_id]);
                $inTeam2 = in_array($userId, [$finalMatch->team2_player1_id, $finalMatch->team2_player2_id]);

                if ($inTeam1 || $inTeam2) {
                    $team1Won = $finalMatch->team1_score > $finalMatch->team2_score;
                    return ($inTeam1 && $team1Won) || ($inTeam2 && !$team1Won) ? 1 : 2;
                }
            }

            // Team-based (team)
            if ($finalMatch->team1_id && $myTeamIds->isNotEmpty()) {
                $inTeam1 = $myTeamIds->contains($finalMatch->team1_id);
                $inTeam2 = $myTeamIds->contains($finalMatch->team2_id);

                if ($inTeam1 || $inTeam2) {
                    if ($finalMatch->winner_id && $myTeamIds->contains($finalMatch->winner_id)) return 1;
                    return 2;
                }
            }

            // Полуфинал — 3-4 место
            $semiMatches = $tournament->playoffMatches()
                ->whereIn('stage', ['semi', 'Полуфинал'])
                ->where('status', 'completed')
                ->get();

            foreach ($semiMatches as $semi) {
                // Player-based
                $inSemi = in_array($userId, [
                    $semi->team1_player1_id, $semi->team1_player2_id,
                    $semi->team2_player1_id, $semi->team2_player2_id,
                ]);
                if ($inSemi) return 3;

                // Team-based
                if ($myTeamIds->isNotEmpty() && ($myTeamIds->contains($semi->team1_id) || $myTeamIds->contains($semi->team2_id))) {
                    return 3;
                }
            }
        }

        // По лидерборду (позиция в группе по очкам)
        if (in_array($tournament->type, ['americano', 'mexicano'])) {
            $groups = $tournament->groups()->with('players')->get();
            $allPlayers = collect();
            foreach ($groups as $group) {
                foreach ($group->players as $player) {
                    $existing = $allPlayers->firstWhere('id', $player->id);
                    if ($existing) {
                        $existing['total_points'] += (int) ($player->pivot->total_points ?? 0);
                    } else {
                        $allPlayers->push([
                            'id' => $player->id,
                            'total_points' => (int) ($player->pivot->total_points ?? 0),
                        ]);
                    }
                }
            }
            $sorted = $allPlayers->sortByDesc('total_points')->values();
            $index = $sorted->search(fn($p) => $p['id'] === $userId);
            if ($index !== false) return $index + 1;
        }

        // Король корта — место по лидерборду
        if ($tournament->type === 'king_of_court') {
            $players = $tournament->kingOfCourtPlayers()
                ->orderByDesc('total_points')
                ->orderByDesc('wins')
                ->get();
            foreach ($players as $i => $player) {
                if ($player->user_id === $userId) return $i + 1;
            }
        }

        // Team турнир без плей-офф — место по группе
        if ($tournament->type === 'team' && $myTeamIds->isNotEmpty()) {
            $groups = $tournament->groups()->with('teams')->get();
            foreach ($groups as $group) {
                $sorted = $group->teams->sortByDesc(fn($t) => $t->pivot->points ?? 0)->values();
                foreach ($sorted as $i => $team) {
                    if ($myTeamIds->contains($team->id)) return $i + 1;
                }
            }
        }

        return null;
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
            'level_verified' => (bool) $player->level_verified,
            'position' => $position,
        ];
    }
}

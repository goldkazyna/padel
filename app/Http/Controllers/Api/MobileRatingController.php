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
        $perPage = 50;

        // Базовый запрос с фильтрами
        $query = User::visibleInRating();
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
        $globalPlace = User::visibleInRating()
            ->where(function ($q) use ($user) {
                $q->where('rating', '>', $user->rating)
                  ->orWhere(function ($q2) use ($user) {
                      $q2->where('rating', '=', $user->rating)
                         ->where('id', '<', $user->id);
                  });
            })
            ->count() + 1;

        $totalPlayers = User::visibleInRating()->count();

        // Место с учётом текущего фильтра
        $filteredPlace = null;
        if ($level !== 'all' || $search) {
            $filteredQuery = User::visibleInRating();
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
            'verification_blockers' => $user->verificationBlockers(),
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
        $above = User::visibleInRating()
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
        $below = User::visibleInRating()
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
        $myPlace = User::visibleInRating()
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
        $perPage = 50;

        // Ручные правки рейтинга (reason='Ручная корректировка') не считаются
        // «игровым ростом» — исключаем их из лидерборда роста.
        $query = \App\Models\RatingHistory::selectRaw('user_id, SUM(`change`) as total_growth')
            ->where('reason', '!=', 'Ручная корректировка')
            ->groupBy('user_id')
            ->having('total_growth', '>', 0);

        if ($period === 'week') {
            $query->where('created_at', '>=', now()->subWeek());
        } elseif ($period === 'month') {
            $query->where('created_at', '>=', now()->subMonth());
        }

        if ($search) {
            $userIds = User::visibleInRating()
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

        // Мой рост (без ручных правок)
        $myGrowthQuery = \App\Models\RatingHistory::where('user_id', $user->id)
            ->where('reason', '!=', 'Ручная корректировка');
        if ($period === 'week') {
            $myGrowthQuery->where('created_at', '>=', now()->subWeek());
        } elseif ($period === 'month') {
            $myGrowthQuery->where('created_at', '>=', now()->subMonth());
        }
        $myGrowth = (int) $myGrowthQuery->sum('change');

        // Моя позиция в росте (без ручных правок)
        $myPlaceQuery = \App\Models\RatingHistory::selectRaw('user_id, SUM(`change`) as total_growth')
            ->where('reason', '!=', 'Ручная корректировка')
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
        $place = User::visibleInRating()
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

        // Тренд рейтинга — каждый турнир и каждая ручная правка как отдельная точка.
        $rawHistory = \App\Models\RatingHistory::where('user_id', $user->id)
            ->whereNotNull('rating_after')
            ->orderBy('id', 'asc')
            ->get(['id', 'tournament_id', 'rating_after', 'created_at']);

        $entries = [];
        $prevTournamentId = -1;
        foreach ($rawHistory as $h) {
            $tid = $h->tournament_id !== null ? (int) $h->tournament_id : null;
            if ($tid !== null && $tid === $prevTournamentId) {
                $last = count($entries) - 1;
                $entries[$last]['rating_after'] = (int) $h->rating_after;
                $entries[$last]['created_at'] = $h->created_at;
            } else {
                $entries[] = [
                    'tournament_id' => $tid,
                    'rating_after' => (int) $h->rating_after,
                    'created_at' => $h->created_at,
                ];
                $prevTournamentId = $tid ?? -1;
            }
        }
        $entries = array_slice($entries, -10);

        $ratingTrend = array_map(fn($e) => $e['rating_after'], $entries);

        $tournamentIds = array_values(array_filter(array_map(
            fn($e) => $e['tournament_id'],
            $entries,
        ), fn($v) => $v !== null));
        $tournamentsForTrend = $tournamentIds
            ? \App\Models\Tournament::whereIn('id', $tournamentIds)
                ->with('club')
                ->get()
                ->keyBy('id')
            : collect();

        $ratingTrendDetails = [];
        $prevRating = null;
        foreach ($entries as $entry) {
            $rating = (int) $entry['rating_after'];
            $delta = $prevRating === null ? null : $rating - $prevRating;
            if ($entry['tournament_id'] === null) {
                $ratingTrendDetails[] = [
                    'tournament_id' => null,
                    'name' => 'Ручная корректировка',
                    'club_name' => 'Padel Kz',
                    'date' => $entry['created_at']?->translatedFormat('j M Y'),
                    'rating' => $rating,
                    'delta' => $delta,
                    'is_manual' => true,
                ];
            } else {
                $t = $tournamentsForTrend[$entry['tournament_id']] ?? null;
                $ratingTrendDetails[] = [
                    'tournament_id' => $entry['tournament_id'],
                    'name' => $t?->name ?? 'Турнир',
                    'club_name' => $t?->club?->name,
                    'date' => $t?->start_date?->translatedFormat('j M Y'),
                    'rating' => $rating,
                    'delta' => $delta,
                    'is_manual' => false,
                ];
            }
            $prevRating = $rating;
        }

        // История рейтинга — показываем турниры + ручные правки
        // (reason = 'Ручная корректировка', tournament_id = null).
        // Если turn_id есть, но турнир физически удалён — это руины, прячем.
        $ratedHistory = \App\Models\RatingHistory::where('user_id', $user->id)
            ->with('tournament:id,name,type,has_playoff,is_paired')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->filter(fn($h) => $h->tournament_id === null || $h->tournament !== null)
            ->map(function($h) use ($user) {
                if ($h->tournament_id === null) {
                    return [
                        'tournament_id' => null,
                        'tournament_name' => $h->reason ?: 'Ручная корректировка',
                        'tournament_type' => 'manual',
                        'date' => $h->created_at->format('d.m.Y'),
                        'change' => $h->change,
                        'rating_after' => $h->rating_after,
                        'place' => null,
                        'is_manual' => true,
                        'is_rated' => true,
                        '_ts' => $h->created_at->timestamp,
                    ];
                }
                return [
                    'tournament_id' => $h->tournament->id,
                    'tournament_name' => $h->tournament->name,
                    'tournament_type' => $h->tournament->type,
                    'date' => $h->created_at->format('d.m.Y'),
                    'change' => $h->change,
                    'rating_after' => $h->rating_after,
                    'place' => $this->getTournamentPlace($h->tournament, $user->id),
                    'is_manual' => false,
                    'is_rated' => true,
                    '_ts' => $h->created_at->timestamp,
                ];
            });

        // Нерейтинговые завершённые турниры с участием игрока — в rating_history
        // их нет, подмешиваем отдельно (без изменения рейтинга, с местом).
        $uid = $user->id;
        $nonRatedHistory = \App\Models\Tournament::where('is_rated', false)
            ->where('status', 'completed')
            ->where(function ($q) use ($uid) {
                $q->whereHas('groups.players', fn($x) => $x->where('users.id', $uid))
                  ->orWhereHas('mexicanoPlayers', fn($x) => $x->where('user_id', $uid))
                  ->orWhereHas('kingOfCourtPlayers', fn($x) => $x->where('user_id', $uid))
                  ->orWhereHas('roundRobinPlayers', fn($x) => $x->where('user_id', $uid))
                  ->orWhereHas('americanoFlexPlayers', fn($x) => $x->where('user_id', $uid))
                  ->orWhereHas('baliKocPairs', fn($x) => $x->where('player1_id', $uid)->orWhere('player2_id', $uid))
                  ->orWhereHas('teams', fn($x) => $x->where('player1_id', $uid)->orWhere('player2_id', $uid));
            })
            ->orderBy('start_date', 'desc')
            ->limit(50)
            ->get()
            ->map(fn($t) => [
                'tournament_id' => $t->id,
                'tournament_name' => $t->name,
                'tournament_type' => $t->type,
                'date' => $t->start_date->format('d.m.Y'),
                'change' => null,
                'rating_after' => null,
                'place' => $this->getTournamentPlace($t, $user->id),
                'is_manual' => false,
                'is_rated' => false,
                '_ts' => $t->start_date->timestamp,
            ]);

        $history = $ratedHistory->concat($nonRatedHistory)
            ->sortByDesc('_ts')
            ->take(50)
            ->map(function ($i) {
                unset($i['_ts']);
                return $i;
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
                'verification_blockers' => $user->verificationBlockers(),
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
                'rating_trend_details' => $ratingTrendDetails,
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
            'verification_blockers' => $user->verificationBlockers(),
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
        $perPage = 50;

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
            $userIds = User::visibleInRating()
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

        // Проверяем финал (только верхняя сетка — чемпион/призёры из неё)
        $finalMatch = $tournament->playoffMatches()
            ->whereIn('stage', ['final', 'Финал'])
            ->where(function ($q) { $q->where('bracket', 'upper')->orWhereNull('bracket'); })
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

            // Матч за 3-е место (is_bronze) — если сыгран, именно он определяет
            // 3/4 место (приоритетнее эвристики по полуфиналу ниже). Работает
            // одинаково для формата с полуфиналами и для winners_final (без них).
            $bronzeMatch = $tournament->playoffMatches()
                ->where('is_bronze', true)
                ->whereIn('stage', ['final', 'Финал'])
                ->where('status', 'completed')
                ->first();

            if ($bronzeMatch) {
                // Player-based
                if ($bronzeMatch->team1_player1_id) {
                    $inTeam1 = in_array($userId, [$bronzeMatch->team1_player1_id, $bronzeMatch->team1_player2_id]);
                    $inTeam2 = in_array($userId, [$bronzeMatch->team2_player1_id, $bronzeMatch->team2_player2_id]);

                    if ($inTeam1 || $inTeam2) {
                        $team1Won = $bronzeMatch->team1_score > $bronzeMatch->team2_score;
                        return ($inTeam1 && $team1Won) || ($inTeam2 && !$team1Won) ? 3 : 4;
                    }
                }

                // Team-based
                if ($bronzeMatch->team1_id && $myTeamIds->isNotEmpty()) {
                    $inTeam1 = $myTeamIds->contains($bronzeMatch->team1_id);
                    $inTeam2 = $myTeamIds->contains($bronzeMatch->team2_id);

                    if ($inTeam1 || $inTeam2) {
                        if ($bronzeMatch->winner_id && $myTeamIds->contains($bronzeMatch->winner_id)) return 3;
                        return 4;
                    }
                }
            }

            // Полуфинал — 3-4 место (только верхняя сетка, fallback когда нет
            // отдельного/сыгранного матча за 3-е место)
            $semiMatches = $tournament->playoffMatches()
                ->whereIn('stage', ['semi', 'Полуфинал'])
                ->where(function ($q) { $q->where('bracket', 'upper')->orWhereNull('bracket'); })
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

        // Место по лидерборду (americano/mexicano) — единый источник ранжирования
        // (очки → победы → разница → личная встреча), совпадает с «Таблицей».
        if (in_array($tournament->type, ['americano', 'mexicano'])) {
            $place = \App\Support\AmericanoRanking::place($tournament, $userId);
            if ($place !== null) return $place;
        }

        // Король корта — место по лидерборду
        if ($tournament->type === 'king_of_court') {
            // Фикс-пары: место по таблице ПАР (у обоих игроков пары оно одинаковое).
            if ($tournament->isPairedKingOfCourt()) {
                $standings = app(\App\Services\KingOfCourtService::class)->getPairStandings($tournament);
                foreach ($standings as $i => $row) {
                    $pair = $row['pair'];
                    if ((int) $pair->player1_id === $userId || (int) $pair->player2_id === $userId) {
                        return $i + 1;
                    }
                }
                return null;
            }
            $players = $tournament->kingOfCourtPlayers()
                ->orderByDesc('total_points')
                ->orderByDesc('wins')
                ->get();
            foreach ($players as $i => $player) {
                if ($player->user_id === $userId) return $i + 1;
            }
        }

        // Just Padel It — место по лидерборду
        if ($tournament->type === 'just_padel_it') {
            if ($tournament->isPairedJustPadelIt()) {
                $standings = app(\App\Services\JustPadelItService::class)->getPairStandings($tournament);
                foreach ($standings as $i => $row) {
                    $pair = $row['pair'];
                    if ((int) $pair->player1_id === $userId || (int) $pair->player2_id === $userId) {
                        return $i + 1;
                    }
                }
                return null;
            }
            $q = $tournament->justPadelItPlayers();
            if ($tournament->jpi_rank_by_wins) {
                $q->orderByDesc('wins')->orderByDesc('total_points');
            } else {
                $q->orderByDesc('total_points')->orderByDesc('wins');
            }
            foreach ($q->get() as $i => $player) {
                if ($player->user_id === $userId) return $i + 1;
            }
        }

        // Round Robin — место по стандингам (победы → разница → личные встречи)
        if ($tournament->type === 'round_robin') {
            $standings = app(\App\Services\RoundRobinService::class)->standings($tournament);
            foreach ($standings as $i => $row) {
                if ((int) $row['user_id'] === $userId) return $i + 1;
            }
        }

        // Bali Format — место по парам (стандинги с tiebreaker через сервис)
        if ($tournament->type === 'bali_koc') {
            $standings = app(\App\Services\BaliKocService::class)->getStandings($tournament);
            foreach ($standings as $i => $pair) {
                if ((int) $pair->player1_id === $userId || (int) $pair->player2_id === $userId) {
                    return $i + 1;
                }
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

        // Americano Flex — место по среднему за матч → суммарным очкам
        if ($tournament->type === 'americano_flex') {
            // Парный: место по таблице ПАР (игрок — player1 или player2).
            if ($tournament->isPairedFlex()) {
                $pairRows = app(\App\Services\AmericanoFlexService::class)->getPairedLeaderboard($tournament);
                foreach ($pairRows as $i => $r) {
                    $p1 = $r['player1']->id ?? null;
                    $p2 = $r['player2']->id ?? null;
                    if ($p1 === $userId || $p2 === $userId) {
                        return $i + 1;
                    }
                }
                return null;
            }
            $players = $tournament->americanoFlexPlayers()->get()
                ->sort(function ($a, $b) {
                    $avgA = $a->matches_played > 0 ? $a->total_points / $a->matches_played : 0;
                    $avgB = $b->matches_played > 0 ? $b->total_points / $b->matches_played : 0;
                    if ($avgA != $avgB) return $avgB <=> $avgA;
                    return $b->total_points <=> $a->total_points;
                })
                ->values();
            foreach ($players as $i => $fp) {
                if ((int) $fp->user_id === $userId) return $i + 1;
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

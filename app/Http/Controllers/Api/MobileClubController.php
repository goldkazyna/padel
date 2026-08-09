<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AmericanoFlexMatch;
use App\Models\AmericanoMatch;
use App\Models\Club;
use App\Models\CourtPriceRange;
use App\Models\KingOfCourtMatch;
use App\Models\MexicanoMatch;
use App\Models\RatingHistory;
use App\Models\RoundRobinMatch;
use App\Models\Tournament;
use App\Models\TournamentGroupMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentPlayoffMatch;
use App\Models\TournamentTeam;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MobileClubController extends Controller
{
    /**
     * Список всех активных клубов
     * GET /api/mobile/clubs?search=...&city=...
     */
    public function index(Request $request)
    {
        $query = Club::active()->notTest();

        // type: 'club' (default) — без флага сообществ; 'community' — только
        // комьюнити; 'all' — без фильтра.
        $type = $request->get('type', 'club');
        if ($type === 'community') {
            $query->where('is_community', true);
        } elseif ($type === 'club') {
            $query->where(function ($q) {
                $q->where('is_community', false)->orWhereNull('is_community');
            });
        }

        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }

        // Счётчики для иконки кубка и сортировки: проведённые (завершённые)
        // турниры за всё время + текущие открытые турниры.
        $query->withCount([
            'tournaments as tournaments_count' => function ($q) {
                $q->where('status', 'completed');
            },
            'tournaments as open_tournaments_count' => function ($q) {
                $q->where('status', 'open')->where('start_date', '>', now());
            },
        ]);

        // Сортировка. По умолчанию — по активности: 1) число проведённых
        // турниров; 2) у кого есть открытые — выше; 3) «скоро открытие»
        // (coming_soon) — в самый конец.
        //
        // sort=created — по дате добавления. Нужен там, где важен
        // предсказуемый порядок (выбор клуба в бронировании): по активности
        // список перетасовывается сам собой после каждого турнира.
        $sortByCreated = $request->get('sort') === 'created';

        $clubs = $query
            ->when($sortByCreated, function ($q) {
                // «Скоро открытие» держим внизу и здесь: записываться туда
                // всё равно нельзя.
                $q->orderBy('coming_soon')->orderBy('created_at')->orderBy('id');
            }, function ($q) {
                $q->orderByDesc('tournaments_count')
                    ->orderByDesc('open_tournaments_count')
                    ->orderBy('coming_soon')
                    ->orderBy('id');
            })
            ->get();

        $result = $clubs->map(fn($club) => [
            'id' => $club->id,
            'name' => $club->name,
            'address' => $club->address,
            'city' => $club->city,
            'logo' => $club->logo ? url($club->logo) : null,
            'description' => $club->description,
            'phone' => $club->phone,
            'is_community' => (bool) $club->is_community,
            'coming_soon' => (bool) $club->coming_soon,
            'telegram_url' => $club->telegram_url,
            'instagram_url' => $club->instagram_url,
            'tournaments_count' => (int) $club->tournaments_count,
        ]);

        $cities = $clubs->pluck('city')->filter()->unique()->sort()->values();

        return response()->json([
            'success' => true,
            'clubs' => $result,
            'cities' => $cities,
        ]);
    }

    /**
     * Карточка клуба
     * GET /api/mobile/clubs/{club}
     */
    public function show(Club $club)
    {
        if (!$club->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Клуб недоступен',
            ], 404);
        }

        $courtsCount = $club->courts()->where('is_active', true)->count();

        $minPrice = CourtPriceRange::whereHas('court', function ($q) use ($club) {
                $q->where('club_id', $club->id)->where('is_active', true);
            })
            ->min('price');

        $user = Auth::user();
        $hiddenIds = $user ? ($user->hidden_club_ids ?? []) : [];
        $isHidden = in_array($club->id, $hiddenIds, true);

        // Тренеры клуба — показываем только тех, у кого есть фото
        $coaches = $club->clubCoaches()
            ->with('user:id,name,first_name,last_name')
            ->whereNotNull('photo')
            ->where('photo', '!=', '')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->user?->full_name ?: $c->user?->name,
                'specialization' => $c->specialization,
                'photo' => url($c->photo),
                'rating' => $c->rating,
            ])
            ->filter(fn($c) => !empty($c['name']))
            ->values();

        return response()->json([
            'success' => true,
            'club' => [
                'id' => $club->id,
                'name' => $club->name,
                'address' => $club->address,
                'city' => $club->city,
                'logo' => $club->logo ? url($club->logo) : null,
                'description' => $club->description,
                'phone' => $club->phone,
                'email' => $club->email,
                'courts_count' => $courtsCount,
                'min_price' => $minPrice !== null ? (float) $minPrice : null,
                'is_hidden' => $isHidden,
                'telegram_url' => $club->telegram_url,
                'instagram_url' => $club->instagram_url,
                'cover' => $club->cover ? url($club->cover) : null,
                'is_community' => (bool) $club->is_community,
                'coming_soon' => (bool) $club->coming_soon,
                'open_tournaments_count' => $club->tournaments()
                    ->where('status', 'open')
                    ->where('start_date', '>', now())
                    ->count(),
                'tournaments_count' => $club->tournaments()
                    ->where('status', 'completed')
                    ->count(),
                'coaches' => $coaches,
            ],
        ]);
    }

    /**
     * Статистика клуба за период — лидерборд игроков.
     * GET /api/mobile/clubs/{club}/stats?from=YYYY-MM-DD&to=YYYY-MM-DD
     * По умолчанию — текущий месяц. Сортировка по заработанному рейтингу.
     */
    public function stats(Request $request, Club $club)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);

        $from = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : now()->startOfMonth();
        $to = $request->filled('to')
            ? Carbon::parse($request->to)->endOfDay()
            : now()->endOfDay();

        // Турниры клуба за период (по дате старта).
        $tournamentIds = Tournament::where('club_id', $club->id)
            ->whereBetween('start_date', [$from, $to])
            ->pluck('id');

        $players = [];

        if ($tournamentIds->isNotEmpty()) {
            // Заработанный рейтинг — из истории рейтинга (только рейтинговые турниры).
            $rows = RatingHistory::whereIn('tournament_id', $tournamentIds)
                ->selectRaw('user_id, SUM(`change`) as rating_earned')
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            // Число сыгранных турниров — по участию (registered), а не по рейтингу:
            // рейтинг начисляется не во всех турнирах, поэтому считать по нему — недосчёт.
            $tournamentCounts = TournamentParticipant::whereIn('tournament_id', $tournamentIds)
                ->where('status', 'registered')
                ->selectRaw('user_id, COUNT(DISTINCT tournament_id) as cnt')
                ->groupBy('user_id')
                ->pluck('cnt', 'user_id');

            // Победы/поражения по матчам турниров клуба за период.
            $winStats = $this->collectClubWinStats($tournamentIds);

            // Игроки = объединение всех, у кого есть участие / рейтинг / матчи.
            $userIds = $rows->keys()
                ->merge($tournamentCounts->keys())
                ->merge(array_keys($winStats))
                ->unique();
            $users = User::whereIn('id', $userIds)->get()->keyBy('id');

            $players = $userIds->map(function ($uid) use ($rows, $tournamentCounts, $winStats, $users) {
                $u = $users[$uid] ?? null;
                if (!$u) {
                    return null;
                }
                $row = $rows[$uid] ?? null;
                $w = $winStats[$uid] ?? ['won' => 0, 'lost' => 0];
                $total = $w['won'] + $w['lost'];

                return [
                    'user' => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'avatar' => $u->avatar,
                        'level' => $u->level,
                        'rating' => $u->rating,
                        'level_verified' => (bool) $u->level_verified,
                    ],
                    'tournaments' => (int) ($tournamentCounts[$uid] ?? 0),
                    'rating_earned' => $row ? (int) $row->rating_earned : 0,
                    'wins' => $w['won'],
                    'losses' => $w['lost'],
                    'winrate' => $total > 0 ? (int) round($w['won'] / $total * 100) : 0,
                ];
            })->filter()->sortByDesc('rating_earned')->values()->all();
        }

        return response()->json([
            'success' => true,
            'club' => [
                'id' => $club->id,
                'name' => $club->name,
                'logo' => $club->logo ? url($club->logo) : null,
            ],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'players' => $players,
        ]);
    }

    /**
     * Победы/поражения игроков по матчам турниров клуба (все типы).
     * Возвращает [user_id => ['won' => int, 'lost' => int]].
     */
    private function collectClubWinStats($tournamentIds): array
    {
        $stats = [];

        // Применить парный матч (по player_id) ко всем 4 игрокам.
        $applyPlayerMatch = function ($m) use (&$stats) {
            if ($m->team1_score == $m->team2_score) {
                return;
            }
            $team1Won = $m->team1_score > $m->team2_score;
            $t1 = array_filter([$m->team1_player1_id, $m->team1_player2_id]);
            $t2 = array_filter([$m->team2_player1_id, $m->team2_player2_id]);
            foreach ($t1 as $pid) {
                $stats[$pid] ??= ['won' => 0, 'lost' => 0];
                $team1Won ? $stats[$pid]['won']++ : $stats[$pid]['lost']++;
            }
            foreach ($t2 as $pid) {
                $stats[$pid] ??= ['won' => 0, 'lost' => 0];
                $team1Won ? $stats[$pid]['lost']++ : $stats[$pid]['won']++;
            }
        };

        // Парные типы: раунд → турнир.
        foreach ([MexicanoMatch::class, KingOfCourtMatch::class, AmericanoFlexMatch::class, RoundRobinMatch::class] as $model) {
            $model::where('status', 'completed')
                ->whereHas('round', fn($q) => $q->whereIn('tournament_id', $tournamentIds))
                ->get()
                ->each($applyPlayerMatch);
        }

        // Американо: раунд → группа → турнир.
        AmericanoMatch::where('status', 'completed')
            ->whereHas('round.group', fn($q) => $q->whereIn('tournament_id', $tournamentIds))
            ->get()
            ->each($applyPlayerMatch);

        // Плей-офф американо/мексикано (по player_id).
        TournamentPlayoffMatch::where('status', 'completed')
            ->whereNotNull('team1_player1_id')
            ->whereIn('tournament_id', $tournamentIds)
            ->get()
            ->each($applyPlayerMatch);

        // Командные турниры — атрибутируем по составам команд.
        $teams = TournamentTeam::whereIn('tournament_id', $tournamentIds)
            ->get(['id', 'player1_id', 'player2_id'])
            ->keyBy('id');

        if ($teams->isNotEmpty()) {
            $applyTeamMatch = function ($m) use (&$stats, $teams) {
                if ($m->team1_score == $m->team2_score) {
                    return;
                }
                $team1Won = $m->team1_score > $m->team2_score;
                $winTeam = $teams[$team1Won ? $m->team1_id : $m->team2_id] ?? null;
                $loseTeam = $teams[$team1Won ? $m->team2_id : $m->team1_id] ?? null;
                foreach (array_filter([$winTeam?->player1_id, $winTeam?->player2_id]) as $pid) {
                    $stats[$pid] ??= ['won' => 0, 'lost' => 0];
                    $stats[$pid]['won']++;
                }
                foreach (array_filter([$loseTeam?->player1_id, $loseTeam?->player2_id]) as $pid) {
                    $stats[$pid] ??= ['won' => 0, 'lost' => 0];
                    $stats[$pid]['lost']++;
                }
            };

            // Групповой этап: матч → группа → турнир.
            TournamentGroupMatch::where('status', 'completed')
                ->whereHas('group', fn($q) => $q->whereIn('tournament_id', $tournamentIds))
                ->get()
                ->each($applyTeamMatch);

            // Плей-офф командный (team1_player1_id = null).
            TournamentPlayoffMatch::where('status', 'completed')
                ->whereNull('team1_player1_id')
                ->whereIn('tournament_id', $tournamentIds)
                ->get()
                ->each($applyTeamMatch);
        }

        return $stats;
    }

    /**
     * Переключить скрытие турниров клуба для пользователя
     * POST /api/mobile/clubs/{club}/toggle-hide
     */
    public function toggleHide(Club $club)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $hidden = $user->hidden_club_ids ?? [];
        if (in_array($club->id, $hidden, true)) {
            $hidden = array_values(array_filter($hidden, fn($id) => $id !== $club->id));
        } else {
            $hidden[] = $club->id;
        }

        $user->hidden_club_ids = array_values(array_unique($hidden));
        $user->save();

        return response()->json([
            'success' => true,
            'is_hidden' => in_array($club->id, $user->hidden_club_ids, true),
        ]);
    }
}

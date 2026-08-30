<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\League;
use App\Models\User;
use App\Services\LeagueService;
use App\Support\LeagueStandings;
use App\Support\NameSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Лиги в мобильной админке: создать лигу, добавить этап, вести состав.
 *
 * Правила совпадают с веб-CRM — общие в LeagueService: организатор заводит
 * лигу с телефона, а доводит с компьютера, и наоборот.
 */
class MobileAdminLeagueController extends Controller
{
    private function club(Request $request): ?Club
    {
        $user = $request->user();
        if (!$user) return null;
        if ($user->isSuperAdmin()) return Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();

        return $user->adminClubs()->first();
    }

    /** Лига принадлежит клубу админа — иначе не его дело. */
    private function guard(Request $request, League $league): ?JsonResponse
    {
        $club = $this->club($request);
        if (!$club || (int) $league->club_id !== (int) $club->id) {
            return response()->json(['success' => false, 'message' => 'Нет доступа'], 403);
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        $club = $this->club($request);
        if (!$club) {
            return response()->json(['success' => false, 'message' => 'Нет клуба'], 403);
        }

        $leagues = League::where('club_id', $club->id)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'leagues' => $leagues->map(fn ($league) => $this->card($league))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $club = $this->club($request);
        if (!$club) {
            return response()->json(['success' => false, 'message' => 'Нет клуба'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'stages_planned' => 'required|integer|min:2|max:30',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'min_level' => 'nullable|numeric|min:1|max:7',
            'max_level' => 'nullable|numeric|min:1|max:7|gte:min_level',
            'max_players' => 'nullable|integer|min:4|max:200',
            'price' => 'nullable|integer|min:0',
            'is_rated' => 'nullable|boolean',
            // Настройки этапов — те же, что в веб-CRM.
            'courts_count' => 'nullable|integer|min:1|max:8',
            'duration_hours' => 'nullable|integer|min:1|max:8',
            'points_to_win' => 'nullable|integer|in:16,21,24,32,42',
            'is_paired' => 'nullable|boolean',
            'verified_only' => 'nullable|boolean',
            'chat_enabled' => 'nullable|boolean',
        ]);

        $league = League::create($validated + [
            'club_id' => $club->id,
            'creator_id' => $request->user()->id,
            'status' => 'open',
            'is_rated' => $request->boolean('is_rated', true),
            'is_paired' => $request->boolean('is_paired'),
            'verified_only' => $request->boolean('verified_only'),
            'chat_enabled' => $request->boolean('chat_enabled', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Лига создана',
            'league' => $this->card($league),
        ]);
    }

    public function show(Request $request, League $league): JsonResponse
    {
        if ($denied = $this->guard($request, $league)) return $denied;

        $league->load(['stages' => fn ($q) => $q->orderBy('league_stage')]);
        $standings = LeagueStandings::build($league);

        return response()->json([
            'success' => true,
            'league' => array_merge($this->card($league), [
                'description' => $league->description,
                'stages' => $league->stages->map(fn ($stage) => [
                    'id' => $stage->id,
                    'stage' => (int) $stage->league_stage,
                    'name' => $stage->name,
                    'status' => $stage->status,
                    'status_name' => $stage->status_name,
                    'start_date' => $stage->start_date?->toIso8601String(),
                    'participants' => $stage->participants()->count(),
                    'max_participants' => $stage->max_participants,
                ])->values(),
                // Ключ roster, а не players: там уже лежит счётчик участников.
                'roster' => $league->players()->with('user:id,name,avatar,level,rating')->get()
                    ->map(fn ($row) => [
                        'user_id' => $row->user_id,
                        'name' => $row->user->name ?? 'Игрок',
                        'avatar' => $row->user->avatar ?? null,
                        'level' => $row->user->level !== null ? (float) $row->user->level : null,
                        'rating' => (int) ($row->user->rating ?? 0),
                        'status' => $row->status,
                    ])
                    ->sortBy('name')
                    ->values(),
                'standings' => collect($standings)->map(fn ($row) => [
                    'position' => $row['position'],
                    'user_id' => $row['id'],
                    'name' => $row['name'],
                    'avatar' => $row['avatar'],
                    'stages' => $row['stages'],
                    'wins' => $row['wins'],
                    'losses' => $row['losses'],
                    'draws' => $row['draws'],
                    'points_for' => $row['points_for'],
                    'points_against' => $row['points_against'],
                    'diff' => $row['diff'],
                    'average' => $row['average'],
                    'is_me' => false,
                ])->values(),
            ]),
        ]);
    }

    public function update(Request $request, League $league): JsonResponse
    {
        if ($denied = $this->guard($request, $league)) return $denied;

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:5000',
            'stages_planned' => 'sometimes|integer|min:2|max:30',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'min_level' => 'nullable|numeric|min:1|max:7',
            'max_level' => 'nullable|numeric|min:1|max:7|gte:min_level',
            'max_players' => 'nullable|integer|min:4|max:200',
            'price' => 'nullable|integer|min:0',
            'status' => 'sometimes|in:draft,open,in_progress,completed,cancelled',
            'courts_count' => 'sometimes|integer|min:1|max:8',
            'duration_hours' => 'nullable|integer|min:1|max:8',
            'points_to_win' => 'nullable|integer|in:16,21,24,32,42',
            'is_paired' => 'sometimes|boolean',
            'verified_only' => 'sometimes|boolean',
            'chat_enabled' => 'sometimes|boolean',
        ]);

        $league->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Лига обновлена',
            'league' => $this->card($league->fresh()),
        ]);
    }

    public function addStage(Request $request, League $league, LeagueService $service): JsonResponse
    {
        if ($denied = $this->guard($request, $league)) return $denied;

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'max_participants' => 'required|integer|min:4|max:64',
            'price' => 'nullable|numeric|min:0',
            'courts_count' => 'nullable|integer|min:1|max:16',
        ]);

        $tournament = $service->createStage($league, $validated, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => "Этап {$tournament->league_stage} создан, состав лиги записан",
            'tournament_id' => $tournament->id,
        ]);
    }

    public function addPlayer(Request $request, League $league, LeagueService $service): JsonResponse
    {
        if ($denied = $this->guard($request, $league)) return $denied;

        $validated = $request->validate(['user_id' => 'required|exists:users,id']);
        $service->addPlayer($league, (int) $validated['user_id']);

        return response()->json(['success' => true, 'message' => 'Игрок добавлен в лигу']);
    }

    public function removePlayer(Request $request, League $league, User $user, LeagueService $service): JsonResponse
    {
        if ($denied = $this->guard($request, $league)) return $denied;

        $service->removePlayer($league, $user->id);

        return response()->json(['success' => true, 'message' => 'Игрок убран из состава']);
    }

    /** Поиск игроков для состава: умный и без тех, кто уже в лиге. */
    public function searchPlayers(Request $request, League $league): JsonResponse
    {
        if ($denied = $this->guard($request, $league)) return $denied;

        $q = trim((string) $request->get('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'players' => []]);
        }

        $digits = preg_replace('/\D/', '', $q);
        $inLeague = $league->activePlayers()->pluck('user_id');

        $players = User::human()
            ->where(function ($w) use ($q, $digits) {
                foreach (NameSearch::variants($q) as $variant) {
                    $w->orWhere('name', 'like', "%{$variant}%");
                }
                if (strlen($digits) >= 3) {
                    $w->orWhere('phone', 'like', "%{$digits}%");
                }
            })
            ->whereNotIn('id', $inLeague)
            ->tap(fn ($w) => NameSearch::orderExactFirst($w, $q, ['name']))
            ->limit(15)
            ->get(['id', 'name', 'phone', 'level', 'rating', 'avatar']);

        return response()->json(['success' => true, 'players' => $players]);
    }

    private function card(League $league): array
    {
        $summary = LeagueStandings::summary($league);

        return [
            'id' => $league->id,
            'name' => $league->name,
            'status' => $league->status,
            'status_name' => $league->status_name,
            'start_date' => $league->start_date?->toIso8601String(),
            'end_date' => $league->end_date?->toIso8601String(),
            'min_level' => $league->min_level !== null ? (float) $league->min_level : null,
            'max_level' => $league->max_level !== null ? (float) $league->max_level : null,
            'price' => $league->price,
            'max_players' => $league->max_players,
            'stages_planned' => $league->stages_planned,
            'is_paired' => (bool) $league->is_paired,
            'courts_count' => (int) ($league->courts_count ?? 2),
            'duration_hours' => $league->duration_hours,
            'points_to_win' => $league->points_to_win,
            'verified_only' => (bool) $league->verified_only,
            'chat_enabled' => (bool) $league->chat_enabled,
            'stages_total' => $summary['stages_total'],
            'stages_done' => $summary['stages_done'],
            'players' => $summary['players'],
            'is_registered' => false,
            'next_stage' => $summary['next_stage'] ? [
                'id' => $summary['next_stage']->id,
                'stage' => (int) $summary['next_stage']->league_stage,
                'name' => $summary['next_stage']->name,
                'start_date' => $summary['next_stage']->start_date?->toIso8601String(),
            ] : null,
        ];
    }
}

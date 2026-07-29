<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MobileGameController extends Controller
{
    /** Справочник клубов для создания игры (активные, опц. фильтр по городу). */
    public function clubs(Request $request)
    {
        $user = $request->user();

        $query = Club::active()->notTest();
        if (!empty($user->city)) {
            $query->where('city', $user->city);
        }

        $clubs = $query->orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'address' => $c->address,
            'city' => $c->city,
        ]);

        return response()->json(['success' => true, 'data' => $clubs]);
    }

    /** Создать игру. Создатель занимает слот 1. Генерится ссылка-приглашение. */
    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $this->validateGame($request);

        // Длительность 30 мин – 6 ч.
        $mins = Carbon::parse($validated['starts_at'])->diffInMinutes(Carbon::parse($validated['ends_at']));
        if ($mins < 30 || $mins > 360) {
            return response()->json([
                'success' => false,
                'message' => 'Длительность игры должна быть от 30 минут до 6 часов',
            ], 422);
        }

        $game = Game::create([
            'creator_id' => $user->id,
            'club_id' => $validated['club_id'],
            'court_id' => $validated['court_id'] ?? null,
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'type' => $validated['type'],
            'visibility' => $validated['visibility'],
            'format' => $validated['format'],
            'format_meta' => $validated['format_meta'] ?? null,
            'rating_min' => $validated['rating_min'] ?? null,
            'rating_max' => $validated['rating_max'] ?? null,
            'capacity' => 4,
            'price' => $validated['price'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => Game::STATUS_OPEN,
            'share_token' => $this->uniqueShareToken(),
            'share_expires_at' => $validated['starts_at'], // по умолчанию до старта
        ]);

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'position' => 1,
            'status' => GamePlayer::STATUS_ACCEPTED,
            'source' => GamePlayer::SOURCE_CREATOR,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ], 201);
    }

    /** Общие правила валидации создания/редактирования. */
    private function validateGame(Request $request): array
    {
        return $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'court_id' => 'nullable|exists:courts,id',
            'starts_at' => 'required|date|after:now',
            'ends_at' => 'required|date|after:starts_at',
            'type' => 'required|in:rated,friendly',
            'visibility' => 'required|in:public,private',
            'format' => 'required|in:sets,points,americano',
            'format_meta' => 'nullable|array',
            'rating_min' => 'nullable|numeric|min:1|max:5.75',
            'rating_max' => 'nullable|numeric|min:1|max:5.75|gte:rating_min',
            'price' => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:1000',
        ]);
    }

    private function uniqueShareToken(): string
    {
        do {
            $token = Str::random(32);
        } while (Game::where('share_token', $token)->exists());
        return $token;
    }

    /** Детали игры. */
    public function show(Request $request, Game $game)
    {
        $game->load(['creator', 'club', 'court', 'players.user']);
        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game, $request->user()),
        ]);
    }

    /** Лента игр — только публичные, набирающие состав, будущие. */
    public function index(Request $request)
    {
        $user = $request->user();
        $games = Game::with(['creator', 'club', 'court', 'players.user'])
            ->where('visibility', Game::VISIBILITY_PUBLIC)
            ->whereIn('status', [Game::STATUS_OPEN, Game::STATUS_FULL])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->get()
            ->map(fn ($g) => $this->formatGame($g, $user));

        return response()->json(['success' => true, 'data' => $games]);
    }

    /** Форматтер игры для API. $user может быть null (публичный переход по ссылке). */
    public function formatGame(Game $game, ?User $user): array
    {
        $players = $game->players->map(function ($p) use ($user) {
            $name = $p->user->name ?? 'Без имени';
            return [
                'id' => $p->user->id,
                'position' => $p->position,
                'status' => $p->status,
                'source' => $p->source,
                'out_of_range' => (bool) $p->out_of_range,
                'full_name' => $name,
                'avatar' => $p->user->avatar,
                'rating' => $p->user->rating,
                'level' => (float) $p->user->level,
                'is_me' => $user && $p->user->id === $user->id,
            ];
        })->values();

        return [
            'id' => $game->id,
            'creator_id' => $game->creator_id,
            'is_creator' => $user && $game->creator_id === $user->id,
            'club' => $game->club ? ['id' => $game->club->id, 'name' => $game->club->name] : null,
            'court_id' => $game->court_id,
            'starts_at' => $game->starts_at?->toIso8601String(),
            'ends_at' => $game->ends_at?->toIso8601String(),
            'type' => $game->type,
            'type_name' => $game->type_name,
            'visibility' => $game->visibility,
            'format' => $game->format,
            'format_name' => $game->format_name,
            'format_meta' => $game->format_meta,
            'rating_min' => $game->rating_min !== null ? (float) $game->rating_min : null,
            'rating_max' => $game->rating_max !== null ? (float) $game->rating_max : null,
            'capacity' => (int) $game->capacity,
            'price' => $game->price,
            'description' => $game->description,
            'status' => $game->status,
            'status_name' => $game->status_name,
            'score_locked' => (bool) $game->score_locked,
            'share_token' => $game->share_token,
            'share_active' => $game->shareLinkActive(),
            'available_positions' => $game->getAvailablePositions(),
            'accepted_count' => $game->acceptedCount(),
            'players' => $players,
        ];
    }
}

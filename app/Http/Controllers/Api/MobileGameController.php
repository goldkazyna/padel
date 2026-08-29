<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Game;
use App\Models\GameActionLog;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\GameTransfer;
use App\Models\Invitation;
use App\Models\Notification;
use App\Models\RatingHistory;
use App\Models\User;
use App\Services\FCMNotificationService;
use App\Support\GameAmericanoRanking;
use App\Traits\RatingCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MobileGameController extends Controller
{
    use RatingCalculator;

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

        // Валидация format_meta по формату
        $metaErr = $this->validateFormatMeta($validated['format'], $validated['format_meta'] ?? null);
        if ($metaErr !== null) {
            return response()->json(['success' => false, 'message' => $metaErr], 422);
        }

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

    /** Валидация format_meta по формату. Возвращает текст ошибки или null. */
    private function validateFormatMeta(string $format, ?array $meta): ?string
    {
        $meta = $meta ?? [];

        if ($format === Game::FORMAT_POINTS) {
            $mode = $meta['points_mode'] ?? null;
            if (!in_array($mode, ['first_to', 'total'], true)) {
                return 'Укажите режим: до N очков или на сумму очков';
            }
            if ($mode === 'first_to') {
                $target = $meta['points_target'] ?? null;
                if (!is_int($target) || $target < 1) {
                    return 'Укажите целевое количество очков';
                }
            }
            if (array_key_exists('points_cap', $meta) && $meta['points_cap'] !== null) {
                if (!is_int($meta['points_cap']) || $meta['points_cap'] < 1) {
                    return 'Некорректный лимит очков';
                }
            }
            return null;
        }

        if ($format === Game::FORMAT_AMERICANO) {
            $sub = $meta['sub'] ?? null;
            if (!in_array($sub, ['by_sets', 'by_tiebreak', 'by_points'], true)) {
                return 'Выберите подформат Американо';
            }
            $target = $meta['target'] ?? null;
            if (!is_int($target) || $target < 1) {
                return 'Укажите значение для подформата';
            }
            return null;
        }

        // sets: format_meta опционален; если есть tiebreak — должен быть boolean.
        if (array_key_exists('tiebreak', $meta) && !is_bool($meta['tiebreak'])) {
            return 'Некорректное значение тай-брейка';
        }
        return null;
    }

    private function uniqueShareToken(): string
    {
        do {
            $token = Str::random(32);
        } while (Game::where('share_token', $token)->exists());
        return $token;
    }

    /** Уведомление участнику игры: запись в notifications + FCM. */
    private function notifyGame(User $user, string $title, string $body, string $type, int $gameId): void
    {
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'category' => 'game',
            'data' => ['game_id' => $gameId],
        ]);

        app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
            'type' => $type,
            'game_id' => (string) $gameId,
        ]);
    }

    /** Записать действие в журнал игры. */
    private function logGameAction(Game $game, int $userId, string $action, array $payload = []): void
    {
        GameActionLog::create([
            'game_id' => $game->id,
            'user_id' => $userId,
            'action' => $action,
            'payload' => $payload ?: null,
        ]);
    }

    /** Синхронизировать статус open/full по числу accepted (пока игра не начата). */
    private function syncFullness(Game $game): void
    {
        $game->refresh();
        if (!in_array($game->status, [Game::STATUS_OPEN, Game::STATUS_FULL], true)) {
            return;
        }
        $accepted = $game->acceptedCount();
        $target = $accepted >= (int) $game->capacity ? Game::STATUS_FULL : Game::STATUS_OPEN;
        if ($game->status !== $target) {
            $game->update(['status' => $target]);
        }
    }

    /** Первая свободная позиция (1..capacity), либо null. */
    private function nextFreePosition(Game $game): ?int
    {
        $free = $game->getAvailablePositions();
        return $free[0] ?? null;
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

    /** Лента игр — публичные, набирающие состав, будущие; фильтры + пагинация. */
    public function index(Request $request)
    {
        $user = $request->user();

        $filters = $request->validate([
            'club_id' => 'nullable|integer',
            'format' => 'nullable|in:sets,points,americano',
            'type' => 'nullable|in:rated,friendly',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:50',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = Game::with(['creator', 'club', 'court', 'players.user'])
            ->where('visibility', Game::VISIBILITY_PUBLIC)
            ->whereIn('status', [Game::STATUS_OPEN, Game::STATUS_FULL])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at');

        if (!empty($filters['club_id'])) {
            $query->where('club_id', $filters['club_id']);
        }
        if (!empty($filters['format'])) {
            $query->where('format', $filters['format']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('starts_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('starts_at', '<=', $filters['date_to']);
        }

        // Диапазон рейтинга — фильтр «самотёка» (S3).
        if (!$request->boolean('show_out_of_level')) {
            $level = (float) $user->level;
            $query->where(function ($q) use ($level) {
                $q->whereNull('rating_min')->orWhere('rating_min', '<=', $level);
            })->where(function ($q) use ($level) {
                $q->whereNull('rating_max')->orWhere('rating_max', '>=', $level);
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $query->paginate($perPage);
        $data = collect($paginator->items())->map(fn ($g) => $this->formatGame($g, $user));

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /** Мои игры: где я организатор или активный участник. */
    public function myGames(Request $request)
    {
        $user = $request->user();
        $filters = $request->validate([
            'status' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:50',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = Game::with(['creator', 'club', 'court', 'players.user'])
            ->where(function ($q) use ($user) {
                $q->where('creator_id', $user->id)
                    ->orWhereHas('players', function ($p) use ($user) {
                        $p->where('user_id', $user->id)
                            ->whereNotIn('status', [
                                GamePlayer::STATUS_DECLINED,
                                GamePlayer::STATUS_LEFT,
                                GamePlayer::STATUS_REMOVED,
                            ]);
                    });
            })
            ->orderByDesc('starts_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $query->paginate($perPage);
        $data = collect($paginator->items())->map(fn ($g) => $this->formatGame($g, $user));

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /** Инбокс: приглашения текущего пользователя в игры. */
    public function invitations(Request $request)
    {
        $user = $request->user();
        $status = $request->query('status');

        $query = Invitation::where('user_id', $user->id)
            ->where('kind', Invitation::KIND_GAME)
            ->where('invitable_type', Game::class)
            ->with(['inviter:id,name', 'invitable.creator', 'invitable.club', 'invitable.court', 'invitable.players.user'])
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        } else {
            $query->where('status', Invitation::STATUS_PENDING)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                });
        }

        $data = $query->get()
            ->filter(fn ($inv) => $inv->invitable !== null) // игра могла быть удалена
            ->map(fn ($inv) => [
                'invitation_id' => $inv->id,
                'status' => $inv->status,
                'expires_at' => $inv->expires_at?->toIso8601String(),
                'inviter' => $inv->inviter ? ['id' => $inv->inviter->id, 'name' => $inv->inviter->name] : null,
                'game' => $this->formatGame($inv->invitable, $user),
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Уровень пользователя в диапазоне игры (null-границы = без ограничения). */
    private function userInRange(Game $game, User $user): bool
    {
        $level = (float) $user->level;
        if ($game->rating_min !== null && $level < (float) $game->rating_min) {
            return false;
        }
        if ($game->rating_max !== null && $level > (float) $game->rating_max) {
            return false;
        }
        return true;
    }

    /** Редактировать игру. Только организатор, пока счёт не залочен. */
    public function update(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор может редактировать игру'], 403);
        }
        if ($game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Счёт утверждён, редактирование недоступно'], 403);
        }

        $validated = $this->validateGame($request);

        $metaErr = $this->validateFormatMeta($validated['format'], $validated['format_meta'] ?? null);
        if ($metaErr !== null) {
            return response()->json(['success' => false, 'message' => $metaErr], 422);
        }

        $mins = Carbon::parse($validated['starts_at'])->diffInMinutes(Carbon::parse($validated['ends_at']));
        if ($mins < 30 || $mins > 360) {
            return response()->json(['success' => false, 'message' => 'Длительность игры должна быть от 30 минут до 6 часов'], 422);
        }

        $game->update([
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
            'price' => $validated['price'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Перевыпустить ссылку-приглашение (старая перестаёт работать). */
    public function shareRotate(Request $request, Game $game)
    {
        if (!$game->isOrganizer($request->user()->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        $game->update([
            'share_token' => $this->uniqueShareToken(),
            'share_revoked_at' => null,
            'share_uses' => 0,
        ]);
        return response()->json([
            'success' => true,
            'data' => ['share_token' => $game->share_token, 'share_active' => $game->shareLinkActive()],
        ]);
    }

    /** Отозвать ссылку-приглашение. */
    public function shareRevoke(Request $request, Game $game)
    {
        if (!$game->isOrganizer($request->user()->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        $game->update(['share_revoked_at' => now()]);
        return response()->json(['success' => true]);
    }

    /** Публичный переход по ссылке-приглашению → карточка игры. */
    public function resolveByShare(string $token)
    {
        $game = Game::where('share_token', $token)->first();
        if (!$game) {
            return response()->json(['success' => false, 'message' => 'Ссылка не найдена'], 404);
        }
        if (!$game->shareLinkActive()) {
            return response()->json(['success' => false, 'message' => 'Ссылка недействительна'], 410);
        }
        $game->load(['creator', 'club', 'court', 'players.user']);
        return response()->json(['success' => true, 'data' => $this->formatGame($game, null)]);
    }

    /** Форматтер игры для API. $user может быть null (публичный переход по ссылке). */
    public function formatGame(Game $game, ?User $user): array
    {
        $players = $game->players->map(function ($p) use ($user) {
            $name = $p->user->name ?? 'Без имени';
            return [
                'id' => $p->user->id,
                'player_id' => $p->id,
                'position' => $p->position,
                'status' => $p->status,
                'source' => $p->source,
                'out_of_range' => (bool) $p->out_of_range,
                'full_name' => $name,
                'avatar' => $p->user->avatar,
                'rating' => $p->user->rating,
                'level' => (float) $p->user->level,
                'is_me' => $user && $p->user->id === $user->id,
                'score_confirmed' => (bool) $p->score_confirmed,
                'rating_before' => $p->rating_before,
                'rating_after' => $p->rating_after,
                'rating_change' => $p->rating_change,
            ];
        })->values();

        $mine = $user ? $game->players->firstWhere('user_id', $user->id) : null;

        $rounds = $game->relationLoaded('rounds')
            ? $game->rounds
            : $game->rounds()->orderBy('round_no')->get();

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
            'is_participant' => $mine !== null,
            'my_status' => $mine?->status,
            'my_position' => $mine?->position,
            'my_score_confirmed' => (bool) ($mine?->score_confirmed),
            'rounds' => $rounds->map(fn ($r) => [
                'id' => $r->id,
                'round_no' => $r->round_no,
                'pair_a' => $r->pair_a,
                'pair_b' => $r->pair_b,
                'score_a' => $r->score_a,
                'score_b' => $r->score_b,
                'tiebreak_a' => $r->tiebreak_a,
                'tiebreak_b' => $r->tiebreak_b,
                'is_played' => (bool) $r->is_played,
            ])->values(),
            'americano_ranking' => $game->format === Game::FORMAT_AMERICANO
                ? GameAmericanoRanking::table($game)
                : null,
        ];
    }

    /** Персональное приглашение игрока (только организатор). */
    public function invite(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор может приглашать'], 403);
        }
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'position' => 'nullable|integer|min:1',
        ]);

        $invitee = User::find($data['user_id']);
        $outOfRange = !$this->userInRange($game, $invitee);

        $existing = $game->players()->where('user_id', $data['user_id'])->first();
        $activeStatuses = [GamePlayer::STATUS_INVITED, GamePlayer::STATUS_CANDIDATE, GamePlayer::STATUS_ACCEPTED];
        if ($existing && in_array($existing->status, $activeStatuses, true)) {
            return response()->json(['success' => false, 'message' => 'Игрок уже в игре'], 422);
        }

        $free = $game->getAvailablePositions();
        $position = (!empty($data['position']) && in_array($data['position'], $free, true))
            ? $data['position']
            : ($free[0] ?? null);

        if ($existing) {
            $existing->update([
                'status' => GamePlayer::STATUS_INVITED,
                'source' => GamePlayer::SOURCE_INVITE,
                'position' => $position,
                'responded_at' => null,
                'out_of_range' => $outOfRange,
            ]);
        } else {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $data['user_id'],
                'position' => $position,
                'status' => GamePlayer::STATUS_INVITED,
                'source' => GamePlayer::SOURCE_INVITE,
                'out_of_range' => $outOfRange,
            ]);
        }

        $invitation = Invitation::where('invitable_type', Game::class)
            ->where('invitable_id', $game->id)
            ->where('user_id', $data['user_id'])
            ->first();
        if ($invitation) {
            $invitation->update([
                'inviter_id' => $user->id,
                'status' => Invitation::STATUS_PENDING,
                'expires_at' => $game->starts_at,
            ]);
        } else {
            Invitation::create([
                'user_id' => $data['user_id'],
                'inviter_id' => $user->id,
                'invitable_type' => Game::class,
                'invitable_id' => $game->id,
                'kind' => Invitation::KIND_GAME,
                'status' => Invitation::STATUS_PENDING,
                'expires_at' => $game->starts_at,
            ]);
        }

        $invitee = User::find($data['user_id']);
        $this->notifyGame($invitee, 'Приглашение в игру', "{$user->name} приглашает вас в игру", 'game_invite', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Подать заявку на игру (кандидат). */
    public function apply(Request $request, Game $game)
    {
        $user = $request->user();
        $data = $request->validate(['source' => 'nullable|in:app_feed,app_link']);

        $outOfRange = !$this->userInRange($game, $user);

        $existing = $game->players()->where('user_id', $user->id)->first();
        $activeStatuses = [GamePlayer::STATUS_INVITED, GamePlayer::STATUS_CANDIDATE, GamePlayer::STATUS_ACCEPTED];
        if ($existing && in_array($existing->status, $activeStatuses, true)) {
            return response()->json(['success' => false, 'message' => 'Вы уже в этой игре'], 422);
        }
        if (!in_array($game->status, [Game::STATUS_OPEN, Game::STATUS_FULL], true)) {
            return response()->json(['success' => false, 'message' => 'Игра недоступна для заявок'], 422);
        }

        $source = ($data['source'] ?? 'app_feed') === 'app_link' ? GamePlayer::SOURCE_APP_LINK : GamePlayer::SOURCE_APP_FEED;

        if ($existing) {
            $existing->update([
                'status' => GamePlayer::STATUS_CANDIDATE,
                'position' => null,
                'source' => $source,
                'responded_at' => null,
                'out_of_range' => $outOfRange,
            ]);
        } else {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'position' => null,
                'status' => GamePlayer::STATUS_CANDIDATE,
                'source' => $source,
                'out_of_range' => $outOfRange,
            ]);
        }

        $this->notifyGame($game->creator, 'Новая заявка', "{$user->name} хочет присоединиться к игре", 'game_application', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Одобрить заявку кандидата (организатор). */
    public function approveApplication(Request $request, Game $game, GamePlayer $player)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($player->game_id !== $game->id || $player->status !== GamePlayer::STATUS_CANDIDATE) {
            return response()->json(['success' => false, 'message' => 'Заявка не найдена'], 422);
        }
        $position = $this->nextFreePosition($game);
        if ($position === null) {
            return response()->json(['success' => false, 'message' => 'Мест больше нет'], 422);
        }

        $player->update(['status' => GamePlayer::STATUS_ACCEPTED, 'position' => $position, 'responded_at' => now()]);
        $this->syncFullness($game);
        $this->notifyGame($player->user, 'Заявка одобрена', 'Вас приняли в игру', 'game_application_approved', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Отклонить заявку кандидата (организатор). */
    public function rejectApplication(Request $request, Game $game, GamePlayer $player)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($player->game_id !== $game->id || $player->status !== GamePlayer::STATUS_CANDIDATE) {
            return response()->json(['success' => false, 'message' => 'Заявка не найдена'], 422);
        }
        $player->update(['status' => GamePlayer::STATUS_DECLINED, 'responded_at' => now()]);
        $this->notifyGame($player->user, 'Заявка отклонена', 'Организатор отклонил вашу заявку', 'game_application_rejected', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Принять приглашение в игру. */
    public function accept(Request $request, Game $game)
    {
        $user = $request->user();
        $player = $game->players()->where('user_id', $user->id)->where('status', GamePlayer::STATUS_INVITED)->first();
        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Приглашение не найдено'], 404);
        }

        $player->update(['status' => GamePlayer::STATUS_ACCEPTED, 'responded_at' => now()]);

        Invitation::where('invitable_type', Game::class)
            ->where('invitable_id', $game->id)
            ->where('user_id', $user->id)
            ->where('status', Invitation::STATUS_PENDING)
            ->update(['status' => Invitation::STATUS_ACCEPTED]);

        $this->syncFullness($game);
        $this->notifyGame($game->creator, 'Приглашение принято', "{$user->name} принял приглашение", 'game_invite_accepted', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Отклонить приглашение в игру. */
    public function decline(Request $request, Game $game)
    {
        $user = $request->user();
        $player = $game->players()->where('user_id', $user->id)->where('status', GamePlayer::STATUS_INVITED)->first();
        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Приглашение не найдено'], 404);
        }

        $player->update(['status' => GamePlayer::STATUS_DECLINED, 'position' => null, 'responded_at' => now()]);

        Invitation::where('invitable_type', Game::class)
            ->where('invitable_id', $game->id)
            ->where('user_id', $user->id)
            ->where('status', Invitation::STATUS_PENDING)
            ->update(['status' => Invitation::STATUS_DECLINED]);

        $this->notifyGame($game->creator, 'Приглашение отклонено', "{$user->name} отклонил приглашение", 'game_invite_declined', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Выйти из игры (участник, до старта). Организатор — нельзя. */
    public function leave(Request $request, Game $game)
    {
        $user = $request->user();
        if ($game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Организатор не может выйти — передайте организацию или отмените игру'], 422);
        }
        if (in_array($game->status, [Game::STATUS_IN_PROGRESS, Game::STATUS_FINISHED, Game::STATUS_DISPUTED], true)) {
            return response()->json(['success' => false, 'message' => 'Игра уже началась'], 422);
        }
        $player = $game->players()->where('user_id', $user->id)->where('status', GamePlayer::STATUS_ACCEPTED)->first();
        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Вы не в этой игре'], 404);
        }

        $player->update(['status' => GamePlayer::STATUS_LEFT, 'position' => null]);
        $this->syncFullness($game);
        $this->notifyGame($game->creator, 'Игрок вышел', "{$user->name} покинул игру", 'game_left', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Удалить участника (организатор). */
    public function removePlayer(Request $request, Game $game, GamePlayer $player)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($player->game_id !== $game->id || $player->status !== GamePlayer::STATUS_ACCEPTED) {
            return response()->json(['success' => false, 'message' => 'Участник не найден'], 422);
        }
        if ($player->user_id === $user->id) {
            return response()->json(['success' => false, 'message' => 'Нельзя удалить себя'], 422);
        }

        $removed = $player->user;
        $removedUserId = $player->user_id;
        $player->update(['status' => GamePlayer::STATUS_REMOVED, 'position' => null]);
        $this->syncFullness($game);
        $this->notifyGame($removed, 'Вас удалили из игры', 'Организатор удалил вас из состава', 'game_removed', $game->id);
        $this->logGameAction($game, $user->id, GameActionLog::ACTION_PLAYER_REMOVE, ['removed_user_id' => $removedUserId]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Инициировать передачу прав организатора принятому участнику. */
    public function transferInitiate(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if (in_array($game->status, [Game::STATUS_FINISHED, Game::STATUS_CANCELLED], true)) {
            return response()->json(['success' => false, 'message' => 'Игра завершена'], 422);
        }
        $data = $request->validate(['to_user_id' => 'required|integer']);
        if ((int) $data['to_user_id'] === $user->id) {
            return response()->json(['success' => false, 'message' => 'Нельзя передать самому себе'], 422);
        }
        $isAccepted = $game->players()
            ->where('user_id', $data['to_user_id'])
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->exists();
        if (!$isAccepted) {
            return response()->json(['success' => false, 'message' => 'Получатель должен быть участником игры'], 422);
        }

        $transfer = GameTransfer::where('game_id', $game->id)
            ->where('status', GameTransfer::STATUS_PENDING)
            ->first();
        if ($transfer) {
            $transfer->update(['from_user_id' => $user->id, 'to_user_id' => $data['to_user_id']]);
        } else {
            GameTransfer::create([
                'game_id' => $game->id,
                'from_user_id' => $user->id,
                'to_user_id' => $data['to_user_id'],
                'status' => GameTransfer::STATUS_PENDING,
            ]);
        }

        $target = User::find($data['to_user_id']);
        if ($target) {
            $this->notifyGame($target, 'Передача прав', 'Вам предлагают стать организатором игры', 'game_transfer_offer', $game->id);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Отменить свою pending-передачу (организатор). */
    public function transferCancel(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        $transfer = GameTransfer::where('game_id', $game->id)
            ->where('status', GameTransfer::STATUS_PENDING)
            ->latest('id')
            ->first();
        if (!$transfer) {
            return response()->json(['success' => false, 'message' => 'Нет активной передачи'], 422);
        }
        $transfer->update(['status' => GameTransfer::STATUS_CANCELLED]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Принять передачу прав (только целевой участник). */
    public function transferAccept(Request $request, Game $game)
    {
        $user = $request->user();
        $transfer = GameTransfer::where('game_id', $game->id)
            ->where('status', GameTransfer::STATUS_PENDING)
            ->where('to_user_id', $user->id)
            ->latest('id')
            ->first();
        if (!$transfer) {
            return response()->json(['success' => false, 'message' => 'Нет передачи для вас'], 403);
        }

        $previousOwner = $game->creator_id;
        $game->update(['creator_id' => $user->id]);
        $transfer->update(['status' => GameTransfer::STATUS_ACCEPTED]);
        // Прочие pending этой игры закрываем.
        GameTransfer::where('game_id', $game->id)
            ->where('status', GameTransfer::STATUS_PENDING)
            ->update(['status' => GameTransfer::STATUS_CANCELLED]);

        $prev = User::find($previousOwner);
        if ($prev) {
            $this->notifyGame($prev, 'Права переданы', 'Участник принял роль организатора', 'game_transfer_accepted', $game->id);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Отклонить передачу прав (только целевой участник). */
    public function transferDecline(Request $request, Game $game)
    {
        $user = $request->user();
        $transfer = GameTransfer::where('game_id', $game->id)
            ->where('status', GameTransfer::STATUS_PENDING)
            ->where('to_user_id', $user->id)
            ->latest('id')
            ->first();
        if (!$transfer) {
            return response()->json(['success' => false, 'message' => 'Нет передачи для вас'], 403);
        }

        $transfer->update(['status' => GameTransfer::STATUS_DECLINED]);
        $initiator = User::find($transfer->from_user_id);
        if ($initiator) {
            $this->notifyGame($initiator, 'Передача отклонена', 'Участник отказался стать организатором', 'game_transfer_declined', $game->id);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Поиск игрока по телефону (для приглашений). */
    public function searchPlayer(Request $request)
    {
        // Ключ остался 'phone' ради старых сборок, но принимаем и имя.
        $request->validate(['phone' => 'required|string|min:2']);

        $term = trim((string) $request->phone);
        $digits = preg_replace('/\D/', '', $term);

        $users = User::where(function ($w) use ($term, $digits) {
                foreach (\App\Support\NameSearch::variants($term) as $variant) {
                    $w->orWhere('name', 'like', "%{$variant}%");
                }
                if (strlen($digits) >= 3) {
                    $w->orWhere('phone', 'like', "%{$digits}%");
                }
            })
            ->tap(fn ($w) => \App\Support\NameSearch::orderExactFirst($w, $term, ['name']))
            ->limit(10)->get();
        if ($users->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Пользователь не найден'], 404);
        }

        $data = $users->map(function ($u) {
            $name = $u->name ?? 'Без имени';
            $parts = explode(' ', $name, 2);
            return [
                'id' => $u->id,
                'first_name' => $parts[0] ?? '',
                'last_name' => $parts[1] ?? '',
                'full_name' => $name,
                'phone' => $u->phone,
                'rating' => $u->rating,
                'level' => (float) $u->level,
                'avatar' => $u->avatar,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Начать игру: full → in_progress (только организатор). */
    public function start(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if (in_array($game->status, [Game::STATUS_IN_PROGRESS, Game::STATUS_FINISHED, Game::STATUS_DISPUTED], true)) {
            return response()->json(['success' => false, 'message' => 'Игра уже начата'], 422);
        }
        if ($game->status !== Game::STATUS_FULL || $game->acceptedCount() < (int) $game->capacity) {
            return response()->json(['success' => false, 'message' => 'Соберите всех игроков перед стартом'], 422);
        }

        $game->update(['status' => Game::STATUS_IN_PROGRESS]);
        $this->generateAmericanoRounds($game);
        $this->logGameAction($game, $user->id, GameActionLog::ACTION_START);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Отменить старт: in_progress → full/open (пока счёт не залочен). */
    public function startCancel(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Старт нельзя отменить'], 422);
        }

        $game->update(['status' => Game::STATUS_FULL]);
        $this->syncFullness($game);
        $this->logGameAction($game, $user->id, GameActionLog::ACTION_START_CANCEL);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Зафиксировать счёт и открыть фазу подтверждения (только организатор). */
    public function finish(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Игру нельзя завершить в этом статусе'], 422);
        }
        if (!$game->rounds()->where('is_played', true)->exists()) {
            return response()->json(['success' => false, 'message' => 'Введите счёт хотя бы одного раунда'], 422);
        }

        $game->update(['score_locked' => true]);
        // Организатор автоматически подтверждает счёт.
        $game->players()
            ->where('user_id', $user->id)
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->update(['score_confirmed' => true]);

        $this->logGameAction($game, $user->id, GameActionLog::ACTION_FINISH);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Подтвердить счёт участником; при полном подтверждении — завершить игру. */
    public function confirmScore(Request $request, Game $game)
    {
        $user = $request->user();
        $player = $game->players()
            ->where('user_id', $user->id)
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->first();
        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Только участник игры'], 403);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || !$game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Счёт сейчас не подтверждается'], 422);
        }

        $player->update(['score_confirmed' => true]);

        if ($this->allScoreConfirmed($game)) {
            $game->update(['status' => Game::STATUS_FINISHED]);
            $this->applyGameElo($game);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Все ли принятые игроки подтвердили счёт. */
    private function allScoreConfirmed(Game $game): bool
    {
        return $game->players()
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->where('score_confirmed', false)
            ->count() === 0;
    }

    /** Начислить ELO завершённой rated-игре по сыгранным раундам (миррор challenge/americano). */
    private function applyGameElo(Game $game): void
    {
        if ($game->type !== Game::TYPE_RATED) {
            return;
        }

        $accepted = $game->players()
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->with('user')
            ->get();

        // Сид: живой рейтинг принятых игроков.
        $before = [];   // user_id => rating_before (снимок)
        $current = [];  // user_id => накопительный рейтинг
        $players = [];  // user_id => GamePlayer
        foreach ($accepted as $gp) {
            if (!$gp->user) continue;
            $before[$gp->user_id] = (int) $gp->user->rating;
            $current[$gp->user_id] = (int) $gp->user->rating;
            $players[$gp->user_id] = $gp;
        }

        // Накопление по каждому сыгранному раунду (2×2 командный средний).
        $rounds = $game->relationLoaded('rounds') ? $game->rounds : $game->rounds()->get();
        foreach ($rounds as $round) {
            if (!$round->is_played || $round->score_a === null || $round->score_b === null) {
                continue;
            }
            $pairA = array_values(array_filter((array) $round->pair_a, fn ($id) => isset($current[$id])));
            $pairB = array_values(array_filter((array) $round->pair_b, fn ($id) => isset($current[$id])));
            if (count($pairA) < 1 || count($pairB) < 1) {
                continue;
            }
            $avgA = array_sum(array_map(fn ($id) => $current[$id], $pairA)) / count($pairA);
            $avgB = array_sum(array_map(fn ($id) => $current[$id], $pairB)) / count($pairB);
            $changes = $this->calculateRatingChange($avgA, $avgB, (int) $round->score_a, (int) $round->score_b);
            foreach ($pairA as $id) {
                $current[$id] = $this->applyRatingChange($current[$id], $changes['change1']);
            }
            foreach ($pairB as $id) {
                $current[$id] = $this->applyRatingChange($current[$id], $changes['change2']);
            }
        }

        // Коммит один раз на игрока.
        foreach ($players as $uid => $gp) {
            $this->applyPlayerRating($gp, $before[$uid], $current[$uid]);
        }
    }

    /** Записать итог рейтинга игрока (миррор MobileChallengeController::applyPlayerRating). */
    private function applyPlayerRating(GamePlayer $player, int $before, int $after): void
    {
        $user = $player->user;
        if (!$user) {
            return;
        }

        $player->update([
            'rating_before' => $before,
            'rating_after' => $after,
            'rating_change' => $after - $before,
        ]);

        $user->update(['rating' => $after]);
        $this->updateLevel($user);

        RatingHistory::create([
            'user_id' => $user->id,
            'tournament_id' => null,
            'rating_before' => $before,
            'rating_after' => $after,
            'change' => $after - $before,
            'reason' => 'game',
        ]);
    }

    /** Перегенерировать расписание Американо (пока счёт не введён; только организатор). */
    public function regenerateSchedule(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($game->format !== Game::FORMAT_AMERICANO) {
            return response()->json(['success' => false, 'message' => 'Расписание есть только у Американо'], 422);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Перегенерация недоступна'], 422);
        }
        if ($game->rounds()->where('is_played', true)->exists()) {
            return response()->json(['success' => false, 'message' => 'Нельзя перегенерировать: уже введён счёт'], 422);
        }

        $game->rounds()->delete();
        $this->generateAmericanoRounds($game);
        $this->logGameAction($game, $user->id, GameActionLog::ACTION_SCHEDULE_REGENERATE);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Классическое расписание Американо 4/1: слоты 0..3, 3 раунда, каждый партнёрит каждого 1 раз. */
    private const AMERICANO_4_SCHEDULE = [
        [[0, 1], [2, 3]],
        [[0, 2], [1, 3]],
        [[0, 3], [1, 2]],
    ];

    /** Проверка пар раунда. Возвращает текст ошибки или null. */
    private function validateRoundPairs(Game $game, array $pairA, array $pairB): ?string
    {
        if (count($pairA) !== 2 || count($pairB) !== 2) {
            return 'В каждой паре должно быть по 2 игрока';
        }
        $all = array_merge($pairA, $pairB);
        if (count(array_unique($all)) !== 4) {
            return 'Игроки в парах не должны повторяться';
        }
        $acceptedIds = $game->players()
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->pluck('user_id')->all();
        foreach ($all as $uid) {
            if (!in_array($uid, $acceptedIds)) {
                return 'Все игроки раунда должны быть участниками игры';
            }
        }
        return null;
    }

    /** Генерирует раунды Американо при старте (4 игрока, если раундов ещё нет). No-op иначе. */
    private function generateAmericanoRounds(Game $game): void
    {
        if ($game->format !== Game::FORMAT_AMERICANO) {
            return;
        }
        if ($game->rounds()->exists()) {
            return; // расписание уже есть — не дублируем при повторном старте
        }

        $userIds = $game->players()
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->orderBy('position')
            ->pluck('user_id')
            ->all();

        if (count($userIds) !== 4) {
            return; // расписание определено только для 4 игроков
        }

        shuffle($userIds); // слот→игрок случайно: варьирует партнёрства

        $roundNo = 1;
        foreach (self::AMERICANO_4_SCHEDULE as $slots) {
            [$a, $b] = $slots;
            GameRound::create([
                'game_id' => $game->id,
                'round_no' => $roundNo++,
                'pair_a' => [$userIds[$a[0]], $userIds[$a[1]]],
                'pair_b' => [$userIds[$b[0]], $userIds[$b[1]]],
                'is_played' => false,
            ]);
        }
    }

    /** Добавить раунд (сет/партию) с парами и опциональным счётом. */
    public function addRound(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Счёт можно вводить только у идущей игры'], 422);
        }

        $data = $request->validate([
            'pair_a' => 'required|array',
            'pair_b' => 'required|array',
            'pair_a.*' => 'integer',
            'pair_b.*' => 'integer',
            'score_a' => 'nullable|integer|min:0',
            'score_b' => 'nullable|integer|min:0',
            'tiebreak_a' => 'nullable|integer|min:0',
            'tiebreak_b' => 'nullable|integer|min:0',
        ]);

        $err = $this->validateRoundPairs($game, $data['pair_a'], $data['pair_b']);
        if ($err !== null) {
            return response()->json(['success' => false, 'message' => $err], 422);
        }

        $nextNo = (int) ($game->rounds()->max('round_no') ?? 0) + 1;
        $played = array_key_exists('score_a', $data) && $data['score_a'] !== null
            && array_key_exists('score_b', $data) && $data['score_b'] !== null;

        GameRound::create([
            'game_id' => $game->id,
            'round_no' => $nextNo,
            'pair_a' => array_values($data['pair_a']),
            'pair_b' => array_values($data['pair_b']),
            'score_a' => $data['score_a'] ?? null,
            'score_b' => $data['score_b'] ?? null,
            'tiebreak_a' => $data['tiebreak_a'] ?? null,
            'tiebreak_b' => $data['tiebreak_b'] ?? null,
            'is_played' => $played,
        ]);

        $this->logGameAction($game, $user->id, GameActionLog::ACTION_ROUND_ADD, ['round_no' => $nextNo]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Изменить раунд (счёт/пары). */
    public function updateRound(Request $request, Game $game, GameRound $round)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($round->game_id !== $game->id) {
            return response()->json(['success' => false, 'message' => 'Раунд не найден'], 422);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Счёт можно менять только у идущей игры'], 422);
        }

        $data = $request->validate([
            'pair_a' => 'nullable|array',
            'pair_b' => 'nullable|array',
            'pair_a.*' => 'integer',
            'pair_b.*' => 'integer',
            'score_a' => 'nullable|integer|min:0',
            'score_b' => 'nullable|integer|min:0',
            'tiebreak_a' => 'nullable|integer|min:0',
            'tiebreak_b' => 'nullable|integer|min:0',
        ]);

        if (isset($data['pair_a'], $data['pair_b'])) {
            $err = $this->validateRoundPairs($game, $data['pair_a'], $data['pair_b']);
            if ($err !== null) {
                return response()->json(['success' => false, 'message' => $err], 422);
            }
            $round->pair_a = array_values($data['pair_a']);
            $round->pair_b = array_values($data['pair_b']);
        }

        foreach (['score_a', 'score_b', 'tiebreak_a', 'tiebreak_b'] as $field) {
            if (array_key_exists($field, $data)) {
                $round->{$field} = $data[$field];
            }
        }
        $round->is_played = $round->score_a !== null && $round->score_b !== null;
        $round->save();

        $this->logGameAction($game, $user->id, GameActionLog::ACTION_ROUND_UPDATE, ['round_no' => $round->round_no]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Удалить раунд. */
    public function deleteRound(Request $request, Game $game, GameRound $round)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($round->game_id !== $game->id) {
            return response()->json(['success' => false, 'message' => 'Раунд не найден'], 422);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Раунд можно удалить только у идущей игры'], 422);
        }

        $roundNo = $round->round_no;
        $round->delete();
        $this->logGameAction($game, $user->id, GameActionLog::ACTION_ROUND_DELETE, ['round_no' => $roundNo]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Журнал действий игры (организатор или принятый участник). */
    public function logs(Request $request, Game $game)
    {
        $user = $request->user();
        $isParticipant = $game->players()
            ->where('user_id', $user->id)
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->exists();
        if (!$game->isOrganizer($user->id) && !$isParticipant) {
            return response()->json(['success' => false, 'message' => 'Нет доступа к журналу'], 403);
        }

        $logs = GameActionLog::where('game_id', $game->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'user_id' => $l->user_id,
                'user_name' => $l->user->name ?? null,
                'action' => $l->action,
                'payload' => $l->payload,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $logs]);
    }
}

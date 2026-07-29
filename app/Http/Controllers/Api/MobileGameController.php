<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Invitation;
use App\Models\Notification;
use App\Models\User;
use App\Services\FCMNotificationService;
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

    /** Лента игр — только публичные, набирающие состав, будущие. */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Game::with(['creator', 'club', 'court', 'players.user'])
            ->where('visibility', Game::VISIBILITY_PUBLIC)
            ->whereIn('status', [Game::STATUS_OPEN, Game::STATUS_FULL])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at');

        // Диапазон рейтинга — фильтр «самотёка»: по умолчанию прячем игры вне уровня.
        if (!$request->boolean('show_out_of_level')) {
            $level = (float) $user->level;
            $query->where(function ($q) use ($level) {
                $q->whereNull('rating_min')->orWhere('rating_min', '<=', $level);
            })->where(function ($q) use ($level) {
                $q->whereNull('rating_max')->orWhere('rating_max', '>=', $level);
            });
        }

        $games = $query->get()->map(fn ($g) => $this->formatGame($g, $user));

        return response()->json(['success' => true, 'data' => $games]);
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

        $mine = $user ? $game->players->firstWhere('user_id', $user->id) : null;

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
        $player->update(['status' => GamePlayer::STATUS_REMOVED, 'position' => null]);
        $this->syncFullness($game);
        $this->notifyGame($removed, 'Вас удалили из игры', 'Организатор удалил вас из состава', 'game_removed', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Поиск игрока по телефону (для приглашений). */
    public function searchPlayer(Request $request)
    {
        $request->validate(['phone' => 'required|string|min:3']);

        $users = User::where('phone', 'like', '%' . $request->phone . '%')->limit(10)->get();
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

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }
}

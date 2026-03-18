<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\ChallengePlayer;
use App\Models\Notification;
use App\Models\RatingHistory;
use App\Models\User;
use App\Services\FCMNotificationService;
use App\Traits\RatingCalculator;
use Illuminate\Http\Request;

class MobileChallengeController extends Controller
{
    use RatingCalculator;

    /**
     * Открытые поединки
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $challenges = Challenge::with(['creator', 'club', 'players.user'])
            ->where('status', Challenge::STATUS_OPEN)
            ->where('scheduled_at', '>=', now())
            ->where('min_level', '<=', $user->level)
            ->where('max_level', '>=', $user->level)
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn($c) => $this->formatChallenge($c, $user));

        return response()->json(['success' => true, 'data' => $challenges]);
    }

    /**
     * Мои поединки
     */
    public function my(Request $request)
    {
        $user = $request->user();

        $challengeIds = ChallengePlayer::where('user_id', $user->id)
            ->where('status', '!=', ChallengePlayer::STATUS_DECLINED)
            ->pluck('challenge_id');

        $challenges = Challenge::with(['creator', 'club', 'players.user'])
            ->whereIn('id', $challengeIds)
            ->whereIn('status', [
                Challenge::STATUS_OPEN,
                Challenge::STATUS_READY,
                Challenge::STATUS_IN_PROGRESS,
                Challenge::STATUS_COMPLETED,
            ])
            ->orderByDesc('scheduled_at')
            ->get()
            ->map(fn($c) => $this->formatChallenge($c, $user));

        return response()->json(['success' => true, 'data' => $challenges]);
    }

    /**
     * Создать поединок
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'scheduled_at' => 'required|date|after:now',
            'club_id' => 'nullable|exists:clubs,id',
            'type' => 'required|in:rated,friendly',
            'min_level' => 'required|numeric|min:1|max:5.75',
            'max_level' => 'required|numeric|min:1|max:5.75|gte:min_level',
            'position' => 'required|integer|in:1,2,3,4',
            'invites' => 'nullable|array|max:3',
            'invites.*.phone' => 'required|string',
            'invites.*.position' => 'required|integer|in:1,2,3,4',
        ]);

        $challenge = Challenge::create([
            'creator_id' => $user->id,
            'club_id' => $validated['club_id'] ?? null,
            'scheduled_at' => $validated['scheduled_at'],
            'type' => $validated['type'],
            'min_level' => $validated['min_level'],
            'max_level' => $validated['max_level'],
            'status' => Challenge::STATUS_OPEN,
        ]);
        $challenge->refresh();

        // Создатель занимает свою позицию
        ChallengePlayer::create([
            'challenge_id' => $challenge->id,
            'user_id' => $user->id,
            'position' => $validated['position'],
            'status' => ChallengePlayer::STATUS_CONFIRMED,
        ]);

        // Приглашения
        if (!empty($validated['invites'])) {
            $fcm = app(FCMNotificationService::class);

            foreach ($validated['invites'] as $invite) {
                $invitedUser = User::where('phone', $invite['phone'])->first();
                if (!$invitedUser || $invitedUser->id === $user->id) continue;
                if ($challenge->hasPlayer($invitedUser->id)) continue;

                ChallengePlayer::create([
                    'challenge_id' => $challenge->id,
                    'user_id' => $invitedUser->id,
                    'position' => $invite['position'],
                    'status' => ChallengePlayer::STATUS_INVITED,
                ]);

                $title = 'Приглашение на поединок';
                $body = "{$user->name} приглашает вас на матч";

                Notification::create([
                    'user_id' => $invitedUser->id,
                    'title' => $title,
                    'body' => $body,
                    'type' => 'challenge_invite',
                    'data' => ['challenge_id' => $challenge->id],
                ]);

                $fcm->sendToUser($invitedUser, $title, $body, [
                    'type' => 'challenge_invite',
                    'challenge_id' => (string) $challenge->id,
                ]);
            }
        }

        $challenge->load(['creator', 'club', 'players.user']);

        return response()->json([
            'success' => true,
            'data' => $this->formatChallenge($challenge, $user),
        ], 201);
    }

    /**
     * Детали поединка
     */
    public function show(Request $request, Challenge $challenge)
    {
        $user = $request->user();
        $challenge->load(['creator', 'club', 'players.user']);

        return response()->json([
            'success' => true,
            'data' => $this->formatChallenge($challenge, $user),
        ]);
    }

    /**
     * Занять свободное место
     */
    public function join(Request $request, Challenge $challenge)
    {
        $user = $request->user();

        $request->validate([
            'position' => 'required|integer|in:1,2,3,4',
        ]);

        if (!$challenge->isOpen()) {
            return response()->json(['success' => false, 'message' => 'Поединок недоступен'], 422);
        }

        if ($challenge->hasPlayer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Вы уже в этом поединке'], 422);
        }

        if ($user->level < $challenge->min_level || $user->level > $challenge->max_level) {
            return response()->json(['success' => false, 'message' => 'Ваш уровень не подходит'], 422);
        }

        $available = $challenge->getAvailablePositions();
        if (!in_array($request->position, $available)) {
            return response()->json(['success' => false, 'message' => 'Позиция занята'], 422);
        }

        ChallengePlayer::create([
            'challenge_id' => $challenge->id,
            'user_id' => $user->id,
            'position' => $request->position,
            'status' => ChallengePlayer::STATUS_CONFIRMED,
        ]);

        // Проверяем заполненность
        $this->checkAndUpdateStatus($challenge);

        // Уведомляем создателя
        $this->notifyPlayer($challenge->creator, 'Игрок присоединился', "{$user->name} занял место в вашем поединке", 'challenge_joined', $challenge->id);

        $challenge->load(['creator', 'club', 'players.user']);

        return response()->json([
            'success' => true,
            'data' => $this->formatChallenge($challenge, $user),
        ]);
    }

    /**
     * Пригласить игрока по телефону
     */
    public function invite(Request $request, Challenge $challenge)
    {
        $user = $request->user();

        $request->validate([
            'phone' => 'required|string',
            'position' => 'required|integer|in:1,2,3,4',
        ]);

        if ($challenge->creator_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Только создатель может приглашать'], 403);
        }

        $invitedUser = User::where('phone', $request->phone)->first();
        if (!$invitedUser) {
            return response()->json(['success' => false, 'message' => 'Пользователь не найден'], 404);
        }

        if ($challenge->hasPlayer($invitedUser->id)) {
            return response()->json(['success' => false, 'message' => 'Игрок уже в поединке'], 422);
        }

        $available = $challenge->getAvailablePositions();
        if (!in_array($request->position, $available)) {
            return response()->json(['success' => false, 'message' => 'Позиция занята'], 422);
        }

        ChallengePlayer::create([
            'challenge_id' => $challenge->id,
            'user_id' => $invitedUser->id,
            'position' => $request->position,
            'status' => ChallengePlayer::STATUS_INVITED,
        ]);

        $title = 'Приглашение на поединок';
        $body = "{$user->name} приглашает вас на матч";

        $this->notifyPlayer($invitedUser, $title, $body, 'challenge_invite', $challenge->id);

        return response()->json(['success' => true, 'message' => 'Приглашение отправлено']);
    }

    /**
     * Принять приглашение
     */
    public function accept(Request $request, Challenge $challenge)
    {
        $user = $request->user();

        $player = ChallengePlayer::where('challenge_id', $challenge->id)
            ->where('user_id', $user->id)
            ->where('status', ChallengePlayer::STATUS_INVITED)
            ->first();

        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Приглашение не найдено'], 404);
        }

        $player->update(['status' => ChallengePlayer::STATUS_CONFIRMED]);

        $this->checkAndUpdateStatus($challenge);

        $this->notifyPlayer($challenge->creator, 'Приглашение принято', "{$user->name} принял приглашение на поединок", 'challenge_accepted', $challenge->id);

        $challenge->load(['creator', 'club', 'players.user']);

        return response()->json([
            'success' => true,
            'data' => $this->formatChallenge($challenge, $user),
        ]);
    }

    /**
     * Отклонить приглашение
     */
    public function decline(Request $request, Challenge $challenge)
    {
        $user = $request->user();

        $player = ChallengePlayer::where('challenge_id', $challenge->id)
            ->where('user_id', $user->id)
            ->where('status', ChallengePlayer::STATUS_INVITED)
            ->first();

        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Приглашение не найдено'], 404);
        }

        $player->update(['status' => ChallengePlayer::STATUS_DECLINED]);

        $this->notifyPlayer($challenge->creator, 'Приглашение отклонено', "{$user->name} отклонил приглашение", 'challenge_declined', $challenge->id);

        return response()->json(['success' => true, 'message' => 'Приглашение отклонено']);
    }

    /**
     * Начать поединок (все собрались)
     */
    public function start(Request $request, Challenge $challenge)
    {
        $user = $request->user();

        if ($challenge->creator_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Только создатель может начать'], 403);
        }

        if (!$challenge->isReady()) {
            return response()->json(['success' => false, 'message' => 'Не все игроки на месте'], 422);
        }

        $challenge->update(['status' => Challenge::STATUS_IN_PROGRESS]);

        return response()->json(['success' => true, 'message' => 'Поединок начат']);
    }

    /**
     * Ввести счёт
     */
    public function score(Request $request, Challenge $challenge)
    {
        $user = $request->user();

        if ($challenge->creator_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Только создатель может вводить счёт'], 403);
        }

        if (!in_array($challenge->status, [Challenge::STATUS_READY, Challenge::STATUS_IN_PROGRESS])) {
            return response()->json(['success' => false, 'message' => 'Нельзя ввести счёт для этого поединка'], 422);
        }

        $validated = $request->validate([
            'sets' => 'required|array|min:1',
            'sets.*.team_a' => 'required|integer|min:0',
            'sets.*.team_b' => 'required|integer|min:0',
        ]);

        $sets = $validated['sets'];
        $teamAWins = 0;
        $teamBWins = 0;
        $totalScoreA = 0;
        $totalScoreB = 0;

        foreach ($sets as $set) {
            $totalScoreA += $set['team_a'];
            $totalScoreB += $set['team_b'];
            if ($set['team_a'] > $set['team_b']) $teamAWins++;
            elseif ($set['team_b'] > $set['team_a']) $teamBWins++;
        }

        $challenge->update([
            'score' => $sets,
            'status' => Challenge::STATUS_COMPLETED,
        ]);

        // Расчёт ELO для рейтинговых
        if ($challenge->isRated()) {
            $this->calculateElo($challenge, $totalScoreA, $totalScoreB);
        }

        // Уведомления участникам
        $challenge->load('players.user');
        $fcm = app(FCMNotificationService::class);
        $winnerLabel = $teamAWins > $teamBWins ? 'Команда A' : ($teamBWins > $teamAWins ? 'Команда B' : 'Ничья');

        foreach ($challenge->confirmedPlayers as $player) {
            if ($player->user_id === $user->id) continue;

            $title = 'Поединок завершён';
            $body = "Результат: {$teamAWins}:{$teamBWins} ({$winnerLabel})";
            if ($challenge->isRated() && $player->rating_change !== null) {
                $sign = $player->rating_change >= 0 ? '+' : '';
                $body .= " · {$sign}{$player->rating_change} ELO";
            }

            Notification::create([
                'user_id' => $player->user_id,
                'title' => $title,
                'body' => $body,
                'type' => 'challenge_result',
                'data' => ['challenge_id' => $challenge->id],
            ]);

            $fcm->sendToUser($player->user, $title, $body, [
                'type' => 'challenge_result',
                'challenge_id' => (string) $challenge->id,
            ]);
        }

        $challenge->load(['creator', 'club', 'players.user']);

        return response()->json([
            'success' => true,
            'data' => $this->formatChallenge($challenge, $user),
        ]);
    }

    /**
     * Отменить поединок
     */
    public function cancel(Request $request, Challenge $challenge)
    {
        $user = $request->user();

        if ($challenge->creator_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Только создатель может отменить'], 403);
        }

        if ($challenge->isCompleted()) {
            return response()->json(['success' => false, 'message' => 'Завершённый поединок нельзя отменить'], 422);
        }

        $challenge->update(['status' => Challenge::STATUS_CANCELLED]);

        // Уведомляем участников
        $fcm = app(FCMNotificationService::class);
        foreach ($challenge->confirmedPlayers as $player) {
            if ($player->user_id === $user->id) continue;

            $this->notifyPlayer($player->user, 'Поединок отменён', "{$user->name} отменил поединок", 'challenge_cancelled', $challenge->id);
        }

        return response()->json(['success' => true, 'message' => 'Поединок отменён']);
    }

    /**
     * Покинуть поединок
     */
    public function leave(Request $request, Challenge $challenge)
    {
        $user = $request->user();

        if ($challenge->creator_id === $user->id) {
            return response()->json(['success' => false, 'message' => 'Создатель не может покинуть, используйте отмену'], 422);
        }

        $player = ChallengePlayer::where('challenge_id', $challenge->id)
            ->where('user_id', $user->id)
            ->where('status', ChallengePlayer::STATUS_CONFIRMED)
            ->first();

        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Вы не в этом поединке'], 404);
        }

        $player->delete();

        // Возвращаем статус open если был ready
        if ($challenge->status === Challenge::STATUS_READY) {
            $challenge->update(['status' => Challenge::STATUS_OPEN]);
        }

        $this->notifyPlayer($challenge->creator, 'Игрок покинул поединок', "{$user->name} покинул ваш поединок", 'challenge_left', $challenge->id);

        return response()->json(['success' => true, 'message' => 'Вы покинули поединок']);
    }

    /**
     * Список клубов (фильтр по городу пользователя)
     */
    public function clubs(Request $request)
    {
        $user = $request->user();

        $query = \App\Models\Club::active()->orderBy('name');

        if ($user->city) {
            $query->where('city', $user->city);
        }

        $clubs = $query->get()->map(fn($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'address' => $c->address,
            'city' => $c->city,
        ]);

        return response()->json(['success' => true, 'data' => $clubs]);
    }

    /**
     * Поиск игрока по телефону
     */
    public function searchPlayer(Request $request)
    {
        $request->validate(['phone' => 'required|string|min:3']);

        $users = User::where('phone', 'like', '%' . $request->phone . '%')
            ->limit(10)
            ->get();

        if ($users->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Пользователь не найден'], 404);
        }

        $data = $users->map(function($user) {
            $name = $user->name ?? 'Без имени';
            $parts = explode(' ', $name, 2);
            return [
                'id' => $user->id,
                'first_name' => $parts[0] ?? '',
                'last_name' => $parts[1] ?? '',
                'full_name' => $name,
                'phone' => $user->phone,
                'rating' => $user->rating,
                'level' => (float) $user->level,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // =====================
    // Private helpers
    // =====================

    private function checkAndUpdateStatus(Challenge $challenge): void
    {
        $challenge->refresh();
        $confirmed = $challenge->confirmedPlayers()->count();

        if ($confirmed >= 4 && $challenge->isOpen()) {
            $challenge->update(['status' => Challenge::STATUS_READY]);

            // Уведомляем всех — можно начинать
            $fcm = app(FCMNotificationService::class);
            foreach ($challenge->confirmedPlayers()->with('user')->get() as $player) {
                $this->notifyPlayer($player->user, 'Все игроки собраны!', 'Ваш поединок готов к началу', 'challenge_ready', $challenge->id);
            }
        }
    }

    private function calculateElo(Challenge $challenge, int $totalScoreA, int $totalScoreB): void
    {
        $challenge->load('confirmedPlayers.user');

        $teamAPlayers = $challenge->confirmedPlayers->filter(fn($p) => $p->isTeamA());
        $teamBPlayers = $challenge->confirmedPlayers->filter(fn($p) => $p->isTeamB());

        $teamARating = $teamAPlayers->avg(fn($p) => $p->user->rating);
        $teamBRating = $teamBPlayers->avg(fn($p) => $p->user->rating);

        $changes = $this->calculateRatingChange($teamARating, $teamBRating, $totalScoreA, $totalScoreB);

        foreach ($teamAPlayers as $player) {
            $this->applyPlayerRating($player, $changes['change1']);
        }

        foreach ($teamBPlayers as $player) {
            $this->applyPlayerRating($player, $changes['change2']);
        }
    }

    private function applyPlayerRating(ChallengePlayer $player, int $change): void
    {
        $user = $player->user;
        $before = $user->rating;
        $after = $this->applyRatingChange($before, $change);

        $player->update([
            'rating_before' => $before,
            'rating_after' => $after,
            'rating_change' => $after - $before,
        ]);

        $user->update(['rating' => $after]);
        $this->updateLevel($user);

        RatingHistory::create([
            'user_id' => $user->id,
            'rating' => $after,
            'change' => $after - $before,
            'reason' => 'challenge',
        ]);
    }

    private function notifyPlayer(User $user, string $title, string $body, string $type, int $challengeId): void
    {
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'data' => ['challenge_id' => $challengeId],
        ]);

        $fcm = app(FCMNotificationService::class);
        $fcm->sendToUser($user, $title, $body, [
            'type' => $type,
            'challenge_id' => (string) $challengeId,
        ]);
    }

    private function formatChallenge(Challenge $challenge, User $currentUser): array
    {
        $players = $challenge->players->map(function($p) use ($currentUser) {
            $name = $p->user->name ?? 'Без имени';
            $parts = explode(' ', $name, 2);
            return [
                'id' => $p->user->id,
                'position' => $p->position,
                'status' => $p->status,
                'first_name' => $parts[0] ?? '',
                'last_name' => $parts[1] ?? '',
                'full_name' => $name,
                'avatar' => $p->user->avatar,
                'rating' => $p->user->rating,
                'level' => (float) $p->user->level,
                'rating_before' => $p->rating_before,
                'rating_after' => $p->rating_after,
                'rating_change' => $p->rating_change,
                'is_me' => $p->user->id === $currentUser->id,
            ];
        });

        $myPlayer = $challenge->players->firstWhere('user_id', $currentUser->id);

        return [
            'id' => $challenge->id,
            'creator_id' => $challenge->creator_id,
            'is_creator' => $challenge->creator_id === $currentUser->id,
            'club' => $challenge->club ? [
                'id' => $challenge->club->id,
                'name' => $challenge->club->name,
            ] : null,
            'scheduled_at' => $challenge->scheduled_at->toIso8601String(),
            'type' => $challenge->type,
            'type_name' => $challenge->type_name,
            'min_level' => (float) $challenge->min_level,
            'max_level' => (float) $challenge->max_level,
            'status' => $challenge->status,
            'status_name' => $challenge->status_name,
            'score' => $challenge->score,
            'players' => $players,
            'confirmed_count' => $players->where('status', 'confirmed')->count(),
            'available_positions' => $challenge->getAvailablePositions(),
            'is_participant' => $myPlayer !== null,
            'my_status' => $myPlayer?->status,
            'my_position' => $myPlayer?->position,
            'my_rating_change' => $myPlayer?->rating_change,
        ];
    }
}

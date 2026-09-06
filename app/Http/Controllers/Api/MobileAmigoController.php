<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\PlayerFollow;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\FCMNotificationService;
use App\Support\AmigoActivity;
use App\Support\PlayerPartners;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Амигос: кого игрок добавил, кто добавил его, чем все они заняты.
 *
 * Связь односторонняя и без подтверждений: добавил — видишь его активность.
 * Взаимность (обе строки существуют) показываем словом, но прав она не даёт.
 */
class MobileAmigoController extends Controller
{
    /** GET /amigos — мои амигос со статусами. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = $this->followingIds($user->id);

        $players = $this->players($ids, $user->id);

        if ($request->query('filter') === 'playing') {
            $players = array_values(array_filter(
                $players,
                fn ($p) => ($p['status']['kind'] ?? null) === 'playing'
            ));
        }

        return response()->json([
            'success' => true,
            'count' => count($ids),
            'playing_count' => count(array_filter(
                $players,
                fn ($p) => ($p['status']['kind'] ?? null) === 'playing'
            )),
            'amigos' => $players,
        ]);
    }

    /** GET /amigos/followers — кто добавил меня. */
    public function followers(Request $request): JsonResponse
    {
        $user = $request->user();

        $rows = PlayerFollow::where('following_id', $user->id)
            ->orderByDesc('id')
            ->get(['follower_id', 'created_at']);

        $addedAt = $rows->pluck('created_at', 'follower_id');
        $players = $this->players($rows->pluck('follower_id')->all(), $user->id, sort: false);

        foreach ($players as &$player) {
            $player['added_at'] = optional($addedAt[$player['id']] ?? null)?->toIso8601String();
        }

        return response()->json([
            'success' => true,
            'count' => count($players),
            'followers' => $players,
        ]);
    }

    /** POST /amigos/{user} — добавить. */
    public function follow(Request $request, User $user): JsonResponse
    {
        $me = $request->user();

        if ($me->id === $user->id) {
            return response()->json(['success' => false, 'message' => 'Себя добавить нельзя'], 422);
        }

        if (UserBlock::betweenExists($me->id, $user->id)) {
            return response()->json(['success' => false, 'message' => 'Игрок недоступен'], 422);
        }

        $created = PlayerFollow::firstOrCreate(
            ['follower_id' => $me->id, 'following_id' => $user->id],
            ['created_at' => now()]
        )->wasRecentlyCreated;

        // Повторное добавление не ошибка, но и уведомление второй раз не шлём.
        if ($created) {
            $this->notify(
                $user,
                'Вас добавили в амигос',
                "{$me->name} добавил вас в амигос",
                'amigo_added',
                $me->id
            );
        }

        return response()->json([
            'success' => true,
            'is_amigo' => true,
            'mutual' => $this->isMutual($me->id, $user->id),
        ]);
    }

    /** DELETE /amigos/{user} — убрать. */
    public function unfollow(Request $request, User $user): JsonResponse
    {
        PlayerFollow::where('follower_id', $request->user()->id)
            ->where('following_id', $user->id)
            ->delete();

        return response()->json(['success' => true, 'is_amigo' => false, 'mutual' => false]);
    }

    /**
     * GET /amigos/candidates — с кем уже играли, но ещё не в амигос.
     *
     * Лечит пустой экран на старте: список у человека появляется не с нуля,
     * а из тех, с кем он реально выходил на корт.
     */
    public function candidates(Request $request): JsonResponse
    {
        $user = $request->user();
        $already = $this->followingIds($user->id);
        $blocked = $this->blockedIds($user->id);

        $rows = array_filter(
            PlayerPartners::all($user),
            fn ($row) => !in_array($row['user_id'], $already, true)
                && !in_array($row['user_id'], $blocked, true)
        );

        $rows = array_slice(array_values($rows), 0, 10);
        $users = User::whereIn('id', array_column($rows, 'user_id'))->get()->keyBy('id');

        $candidates = [];
        foreach ($rows as $row) {
            $player = $users[$row['user_id']] ?? null;
            if (!$player || $player->hidden_from_rating) {
                continue;
            }

            $candidates[] = [
                'id' => $player->id,
                'name' => $player->name,
                'avatar' => $player->avatar,
                'level' => $player->level,
                'level_verified' => (bool) $player->level_verified,
                'rating' => (int) $player->rating,
                'games_together' => (int) $row['games'],
                'winrate' => $row['winrate'],
            ];
        }

        return response()->json(['success' => true, 'candidates' => $candidates]);
    }

    /**
     * GET /amigos/search?q= — найти игрока по имени и добавить.
     *
     * Кандидаты (те, с кем уже играли) закрывают только первый шаг. Дальше
     * человеку нужно найти конкретного Ержана, которого он знает по клубу, —
     * поэтому ищем по всей базе тем же поиском, что и рейтинг: он понимает
     * и «Ержан», и «Yerzhan».
     */
    public function search(Request $request): JsonResponse
    {
        $me = $request->user();
        $q = trim((string) $request->query('q', ''));

        // Меньше двух символов — это пол-базы в выдаче, толку ноль.
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'players' => []]);
        }

        $blocked = $this->blockedIds($me->id);
        $amigoIds = $this->followingIds($me->id);

        $query = User::visibleInRating()
            ->whereNotIn('id', array_merge($blocked, [$me->id]));

        // apply() фильтрует, orderExactFirst() только сортирует — нужны оба,
        // иначе в выдачу попадёт вся база.
        \App\Support\NameSearch::apply($query, $q);

        $players = \App\Support\NameSearch::orderExactFirst($query, $q)
            ->orderByDesc('rating')
            ->limit(30)
            ->get(['id', 'name', 'avatar', 'level', 'level_verified', 'rating']);

        return response()->json([
            'success' => true,
            'players' => $players->map(fn ($player) => [
                'id' => $player->id,
                'name' => $player->name,
                'avatar' => $player->avatar,
                'level' => $player->level,
                'level_verified' => (bool) $player->level_verified,
                'rating' => (int) $player->rating,
                'is_amigo' => in_array($player->id, $amigoIds, true),
            ])->values(),
        ]);
    }

    /** GET /amigos/feed — что у своих происходит. */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = $this->followingIds($user->id);

        $events = AmigoActivity::feed($ids);
        $users = User::whereIn('id', array_column($events, 'user_id'))
            ->get(['id', 'name', 'avatar', 'level', 'level_verified', 'rating', 'hidden_from_rating'])
            ->keyBy('id');

        $out = [];
        foreach ($events as $event) {
            $player = $users[$event['user_id']] ?? null;
            if (!$player || $player->hidden_from_rating) {
                continue;
            }

            $event['player'] = [
                'id' => $player->id,
                'name' => $player->name,
                'avatar' => $player->avatar,
                'level_verified' => (bool) $player->level_verified,
            ];
            $out[] = $event;
        }

        return response()->json(['success' => true, 'events' => $out]);
    }

    // ===== внутреннее =====

    /** @return array<int, int> */
    private function followingIds(int $userId): array
    {
        return PlayerFollow::where('follower_id', $userId)
            ->pluck('following_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int, int> */
    private function blockedIds(int $userId): array
    {
        $mine = UserBlock::where('user_id', $userId)->pluck('blocked_user_id');
        $theirs = UserBlock::where('blocked_user_id', $userId)->pluck('user_id');

        return $mine->merge($theirs)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function isMutual(int $meId, int $otherId): bool
    {
        return PlayerFollow::where('follower_id', $otherId)
            ->where('following_id', $meId)
            ->exists();
    }

    /**
     * Собрать строки игроков со статусами.
     *
     * Порядок не алфавитный: сначала кто на корте, потом у кого турнир скоро,
     * потом кто ищет игроков, дальше по имени. Полезное всегда сверху.
     *
     * @param  array<int, int> $ids
     */
    private function players(array $ids, int $meId, bool $sort = true): array
    {
        $ids = array_values(array_diff(array_unique($ids), $this->blockedIds($meId)));
        if ($ids === []) {
            return [];
        }

        $users = User::whereIn('id', $ids)
            ->get(['id', 'name', 'avatar', 'level', 'level_verified', 'rating', 'hidden_from_rating']);

        $statuses = AmigoActivity::cached($ids);

        $mutualIds = PlayerFollow::where('following_id', $meId)
            ->whereIn('follower_id', $ids)
            ->pluck('follower_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $amigoIds = PlayerFollow::where('follower_id', $meId)
            ->whereIn('following_id', $ids)
            ->pluck('following_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rows = [];
        foreach ($users as $player) {
            $status = $statuses[$player->id] ?? null;

            // Скрытого из рейтинга показываем как человека, но без активности:
            // он просил не светить его игры.
            if ($player->hidden_from_rating) {
                $status = null;
            }

            $rows[] = [
                'id' => $player->id,
                'name' => $player->name,
                'avatar' => $player->avatar,
                'level' => $player->level,
                'level_verified' => (bool) $player->level_verified,
                'rating' => (int) $player->rating,
                // «Взаимно» — это обе стороны сразу. На вкладке «меня добавили»
                // одна сторона есть у всех, и односторонний флаг там врал бы.
                'mutual' => in_array($player->id, $mutualIds, true)
                    && in_array($player->id, $amigoIds, true),
                'is_amigo' => in_array($player->id, $amigoIds, true),
                'status' => $status,
            ];
        }

        if ($sort) {
            $weight = ['playing' => 0, 'soon' => 1, 'looking' => 2];
            usort($rows, function ($a, $b) use ($weight) {
                $aw = $weight[$a['status']['kind'] ?? ''] ?? 3;
                $bw = $weight[$b['status']['kind'] ?? ''] ?? 3;

                return $aw === $bw ? strcasecmp($a['name'], $b['name']) : $aw <=> $bw;
            });
        }

        return $rows;
    }

    /** Уведомление про амигос — с оглядкой на тумблер в настройках. */
    private function notify(User $user, string $title, string $body, string $type, int $actorId): void
    {
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'category' => 'amigo',
            'data' => ['user_id' => $actorId],
        ]);

        if (!$user->notify_amigos) {
            return;
        }

        app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
            'type' => $type,
            'user_id' => (string) $actorId,
        ]);
    }
}

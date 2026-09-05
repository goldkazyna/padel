<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\PlayerFollow;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Блокировки и жалобы.
 *
 * Обязательная пара к открытой переписке: писать можно любому, значит должен
 * быть способ это прекратить. Сторы без этих двух вещей приложение с
 * перепиской между пользователями не пропускают.
 */
class MobileBlockController extends Controller
{
    /** GET /blocks — кого я заблокировал. */
    public function index(Request $request): JsonResponse
    {
        $rows = UserBlock::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get(['blocked_user_id', 'created_at']);

        $users = User::whereIn('id', $rows->pluck('blocked_user_id'))
            ->get(['id', 'name', 'avatar'])
            ->keyBy('id');

        $blocked = [];
        foreach ($rows as $row) {
            $user = $users[$row->blocked_user_id] ?? null;
            if (!$user) {
                continue;
            }

            $blocked[] = [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'blocked_at' => $row->created_at?->toIso8601String(),
            ];
        }

        return response()->json(['success' => true, 'blocked' => $blocked]);
    }

    /**
     * POST /users/{user}/block — заблокировать.
     *
     * Заодно рвём связь в обе стороны: смысл блокировки в том, что человек
     * исчезает из вашей жизни в приложении, а не только перестаёт писать.
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $me = $request->user();

        if ($me->id === $user->id) {
            return response()->json(['success' => false, 'message' => 'Себя заблокировать нельзя'], 422);
        }

        UserBlock::firstOrCreate(
            ['user_id' => $me->id, 'blocked_user_id' => $user->id],
            ['created_at' => now()]
        );

        PlayerFollow::where(function ($q) use ($me, $user) {
            $q->where('follower_id', $me->id)->where('following_id', $user->id);
        })->orWhere(function ($q) use ($me, $user) {
            $q->where('follower_id', $user->id)->where('following_id', $me->id);
        })->delete();

        return response()->json(['success' => true, 'blocked' => true]);
    }

    /** DELETE /users/{user}/block — разблокировать. */
    public function destroy(Request $request, User $user): JsonResponse
    {
        UserBlock::where('user_id', $request->user()->id)
            ->where('blocked_user_id', $user->id)
            ->delete();

        return response()->json(['success' => true, 'blocked' => false]);
    }

    /** POST /reports — жалоба на игрока или переписку. */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'reason' => 'required|in:' . implode(',', ContentReport::REASONS),
            'comment' => 'nullable|string|max:1000',
        ]);

        $me = $request->user();

        if ((int) $data['user_id'] === $me->id) {
            return response()->json(['success' => false, 'message' => 'На себя жаловаться не нужно'], 422);
        }

        ContentReport::create([
            'reporter_id' => $me->id,
            'reportable_type' => User::class,
            'reportable_id' => $data['user_id'],
            'reason' => $data['reason'],
            'comment' => $data['comment'] ?? null,
            'status' => ContentReport::STATUS_NEW,
        ]);

        return response()->json(['success' => true]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Управление модераторами клуба из мобильной админки.
 * Доступ — club_admin данного клуба (или super_admin) и feature 'moderators'.
 *
 * В отличие от веб-версии (где модератор создаётся с email+паролем для входа
 * в веб-панель), здесь модератором назначается СУЩЕСТВУЮЩИЙ пользователь
 * приложения, найденный по телефону — так он сможет заходить в приложение
 * (вход по телефону) и модерировать.
 *
 * Права (как на бэке): tournaments_full_access, can_view_activity_log.
 */
class MobileAdminModeratorController extends Controller
{
    /**
     * GET /api/mobile/admin/clubs/{club}/moderators
     */
    public function index(Request $request, Club $club): JsonResponse
    {
        if (!$this->canManageClub($request->user(), $club)) return $this->forbidden();
        if (!$club->hasFeature('moderators')) return $this->featureOff();

        $moderators = $club->moderators()->get()
            ->map(fn($u) => $this->formatModerator($u))
            ->values();

        return response()->json([
            'success' => true,
            'moderators' => $moderators,
        ]);
    }

    /**
     * GET /api/mobile/admin/clubs/{club}/moderators/search?phone=...
     * Поиск пользователей по телефону для добавления в модераторы.
     */
    public function search(Request $request, Club $club): JsonResponse
    {
        if (!$this->canManageClub($request->user(), $club)) return $this->forbidden();
        if (!$club->hasFeature('moderators')) return $this->featureOff();

        $phone = preg_replace('/\D/', '', (string) $request->get('phone', ''));
        if (strlen($phone) < 5) {
            return response()->json([
                'success' => false,
                'message' => 'Введите минимум 5 цифр номера',
            ], 400);
        }

        $existingIds = $club->moderators()->pluck('users.id')->all();

        $users = User::human()
            ->where('phone', 'LIKE', "%{$phone}%")
            ->whereNotIn('id', $existingIds)
            ->whereNotIn('role', ['super_admin', 'club_admin'])
            ->limit(10)
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'level' => $u->level !== null ? (float) $u->level : null,
                'avatar_url' => $u->avatar ?: null,
            ])
            ->values();

        return response()->json(['success' => true, 'users' => $users]);
    }

    /**
     * POST /api/mobile/admin/clubs/{club}/moderators
     * body: user_id, tournaments_full_access?, can_view_activity_log?
     */
    public function store(Request $request, Club $club): JsonResponse
    {
        if (!$this->canManageClub($request->user(), $club)) return $this->forbidden();
        if (!$club->hasFeature('moderators')) return $this->featureOff();

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'tournaments_full_access' => 'boolean',
            'can_view_activity_log' => 'boolean',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::find($request->integer('user_id'));

        if (in_array($user->role, ['super_admin', 'club_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя назначить этого пользователя модератором',
            ], 422);
        }

        if ($club->moderators()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Этот пользователь уже модератор клуба',
            ], 422);
        }

        $pivot = [
            'tournaments_full_access' => $request->boolean('tournaments_full_access'),
            'can_view_activity_log' => $request->boolean('can_view_activity_log'),
        ];

        $user->update(['role' => 'club_moderator']);
        $club->moderators()->syncWithoutDetaching([$user->id => $pivot]);

        return response()->json([
            'success' => true,
            'moderator' => $this->formatModerator(
                $club->moderators()->where('user_id', $user->id)->first()
            ),
        ]);
    }

    /**
     * PUT /api/mobile/admin/clubs/{club}/moderators/{target}/permissions
     */
    public function updatePermissions(Request $request, Club $club, User $target): JsonResponse
    {
        if (!$this->canManageClub($request->user(), $club)) return $this->forbidden();
        if (!$club->moderators()->where('user_id', $target->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не модератор этого клуба',
            ], 404);
        }

        $club->moderators()->updateExistingPivot($target->id, [
            'tournaments_full_access' => $request->boolean('tournaments_full_access'),
            'can_view_activity_log' => $request->boolean('can_view_activity_log'),
        ]);

        return response()->json([
            'success' => true,
            'moderator' => $this->formatModerator(
                $club->moderators()->where('user_id', $target->id)->first()
            ),
        ]);
    }

    /**
     * DELETE /api/mobile/admin/clubs/{club}/moderators/{target}
     */
    public function destroy(Request $request, Club $club, User $target): JsonResponse
    {
        if (!$this->canManageClub($request->user(), $club)) return $this->forbidden();

        $club->moderators()->detach($target->id);

        // Если больше нигде не модератор — вернуть роль player.
        if ($target->role === 'club_moderator' && $target->moderatorClubs()->count() === 0) {
            $target->update(['role' => 'player']);
        }

        return response()->json(['success' => true]);
    }

    private function formatModerator(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'avatar_url' => $u->avatar ?: null,
            'tournaments_full_access' => (bool) ($u->pivot->tournaments_full_access ?? false),
            'can_view_activity_log' => (bool) ($u->pivot->can_view_activity_log ?? false),
        ];
    }

    private function canManageClub($user, Club $club): bool
    {
        if (!$user) return false;
        if ($user->isSuperAdmin()) return true;
        return $user->adminClubs()->where('clubs.id', $club->id)->exists();
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Нет доступа к этому клубу',
        ], 403);
    }

    private function featureOff(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Раздел «Модераторы» отключён для этого клуба',
        ], 403);
    }
}

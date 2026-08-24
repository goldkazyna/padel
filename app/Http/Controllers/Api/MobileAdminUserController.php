<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\User;
use App\Models\UserLevelHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Управление игроками клуба из мобильной админки.
 * Доступ — club_admin данного клуба и наличие feature 'users'.
 *
 * Логика идентична веб-админке (Club\UserController):
 * - формат списка с фильтрами (search, level, exact_level, verified)
 * - фильтр по городу клуба (Алматы и null/'' включены, иначе только город клуба)
 * - редактирование name + level, авто-выставление level_verified=true
 * - запись в ActivityLog и UserLevelHistory
 */
class MobileAdminUserController extends Controller
{
    /**
     * GET /api/mobile/admin/clubs/{club}/users
     */
    public function index(Request $request, Club $club): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageClub($user, $club)) {
            return $this->forbidden();
        }
        if (!$club->hasFeature('users')) {
            return response()->json([
                'success' => false,
                'message' => 'Раздел «Пользователи» отключён для этого клуба',
            ], 403);
        }

        $query = User::human()->orderBy('name');

        // Фильтр по городу клуба — как в Web
        if ($club->city) {
            $clubCity = $club->city;
            if ($clubCity === 'Алматы') {
                $query->where(function ($q) use ($clubCity) {
                    $q->where('city', $clubCity)
                      ->orWhereNull('city')
                      ->orWhere('city', '');
                });
            } else {
                $query->where('city', $clubCity);
            }
        }

        // Поиск
        if ($search = trim((string) $request->get('search', ''))) {
            // Имя ищем во всех написаниях: «Денис» находит и Denis.
            $variants = \App\Support\NameSearch::variants($search);
            $query->where(function ($q) use ($search, $variants) {
                foreach ($variants as $variant) {
                    $q->orWhere('name', 'like', "%{$variant}%")
                      ->orWhere('first_name', 'like', "%{$variant}%")
                      ->orWhere('last_name', 'like', "%{$variant}%");
                }
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });

            // Совпавшие с запросом как есть — выше найденных по другому
            // написанию имени. Приоритет ставим перед сортировкой по имени.
            $query->reorder();
            \App\Support\NameSearch::orderExactFirst($query, $search);
            $query->orderBy('name');
        }

        // Уровень
        if ($exactLevel = $request->get('exact_level')) {
            $query->where('level', (float) $exactLevel);
        } elseif ($level = $request->get('level')) {
            $min = (float) $level;
            $max = $min + 0.75;
            $query->whereBetween('level', [$min, $max]);
        }

        // Верификация
        $verified = $request->get('verified');
        if ($verified === 'unverified') {
            $query->where(function ($q) {
                $q->where('level_verified', false)
                  ->orWhereNull('level_verified');
            });
        } elseif ($verified === 'verified') {
            $query->where('level_verified', true);
        }

        $perPage = 30;
        $paginator = $query->paginate($perPage)->withQueryString();

        // Кулдаун ручной смены рейтинга: 2 месяца с последней ручной
        // корректировки. Карта [user_id => Carbon дата_разблокировки] для
        // игроков на странице. Супер-админ не ограничен — пустая карта.
        $ratingLocks = [];
        if (!$user->isSuperAdmin()) {
            $ids = $paginator->getCollection()->pluck('id')->all();
            if (!empty($ids)) {
                $rows = \App\Models\RatingHistory::whereIn('user_id', $ids)
                    ->where('reason', 'Ручная корректировка')
                    ->selectRaw('user_id, MAX(created_at) as last_manual')
                    ->groupBy('user_id')
                    ->get();
                foreach ($rows as $r) {
                    $until = \Carbon\Carbon::parse($r->last_manual)->addMonths(2);
                    if ($until->isFuture()) {
                        $ratingLocks[$r->user_id] = $until;
                    }
                }
            }
        }

        // Статистика по уровням (без фильтров поиска/уровня — глобально по городу)
        $statsQuery = User::human();
        if ($club->city) {
            $clubCity = $club->city;
            if ($clubCity === 'Алматы') {
                $statsQuery->where(function ($q) use ($clubCity) {
                    $q->where('city', $clubCity)
                      ->orWhereNull('city')
                      ->orWhere('city', '');
                });
            } else {
                $statsQuery->where('city', $clubCity);
            }
        }
        $stats = $statsQuery->selectRaw("
            SUM(CASE WHEN level >= 1 AND level <= 1.75 THEN 1 ELSE 0 END) as l1,
            SUM(CASE WHEN level >= 2 AND level <= 2.75 THEN 1 ELSE 0 END) as l2,
            SUM(CASE WHEN level >= 3 AND level <= 3.75 THEN 1 ELSE 0 END) as l3,
            SUM(CASE WHEN level >= 4 AND level <= 4.75 THEN 1 ELSE 0 END) as l4,
            SUM(CASE WHEN level >= 5 AND level <= 5.75 THEN 1 ELSE 0 END) as l5
        ")->first();

        return response()->json([
            'success' => true,
            'users' => $paginator->getCollection()->map(fn($u) => $this->formatUserRow($u, $ratingLocks[$u->id] ?? null))->values(),
            'page' => $paginator->currentPage(),
            'total_pages' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'level_stats' => [
                'l1' => (int) ($stats->l1 ?? 0),
                'l2' => (int) ($stats->l2 ?? 0),
                'l3' => (int) ($stats->l3 ?? 0),
                'l4' => (int) ($stats->l4 ?? 0),
                'l5' => (int) ($stats->l5 ?? 0),
            ],
        ]);
    }

    /**
     * PUT /api/mobile/admin/clubs/{club}/users/{user}
     * Обновить имя и/или уровень игрока. level_verified=true проставляется
     * автоматически. Логируется в ActivityLog + UserLevelHistory.
     */
    public function update(Request $request, Club $club, User $target): JsonResponse
    {
        $actor = $request->user();
        if (!$this->canManageClub($actor, $club)) {
            return $this->forbidden();
        }
        if (!$club->hasFeature('users')) {
            return response()->json([
                'success' => false,
                'message' => 'Раздел «Пользователи» отключён для этого клуба',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'level' => 'nullable|numeric|min:1|max:5.75',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }
        $validated = $validator->validated();

        $oldName = $target->name;
        $oldLevel = $target->level !== null ? (float) $target->level : null;
        $oldVerified = (bool) $target->level_verified;
        $oldRating = (int) ($target->rating ?? 0);

        // Кулдаун 2 месяца: клубный админ не может менять уровень/рейтинг,
        // если рейтинг уже правили вручную менее 2 месяцев назад. Супер-админ
        // — без ограничений. Имя менять можно всегда.
        if (!$actor->isSuperAdmin()) {
            $lastManual = \App\Models\RatingHistory::where('user_id', $target->id)
                ->where('reason', 'Ручная корректировка')
                ->latest('created_at')
                ->first();
            if ($lastManual && ($until = $lastManual->created_at->copy()->addMonths(2))->isFuture()) {
                $attemptedLevel = array_key_exists('level', $validated) && $validated['level'] !== null
                    ? (float) $validated['level'] : null;
                if ($attemptedLevel !== null && $attemptedLevel !== $oldLevel) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Рейтинг игрока менялся недавно. Изменить уровень можно с '
                            . $until->format('d.m.Y') . ' — не чаще раза в 2 месяца.',
                        'rating_locked_until' => $until->toIso8601String(),
                    ], 422);
                }
                // Защита от обхода: уровень/рейтинг не трогаем, даже если пришли.
                unset($validated['level']);
            }
        }

        $update = ['name' => $validated['name']];
        $newLevel = null;
        if (array_key_exists('level', $validated) && $validated['level'] !== null) {
            $newLevel = (float) $validated['level'];
            $update['level'] = $newLevel;
            $update['rating'] = (int) ($newLevel * 1000 + 125);
        }
        // Ручная правка уровня админом верифицирует уровень ТОЛЬКО если у
        // игрока есть аватар. Без фото уровень не верифицируем, даже если
        // его выставили вручную.
        $update['level_verified'] = !empty($target->avatar);

        $target->update($update);
        $target->refresh();

        // Фиксируем ручную правку рейтинга в rating_history (tournament_id=null),
        // чтобы график «Динамика рейтинга» был полным.
        if (isset($update['rating']) && $update['rating'] !== $oldRating) {
            \App\Models\RatingHistory::create([
                'user_id' => $target->id,
                'tournament_id' => null,
                // Игрок должен видеть, чей администратор правил ему рейтинг.
                'changed_by_user_id' => $actor->id,
                'club_id' => $club->id,
                'rating_before' => $oldRating,
                'rating_after' => (int) $update['rating'],
                'change' => (int) $update['rating'] - $oldRating,
                'reason' => \App\Models\RatingHistory::REASON_MANUAL,
            ]);
        }
        $newVerified = (bool) $target->level_verified;

        // Сборка changes
        $changes = [];
        if ($oldName !== $validated['name']) {
            $changes['name'] = ['from' => $oldName, 'to' => $validated['name']];
        }
        if ($newLevel !== null && $oldLevel !== $newLevel) {
            $changes['level'] = ['from' => $oldLevel, 'to' => $newLevel];
        }
        if ($oldVerified !== $newVerified) {
            $changes['level_verified'] = ['from' => $oldVerified, 'to' => $newVerified];
        }

        if (!empty($changes)) {
            $action = isset($changes['level']) ? 'level_changed' : 'updated';
            $description = isset($changes['level'])
                ? sprintf(
                    'Уровень %s изменён с %s на %s',
                    $target->name,
                    $oldLevel !== null ? rtrim(rtrim(number_format($oldLevel, 2, '.', ''), '0'), '.') : '—',
                    rtrim(rtrim(number_format($newLevel, 2, '.', ''), '0'), '.')
                )
                : 'Профиль ' . $target->name . ' обновлён';

            ActivityLog::log(
                action: $action,
                subjectType: 'User',
                subjectId: $target->id,
                description: $description,
                changes: $changes,
                clubId: $club->id,
            );
        }

        if (isset($changes['level']) || isset($changes['level_verified'])) {
            UserLevelHistory::create([
                'user_id' => $target->id,
                'changed_by_user_id' => $actor->id,
                'club_id' => $club->id,
                'old_level' => $oldLevel,
                'new_level' => $newLevel ?? $oldLevel,
                'old_verified' => $oldVerified,
                'new_verified' => $newVerified,
                'created_at' => now(),
            ]);
        }

        // Актуальная дата блокировки для ответа (после возможной новой ручной
        // корректировки): чтобы приложение сразу заблокировало поле.
        $respLock = null;
        if (!$actor->isSuperAdmin()) {
            $lm = \App\Models\RatingHistory::where('user_id', $target->id)
                ->where('reason', 'Ручная корректировка')
                ->latest('created_at')
                ->first();
            if ($lm && ($u2 = $lm->created_at->copy()->addMonths(2))->isFuture()) {
                $respLock = $u2;
            }
        }

        return response()->json([
            'success' => true,
            'user' => $this->formatUserRow($target->refresh(), $respLock),
        ]);
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

    private function formatUserRow(User $u, ?\Carbon\Carbon $lockedUntil = null): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'level' => $u->level !== null ? (float) $u->level : null,
            'rating' => (int) ($u->rating ?? 0),
            'level_verified' => (bool) $u->level_verified,
            'avatar_url' => $u->avatar ?: null,
            // Дата, до которой запрещено менять уровень/рейтинг (ISO8601) или null.
            'rating_locked_until' => $lockedUntil?->toIso8601String(),
        ];
    }
}

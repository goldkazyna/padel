<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\AmericanoService;
use App\Services\KingOfCourtService;
use App\Services\MexicanoService;
use App\Services\TeamTournamentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Управление существующим турниром из мобилы (админ клуба).
 * Этап 3a: инфо-таб, редактирование, запуск, удаление.
 * Этап 3b: участники — модерация, добавление, удаление, замена.
 */
class MobileAdminTournamentDetailController extends Controller
{
    /**
     * GET /api/mobile/admin/tournaments/{tournament}
     */
    public function show(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $tournament->loadMissing('club');

        return response()->json([
            'success' => true,
            'tournament' => $this->formatDetail($tournament),
        ]);
    }

    /**
     * PUT /api/mobile/admin/tournaments/{tournament}
     */
    public function update(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if (!in_array($tournament->status, ['draft', 'open'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Редактировать можно только черновик или открытый турнир',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'min_level' => 'required|numeric|min:1|max:5.75',
            'max_level' => 'required|numeric|min:1|max:5.75|gte:min_level',
            'max_participants' => 'required|integer|min:2|max:128',
            'price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // Не позволяем уменьшать max_participants ниже текущих участников
        $taken = $tournament->takenSlotsCount();
        if ($validated['max_participants'] < $taken) {
            return response()->json([
                'success' => false,
                'message' => "Уже {$taken} участников — нельзя поставить лимит меньше",
            ], 422);
        }

        $tournament->update($validated);
        $tournament->refresh()->loadMissing('club');

        return response()->json([
            'success' => true,
            'tournament' => $this->formatDetail($tournament),
        ]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/start
     */
    public function start(
        Request $request,
        Tournament $tournament,
        AmericanoService $americano,
        MexicanoService $mexicano,
        TeamTournamentService $team,
        KingOfCourtService $king
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ($tournament->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Запустить можно только открытый турнир',
            ], 422);
        }

        if ($tournament->isAmericano()) {
            $ok = $americano->startTournament($tournament);
        } elseif ($tournament->isMexicano()) {
            $ok = $mexicano->startTournament($tournament);
        } elseif ($tournament->isTeamBased()) {
            $ok = $team->startTournament($tournament);
        } elseif ($tournament->isKingOfCourt()) {
            $ok = $king->startTournament($tournament);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Неизвестный тип турнира',
            ], 422);
        }

        if (!$ok) {
            return response()->json([
                'success' => false,
                'message' => 'Не удалось начать турнир. Проверьте количество участников/пар.',
            ], 422);
        }

        $tournament->refresh()->loadMissing('club');

        return response()->json([
            'success' => true,
            'tournament' => $this->formatDetail($tournament),
        ]);
    }

    /**
     * DELETE /api/mobile/admin/tournaments/{tournament}
     */
    public function destroy(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ($tournament->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Можно удалить только черновик',
            ], 422);
        }

        $tournament->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    private function canManageTournament($user, Tournament $tournament): bool
    {
        if (!$user) return false;
        if ($user->isSuperAdmin()) return true;
        return $user->adminClubs()->where('clubs.id', $tournament->club_id)->exists();
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Нет доступа к этому турниру',
        ], 403);
    }

    private function formatDetail(Tournament $t): array
    {
        $taken = $this->getParticipantsCount($t);
        $minRequired = $t->isTeamBased() ? 4 : 4; // минимум для запуска
        $canStart = $t->status === 'open' && $taken >= $minRequired;
        $canEdit = in_array($t->status, ['draft', 'open'], true);
        $canDelete = $t->status === 'draft';

        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'type' => $t->type,
            'type_name' => $t->type_name,
            'status' => $t->status,
            'status_name' => $t->status_name,
            'club' => $t->club ? [
                'id' => $t->club->id,
                'name' => $t->club->name,
            ] : null,
            'start_date' => $t->start_date?->toIso8601String(),
            'min_level' => (float) $t->min_level,
            'max_level' => (float) $t->max_level,
            'max_participants' => (int) $t->max_participants,
            'participants_count' => $taken,
            'pending_count' => $this->getPendingCount($t),
            'price' => $t->price !== null ? (float) $t->price : null,
            'has_playoff' => (bool) $t->has_playoff,
            'has_lower_bracket' => (bool) $t->has_lower_bracket,
            'has_bronze_match' => (bool) $t->has_bronze_match,
            'courts' => $t->courts ?? [],
            'can_edit' => $canEdit,
            'can_start' => $canStart,
            'can_delete' => $canDelete,
        ];
    }

    private function getParticipantsCount(Tournament $t): int
    {
        if ($t->type === 'team') {
            return TournamentTeam::where('tournament_id', $t->id)
                ->whereIn('status', ['approved', 'pending'])
                ->count() * 2;
        }
        return $t->participants()
            ->wherePivotIn('status', ['registered', 'pending'])
            ->count();
    }

    private function getPendingCount(Tournament $t): int
    {
        if ($t->type === 'team') {
            return TournamentTeam::where('tournament_id', $t->id)
                ->where('status', 'pending')
                ->count();
        }
        return $t->participants()
            ->wherePivot('status', 'pending')
            ->count();
    }

    // -------------------------------------------------------------------------
    // 3b — Участники
    // -------------------------------------------------------------------------

    /**
     * GET /api/mobile/admin/tournaments/{tournament}/participants
     */
    public function participants(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ($tournament->type === 'team') {
            $teams = $tournament->teams()
                ->with(['player1', 'player2'])
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
                ->orderBy('id')
                ->get()
                ->map(fn($t) => [
                    'id' => $t->id,
                    'status' => $t->status,
                    'player1' => $t->player1 ? $this->formatUser($t->player1) : null,
                    'player2' => $t->player2 ? $this->formatUser($t->player2) : null,
                ]);

            return response()->json([
                'success' => true,
                'type' => 'team',
                'teams' => $teams,
                'max_teams' => (int) ($tournament->max_participants / 2),
                'can_modify' => $this->canModifyParticipants($tournament),
            ]);
        }

        $list = $tournament->participants()
            ->withPivot(['status', 'created_at'])
            ->orderByRaw("CASE tournament_participants.status WHEN 'pending' THEN 0 WHEN 'registered' THEN 1 ELSE 2 END")
            ->get()
            ->map(function ($u) {
                $arr = $this->formatUser($u);
                $arr['status'] = $u->pivot->status;
                $arr['registered_at'] = $u->pivot->created_at
                    ? $u->pivot->created_at->toIso8601String() : null;
                return $arr;
            });

        return response()->json([
            'success' => true,
            'type' => 'single',
            'participants' => $list,
            'max_participants' => (int) $tournament->max_participants,
            'can_modify' => $this->canModifyParticipants($tournament),
        ]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/participants/{user}/approve
     */
    public function approveParticipant(Request $request, Tournament $tournament, User $user): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ($tournament->status !== 'open') {
            return $this->error('Турнир не открыт для регистрации');
        }

        $row = $tournament->participants()->where('user_id', $user->id)->first();
        if (!$row) {
            return $this->error('Участник не найден', 404);
        }
        if ($row->pivot->status !== 'pending') {
            return $this->error('Заявка уже обработана');
        }

        $approved = DB::transaction(function () use ($tournament, $user) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            $approvedCount = $tournament->participants()
                ->wherePivot('status', 'registered')
                ->count();
            if ($approvedCount >= $tournament->max_participants) {
                return false;
            }

            $tournament->participants()
                ->updateExistingPivot($user->id, ['status' => 'registered']);
            return true;
        });

        if (!$approved) {
            return $this->error('Достигнут лимит участников');
        }

        $this->notifyApproved($tournament, $user);

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/participants/{user}/reject
     */
    public function rejectParticipant(Request $request, Tournament $tournament, User $user): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $tournament->participants()->detach($user->id);
        $this->notifyRejected($tournament, $user);

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /api/mobile/admin/tournaments/{tournament}/participants/{user}
     */
    public function removeParticipant(Request $request, Tournament $tournament, User $user): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ($tournament->isAmericano() && $tournament->groups()->count() > 0) {
            return $this->error('Группы уже сформированы. Используйте редактор групп в Web.');
        }

        $tournament->participants()->detach($user->id);
        return response()->json(['success' => true]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/participants
     * body: user_id
     */
    public function addParticipant(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ($tournament->isAmericano() && $tournament->groups()->count() > 0) {
            return $this->error('Группы уже сформированы. Используйте редактор групп в Web.');
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }
        $userId = (int) $validator->validated()['user_id'];

        $result = DB::transaction(function () use ($tournament, $userId) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            if ($tournament->participants()->where('user_id', $userId)->exists()) {
                return 'exists';
            }
            if ($tournament->takenSlotsCount() >= $tournament->max_participants) {
                return 'full';
            }
            $tournament->participants()->attach($userId, ['status' => 'registered']);
            return 'ok';
        });

        if ($result === 'exists') return $this->error('Игрок уже добавлен');
        if ($result === 'full') return $this->error('Достигнут лимит участников');

        return response()->json(['success' => true]);
    }

    /**
     * PUT /api/mobile/admin/tournaments/{tournament}/participants/{user}
     * body: new_user_id
     */
    public function replaceParticipant(Request $request, Tournament $tournament, User $user): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ($tournament->isAmericano() && $tournament->groups()->count() > 0) {
            return $this->error('Группы уже сформированы. Используйте редактор групп в Web.');
        }

        $validator = Validator::make($request->all(), [
            'new_user_id' => 'required|exists:users,id',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }
        $newId = (int) $validator->validated()['new_user_id'];

        if ($newId === $user->id) {
            return $this->error('Это тот же игрок');
        }

        if ($tournament->participants()->where('user_id', $newId)->exists()) {
            return $this->error('Этот игрок уже участвует');
        }

        DB::transaction(function () use ($tournament, $user, $newId) {
            $tournament->participants()->detach($user->id);
            $tournament->participants()->attach($newId, ['status' => 'registered']);
        });

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/mobile/admin/tournaments/{tournament}/players/search?q=...
     */
    public function searchPlayers(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'players' => []]);
        }

        // Уже занятые игроки (для одиночных — pivot, для team — обе колонки)
        if ($tournament->type === 'team') {
            $excluded = $tournament->teams()
                ->get()
                ->flatMap(fn($t) => [$t->player1_id, $t->player2_id])
                ->unique()
                ->values()
                ->toArray();
        } else {
            $excluded = $tournament->participants()->pluck('users.id')->toArray();
        }

        $players = User::where('role', 'player')
            ->where(function ($qq) use ($q) {
                $qq->where('phone', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%");
            })
            ->whereNotIn('id', $excluded)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone', 'level', 'rating', 'avatar'])
            ->map(fn($u) => $this->formatUser($u));

        return response()->json([
            'success' => true,
            'players' => $players,
        ]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/teams/{team}/approve
     */
    public function approveTeam(Request $request, Tournament $tournament, TournamentTeam $team): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        if ($team->tournament_id !== $tournament->id) {
            return $this->error('Пара не принадлежит этому турниру', 404);
        }
        if ($tournament->status !== 'open') {
            return $this->error('Турнир не открыт');
        }

        $team->update(['status' => 'approved']);
        return response()->json(['success' => true]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/teams/{team}/reject
     */
    public function rejectTeam(Request $request, Tournament $tournament, TournamentTeam $team): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        if ($team->tournament_id !== $tournament->id) {
            return $this->error('Пара не принадлежит этому турниру', 404);
        }
        $team->delete();
        return response()->json(['success' => true]);
    }

    /**
     * DELETE /api/mobile/admin/tournaments/{tournament}/teams/{team}
     */
    public function removeTeam(Request $request, Tournament $tournament, TournamentTeam $team): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        if ($team->tournament_id !== $tournament->id) {
            return $this->error('Пара не принадлежит этому турниру', 404);
        }
        if ($tournament->status !== 'open') {
            return $this->error('Турнир уже начат');
        }
        $team->delete();
        return response()->json(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // helpers 3b
    // -------------------------------------------------------------------------

    private function canModifyParticipants(Tournament $t): bool
    {
        // Базово: можно править пока турнир не запущен
        if (!in_array($t->status, ['draft', 'open'], true)) return false;
        // Для Americano — пока группы не сформированы
        if ($t->isAmericano() && $t->groups()->count() > 0) return false;
        return true;
    }

    private function formatUser(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'level' => $u->level !== null ? (float) $u->level : null,
            'rating' => $u->rating !== null ? (int) $u->rating : null,
            'avatar_url' => $u->avatar
                ? asset('storage/' . $u->avatar)
                : null,
        ];
    }

    private function error(string $message, int $code = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $code);
    }

    private function notifyApproved(Tournament $tournament, User $user): void
    {
        try {
            $service = new \App\Services\TelegramNotificationService($tournament->club);
            $service->notifyRegistrationApproved($user, $tournament);

            $date = $tournament->start_date->format('d.m.Y H:i');
            \App\Models\Notification::create([
                'user_id' => $user->id,
                'title' => 'Заявка одобрена!',
                'body' => "Вы записаны на турнир «{$tournament->name}» — {$date}",
                'type' => 'registration_approved',
                'category' => 'tournament',
                'data' => ['tournament_id' => $tournament->id],
            ]);

            $fcm = app(\App\Services\FCMNotificationService::class);
            $fcm->sendToUser(
                $user,
                'Заявка одобрена!',
                "Вы записаны на турнир «{$tournament->name}» — {$date}",
                [
                    'type' => 'registration_approved',
                    'tournament_id' => (string) $tournament->id,
                ]
            );
        } catch (\Throwable $e) {
            // нотификация не должна ронять основное действие
        }
    }

    private function notifyRejected(Tournament $tournament, User $user): void
    {
        try {
            $service = new \App\Services\TelegramNotificationService($tournament->club);
            $service->notifyRegistrationRejected($user, $tournament);

            \App\Models\Notification::create([
                'user_id' => $user->id,
                'title' => 'Заявка отклонена',
                'body' => "К сожалению, ваша заявка на турнир «{$tournament->name}» была отклонена",
                'type' => 'registration_rejected',
                'category' => 'tournament',
                'data' => ['tournament_id' => $tournament->id],
            ]);

            $fcm = app(\App\Services\FCMNotificationService::class);
            $fcm->sendToUser(
                $user,
                'Заявка отклонена',
                "К сожалению, ваша заявка на турнир «{$tournament->name}» была отклонена",
                [
                    'type' => 'registration_rejected',
                    'tournament_id' => (string) $tournament->id,
                ]
            );
        } catch (\Throwable $e) {
            // ignore
        }
    }
}

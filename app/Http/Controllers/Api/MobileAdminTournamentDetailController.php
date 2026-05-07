<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AmericanoMatch;
use App\Models\KingOfCourtMatch;
use App\Models\Tournament;
use App\Models\TournamentPlayoffMatch;
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
 * Этап 3c-1: матчи Американо (групповые + плей-офф) — просмотр и ввод счёта.
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
        $user = $request->user();
        if (!$this->canManageTournament($user, $tournament)) {
            return $this->forbidden();
        }
        if (!$this->hasTournamentsFullAccess($user, $tournament)) {
            return $this->noPermission('Нет прав на редактирование турниров');
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
        $user = $request->user();
        if (!$this->canManageTournament($user, $tournament)) {
            return $this->forbidden();
        }
        if (!$this->hasTournamentsFullAccess($user, $tournament)) {
            return $this->noPermission('Нет прав на удаление турниров');
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

    /**
     * Любой доступ к турниру — админ или модератор клуба (любой).
     * Используется для всех «провести турнир»-действий: модерация, ввод
     * счёта, генерация раундов/плей-офф, запуск, завершение.
     */
    private function canManageTournament($user, Tournament $tournament): bool
    {
        if (!$user) return false;
        if ($user->isSuperAdmin()) return true;
        $clubId = $tournament->club_id;
        if ($user->adminClubs()->where('clubs.id', $clubId)->exists()) {
            return true;
        }
        return $user->moderatorClubs()->where('clubs.id', $clubId)->exists();
    }

    /**
     * Полные права (правка/удаление) — только админ или full-access модератор.
     */
    private function hasTournamentsFullAccess($user, Tournament $tournament): bool
    {
        if (!$user) return false;
        $club = $tournament->club ?? \App\Models\Club::find($tournament->club_id);
        if (!$club) return false;
        return $user->hasTournamentsFullAccess($club);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Нет доступа к этому турниру',
        ], 403);
    }

    private function noPermission(string $message = 'Нет прав на это действие'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }

    private function formatDetail(Tournament $t, $user = null): array
    {
        $user = $user ?? auth()->user();
        $taken = $this->getParticipantsCount($t);
        $minRequired = $t->isTeamBased() ? 4 : 4; // минимум для запуска
        $hasFullAccess = $user
            ? $this->hasTournamentsFullAccess($user, $t)
            : false;
        $canStart = $t->status === 'open' && $taken >= $minRequired;
        // Редактировать/удалять — только с полными правами.
        $canEdit = $hasFullAccess
            && in_array($t->status, ['draft', 'open'], true);
        $canDelete = $hasFullAccess && $t->status === 'draft';

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
            'telegram_registration_url' => $t->telegram_registration_url,
            'has_playoff' => (bool) $t->has_playoff,
            'has_lower_bracket' => (bool) $t->has_lower_bracket,
            'has_bronze_match' => (bool) $t->has_bronze_match,
            'courts' => $t->courts ?? [],
            'can_edit' => $canEdit,
            'can_start' => $canStart,
            'can_delete' => $canDelete,
            'tournaments_full_access' => $hasFullAccess,
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

        $players = User::human()
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

    // -------------------------------------------------------------------------
    // 3c-1 — Матчи (Американо)
    // -------------------------------------------------------------------------

    /**
     * GET /api/mobile/admin/tournaments/{tournament}/matches
     */
    public function matches(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ($tournament->isAmericano()) {
            return response()->json($this->buildAmericanoMatches($tournament));
        }

        if ($tournament->isKingOfCourt()) {
            return response()->json($this->buildKingOfCourtMatches($tournament));
        }

        // Для Mexicano / Team — пока заглушка, добавим на этапах 3c-3/3c-4
        return response()->json([
            'success' => true,
            'type' => $tournament->type,
            'unsupported' => true,
            'message' => 'Тип турнира пока не поддерживается в мобильной админке',
        ]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/americano/matches/{match}/score
     */
    public function saveAmericanoScore(
        Request $request,
        Tournament $tournament,
        AmericanoMatch $match,
        AmericanoService $service
    ): JsonResponse {
        return $this->handleAmericanoScore($request, $tournament, $match, $service, isUpdate: false);
    }

    /**
     * PUT /api/mobile/admin/tournaments/{tournament}/americano/matches/{match}/score
     */
    public function updateAmericanoScore(
        Request $request,
        Tournament $tournament,
        AmericanoMatch $match,
        AmericanoService $service
    ): JsonResponse {
        return $this->handleAmericanoScore($request, $tournament, $match, $service, isUpdate: true);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/americano/playoff/{match}/score
     */
    public function saveAmericanoPlayoffScore(
        Request $request,
        Tournament $tournament,
        TournamentPlayoffMatch $match,
        AmericanoService $service
    ): JsonResponse {
        return $this->handleAmericanoPlayoffScore($request, $tournament, $match, $service, isUpdate: false);
    }

    /**
     * PUT /api/mobile/admin/tournaments/{tournament}/americano/playoff/{match}/score
     */
    public function updateAmericanoPlayoffScore(
        Request $request,
        Tournament $tournament,
        TournamentPlayoffMatch $match,
        AmericanoService $service
    ): JsonResponse {
        return $this->handleAmericanoPlayoffScore($request, $tournament, $match, $service, isUpdate: true);
    }

    // -------------------------------------------------------------------------
    // helpers 3c-1
    // -------------------------------------------------------------------------

    private function handleAmericanoScore(
        Request $request,
        Tournament $tournament,
        AmericanoMatch $match,
        AmericanoService $service,
        bool $isUpdate
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $match->loadMissing('round.group');
        if (!$match->round || !$match->round->group ||
            (int) $match->round->group->tournament_id !== (int) $tournament->id) {
            return $this->error('Матч не принадлежит этому турниру', 404);
        }

        $validator = Validator::make($request->all(), [
            'team1_score' => 'required|integer|min:0|max:99',
            'team2_score' => 'required|integer|min:0|max:99',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }
        $s1 = (int) $request->input('team1_score');
        $s2 = (int) $request->input('team2_score');

        if ($isUpdate) {
            if ($match->status !== 'completed') {
                return $this->error('Матч ещё не сыгран — используйте сохранение, а не обновление');
            }
            $service->updateMatchResult($match, $s1, $s2);
        } else {
            if ($match->status === 'completed') {
                return $this->error('Матч уже сыгран — используйте обновление счёта');
            }
            $service->saveMatchResult($match, $s1, $s2);
        }

        $match->refresh();
        return response()->json([
            'success' => true,
            'match' => [
                'id' => $match->id,
                'team1_score' => $match->team1_score,
                'team2_score' => $match->team2_score,
                'status' => $match->status,
                'winner' => $match->winning_team,
            ],
        ]);
    }

    private function handleAmericanoPlayoffScore(
        Request $request,
        Tournament $tournament,
        TournamentPlayoffMatch $match,
        AmericanoService $service,
        bool $isUpdate
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ((int) $match->tournament_id !== (int) $tournament->id) {
            return $this->error('Матч не принадлежит этому турниру', 404);
        }

        $validator = Validator::make($request->all(), [
            'team1_score' => 'required|integer|min:0|max:99',
            'team2_score' => 'required|integer|min:0|max:99|different:team1_score',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }
        $s1 = (int) $request->input('team1_score');
        $s2 = (int) $request->input('team2_score');

        if (!$isUpdate && $match->status === 'completed') {
            return $this->error('Матч уже сыгран — используйте обновление счёта');
        }
        if ($isUpdate && $match->status !== 'completed') {
            return $this->error('Матч ещё не сыгран — используйте сохранение, а не обновление');
        }

        $match->update([
            'team1_score' => $s1,
            'team2_score' => $s2,
            'status' => 'completed',
        ]);

        if ($match->stage === 'Полуфинал') {
            $service->updateFinalAfterSemifinal($match);
        }

        $match->refresh();
        $winner = null;
        if ($match->team1_score !== null && $match->team2_score !== null) {
            $winner = $match->team1_score > $match->team2_score ? 1 : 2;
        }

        return response()->json([
            'success' => true,
            'match' => [
                'id' => $match->id,
                'team1_score' => $match->team1_score,
                'team2_score' => $match->team2_score,
                'status' => $match->status,
                'winner' => $winner,
            ],
        ]);
    }

    private function buildAmericanoMatches(Tournament $tournament): array
    {
        $tournament->load([
            'groups.rounds.matches.team1Player1',
            'groups.rounds.matches.team1Player2',
            'groups.rounds.matches.team2Player1',
            'groups.rounds.matches.team2Player2',
            'groups.players',
            'playoffMatches.team1Player1',
            'playoffMatches.team1Player2',
            'playoffMatches.team2Player1',
            'playoffMatches.team2Player2',
        ]);

        $matchesTotal = 0;
        $matchesPlayed = 0;

        $groups = $tournament->groups->sortBy('id')->values()->map(function ($group) use (&$matchesTotal, &$matchesPlayed) {
            $rounds = $group->rounds->sortBy('round_number')->values()->map(function ($round) use (&$matchesTotal, &$matchesPlayed) {
                $matches = $round->matches->map(function ($m) use (&$matchesTotal, &$matchesPlayed) {
                    $matchesTotal++;
                    if ($m->status === 'completed') {
                        $matchesPlayed++;
                    }
                    return $this->formatAmericanoMatch($m);
                });

                return [
                    'id' => $round->id,
                    'round_number' => (int) $round->round_number,
                    'status' => $round->status,
                    'matches' => $matches,
                ];
            });

            $leaderboard = $this->buildAmericanoLeaderboard($group);

            return [
                'id' => $group->id,
                'name' => $group->name,
                'rounds' => $rounds,
                'leaderboard' => $leaderboard,
            ];
        });

        // Плей-офф
        $playoffMatches = $tournament->playoffMatches
            ->filter(fn($m) => $m->isAmericanoMatch())
            ->values();

        $playoff = [
            'has_playoff' => (bool) $tournament->has_playoff,
            'is_generated' => $playoffMatches->count() > 0,
            'matches' => $playoffMatches->map(fn($m) => $this->formatPlayoffMatch($m))->values(),
        ];

        $isLive = $tournament->status === 'in_progress';

        return [
            'success' => true,
            'type' => 'americano',
            'groups' => $groups,
            'playoff' => $playoff,
            'summary' => [
                'matches_total' => $matchesTotal,
                'matches_played' => $matchesPlayed,
                'all_group_matches_played' => $matchesTotal > 0 && $matchesTotal === $matchesPlayed,
                // Действия доступны только пока турнир идёт.
                'can_finish' => $isLive
                    && app(AmericanoService::class)->canFinishTournament($tournament),
                'can_generate_playoff' => $isLive
                    && app(AmericanoService::class)->canGeneratePlayoff($tournament),
            ],
        ];
    }

    private function buildAmericanoLeaderboard($group): array
    {
        $stats = [];
        foreach ($group->players as $p) {
            $stats[$p->id] = [
                'id' => $p->id,
                'name' => $p->full_name ?? $p->name,
                'avatar' => $p->avatar ? asset('storage/' . $p->avatar) : null,
                'rating' => (int) ($p->rating ?? 0),
                'wins' => 0,
                'losses' => 0,
                'draws' => 0,
                'points_for' => 0,
                'points_against' => 0,
                'total_points' => (int) ($p->pivot->total_points ?? 0),
            ];
        }

        foreach ($group->rounds as $round) {
            foreach ($round->matches as $m) {
                if ($m->status !== 'completed') continue;
                foreach ([$m->team1_player1_id, $m->team1_player2_id] as $pId) {
                    if (!isset($stats[$pId])) continue;
                    $stats[$pId]['points_for'] += (int) $m->team1_score;
                    $stats[$pId]['points_against'] += (int) $m->team2_score;
                    if ($m->team1_score > $m->team2_score) $stats[$pId]['wins']++;
                    elseif ($m->team1_score < $m->team2_score) $stats[$pId]['losses']++;
                }
                foreach ([$m->team2_player1_id, $m->team2_player2_id] as $pId) {
                    if (!isset($stats[$pId])) continue;
                    $stats[$pId]['points_for'] += (int) $m->team2_score;
                    $stats[$pId]['points_against'] += (int) $m->team1_score;
                    if ($m->team2_score > $m->team1_score) $stats[$pId]['wins']++;
                    elseif ($m->team2_score < $m->team1_score) $stats[$pId]['losses']++;
                }
                if ((int) $m->team1_score === (int) $m->team2_score) {
                    foreach ([$m->team1_player1_id, $m->team1_player2_id, $m->team2_player1_id, $m->team2_player2_id] as $pId) {
                        if (isset($stats[$pId])) $stats[$pId]['draws']++;
                    }
                }
            }
        }

        uasort($stats, function ($a, $b) {
            if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
            if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
            return ($b['points_for'] - $b['points_against']) <=> ($a['points_for'] - $a['points_against']);
        });

        $position = 1;
        $rows = [];
        foreach ($stats as $s) {
            $totalGames = $s['wins'] + $s['losses'] + $s['draws'];
            $diff = $s['points_for'] - $s['points_against'];
            $totalBalls = $s['points_for'] + $s['points_against'];
            $ballPercent = $totalBalls > 0 ? (int) round($s['points_for'] / $totalBalls * 100) : 0;
            $rows[] = array_merge($s, [
                'position' => $position++,
                'games_played' => $totalGames,
                'point_diff' => $diff,
                'win_percent' => $totalGames > 0 ? (int) round($s['wins'] / $totalGames * 100) : 0,
                'ball_percent' => $ballPercent,
            ]);
        }
        return $rows;
    }

    private function formatAmericanoMatch(AmericanoMatch $m): array
    {
        return [
            'id' => $m->id,
            'court_number' => $m->court_number !== null ? (int) $m->court_number : null,
            'team1' => [
                'players' => array_filter([
                    $this->formatMatchPlayer($m->team1Player1),
                    $this->formatMatchPlayer($m->team1Player2),
                ]),
                'score' => $m->team1_score,
            ],
            'team2' => [
                'players' => array_filter([
                    $this->formatMatchPlayer($m->team2Player1),
                    $this->formatMatchPlayer($m->team2Player2),
                ]),
                'score' => $m->team2_score,
            ],
            'status' => $m->status,
            'winner' => $m->winning_team,
        ];
    }

    private function formatPlayoffMatch(TournamentPlayoffMatch $m): array
    {
        $winner = null;
        if ($m->status === 'completed' &&
            $m->team1_score !== null && $m->team2_score !== null &&
            $m->team1_score !== $m->team2_score) {
            $winner = $m->team1_score > $m->team2_score ? 1 : 2;
        }

        return [
            'id' => $m->id,
            'stage' => $m->stage,
            'court_number' => $m->court_number !== null ? (int) $m->court_number : null,
            'team1' => [
                'players' => array_filter([
                    $this->formatMatchPlayer($m->team1Player1),
                    $this->formatMatchPlayer($m->team1Player2),
                ]),
                'score' => $m->team1_score,
            ],
            'team2' => [
                'players' => array_filter([
                    $this->formatMatchPlayer($m->team2Player1),
                    $this->formatMatchPlayer($m->team2Player2),
                ]),
                'score' => $m->team2_score,
            ],
            'status' => $m->status,
            'winner' => $winner,
        ];
    }

    private function formatMatchPlayer($user): ?array
    {
        if (!$user) return null;
        $name = $user->full_name ?? $user->name;
        return [
            'id' => $user->id,
            'name' => $name,
            'initials' => $this->initials($name),
            'avatar_url' => $user->avatar ? asset('storage/' . $user->avatar) : null,
        ];
    }

    private function initials(?string $name): string
    {
        if (!$name) return '?';
        $parts = preg_split('/\s+/u', trim($name));
        if (empty($parts)) return '?';
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1));
        }
        return mb_strtoupper(
            mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1)
        );
    }

    // -------------------------------------------------------------------------
    // 3d-Americano — генерация плей-офф и завершение турнира
    // -------------------------------------------------------------------------

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/playoff/generate
     */
    public function generatePlayoff(
        Request $request,
        Tournament $tournament,
        AmericanoService $americano
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if (!$tournament->isAmericano()) {
            return $this->error(
                'Генерация плей-офф из мобилы пока поддерживается только для Американо'
            );
        }

        if (!$americano->canGeneratePlayoff($tournament)) {
            return $this->error(
                'Невозможно сгенерировать плей-офф. Не все групповые матчи сыграны.'
            );
        }

        $ok = $americano->generatePlayoff($tournament);
        if (!$ok) {
            return $this->error('Не удалось сгенерировать плей-офф');
        }

        $tournament->refresh();
        return response()->json($this->buildAmericanoMatches($tournament));
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/finish
     */
    public function finish(
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

        if ($tournament->status !== 'in_progress') {
            return $this->error('Можно завершить только идущий турнир');
        }

        if ($tournament->isAmericano()) {
            if (!$americano->canFinishTournament($tournament)) {
                return $this->error(
                    'Не все матчи сыграны (включая плей-офф)'
                );
            }
            $ok = $americano->finishTournament($tournament);
        } elseif ($tournament->isKingOfCourt()) {
            if (!$king->canFinishTournament($tournament)) {
                return $this->error('Доиграйте текущий раунд');
            }
            $ok = $king->finishTournament($tournament);
        } else {
            return $this->error(
                'Завершение из мобилы пока поддерживается только для Американо и Король корта'
            );
        }

        if (!$ok) {
            return $this->error('Не удалось завершить турнир');
        }

        // Триггер #5 верификации — пересчитать level_verified у участников
        $tournament->recalculateParticipantsVerification(
            $request->user()?->id,
            $tournament->club_id
        );

        $tournament->refresh()->loadMissing('club');
        return response()->json([
            'success' => true,
            'tournament' => $this->formatDetail($tournament),
        ]);
    }

    // -------------------------------------------------------------------------
    // KOC — ввод счёта и генерация следующего раунда
    // -------------------------------------------------------------------------

    /**
     * POST/PUT /api/mobile/admin/tournaments/{tournament}/kingofcourt/matches/{match}/score
     * KOC использует один и тот же метод и для save, и для update —
     * KingOfCourtService::saveMatchResult сам откатит старые stat если матч
     * уже completed.
     */
    public function saveKingOfCourtScore(
        Request $request,
        Tournament $tournament,
        KingOfCourtMatch $match,
        KingOfCourtService $service
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $match->loadMissing('round');
        if (!$match->round ||
            (int) $match->round->tournament_id !== (int) $tournament->id) {
            return $this->error('Матч не принадлежит этому турниру', 404);
        }

        $validator = Validator::make($request->all(), [
            'team1_score' => 'required|integer|min:0|max:99',
            'team2_score' => 'required|integer|min:0|max:99|different:team1_score',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        $service->saveMatchResult(
            $match,
            (int) $request->input('team1_score'),
            (int) $request->input('team2_score'),
        );

        $match->refresh();
        $winner = null;
        if ($match->team1_score !== null && $match->team2_score !== null) {
            $winner = $match->team1_score > $match->team2_score ? 1 : 2;
        }

        return response()->json([
            'success' => true,
            'match' => [
                'id' => $match->id,
                'team1_score' => $match->team1_score,
                'team2_score' => $match->team2_score,
                'status' => $match->status,
                'winner' => $winner,
            ],
        ]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/next-round
     * Сейчас работает только для KOC (Mexicano добавим в 3c-3).
     */
    public function nextRound(
        Request $request,
        Tournament $tournament,
        KingOfCourtService $king
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if (!$tournament->isKingOfCourt()) {
            return $this->error(
                'Генерация следующего раунда из мобилы пока поддерживается только для Король корта'
            );
        }

        if (!$king->canGenerateNextRound($tournament)) {
            return $this->error('Текущий раунд ещё не завершён');
        }

        $ok = $king->generateNextRound($tournament);
        if (!$ok) {
            return $this->error('Не удалось сгенерировать следующий раунд');
        }

        $tournament->refresh();
        return response()->json($this->buildKingOfCourtMatches($tournament));
    }

    // -------------------------------------------------------------------------
    // KOC — формирование ответа /matches
    // -------------------------------------------------------------------------

    private function buildKingOfCourtMatches(Tournament $tournament): array
    {
        $tournament->load([
            'kingOfCourtRounds.matches.team1Player1',
            'kingOfCourtRounds.matches.team1Player2',
            'kingOfCourtRounds.matches.team2Player1',
            'kingOfCourtRounds.matches.team2Player2',
            'kingOfCourtPlayers.user',
        ]);

        $matchesTotal = 0;
        $matchesPlayed = 0;

        $rounds = $tournament->kingOfCourtRounds
            ->sortBy('round_number')
            ->values()
            ->map(function ($round) use (&$matchesTotal, &$matchesPlayed) {
                $matches = $round->matches->map(function ($m) use (&$matchesTotal, &$matchesPlayed) {
                    $matchesTotal++;
                    if ($m->status === 'completed') {
                        $matchesPlayed++;
                    }
                    return $this->formatKingOfCourtMatch($m);
                });

                return [
                    'id' => $round->id,
                    'round_number' => (int) $round->round_number,
                    'status' => $round->status,
                    'matches' => $matches,
                ];
            });

        $leaderboard = $this->buildKingOfCourtLeaderboard($tournament);

        // Заворачиваем в одну виртуальную «группу» — это даёт фронту
        // переиспользовать готовый рендер «группа → раунды → таблица».
        $virtualGroup = [
            'id' => 0,
            'name' => '',
            'rounds' => $rounds,
            'leaderboard' => $leaderboard,
        ];

        $isLive = $tournament->status === 'in_progress';
        $king = app(KingOfCourtService::class);

        return [
            'success' => true,
            'type' => 'king_of_court',
            'groups' => [$virtualGroup],
            'playoff' => null,
            'summary' => [
                'matches_total' => $matchesTotal,
                'matches_played' => $matchesPlayed,
                'all_group_matches_played' => $matchesTotal > 0 && $matchesTotal === $matchesPlayed,
                'can_finish' => $isLive && $king->canFinishTournament($tournament),
                'can_generate_playoff' => false,
                'can_generate_next_round' => $isLive && $king->canGenerateNextRound($tournament),
            ],
        ];
    }

    private function formatKingOfCourtMatch(KingOfCourtMatch $m): array
    {
        return [
            'id' => $m->id,
            'court_number' => $m->court_number !== null ? (int) $m->court_number : null,
            'team1' => [
                'players' => array_filter([
                    $this->formatMatchPlayer($m->team1Player1),
                    $this->formatMatchPlayer($m->team1Player2),
                ]),
                'score' => $m->team1_score,
            ],
            'team2' => [
                'players' => array_filter([
                    $this->formatMatchPlayer($m->team2Player1),
                    $this->formatMatchPlayer($m->team2Player2),
                ]),
                'score' => $m->team2_score,
            ],
            'status' => $m->status,
            'winner' => $this->kingOfCourtMatchWinner($m),
        ];
    }

    private function kingOfCourtMatchWinner(KingOfCourtMatch $m): ?int
    {
        if ($m->status !== 'completed') return null;
        if ($m->team1_score === null || $m->team2_score === null) return null;
        if ($m->team1_score === $m->team2_score) return null;
        return $m->team1_score > $m->team2_score ? 1 : 2;
    }

    private function buildKingOfCourtLeaderboard(Tournament $tournament): array
    {
        $players = $tournament->kingOfCourtPlayers
            ->sortByDesc('total_points')
            ->values();

        $rows = [];
        $position = 1;
        foreach ($players as $kp) {
            $u = $kp->user;
            if (!$u) continue;
            $totalGames = (int) $kp->wins + (int) $kp->losses;
            $totalBalls = (int) $kp->points_for + (int) $kp->points_against;
            $rows[] = [
                'position' => $position++,
                'id' => $u->id,
                'name' => $u->full_name ?? $u->name,
                'avatar' => $u->avatar ? asset('storage/' . $u->avatar) : null,
                'rating' => (int) ($u->rating ?? 0),
                'wins' => (int) $kp->wins,
                'losses' => (int) $kp->losses,
                'draws' => 0,
                'points_for' => (int) $kp->points_for,
                'points_against' => (int) $kp->points_against,
                'total_points' => (int) $kp->total_points,
                'games_played' => $totalGames,
                'point_diff' => (int) $kp->points_for - (int) $kp->points_against,
                'win_percent' => $totalGames > 0 ? (int) round($kp->wins / $totalGames * 100) : 0,
                'ball_percent' => $totalBalls > 0 ? (int) round($kp->points_for / $totalBalls * 100) : 0,
            ];
        }
        return $rows;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AmericanoFlexMatch;
use App\Models\AmericanoMatch;
use App\Models\BaliKocMatch;
use App\Models\BaliKocPair;
use App\Models\JustPadelItMatch;
use App\Models\KingOfCourtMatch;
use App\Models\RoundRobinMatch;
use App\Models\Tournament;
use App\Models\TournamentGroupMatch;
use App\Models\TournamentPlayoffMatch;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\AmericanoFlexService;
use App\Services\AmericanoService;
use App\Services\BaliKocService;
use App\Services\JustPadelItService;
use App\Services\KingOfCourtService;
use App\Services\MexicanoService;
use App\Services\RoundRobinService;
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
    /** Максимум приглашений на один турнир (анти-спам). */
    private const MAX_INVITATIONS = 10;

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
            'moderation_hours' => 'nullable|integer|min:0|max:720',
            'moderation_minutes' => 'nullable|integer|min:0|max:1440',
            'verified_only' => 'nullable|boolean',
            // Можно перевести черновик в «Открыта регистрация» и обратно.
            'status' => 'nullable|in:draft,open',
            // Кто собирает пары (командный турнир): сами игроки / админ.
            'pairing_mode' => 'nullable|in:self,admin',
            // Настройки плей-офф (командный турнир).
            'has_playoff' => 'nullable|boolean',
            'has_lower_bracket' => 'nullable|boolean',
            'has_bronze_match' => 'nullable|boolean',
            'courts_count' => 'nullable|integer|min:1|max:32',
            // Клуб-площадка (где играем) — необязательный.
            'venue_club_id' => 'nullable|exists:clubs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // verified_only: меняем только если поле прислано (старые версии приложения не трогают).
        if ($request->has('verified_only')) {
            $validated['verified_only'] = $request->boolean('verified_only');
        } else {
            unset($validated['verified_only']);
        }

        // status: меняем только если прислан (старые версии не трогают статус).
        if (!$request->filled('status')) {
            unset($validated['status']);
        }

        // pairing_mode: меняем только если прислан (старые сборки не трогают).
        if (!$request->filled('pairing_mode')) {
            unset($validated['pairing_mode']);
        }

        // Плей-офф (командный турнир) — меняем только если присланы флаги.
        // Нормализация как при создании: has_playoff включается, если отмечены
        // нижняя сетка или матч за 3-е место; при выключенном плей-офф всё сбрасываем.
        if ($request->has('has_playoff')
            || $request->has('has_lower_bracket')
            || $request->has('has_bronze_match')) {
            $hasLower = $request->boolean('has_lower_bracket');
            $hasBronze = $request->boolean('has_bronze_match');
            $hasPlayoff = $request->boolean('has_playoff') || $hasLower || $hasBronze;
            $validated['has_playoff'] = $hasPlayoff;
            $validated['has_lower_bracket'] = $hasPlayoff ? $hasLower : false;
            $validated['has_bronze_match'] = $hasPlayoff ? $hasBronze : false;
            if (!$hasPlayoff) {
                $validated['playoff_type'] = null;
                $validated['playoff_format'] = null;
            }
        } else {
            unset($validated['has_playoff'], $validated['has_lower_bracket'], $validated['has_bronze_match']);
        }

        // courts_count — меняем только если прислан.
        if ($request->filled('courts_count')) {
            $validated['courts_count'] = (int) $request->input('courts_count');
        } else {
            unset($validated['courts_count']);
        }

        // venue_club_id — меняем только если прислан (в т.ч. null для очистки).
        if (!$request->has('venue_club_id')) {
            unset($validated['venue_club_id']);
        }

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
        KingOfCourtService $king,
        BaliKocService $bali,
        AmericanoFlexService $flex,
        RoundRobinService $roundRobin,
        \App\Services\JustPadelItService $jpi
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
        } elseif ($tournament->isAmericanoFlex()) {
            $ok = $flex->startTournament($tournament);
        } elseif ($tournament->isMexicano()) {
            $ok = $mexicano->startTournament($tournament);
        } elseif ($tournament->isTeamBased()) {
            $ok = $team->startTournament($tournament);
        } elseif ($tournament->isKingOfCourt()) {
            if ($tournament->isPairedKingOfCourt() && !$king->arePairsCreated($tournament)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Сначала создайте пары',
                    'pairs_required' => true,
                ], 422);
            }
            $ok = $king->startTournament($tournament);
        } elseif ($tournament->isRoundRobin()) {
            $ok = $roundRobin->startTournament($tournament);
        } elseif ($tournament->isBaliKoc()) {
            if (!$bali->arePairsCreated($tournament)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Сначала создайте пары',
                    'pairs_required' => true,
                ], 422);
            }
            $ok = $bali->startTournament($tournament);
        } elseif ($tournament->isJustPadelIt()) {
            if ($tournament->isPairedJustPadelIt() && !$jpi->arePairsCreated($tournament)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Сначала создайте пары',
                    'pairs_required' => true,
                ], 422);
            }
            $order = $request->input('order');
            $order = is_array($order) ? array_map('intval', $order) : null;
            $ok = $jpi->startTournament($tournament, $order ?: null);
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

    // -------------------------------------------------------------------------
    // Ручной сбор пар (групповой турнир, pairing_mode=admin)
    // -------------------------------------------------------------------------

    /**
     * GET /api/mobile/admin/tournaments/{tournament}/pairing
     */
    public function pairingState(Request $request, Tournament $tournament, TeamTournamentService $team): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        if (!$tournament->isAdminPairing()) {
            return response()->json(['success' => false, 'message' => 'Сбор пар недоступен для этого турнира'], 422);
        }
        return response()->json(['success' => true, 'pairing' => $team->getPairingState($tournament)]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/pairing/teams
     */
    public function createPairing(Request $request, Tournament $tournament, TeamTournamentService $team): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        $data = $request->validate([
            'player1_id' => 'required|integer',
            'player2_id' => 'required|integer',
        ]);
        [$ok, $msg] = $team->createPair($tournament, (int) $data['player1_id'], (int) $data['player2_id']);
        if (!$ok) {
            return response()->json(['success' => false, 'message' => $msg], 422);
        }
        return response()->json(['success' => true, 'message' => $msg, 'pairing' => $team->getPairingState($tournament)]);
    }

    /**
     * DELETE /api/mobile/admin/tournaments/{tournament}/pairing/teams/{pair}
     */
    public function deletePairing(Request $request, Tournament $tournament, TournamentTeam $pair, TeamTournamentService $team): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        [$ok, $msg] = $team->deletePair($tournament, $pair);
        if (!$ok) {
            return response()->json(['success' => false, 'message' => $msg], 422);
        }
        return response()->json(['success' => true, 'message' => $msg, 'pairing' => $team->getPairingState($tournament)]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/pairing/auto
     */
    public function autoPairing(Request $request, Tournament $tournament, TeamTournamentService $team): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        [$ok, $msg] = $team->autoBalancePairs($tournament);
        if (!$ok) {
            return response()->json(['success' => false, 'message' => $msg], 422);
        }
        return response()->json(['success' => true, 'message' => $msg, 'pairing' => $team->getPairingState($tournament)]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/restart
     */
    public function restart(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if (!$tournament->canRestart()) {
            return response()->json([
                'success' => false,
                'message' => 'Перезапуск недоступен: турнир не запущен или первый раунд уже сыгран',
            ], 422);
        }

        app(\App\Services\TournamentResetService::class)->reset($tournament);

        $tournament->refresh()->loadMissing('club');

        return response()->json([
            'success' => true,
            'tournament' => $this->formatDetail($tournament),
        ]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/cancel
     * Остановить турнир — перевести в статус «cancelled». Аналог отмены на вебе.
     */
    public function cancelTournament(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if (in_array($tournament->status, ['completed', 'cancelled'], true)) {
            return $this->error('Турнир уже завершён или отменён');
        }

        $tournament->update(['status' => 'cancelled']);
        $tournament->refresh()->loadMissing('club');

        // Оповещение участников об отмене шлёт observer модели Tournament
        // (static::updated → notifyParticipantsTournamentCancelled).

        return response()->json([
            'success' => true,
            'tournament' => $this->formatDetail($tournament),
        ]);
    }

    /**
     * DELETE /api/mobile/admin/tournaments/{tournament}
     */
    /**
     * Дублировать турнир: создать копию-черновик с теми же настройками и
     * описанием, но без участников/пар/матчей/результатов — на дубль можно
     * записываться заново. Дата начала очищается, её задают при подготовке.
     */
    public function duplicate(Request $request, Tournament $tournament): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageTournament($user, $tournament)) {
            return $this->forbidden();
        }
        if (!$this->hasTournamentsFullAccess($user, $tournament)) {
            return $this->noPermission('Нет прав на создание турниров');
        }

        $copy = $tournament->replicate([
            // Per-instance / сгенерированные данные — не копируем.
            'round_robin_schedule',
        ]);
        $copy->name = $tournament->name . ' (копия)';
        $copy->status = 'draft';
        $copy->start_date = null;
        $copy->registration_deadline = null;
        $copy->round_robin_schedule = null;
        // Личный турнир остаётся за тем же создателем; клубный — за тем же клубом.
        $copy->save();

        return response()->json([
            'success' => true,
            'tournament' => $this->formatDetail($copy, $user),
        ]);
    }

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
        // Создатель личного турнира управляет им сам.
        if ($tournament->creator_id && (int) $tournament->creator_id === (int) $user->id) {
            return true;
        }
        $clubId = $tournament->club_id;
        if (!$clubId) return false;
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
        // Создатель личного турнира имеет полные права на него.
        if ($tournament->creator_id && (int) $tournament->creator_id === (int) $user->id) {
            return true;
        }
        $club = $tournament->club
            ?? ($tournament->club_id ? \App\Models\Club::find($tournament->club_id) : null);
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
        // Solo Just Padel It — посев только когда игроков ровно кортов × 4.
        if ($t->type === 'just_padel_it' && ! $t->is_paired) {
            $canStart = $t->status === 'open' && $t->jpiSeedingReady();
        }
        // Для Bali KOC: чтобы стартануть, пары должны быть созданы.
        $baliPairsCreated = $t->isBaliKoc()
            ? $t->baliKocPairs()->exists()
            : false;
        // Для фикс-парного Короля корта — аналогично.
        $kocPairsCreated = $t->isPairedKingOfCourt()
            ? $t->kingOfCourtPairs()->exists()
            : false;
        // Для фикс-парного Just Padel It — аналогично.
        $jpiPairsCreated = $t->isPairedJustPadelIt()
            ? $t->justPadelItPairs()->exists()
            : false;
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
            'pairing_mode' => $t->pairing_mode ?? 'self',
            'is_admin_pairing' => $t->isAdminPairing(),
            'club' => $t->club ? [
                'id' => $t->club->id,
                'name' => $t->club->name,
            ] : null,
            'is_personal' => $t->isPersonal(),
            'creator' => $t->creator ? [
                'id' => $t->creator->id,
                'name' => $t->creator->name,
            ] : null,
            'start_date' => $t->start_date?->toIso8601String(),
            'min_level' => (float) $t->min_level,
            'max_level' => (float) $t->max_level,
            'max_participants' => (int) $t->max_participants,
            'participants_count' => $taken,
            'pending_count' => $this->getPendingCount($t),
            'price' => $t->price !== null ? (float) $t->price : null,
            'verified_only' => (bool) $t->verified_only,
            'telegram_registration_url' => $t->telegram_registration_url,
            'has_playoff' => (bool) $t->has_playoff,
            'has_lower_bracket' => (bool) $t->has_lower_bracket,
            'has_bronze_match' => (bool) $t->has_bronze_match,
            'courts_count' => $t->courts_count !== null ? (int) $t->courts_count : null,
            'courts' => $t->courts ?? [],
            'can_edit' => $canEdit,
            'can_start' => $canStart,
            'can_restart' => $t->canRestart(),
            'can_delete' => $canDelete,
            'bali_pairs_created' => $baliPairsCreated,
            'koc_pairs_created' => $kocPairsCreated,
            'jpi_pairs_created' => $jpiPairsCreated,
            'is_paired' => (bool) $t->is_paired,
            'moderation_hours' => $t->moderation_hours !== null ? (int) $t->moderation_hours : null,
            'moderation_minutes' => $t->moderation_minutes !== null ? (int) $t->moderation_minutes : null,
            'tournaments_full_access' => $hasFullAccess,
            'chat_enabled' => (bool) $t->chat_enabled,
            'chat_write_mode' => $t->chat_write_mode ?? 'participants',
        ];
    }

    private function getParticipantsCount(Tournament $t): int
    {
        if (!$t->usesSoloRegistration()) {
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
        if (!$t->usesSoloRegistration()) {
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

        if (!$tournament->usesSoloRegistration()) {
            $teams = $tournament->teams()
                ->with(['player1', 'player2'])
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
                ->orderBy('created_at') // внутри статуса — FIFO (лист ожидания по очереди)
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
            ->withPivot(['status', 'created_at', 'moderation_deadline'])
            ->orderByRaw("CASE tournament_participants.status WHEN 'pending' THEN 0 WHEN 'registered' THEN 1 ELSE 2 END")
            ->orderBy('tournament_participants.created_at') // внутри статуса — FIFO (лист ожидания по очереди)
            ->get()
            ->map(function ($u) {
                $arr = $this->formatUser($u);
                $arr['status'] = $u->pivot->status;
                $arr['registered_at'] = $u->pivot->created_at
                    ? $u->pivot->created_at->toIso8601String() : null;
                $arr['moderation_deadline'] = $u->pivot->moderation_deadline
                    ? \Carbon\Carbon::parse($u->pivot->moderation_deadline)->toIso8601String() : null;
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
     * GET /api/mobile/admin/tournaments/{tournament}/registration-journal
     * Журнал записей: кто записался / кто отписался (события самого игрока),
     * с аватаром и телефоном. Разделено по вкладкам.
     */
    public function registrationJournal(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $logs = \App\Models\TournamentRegistrationLog::where('tournament_id', $tournament->id)
            ->with('user:id,name,phone,avatar,level,level_verified,rating')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $map = function ($log) {
            $u = $log->user;
            if (!$u) return null;
            return [
                'id' => $log->id,
                'action' => $log->action,
                'created_at' => $log->created_at?->toIso8601String(),
                'user' => $this->formatUser($u),
            ];
        };

        $registered = $logs->where('action', 'registered')->map($map)->filter()->values();
        $unregistered = $logs->where('action', 'unregistered')->map($map)->filter()->values();

        return response()->json([
            'success' => true,
            'registered' => $registered,
            'unregistered' => $unregistered,
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

            $tournament->participants()->updateExistingPivot($user->id, [
                'status' => 'registered',
                'moderation_deadline' => null,
                'reminder_sent_at' => null,
            ]);
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

        $row = $tournament->participants()->where('user_id', $user->id)->first();
        $wasMain = $row ? in_array($row->pivot->status, ['registered', 'pending'], true) : false;

        $tournament->participants()->detach($user->id);
        $this->notifyRejected($tournament, $user);

        if ($wasMain && $tournament->status === 'open') {
            \App\Http\Controllers\Api\MobileTournamentController::promoteNextFromWaitlist($tournament);
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/participants/{user}/move
     * Универсальное перемещение между статусами: registered | pending | waiting.
     * Тело: { to: 'registered'|'pending'|'waiting' }.
     */
    public function moveParticipant(Request $request, Tournament $tournament, User $user): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        if ($tournament->isAmericano() && $tournament->groups()->count() > 0) {
            return $this->error('Группы уже сформированы. Используйте редактор групп в Web.');
        }

        $to = $request->input('to');
        if (!in_array($to, ['registered', 'pending', 'waiting'], true)) {
            return $this->error('Неверный статус');
        }

        $row = $tournament->participants()->where('user_id', $user->id)->first();
        if (!$row) {
            return $this->error('Участник не найден', 404);
        }
        if ($row->pivot->status === $to) {
            return response()->json(['success' => true]);
        }

        // Вход в основной список из листа ожидания — не превышаем лимит.
        // Считаем ОБА статуса (утверждённые + на модерации), как при записи,
        // иначе можно переполнить сверх max_participants.
        $entersMain = $row->pivot->status === 'waiting'
            && in_array($to, ['registered', 'pending'], true);
        if ($entersMain) {
            $taken = $tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending'])
                ->count();
            if ($taken >= $tournament->max_participants) {
                return $this->error('Достигнут лимит участников');
            }
        }

        $tournament->participants()->updateExistingPivot($user->id, [
            'status' => $to,
            'moderation_deadline' => $to === 'pending' ? $tournament->moderationDeadline() : null,
            'reminder_sent_at' => null,
        ]);

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

        $row = $tournament->participants()->where('user_id', $user->id)->first();
        $wasMain = $row ? in_array($row->pivot->status, ['registered', 'pending'], true) : false;

        $tournament->participants()->detach($user->id);

        if ($wasMain && $tournament->status === 'open') {
            \App\Http\Controllers\Api\MobileTournamentController::promoteNextFromWaitlist($tournament);
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/participants/{user}/to-waitlist
     * Переместить участника в конец листа ожидания.
     */
    public function moveToWaitlist(Request $request, Tournament $tournament, User $user): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ($tournament->isAmericano() && $tournament->groups()->count() > 0) {
            return $this->error('Группы уже сформированы. Используйте редактор групп в Web.');
        }

        $row = $tournament->participants()->where('user_id', $user->id)->first();
        if (!$row) {
            return $this->error('Участник не найден', 404);
        }
        if ($row->pivot->status === 'waiting') {
            return $this->error('Игрок уже в листе ожидания');
        }

        // Кого из листа ожидания поднять на освободившееся место (выбирает админ).
        // Если не передан — просто переносим, никого не продвигаем.
        $promoteId = $request->input('promote_user_id');

        $promoted = \Illuminate\Support\Facades\DB::transaction(function () use ($tournament, $user, $promoteId) {
            // Перемещаемого — в конец листа ожидания, таймер снят.
            $tournament->participants()->updateExistingPivot($user->id, [
                'status' => 'waiting',
                'created_at' => now(),
                'moderation_deadline' => null,
                'reminder_sent_at' => null,
            ]);

            if (!$promoteId || (int) $promoteId === $user->id) return null;

            // Выбранный должен сейчас быть в листе ожидания.
            $target = $tournament->participants()
                ->wherePivot('status', 'waiting')
                ->where('users.id', (int) $promoteId)
                ->first();
            if (!$target) return null;

            // Выбранный занимает слот — на модерацию (со свежим таймером).
            $deadline = $tournament->moderationDeadline();
            $tournament->participants()->updateExistingPivot($target->id, [
                'status' => 'pending',
                'moderation_deadline' => $deadline,
                'reminder_sent_at' => null,
            ]);

            return ['user' => $target, 'deadline' => $deadline];
        });

        // Уведомление продвинутому (вне транзакции).
        if ($promoted) {
            $u = $promoted['user'];
            if ($promoted['deadline']) {
                app(\App\Services\ModerationNotifier::class)->pending($u, $tournament, $promoted['deadline']);
            } else {
                $title = 'Место освободилось!';
                $body = "Вы перешли из листа ожидания на турнир «{$tournament->name}». Заявка на модерации.";
                \App\Models\Notification::create([
                    'user_id' => $u->id, 'title' => $title, 'body' => $body,
                    'type' => 'waitlist_promoted', 'category' => 'tournament',
                    'data' => ['tournament_id' => $tournament->id],
                ]);
                try {
                    app(\App\Services\FCMNotificationService::class)->sendToUser($u, $title, $body, [
                        'type' => 'tournament', 'tournament_id' => (string) $tournament->id,
                    ]);
                } catch (\Throwable $e) {
                    // пуш не критичен
                }
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/participants/{user}/to-moderation
     * Поднять игрока из листа ожидания на модерацию. Если турнир заполнен —
     * нужно указать demote_user_id (кого отправить в лист ожидания вместо него).
     */
    public function moveToModeration(Request $request, Tournament $tournament, User $user): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        if ($tournament->isAmericano() && $tournament->groups()->count() > 0) {
            return $this->error('Группы уже сформированы. Используйте редактор групп в Web.');
        }

        $row = $tournament->participants()->where('user_id', $user->id)->first();
        if (!$row) {
            return $this->error('Участник не найден', 404);
        }
        if ($row->pivot->status !== 'waiting') {
            return $this->error('Игрок не в листе ожидания');
        }

        $demoteId = $request->input('demote_user_id');

        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($tournament, $user, $demoteId) {
            $taken = $tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending'])
                ->count();
            $hasFreeSlot = $taken < $tournament->max_participants;

            if (!$hasFreeSlot) {
                // Полный турнир — заменить можно ТОЛЬКО игрока на модерации (pending).
                $pendingCount = $tournament->participants()
                    ->wherePivot('status', 'pending')
                    ->count();
                if ($pendingCount === 0) return 'full_confirmed';

                if (!$demoteId || (int) $demoteId === $user->id) return 'needs_choice';
                $target = $tournament->participants()
                    ->wherePivot('status', 'pending')
                    ->where('users.id', (int) $demoteId)
                    ->first();
                if (!$target) return 'invalid_demote';

                // выбранного — в конец листа ожидания
                $tournament->participants()->updateExistingPivot($target->id, [
                    'status' => 'waiting',
                    'created_at' => now(),
                    'moderation_deadline' => null,
                    'reminder_sent_at' => null,
                ]);
            }

            // игрока из листа — на модерацию (со свежим таймером)
            $deadline = $tournament->moderationDeadline();
            $tournament->participants()->updateExistingPivot($user->id, [
                'status' => 'pending',
                'moderation_deadline' => $deadline,
                'reminder_sent_at' => null,
            ]);

            return ['deadline' => $deadline];
        });

        if ($result === 'full_confirmed') {
            return $this->error('Турнир заполнен — все места подтверждены, поднять некого');
        }
        if ($result === 'needs_choice') {
            return $this->error('Турнир заполнен — выберите, кого с модерации отправить в лист ожидания');
        }
        if ($result === 'invalid_demote') {
            return $this->error('Заменить можно только игрока на модерации');
        }

        if (($result['deadline'] ?? null)) {
            app(\App\Services\ModerationNotifier::class)->pending($user, $tournament, $result['deadline']);
        }

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
     * POST /api/mobile/admin/tournaments/{tournament}/invite
     * body: user_id — пригласить игрока (пуш + запись в tournament_invitations).
     */
    public function invite(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ($tournament->type === 'team') {
            return $this->error('Приглашения доступны только для индивидуальных турниров');
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }
        $userId = (int) $validator->validated()['user_id'];

        if ($tournament->participants()->where('user_id', $userId)->exists()) {
            return $this->error('Игрок уже участвует в турнире');
        }

        // Лимит приглашений на турнир (анти-спам). Повторное приглашение уже
        // приглашённого игрока не считается новым (обновляет существующее).
        $alreadyInvited = \App\Models\TournamentInvitation::where('tournament_id', $tournament->id)
            ->where('user_id', $userId)
            ->exists();
        if (!$alreadyInvited) {
            $invitesCount = \App\Models\TournamentInvitation::where('tournament_id', $tournament->id)->count();
            if ($invitesCount >= self::MAX_INVITATIONS) {
                return $this->error('Можно отправить не более ' . self::MAX_INVITATIONS . ' приглашений на турнир');
            }
        }

        $invitation = \App\Models\TournamentInvitation::updateOrCreate(
            ['tournament_id' => $tournament->id, 'user_id' => $userId],
            ['invited_by' => $request->user()->id, 'status' => 'pending', 'responded_at' => null],
        );

        $player = User::find($userId);
        if ($player) {
            $title = 'Приглашение на турнир';
            $body = "Вас пригласили на турнир «{$tournament->name}»";
            \App\Models\Notification::create([
                'user_id' => $player->id,
                'title' => $title,
                'body' => $body,
                'type' => 'tournament_invite',
                'category' => 'tournament',
                'data' => [
                    'tournament_id' => $tournament->id,
                    'invitation_id' => $invitation->id,
                ],
            ]);
            try {
                app(\App\Services\FCMNotificationService::class)->sendToUser($player, $title, $body, [
                    'type' => 'tournament_invite',
                    'tournament_id' => (string) $tournament->id,
                    'invitation_id' => (string) $invitation->id,
                ]);
            } catch (\Throwable $e) {
                // пуш не критичен — приглашение уже сохранено
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/mobile/admin/tournaments/{tournament}/invitations
     * Список приглашённых игроков (все статусы).
     */
    public function invitations(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $invitations = \App\Models\TournamentInvitation::where('tournament_id', $tournament->id)
            ->with('user:id,name,phone,level,rating,avatar')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn($inv) => $inv->user)
            ->map(fn($inv) => [
                'id' => $inv->id,
                'status' => $inv->status,
                'created_at' => $inv->created_at?->toIso8601String(),
                'player' => $this->formatUser($inv->user),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'invitations' => $invitations,
        ]);
    }

    /**
     * DELETE /api/mobile/admin/tournaments/{tournament}/invitations/{invitation}
     * Убрать игрока из списка приглашённых.
     */
    public function cancelInvitation(Request $request, Tournament $tournament, \App\Models\TournamentInvitation $invitation): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        if ($invitation->tournament_id !== $tournament->id) {
            return $this->error('Приглашение не найдено', 404);
        }

        $invitation->delete();
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
                if (ctype_digit($q)) {
                    $qq->orWhere('id', (int) $q);
                }
            })
            ->whereNotIn('id', $excluded)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone', 'level', 'rating', 'avatar', 'level_verified'])
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

        $team->update(['status' => 'approved', 'moderation_deadline' => null, 'reminder_sent_at' => null]);
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
        $wasMain = in_array($team->status, ['approved', 'pending'], true);
        $team->delete();

        if ($wasMain && $tournament->status === 'open') {
            \App\Http\Controllers\Api\MobileTournamentController::promoteNextTeamFromWaitlist($tournament);
        }
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
        $wasMain = in_array($team->status, ['approved', 'pending'], true);
        $team->delete();

        if ($wasMain) {
            \App\Http\Controllers\Api\MobileTournamentController::promoteNextTeamFromWaitlist($tournament);
        }
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
            'avatar_url' => $u->avatar, // в БД уже хранится готовый URL (как в рейтинге)
            'level_verified' => (bool) $u->level_verified,
        ];
    }

    private function error(string $message, int $code = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $code);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/send-push
     * Push «Новый турнир!» всем подходящим пользователям (как колокольчик в вебе).
     */
    public function sendPush(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        // Личные турниры игроков не рассылают пуши всем пользователям.
        if ($tournament->isPersonal()) {
            return $this->error('Личные турниры не отправляют уведомления');
        }

        if ($tournament->status !== 'open') {
            return $this->error('Уведомление можно отправить только для открытого турнира');
        }

        $tournament->loadMissing('club');
        if ($tournament->club && $tournament->club->is_test) {
            return $this->error('Уведомление не отправляется для тестовых клубов');
        }

        $result = app(\App\Services\TournamentPushService::class)->send($tournament);

        return response()->json([
            'success' => true,
            'message' => "Уведомление отправлено: {$result['sent']} из {$result['total']} пользователей"
                . ($result['filtered'] ? ", {$result['filtered']} отфильтровано по настройкам" : ''),
            'sent' => $result['sent'],
            'total' => $result['total'],
            'filtered' => $result['filtered'],
        ]);
    }

    private function notifyApproved(Tournament $tournament, User $user): void
    {
        try {
            $service = new \App\Services\TelegramNotificationService($tournament->club);
            $service->notifyRegistrationApproved($user, $tournament);

            $date = $tournament->start_date?->format('d.m.Y H:i') ?? 'дата уточняется';
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

        if ($tournament->isJustPadelIt()) {
            return response()->json($this->buildJustPadelItMatches($tournament));
        }

        if ($tournament->isRoundRobin()) {
            return response()->json($this->buildRoundRobinMatches($tournament));
        }

        if ($tournament->isAmericanoFlex()) {
            return response()->json($this->buildAmericanoFlexMatches($tournament));
        }

        if ($tournament->isBaliKoc()) {
            return response()->json($this->buildBaliKocMatches($tournament));
        }

        if ($tournament->isTeamBased()) {
            return response()->json($this->buildTeamMatches($tournament));
        }

        // Mexicano — пока заглушка
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

        $h2h = \App\Support\AmericanoTie::fromGroups([$group]);
        uasort($stats, function ($a, $b) use ($h2h) {
            if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
            if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
            $diffA = $a['points_for'] - $a['points_against'];
            $diffB = $b['points_for'] - $b['points_against'];
            if ($diffA !== $diffB) return $diffB <=> $diffA;
            return \App\Support\AmericanoTie::compare($h2h, $a['id'], $b['id']);
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

        $stage = $m->stage;
        if ($m->bracket === 'lower') {
            $stage .= ' (нижняя сетка)';
        }

        return [
            'id' => $m->id,
            'stage' => $stage,
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
        KingOfCourtService $king,
        JustPadelItService $jpi,
        BaliKocService $bali,
        AmericanoFlexService $flex,
        RoundRobinService $roundRobin
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
        } elseif ($tournament->isAmericanoFlex()) {
            if (!$flex->canFinishTournament($tournament)) {
                return $this->error('Доиграйте текущий раунд');
            }
            $ok = $flex->completeTournament($tournament);
        } elseif ($tournament->isKingOfCourt()) {
            if (!$king->canFinishTournament($tournament)) {
                return $this->error('Доиграйте текущий раунд');
            }
            $ok = $king->finishTournament($tournament);
        } elseif ($tournament->isJustPadelIt()) {
            if (!$jpi->canFinishTournament($tournament)) {
                return $this->error('Доиграйте текущий раунд');
            }
            $ok = $jpi->finishTournament($tournament);
        } elseif ($tournament->isRoundRobin()) {
            if (!$roundRobin->canFinishTournament($tournament)) {
                return $this->error('Доиграйте текущий раунд');
            }
            $ok = $roundRobin->finishTournament($tournament);
        } elseif ($tournament->isBaliKoc()) {
            if (!$bali->canFinishTournament($tournament)) {
                return $this->error('Доиграйте текущий раунд');
            }
            $ok = $bali->finishTournament($tournament);
        } elseif ($tournament->isTeamBased()) {
            if (!$team->canFinishTournament($tournament)) {
                return $this->error('Финал ещё не сыгран');
            }
            $ok = $team->finishTournament($tournament);
        } else {
            return $this->error(
                'Завершение из мобилы пока поддерживается только для Американо/KOC/Bali/Group'
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

    public function saveJustPadelItScore(
        Request $request,
        Tournament $tournament,
        \App\Models\JustPadelItMatch $match,
        \App\Services\JustPadelItService $service
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
        KingOfCourtService $king,
        JustPadelItService $jpi,
        BaliKocService $bali,
        AmericanoFlexService $flex,
        RoundRobinService $roundRobin
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if ($tournament->isRoundRobin()) {
            if (!$roundRobin->canGenerateNextRound($tournament)) {
                return $this->error('Текущий раунд ещё не завершён');
            }
            $ok = $roundRobin->generateNextRound($tournament);
            if (!$ok) {
                return $this->error('Не удалось сгенерировать следующий раунд');
            }

            // Персональные пуши участникам (как и из веб-админки)
            $newRoundNumber = (int) $tournament->roundRobinRounds()->max('round_number');
            $tournamentId = $tournament->id;
            $tournamentName = $tournament->name;
            app()->terminating(function () use ($tournamentId, $tournamentName, $newRoundNumber) {
                \App\Http\Controllers\Club\RoundRobinController::notifyRoundRobinRoundGenerated(
                    $tournamentId, $tournamentName, $newRoundNumber
                );
            });

            $tournament->refresh();
            return response()->json($this->buildRoundRobinMatches($tournament));
        }

        if ($tournament->isAmericanoFlex()) {
            if (!$flex->canGenerateNextRound($tournament)) {
                return $this->error('Текущий раунд ещё не завершён');
            }
            $flex->generateNextRound($tournament);
            $tournament->refresh();
            return response()->json($this->buildAmericanoFlexMatches($tournament));
        }

        if ($tournament->isKingOfCourt()) {
            if (!$king->canGenerateNextRound($tournament)) {
                return $this->error('Текущий раунд ещё не завершён');
            }
            $ok = $king->generateNextRound($tournament);
            if (!$ok) {
                return $this->error('Не удалось сгенерировать следующий раунд');
            }

            // Персональные пуши участникам (как и из веб-админки)
            $newRoundNumber = (int) $tournament->kingOfCourtRounds()->max('round_number');
            $tournamentId = $tournament->id;
            $tournamentName = $tournament->name;
            app()->terminating(function () use ($tournamentId, $tournamentName, $newRoundNumber) {
                \App\Http\Controllers\Club\KingOfCourtController::notifyKocRoundGenerated(
                    $tournamentId, $tournamentName, $newRoundNumber
                );
            });

            $tournament->refresh();
            return response()->json($this->buildKingOfCourtMatches($tournament));
        }

        if ($tournament->isJustPadelIt()) {
            if (!$jpi->canGenerateNextRound($tournament)) {
                return $this->error('Текущий раунд ещё не завершён');
            }
            $ok = $jpi->generateNextRound($tournament);
            if (!$ok) {
                return $this->error('Не удалось сгенерировать следующий раунд');
            }

            $tournament->refresh();
            return response()->json($this->buildJustPadelItMatches($tournament));
        }

        if ($tournament->isBaliKoc()) {
            if (!$bali->canGenerateNextRound($tournament)) {
                return $this->error('Текущий раунд ещё не завершён');
            }
            $ok = $bali->generateNextRound($tournament);
            if (!$ok) {
                return $this->error('Не удалось сгенерировать следующий раунд');
            }

            // Персональные пуши — переиспользуем тот же метод что веб-контроллер
            $newRoundNumber = (int) $tournament->baliKocRounds()->max('round_number');
            $tournamentId = $tournament->id;
            $tournamentName = $tournament->name;
            app()->terminating(function () use ($tournamentId, $tournamentName, $newRoundNumber) {
                \App\Http\Controllers\Club\BaliKocController::notifyBaliRoundGenerated(
                    $tournamentId, $tournamentName, $newRoundNumber
                );
            });

            $tournament->refresh();
            return response()->json($this->buildBaliKocMatches($tournament));
        }

        return $this->error(
            'Генерация следующего раунда из мобилы пока поддерживается только для KOC, Bali и Americano Flex'
        );
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

        $paired = $tournament->isPairedKingOfCourt();
        $leaderboard = $paired
            ? $this->buildKocPairLeaderboard($tournament)
            : $this->buildKingOfCourtLeaderboard($tournament);

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
            'is_paired' => $paired,
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

    /** Таблица лидеров по парам для фикс-парного Короля корта. */
    private function buildKocPairLeaderboard(Tournament $tournament): array
    {
        $standings = app(KingOfCourtService::class)->getPairStandings($tournament);
        $rows = [];
        $position = 1;
        foreach ($standings as $row) {
            $pair = $row['pair'];
            $p1 = $pair->player1;
            $p2 = $pair->player2;
            $totalGames = $row['wins'] + $row['losses'];
            $totalBalls = $row['points_for'] + $row['points_against'];
            $rows[] = [
                'position' => $position++,
                'id' => $pair->id,
                'name' => trim(($p1->name ?? '?') . ' / ' . ($p2->name ?? '?')),
                'player1' => $p1 ? $this->formatUser($p1) : null,
                'player2' => $p2 ? $this->formatUser($p2) : null,
                'rating' => (int) round((($p1->rating ?? 0) + ($p2->rating ?? 0)) / 2),
                'wins' => $row['wins'],
                'losses' => $row['losses'],
                'draws' => 0,
                'points_for' => $row['points_for'],
                'points_against' => $row['points_against'],
                'total_points' => $row['total_points'],
                'games_played' => $totalGames,
                'point_diff' => $row['diff'],
                'win_percent' => $row['win_rate'],
                'ball_percent' => $totalBalls > 0 ? (int) round($row['points_for'] / $totalBalls * 100) : 0,
            ];
        }
        return $rows;
    }

    // -------------------------------------------------------------------------
    // 3c-2 — Матчи (Just Padel It)
    // -------------------------------------------------------------------------

    private function buildJustPadelItMatches(Tournament $tournament): array
    {
        $tournament->load([
            'justPadelItRounds.matches.team1Player1',
            'justPadelItRounds.matches.team1Player2',
            'justPadelItRounds.matches.team2Player1',
            'justPadelItRounds.matches.team2Player2',
            'justPadelItPlayers.user',
        ]);

        $matchesTotal = 0;
        $matchesPlayed = 0;

        $rounds = $tournament->justPadelItRounds
            ->sortBy('round_number')
            ->values()
            ->map(function ($round) use (&$matchesTotal, &$matchesPlayed) {
                $matches = $round->matches->map(function ($m) use (&$matchesTotal, &$matchesPlayed) {
                    $matchesTotal++;
                    if ($m->status === 'completed') {
                        $matchesPlayed++;
                    }
                    return $this->formatJustPadelItMatch($m);
                });

                return [
                    'id' => $round->id,
                    'round_number' => (int) $round->round_number,
                    'status' => $round->status,
                    'matches' => $matches,
                ];
            });

        $paired = $tournament->isPairedJustPadelIt();
        $leaderboard = $paired
            ? $this->buildJpiPairLeaderboard($tournament)
            : $this->buildJustPadelItLeaderboard($tournament);

        // Заворачиваем в одну виртуальную «группу» — это даёт фронту
        // переиспользовать готовый рендер «группа → раунды → таблица».
        $virtualGroup = [
            'id' => 0,
            'name' => '',
            'rounds' => $rounds,
            'leaderboard' => $leaderboard,
        ];

        $isLive = $tournament->status === 'in_progress';
        $jpi = app(JustPadelItService::class);

        return [
            'success' => true,
            'type' => 'just_padel_it',
            'is_paired' => $paired,
            'groups' => [$virtualGroup],
            'playoff' => null,
            'summary' => [
                'matches_total' => $matchesTotal,
                'matches_played' => $matchesPlayed,
                'all_group_matches_played' => $matchesTotal > 0 && $matchesTotal === $matchesPlayed,
                'can_finish' => $isLive && $jpi->canFinishTournament($tournament),
                'can_generate_playoff' => false,
                'can_generate_next_round' => $isLive && $jpi->canGenerateNextRound($tournament),
            ],
        ];
    }

    private function formatJustPadelItMatch(JustPadelItMatch $m): array
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
            'winner' => $this->justPadelItMatchWinner($m),
        ];
    }

    private function justPadelItMatchWinner(JustPadelItMatch $m): ?int
    {
        if ($m->status !== 'completed') return null;
        if ($m->team1_score === null || $m->team2_score === null) return null;
        if ($m->team1_score === $m->team2_score) return null;
        return $m->team1_score > $m->team2_score ? 1 : 2;
    }

    private function buildJustPadelItLeaderboard(Tournament $tournament): array
    {
        // Сортировка как в JustPadelItScoring::sortStandings и веб-таблице:
        // очки ↓, при равенстве — победы ↓, далее — разница мячей ↓.
        // (sortByDesc('total_points') сам по себе тай-брейка не даёт — при
        // равных очках сохранял исходный порядок игроков.)
        $players = $tournament->justPadelItPlayers
            ->sort(fn ($a, $b) => [
                (int) $b->total_points, (int) $b->wins,
                (int) $b->points_for - (int) $b->points_against,
            ] <=> [
                (int) $a->total_points, (int) $a->wins,
                (int) $a->points_for - (int) $a->points_against,
            ])
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

    /** Таблица лидеров по парам для фикс-парного Just Padel It. */
    private function buildJpiPairLeaderboard(Tournament $tournament): array
    {
        $standings = app(JustPadelItService::class)->getPairStandings($tournament);
        $rows = [];
        $position = 1;
        foreach ($standings as $row) {
            $pair = $row['pair'];
            $p1 = $pair->player1;
            $p2 = $pair->player2;
            $totalGames = $row['wins'] + $row['losses'];
            $totalBalls = $row['points_for'] + $row['points_against'];
            $rows[] = [
                'position' => $position++,
                'id' => $pair->id,
                'name' => trim(($p1->name ?? '?') . ' / ' . ($p2->name ?? '?')),
                'player1' => $p1 ? $this->formatUser($p1) : null,
                'player2' => $p2 ? $this->formatUser($p2) : null,
                'rating' => (int) round((($p1->rating ?? 0) + ($p2->rating ?? 0)) / 2),
                'wins' => $row['wins'],
                'losses' => $row['losses'],
                'draws' => 0,
                'points_for' => $row['points_for'],
                'points_against' => $row['points_against'],
                'total_points' => $row['total_points'],
                'games_played' => $totalGames,
                'point_diff' => $row['diff'],
                'win_percent' => $row['win_rate'],
                'ball_percent' => $totalBalls > 0 ? (int) round($row['points_for'] / $totalBalls * 100) : 0,
            ];
        }
        return $rows;
    }

    // -------------------------------------------------------------------------
    // Round Robin — ввод счёта и формирование ответа /matches
    // -------------------------------------------------------------------------

    /**
     * POST/PUT /api/mobile/admin/tournaments/{tournament}/round_robin/matches/{match}/score
     * Один метод и для save, и для update — saveMatchResult сам откатит старые
     * статы, если матч уже completed.
     */
    public function saveRoundRobinScore(
        Request $request,
        Tournament $tournament,
        RoundRobinMatch $match,
        RoundRobinService $service
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

    private function buildRoundRobinMatches(Tournament $tournament): array
    {
        $tournament->load([
            'roundRobinRounds.matches.team1Player1',
            'roundRobinRounds.matches.team1Player2',
            'roundRobinRounds.matches.team2Player1',
            'roundRobinRounds.matches.team2Player2',
            'roundRobinPlayers.user',
        ]);

        $matchesTotal = 0;
        $matchesPlayed = 0;

        $rounds = $tournament->roundRobinRounds
            ->sortBy('round_number')
            ->values()
            ->map(function ($round) use (&$matchesTotal, &$matchesPlayed) {
                $matches = $round->matches->map(function ($m) use (&$matchesTotal, &$matchesPlayed) {
                    $matchesTotal++;
                    if ($m->status === 'completed') {
                        $matchesPlayed++;
                    }
                    return $this->formatRoundRobinMatch($m);
                });

                return [
                    'id' => $round->id,
                    'round_number' => (int) $round->round_number,
                    'status' => $round->status,
                    'matches' => $matches,
                ];
            });

        $leaderboard = $this->buildRoundRobinLeaderboard($tournament);

        // Как и KOC — заворачиваем в виртуальную «группу», чтобы переиспользовать
        // готовый рендер «группа → раунды → таблица».
        $virtualGroup = [
            'id' => 0,
            'name' => '',
            'rounds' => $rounds,
            'leaderboard' => $leaderboard,
        ];

        $isLive = $tournament->status === 'in_progress';
        $rr = app(RoundRobinService::class);

        return [
            'success' => true,
            'type' => 'round_robin',
            'groups' => [$virtualGroup],
            'playoff' => null,
            'summary' => [
                'matches_total' => $matchesTotal,
                'matches_played' => $matchesPlayed,
                'all_group_matches_played' => $matchesTotal > 0 && $matchesTotal === $matchesPlayed,
                'can_finish' => $isLive && $rr->canFinishTournament($tournament),
                'can_generate_playoff' => false,
                'can_generate_next_round' => $isLive && $rr->canGenerateNextRound($tournament),
            ],
        ];
    }

    private function formatRoundRobinMatch(RoundRobinMatch $m): array
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
            'winner' => $this->roundRobinMatchWinner($m),
        ];
    }

    private function roundRobinMatchWinner(RoundRobinMatch $m): ?int
    {
        if ($m->status !== 'completed') return null;
        if ($m->team1_score === null || $m->team2_score === null) return null;
        if ($m->team1_score === $m->team2_score) return null;
        return $m->team1_score > $m->team2_score ? 1 : 2;
    }

    /**
     * Таблица Round Robin: ранжирование по победам → разница геймов → личные
     * встречи (через RoundRobinService::standings). total_points = победы, чтобы
     * фронт показал в колонке «Очки» именно метрику ранжирования.
     */
    private function buildRoundRobinLeaderboard(Tournament $tournament): array
    {
        $standings = app(RoundRobinService::class)->standings($tournament);

        $rows = [];
        $position = 1;
        foreach ($standings as $s) {
            $u = $s['user'];
            if (!$u) continue;
            $totalGames = (int) $s['wins'] + (int) $s['losses'];
            $totalBalls = (int) $s['points_for'] + (int) $s['points_against'];
            $rows[] = [
                'position' => $position++,
                'id' => $u->id,
                'name' => $u->full_name ?? $u->name,
                'avatar' => $u->avatar ? asset('storage/' . $u->avatar) : null,
                'rating' => (int) ($u->rating ?? 0),
                'wins' => (int) $s['wins'],
                'losses' => (int) $s['losses'],
                'draws' => 0,
                'points_for' => (int) $s['points_for'],
                'points_against' => (int) $s['points_against'],
                'total_points' => (int) $s['wins'],
                'games_played' => $totalGames,
                'point_diff' => (int) $s['diff'],
                'win_percent' => $totalGames > 0 ? (int) round($s['wins'] / $totalGames * 100) : 0,
                'ball_percent' => $totalBalls > 0 ? (int) round($s['points_for'] / $totalBalls * 100) : 0,
            ];
        }
        return $rows;
    }

    // -------------------------------------------------------------------------
    // Bali KOC — ввод счёта, создание пар, формирование ответа /matches
    // -------------------------------------------------------------------------

    /**
     * POST/PUT /api/mobile/admin/tournaments/{tournament}/bali_koc/matches/{match}/score
     * Bali, как и KOC, использует один метод для save/update — saveMatchResult
     * сам откатит старые статы если матч уже completed.
     */
    public function saveBaliKocScore(
        Request $request,
        Tournament $tournament,
        BaliKocMatch $match,
        BaliKocService $service
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
            'pair1_games' => 'required|integer|min:0|max:99',
            'pair2_games' => 'required|integer|min:0|max:99|different:pair1_games',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        $service->saveMatchResult(
            $match,
            (int) $request->input('pair1_games'),
            (int) $request->input('pair2_games'),
        );

        $match->refresh();
        $winner = null;
        if ($match->pair1_games !== null && $match->pair2_games !== null) {
            $winner = $match->pair1_games > $match->pair2_games ? 1 : 2;
        }

        return response()->json([
            'success' => true,
            'match' => [
                'id' => $match->id,
                'team1_score' => $match->pair1_games,
                'team2_score' => $match->pair2_games,
                'status' => $match->status,
                'winner' => $winner,
            ],
        ]);
    }

    /**
     * GET /api/mobile/admin/tournaments/{tournament}/bali_koc/pairs
     * Список зарегистрированных участников + уже созданные пары.
     */
    public function baliKocPairs(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        if (!$tournament->isBaliKoc()) {
            return $this->error('Турнир не Bali Format', 422);
        }

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => $this->formatUser($u));

        $existingPairs = $tournament->baliKocPairs()
            ->with(['player1', 'player2'])
            ->orderBy('id')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'player1' => $p->player1 ? $this->formatUser($p->player1) : null,
                'player2' => $p->player2 ? $this->formatUser($p->player2) : null,
            ]);

        $expectedPairs = (int) ($participants->count() / 2);
        $canCreate = $participants->count() >= 8 && $participants->count() % 4 === 0;

        return response()->json([
            'success' => true,
            'participants' => $participants,
            'pairs' => $existingPairs,
            'expected_pairs_count' => $expectedPairs,
            'can_create' => $canCreate,
            'locked' => $existingPairs->isNotEmpty(),
        ]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/bali_koc/pairs
     * Сохранить пары. Тело: { pairs: [[player1_id, player2_id], ...] }
     */
    public function saveBaliKocPairs(
        Request $request,
        Tournament $tournament,
        BaliKocService $service
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'pairs' => 'required|array|min:2',
            'pairs.*.0' => 'required|integer|exists:users,id',
            'pairs.*.1' => 'required|integer|exists:users,id',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        [$ok, $message] = $service->createPairs($tournament, $request->input('pairs'));
        if (!$ok) {
            return $this->error($message);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * GET /api/mobile/admin/tournaments/{tournament}/justpadelit/seeding
     * Участники по рейтингу (убыв.) + число кортов — для экрана посева Just Padel It.
     */
    public function jpiSeeding(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        if (!$tournament->isJustPadelIt()) {
            return response()->json(['success' => false, 'message' => 'Не тот тип турнира'], 422);
        }

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'rating' => (int) ($u->rating ?? 0),
            ])
            ->sortByDesc('rating')
            ->values();

        return response()->json([
            'success' => true,
            'participants' => $participants,
            'courts_count' => intdiv($participants->count(), 4),
        ]);
    }

    /**
     * GET /api/mobile/admin/tournaments/{tournament}/kingofcourt/pairs
     * Состояние пар для фикс-парного Короля корта.
     */
    public function kocPairs(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        if (!$tournament->isPairedKingOfCourt()) {
            return $this->error('Турнир не «Король корта» с фиксированными парами', 422);
        }

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => $this->formatUser($u));

        $existingPairs = $tournament->kingOfCourtPairs()
            ->with(['player1', 'player2'])
            ->orderBy('id')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'player1' => $p->player1 ? $this->formatUser($p->player1) : null,
                'player2' => $p->player2 ? $this->formatUser($p->player2) : null,
            ]);

        $expectedPairs = (int) ($participants->count() / 2);
        $canCreate = $participants->count() >= 8 && $participants->count() % 4 === 0;

        return response()->json([
            'success' => true,
            'participants' => $participants,
            'pairs' => $existingPairs,
            'expected_pairs_count' => $expectedPairs,
            'can_create' => $canCreate,
            'locked' => $existingPairs->isNotEmpty(),
        ]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/kingofcourt/pairs
     * Сохранить пары. Тело: { pairs: [[player1_id, player2_id], ...] }
     */
    public function saveKocPairs(Request $request, Tournament $tournament, KingOfCourtService $service): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'pairs' => 'required|array|min:2',
            'pairs.*.0' => 'required|integer|exists:users,id',
            'pairs.*.1' => 'required|integer|exists:users,id',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        [$ok, $message] = $service->createPairs($tournament, $request->input('pairs'));
        if (!$ok) {
            return $this->error($message);
        }

        return response()->json(['success' => true, 'message' => $message]);
    }

    /**
     * GET /api/mobile/admin/tournaments/{tournament}/justpadelit/pairs
     * Состояние пар для фикс-парного Just Padel It.
     */
    public function jpiPairs(Request $request, Tournament $tournament): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        if (!$tournament->isPairedJustPadelIt()) {
            return $this->error('Турнир не «Just Padel It» с фиксированными парами', 422);
        }

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => $this->formatUser($u));

        $existingPairs = $tournament->justPadelItPairs()
            ->with(['player1', 'player2'])
            ->orderBy('id')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'player1' => $p->player1 ? $this->formatUser($p->player1) : null,
                'player2' => $p->player2 ? $this->formatUser($p->player2) : null,
            ]);

        $expectedPairs = (int) ($participants->count() / 2);
        $canCreate = $participants->count() >= 8 && $participants->count() % 4 === 0;

        return response()->json([
            'success' => true,
            'participants' => $participants,
            'pairs' => $existingPairs,
            'expected_pairs_count' => $expectedPairs,
            'can_create' => $canCreate,
            'locked' => $existingPairs->isNotEmpty(),
        ]);
    }

    /**
     * POST /api/mobile/admin/tournaments/{tournament}/justpadelit/pairs
     * Сохранить пары. Тело: { pairs: [[player1_id, player2_id], ...] }
     */
    public function saveJpiPairs(Request $request, Tournament $tournament, \App\Services\JustPadelItService $service): JsonResponse
    {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'pairs' => 'required|array|min:2',
            'pairs.*.0' => 'required|integer|exists:users,id',
            'pairs.*.1' => 'required|integer|exists:users,id',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        [$ok, $message] = $service->createPairs($tournament, $request->input('pairs'));
        if (!$ok) {
            return $this->error($message);
        }

        return response()->json(['success' => true, 'message' => $message]);
    }

    // -------------------------------------------------------------------------
    // Bali KOC — формирование ответа /matches
    // -------------------------------------------------------------------------

    private function buildBaliKocMatches(Tournament $tournament): array
    {
        $tournament->load([
            'baliKocPairs.player1',
            'baliKocPairs.player2',
            'baliKocRounds.matches',
        ]);

        $bali = app(BaliKocService::class);

        // Если пары ещё не созданы — возвращаем спец-сигнал для UI
        if (!$bali->arePairsCreated($tournament)) {
            $participantsCount = $tournament->participants()
                ->wherePivot('status', 'registered')
                ->count();
            return [
                'success' => true,
                'type' => 'bali_koc',
                'pairs_required' => true,
                'participants_count' => $participantsCount,
                'expected_pairs_count' => (int) ($participantsCount / 2),
                'groups' => [],
                'playoff' => null,
                'summary' => [
                    'matches_total' => 0,
                    'matches_played' => 0,
                    'all_group_matches_played' => false,
                    'can_finish' => false,
                    'can_generate_playoff' => false,
                    'can_generate_next_round' => false,
                ],
            ];
        }

        $pairsById = $tournament->baliKocPairs->keyBy('id');

        $matchesTotal = 0;
        $matchesPlayed = 0;

        $rounds = $tournament->baliKocRounds
            ->sortBy('round_number')
            ->values()
            ->map(function ($round) use (&$matchesTotal, &$matchesPlayed, $pairsById) {
                $matches = $round->matches
                    ->sortBy('court_number')
                    ->values()
                    ->map(function ($m) use (&$matchesTotal, &$matchesPlayed, $pairsById) {
                        $matchesTotal++;
                        if ($m->status === 'completed') $matchesPlayed++;
                        return $this->formatBaliKocMatch($m, $pairsById);
                    });

                return [
                    'id' => $round->id,
                    'round_number' => (int) $round->round_number,
                    'status' => $round->status,
                    'matches' => $matches,
                ];
            });

        $leaderboard = $this->buildBaliKocLeaderboard($tournament, $bali);

        // Заворачиваем в одну виртуальную группу — UI переиспользует рендер.
        $virtualGroup = [
            'id' => 0,
            'name' => '',
            'rounds' => $rounds,
            'leaderboard' => $leaderboard,
        ];

        $isLive = $tournament->status === 'in_progress';

        return [
            'success' => true,
            'type' => 'bali_koc',
            'pairs_required' => false,
            'groups' => [$virtualGroup],
            'playoff' => null,
            'summary' => [
                'matches_total' => $matchesTotal,
                'matches_played' => $matchesPlayed,
                'all_group_matches_played' => $matchesTotal > 0 && $matchesTotal === $matchesPlayed,
                'can_finish' => $isLive && $bali->canFinishTournament($tournament),
                'can_generate_playoff' => false,
                'can_generate_next_round' => $isLive && $bali->canGenerateNextRound($tournament),
            ],
        ];
    }

    private function formatBaliKocMatch(BaliKocMatch $m, $pairsById): array
    {
        $pair1 = $pairsById[$m->pair1_id] ?? null;
        $pair2 = $pairsById[$m->pair2_id] ?? null;

        return [
            'id' => $m->id,
            'court_number' => $m->court_number !== null ? (int) $m->court_number : null,
            'team1' => [
                'players' => array_filter([
                    $pair1?->player1 ? $this->formatMatchPlayer($pair1->player1) : null,
                    $pair1?->player2 ? $this->formatMatchPlayer($pair1->player2) : null,
                ]),
                'score' => $m->pair1_games,
            ],
            'team2' => [
                'players' => array_filter([
                    $pair2?->player1 ? $this->formatMatchPlayer($pair2->player1) : null,
                    $pair2?->player2 ? $this->formatMatchPlayer($pair2->player2) : null,
                ]),
                'score' => $m->pair2_games,
            ],
            'status' => $m->status,
            'winner' => $this->baliKocMatchWinner($m),
        ];
    }

    private function baliKocMatchWinner(BaliKocMatch $m): ?int
    {
        if ($m->status !== 'completed') return null;
        if ($m->pair1_games === null || $m->pair2_games === null) return null;
        if ($m->pair1_games === $m->pair2_games) return null;
        return $m->pair1_games > $m->pair2_games ? 1 : 2;
    }

    /**
     * Лидерборд для Bali — строка = пара (2 игрока), отсортировано
     * через BaliKocService::getStandings с tiebreaker.
     * Чтобы переиспользовать ту же UI-структуру (одна строка таблицы),
     * рендерим пару как: name = «Иван / Сергей», avatar = первый игрок.
     */
    private function buildBaliKocLeaderboard(Tournament $tournament, BaliKocService $bali): array
    {
        $standings = $bali->getStandings($tournament);
        $rows = [];
        $position = 1;
        foreach ($standings as $p) {
            $p1 = $p->player1;
            $p2 = $p->player2;
            $name1 = $p1->name ?? '?';
            $name2 = $p2->name ?? '?';
            $totalGames = (int) $p->wins + (int) $p->losses;
            $totalBalls = (int) $p->games_for + (int) $p->games_against;
            $rows[] = [
                'position' => $position++,
                'id' => $p->id, // pair id (отрицательным не делаем — UI не будет открывать профиль пары)
                'name' => "{$name1} / {$name2}",
                'avatar' => $p1 && $p1->avatar ? asset('storage/' . $p1->avatar) : null,
                'rating' => 0,
                'wins' => (int) $p->wins,
                'losses' => (int) $p->losses,
                'draws' => 0,
                'points_for' => (int) $p->games_for,
                'points_against' => (int) $p->games_against,
                'total_points' => (int) $p->points,
                'games_played' => $totalGames,
                'point_diff' => (int) $p->games_for - (int) $p->games_against,
                'win_percent' => $totalGames > 0 ? (int) round($p->wins / $totalGames * 100) : 0,
                'ball_percent' => $totalBalls > 0 ? (int) round($p->games_for / $totalBalls * 100) : 0,
            ];
        }
        return $rows;
    }

    // -------------------------------------------------------------------------
    // Team (Групповой + Плей-офф) — ввод счёта, генерация плей-офф, /matches
    // -------------------------------------------------------------------------

    /**
     * POST/PUT /api/mobile/admin/tournaments/{tournament}/team/group-match/{match}/score
     */
    public function saveTeamGroupScore(
        Request $request,
        Tournament $tournament,
        TournamentGroupMatch $match,
        TeamTournamentService $service
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $match->loadMissing('group');
        if (!$match->group ||
            (int) $match->group->tournament_id !== (int) $tournament->id) {
            return $this->error('Матч не принадлежит этому турниру', 404);
        }

        $validator = Validator::make($request->all(), [
            'team1_score' => 'required|integer|min:0|max:99',
            'team2_score' => 'required|integer|min:0|max:99',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        $service->saveGroupMatchResult(
            $match,
            (int) $request->input('team1_score'),
            (int) $request->input('team2_score'),
        );

        $match->refresh();
        $winner = null;
        if ($match->team1_score !== null && $match->team2_score !== null) {
            if ($match->team1_score > $match->team2_score) $winner = 1;
            elseif ($match->team2_score > $match->team1_score) $winner = 2;
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
     * POST/PUT /api/mobile/admin/tournaments/{tournament}/team/playoff-match/{match}/score
     */
    public function saveTeamPlayoffScore(
        Request $request,
        Tournament $tournament,
        TournamentPlayoffMatch $match,
        TeamTournamentService $service
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

        $service->savePlayoffMatchResult(
            $match,
            (int) $request->input('team1_score'),
            (int) $request->input('team2_score'),
        );

        $match->refresh();
        $winner = $match->team1_score > $match->team2_score ? 1 : 2;

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
     * POST /api/mobile/admin/tournaments/{tournament}/team/generate-playoff
     */
    public function generateTeamPlayoff(
        Request $request,
        Tournament $tournament,
        TeamTournamentService $service
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }
        if (!$tournament->isTeamBased()) {
            return $this->error('Только для парного группового турнира');
        }
        if (!$service->isGroupStageCompleted($tournament)) {
            return $this->error('Групповой этап не завершён');
        }
        if (!$service->generatePlayoff($tournament)) {
            return $this->error('Не удалось сгенерировать плей-офф');
        }

        $tournament->refresh();
        return response()->json($this->buildTeamMatches($tournament));
    }

    /**
     * Формирование /matches для team — структура такая же как у liveTeam в
     * MobileTournamentController, плюс админ-флаги (can_finish, can_generate_playoff).
     */
    private function buildTeamMatches(Tournament $tournament): array
    {
        $tournament->load([
            'teamGroups.standings.team.player1',
            'teamGroups.standings.team.player2',
            'teamGroups.matches.team1.player1',
            'teamGroups.matches.team1.player2',
            'teamGroups.matches.team2.player1',
            'teamGroups.matches.team2.player2',
            'playoffMatches.team1.player1',
            'playoffMatches.team1.player2',
            'playoffMatches.team2.player1',
            'playoffMatches.team2.player2',
        ]);

        $service = app(TeamTournamentService::class);

        $fmtPlayer = function ($p) {
            if (!$p) return null;
            return ['id' => $p->id, 'name' => $p->name, 'avatar' => $p->avatar];
        };
        $fmtTeam = function ($team) use ($fmtPlayer) {
            if (!$team) return null;
            return [
                'id' => $team->id,
                'name' => $team->name,
                'player1' => $fmtPlayer($team->player1),
                'player2' => $fmtPlayer($team->player2),
            ];
        };

        $matchesTotal = 0;
        $matchesPlayed = 0;
        $groupsOut = [];

        foreach ($tournament->teamGroups as $group) {
            // Standings через сервис — учитывается личная встреча при равных очках
            $sortedStandings = $service->getSortedStandings($group);

            $leaderboard = [];
            $position = 1;
            foreach ($sortedStandings as $row) {
                $teamId = (int) $row['team_id'];
                $standingObj = $group->standings->firstWhere('team_id', $teamId);
                $team = $standingObj?->team;
                if (!$team) continue;

                $diff = (int) $row['points_for'] - (int) $row['points_against'];
                $totalBalls = (int) $row['points_for'] + (int) $row['points_against'];
                $ballPercent = $totalBalls > 0
                    ? (int) round((int) $row['points_for'] / $totalBalls * 100)
                    : 0;

                $name1 = $team->player1?->name ?? '?';
                $name2 = $team->player2?->name ?? '?';

                // Для лидерборда UI используем единый формат с другими типами:
                // строка показывается через name (склейка имён пары).
                $leaderboard[] = [
                    'position' => $position++,
                    'id' => $team->id,
                    'name' => "{$name1} / {$name2}",
                    'avatar' => $team->player1?->avatar ? asset('storage/' . $team->player1->avatar) : null,
                    'rating' => 0,
                    'wins' => (int) $row['won'],
                    'losses' => (int) $row['lost'],
                    'draws' => max(0, (int) $row['played'] - (int) $row['won'] - (int) $row['lost']),
                    'points_for' => (int) $row['points_for'],
                    'points_against' => (int) $row['points_against'],
                    'total_points' => (int) $row['points'],
                    'games_played' => (int) $row['played'],
                    'point_diff' => $diff,
                    'win_percent' => ((int) $row['played']) > 0
                        ? (int) round((int) $row['won'] / (int) $row['played'] * 100)
                        : 0,
                    'ball_percent' => $ballPercent,
                ];
            }

            // Матчи группы, объединённые в раунды по round_number
            $byRound = [];
            foreach ($group->matches as $m) {
                $matchesTotal++;
                if ($m->status === 'completed') $matchesPlayed++;

                $rn = (int) $m->round_number;
                $byRound[$rn] ??= [];

                $t1 = $fmtTeam($m->team1);
                $t2 = $fmtTeam($m->team2);

                $byRound[$rn][] = [
                    'id' => $m->id,
                    'court_number' => $m->court_number !== null ? (int) $m->court_number : null,
                    'team1' => [
                        'players' => array_filter([
                            $t1['player1'] ?? null,
                            $t1['player2'] ?? null,
                        ]),
                        'score' => $m->team1_score,
                        'team_id' => $m->team1_id,
                        'team_name' => $t1['name'] ?? null,
                    ],
                    'team2' => [
                        'players' => array_filter([
                            $t2['player1'] ?? null,
                            $t2['player2'] ?? null,
                        ]),
                        'score' => $m->team2_score,
                        'team_id' => $m->team2_id,
                        'team_name' => $t2['name'] ?? null,
                    ],
                    'status' => $m->status,
                    'winner' => $this->teamMatchWinner($m->team1_score, $m->team2_score, $m->status),
                ];
            }
            ksort($byRound);

            $rounds = [];
            foreach ($byRound as $rn => $matches) {
                $hasInProgress = false;
                $allCompleted = true;
                foreach ($matches as $m) {
                    if ($m['status'] === 'in_progress') $hasInProgress = true;
                    if ($m['status'] !== 'completed') $allCompleted = false;
                }
                $rounds[] = [
                    'id' => $group->id * 1000 + $rn,
                    'round_number' => $rn,
                    'status' => $hasInProgress
                        ? 'in_progress'
                        : ($allCompleted ? 'completed' : 'pending'),
                    'matches' => $matches,
                ];
            }

            $groupsOut[] = [
                'id' => $group->id,
                'name' => $group->name,
                'rounds' => $rounds,
                'leaderboard' => $leaderboard,
            ];
        }

        // Плей-офф (если есть матчи). Возвращаем плоский массив playoff.matches —
        // его рендерит общий админский UI. Поле stage у каждого матча подсказывает
        // заголовок (Полуфинал/Финал/...).
        $playoffOut = null;
        $playoffMatches = $tournament->playoffMatches;
        if ($playoffMatches->isNotEmpty()) {
            $stageOrder = [
                '1/8 финала' => 1,
                '1/4 финала' => 2,
                'Полуфинал' => 3,
                'За 3-е место' => 4,
                'Финал' => 5,
            ];

            $flat = [];
            foreach ($playoffMatches as $m) {
                $matchesTotal++;
                if ($m->status === 'completed') $matchesPlayed++;

                $stageKey = $m->is_bronze
                    ? 'За 3-е место'
                    : ($m->stage_name ?: ($m->stage ?? '—'));
                if ($m->bracket === 'lower') {
                    $stageKey .= ' (нижняя сетка)';
                }

                $t1 = $fmtTeam($m->team1);
                $t2 = $fmtTeam($m->team2);

                $flat[] = [
                    'id' => $m->id,
                    'stage' => $stageKey,
                    'court_number' => $m->court_number !== null ? (int) $m->court_number : null,
                    'match_number' => $m->match_number,
                    'team1' => [
                        'players' => array_filter([
                            $t1['player1'] ?? null,
                            $t1['player2'] ?? null,
                        ]),
                        'score' => $m->team1_score,
                        'team_id' => $m->team1_id,
                        'team_name' => $t1['name'] ?? null,
                    ],
                    'team2' => [
                        'players' => array_filter([
                            $t2['player1'] ?? null,
                            $t2['player2'] ?? null,
                        ]),
                        'score' => $m->team2_score,
                        'team_id' => $m->team2_id,
                        'team_name' => $t2['name'] ?? null,
                    ],
                    'status' => $m->status,
                    'winner' => $this->teamMatchWinner($m->team1_score, $m->team2_score, $m->status),
                    '_stage_order' => $stageOrder[$stageKey] ?? 99,
                ];
            }

            // Сортируем по логическому порядку стадий, затем по match_number
            usort($flat, function ($a, $b) {
                if ($a['_stage_order'] !== $b['_stage_order']) {
                    return $a['_stage_order'] <=> $b['_stage_order'];
                }
                return ($a['match_number'] ?? 0) <=> ($b['match_number'] ?? 0);
            });
            // Убираем служебное поле
            foreach ($flat as &$mm) unset($mm['_stage_order']);
            unset($mm);

            $playoffOut = [
                'has_playoff' => true,
                'is_generated' => true,
                'matches' => $flat,
            ];
        }

        $isLive = $tournament->status === 'in_progress';
        $canGeneratePlayoff = $isLive
            && $tournament->has_playoff
            && $playoffMatches->isEmpty()
            && $service->isGroupStageCompleted($tournament);

        return [
            'success' => true,
            'type' => 'team',
            'groups' => $groupsOut,
            'playoff' => $playoffOut,
            'summary' => [
                'matches_total' => $matchesTotal,
                'matches_played' => $matchesPlayed,
                'all_group_matches_played' => $service->isGroupStageCompleted($tournament),
                'can_finish' => $isLive && $service->canFinishTournament($tournament),
                'can_generate_playoff' => $canGeneratePlayoff,
                'can_generate_next_round' => false,
            ],
        ];
    }

    private function teamMatchWinner($s1, $s2, ?string $status): ?int
    {
        if ($status !== 'completed') return null;
        if ($s1 === null || $s2 === null) return null;
        if ($s1 === $s2) return null;
        return $s1 > $s2 ? 1 : 2;
    }

    // -------------------------------------------------------------------------
    // Americano Flex — ввод счёта и формирование ответа /matches
    // -------------------------------------------------------------------------

    /**
     * POST/PUT /api/mobile/admin/tournaments/{tournament}/americano_flex/matches/{match}/score
     * Один эндпоинт для save и update — service переписывает счёт идемпотентно.
     */
    public function saveAmericanoFlexScore(
        Request $request,
        Tournament $tournament,
        AmericanoFlexMatch $match,
        AmericanoFlexService $service
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $match->loadMissing('round');
        if (!$match->round || (int) $match->round->tournament_id !== (int) $tournament->id) {
            return $this->error('Матч не принадлежит этому турниру', 404);
        }

        $validator = Validator::make($request->all(), [
            'team1_score' => 'required|integer|min:0|max:99',
            'team2_score' => 'required|integer|min:0|max:99',
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
        if ($match->team1_score !== null && $match->team2_score !== null && $match->team1_score !== $match->team2_score) {
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

    private function buildAmericanoFlexMatches(Tournament $tournament): array
    {
        $tournament->load([
            'americanoFlexRounds.matches.team1Player1',
            'americanoFlexRounds.matches.team1Player2',
            'americanoFlexRounds.matches.team2Player1',
            'americanoFlexRounds.matches.team2Player2',
            'americanoFlexRounds.byes.user',
            'americanoFlexPlayers.user',
        ]);

        $matchesTotal = 0;
        $matchesPlayed = 0;

        $rounds = $tournament->americanoFlexRounds
            ->sortBy('round_number')
            ->values()
            ->map(function ($round) use (&$matchesTotal, &$matchesPlayed) {
                $matches = $round->matches->map(function ($m) use (&$matchesTotal, &$matchesPlayed) {
                    $matchesTotal++;
                    if ($m->status === 'completed') {
                        $matchesPlayed++;
                    }
                    return $this->formatAmericanoFlexMatch($m);
                })->values();

                $byes = $round->byes->map(function ($b) {
                    return $this->formatMatchPlayer($b->user);
                })->filter()->values();

                return [
                    'id' => $round->id,
                    'round_number' => (int) $round->round_number,
                    'status' => $round->status,
                    'matches' => $matches,
                    'byes' => $byes,
                ];
            });

        $leaderboard = $this->buildAmericanoFlexLeaderboard($tournament);
        $virtualGroup = [
            'id' => 0,
            'name' => '',
            'rounds' => $rounds,
            'leaderboard' => $leaderboard,
        ];

        $isLive = $tournament->status === 'in_progress';
        $flex = app(AmericanoFlexService::class);

        return [
            'success' => true,
            'type' => 'americano_flex',
            'groups' => [$virtualGroup],
            'playoff' => null,
            'summary' => [
                'matches_total' => $matchesTotal,
                'matches_played' => $matchesPlayed,
                'all_group_matches_played' => $matchesTotal > 0 && $matchesTotal === $matchesPlayed,
                'can_finish' => $isLive && $flex->canFinishTournament($tournament),
                'can_generate_playoff' => false,
                'can_generate_next_round' => $isLive && $flex->canGenerateNextRound($tournament),
            ],
        ];
    }

    private function formatAmericanoFlexMatch(AmericanoFlexMatch $m): array
    {
        $winner = null;
        if ($m->status === 'completed' && $m->team1_score !== null && $m->team2_score !== null && $m->team1_score !== $m->team2_score) {
            $winner = $m->team1_score > $m->team2_score ? 1 : 2;
        }
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
            'winner' => $winner,
        ];
    }

    private function buildAmericanoFlexLeaderboard(Tournament $tournament): array
    {
        // Парный флекс: лидерборд по парам (одна строка = пара «A / B»).
        if ($tournament->isPairedFlex()) {
            // В паре оба игрока всегда играют вместе — статистика у них одинаковая,
            // берём по player1.
            $stats = $this->flexPlayerStats($tournament);
            $pairRows = app(AmericanoFlexService::class)->getPairedLeaderboard($tournament);
            $rows = [];
            foreach ($pairRows as $r) {
                $p1 = $r['player1'];
                $p2 = $r['player2'];
                $name = trim(($p1->name ?? '—') . ' / ' . ($p2->name ?? '—'));
                $st = $stats[$p1->id ?? 0] ?? [0, 0, 0, 0, 0];
                $matches = $st[4];
                $points = $st[2]; // очки = сумма набранного (без 0:0)
                $avg = $matches > 0 ? round($points / $matches, 2) : 0;
                $rows[] = [
                    'id' => $p1->id ?? 0,
                    'name' => $name,
                    'avatar' => $p1 && $p1->avatar ? asset('storage/' . $p1->avatar) : null,
                    'rating' => null,
                    'rating_before' => null,
                    'rating_after' => null,
                    'total_points' => $points,
                    'matches_played' => $matches,
                    'bye_count' => (int) $r['bye_count'],
                    'bye_streak' => (int) $r['bye_streak'],
                    'avg_points' => $avg,
                    'wins' => $st[0],
                    'losses' => $st[1],
                    'points_for' => $st[2],
                    'points_against' => $st[3],
                ];
            }
            return $this->rankFlexRows($rows);
        }

        // Статистика из завершённых матчей (матчи 0:0 не учитываются).
        $stats = $this->flexPlayerStats($tournament);

        $rows = [];
        foreach ($tournament->americanoFlexPlayers as $fp) {
            $u = $fp->user;
            if (!$u) continue;
            $st = $stats[$u->id] ?? [0, 0, 0, 0, 0];
            $matches = $st[4];
            $points = $st[2]; // очки = сумма набранного (без 0:0)
            $avg = $matches > 0 ? round($points / $matches, 2) : 0;
            $rows[] = [
                'id' => $u->id,
                'name' => $u->full_name ?? $u->name,
                'avatar' => $u->avatar ? asset('storage/' . $u->avatar) : null,
                'rating' => (int) ($u->rating ?? 0),
                'rating_before' => $fp->rating_before !== null ? (int) $fp->rating_before : null,
                'rating_after' => $fp->rating_after !== null ? (int) $fp->rating_after : null,
                'total_points' => $points,
                'matches_played' => $matches,
                'bye_count' => (int) $fp->bye_count,
                'bye_streak' => (int) $fp->bye_streak,
                'avg_points' => $avg,
                'wins' => $st[0],
                'losses' => $st[1],
                'points_for' => $st[2],
                'points_against' => $st[3],
            ];
        }
        return $this->rankFlexRows($rows);
    }

    /**
     * Статистика по игрокам из завершённых матчей Americano Flex.
     * Матчи со счётом 0:0 (несыгранные) НЕ учитываются нигде.
     * Возвращает user_id => [wins, losses, points_for, points_against, matches].
     */
    private function flexPlayerStats(Tournament $tournament): array
    {
        $stats = [];
        foreach ($tournament->americanoFlexRounds()->with('matches')->get() as $round) {
            foreach ($round->matches as $m) {
                if ($m->status !== 'completed') continue;
                $s1 = (int) $m->team1_score;
                $s2 = (int) $m->team2_score;
                if ($s1 === 0 && $s2 === 0) continue; // 0:0 — матч не игрался, не считаем
                $team1 = [$m->team1_player1_id, $m->team1_player2_id];
                $team2 = [$m->team2_player1_id, $m->team2_player2_id];
                foreach ($team1 as $uid) {
                    if (!$uid) continue;
                    $stats[$uid] ??= [0, 0, 0, 0, 0];
                    $stats[$uid][2] += $s1;
                    $stats[$uid][3] += $s2;
                    $stats[$uid][4]++;
                    if ($s1 > $s2) $stats[$uid][0]++; elseif ($s1 < $s2) $stats[$uid][1]++;
                }
                foreach ($team2 as $uid) {
                    if (!$uid) continue;
                    $stats[$uid] ??= [0, 0, 0, 0, 0];
                    $stats[$uid][2] += $s2;
                    $stats[$uid][3] += $s1;
                    $stats[$uid][4]++;
                    if ($s2 > $s1) $stats[$uid][0]++; elseif ($s2 < $s1) $stats[$uid][1]++;
                }
            }
        }
        return $stats;
    }

    /**
     * Отсортировать строки лидерборда флекса по среднему очков за матч
     * (затем по сумме) и проставить position.
     */
    private function rankFlexRows(array $rows): array
    {
        usort($rows, function ($a, $b) {
            if ($a['avg_points'] != $b['avg_points']) {
                return $b['avg_points'] <=> $a['avg_points'];
            }
            return $b['total_points'] <=> $a['total_points'];
        });
        $position = 1;
        foreach ($rows as &$r) {
            $r['position'] = $position++;
        }
        unset($r);
        return $rows;
    }
}

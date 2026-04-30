<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Services\AmericanoService;
use App\Services\KingOfCourtService;
use App\Services\MexicanoService;
use App\Services\TeamTournamentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Управление существующим турниром из мобилы (админ клуба).
 * Этап 3a: инфо-таб, редактирование, запуск, удаление.
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
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileAdminTournamentController extends Controller
{
    /**
     * GET /api/mobile/admin/clubs/{club}/tournaments
     * Все турниры клуба со всеми статусами для админа клуба.
     */
    public function index(Request $request, Club $club): JsonResponse
    {
        $user = $request->user();

        // Проверка: user должен быть админом этого клуба
        if (!$this->canManageClub($user, $club)) {
            return response()->json([
                'success' => false,
                'message' => 'Нет доступа к этому клубу',
            ], 403);
        }

        $tournaments = Tournament::where('club_id', $club->id)
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(fn($t) => $this->formatSummary($t));

        return response()->json([
            'success' => true,
            'club' => [
                'id' => $club->id,
                'name' => $club->name,
            ],
            'tournaments' => $tournaments,
        ]);
    }

    /**
     * Может ли пользователь управлять клубом (он club_admin этого клуба).
     */
    private function canManageClub($user, Club $club): bool
    {
        if (!$user) return false;
        if ($user->isSuperAdmin()) return true;
        return $user->adminClubs()->where('clubs.id', $club->id)->exists();
    }

    /**
     * Компактная карточка турнира для списка.
     */
    private function formatSummary(Tournament $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'type' => $t->type,
            'type_name' => $t->type_name,
            'date' => $t->start_date->format('d.m.Y'),
            'time' => $t->start_date->format('H:i'),
            'datetime' => $t->start_date->toIso8601String(),
            'status' => $t->status,
            'status_name' => $t->status_name,
            'min_level' => (float) $t->min_level,
            'max_level' => (float) $t->max_level,
            'max_participants' => $t->max_participants,
            'participants_count' => $this->getParticipantsCount($t),
            'pending_count' => $this->getPendingCount($t),
        ];
    }

    /**
     * Сколько участников зарегистрировано (registered + pending).
     * Для team-турниров считаем по парам × 2.
     */
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

    /**
     * Сколько заявок ждут модерации.
     */
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use Illuminate\Http\Request;

class MobileHomeController extends Controller
{
    /**
     * Главная страница мобильного приложения
     * GET /api/mobile/home
     */
    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => $this->getUserData($user),
            'nearest_tournament' => $this->getNearestTournament($user),
            'active_tournament' => $this->getActiveTournament($user),
            'upcoming_tournaments' => $this->getUpcomingTournaments($user),
            'court_booking_available' => \App\Models\Club::active()->whereHas('courts', fn($q) => $q->where('is_active', true))->exists(),
        ]);
    }

    /**
     * Данные пользователя + место в рейтинге
     */
    private function getUserData($user): array
    {
        $place = null;
        if ($user->rating) {
            $place = User::where('role', 'player')
                ->where('rating', '>', $user->rating)
                ->count() + 1;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'rating' => $user->rating,
            'level' => $user->level,
            'level_name' => $user->level_name,
            'place' => $place,
            'city' => $user->city,
            'gender' => $user->gender,
        ];
    }

    /**
     * Ближайший турнир на который можно записаться (не записан, can_register = true)
     */
    private function getNearestTournament($user): ?array
    {
        $tournaments = Tournament::where('status', 'open')
            ->where('start_date', '>', now())
            ->orderBy('start_date', 'asc')
            ->with('club')
            ->get();

        foreach ($tournaments as $t) {
            if ($t->canRegister($user)) {
                return $this->formatTournament($t);
            }
        }

        return null;
    }

    /**
     * Турнир в котором я уже участвую (is_registered = true)
     */
    private function getActiveTournament($user): ?array
    {
        // Обычные турниры (americano/mexicano/classic)
        $tournament = Tournament::whereHas('participants', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->where('status', 'registered');
            })
            ->whereIn('status', ['open', 'closed', 'in_progress'])
            ->orderBy('start_date', 'asc')
            ->with('club')
            ->first();

        if ($tournament) {
            return $this->formatTournament($tournament);
        }

        // Командные турниры (team)
        $teamTournamentId = TournamentTeam::where(function ($q) use ($user) {
                $q->where('player1_id', $user->id)
                  ->orWhere('player2_id', $user->id);
            })
            ->whereIn('status', ['approved', 'pending'])
            ->pluck('tournament_id')
            ->first();

        if ($teamTournamentId) {
            $tournament = Tournament::where('id', $teamTournamentId)
                ->whereIn('status', ['open', 'closed', 'in_progress'])
                ->with('club')
                ->first();

            if ($tournament) {
                return $this->formatTournament($tournament);
            }
        }

        return null;
    }

    /**
     * Ближайшие открытые турниры (исключая скрытые клубы)
     */
    private function getUpcomingTournaments($user = null): array
    {
        $query = Tournament::where('status', 'open')
            ->where('start_date', '>', now())
            ->orderBy('start_date', 'asc')
            ->with('club');

        $hiddenClubIds = $user ? ($user->hidden_club_ids ?? []) : [];
        if (!empty($hiddenClubIds)) {
            $query->whereNotIn('club_id', $hiddenClubIds);
        }

        return $query->get()
            ->map(fn($t) => $this->formatTournament($t))
            ->toArray();
    }

    /**
     * Формат турнира для ответа
     */
    private function formatTournament(Tournament $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'club' => [
                'id' => $t->club->id ?? null,
                'name' => $t->club->name ?? 'Клуб',
            ],
            'date' => $t->start_date->format('d.m.Y'),
            'time' => $t->start_date->format('H:i'),
            'datetime' => $t->start_date->toIso8601String(),
            'type' => $t->type,
            'type_name' => $t->type_name,
            'status' => $t->status,
            'status_name' => $t->status_name,
            'min_level' => (float) $t->min_level,
            'max_level' => (float) $t->max_level,
            'price' => (float) $t->price,
            'max_participants' => $t->max_participants,
            'participants_count' => $this->getParticipantsCount($t),
        ];
    }

    /**
     * Количество участников турнира
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
}

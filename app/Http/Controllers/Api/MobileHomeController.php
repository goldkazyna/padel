<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
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
            'banner' => $this->getBanner(),
        ]);
    }

    /**
     * Рекламный баннер (синглтон). Возвращаем только если задано фото.
     */
    private function getBanner(): ?array
    {
        $banner = Banner::first();

        if (!$banner || empty($banner->image_path)) {
            return null;
        }

        return [
            'image' => url($banner->image_path),
            'link' => $banner->link,
        ];
    }

    /**
     * Данные пользователя + место в рейтинге
     */
    private function getUserData($user): array
    {
        $place = null;
        if ($user->rating) {
            $place = User::visibleInRating()
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
            'level_verified' => (bool) $user->level_verified,
            'verification_blockers' => $user->verificationBlockers(),
            'place' => $place,
            'city' => $user->city,
            'gender' => $user->gender,
        ];
    }

    /**
     * Ближайший турнир, в котором пользователь УЖЕ участвует и который ещё
     * не начался (status=open, start_date в будущем). Учитываем
     * индивидуальные и командные турниры, берём наиболее ранний.
     */
    private function getNearestTournament($user): ?array
    {
        // Индивидуальные турниры, где пользователь записан
        $individual = Tournament::whereHas('participants', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->whereIn('status', ['registered', 'pending']);
            })
            ->where('status', 'open')
            ->where('start_date', '>', now())
            ->orderBy('start_date', 'asc')
            ->with('club')
            ->first();

        // Командные турниры, где пользователь в паре
        $teamTournamentIds = TournamentTeam::where(function ($q) use ($user) {
                $q->where('player1_id', $user->id)
                  ->orWhere('player2_id', $user->id);
            })
            ->whereIn('status', ['approved', 'pending'])
            ->pluck('tournament_id');

        $team = Tournament::whereIn('id', $teamTournamentIds)
            ->where('status', 'open')
            ->where('start_date', '>', now())
            ->orderBy('start_date', 'asc')
            ->with('club')
            ->first();

        // Берём наиболее ранний из двух
        $nearest = collect([$individual, $team])
            ->filter()
            ->sortBy('start_date')
            ->first();

        return $nearest ? $this->formatTournament($nearest) : null;
    }

    /**
     * Нормализуем hidden_club_ids: поддерживаем массив и JSON-строку,
     * приводим к int, отфильтровываем пустые значения.
     */
    private function normalizeHiddenClubIds($user): array
    {
        if (!$user) return [];
        $raw = $user->hidden_club_ids ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) return [];
        return array_values(array_filter(array_map('intval', $raw)));
    }

    /**
     * Турнир в котором я уже участвую (is_registered = true)
     */
    private function getActiveTournament($user): ?array
    {
        // Обычные турниры (americano/mexicano/classic) — только идущие сейчас
        $tournament = Tournament::whereHas('participants', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->where('status', 'registered');
            })
            ->where('status', 'in_progress')
            ->orderBy('start_date', 'asc')
            ->with('club')
            ->first();

        if ($tournament) {
            return $this->formatTournament($tournament);
        }

        // Командные турниры (team) — только идущие сейчас
        $teamTournamentId = TournamentTeam::where(function ($q) use ($user) {
                $q->where('player1_id', $user->id)
                  ->orWhere('player2_id', $user->id);
            })
            ->whereIn('status', ['approved', 'pending'])
            ->pluck('tournament_id')
            ->first();

        if ($teamTournamentId) {
            $tournament = Tournament::where('id', $teamTournamentId)
                ->where('status', 'in_progress')
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
            ->whereHas('club', fn($q) => $q->where('is_test', false))
            ->orderBy('start_date', 'asc')
            ->with('club');

        $hiddenClubIds = $this->normalizeHiddenClubIds($user);
        if (!empty($hiddenClubIds)) {
            $query->whereNotIn('club_id', $hiddenClubIds);
        }

        return $query->get()
            ->filter(fn($t) => !in_array((int) $t->club_id, $hiddenClubIds, true))
            ->map(fn($t) => $this->formatTournament($t))
            ->values()
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
                'logo' => $t->club->logo ? url($t->club->logo) : null,
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

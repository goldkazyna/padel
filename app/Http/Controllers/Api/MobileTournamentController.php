<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use Illuminate\Http\Request;

class MobileTournamentController extends Controller
{
    /**
     * Список открытых турниров (предстоящие, с открытой регистрацией)
     * GET /api/mobile/tournaments
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $tournaments = Tournament::where('status', 'open')
            ->where('start_date', '>', now())
            ->orderBy('start_date', 'asc')
            ->with('club')
            ->get()
            ->map(fn($t) => $this->formatTournament($t, $user));

        return response()->json([
            'success' => true,
            'tournaments' => $tournaments,
        ]);
    }

    /**
     * Мои турниры (на которые записан, предстоящие и текущие)
     * GET /api/mobile/tournaments/my
     */
    public function my(Request $request)
    {
        $user = $request->user();

        // Турниры где я участник (americano/mexicano)
        $participantTournamentIds = $user->tournaments()
            ->whereIn('tournaments.status', ['open', 'closed', 'in_progress'])
            ->pluck('tournaments.id');

        // Турниры где я в команде (team)
        $teamTournamentIds = TournamentTeam::where(function($q) use ($user) {
                $q->where('player1_id', $user->id)
                  ->orWhere('player2_id', $user->id);
            })
            ->pluck('tournament_id');

        $allIds = $participantTournamentIds->merge($teamTournamentIds)->unique();

        $tournaments = Tournament::whereIn('id', $allIds)
            ->whereIn('status', ['open', 'closed', 'in_progress'])
            ->orderBy('start_date', 'asc')
            ->with('club')
            ->get()
            ->map(fn($t) => $this->formatTournament($t, $user, true));

        return response()->json([
            'success' => true,
            'tournaments' => $tournaments,
        ]);
    }

    /**
     * Архив турниров (завершённые, где я участвовал + мой результат)
     * GET /api/mobile/tournaments/archive
     */
    public function archive(Request $request)
    {
        $user = $request->user();

        // Турниры где я участник
        $participantTournamentIds = $user->tournaments()
            ->where('tournaments.status', 'completed')
            ->pluck('tournaments.id');

        // Турниры где я в команде
        $teamTournamentIds = TournamentTeam::where(function($q) use ($user) {
                $q->where('player1_id', $user->id)
                  ->orWhere('player2_id', $user->id);
            })
            ->pluck('tournament_id');

        $allIds = $participantTournamentIds->merge($teamTournamentIds)->unique();

        $tournaments = Tournament::whereIn('id', $allIds)
            ->where('status', 'completed')
            ->orderBy('start_date', 'desc')
            ->with('club')
            ->get()
            ->map(fn($t) => $this->formatArchiveTournament($t, $user));

        return response()->json([
            'success' => true,
            'tournaments' => $tournaments,
        ]);
    }

    /**
     * Детали турнира
     * GET /api/mobile/tournaments/{id}
     */
    public function show(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        $tournament->load('club');

        $data = $this->formatTournament($tournament, $user, true);

        // Добавляем участников/команды
        if ($tournament->type === 'team') {
            $data['teams'] = $tournament->teams()
                ->with(['player1', 'player2'])
                ->whereIn('status', ['approved', 'pending'])
                ->get()
                ->map(fn($t) => [
                    'id' => $t->id,
                    'player1' => [
                        'id' => $t->player1->id,
                        'name' => $t->player1->name,
                        'level' => $t->player1->level,
                        'rating' => $t->player1->rating,
                    ],
                    'player2' => [
                        'id' => $t->player2->id,
                        'name' => $t->player2->name,
                        'level' => $t->player2->level,
                        'rating' => $t->player2->rating,
                    ],
                    'status' => $t->status,
                ]);
        } else {
            $data['participants'] = $tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending'])
                ->get()
                ->map(fn($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'level' => $p->level,
                    'rating' => $p->rating,
                    'status' => $p->pivot->status,
                ]);
        }

        return response()->json([
            'success' => true,
            'tournament' => $data,
        ]);
    }

    /**
     * Записаться на турнир
     * POST /api/mobile/tournaments/{id}/register
     */
    public function register(Request $request, Tournament $tournament)
    {
        $user = $request->user();

        if ($tournament->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Турнир не открыт для регистрации'], 400);
        }

        if ($tournament->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Вы уже записаны на этот турнир'], 400);
        }

        if ($user->level < $tournament->min_level || $user->level > $tournament->max_level) {
            return response()->json([
                'success' => false,
                'message' => "Ваш уровень ({$user->level}) не подходит. Требуется: {$tournament->min_level} – {$tournament->max_level}",
            ], 400);
        }

        $takenSlots = $tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending'])
            ->count();

        if ($takenSlots >= $tournament->max_participants) {
            return response()->json(['success' => false, 'message' => 'Все места заняты'], 400);
        }

        $tournament->participants()->attach($user->id, ['status' => 'pending']);

        return response()->json([
            'success' => true,
            'message' => 'Заявка отправлена на модерацию',
            'registration_status' => 'pending',
        ]);
    }

    /**
     * Отменить запись на турнир
     * POST /api/mobile/tournaments/{id}/cancel
     */
    public function cancel(Request $request, Tournament $tournament)
    {
        $user = $request->user();

        $participant = $tournament->participants()->where('user_id', $user->id)->first();

        if (!$participant) {
            return response()->json(['success' => false, 'message' => 'Вы не записаны на этот турнир'], 400);
        }

        if (!in_array($tournament->status, ['open'])) {
            return response()->json(['success' => false, 'message' => 'Нельзя отменить запись — турнир уже начался'], 400);
        }

        $wasFull = $tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending'])
            ->count() >= $tournament->max_participants;

        $tournament->participants()->detach($user->id);

        if ($wasFull) {
            $channelService = new \App\Services\TelegramChannelService();
            $channelService->postSlotAvailable($tournament);
        }

        return response()->json([
            'success' => true,
            'message' => 'Запись на турнир отменена',
        ]);
    }

    /**
     * Форматирование турнира для списка
     */
    private function formatTournament(Tournament $t, $user, bool $includeRegistration = false): array
    {
        $data = [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'club' => [
                'id' => $t->club->id ?? null,
                'name' => $t->club->name ?? 'Клуб',
                'phone' => $t->club->phone ?? null,
                'address' => $t->club->address ?? null,
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
            'spots_left' => max(0, $t->max_participants - $this->getParticipantsCount($t)),
        ];

        if ($user && $includeRegistration) {
            $registration = $this->getUserRegistration($t, $user);
            $data['is_registered'] = $registration['is_registered'];
            $data['registration_status'] = $registration['status'];
            $data['can_register'] = $registration['can_register'];
            $data['block_reason'] = $registration['block_reason'];
        }

        return $data;
    }

    /**
     * Форматирование архивного турнира с результатом
     */
    private function formatArchiveTournament(Tournament $t, $user): array
    {
        $data = $this->formatTournament($t, $user);

        // Добавляем результат пользователя
        $data['my_result'] = $this->getUserResult($t, $user);

        return $data;
    }

    /**
     * Получить количество участников
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
     * Получить статус регистрации пользователя
     */
    private function getUserRegistration(Tournament $t, $user): array
    {
        $result = [
            'is_registered' => false,
            'status' => null,
            'can_register' => false,
            'block_reason' => null,
        ];

        if ($t->type === 'team') {
            $team = TournamentTeam::where('tournament_id', $t->id)
                ->where(function($q) use ($user) {
                    $q->where('player1_id', $user->id)
                      ->orWhere('player2_id', $user->id);
                })
                ->first();

            if ($team) {
                $result['is_registered'] = true;
                $result['status'] = $team->status;
            } else {
                $result['block_reason'] = $t->getRegistrationBlockReason($user);
                $result['can_register'] = $result['block_reason'] === null && $t->isOpen();
            }
        } else {
            $participant = $t->participants()->where('user_id', $user->id)->first();

            if ($participant) {
                $result['is_registered'] = true;
                $result['status'] = $participant->pivot->status;
            } else {
                $result['block_reason'] = $t->getRegistrationBlockReason($user);
                $result['can_register'] = $result['block_reason'] === null && $t->isOpen();
            }
        }

        return $result;
    }

    /**
     * Получить результат пользователя в турнире
     */
    private function getUserResult(Tournament $t, $user): ?array
    {
        // Получаем изменение рейтинга из истории
        $ratingChange = $user->ratingHistory()
            ->where('tournament_id', $t->id)
            ->first();

        if (!$ratingChange) {
            return null;
        }

        $result = [
            'rating_change' => $ratingChange->change,
            'rating_after' => $ratingChange->rating_after,
            'place' => null,
        ];

        // Определяем место в зависимости от типа турнира
        if ($t->type === 'mexicano') {
            $player = $t->mexicanoPlayers()
                ->where('user_id', $user->id)
                ->first();

            if ($player) {
                $place = $t->mexicanoPlayers()
                    ->where('total_points', '>', $player->total_points)
                    ->count() + 1;
                $result['place'] = $place;
                $result['points'] = $player->total_points;
            }
        } elseif ($t->type === 'americano') {
            // Для американо — место в группе
            $groupPlayer = \DB::table('tournament_group_players')
                ->join('tournament_groups', 'tournament_groups.id', '=', 'tournament_group_players.tournament_group_id')
                ->where('tournament_groups.tournament_id', $t->id)
                ->where('tournament_group_players.user_id', $user->id)
                ->first();

            if ($groupPlayer) {
                $result['points'] = $groupPlayer->total_points ?? 0;
            }
        }

        return $result;
    }
}

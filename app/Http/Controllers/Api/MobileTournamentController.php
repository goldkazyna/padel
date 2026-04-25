<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentSubscription;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Models\RatingHistory;
use App\Traits\RatingCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileTournamentController extends Controller
{
    use RatingCalculator;
    /**
     * Список открытых турниров (предстоящие, с открытой регистрацией)
     * GET /api/mobile/tournaments
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $hiddenClubIds = $user ? ($user->hidden_club_ids ?? []) : [];

        $query = Tournament::where('status', 'open')
            ->where('start_date', '>', now())
            ->orderBy('start_date', 'asc')
            ->with('club');

        if (!empty($hiddenClubIds)) {
            $query->whereNotIn('club_id', $hiddenClubIds);
        }

        $tournaments = $query->get()
            ->map(fn($t) => $this->formatTournament($t, $user, true));

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
     * Все завершённые турниры (спектаторский архив)
     * GET /api/mobile/tournaments/completed
     */
    public function completed(Request $request)
    {
        $user = $request->user();
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = Tournament::where('status', 'completed')
            ->orderBy('start_date', 'desc')
            ->with('club');

        if ($dateFrom) {
            $query->where('start_date', '>=', $dateFrom . ' 00:00:00');
        } else {
            $query->where('start_date', '>=', now()->subDays(7)->startOfDay());
        }

        if ($dateTo) {
            $query->where('start_date', '<=', $dateTo . ' 23:59:59');
        }

        $tournaments = $query->get()
            ->map(fn($t) => $this->formatTournament($t, $user));

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

        // Флаг подписки на освободившиеся места
        $data['is_subscribed'] = TournamentSubscription::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

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
                        'level_verified' => (bool) $t->player1->level_verified,
                    ],
                    'player2' => [
                        'id' => $t->player2->id,
                        'name' => $t->player2->name,
                        'level' => $t->player2->level,
                        'rating' => $t->player2->rating,
                        'level_verified' => (bool) $t->player2->level_verified,
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
                    'level_verified' => (bool) $p->level_verified,
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

        // Атомарная проверка мест + запись (защита от race condition)
        $registered = DB::transaction(function () use ($tournament, $user) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            $takenSlots = $tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending'])
                ->count();

            if ($takenSlots >= $tournament->max_participants) {
                return false;
            }

            $tournament->participants()->attach($user->id, ['status' => 'pending']);
            return true;
        });

        if (!$registered) {
            return response()->json(['success' => false, 'message' => 'Все места заняты'], 400);
        }

        // Удаляем подписку — пользователь уже записался
        TournamentSubscription::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->delete();

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

        if ($wasFull && $tournament->status === 'open') {
            $channelService = new \App\Services\TelegramChannelService($tournament->club);
            if ($channelService->isConfigured()) {
                $channelService->postSlotAvailable($tournament);
            }
            $this->notifySubscribersSlotAvailable($tournament);
        }

        return response()->json([
            'success' => true,
            'message' => 'Запись на турнир отменена',
        ]);
    }

    /**
     * Поиск партнёра по номеру телефона
     * POST /api/mobile/tournaments/{id}/search-partner
     */
    public function searchPartner(Request $request, Tournament $tournament)
    {
        $user = $request->user();

        $request->validate([
            'phone' => 'required|string|min:5',
        ]);

        $phone = preg_replace('/\D/', '', $request->input('phone'));

        if (strlen($phone) < 5) {
            return response()->json(['success' => false, 'message' => 'Введите минимум 5 цифр номера'], 400);
        }

        $partners = User::where('role', 'player')
            ->where('id', '!=', $user->id)
            ->where('phone', 'LIKE', "%{$phone}%")
            ->limit(10)
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'level' => $p->level,
                'rating' => $p->rating,
                'phone' => $p->phone,
            ]);

        if ($partners->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Игроки не найдены'], 404);
        }

        return response()->json([
            'success' => true,
            'partners' => $partners,
        ]);
    }

    /**
     * Записать пару на командный турнир
     * POST /api/mobile/tournaments/{id}/register-team
     */
    public function registerTeam(Request $request, Tournament $tournament)
    {
        $user = $request->user();

        $request->validate([
            'partner_id' => 'required|exists:users,id',
        ]);

        if ($tournament->type !== 'team') {
            return response()->json(['success' => false, 'message' => 'Это не командный турнир'], 400);
        }

        if ($tournament->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Регистрация закрыта'], 400);
        }

        $partner = User::find($request->input('partner_id'));

        if ($partner->id === $user->id) {
            return response()->json(['success' => false, 'message' => 'Нельзя выбрать себя в качестве партнёра'], 400);
        }

        // Проверяем уровни
        if ($user->level < $tournament->min_level || $user->level > $tournament->max_level) {
            return response()->json([
                'success' => false,
                'message' => "Ваш уровень ({$user->level}) не подходит. Требуется: {$tournament->min_level} – {$tournament->max_level}",
            ], 400);
        }

        if ($partner->level < $tournament->min_level || $partner->level > $tournament->max_level) {
            return response()->json([
                'success' => false,
                'message' => "Уровень партнёра ({$partner->level}) не подходит. Требуется: {$tournament->min_level} – {$tournament->max_level}",
            ], 400);
        }

        // Проверяем, не зарегистрированы ли уже
        $existingTeam = TournamentTeam::where('tournament_id', $tournament->id)
            ->where(function($q) use ($user, $partner) {
                $q->where(function($q2) use ($user) {
                    $q2->where('player1_id', $user->id)
                       ->orWhere('player2_id', $user->id);
                })->orWhere(function($q2) use ($partner) {
                    $q2->where('player1_id', $partner->id)
                       ->orWhere('player2_id', $partner->id);
                });
            })
            ->first();

        if ($existingTeam) {
            return response()->json(['success' => false, 'message' => 'Вы или ваш партнёр уже зарегистрированы'], 400);
        }

        // Атомарная проверка мест + создание команды (защита от race condition)
        $team = DB::transaction(function () use ($tournament, $user, $partner) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            $maxTeams = $tournament->max_participants / 2;
            $takenTeams = TournamentTeam::where('tournament_id', $tournament->id)
                ->whereIn('status', ['approved', 'pending'])
                ->count();

            if ($takenTeams >= $maxTeams) {
                return null;
            }

            return TournamentTeam::create([
                'tournament_id' => $tournament->id,
                'player1_id' => $user->id,
                'player2_id' => $partner->id,
                'rating_avg' => intval(($user->rating + $partner->rating) / 2),
                'status' => 'pending',
            ]);
        });

        if ($team === null) {
            return response()->json(['success' => false, 'message' => 'Все места заняты'], 400);
        }

        // Удаляем подписки обоих игроков — они уже записались
        TournamentSubscription::where('tournament_id', $tournament->id)
            ->whereIn('user_id', [$user->id, $partner->id])
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Заявка отправлена на модерацию',
            'team' => [
                'id' => $team->id,
                'player1' => ['id' => $user->id, 'name' => $user->name],
                'player2' => ['id' => $partner->id, 'name' => $partner->name],
                'status' => 'pending',
            ],
        ]);
    }

    /**
     * Отменить регистрацию пары
     * POST /api/mobile/tournaments/{id}/cancel-team
     */
    public function cancelTeam(Request $request, Tournament $tournament)
    {
        $user = $request->user();

        if ($tournament->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Отмена невозможна — турнир уже начался'], 400);
        }

        $team = TournamentTeam::where('tournament_id', $tournament->id)
            ->where(function($q) use ($user) {
                $q->where('player1_id', $user->id)
                  ->orWhere('player2_id', $user->id);
            })
            ->first();

        if (!$team) {
            return response()->json(['success' => false, 'message' => 'Вы не зарегистрированы в этом турнире'], 400);
        }

        $team->delete();

        return response()->json([
            'success' => true,
            'message' => 'Регистрация пары отменена',
        ]);
    }

    /**
     * Подписаться на уведомления о свободных местах
     * POST /api/mobile/tournaments/{id}/subscribe
     */
    public function subscribe(Request $request, Tournament $tournament)
    {
        $user = $request->user();

        if ($tournament->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Турнир не открыт для регистрации'], 400);
        }

        if (TournamentSubscription::where('tournament_id', $tournament->id)->where('user_id', $user->id)->exists()) {
            return response()->json(['success' => true, 'message' => 'Вы уже подписаны']);
        }

        TournamentSubscription::create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Вы подписались на уведомления',
        ]);
    }

    /**
     * Отписаться от уведомлений о свободных местах
     * POST /api/mobile/tournaments/{id}/unsubscribe
     */
    public function unsubscribe(Request $request, Tournament $tournament)
    {
        $user = $request->user();

        TournamentSubscription::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Подписка отменена',
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
                'payment_url' => $t->club->payment_url ?? null,
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

        return [
            'rating_change' => $ratingChange->change,
            'rating_after' => $ratingChange->rating_after,
            'place' => $this->getUserPlace($t, $user->id),
        ];
    }

    /**
     * Результаты турнира для текущего пользователя
     * GET /api/mobile/tournaments/{id}/results
     */
    public function results(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        $userId = $request->input('player_id', $user->id);
        $tournament->load('club');

        // Собираем матчи пользователя с rating_change
        $userMatches = [];

        if (in_array($tournament->type, ['americano', 'mexicano'])) {
            $userMatches = $this->getPlayerBasedMatches($tournament, $userId);
        } elseif ($tournament->type === 'team') {
            $userMatches = $this->getTeamBasedMatches($tournament, $userId);
        }

        // Summary
        $wins = count(array_filter($userMatches, fn($m) => $m['result'] === 'win'));
        $losses = count($userMatches) - $wins;

        $ratingHistory = RatingHistory::where('user_id', $userId)
            ->where('tournament_id', $tournament->id)
            ->first();

        // Участники турнира
        $participants = $tournament->participants()
            ->wherePivot('status', '!=', 'cancelled')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'rating' => $u->rating,
                'level' => $u->level,
            ])
            ->sortByDesc('rating')
            ->values();

        return response()->json([
            'success' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'date' => $tournament->start_date->translatedFormat('j F'),
                'club_name' => $tournament->club->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
            ],
            'summary' => [
                'matches_count' => count($userMatches),
                'wins' => $wins,
                'losses' => $losses,
                'rating_change' => $ratingHistory->change ?? 0,
                'place' => $this->getUserPlace($tournament, $userId),
            ],
            'matches' => $userMatches,
            'participants' => $participants,
            'leaderboard' => $this->getLeaderboard($tournament),
            'playoff' => $this->getPlayoff($tournament),
        ]);
    }

    /**
     * Публичная статистика турнира (для спектатора)
     * GET /api/mobile/tournaments/{id}/stats
     */
    public function stats(Request $request, Tournament $tournament)
    {
        $tournament->load('club');

        $participants = $tournament->participants()
            ->wherePivot('status', '!=', 'cancelled')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'rating' => $u->rating,
                'level' => $u->level,
                'level_verified' => (bool) $u->level_verified,
            ])
            ->sortByDesc('rating')
            ->values();

        return response()->json([
            'success' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'date' => $tournament->start_date->translatedFormat('j F Y'),
                'time' => $tournament->start_date->format('H:i'),
                'club_name' => $tournament->club->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
                'status' => $tournament->status,
                'participants_count' => $this->getParticipantsCount($tournament),
            ],
            'participants' => $participants,
            'leaderboard' => $this->getLeaderboard($tournament),
            'team_standings' => $tournament->type === 'team' ? $this->getTeamStandings($tournament) : [],
            'playoff' => $this->getPlayoff($tournament),
            'matches' => $this->getAllCompletedMatches($tournament),
        ]);
    }

    /**
     * Таблица команд для парного турнира
     */
    private function getTeamStandings(Tournament $tournament): array
    {
        $teams = $tournament->teams()
            ->with(['player1', 'player2'])
            ->where('status', 'approved')
            ->get();

        $teamStats = [];
        foreach ($teams as $t) {
            $teamStats[$t->id] = [
                'id' => $t->id,
                'player1' => $t->player1 ? [
                    'id' => $t->player1->id,
                    'name' => $t->player1->name,
                    'level_verified' => (bool) $t->player1->level_verified,
                ] : null,
                'player2' => $t->player2 ? [
                    'id' => $t->player2->id,
                    'name' => $t->player2->name,
                    'level_verified' => (bool) $t->player2->level_verified,
                ] : null,
                'wins' => 0, 'losses' => 0,
                'points_for' => 0, 'points_against' => 0,
            ];
        }

        // Собираем статы по групповым матчам
        foreach ($tournament->teamGroups()->with('matches')->get() as $group) {
            foreach ($group->matches as $m) {
                if ($m->status !== 'completed') continue;
                if (!isset($teamStats[$m->team1_id]) || !isset($teamStats[$m->team2_id])) continue;

                $teamStats[$m->team1_id]['points_for'] += (int) $m->team1_score;
                $teamStats[$m->team1_id]['points_against'] += (int) $m->team2_score;
                $teamStats[$m->team2_id]['points_for'] += (int) $m->team2_score;
                $teamStats[$m->team2_id]['points_against'] += (int) $m->team1_score;

                if ($m->team1_score > $m->team2_score) {
                    $teamStats[$m->team1_id]['wins']++;
                    $teamStats[$m->team2_id]['losses']++;
                } elseif ($m->team2_score > $m->team1_score) {
                    $teamStats[$m->team2_id]['wins']++;
                    $teamStats[$m->team1_id]['losses']++;
                }
            }
        }

        usort($teamStats, function ($a, $b) {
            if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
            return ($b['points_for'] - $b['points_against']) <=> ($a['points_for'] - $a['points_against']);
        });

        return array_values(array_map(function ($s, $i) {
            $s['position'] = $i + 1;
            return $s;
        }, $teamStats, array_keys($teamStats)));
    }

    /**
     * Все сыгранные матчи турнира (групповые + плей-офф)
     */
    private function getAllCompletedMatches(Tournament $tournament): array
    {
        $result = [];

        // Групповые раунды americano
        if ($tournament->type === 'americano') {
            foreach ($tournament->groups()->with(['rounds.matches'])->get() as $group) {
                foreach ($group->rounds as $round) {
                    foreach ($round->matches as $m) {
                        if ($m->status !== 'completed') continue;
                        $result[] = [
                            'stage' => 'Группа ' . ($group->name ?? ''),
                            'round' => $round->round_number,
                            'team1_players' => $this->matchPlayersNames($m, 'team1'),
                            'team2_players' => $this->matchPlayersNames($m, 'team2'),
                            'team1_score' => (int) $m->team1_score,
                            'team2_score' => (int) $m->team2_score,
                        ];
                    }
                }
            }
        }

        // Mexicano
        if ($tournament->type === 'mexicano') {
            foreach ($tournament->mexicanoRounds()->with('matches')->get() as $round) {
                foreach ($round->matches as $m) {
                    if ($m->status !== 'completed') continue;
                    $result[] = [
                        'stage' => 'Раунд ' . $round->round_number,
                        'round' => $round->round_number,
                        'team1_players' => $this->matchPlayersNames($m, 'team1'),
                        'team2_players' => $this->matchPlayersNames($m, 'team2'),
                        'team1_score' => (int) $m->team1_score,
                        'team2_score' => (int) $m->team2_score,
                    ];
                }
            }
        }

        // Team tournament — групповые матчи по командам
        if ($tournament->type === 'team') {
            $teams = $tournament->teams()->with(['player1', 'player2'])->get()->keyBy('id');
            foreach ($tournament->teamGroups()->with('matches')->get() as $group) {
                foreach ($group->matches as $m) {
                    if ($m->status !== 'completed') continue;
                    $t1 = $teams[$m->team1_id] ?? null;
                    $t2 = $teams[$m->team2_id] ?? null;
                    $result[] = [
                        'stage' => 'Группа ' . ($group->name ?? ''),
                        'round' => $m->round_number,
                        'team1_players' => $t1 ? $this->teamPlayersNames($t1) : [],
                        'team2_players' => $t2 ? $this->teamPlayersNames($t2) : [],
                        'team1_score' => (int) $m->team1_score,
                        'team2_score' => (int) $m->team2_score,
                    ];
                }
            }
        }

        // Плей-офф
        foreach ($tournament->playoffMatches()
            ->where('status', 'completed')
            ->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2'])
            ->orderByRaw("FIELD(stage, '1/8 финала', '1/4 финала', 'Полуфинал', 'За 3-е место', 'Финал'), match_number")
            ->get() as $m) {

            // Для team-based плей-офф: игроки берутся из команды
            if (!$m->team1Player1 && $m->team1_id && $tournament->type === 'team') {
                $t1 = TournamentTeam::with(['player1', 'player2'])->find($m->team1_id);
                $t2 = TournamentTeam::with(['player1', 'player2'])->find($m->team2_id);
                $team1Names = $t1 ? $this->teamPlayersNames($t1) : [];
                $team2Names = $t2 ? $this->teamPlayersNames($t2) : [];
            } else {
                $team1Names = array_values(array_filter([
                    $m->team1Player1 ? ['id' => $m->team1Player1->id, 'name' => $m->team1Player1->name] : null,
                    $m->team1Player2 ? ['id' => $m->team1Player2->id, 'name' => $m->team1Player2->name] : null,
                ]));
                $team2Names = array_values(array_filter([
                    $m->team2Player1 ? ['id' => $m->team2Player1->id, 'name' => $m->team2Player1->name] : null,
                    $m->team2Player2 ? ['id' => $m->team2Player2->id, 'name' => $m->team2Player2->name] : null,
                ]));
            }

            $result[] = [
                'stage' => $m->stage_name ?? $m->stage,
                'round' => null,
                'team1_players' => $team1Names,
                'team2_players' => $team2Names,
                'team1_score' => (int) $m->team1_score,
                'team2_score' => (int) $m->team2_score,
            ];
        }

        return $result;
    }

    private function matchPlayersNames($match, string $prefix): array
    {
        $p1Field = "{$prefix}_player1_id";
        $p2Field = "{$prefix}_player2_id";
        $ids = array_filter([$match->$p1Field, $match->$p2Field]);
        if (empty($ids)) return [];

        $users = \App\Models\User::whereIn('id', $ids)->get(['id', 'name']);
        return $users->map(fn($u) => ['id' => $u->id, 'name' => $u->name])->values()->toArray();
    }

    private function teamPlayersNames($team): array
    {
        $result = [];
        if ($team->player1) {
            $result[] = ['id' => $team->player1->id, 'name' => $team->player1->name];
        }
        if ($team->player2) {
            $result[] = ['id' => $team->player2->id, 'name' => $team->player2->name];
        }
        return $result;
    }

    /**
     * Лидерборд турнира (американо/мексикано)
     */
    private function getLeaderboard(Tournament $tournament): array
    {
        if (!in_array($tournament->type, ['americano', 'mexicano'])) {
            return [];
        }

        $playerStats = [];

        if ($tournament->type === 'americano') {
            $groups = $tournament->groups()->with(['players', 'rounds.matches'])->get();

            foreach ($groups as $group) {
                foreach ($group->players as $player) {
                    if (!isset($playerStats[$player->id])) {
                        $playerStats[$player->id] = [
                            'id' => $player->id,
                            'name' => $player->name,
                            'avatar' => $player->avatar,
                            'rating' => $player->rating,
                            'level' => $player->level,
                            'wins' => 0, 'losses' => 0,
                            'points_for' => 0, 'points_against' => 0,
                            'total_points' => (int) ($player->pivot->total_points ?? 0),
                        ];
                    } else {
                        $playerStats[$player->id]['total_points'] += (int) ($player->pivot->total_points ?? 0);
                    }
                }

                foreach ($group->rounds as $round) {
                    foreach ($round->matches as $match) {
                        if ($match->status !== 'completed') continue;
                        $this->countMatchStats($playerStats, $match);
                    }
                }
            }
        } elseif ($tournament->type === 'mexicano') {
            $mexicanoPlayers = $tournament->mexicanoPlayers()->with('user')->get();
            foreach ($mexicanoPlayers as $mp) {
                $user = $mp->user;
                if (!$user) continue;
                $playerStats[$user->id] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                    'rating' => $user->rating,
                    'level' => $user->level,
                    'wins' => 0, 'losses' => 0,
                    'points_for' => 0, 'points_against' => 0,
                    'total_points' => (int) ($mp->total_points ?? 0),
                ];
            }

            $rounds = $tournament->mexicanoRounds()->with('matches')->get();
            foreach ($rounds as $round) {
                foreach ($round->matches as $match) {
                    if ($match->status !== 'completed') continue;
                    $this->countMatchStats($playerStats, $match);
                }
            }
        }

        usort($playerStats, function ($a, $b) {
            if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
            if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
            return ($b['points_for'] - $b['points_against']) <=> ($a['points_for'] - $a['points_against']);
        });

        return array_values(array_map(function ($s, $i) {
            $totalGames = $s['wins'] + $s['losses'];
            $s['position'] = $i + 1;
            $s['win_percent'] = $totalGames > 0 ? (int) round($s['wins'] / $totalGames * 100) : 0;
            return $s;
        }, $playerStats, array_keys($playerStats)));
    }

    private function getPlayoff(Tournament $tournament): array
    {
        if (!$tournament->has_playoff) return [];

        $matches = $tournament->playoffMatches()
            ->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2'])
            ->orderByRaw("FIELD(stage, '1/8 финала', '1/4 финала', 'Полуфинал', 'За 3-е место', 'Финал'), match_number")
            ->get();

        if ($matches->isEmpty()) return [];

        $result = [];
        foreach ($matches as $m) {
            $stageName = $m->stage_name;

            $team1Players = array_filter([
                $m->team1Player1 ? ['id' => $m->team1Player1->id, 'name' => $m->team1Player1->name, 'initials' => mb_strtoupper(mb_substr($m->team1Player1->first_name ?? '', 0, 1) . mb_substr($m->team1Player1->last_name ?? '', 0, 1))] : null,
                $m->team1Player2 ? ['id' => $m->team1Player2->id, 'name' => $m->team1Player2->name, 'initials' => mb_strtoupper(mb_substr($m->team1Player2->first_name ?? '', 0, 1) . mb_substr($m->team1Player2->last_name ?? '', 0, 1))] : null,
            ]);

            $team2Players = array_filter([
                $m->team2Player1 ? ['id' => $m->team2Player1->id, 'name' => $m->team2Player1->name, 'initials' => mb_strtoupper(mb_substr($m->team2Player1->first_name ?? '', 0, 1) . mb_substr($m->team2Player1->last_name ?? '', 0, 1))] : null,
                $m->team2Player2 ? ['id' => $m->team2Player2->id, 'name' => $m->team2Player2->name, 'initials' => mb_strtoupper(mb_substr($m->team2Player2->first_name ?? '', 0, 1) . mb_substr($m->team2Player2->last_name ?? '', 0, 1))] : null,
            ]);

            $result[] = [
                'stage' => $m->stage,
                'stage_name' => $stageName,
                'match_number' => $m->match_number,
                'status' => $m->status,
                'team1_score' => $m->team1_score,
                'team2_score' => $m->team2_score,
                'team1_players' => array_values($team1Players),
                'team2_players' => array_values($team2Players),
            ];
        }

        return $result;
    }

    private function countMatchStats(array &$stats, $match): void
    {
        $team1 = array_filter([$match->team1_player1_id, $match->team1_player2_id]);
        $team2 = array_filter([$match->team2_player1_id, $match->team2_player2_id]);

        foreach ($team1 as $pId) {
            if (!isset($stats[$pId])) continue;
            $stats[$pId]['points_for'] += (int) $match->team1_score;
            $stats[$pId]['points_against'] += (int) $match->team2_score;
            if ($match->team1_score > $match->team2_score) $stats[$pId]['wins']++;
            elseif ($match->team1_score < $match->team2_score) $stats[$pId]['losses']++;
        }

        foreach ($team2 as $pId) {
            if (!isset($stats[$pId])) continue;
            $stats[$pId]['points_for'] += (int) $match->team2_score;
            $stats[$pId]['points_against'] += (int) $match->team1_score;
            if ($match->team2_score > $match->team1_score) $stats[$pId]['wins']++;
            elseif ($match->team2_score < $match->team1_score) $stats[$pId]['losses']++;
        }
    }

    /**
     * Матчи для americano/mexicano (player_id based)
     */
    private function getPlayerBasedMatches(Tournament $tournament, int $userId): array
    {
        // Начальные рейтинги всех игроков
        $ratings = $this->initPlayerRatings($tournament);
        $userMatches = [];
        $roundCounter = 0;

        // Групповые раунды (americano)
        if ($tournament->type === 'americano') {
            // Находим группу пользователя
            $userGroup = null;
            foreach ($tournament->groups as $group) {
                $playerIds = $group->players->pluck('id')->toArray();
                if (in_array($userId, $playerIds)) {
                    $userGroup = $group;
                    break;
                }
            }

            if ($userGroup) {
                foreach ($userGroup->rounds()->orderBy('round_number')->get() as $round) {
                    $roundCounter++;
                    foreach ($round->matches as $match) {
                        if (!$match->isCompleted()) continue;
                        $change = $this->processPlayerMatch($match, $ratings);
                        if ($this->isPlayerInMatch($match, $userId)) {
                            $userMatches[] = $this->formatResultMatch($match, $userId, $roundCounter, $change, false);
                        }
                    }
                }
            }

            // Все группы (для рейтингов не-моей группы, нужны для плей-офф)
            foreach ($tournament->groups as $group) {
                if ($userGroup && $group->id === $userGroup->id) continue;
                foreach ($group->rounds()->orderBy('round_number')->get() as $round) {
                    foreach ($round->matches as $match) {
                        if (!$match->isCompleted()) continue;
                        $this->processPlayerMatch($match, $ratings);
                    }
                }
            }
        }

        // Раунды мексикано
        if ($tournament->type === 'mexicano') {
            foreach ($tournament->mexicanoRounds()->orderBy('round_number')->get() as $round) {
                $roundCounter++;
                foreach ($round->matches as $match) {
                    if (!$match->isCompleted()) continue;
                    $change = $this->processPlayerMatch($match, $ratings);
                    if ($this->isPlayerInMatch($match, $userId)) {
                        $userMatches[] = $this->formatResultMatch($match, $userId, $roundCounter, $change, false);
                    }
                }
            }
        }

        // Плей-офф (player-based)
        $playoffMatches = $tournament->playoffMatches()
            ->where('status', 'completed')
            ->whereNotNull('team1_player1_id')
            ->orderBy('id')
            ->get();

        $totalRounds = $roundCounter + $playoffMatches->count();

        foreach ($playoffMatches as $match) {
            $roundCounter++;
            $change = $this->processPlayerMatch($match, $ratings);
            if ($this->isPlayerInMatch($match, $userId)) {
                $isFinal = ($roundCounter === $totalRounds);
                $userMatches[] = $this->formatResultMatch($match, $userId, $roundCounter, $change, $isFinal, $match->stage_name);
            }
        }

        return $userMatches;
    }

    /**
     * Матчи для team tournament (team_id based)
     */
    private function getTeamBasedMatches(Tournament $tournament, int $userId): array
    {
        $ratings = $this->initTeamPlayerRatings($tournament);

        $myTeamIds = TournamentTeam::where('tournament_id', $tournament->id)
            ->where(function ($q) use ($userId) {
                $q->where('player1_id', $userId)->orWhere('player2_id', $userId);
            })
            ->pluck('id');

        $userMatches = [];
        $roundCounter = 0;

        // Моя группа
        $myGroup = null;
        foreach ($tournament->teamGroups as $group) {
            $teamIdsInGroup = $group->standings()->pluck('team_id');
            if ($teamIdsInGroup->intersect($myTeamIds)->isNotEmpty()) {
                $myGroup = $group;
                break;
            }
        }

        if ($myGroup) {
            $maxRound = $myGroup->matches()->max('round_number') ?? 0;
            for ($r = 1; $r <= $maxRound; $r++) {
                $roundCounter++;
                foreach ($myGroup->matches()->where('round_number', $r)->where('status', 'completed')->get() as $match) {
                    $change = $this->processTeamMatch($match, $ratings);
                    if ($myTeamIds->contains($match->team1_id) || $myTeamIds->contains($match->team2_id)) {
                        $userMatches[] = $this->formatTeamResultMatch($match, $userId, $myTeamIds, $roundCounter, $change, false);
                    }
                }
            }
        }

        // Все остальные группы (для рейтингов)
        foreach ($tournament->teamGroups as $group) {
            if ($myGroup && $group->id === $myGroup->id) continue;
            foreach ($group->matches()->where('status', 'completed')->orderBy('round_number')->get() as $match) {
                $this->processTeamMatch($match, $ratings);
            }
        }

        // Плей-офф (team-based)
        $playoffMatches = $tournament->playoffMatches()
            ->where('status', 'completed')
            ->whereNull('team1_player1_id')
            ->orderBy('id')
            ->get();

        $totalRounds = $roundCounter + $playoffMatches->count();

        foreach ($playoffMatches as $match) {
            $roundCounter++;
            $change = $this->processTeamMatch($match, $ratings);
            if ($myTeamIds->contains($match->team1_id) || $myTeamIds->contains($match->team2_id)) {
                $isFinal = ($roundCounter === $totalRounds);
                $userMatches[] = $this->formatTeamResultMatch($match, $userId, $myTeamIds, $roundCounter, $change, $isFinal, $match->stage_name);
            }
        }

        return $userMatches;
    }

    /**
     * Инициализация рейтингов для americano/mexicano
     */
    private function initPlayerRatings(Tournament $tournament): array
    {
        $ratings = [];

        if ($tournament->type === 'americano') {
            foreach ($tournament->groups as $group) {
                foreach ($group->players as $player) {
                    $ratingBefore = (int) $player->pivot->rating_before;
                    $ratings[$player->id] = $ratingBefore > 0 ? $ratingBefore : (int) $player->rating;
                }
            }
        } elseif ($tournament->type === 'mexicano') {
            foreach ($tournament->mexicanoPlayers()->with('user')->get() as $mp) {
                $ratings[$mp->user_id] = (int) $mp->rating_before;
            }
        }

        return $ratings;
    }

    /**
     * Инициализация рейтингов для team tournament
     */
    private function initTeamPlayerRatings(Tournament $tournament): array
    {
        $ratings = [];
        foreach ($tournament->teams()->with(['player1', 'player2'])->get() as $team) {
            $ratings[$team->player1_id] = (int) $team->player1->rating;
            $ratings[$team->player2_id] = (int) $team->player2->rating;
        }

        // Для завершённых турниров берём rating_before из истории
        if ($tournament->status === 'completed') {
            $histories = RatingHistory::where('tournament_id', $tournament->id)->get();
            foreach ($histories as $h) {
                $ratings[$h->user_id] = (int) $h->rating_before;
            }
        }

        return $ratings;
    }

    /**
     * Обработать матч (player-based) и вернуть change
     */
    private function processPlayerMatch($match, array &$ratings): array
    {
        $p1_1 = $match->team1_player1_id;
        $p1_2 = $match->team1_player2_id;
        $p2_1 = $match->team2_player1_id;
        $p2_2 = $match->team2_player2_id;

        $team1Rating = (($ratings[$p1_1] ?? 1000) + ($ratings[$p1_2] ?? 1000)) / 2;
        $team2Rating = (($ratings[$p2_1] ?? 1000) + ($ratings[$p2_2] ?? 1000)) / 2;

        $result = $this->calculateRatingChange($team1Rating, $team2Rating, $match->team1_score, $match->team2_score);

        $ratings[$p1_1] = $this->applyRatingChange($ratings[$p1_1] ?? 1000, $result['change1']);
        $ratings[$p1_2] = $this->applyRatingChange($ratings[$p1_2] ?? 1000, $result['change1']);
        $ratings[$p2_1] = $this->applyRatingChange($ratings[$p2_1] ?? 1000, $result['change2']);
        $ratings[$p2_2] = $this->applyRatingChange($ratings[$p2_2] ?? 1000, $result['change2']);

        return $result;
    }

    /**
     * Обработать матч (team-based) и вернуть change
     */
    private function processTeamMatch($match, array &$ratings): array
    {
        $team1 = $match->team1;
        $team2 = $match->team2;
        if (!$team1 || !$team2) return ['change1' => 0, 'change2' => 0];

        $team1Rating = (($ratings[$team1->player1_id] ?? 1000) + ($ratings[$team1->player2_id] ?? 1000)) / 2;
        $team2Rating = (($ratings[$team2->player1_id] ?? 1000) + ($ratings[$team2->player2_id] ?? 1000)) / 2;

        $result = $this->calculateRatingChange($team1Rating, $team2Rating, $match->team1_score, $match->team2_score);

        $ratings[$team1->player1_id] = $this->applyRatingChange($ratings[$team1->player1_id] ?? 1000, $result['change1']);
        $ratings[$team1->player2_id] = $this->applyRatingChange($ratings[$team1->player2_id] ?? 1000, $result['change1']);
        $ratings[$team2->player1_id] = $this->applyRatingChange($ratings[$team2->player1_id] ?? 1000, $result['change2']);
        $ratings[$team2->player2_id] = $this->applyRatingChange($ratings[$team2->player2_id] ?? 1000, $result['change2']);

        return $result;
    }

    private function isPlayerInMatch($match, int $userId): bool
    {
        return in_array($userId, [
            $match->team1_player1_id,
            $match->team1_player2_id,
            $match->team2_player1_id,
            $match->team2_player2_id,
        ]);
    }

    /**
     * Форматировать матч (player-based) для результатов
     */
    private function formatResultMatch($match, int $userId, int $roundNum, array $change, bool $isFinal, ?string $stageName = null): array
    {
        $isTeam1 = in_array($userId, [$match->team1_player1_id, $match->team1_player2_id]);

        $myScore = $isTeam1 ? $match->team1_score : $match->team2_score;
        $oppScore = $isTeam1 ? $match->team2_score : $match->team1_score;
        $ratingChange = $isTeam1 ? $change['change1'] : $change['change2'];

        $partner = $isTeam1
            ? ($match->team1_player1_id == $userId ? $match->team1Player2 : $match->team1Player1)
            : ($match->team2_player1_id == $userId ? $match->team2Player2 : $match->team2Player1);

        $opponents = $isTeam1
            ? [$match->team2Player1, $match->team2Player2]
            : [$match->team1Player1, $match->team1Player2];

        $me = \App\Models\User::find($userId);

        $roundName = $stageName
            ? 'РАУНД ' . $roundNum . ' · ' . mb_strtoupper($stageName)
            : 'РАУНД ' . $roundNum;

        return [
            'id' => $match->id,
            'round' => $roundNum,
            'round_name' => $roundName,
            'is_final' => $isFinal,
            'score_my' => $myScore,
            'score_opponent' => $oppScore,
            'result' => $myScore > $oppScore ? 'win' : 'loss',
            'rating_change' => $ratingChange,
            'my_team' => array_values(array_filter([
                $me ? $this->formatPlayerShort($me) : null,
                $partner ? $this->formatPlayerShort($partner) : null,
            ])),
            'opponent_team' => array_values(array_filter(array_map(
                fn($p) => $p ? $this->formatPlayerShort($p) : null,
                $opponents
            ))),
        ];
    }

    /**
     * Форматировать матч (team-based) для результатов
     */
    private function formatTeamResultMatch($match, int $userId, $myTeamIds, int $roundNum, array $change, bool $isFinal, ?string $stageName = null): array
    {
        $isTeam1 = $myTeamIds->contains($match->team1_id);

        $myTeam = $isTeam1 ? $match->team1 : $match->team2;
        $oppTeam = $isTeam1 ? $match->team2 : $match->team1;

        $myScore = $isTeam1 ? $match->team1_score : $match->team2_score;
        $oppScore = $isTeam1 ? $match->team2_score : $match->team1_score;
        $ratingChange = $isTeam1 ? $change['change1'] : $change['change2'];

        $partner = $myTeam->player1_id == $userId ? $myTeam->player2 : $myTeam->player1;
        $me = $myTeam->player1_id == $userId ? $myTeam->player1 : $myTeam->player2;

        $roundName = $stageName
            ? 'РАУНД ' . $roundNum . ' · ' . mb_strtoupper($stageName)
            : 'РАУНД ' . $roundNum;

        return [
            'id' => $match->id,
            'round' => $roundNum,
            'round_name' => $roundName,
            'is_final' => $isFinal,
            'score_my' => $myScore,
            'score_opponent' => $oppScore,
            'result' => $myScore > $oppScore ? 'win' : 'loss',
            'rating_change' => $ratingChange,
            'my_team' => array_values(array_filter([
                $me ? $this->formatPlayerShort($me) : null,
                $partner ? $this->formatPlayerShort($partner) : null,
            ])),
            'opponent_team' => array_values(array_filter([
                $oppTeam->player1 ? $this->formatPlayerShort($oppTeam->player1) : null,
                $oppTeam->player2 ? $this->formatPlayerShort($oppTeam->player2) : null,
            ])),
        ];
    }

    /**
     * Место пользователя: 1 — выиграл финал, 2 — проиграл финал, null — нет плей-офф или не в финале
     */
    private function getUserPlace(Tournament $tournament, int $userId): ?int
    {
        $myTeamIds = TournamentTeam::where('tournament_id', $tournament->id)
            ->where(function ($q) use ($userId) {
                $q->where('player1_id', $userId)->orWhere('player2_id', $userId);
            })
            ->pluck('id');

        // Проверяем финал
        $finalMatch = $tournament->playoffMatches()
            ->whereIn('stage', ['final', 'Финал'])
            ->where('status', 'completed')
            ->first();

        if ($finalMatch) {
            // Player-based (americano/mexicano)
            if ($finalMatch->team1_player1_id) {
                $inTeam1 = in_array($userId, [$finalMatch->team1_player1_id, $finalMatch->team1_player2_id]);
                $inTeam2 = in_array($userId, [$finalMatch->team2_player1_id, $finalMatch->team2_player2_id]);

                if ($inTeam1 || $inTeam2) {
                    $team1Won = $finalMatch->team1_score > $finalMatch->team2_score;
                    return ($inTeam1 && $team1Won) || ($inTeam2 && !$team1Won) ? 1 : 2;
                }
            }

            // Team-based (team)
            if ($finalMatch->team1_id && $myTeamIds->isNotEmpty()) {
                $inTeam1 = $myTeamIds->contains($finalMatch->team1_id);
                $inTeam2 = $myTeamIds->contains($finalMatch->team2_id);

                if ($inTeam1 || $inTeam2) {
                    return $finalMatch->winner_id && $myTeamIds->contains($finalMatch->winner_id) ? 1 : 2;
                }
            }

            // Полуфинал — 3-4 место
            $semiMatches = $tournament->playoffMatches()
                ->whereIn('stage', ['semi', 'Полуфинал'])
                ->where('status', 'completed')
                ->get();

            foreach ($semiMatches as $semi) {
                $inSemi = in_array($userId, [
                    $semi->team1_player1_id, $semi->team1_player2_id,
                    $semi->team2_player1_id, $semi->team2_player2_id,
                ]);
                if ($inSemi) return 3;

                if ($myTeamIds->isNotEmpty() && ($myTeamIds->contains($semi->team1_id) || $myTeamIds->contains($semi->team2_id))) {
                    return 3;
                }
            }
        }

        // По лидерборду (americano/mexicano)
        if (in_array($tournament->type, ['americano', 'mexicano'])) {
            if ($tournament->type === 'mexicano') {
                $players = $tournament->mexicanoPlayers()->orderBy('total_points', 'desc')->get();
                foreach ($players as $i => $mp) {
                    if ($mp->user_id === $userId) return $i + 1;
                }
            } else {
                $groups = $tournament->groups()->with('players')->get();
                $allPlayers = collect();
                foreach ($groups as $group) {
                    foreach ($group->players as $player) {
                        $existing = $allPlayers->firstWhere('id', $player->id);
                        if ($existing) {
                            $allPlayers = $allPlayers->map(fn($p) => $p['id'] === $player->id
                                ? array_merge($p, ['total_points' => $p['total_points'] + (int)($player->pivot->total_points ?? 0)])
                                : $p);
                        } else {
                            $allPlayers->push([
                                'id' => $player->id,
                                'total_points' => (int) ($player->pivot->total_points ?? 0),
                            ]);
                        }
                    }
                }
                $sorted = $allPlayers->sortByDesc('total_points')->values();
                $index = $sorted->search(fn($p) => $p['id'] === $userId);
                if ($index !== false) return $index + 1;
            }
        }

        // Team турнир — место по группе
        if ($tournament->type === 'team' && $myTeamIds->isNotEmpty()) {
            $groups = $tournament->groups()->with('teams')->get();
            foreach ($groups as $group) {
                $sorted = $group->teams->sortByDesc(fn($t) => $t->pivot->points ?? 0)->values();
                foreach ($sorted as $i => $team) {
                    if ($myTeamIds->contains($team->id)) return $i + 1;
                }
            }
        }

        return null;
    }

    private function formatPlayerShort($player): array
    {
        $parts = explode(' ', trim($player->name));
        $initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));

        return [
            'id' => $player->id,
            'name' => $player->name,
            'initials' => $initials,
        ];
    }

    /**
     * Уведомить подписчиков о свободном месте в турнире
     */
    public static function notifySubscribersSlotAvailable(Tournament $tournament): void
    {
        $subscribers = TournamentSubscription::where('tournament_id', $tournament->id)
            ->with('user')
            ->get();

        if ($subscribers->isEmpty()) {
            return;
        }

        $date = $tournament->start_date->format('d.m.Y H:i');
        $title = 'Освободилось место!';
        $body = "В турнире «{$tournament->name}» ({$date}) освободилось место. Успейте записаться!";

        $fcm = app(\App\Services\FCMNotificationService::class);

        foreach ($subscribers as $subscription) {
            $user = $subscription->user;
            if (!$user) continue;

            \App\Models\Notification::create([
                'user_id' => $user->id,
                'title' => $title,
                'body' => $body,
                'type' => 'slot_available',
                'category' => 'tournament',
                'data' => ['tournament_id' => $tournament->id],
            ]);

            $fcm->sendToUser($user, $title, $body, [
                'type' => 'slot_available',
                'tournament_id' => (string) $tournament->id,
            ]);
        }
    }

    /**
     * Live-данные турнира для экрана «Идёт сейчас»: группы, таблицы
     * лидеров, раунды и матчи. Только чтение — счёт не редактируется.
     * GET /api/mobile/tournaments/{id}/live
     */
    public function live(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        $tournament->load('club');

        // Пока поддерживаем только Американо
        if ($tournament->type !== 'americano') {
            return response()->json([
                'success' => false,
                'message' => 'Live-режим пока доступен только для Американо',
            ], 400);
        }

        $groups = [];
        $tournamentGroups = $tournament->groups()
            ->with(['players', 'rounds.matches'])
            ->orderBy('id')
            ->get();

        foreach ($tournamentGroups as $group) {
            // Статистика игроков группы
            $playerStats = [];
            foreach ($group->players as $p) {
                $playerStats[$p->id] = [
                    'id' => $p->id,
                    'name' => $p->name,
                    'avatar' => $p->avatar,
                    'rating' => $p->rating,
                    'level' => $p->level,
                    'wins' => 0,
                    'losses' => 0,
                    'draws' => 0,
                    'points_for' => 0,
                    'points_against' => 0,
                    'total_points' => (int) ($p->pivot->total_points ?? 0),
                ];
            }

            // Считаем по завершённым матчам
            foreach ($group->rounds as $round) {
                foreach ($round->matches as $match) {
                    if ($match->status !== 'completed') continue;
                    $this->countMatchStats($playerStats, $match);
                    // Ничьи отдельно
                    if ((int) $match->team1_score === (int) $match->team2_score) {
                        foreach ([$match->team1_player1_id, $match->team1_player2_id, $match->team2_player1_id, $match->team2_player2_id] as $pId) {
                            if (isset($playerStats[$pId])) $playerStats[$pId]['draws']++;
                        }
                    }
                }
            }

            // Сортируем: очки → победы → разница мячей
            uasort($playerStats, function ($a, $b) {
                if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
                if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
                return ($b['points_for'] - $b['points_against']) <=> ($a['points_for'] - $a['points_against']);
            });

            $position = 1;
            $leaderboard = [];
            foreach ($playerStats as $s) {
                $totalGames = $s['wins'] + $s['losses'] + $s['draws'];
                $diff = $s['points_for'] - $s['points_against'];
                // % мячей: забитых от всех мячей в матчах игрока (как в админке)
                $totalBalls = $s['points_for'] + $s['points_against'];
                $ballPercent = $totalBalls > 0
                    ? (int) round($s['points_for'] / $totalBalls * 100)
                    : 0;
                $leaderboard[] = array_merge($s, [
                    'position' => $position++,
                    'games_played' => $totalGames,
                    'point_diff' => $diff,
                    'win_percent' => $totalGames > 0 ? (int) round($s['wins'] / $totalGames * 100) : 0,
                    'ball_percent' => $ballPercent,
                    'is_me' => $user && (int) $s['id'] === (int) $user->id,
                ]);
            }

            // Раунды + матчи
            $rounds = [];
            foreach ($group->rounds as $round) {
                $matches = [];
                foreach ($round->matches as $m) {
                    $userId = $user ? (int) $user->id : null;
                    $t1HasMe = $userId !== null && in_array($userId, [
                        (int) $m->team1_player1_id,
                        (int) $m->team1_player2_id,
                    ], true);
                    $t2HasMe = $userId !== null && in_array($userId, [
                        (int) $m->team2_player1_id,
                        (int) $m->team2_player2_id,
                    ], true);

                    $matches[] = [
                        'id' => $m->id,
                        'court_number' => $m->court_number,
                        'status' => $m->status,
                        'team1' => [
                            'player1' => $this->formatPlayerForLive($m->team1_player1_id, $playerStats, $tournament),
                            'player2' => $this->formatPlayerForLive($m->team1_player2_id, $playerStats, $tournament),
                            'score' => $m->status === 'completed' ? (int) $m->team1_score : null,
                            'has_me' => $t1HasMe,
                        ],
                        'team2' => [
                            'player1' => $this->formatPlayerForLive($m->team2_player1_id, $playerStats, $tournament),
                            'player2' => $this->formatPlayerForLive($m->team2_player2_id, $playerStats, $tournament),
                            'score' => $m->status === 'completed' ? (int) $m->team2_score : null,
                            'has_me' => $t2HasMe,
                        ],
                        'has_me' => $t1HasMe || $t2HasMe,
                    ];
                }
                $rounds[] = [
                    'id' => $round->id,
                    'round_number' => $round->round_number,
                    'status' => $round->status, // pending / in_progress / completed
                    'matches' => $matches,
                ];
            }

            $groups[] = [
                'id' => $group->id,
                'name' => $group->name,
                'leaderboard' => array_values($leaderboard),
                'rounds' => $rounds,
            ];
        }

        return response()->json([
            'success' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'date' => $tournament->start_date->translatedFormat('j F'),
                'time' => $tournament->start_date->format('H:i'),
                'club_name' => $tournament->club->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
                'status' => $tournament->status,
                'has_playoff' => (bool) $tournament->has_playoff,
            ],
            'groups' => $groups,
        ]);
    }

    /**
     * Хелпер: данные игрока для live-матча
     */
    private function formatPlayerForLive(?int $playerId, array $playerStats, Tournament $tournament): ?array
    {
        if (!$playerId) return null;
        if (isset($playerStats[$playerId])) {
            return [
                'id' => $playerStats[$playerId]['id'],
                'name' => $playerStats[$playerId]['name'],
                'avatar' => $playerStats[$playerId]['avatar'],
            ];
        }
        // Игрок из другой группы — подгрузим из participants
        $p = $tournament->participants()->where('users.id', $playerId)->first();
        if ($p) {
            return ['id' => $p->id, 'name' => $p->name, 'avatar' => $p->avatar];
        }
        return ['id' => $playerId, 'name' => '?', 'avatar' => null];
    }
}

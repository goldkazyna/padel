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
            $channelService = new \App\Services\TelegramChannelService();
            $channelService->postSlotAvailable($tournament);
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
        $userId = $user->id;
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
        ]);
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
        $finalMatch = $tournament->playoffMatches()
            ->whereIn('stage', ['final', 'Финал'])
            ->where('status', 'completed')
            ->first();

        if (!$finalMatch) return null;

        // Player-based playoff (americano/mexicano)
        if ($finalMatch->team1_player1_id) {
            $inTeam1 = in_array($userId, [$finalMatch->team1_player1_id, $finalMatch->team1_player2_id]);
            $inTeam2 = in_array($userId, [$finalMatch->team2_player1_id, $finalMatch->team2_player2_id]);

            if (!$inTeam1 && !$inTeam2) return null;

            $team1Won = $finalMatch->team1_score > $finalMatch->team2_score;
            return ($inTeam1 && $team1Won) || ($inTeam2 && !$team1Won) ? 1 : 2;
        }

        // Team-based playoff (team)
        if ($finalMatch->team1_id && $finalMatch->team2_id) {
            $myTeamIds = TournamentTeam::where('tournament_id', $tournament->id)
                ->where(function ($q) use ($userId) {
                    $q->where('player1_id', $userId)->orWhere('player2_id', $userId);
                })
                ->pluck('id');

            $inTeam1 = $myTeamIds->contains($finalMatch->team1_id);
            $inTeam2 = $myTeamIds->contains($finalMatch->team2_id);

            if (!$inTeam1 && !$inTeam2) return null;

            return $finalMatch->winner_id && $myTeamIds->contains($finalMatch->winner_id) ? 1 : 2;
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
                'data' => ['tournament_id' => $tournament->id],
            ]);

            $fcm->sendToUser($user, $title, $body, [
                'type' => 'slot_available',
                'tournament_id' => (string) $tournament->id,
            ]);
        }
    }
}

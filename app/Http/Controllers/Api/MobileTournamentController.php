<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\FormatsTournaments;
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
    use FormatsTournaments;
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
            ->whereNull('creator_id') // личные (приватные) турниры не показываем
            ->whereHas('club', fn($q) => $q->where('is_test', false))
            ->orderBy('start_date', 'asc')
            ->with(['club', 'venueClub']);

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
    /**
     * Ближайшая неоплаченная заявка с запущенным таймером модерации
     * (соло или команда) — для баннера на экране профиля.
     * GET /api/mobile/tournaments/moderation-pending
     */
    public function moderationPending(Request $request)
    {
        $userId = $request->user()->id;

        $solo = DB::table('tournament_participants')
            ->join('tournaments', 'tournaments.id', '=', 'tournament_participants.tournament_id')
            ->where('tournament_participants.user_id', $userId)
            ->where('tournament_participants.status', 'pending')
            ->whereNotNull('tournament_participants.moderation_deadline')
            ->where('tournaments.status', 'open')
            ->select('tournaments.id', 'tournaments.name', 'tournaments.start_date', 'tournament_participants.moderation_deadline as deadline')
            ->get();

        $team = DB::table('tournament_teams')
            ->join('tournaments', 'tournaments.id', '=', 'tournament_teams.tournament_id')
            ->where(function ($q) use ($userId) {
                $q->where('tournament_teams.player1_id', $userId)
                  ->orWhere('tournament_teams.player2_id', $userId);
            })
            ->where('tournament_teams.status', 'pending')
            ->whereNotNull('tournament_teams.moderation_deadline')
            ->where('tournaments.status', 'open')
            ->select('tournaments.id', 'tournaments.name', 'tournaments.start_date', 'tournament_teams.moderation_deadline as deadline')
            ->get();

        $nearest = $solo->concat($team)
            ->sortBy('deadline')
            ->first();

        return response()->json([
            'success' => true,
            'pending' => $nearest ? [
                'tournament_id' => (int) $nearest->id,
                'name' => $nearest->name,
                'date' => \Carbon\Carbon::parse($nearest->start_date)->locale('ru')->isoFormat('D MMMM, HH:mm'),
                'deadline' => \Carbon\Carbon::parse($nearest->deadline)->toIso8601String(),
            ] : null,
        ]);
    }

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
            ->with(['club', 'venueClub'])
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
            ->with(['club', 'venueClub'])
            ->get()
            ->map(fn($t) => $this->formatArchiveTournament($t, $user));

        return response()->json([
            'success' => true,
            'tournaments' => $tournaments,
        ]);
    }

    /**
     * Отменённые турниры — все турниры со статусом cancelled.
     * Видны всем юзерам, аналогично вкладке «Открытые».
     * Тестовые клубы отфильтрованы.
     * GET /api/mobile/tournaments/cancelled
     */
    public function cancelled(Request $request)
    {
        $user = $request->user();

        $tournaments = Tournament::where('status', 'cancelled')
            ->whereHas('club', fn($q) => $q->where('is_test', false))
            ->orderBy('start_date', 'desc')
            ->with(['club', 'venueClub'])
            ->get()
            ->map(fn($t) => $this->formatTournament($t, $user));

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
        $clubId = $request->input('club_id');

        $query = Tournament::where('status', 'completed')
            ->whereHas('club', fn($q) => $q->where('is_test', false))
            ->orderBy('start_date', 'desc')
            ->with(['club', 'venueClub']);

        // Прошедшие турниры конкретного клуба — показываем ВСЕ за всё время
        // (без окна «последние 7 дней»).
        if ($clubId) {
            $query->where('club_id', $clubId);
        } else {
            // Турнир попадает в период если в диапазон попадает start_date ИЛИ
            // updated_at (т.е. дата фактического завершения). Это нужно потому
            // что админ может поставить start_date в будущем, а закончить турнир
            // раньше — без OR-условия такие турниры пропадают из архива.
            $from = $dateFrom ? $dateFrom . ' 00:00:00' : now()->subDays(7)->startOfDay();
            $to = $dateTo ? $dateTo . ' 23:59:59' : null;

            $query->where(function ($q) use ($from, $to) {
                $q->where(function ($qq) use ($from, $to) {
                    $qq->where('start_date', '>=', $from);
                    if ($to) $qq->where('start_date', '<=', $to);
                })->orWhere(function ($qq) use ($from, $to) {
                    $qq->where('updated_at', '>=', $from);
                    if ($to) $qq->where('updated_at', '<=', $to);
                });
            });
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
        $tournament->load(['club', 'venueClub']);

        $data = $this->formatTournament($tournament, $user, true);

        // Блок чата турнира (права + непрочитанные) — только в деталях, не в списках.
        $data['chat'] = $this->tournamentChatBlock($tournament, $user);

        // Флаг подписки на освободившиеся места
        $data['is_subscribed'] = TournamentSubscription::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();

        // Хелперы форматирования
        $formatPlayer = fn($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'level' => $u->level,
            'rating' => $u->rating,
            'level_verified' => (bool) $u->level_verified,
            'avatar' => $u->avatar,
        ];
        $formatParticipant = fn($p, $status = null) => array_merge(
            $formatPlayer($p),
            ['status' => $status ?? $p->pivot->status],
        );
        $formatTeam = fn($t, $status = null) => [
            'id' => $t->id,
            'player1' => $formatPlayer($t->player1),
            'player2' => $formatPlayer($t->player2),
            'status' => $status ?? $t->status,
        ];

        // Добавляем участников/команды (основные + лист ожидания)
        if (!$tournament->usesSoloRegistration()) {
            $data['teams'] = $tournament->teams()
                ->with(['player1', 'player2'])
                ->whereIn('status', ['approved', 'pending'])
                ->get()
                ->map(fn($t) => $formatTeam($t));
            $data['waitlist_teams'] = $tournament->teams()
                ->with(['player1', 'player2'])
                ->where('status', 'waiting')
                ->orderBy('created_at')
                ->get()
                ->map(fn($t) => $formatTeam($t, 'waiting'));
        } else {
            $data['participants'] = $tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending'])
                ->get()
                ->map(fn($p) => $formatParticipant($p));
            $data['waitlist_participants'] = $tournament->participants()
                ->wherePivot('status', 'waiting')
                ->orderBy('tournament_participants.created_at')
                ->get()
                ->map(fn($p) => $formatParticipant($p, 'waiting'));
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

        $request->validate([
            'friend_user_id' => 'nullable|integer|exists:users,id',
            'confirm_waitlist' => 'nullable|boolean',
        ]);
        $friendId = $request->input('friend_user_id');
        $confirmWaitlist = $request->boolean('confirm_waitlist');

        if ($tournament->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Турнир не открыт для регистрации'], 400);
        }

        // Активной считается только запись в статусах registered/pending/waiting.
        // Ранее отменённая (cancelled) запись не блокирует повторную регистрацию.
        if ($tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending', 'waiting'])
            ->where('user_id', $user->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Вы уже записаны на этот турнир'], 400);
        }

        if ($tournament->verified_only && !$user->level_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Турнир только для верифицированных игроков',
            ], 400);
        }

        if ($user->level < $tournament->min_level || $user->level > $tournament->max_level) {
            return response()->json([
                'success' => false,
                'message' => "Ваш уровень ({$user->level}) не подходит. Требуется: {$tournament->min_level} – {$tournament->max_level}",
            ], 400);
        }

        // Валидация друга (если указан)
        $friend = null;
        if ($friendId) {
            if ((int) $friendId === (int) $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нельзя записать самого себя как друга',
                ], 400);
            }
            $friend = User::find($friendId);
            if (!$friend) {
                return response()->json(['success' => false, 'message' => 'Друг не найден'], 404);
            }
            if ($tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending', 'waiting'])
                ->where('user_id', $friend->id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => "{$friend->name} уже записан на этот турнир",
                ], 400);
            }
            if ($tournament->verified_only && !$friend->level_verified) {
                return response()->json([
                    'success' => false,
                    'message' => "{$friend->name} не верифицирован — турнир только для верифицированных игроков",
                ], 400);
            }
            if ($friend->level < $tournament->min_level || $friend->level > $tournament->max_level) {
                return response()->json([
                    'success' => false,
                    'message' => "Уровень {$friend->name} ({$friend->level}) не подходит. Требуется: {$tournament->min_level} – {$tournament->max_level}",
                ], 400);
            }
        }

        // Атомарная проверка мест + запись (защита от race condition).
        // Если друг указан — нужно ≥ 2 свободных мест, записываем обоих.
        // Если основных мест нет, но включён confirm_waitlist и в листе ожидания есть место —
        // ставим в очередь со статусом 'waiting'.
        $needSlots = $friend ? 2 : 1;
        $deadline = $tournament->moderationDeadline();
        $outcome = DB::transaction(function () use ($tournament, $user, $friend, $needSlots, $confirmWaitlist, $deadline) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            // Подчищаем ранее отменённые записи, чтобы attach не нарушил
            // уникальный индекс [tournament_id, user_id].
            $tournament->participants()->wherePivot('status', 'cancelled')->detach($user->id);
            if ($friend) {
                $tournament->participants()->wherePivot('status', 'cancelled')->detach($friend->id);
            }

            $takenSlots = $tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending'])
                ->count();

            $hasMain = ($takenSlots + $needSlots) <= $tournament->max_participants;

            if ($hasMain) {
                $pivot = ['status' => 'pending'];
                if ($deadline) $pivot['moderation_deadline'] = $deadline;
                $tournament->participants()->attach($user->id, $pivot);
                if ($friend) {
                    $tournament->participants()->attach($friend->id, $pivot);
                }
                return 'registered';
            }

            // Основные места кончились — пробуем waitlist
            $waitlistTaken = $tournament->participants()
                ->wherePivot('status', 'waiting')
                ->count();
            $waitlistCapacity = (int) ($tournament->waitlist_size ?? 0);
            $hasWaitlist = $waitlistCapacity > 0
                && ($waitlistTaken + $needSlots) <= $waitlistCapacity;

            if (!$hasWaitlist) {
                return 'no_space';
            }

            if (!$confirmWaitlist) {
                return 'needs_confirm';
            }

            $tournament->participants()->attach($user->id, ['status' => 'waiting']);
            if ($friend) {
                $tournament->participants()->attach($friend->id, ['status' => 'waiting']);
            }
            return 'waitlisted';
        });

        if ($outcome === 'no_space') {
            return response()->json([
                'success' => false,
                'message' => $needSlots === 2 ? 'Не хватает мест для двоих' : 'Все места заняты',
            ], 400);
        }

        if ($outcome === 'needs_confirm') {
            $position = $tournament->participants()
                ->wherePivot('status', 'waiting')
                ->count() + 1;
            return response()->json([
                'success' => false,
                'requires_waitlist_confirmation' => true,
                'message' => 'Все места заняты. Встать в лист ожидания?',
                'waitlist_position' => $position,
                'waitlist_size' => (int) ($tournament->waitlist_size ?? 0),
            ]);
        }

        $isWaitlisted = $outcome === 'waitlisted';

        // Журнал записей: фиксируем запись игрока (и друга, если записал пару).
        if (in_array($outcome, ['registered', 'waitlisted'], true)) {
            \App\Models\TournamentRegistrationLog::record($tournament->id, $user->id, 'registered');
            if ($friend) {
                \App\Models\TournamentRegistrationLog::record($tournament->id, $friend->id, 'registered');
            }
        }

        // Пуш «заявка на модерации» себе — только при успешной основной записи
        // (не для листа ожидания) и только если у турнира включён таймер модерации.
        if ($outcome === 'registered' && $deadline) {
            app(\App\Services\ModerationNotifier::class)->pending($user, $tournament, $deadline);
        }

        // Удаляем подписку — пользователь уже записался
        TournamentSubscription::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->delete();
        if ($friend) {
            TournamentSubscription::where('tournament_id', $tournament->id)
                ->where('user_id', $friend->id)
                ->delete();

            // Пуш + запись в колокольчик — другу, что его записали
            $date = $tournament->start_date->format('d.m.Y H:i');
            $title = $isWaitlisted ? 'Вас поставили в лист ожидания' : 'Вас записали на турнир';
            $body = $isWaitlisted
                ? "{$user->name} поставил(а) вас в лист ожидания «{$tournament->name}» — {$date}."
                : "{$user->name} записал(а) вас на «{$tournament->name}» — {$date}. Заявка на модерации.";

            \App\Models\Notification::create([
                'user_id' => $friend->id,
                'title' => $title,
                'body' => $body,
                'type' => $isWaitlisted ? 'waitlisted_by_friend' : 'registered_by_friend',
                'category' => 'tournament',
                'data' => [
                    'tournament_id' => $tournament->id,
                    'invited_by_user_id' => $user->id,
                    'invited_by_name' => $user->name,
                ],
            ]);

            $fcm = app(\App\Services\FCMNotificationService::class);
            $fcm->sendToUser($friend, $title, $body, [
                'type' => 'tournament',
                'category' => 'tournament',
                'subtype' => $isWaitlisted ? 'waitlisted_by_friend' : 'registered_by_friend',
                'tournament_id' => (string) $tournament->id,
                'invited_by_user_id' => (string) $user->id,
                'invited_by_name' => $user->name,
            ]);
        }

        if ($isWaitlisted) {
            $position = $tournament->getWaitlistPosition($user) ?? 0;
            return response()->json([
                'success' => true,
                'message' => $friend
                    ? "Вы и {$friend->name} в листе ожидания"
                    : 'Вы в листе ожидания',
                'registration_status' => 'waiting',
                'waitlist_position' => $position,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $friend
                ? "Заявка на двоих отправлена на модерацию ({$friend->name})"
                : 'Заявка отправлена на модерацию',
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

        // Был ли пользователь в основном составе (а не в waitlist)
        $wasMain = in_array($participant->pivot->status, ['registered', 'pending'], true);

        $wasFull = $tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending'])
            ->count() >= $tournament->max_participants;

        $tournament->participants()->detach($user->id);

        \App\Models\ActivityLog::log(
            'unregistered',
            'Tournament',
            $tournament->id,
            "{$user->name} снялся с турнира «{$tournament->name}»",
            ['user_id' => $user->id, 'user_name' => $user->name],
            $tournament->club_id,
        );

        // Журнал записей: отписка игрока.
        \App\Models\TournamentRegistrationLog::record($tournament->id, $user->id, 'unregistered');

        // Освободилось место в основном составе — пробуем подтянуть из waitlist.
        // Только если уходил человек из основного состава.
        $promoted = null;
        if ($wasMain && $tournament->status === 'open') {
            $promoted = self::promoteNextFromWaitlist($tournament);
        }

        // Если место осталось свободным (никого не подтянули) и турнир был полным —
        // оповещаем подписчиков и канал.
        if ($wasFull && $tournament->status === 'open' && !$promoted) {
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

        // Принимаем общий поисковый запрос (имя ИЛИ телефон). Для совместимости
        // со старыми сборками поддерживаем прежний ключ 'phone'.
        $term = trim((string) $request->input('query', $request->input('phone', '')));
        if (mb_strlen($term) < 2) {
            return response()->json(['success' => false, 'message' => 'Введите минимум 2 символа'], 400);
        }

        $digits = preg_replace('/\D/', '', $term);

        $partners = User::human()
            ->where('id', '!=', $user->id)
            ->where(function ($q) use ($term, $digits) {
                $q->where('name', 'LIKE', "%{$term}%");
                if (strlen($digits) >= 3) {
                    $q->orWhere('phone', 'LIKE', "%{$digits}%");
                }
            })
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
            'confirm_waitlist' => 'nullable|boolean',
        ]);
        $confirmWaitlist = $request->boolean('confirm_waitlist');

        if ($tournament->type !== 'team' || $tournament->isAdminPairing()) {
            return response()->json(['success' => false, 'message' => 'Парная регистрация для этого турнира недоступна'], 400);
        }

        if ($tournament->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Регистрация закрыта'], 400);
        }

        $partner = User::find($request->input('partner_id'));

        if ($partner->id === $user->id) {
            return response()->json(['success' => false, 'message' => 'Нельзя выбрать себя в качестве партнёра'], 400);
        }

        // Только для верифицированных — оба игрока пары
        if ($tournament->verified_only && !$user->level_verified) {
            return response()->json(['success' => false, 'message' => 'Турнир только для верифицированных игроков'], 400);
        }
        if ($tournament->verified_only && !$partner->level_verified) {
            return response()->json(['success' => false, 'message' => "{$partner->name} не верифицирован — турнир только для верифицированных игроков"], 400);
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

        // Проверяем, не зарегистрированы ли уже. Отклонённые (rejected) команды —
        // терминальный статус, они не блокируют повторную запись.
        $existingTeam = TournamentTeam::where('tournament_id', $tournament->id)
            ->where('status', '!=', 'rejected')
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

        // Подчищаем прежние отклонённые команды этой пары (любого из игроков),
        // чтобы не плодить мусор и stale-дубликаты при повторной записи.
        TournamentTeam::where('tournament_id', $tournament->id)
            ->where('status', 'rejected')
            ->where(function($q) use ($user, $partner) {
                $q->whereIn('player1_id', [$user->id, $partner->id])
                  ->orWhereIn('player2_id', [$user->id, $partner->id]);
            })
            ->delete();

        // Атомарная проверка мест + создание команды (с поддержкой waitlist)
        $result = DB::transaction(function () use ($tournament, $user, $partner, $confirmWaitlist) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            $maxTeams = $tournament->max_participants / 2;
            $takenTeams = TournamentTeam::where('tournament_id', $tournament->id)
                ->whereIn('status', ['approved', 'pending'])
                ->count();

            $hasMain = $takenTeams < $maxTeams;

            if ($hasMain) {
                return [
                    'outcome' => 'registered',
                    'team' => TournamentTeam::create([
                        'tournament_id' => $tournament->id,
                        'player1_id' => $user->id,
                        'player2_id' => $partner->id,
                        'rating_avg' => intval(($user->rating + $partner->rating) / 2),
                        'status' => 'pending',
                        'moderation_deadline' => $tournament->moderationDeadline(),
                    ]),
                ];
            }

            $waitlistCapacity = (int) ($tournament->waitlist_size ?? 0);
            $waitlistTaken = TournamentTeam::where('tournament_id', $tournament->id)
                ->where('status', 'waiting')
                ->count();
            $hasWaitlist = $waitlistCapacity > 0 && ($waitlistTaken + 1) <= $waitlistCapacity;

            if (!$hasWaitlist) {
                return ['outcome' => 'no_space'];
            }
            if (!$confirmWaitlist) {
                return ['outcome' => 'needs_confirm', 'position' => $waitlistTaken + 1];
            }

            return [
                'outcome' => 'waitlisted',
                'team' => TournamentTeam::create([
                    'tournament_id' => $tournament->id,
                    'player1_id' => $user->id,
                    'player2_id' => $partner->id,
                    'rating_avg' => intval(($user->rating + $partner->rating) / 2),
                    'status' => 'waiting',
                ]),
            ];
        });

        if ($result['outcome'] === 'no_space') {
            return response()->json(['success' => false, 'message' => 'Все места заняты'], 400);
        }

        if ($result['outcome'] === 'needs_confirm') {
            return response()->json([
                'success' => false,
                'requires_waitlist_confirmation' => true,
                'message' => 'Все места заняты. Встать парой в лист ожидания?',
                'waitlist_position' => $result['position'],
                'waitlist_size' => (int) ($tournament->waitlist_size ?? 0),
            ]);
        }

        $team = $result['team'];

        // Удаляем подписки обоих игроков — они уже записались/в листе
        TournamentSubscription::where('tournament_id', $tournament->id)
            ->whereIn('user_id', [$user->id, $partner->id])
            ->delete();

        $isWaitlisted = $result['outcome'] === 'waitlisted';

        // Журнал записей: оба игрока пары.
        \App\Models\TournamentRegistrationLog::record($tournament->id, $user->id, 'registered');
        \App\Models\TournamentRegistrationLog::record($tournament->id, $partner->id, 'registered');

        return response()->json([
            'success' => true,
            'message' => $isWaitlisted ? 'Пара в листе ожидания' : 'Заявка отправлена на модерацию',
            'registration_status' => $team->status,
            'team' => [
                'id' => $team->id,
                'player1' => ['id' => $user->id, 'name' => $user->name],
                'player2' => ['id' => $partner->id, 'name' => $partner->name],
                'status' => $team->status,
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

        $team->load(['player1', 'player2']);
        $partner = $team->player1_id === $user->id ? $team->player2 : $team->player1;
        $partnerName = $partner->name ?? '—';
        $wasMain = in_array($team->status, ['approved', 'pending'], true);

        $team->delete();

        \App\Models\ActivityLog::log(
            'unregistered',
            'Tournament',
            $tournament->id,
            "Пара {$user->name} + {$partnerName} снялась с турнира «{$tournament->name}»",
            [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'partner_id' => $partner->id ?? null,
                'partner_name' => $partnerName,
                'team_id' => $team->id,
            ],
            $tournament->club_id,
        );

        // Журнал записей: отписка обоих игроков пары.
        \App\Models\TournamentRegistrationLog::record($tournament->id, $user->id, 'unregistered');
        if ($partner && $partner->id) {
            \App\Models\TournamentRegistrationLog::record($tournament->id, $partner->id, 'unregistered');
        }

        // Освободилось место в основном составе — подтягиваем пару из waitlist
        if ($wasMain && $tournament->status === 'open') {
            self::promoteNextTeamFromWaitlist($tournament);
        }

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
     * Получить статус регистрации пользователя
     */
    private function getUserRegistration(Tournament $t, $user): array
    {
        $result = [
            'is_registered' => false,
            'status' => null,
            'can_register' => false,
            'block_reason' => null,
            'in_waitlist' => false,
            'waitlist_position' => null,
            'moderation_deadline' => null,
        ];

        if (!$t->usesSoloRegistration()) {
            // Отклонённые (rejected) команды — терминальный статус: считаем как
            // «не зарегистрирован», чтобы пара могла записаться повторно.
            $team = TournamentTeam::where('tournament_id', $t->id)
                ->where('status', '!=', 'rejected')
                ->where(function($q) use ($user) {
                    $q->where('player1_id', $user->id)
                      ->orWhere('player2_id', $user->id);
                })
                ->first();

            if ($team) {
                $result['is_registered'] = true;
                $result['status'] = $team->status;
                if ($team->status === 'pending' && $team->moderation_deadline) {
                    $result['moderation_deadline'] = \Carbon\Carbon::parse($team->moderation_deadline)->toIso8601String();
                }
                if ($team->status === 'waiting') {
                    $result['in_waitlist'] = true;
                    $result['waitlist_position'] = $t->getWaitlistPosition($user);
                }
            } else {
                // Парная регистрация — учитываем waitlist (нужна 1 пара = 2 человека)
                $result['block_reason'] = $this->resolveBlockReason($t, $user, 2);
                $result['can_register'] = $result['block_reason'] === null && $t->isOpen();
            }
        } else {
            // Отменённые (cancelled) записи — терминальный статус: считаем как
            // «не зарегистрирован», чтобы игрок мог записаться повторно.
            $participant = $t->participants()
                ->wherePivot('status', '!=', 'cancelled')
                ->where('user_id', $user->id)
                ->first();

            if ($participant) {
                $result['is_registered'] = true;
                $result['status'] = $participant->pivot->status;
                if ($participant->pivot->status === 'pending' && $participant->pivot->moderation_deadline) {
                    $result['moderation_deadline'] = \Carbon\Carbon::parse($participant->pivot->moderation_deadline)->toIso8601String();
                }
                if ($participant->pivot->status === 'waiting') {
                    $result['in_waitlist'] = true;
                    $result['waitlist_position'] = $t->getWaitlistPosition($user);
                }
            } else {
                $result['block_reason'] = $this->resolveBlockReason($t, $user, 1);
                $result['can_register'] = $result['block_reason'] === null && $t->isOpen();
            }
        }

        return $result;
    }

    /**
     * Block reason с учётом waitlist: если основной состав полный, но waitlist открыт —
     * не блокируем (даём пользователю записаться через подтверждение листа ожидания).
     */
    private function resolveBlockReason(Tournament $t, $user, int $needSlots): ?string
    {
        if ($t->isFull() && $t->hasWaitlistSlot($needSlots)) {
            // Места нет в основном, но есть в waitlist — пропускаем (UI спросит confirm)
            if (!$t->isOpen()) return 'Турнир не открыт для регистрации';
            if ($t->verified_only && !$user->level_verified) {
                return 'Турнир только для верифицированных игроков';
            }
            if ($user->level < $t->min_level) {
                return 'Ваш уровень (' . $user->level . ') ниже минимального (' . $t->min_level . ')';
            }
            if ($user->level > $t->max_level) {
                return 'Ваш уровень (' . $user->level . ') выше максимального (' . $t->max_level . ')';
            }
            return null;
        }
        return $t->getRegistrationBlockReason($user);
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
        $userId = (int) $request->input('player_id', $user->id);
        $tournament->load(['club', 'venueClub']);

        // Собираем матчи пользователя с rating_change
        $userMatches = [];

        if (in_array($tournament->type, ['americano', 'mexicano', 'americano_flex'])) {
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
                'club_name' => $tournament->club?->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
                'is_rated' => (bool) $tournament->is_rated,
            ],
            'summary' => [
                'matches_count' => count($userMatches),
                'wins' => $wins,
                'losses' => $losses,
                'rating_change' => $ratingHistory?->change ?? 0,
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
        $tournament->load(['club', 'venueClub']);

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
                'time' => $tournament->duration_hours
                    ? $tournament->start_date->format('H:i') . ' – ' . $tournament->start_date->copy()->addHours($tournament->duration_hours)->format('H:i')
                    : $tournament->start_date->format('H:i'),
                'duration_hours' => $tournament->duration_hours,
                'club_name' => $tournament->club?->name ?? 'Клуб',
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

            $stageLabel = $m->stage_name ?? $m->stage;
            if ($m->bracket === 'lower') {
                $stageLabel .= ' (нижняя сетка)';
            }

            $result[] = [
                'stage' => $stageLabel,
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
        if (!in_array($tournament->type, ['americano', 'mexicano', 'americano_flex'])) {
            return [];
        }

        $playerStats = [];
        $h2h = [];

        if ($tournament->type === 'americano') {
            $groups = $tournament->groups()->with(['players', 'rounds.matches'])->get();
            $h2h = \App\Support\AmericanoTie::fromGroups($groups);

            foreach ($groups as $group) {
                foreach ($group->players as $player) {
                    if (!isset($playerStats[$player->id])) {
                        $playerStats[$player->id] = [
                            'id' => $player->id,
                            'name' => $player->name,
                            'avatar' => $player->avatar,
                            'rating' => $player->rating,
                            'level' => $player->level,
                            'verified' => (bool) $player->level_verified,
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
                    'verified' => (bool) $user->level_verified,
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
        } elseif ($tournament->type === 'americano_flex') {
            $flexPlayers = $tournament->americanoFlexPlayers()->with('user')->get();
            foreach ($flexPlayers as $fp) {
                $user = $fp->user;
                if (!$user) continue;
                $playerStats[$user->id] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                    'rating' => $user->rating,
                    'level' => $user->level,
                    'verified' => (bool) $user->level_verified,
                    'wins' => 0, 'losses' => 0,
                    'points_for' => 0, 'points_against' => 0,
                    'total_points' => 0,
                    'matches_played' => 0, // считаем из фактически сыгранных (без 0:0)
                ];
            }

            foreach ($tournament->americanoFlexRounds()->with('matches')->get() as $round) {
                foreach ($round->matches as $match) {
                    if ($match->status !== 'completed') continue;
                    if ((int) $match->team1_score === 0 && (int) $match->team2_score === 0) continue; // 0:0 не считаем
                    $this->countMatchStats($playerStats, $match);
                    foreach ([$match->team1_player1_id, $match->team1_player2_id, $match->team2_player1_id, $match->team2_player2_id] as $pId) {
                        if (isset($playerStats[$pId])) $playerStats[$pId]['matches_played']++;
                    }
                }
            }
            // В flex «очки» = сумма забитых
            foreach ($playerStats as &$s) {
                $s['total_points'] = $s['points_for'];
            }
            unset($s);
        }

        if ($tournament->type === 'americano_flex') {
            // Flex: рейтинг по среднему за матч → суммарные очки
            usort($playerStats, function ($a, $b) {
                $avgA = $a['matches_played'] > 0 ? $a['total_points'] / $a['matches_played'] : 0;
                $avgB = $b['matches_played'] > 0 ? $b['total_points'] / $b['matches_played'] : 0;
                if ($avgA != $avgB) return $avgB <=> $avgA;
                return $b['total_points'] <=> $a['total_points'];
            });
        } else {
            usort($playerStats, function ($a, $b) use ($h2h) {
                if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
                if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
                $diffA = $a['points_for'] - $a['points_against'];
                $diffB = $b['points_for'] - $b['points_against'];
                if ($diffA !== $diffB) return $diffB <=> $diffA;
                return \App\Support\AmericanoTie::compare($h2h, $a['id'], $b['id']);
            });
        }

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
            if ($m->bracket === 'lower') {
                $stageName .= ' (нижняя сетка)';
            }

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

        // Раунды Americano Flex
        if ($tournament->type === 'americano_flex') {
            foreach ($tournament->americanoFlexRounds()->orderBy('round_number')->with('matches')->get() as $round) {
                $roundCounter++;
                foreach ($round->matches as $match) {
                    if (!$match->isCompleted()) continue;
                    // Несыгранный матч 0:0 в рейтинге не участвует.
                    if ((int) $match->team1_score === 0 && (int) $match->team2_score === 0) continue;
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
        } elseif ($tournament->type === 'americano_flex') {
            foreach ($tournament->americanoFlexPlayers()->with('user')->get() as $fp) {
                $rb = (int) $fp->rating_before;
                $ratings[$fp->user_id] = $rb > 0 ? $rb : (int) ($fp->user->rating ?? 1000);
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

        // Проверяем финал (только верхняя сетка — чемпион/призёры из неё)
        $finalMatch = $tournament->playoffMatches()
            ->whereIn('stage', ['final', 'Финал'])
            ->where(function ($q) { $q->where('bracket', 'upper')->orWhereNull('bracket'); })
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

            // Матч за 3-е место (is_bronze) — если сыгран, именно он определяет
            // 3/4 место (приоритетнее эвристики по полуфиналу ниже). Работает
            // одинаково для формата с полуфиналами и для winners_final (без них).
            $bronzeMatch = $tournament->playoffMatches()
                ->where('is_bronze', true)
                ->whereIn('stage', ['final', 'Финал'])
                ->where('status', 'completed')
                ->first();

            if ($bronzeMatch) {
                // Player-based (americano/mexicano)
                if ($bronzeMatch->team1_player1_id) {
                    $inTeam1 = in_array($userId, [$bronzeMatch->team1_player1_id, $bronzeMatch->team1_player2_id]);
                    $inTeam2 = in_array($userId, [$bronzeMatch->team2_player1_id, $bronzeMatch->team2_player2_id]);

                    if ($inTeam1 || $inTeam2) {
                        $team1Won = $bronzeMatch->team1_score > $bronzeMatch->team2_score;
                        return ($inTeam1 && $team1Won) || ($inTeam2 && !$team1Won) ? 3 : 4;
                    }
                }

                // Team-based (team)
                if ($bronzeMatch->team1_id && $myTeamIds->isNotEmpty()) {
                    $inTeam1 = $myTeamIds->contains($bronzeMatch->team1_id);
                    $inTeam2 = $myTeamIds->contains($bronzeMatch->team2_id);

                    if ($inTeam1 || $inTeam2) {
                        return $bronzeMatch->winner_id && $myTeamIds->contains($bronzeMatch->winner_id) ? 3 : 4;
                    }
                }
            }

            // Полуфинал — 3-4 место (только верхняя сетка, fallback когда нет
            // отдельного/сыгранного матча за 3-е место)
            $semiMatches = $tournament->playoffMatches()
                ->whereIn('stage', ['semi', 'Полуфинал'])
                ->where(function ($q) { $q->where('bracket', 'upper')->orWhereNull('bracket'); })
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

        // Король корта — место по лидерборду
        if ($tournament->type === 'king_of_court') {
            // Фикс-пары: место по таблице ПАР (у обоих игроков пары оно одинаковое).
            if ($tournament->isPairedKingOfCourt()) {
                $standings = app(\App\Services\KingOfCourtService::class)->getPairStandings($tournament);
                foreach ($standings as $i => $row) {
                    $pair = $row['pair'];
                    if ((int) $pair->player1_id === $userId || (int) $pair->player2_id === $userId) {
                        return $i + 1;
                    }
                }
                return null;
            }
            $players = $tournament->kingOfCourtPlayers()
                ->orderByDesc('total_points')
                ->orderByDesc('wins')
                ->get();
            foreach ($players as $i => $kp) {
                if ($kp->user_id === $userId) return $i + 1;
            }
        }

        // Just Padel It — место по лидерборду
        if ($tournament->type === 'just_padel_it') {
            if ($tournament->isPairedJustPadelIt()) {
                $standings = app(\App\Services\JustPadelItService::class)->getPairStandings($tournament);
                foreach ($standings as $i => $row) {
                    $pair = $row['pair'];
                    if ((int) $pair->player1_id === $userId || (int) $pair->player2_id === $userId) {
                        return $i + 1;
                    }
                }
                return null;
            }
            $players = $tournament->justPadelItPlayers()
                ->orderByDesc('total_points')
                ->orderByDesc('wins')
                ->get();
            foreach ($players as $i => $kp) {
                if ($kp->user_id === $userId) return $i + 1;
            }
        }

        // Round Robin — место по стандингам (победы → разница → личные встречи)
        if ($tournament->type === 'round_robin') {
            $standings = app(\App\Services\RoundRobinService::class)->standings($tournament);
            foreach ($standings as $i => $row) {
                if ((int) $row['user_id'] === $userId) return $i + 1;
            }
        }

        // Bali Format — место по парам (стандинги через сервис с tiebreaker)
        if ($tournament->type === 'bali_koc') {
            $standings = app(\App\Services\BaliKocService::class)->getStandings($tournament);
            foreach ($standings as $i => $pair) {
                if ((int) $pair->player1_id === $userId || (int) $pair->player2_id === $userId) {
                    return $i + 1;
                }
            }
        }

        // Americano Flex — место по таблице лидеров
        if ($tournament->type === 'americano_flex') {
            // Парный: место по таблице ПАР (игрок ищется как player1 или player2).
            if ($tournament->isPairedFlex()) {
                $pairRows = app(\App\Services\AmericanoFlexService::class)->getPairedLeaderboard($tournament);
                foreach ($pairRows as $i => $r) {
                    $p1 = $r['player1']->id ?? null;
                    $p2 = $r['player2']->id ?? null;
                    if ($p1 === $userId || $p2 === $userId) {
                        return $i + 1;
                    }
                }
                return null;
            }
            foreach ($this->getLeaderboard($tournament) as $row) {
                if ((int) $row['id'] === $userId) return (int) $row['position'];
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
     * Подтянуть следующего из листа ожидания в pending (solo).
     * Возвращает promoted-user или null.
     */
    public static function promoteNextFromWaitlist(Tournament $tournament): ?\App\Models\User
    {
        if ($tournament->isTeamBased()) return null;
        if ($tournament->status !== 'open') return null;

        return DB::transaction(function () use ($tournament) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            $taken = $tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending'])
                ->count();
            if ($taken >= $tournament->max_participants) {
                return null;
            }

            $next = $tournament->participants()
                ->wherePivot('status', 'waiting')
                ->orderBy('tournament_participants.created_at')
                ->first();

            if (!$next) return null;

            $tournament->participants()->updateExistingPivot($next->id, ['status' => 'pending']);

            $date = $tournament->start_date->format('d.m.Y H:i');
            $title = 'Место освободилось!';
            $body = "Вы перешли из листа ожидания на турнир «{$tournament->name}» ({$date}). Заявка на модерации.";

            \App\Models\Notification::create([
                'user_id' => $next->id,
                'title' => $title,
                'body' => $body,
                'type' => 'waitlist_promoted',
                'category' => 'tournament',
                'data' => ['tournament_id' => $tournament->id],
            ]);

            app(\App\Services\FCMNotificationService::class)->sendToUser($next, $title, $body, [
                'type' => 'tournament',
                'category' => 'tournament',
                'subtype' => 'waitlist_promoted',
                'tournament_id' => (string) $tournament->id,
            ]);

            return $next;
        });
    }

    /**
     * Подтянуть следующую пару из листа ожидания в pending (team).
     */
    public static function promoteNextTeamFromWaitlist(Tournament $tournament): ?TournamentTeam
    {
        if (!$tournament->isTeamBased()) return null;
        if ($tournament->status !== 'open') return null;

        return DB::transaction(function () use ($tournament) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            $maxTeams = $tournament->max_participants / 2;
            $taken = TournamentTeam::where('tournament_id', $tournament->id)
                ->whereIn('status', ['approved', 'pending'])
                ->count();
            if ($taken >= $maxTeams) return null;

            $team = TournamentTeam::where('tournament_id', $tournament->id)
                ->where('status', 'waiting')
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            if (!$team) return null;

            $team->update(['status' => 'pending']);
            $team->load(['player1', 'player2']);

            $date = $tournament->start_date->format('d.m.Y H:i');
            $title = 'Место освободилось!';
            $body = "Ваша пара перешла из листа ожидания на турнир «{$tournament->name}» ({$date}). Заявка на модерации.";

            $fcm = app(\App\Services\FCMNotificationService::class);
            foreach ([$team->player1, $team->player2] as $u) {
                if (!$u) continue;
                \App\Models\Notification::create([
                    'user_id' => $u->id,
                    'title' => $title,
                    'body' => $body,
                    'type' => 'waitlist_promoted',
                    'category' => 'tournament',
                    'data' => ['tournament_id' => $tournament->id, 'team_id' => $team->id],
                ]);
                $fcm->sendToUser($u, $title, $body, [
                    'type' => 'tournament',
                    'category' => 'tournament',
                    'subtype' => 'waitlist_promoted',
                    'tournament_id' => (string) $tournament->id,
                    'team_id' => (string) $team->id,
                ]);
            }

            return $team;
        });
    }

    /**
     * Уведомить всех зарегистрированных участников об отмене турнира.
     * Поддерживает как одиночные форматы (тур. participants), так и
     * командные (tournament_teams.player1_id/player2_id).
     */
    public static function notifyParticipantsTournamentCancelled(Tournament $tournament): void
    {
        $userIds = collect();

        // Одиночные форматы — pivot tournament_participants
        $individualIds = $tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending', 'approved'])
            ->pluck('users.id');
        $userIds = $userIds->concat($individualIds);

        // Командные — tournament_teams
        $teamIds = TournamentTeam::where('tournament_id', $tournament->id)
            ->whereIn('status', ['approved', 'pending'])
            ->get(['player1_id', 'player2_id'])
            ->flatMap(fn($t) => [$t->player1_id, $t->player2_id])
            ->filter();
        $userIds = $userIds->concat($teamIds);

        $userIds = $userIds->filter()->unique()->values();
        if ($userIds->isEmpty()) {
            return;
        }

        $date = $tournament->start_date->format('d.m.Y H:i');
        $title = 'Турнир отменён';
        $body = "Внимание! Турнир «{$tournament->name}» ({$date}) отменён организатором.";

        $fcm = app(\App\Services\FCMNotificationService::class);

        foreach ($userIds as $userId) {
            $user = \App\Models\User::find($userId);
            if (!$user) continue;

            \App\Models\Notification::create([
                'user_id' => $user->id,
                'title' => $title,
                'body' => $body,
                'type' => 'tournament_cancelled',
                'category' => 'tournament',
                'data' => ['tournament_id' => $tournament->id],
            ]);

            $fcm->sendToUser($user, $title, $body, [
                'type' => 'tournament_cancelled',
                'tournament_id' => (string) $tournament->id,
            ]);
        }
    }

    /**
     * Уведомить всех зарегистрированных участников об изменении даты турнира.
     * Одиночные форматы (tournament_participants) + командные (tournament_teams).
     */
    public static function notifyParticipantsTournamentDateChanged(Tournament $tournament): void
    {
        $userIds = collect();

        $individualIds = $tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending', 'approved'])
            ->pluck('users.id');
        $userIds = $userIds->concat($individualIds);

        $teamIds = TournamentTeam::where('tournament_id', $tournament->id)
            ->whereIn('status', ['approved', 'pending'])
            ->get(['player1_id', 'player2_id'])
            ->flatMap(fn($t) => [$t->player1_id, $t->player2_id])
            ->filter();
        $userIds = $userIds->concat($teamIds);

        $userIds = $userIds->filter()->unique()->values();
        if ($userIds->isEmpty()) {
            return;
        }

        $date = $tournament->start_date->format('d.m.Y H:i');
        $title = 'Дата турнира изменена';
        $body = "Внимание! Дата турнира «{$tournament->name}» изменена на {$date}.";

        $fcm = app(\App\Services\FCMNotificationService::class);

        foreach ($userIds as $userId) {
            $user = \App\Models\User::find($userId);
            if (!$user) continue;

            \App\Models\Notification::create([
                'user_id' => $user->id,
                'title' => $title,
                'body' => $body,
                'type' => 'tournament_date_changed',
                'category' => 'tournament',
                'data' => ['tournament_id' => $tournament->id],
            ]);

            $fcm->sendToUser($user, $title, $body, [
                'type' => 'tournament_date_changed',
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

        // Подсветка конкретного игрока (открытие live из чужого профиля):
        // is_me / has_me / дельты рейтинга считаются для этого игрока.
        $playerId = (int) $request->input('player_id', 0);
        if ($playerId > 0) {
            $user = \App\Models\User::find($playerId) ?? $user;
        }

        $tournament->load(['club', 'venueClub']);

        if ($tournament->type === 'mexicano') {
            return $this->liveMexicano($tournament, $user);
        }
        if ($tournament->type === 'team') {
            return $this->liveTeam($tournament, $user);
        }
        if ($tournament->type === 'king_of_court') {
            return $this->liveKingOfCourt($tournament, $user);
        }
        if ($tournament->isJustPadelIt()) {
            return $this->liveJustPadelIt($tournament, $user);
        }
        if ($tournament->type === 'round_robin') {
            return $this->liveRoundRobin($tournament, $user);
        }
        if ($tournament->type === 'bali_koc') {
            return $this->liveBaliKoc($tournament, $user);
        }
        if ($tournament->type === 'americano_flex') {
            return $this->liveAmericanoFlex($tournament, $user);
        }
        if ($tournament->type !== 'americano') {
            return response()->json([
                'success' => false,
                'message' => 'Live-режим пока доступен только для Американо/Мексикано/Группового/Король корта/Bali Format',
            ], 400);
        }

        // Карта дельт рейтинга для каждого матча текущего юзера (если он auth)
        $myMatchDeltas = [];
        if ($user && $tournament->is_rated) {
            try {
                $userMatchesForDelta = $this->getPlayerBasedMatches($tournament, (int) $user->id);
                foreach ($userMatchesForDelta as $um) {
                    if (isset($um['id'])) {
                        $myMatchDeltas[(int) $um['id']] = (int) ($um['rating_change'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                $myMatchDeltas = [];
            }
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
                    'verified' => (bool) $p->level_verified,
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

            // Сортируем: очки → победы → разница мячей → личная встреча
            $h2h = \App\Support\AmericanoTie::fromGroups([$group]);
            uasort($playerStats, function ($a, $b) use ($h2h) {
                if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
                if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
                $diffA = $a['points_for'] - $a['points_against'];
                $diffB = $b['points_for'] - $b['points_against'];
                if ($diffA !== $diffB) return $diffB <=> $diffA;
                return \App\Support\AmericanoTie::compare($h2h, $a['id'], $b['id']);
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
                        'my_rating_change' => $myMatchDeltas[(int) $m->id] ?? null,
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

        // Показываем плей-офф если он есть в БД — независимо от has_playoff.
        // Так покрываем team-турниры со старым флагом и любые случаи с
        // существующими матчами плей-офф.
        $playoff = ($tournament->has_playoff || $tournament->playoffMatches()->exists())
            ? $this->getPlayoffForLive($tournament, $user)
            : [];

        return response()->json([
            'success' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'date' => $tournament->start_date->translatedFormat('j F'),
                'time' => $tournament->start_date->format('H:i'),
                'club_name' => $tournament->club?->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
                'status' => $tournament->status,
                'has_playoff' => (bool) $tournament->has_playoff,
            ],
            'groups' => $groups,
            'playoff' => $playoff,
        ]);
    }

    /**
     * Live для Americano Flex: одна виртуальная группа, раунды с матчами и
     * отдыхающими (byes), таблица в формате обычного американо.
     * Без плей-офф. Рейтинг таблицы — по среднему за матч (особенность flex).
     */
    private function liveAmericanoFlex(Tournament $tournament, $user)
    {
        $userId = $user ? (int) $user->id : null;

        // Дельта рейтинга по каждому матчу текущего юзера (как в Американо).
        $myMatchDeltas = [];
        if ($user && $tournament->is_rated) {
            try {
                foreach ($this->getPlayerBasedMatches($tournament, (int) $user->id) as $um) {
                    if (isset($um['id'])) {
                        $myMatchDeltas[(int) $um['id']] = (int) ($um['rating_change'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                $myMatchDeltas = [];
            }
        }

        // Базовая инфа по игрокам flex
        $flexPlayers = $tournament->americanoFlexPlayers()->with('user')->get();
        $playerStats = [];
        foreach ($flexPlayers as $fp) {
            $u = $fp->user;
            if (!$u) continue;
            $playerStats[$u->id] = [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'rating' => $u->rating,
                'level' => $u->level,
                'verified' => (bool) $u->level_verified,
                'wins' => 0,
                'losses' => 0,
                'draws' => 0,
                'points_for' => 0,
                'points_against' => 0,
                'total_points' => 0,
                'matches_played' => 0, // считаем ниже из фактически сыгранных (без 0:0)
            ];
        }

        $flexRounds = $tournament->americanoFlexRounds()
            ->with(['matches', 'byes'])
            ->orderBy('round_number')
            ->get();

        // Статистика по завершённым матчам (матчи 0:0 — несыгранные, не считаем)
        foreach ($flexRounds as $round) {
            foreach ($round->matches as $m) {
                if ($m->status !== 'completed') continue;
                if ((int) $m->team1_score === 0 && (int) $m->team2_score === 0) continue;
                $this->countMatchStats($playerStats, $m);
                foreach ([$m->team1_player1_id, $m->team1_player2_id, $m->team2_player1_id, $m->team2_player2_id] as $pId) {
                    if (isset($playerStats[$pId])) $playerStats[$pId]['matches_played']++;
                }
                if ((int) $m->team1_score === (int) $m->team2_score) {
                    foreach ([$m->team1_player1_id, $m->team1_player2_id, $m->team2_player1_id, $m->team2_player2_id] as $pId) {
                        if (isset($playerStats[$pId])) $playerStats[$pId]['draws']++;
                    }
                }
            }
        }
        // В flex «очки» = сумма забитых (points_for)
        foreach ($playerStats as &$s) {
            $s['total_points'] = $s['points_for'];
        }
        unset($s);

        // Сортировка flex: среднее за матч → суммарные очки
        uasort($playerStats, function ($a, $b) {
            $avgA = $a['matches_played'] > 0 ? $a['total_points'] / $a['matches_played'] : 0;
            $avgB = $b['matches_played'] > 0 ? $b['total_points'] / $b['matches_played'] : 0;
            if ($avgA != $avgB) return $avgB <=> $avgA;
            return $b['total_points'] <=> $a['total_points'];
        });

        $position = 1;
        $leaderboard = [];
        foreach ($playerStats as $s) {
            $totalBalls = $s['points_for'] + $s['points_against'];
            $ballPercent = $totalBalls > 0 ? (int) round($s['points_for'] / $totalBalls * 100) : 0;
            $leaderboard[] = [
                'position' => $position++,
                'id' => $s['id'],
                'name' => $s['name'],
                'avatar' => $s['avatar'],
                'rating' => $s['rating'],
                'wins' => $s['wins'],
                'losses' => $s['losses'],
                'draws' => $s['draws'],
                'points_for' => $s['points_for'],
                'points_against' => $s['points_against'],
                'point_diff' => $s['points_for'] - $s['points_against'],
                'ball_percent' => $ballPercent,
                'total_points' => $s['total_points'],
                'games_played' => $s['matches_played'],
                'verified' => $s['verified'],
                'is_me' => $userId !== null && (int) $s['id'] === $userId,
            ];
        }

        // Парный флекс: лидерборд по парам (одна строка = пара «A / B»).
        if ($tournament->isPairedFlex()) {
            $pairTeams = $tournament->teams()
                ->whereNotNull('player2_id')
                ->with(['player1', 'player2'])
                ->orderBy('id')->get();

            $pairRows = [];
            foreach ($pairTeams as $t) {
                $s1 = $playerStats[$t->player1_id] ?? null;
                if (!$s1) continue;
                $pairRows[] = [
                    'p1' => $t->player1,
                    'p2' => $t->player2,
                    'games' => (int) $s1['matches_played'],
                    'points' => (int) $s1['total_points'],
                    'pf' => (int) $s1['points_for'],
                    'pa' => (int) $s1['points_against'],
                    'wins' => (int) $s1['wins'],
                    'losses' => (int) $s1['losses'],
                    'draws' => (int) $s1['draws'],
                    'is_me' => $userId !== null && in_array($userId, [(int) $t->player1_id, (int) $t->player2_id], true),
                    'avatar' => $t->player1?->avatar,
                ];
            }
            usort($pairRows, function ($a, $b) {
                $avgA = $a['games'] > 0 ? $a['points'] / $a['games'] : 0;
                $avgB = $b['games'] > 0 ? $b['points'] / $b['games'] : 0;
                if ($avgA != $avgB) return $avgB <=> $avgA;
                return $b['points'] <=> $a['points'];
            });

            $position = 1;
            $leaderboard = [];
            foreach ($pairRows as $r) {
                $name = trim(($r['p1']->name ?? '—') . ' / ' . ($r['p2']->name ?? '—'));
                $totalBalls = $r['pf'] + $r['pa'];
                $leaderboard[] = [
                    'position' => $position++,
                    'id' => $r['p1']->id ?? 0,
                    'name' => $name,
                    'avatar' => $r['avatar'],
                    'rating' => null,
                    'wins' => $r['wins'],
                    'losses' => $r['losses'],
                    'draws' => $r['draws'],
                    'points_for' => $r['pf'],
                    'points_against' => $r['pa'],
                    'point_diff' => $r['pf'] - $r['pa'],
                    'ball_percent' => $totalBalls > 0 ? (int) round($r['pf'] / $totalBalls * 100) : 0,
                    'total_points' => $r['points'],
                    'games_played' => $r['games'],
                    'verified' => false,
                    'is_me' => $r['is_me'],
                ];
            }
        }

        // Раунды с матчами и отдыхающими
        $rounds = [];
        foreach ($flexRounds as $round) {
            $matches = [];
            foreach ($round->matches as $m) {
                $t1HasMe = $userId !== null && in_array($userId, [(int) $m->team1_player1_id, (int) $m->team1_player2_id], true);
                $t2HasMe = $userId !== null && in_array($userId, [(int) $m->team2_player1_id, (int) $m->team2_player2_id], true);

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
                    'my_rating_change' => $myMatchDeltas[(int) $m->id] ?? null,
                ];
            }

            $byes = [];
            foreach ($round->byes as $bye) {
                $byes[] = $this->formatPlayerForLive($bye->user_id, $playerStats, $tournament);
            }

            $rounds[] = [
                'id' => $round->id,
                'round_number' => $round->round_number,
                'status' => $round->status,
                'matches' => $matches,
                'byes' => array_values(array_filter($byes)),
            ];
        }

        return response()->json([
            'success' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'date' => $tournament->start_date->translatedFormat('j F'),
                'time' => $tournament->start_date->format('H:i'),
                'club_name' => $tournament->club?->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
                'status' => $tournament->status,
                'has_playoff' => false,
                'is_paired' => $tournament->isPairedFlex(),
            ],
            'groups' => [[
                'id' => 0,
                'name' => '',
                'leaderboard' => array_values($leaderboard),
                'rounds' => $rounds,
            ]],
            'playoff' => [],
        ]);
    }

    /**
     * Плей-офф для live: матчи группированные по stages, с has_me/avatar.
     */
    private function getPlayoffForLive(Tournament $tournament, $user): array
    {
        $userId = $user ? (int) $user->id : null;

        // Карта дельт рейтинга по match_id для текущего юзера
        $myMatchDeltas = [];
        if ($user && $tournament->is_rated) {
            try {
                if ($tournament->type === 'team') {
                    $userMatchesForDelta = $this->getTeamBasedMatches($tournament, (int) $user->id);
                } else {
                    $userMatchesForDelta = $this->getPlayerBasedMatches($tournament, (int) $user->id);
                }
                foreach ($userMatchesForDelta as $um) {
                    if (isset($um['id'])) {
                        $myMatchDeltas[(int) $um['id']] = (int) ($um['rating_change'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                $myMatchDeltas = [];
            }
        }

        $matches = $tournament->playoffMatches()
            ->with([
                'team1Player1', 'team1Player2',
                'team2Player1', 'team2Player2',
                'team1.player1', 'team1.player2',
                'team2.player1', 'team2.player2',
            ])
            ->orderBy('match_number')
            ->get();

        if ($matches->isEmpty()) return [];

        // Логический порядок стадий: 1/8 → 1/4 → Полуфинал → За 3-е место → Финал
        $stageOrder = [
            '1/8 финала' => 1,
            '1/4 финала' => 2,
            'Полуфинал' => 3,
            'За 3-е место' => 4,
            'Финал' => 5,
        ];

        $fmtP = function ($p) {
            if (!$p) return null;
            return [
                'id' => $p->id,
                'name' => $p->name,
                'avatar' => $p->avatar,
                'verified' => (bool) $p->level_verified,
            ];
        };

        $stages = [];
        foreach ($matches as $m) {
            // Bronze-матч идёт под отдельным заголовком «За 3-е место»,
            // даже если в БД его stage='final'
            $stageKey = $m->is_bronze
                ? 'За 3-е место'
                : ($m->stage_name ?: ($m->stage ?? '—'));

            // Для нижней сетки (групповой 24-парный) добавим маркер
            if ($m->bracket === 'lower') {
                $stageKey .= ' (нижняя сетка)';
            }

            // Игроки команды берём в зависимости от типа матча:
            // - Американо: team1_player1_id / team1_player2_id напрямую
            // - Team: через relationship team1->player1/player2
            $isAmericano = $m->team1_player1_id !== null;

            $t1P1 = $isAmericano ? $m->team1Player1 : ($m->team1->player1 ?? null);
            $t1P2 = $isAmericano ? $m->team1Player2 : ($m->team1->player2 ?? null);
            $t2P1 = $isAmericano ? $m->team2Player1 : ($m->team2->player1 ?? null);
            $t2P2 = $isAmericano ? $m->team2Player2 : ($m->team2->player2 ?? null);

            $t1Ids = array_filter([
                $t1P1?->id ? (int) $t1P1->id : null,
                $t1P2?->id ? (int) $t1P2->id : null,
            ]);
            $t2Ids = array_filter([
                $t2P1?->id ? (int) $t2P1->id : null,
                $t2P2?->id ? (int) $t2P2->id : null,
            ]);

            $t1HasMe = $userId !== null && in_array($userId, $t1Ids, true);
            $t2HasMe = $userId !== null && in_array($userId, $t2Ids, true);

            $stages[$stageKey][] = [
                'id' => $m->id,
                'court_number' => $m->court_number,
                'status' => $m->status,
                'match_number' => $m->match_number,
                'team1' => [
                    'player1' => $fmtP($t1P1),
                    'player2' => $fmtP($t1P2),
                    'score' => $m->status === 'completed' ? (int) $m->team1_score : null,
                    'has_me' => $t1HasMe,
                ],
                'team2' => [
                    'player1' => $fmtP($t2P1),
                    'player2' => $fmtP($t2P2),
                    'score' => $m->status === 'completed' ? (int) $m->team2_score : null,
                    'has_me' => $t2HasMe,
                ],
                'has_me' => $t1HasMe || $t2HasMe,
                'my_rating_change' => $myMatchDeltas[(int) $m->id] ?? null,
            ];
        }

        // Сортируем стадии в логическом порядке
        $stageList = array_keys($stages);
        usort($stageList, function ($a, $b) use ($stageOrder) {
            // У lower bracket берём базовое имя без " (нижняя сетка)"
            $baseA = preg_replace('/\s*\(нижняя сетка\)$/u', '', $a);
            $baseB = preg_replace('/\s*\(нижняя сетка\)$/u', '', $b);
            $orderA = $stageOrder[$baseA] ?? 99;
            $orderB = $stageOrder[$baseB] ?? 99;
            if ($orderA !== $orderB) return $orderA <=> $orderB;
            // Lower bracket после upper в той же стадии
            $aLower = str_contains($a, 'нижняя') ? 1 : 0;
            $bLower = str_contains($b, 'нижняя') ? 1 : 0;
            return $aLower <=> $bLower;
        });

        $out = [];
        foreach ($stageList as $name) {
            $out[] = [
                'stage' => $name,
                'matches' => $stages[$name],
            ];
        }
        return $out;
    }

    /**
     * Live для Мексикано — без групп, единая таблица + раунды + опц. плей-офф.
     */
    private function liveMexicano(Tournament $tournament, $user)
    {
        $userId = $user ? (int) $user->id : null;

        // Карта дельт рейтинга для каждого матча текущего юзера
        $myMatchDeltas = [];
        if ($user && $tournament->is_rated) {
            try {
                $userMatchesForDelta = $this->getPlayerBasedMatches($tournament, (int) $user->id);
                foreach ($userMatchesForDelta as $um) {
                    if (isset($um['id'])) {
                        $myMatchDeltas[(int) $um['id']] = (int) ($um['rating_change'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                $myMatchDeltas = [];
            }
        }

        // Игроки с total_points
        $mexicanoPlayers = $tournament->mexicanoPlayers()->with('user')->get();
        $playerStats = [];
        foreach ($mexicanoPlayers as $mp) {
            $u = $mp->user;
            if (!$u) continue;
            $playerStats[$u->id] = [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'rating' => $u->rating,
                'level' => $u->level,
                'wins' => 0,
                'losses' => 0,
                'draws' => 0,
                'points_for' => 0,
                'points_against' => 0,
                'total_points' => (int) ($mp->total_points ?? 0),
            ];
        }

        // Раунды + матчи
        $rounds = $tournament->mexicanoRounds()
            ->with('matches')
            ->orderBy('round_number')
            ->get();

        $roundsOut = [];
        foreach ($rounds as $r) {
            $matchesOut = [];
            foreach ($r->matches as $m) {
                if ($m->status === 'completed') {
                    $this->countMatchStats($playerStats, $m);
                    if ((int) $m->team1_score === (int) $m->team2_score) {
                        foreach ([$m->team1_player1_id, $m->team1_player2_id, $m->team2_player1_id, $m->team2_player2_id] as $pId) {
                            if (isset($playerStats[$pId])) $playerStats[$pId]['draws']++;
                        }
                    }
                }

                $t1HasMe = $userId !== null && in_array($userId, [
                    (int) $m->team1_player1_id,
                    (int) $m->team1_player2_id,
                ], true);
                $t2HasMe = $userId !== null && in_array($userId, [
                    (int) $m->team2_player1_id,
                    (int) $m->team2_player2_id,
                ], true);

                $matchesOut[] = [
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
                    'my_rating_change' => $myMatchDeltas[(int) $m->id] ?? null,
                ];
            }
            $roundsOut[] = [
                'id' => $r->id,
                'round_number' => $r->round_number,
                'status' => $r->status,
                'matches' => $matchesOut,
            ];
        }

        // Сортировка лидерборда
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
                'is_me' => $userId && (int) $s['id'] === $userId,
            ]);
        }

        // Показываем плей-офф если он есть в БД — независимо от has_playoff.
        // Так покрываем team-турниры со старым флагом и любые случаи с
        // существующими матчами плей-офф.
        $playoff = ($tournament->has_playoff || $tournament->playoffMatches()->exists())
            ? $this->getPlayoffForLive($tournament, $user)
            : [];

        return response()->json([
            'success' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'date' => $tournament->start_date->translatedFormat('j F'),
                'time' => $tournament->start_date->format('H:i'),
                'club_name' => $tournament->club?->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
                'status' => $tournament->status,
                'has_playoff' => (bool) $tournament->has_playoff,
            ],
            'leaderboard' => array_values($leaderboard),
            'rounds' => $roundsOut,
            'playoff' => $playoff,
        ]);
    }

    /**
     * Live для турнира «Король корта».
     * Корты упорядочены 1..N: 1 = «Топ», N = «Дно», середина — «Среднее».
     * Лидерборд — по KingOfCourtPlayer.total_points/wins/diff.
     */
    private function liveKingOfCourt(Tournament $tournament, $user)
    {
        $userId = $user ? (int) $user->id : null;

        // Если в запросе пришёл player_id — считаем дельту рейтинга для него
        // (нужно когда смотрим из чужого профиля). Иначе — для текущего юзера.
        $targetId = (int) (request()->query('player_id') ?: $userId);

        $kocPlayers = $tournament->kingOfCourtPlayers()->with('user')->get();
        $playerStats = [];
        $ratingEvolve = [];
        foreach ($kocPlayers as $kp) {
            $u = $kp->user;
            if (!$u) continue;
            $playerStats[$u->id] = [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'rating' => $u->rating,
                'level' => $u->level,
                'wins' => (int) $kp->wins,
                'losses' => (int) $kp->losses,
                'draws' => 0,
                'points_for' => (int) $kp->points_for,
                'points_against' => (int) $kp->points_against,
                'total_points' => (int) $kp->total_points,
            ];
            $ratingEvolve[$u->id] = ['current_rating' => (int) $kp->rating_before];
        }

        $rounds = $tournament->kingOfCourtRounds()
            ->with(['matches' => function ($q) {
                $q->orderBy('court_number');
            }])
            ->orderBy('round_number')
            ->get();

        // Считаем дельту рейтинга для целевого игрока в каждом раунде.
        // Эволюционируем рейтинги ВСЕХ игроков по матчам в порядке раундов
        // (так же как finishTournament), запоминаем pre/post для targetId.
        $kocService = app(\App\Services\KingOfCourtService::class);
        $roundDeltas = [];
        foreach ($rounds as $r) {
            $pre = $ratingEvolve[$targetId]['current_rating'] ?? null;
            foreach ($r->matches as $m) {
                if ($m->status !== 'completed') continue;
                $kocService->calculateEloForMatch($m, $ratingEvolve);
            }
            $post = $ratingEvolve[$targetId]['current_rating'] ?? null;
            $roundDeltas[$r->id] = ($pre !== null && $post !== null) ? ($post - $pre) : null;
        }
        if (!$tournament->is_rated) { $roundDeltas = []; }

        $roundsOut = [];
        foreach ($rounds as $r) {
            $courtsTotal = $r->matches->count();
            $matchesOut = [];
            foreach ($r->matches as $m) {
                $courtIdx = (int) $m->court_number;
                if ($courtIdx === 1) {
                    $courtTier = 'top';
                } elseif ($courtIdx === $courtsTotal) {
                    $courtTier = 'bottom';
                } else {
                    $courtTier = 'middle';
                }
                $courtLabel = "Корт {$courtIdx}";

                $t1HasMe = $userId !== null && in_array($userId, [
                    (int) $m->team1_player1_id,
                    (int) $m->team1_player2_id,
                ], true);
                $t2HasMe = $userId !== null && in_array($userId, [
                    (int) $m->team2_player1_id,
                    (int) $m->team2_player2_id,
                ], true);

                $matchesOut[] = [
                    'id' => $m->id,
                    'court_number' => $courtIdx,
                    'court_tier' => $courtTier,
                    'court_label' => $courtLabel,
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
            $roundsOut[] = [
                'id' => $r->id,
                'round_number' => $r->round_number,
                'status' => $r->status,
                'matches' => $matchesOut,
                'my_rating_change' => $roundDeltas[$r->id] ?? null,
            ];
        }

        $isPaired = $tournament->isPairedKingOfCourt();

        if ($isPaired) {
            // Фикс-пары: таблица по парам (shape как у Bali — player1/player2).
            $pairs = $tournament->kingOfCourtPairs()->with(['player1', 'player2'])->get();
            $myPairId = null;
            foreach ($pairs as $p) {
                if ($userId && in_array($userId, [(int) $p->player1_id, (int) $p->player2_id], true)) {
                    $myPairId = (int) $p->id;
                }
            }
            $standings = $kocService->getPairStandings($tournament);
            $leaderboard = [];
            foreach ($standings as $idx => $row) {
                $pair = $row['pair'];
                $totalBalls = $row['points_for'] + $row['points_against'];
                $leaderboard[] = [
                    'position' => $idx + 1,
                    'pair_id' => $pair->id,
                    'player1' => $this->formatPlayerForLive($pair->player1_id, $playerStats, $tournament),
                    'player2' => $this->formatPlayerForLive($pair->player2_id, $playerStats, $tournament),
                    'wins' => $row['wins'],
                    'losses' => $row['losses'],
                    'points_for' => $row['points_for'],
                    'points_against' => $row['points_against'],
                    'total_points' => $row['total_points'],
                    'points' => $row['total_points'],
                    'point_diff' => $row['diff'],
                    'ball_percent' => $totalBalls > 0 ? (int) round($row['points_for'] / $totalBalls * 100) : 0,
                    'win_percent' => $row['win_rate'],
                    'is_me' => $myPairId !== null && (int) $pair->id === $myPairId,
                ];
            }
        } else {
            uasort($playerStats, function ($a, $b) {
                if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
                if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
                return ($b['points_for'] - $b['points_against']) <=> ($a['points_for'] - $a['points_against']);
            });

            $position = 1;
            $leaderboard = [];
            foreach ($playerStats as $s) {
                $totalGames = $s['wins'] + $s['losses'];
                $diff = $s['points_for'] - $s['points_against'];
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
                    'is_me' => $userId && (int) $s['id'] === $userId,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'date' => $tournament->start_date->translatedFormat('j F'),
                'time' => $tournament->start_date->format('H:i'),
                'club_name' => $tournament->club?->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
                'status' => $tournament->status,
                'is_paired' => $isPaired,
                'has_playoff' => false,
                'courts_count' => (int) ($tournament->max_participants / 4),
            ],
            'leaderboard' => array_values($leaderboard),
            'rounds' => $roundsOut,
            'playoff' => [],
        ]);
    }

    /**
     * Live для турнира «Just Padel It» — копия liveKingOfCourt() с заменой
     * источников данных на JustPadelIt* (JustPadelItService::getPairStandings,
     * justPadelItRounds(), justPadelItPairs()). Структура ответа идентична KoC.
     */
    private function liveJustPadelIt(Tournament $tournament, $user)
    {
        $userId = $user ? (int) $user->id : null;

        // Если в запросе пришёл player_id — считаем дельту рейтинга для него
        // (нужно когда смотрим из чужого профиля). Иначе — для текущего юзера.
        $targetId = (int) (request()->query('player_id') ?: $userId);

        $jpiPlayers = $tournament->justPadelItPlayers()->with('user')->get();
        $playerStats = [];
        $ratingEvolve = [];
        foreach ($jpiPlayers as $kp) {
            $u = $kp->user;
            if (!$u) continue;
            $playerStats[$u->id] = [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'rating' => $u->rating,
                'level' => $u->level,
                'wins' => (int) $kp->wins,
                'losses' => (int) $kp->losses,
                'draws' => 0,
                'points_for' => (int) $kp->points_for,
                'points_against' => (int) $kp->points_against,
                'total_points' => (int) $kp->total_points,
            ];
            $ratingEvolve[$u->id] = ['current_rating' => (int) $kp->rating_before];
        }

        $rounds = $tournament->justPadelItRounds()
            ->with(['matches' => function ($q) {
                $q->orderBy('court_number');
            }])
            ->orderBy('round_number')
            ->get();

        // Считаем дельту рейтинга для целевого игрока в каждом раунде.
        // Эволюционируем рейтинги ВСЕХ игроков по матчам в порядке раундов
        // (так же как finishTournament), запоминаем pre/post для targetId.
        $jpiService = app(\App\Services\JustPadelItService::class);
        $roundDeltas = [];
        foreach ($rounds as $r) {
            $pre = $ratingEvolve[$targetId]['current_rating'] ?? null;
            foreach ($r->matches as $m) {
                if ($m->status !== 'completed') continue;
                $jpiService->calculateEloForMatch($m, $ratingEvolve);
            }
            $post = $ratingEvolve[$targetId]['current_rating'] ?? null;
            $roundDeltas[$r->id] = ($pre !== null && $post !== null) ? ($post - $pre) : null;
        }
        if (!$tournament->is_rated) { $roundDeltas = []; }

        $roundsOut = [];
        foreach ($rounds as $r) {
            $courtsTotal = $r->matches->count();
            $matchesOut = [];
            foreach ($r->matches as $m) {
                $courtIdx = (int) $m->court_number;
                if ($courtIdx === 1) {
                    $courtTier = 'top';
                } elseif ($courtIdx === $courtsTotal) {
                    $courtTier = 'bottom';
                } else {
                    $courtTier = 'middle';
                }
                $courtLabel = "Корт {$courtIdx}";

                $t1HasMe = $userId !== null && in_array($userId, [
                    (int) $m->team1_player1_id,
                    (int) $m->team1_player2_id,
                ], true);
                $t2HasMe = $userId !== null && in_array($userId, [
                    (int) $m->team2_player1_id,
                    (int) $m->team2_player2_id,
                ], true);

                $matchesOut[] = [
                    'id' => $m->id,
                    'court_number' => $courtIdx,
                    'court_tier' => $courtTier,
                    'court_label' => $courtLabel,
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
            $roundsOut[] = [
                'id' => $r->id,
                'round_number' => $r->round_number,
                'status' => $r->status,
                'matches' => $matchesOut,
                'my_rating_change' => $roundDeltas[$r->id] ?? null,
            ];
        }

        $isPaired = $tournament->isPairedJustPadelIt();

        if ($isPaired) {
            // Фикс-пары: таблица по парам (shape как у Bali — player1/player2).
            $pairs = $tournament->justPadelItPairs()->with(['player1', 'player2'])->get();
            $myPairId = null;
            foreach ($pairs as $p) {
                if ($userId && in_array($userId, [(int) $p->player1_id, (int) $p->player2_id], true)) {
                    $myPairId = (int) $p->id;
                }
            }
            $standings = $jpiService->getPairStandings($tournament);
            $leaderboard = [];
            foreach ($standings as $idx => $row) {
                $pair = $row['pair'];
                $totalBalls = $row['points_for'] + $row['points_against'];
                $leaderboard[] = [
                    'position' => $idx + 1,
                    'pair_id' => $pair->id,
                    'player1' => $this->formatPlayerForLive($pair->player1_id, $playerStats, $tournament),
                    'player2' => $this->formatPlayerForLive($pair->player2_id, $playerStats, $tournament),
                    'wins' => $row['wins'],
                    'losses' => $row['losses'],
                    'points_for' => $row['points_for'],
                    'points_against' => $row['points_against'],
                    'total_points' => $row['total_points'],
                    'points' => $row['total_points'],
                    'point_diff' => $row['diff'],
                    'ball_percent' => $totalBalls > 0 ? (int) round($row['points_for'] / $totalBalls * 100) : 0,
                    'win_percent' => $row['win_rate'],
                    'is_me' => $myPairId !== null && (int) $pair->id === $myPairId,
                ];
            }
        } else {
            uasort($playerStats, function ($a, $b) {
                if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
                if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
                return ($b['points_for'] - $b['points_against']) <=> ($a['points_for'] - $a['points_against']);
            });

            $position = 1;
            $leaderboard = [];
            foreach ($playerStats as $s) {
                $totalGames = $s['wins'] + $s['losses'];
                $diff = $s['points_for'] - $s['points_against'];
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
                    'is_me' => $userId && (int) $s['id'] === $userId,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'date' => $tournament->start_date->translatedFormat('j F'),
                'time' => $tournament->start_date->format('H:i'),
                'club_name' => $tournament->club?->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
                'status' => $tournament->status,
                'is_paired' => $isPaired,
                'has_playoff' => false,
                'courts_count' => (int) ($tournament->max_participants / 4),
            ],
            'leaderboard' => array_values($leaderboard),
            'rounds' => $roundsOut,
            'playoff' => [],
        ]);
    }

    /**
     * Live для турнира «Round Robin» (индивидуальный).
     * Структура ответа как у Король корта (один лидерборд + раунды), поэтому
     * на фронте переиспользуется тот же экран. Ранжирование — победы → разница
     * геймов → личная встреча (через RoundRobinService::standings).
     */
    private function liveRoundRobin(Tournament $tournament, $user)
    {
        $userId = $user ? (int) $user->id : null;
        $targetId = (int) (request()->query('player_id') ?: $userId);

        $rrPlayers = $tournament->roundRobinPlayers()->with('user')->get();
        $playerStats = [];
        $ratingEvolve = [];
        foreach ($rrPlayers as $rp) {
            $u = $rp->user;
            if (!$u) continue;
            $playerStats[$u->id] = [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'rating' => $u->rating,
                'level' => $u->level,
                'wins' => (int) $rp->wins,
                'losses' => (int) $rp->losses,
                'draws' => 0,
                'points_for' => (int) $rp->points_for,
                'points_against' => (int) $rp->points_against,
                'total_points' => (int) $rp->wins, // в RR «очки» = победы
            ];
            $ratingEvolve[$u->id] = ['current_rating' => (int) $rp->rating_before];
        }

        $rounds = $tournament->roundRobinRounds()
            ->with(['matches' => function ($q) {
                $q->orderBy('court_number');
            }])
            ->orderBy('round_number')
            ->get();

        // Дельта рейтинга целевого игрока по раундам (эволюция как в finishTournament).
        $rrService = app(\App\Services\RoundRobinService::class);
        $roundDeltas = [];
        foreach ($rounds as $r) {
            $pre = $ratingEvolve[$targetId]['current_rating'] ?? null;
            foreach ($r->matches as $m) {
                if ($m->status !== 'completed') continue;
                $rrService->calculateEloForMatch($m, $ratingEvolve);
            }
            $post = $ratingEvolve[$targetId]['current_rating'] ?? null;
            $roundDeltas[$r->id] = ($pre !== null && $post !== null) ? ($post - $pre) : null;
        }
        if (!$tournament->is_rated) { $roundDeltas = []; }

        $roundsOut = [];
        foreach ($rounds as $r) {
            $matchesOut = [];
            foreach ($r->matches as $m) {
                $courtIdx = (int) $m->court_number;

                $t1HasMe = $userId !== null && in_array($userId, [
                    (int) $m->team1_player1_id,
                    (int) $m->team1_player2_id,
                ], true);
                $t2HasMe = $userId !== null && in_array($userId, [
                    (int) $m->team2_player1_id,
                    (int) $m->team2_player2_id,
                ], true);

                $matchesOut[] = [
                    'id' => $m->id,
                    'court_number' => $courtIdx,
                    'court_tier' => 'middle', // в RR корты равнозначны
                    'court_label' => "Корт {$courtIdx}",
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
            $roundsOut[] = [
                'id' => $r->id,
                'round_number' => $r->round_number,
                'status' => $r->status,
                'matches' => $matchesOut,
                'my_rating_change' => $roundDeltas[$r->id] ?? null,
            ];
        }

        // Лидерборд в порядке стандингов (победы → разница → личные встречи).
        $leaderboard = [];
        $position = 1;
        foreach ($rrService->standings($tournament) as $s) {
            $id = (int) $s['user_id'];
            $base = $playerStats[$id] ?? null;
            if (!$base) continue;
            $totalGames = (int) $s['wins'] + (int) $s['losses'];
            $totalBalls = (int) $s['points_for'] + (int) $s['points_against'];
            $leaderboard[] = array_merge($base, [
                'position' => $position++,
                'games_played' => $totalGames,
                'point_diff' => (int) $s['diff'],
                'win_percent' => $totalGames > 0 ? (int) round($s['wins'] / $totalGames * 100) : 0,
                'ball_percent' => $totalBalls > 0 ? (int) round($s['points_for'] / $totalBalls * 100) : 0,
                'is_me' => $userId && $id === $userId,
            ]);
        }

        return response()->json([
            'success' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'date' => $tournament->start_date->translatedFormat('j F'),
                'time' => $tournament->start_date->format('H:i'),
                'club_name' => $tournament->club?->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
                'status' => $tournament->status,
                'has_playoff' => false,
                'courts_count' => (int) ($tournament->max_participants / 4),
            ],
            'leaderboard' => array_values($leaderboard),
            'rounds' => $roundsOut,
            'playoff' => [],
        ]);
    }

    /**
     * Live для турнира «Король Корта (Bali Format)».
     * Лидерборд — пары (фиксированные), стандинги с tiebreaker (очки → H2H → геймы).
     * Раунды — как у KOC: top/middle/bottom корты.
     */
    private function liveBaliKoc(Tournament $tournament, $user)
    {
        $userId = $user ? (int) $user->id : null;
        $targetId = (int) (request()->query('player_id') ?: $userId);

        $service = app(\App\Services\BaliKocService::class);
        $pairs = $tournament->baliKocPairs()->with(['player1', 'player2'])->get();

        // Мапа player_id → pair (для is_me/has_me и player_stats)
        $playerToPair = [];
        $playerStats = [];
        foreach ($pairs as $p) {
            foreach ([$p->player1, $p->player2] as $u) {
                if (!$u) continue;
                $playerToPair[$u->id] = $p;
                $playerStats[$u->id] = [
                    'id' => $u->id,
                    'name' => $u->name,
                    'avatar' => $u->avatar,
                    'rating' => $u->rating,
                    'level' => $u->level,
                ];
            }
        }

        // Эволюция рейтингов по раундам — для дельты целевого игрока
        $ratingEvolve = [];
        foreach ($pairs as $p) {
            $ratingEvolve[$p->player1_id] = ['current_rating' => (int) $p->player1_rating_before];
            $ratingEvolve[$p->player2_id] = ['current_rating' => (int) $p->player2_rating_before];
        }

        $rounds = $tournament->baliKocRounds()
            ->with(['matches' => function ($q) {
                $q->orderBy('court_number');
            }])
            ->orderBy('round_number')
            ->get();

        $roundDeltas = [];
        foreach ($rounds as $r) {
            $pre = $ratingEvolve[$targetId]['current_rating'] ?? null;
            foreach ($r->matches as $m) {
                if ($m->status !== 'completed') continue;
                $service->calculateEloForMatch($m, $pairs, $ratingEvolve);
            }
            $post = $ratingEvolve[$targetId]['current_rating'] ?? null;
            $roundDeltas[$r->id] = ($pre !== null && $post !== null) ? ($post - $pre) : null;
        }
        if (!$tournament->is_rated) { $roundDeltas = []; }

        // Сборка раундов с матчами
        $roundsOut = [];
        foreach ($rounds as $r) {
            $courtsTotal = $r->matches->count();
            $matchesOut = [];
            foreach ($r->matches as $m) {
                $courtIdx = (int) $m->court_number;
                if ($courtIdx === 1) {
                    $courtTier = 'top';
                } elseif ($courtIdx === $courtsTotal) {
                    $courtTier = 'bottom';
                } else {
                    $courtTier = 'middle';
                }

                $pair1 = $pairs->firstWhere('id', $m->pair1_id);
                $pair2 = $pairs->firstWhere('id', $m->pair2_id);
                if (!$pair1 || !$pair2) continue;

                $t1Players = [(int) $pair1->player1_id, (int) $pair1->player2_id];
                $t2Players = [(int) $pair2->player1_id, (int) $pair2->player2_id];

                $t1HasMe = $userId !== null && in_array($userId, $t1Players, true);
                $t2HasMe = $userId !== null && in_array($userId, $t2Players, true);

                // Очки за победу на этом корте (подсказка)
                $pointsWin = $service->pointsForMatch((int) $r->round_number, $courtIdx, $courtsTotal);

                $matchesOut[] = [
                    'id' => $m->id,
                    'court_number' => $courtIdx,
                    'court_tier' => $courtTier,
                    'court_label' => "Корт {$courtIdx}",
                    'points_for_win' => $pointsWin,
                    'status' => $m->status,
                    'team1' => [
                        'pair_id' => $pair1->id,
                        'player1' => $this->formatPlayerForLive($pair1->player1_id, $playerStats, $tournament),
                        'player2' => $this->formatPlayerForLive($pair1->player2_id, $playerStats, $tournament),
                        'score' => $m->status === 'completed' ? (int) $m->pair1_games : null,
                        'has_me' => $t1HasMe,
                    ],
                    'team2' => [
                        'pair_id' => $pair2->id,
                        'player1' => $this->formatPlayerForLive($pair2->player1_id, $playerStats, $tournament),
                        'player2' => $this->formatPlayerForLive($pair2->player2_id, $playerStats, $tournament),
                        'score' => $m->status === 'completed' ? (int) $m->pair2_games : null,
                        'has_me' => $t2HasMe,
                    ],
                    'has_me' => $t1HasMe || $t2HasMe,
                ];
            }
            $roundsOut[] = [
                'id' => $r->id,
                'round_number' => $r->round_number,
                'status' => $r->status,
                'matches' => $matchesOut,
                'my_rating_change' => $roundDeltas[$r->id] ?? null,
            ];
        }

        // Лидерборд пар (стандинги с tiebreaker через сервис)
        $standings = $service->getStandings($tournament);
        $myPairId = $userId !== null && isset($playerToPair[$userId])
            ? (int) $playerToPair[$userId]->id
            : null;

        $leaderboard = [];
        foreach ($standings as $idx => $p) {
            $totalGames = (int) $p->games_for + (int) $p->games_against;
            $ballPercent = $totalGames > 0
                ? (int) round((int) $p->games_for / $totalGames * 100)
                : 0;
            $leaderboard[] = [
                'position' => $idx + 1,
                'pair_id' => $p->id,
                'player1' => $this->formatPlayerForLive($p->player1_id, $playerStats, $tournament),
                'player2' => $this->formatPlayerForLive($p->player2_id, $playerStats, $tournament),
                'wins' => (int) $p->wins,
                'losses' => (int) $p->losses,
                'games_for' => (int) $p->games_for,
                'games_against' => (int) $p->games_against,
                'point_diff' => (int) $p->games_for - (int) $p->games_against,
                'ball_percent' => $ballPercent,
                'points' => (int) $p->points,
                'is_me' => $myPairId !== null && (int) $p->id === $myPairId,
            ];
        }

        return response()->json([
            'success' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'date' => $tournament->start_date->translatedFormat('j F'),
                'time' => $tournament->start_date->format('H:i'),
                'club_name' => $tournament->club?->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
                'status' => $tournament->status,
                'has_playoff' => false,
                'courts_count' => (int) ($pairs->count() / 2),
            ],
            'leaderboard' => $leaderboard,
            'rounds' => $roundsOut,
            'playoff' => [],
        ]);
    }

    /**
     * Live для группового+плей-офф (type=team).
     * Команды (пары) в группах, round_number на матчах группы.
     */
    private function liveTeam(Tournament $tournament, $user)
    {
        $userId = $user ? (int) $user->id : null;

        // Карта дельт рейтинга для каждого матча текущего юзера (team-based)
        $myMatchDeltas = [];
        if ($user && $tournament->is_rated) {
            try {
                $userMatchesForDelta = $this->getTeamBasedMatches($tournament, (int) $user->id);
                foreach ($userMatchesForDelta as $um) {
                    if (isset($um['id'])) {
                        $myMatchDeltas[(int) $um['id']] = (int) ($um['rating_change'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                $myMatchDeltas = [];
            }
        }

        $teamGroups = $tournament->teamGroups()
            ->with([
                'standings.team.player1',
                'standings.team.player2',
                'matches.team1.player1',
                'matches.team1.player2',
                'matches.team2.player1',
                'matches.team2.player2',
            ])
            ->orderBy('id')
            ->get();

        // Сервис нужен для правильной сортировки (учитывает личную встречу)
        $teamService = app(\App\Services\TeamTournamentService::class);

        // Чтобы определить has_me для команды — соберём id моих команд
        $myTeamIds = [];
        if ($userId) {
            $myTeamIds = \App\Models\TournamentTeam::where('tournament_id', $tournament->id)
                ->where(function ($q) use ($userId) {
                    $q->where('player1_id', $userId)->orWhere('player2_id', $userId);
                })
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->all();
        }

        $fmtPlayer = function ($p) {
            if (!$p) return null;
            return [
                'id' => $p->id,
                'name' => $p->name,
                'avatar' => $p->avatar,
                'verified' => (bool) $p->level_verified,
            ];
        };

        $fmtTeam = function ($team) use ($fmtPlayer, $myTeamIds) {
            if (!$team) return null;
            return [
                'id' => $team->id,
                'name' => $team->name,
                'player1' => $fmtPlayer($team->player1),
                'player2' => $fmtPlayer($team->player2),
                'has_me' => in_array((int) $team->id, $myTeamIds, true),
            ];
        };

        $groupsOut = [];
        foreach ($teamGroups as $group) {
            // Standings — берём через сервис чтобы учитывалась личная встреча
            // при равных очках (как в админке).
            $sortedStandings = $teamService->getSortedStandings($group);

            $standings = [];
            $position = 1;
            foreach ($sortedStandings as $row) {
                $teamId = (int) $row['team_id'];
                // Получаем команду из подгруженных standings (для аватаров)
                $standingObj = $group->standings->firstWhere('team_id', $teamId);
                $team = $standingObj?->team;
                if (!$team) continue;

                $diff = (int) $row['points_for'] - (int) $row['points_against'];
                $totalBalls = (int) $row['points_for'] + (int) $row['points_against'];
                $ballPercent = $totalBalls > 0
                    ? (int) round((int) $row['points_for'] / $totalBalls * 100)
                    : 0;

                $standings[] = [
                    'position' => $position++,
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    'player1' => $fmtPlayer($team->player1),
                    'player2' => $fmtPlayer($team->player2),
                    'played' => (int) $row['played'],
                    'won' => (int) $row['won'],
                    'lost' => (int) $row['lost'],
                    'draws' => max(0, (int) $row['played'] - (int) $row['won'] - (int) $row['lost']),
                    'points_for' => (int) $row['points_for'],
                    'points_against' => (int) $row['points_against'],
                    'point_diff' => $diff,
                    'ball_percent' => $ballPercent,
                    'points' => (int) $row['points'],
                    'has_me' => in_array((int) $team->id, $myTeamIds, true),
                ];
            }

            // Матчи группированные по round_number
            $byRound = [];
            foreach ($group->matches as $m) {
                $rn = (int) $m->round_number;
                $byRound[$rn] ??= [];

                $t1 = $fmtTeam($m->team1);
                $t2 = $fmtTeam($m->team2);

                $byRound[$rn][] = [
                    'id' => $m->id,
                    'court_number' => $m->court_number,
                    'status' => $m->status,
                    'team1' => array_merge($t1 ?? [], [
                        'score' => $m->status === 'completed' ? (int) $m->team1_score : null,
                    ]),
                    'team2' => array_merge($t2 ?? [], [
                        'score' => $m->status === 'completed' ? (int) $m->team2_score : null,
                    ]),
                    'has_me' => ($t1['has_me'] ?? false) || ($t2['has_me'] ?? false),
                    'my_rating_change' => $myMatchDeltas[(int) $m->id] ?? null,
                ];
            }
            ksort($byRound);

            $rounds = [];
            foreach ($byRound as $rn => $matches) {
                // Статус раунда: если есть in_progress → in_progress; все completed → completed; иначе pending
                $hasInProgress = false;
                $allCompleted = true;
                foreach ($matches as $m) {
                    if ($m['status'] === 'in_progress') $hasInProgress = true;
                    if ($m['status'] !== 'completed') $allCompleted = false;
                }
                $roundStatus = $hasInProgress
                    ? 'in_progress'
                    : ($allCompleted ? 'completed' : 'pending');

                $rounds[] = [
                    'id' => $group->id * 1000 + $rn,
                    'round_number' => $rn,
                    'status' => $roundStatus,
                    'matches' => $matches,
                ];
            }

            $groupsOut[] = [
                'id' => $group->id,
                'name' => $group->name,
                'standings' => $standings,
                'rounds' => $rounds,
            ];
        }

        // Показываем плей-офф если он есть в БД — независимо от has_playoff.
        // Так покрываем team-турниры со старым флагом и любые случаи с
        // существующими матчами плей-офф.
        $playoff = ($tournament->has_playoff || $tournament->playoffMatches()->exists())
            ? $this->getPlayoffForLive($tournament, $user)
            : [];

        return response()->json([
            'success' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'date' => $tournament->start_date->translatedFormat('j F'),
                'time' => $tournament->start_date->format('H:i'),
                'club_name' => $tournament->club?->name ?? 'Клуб',
                'format' => $tournament->type,
                'format_name' => $tournament->type_name,
                'status' => $tournament->status,
                'has_playoff' => (bool) $tournament->has_playoff,
            ],
            'groups' => $groupsOut,
            'playoff' => $playoff,
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

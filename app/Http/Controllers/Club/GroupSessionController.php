<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubGroup;
use App\Models\ClubGroupSession;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Services\CourtScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GroupSessionController extends Controller
{
    public function __construct(private CourtScheduleService $scheduleService) {}

    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function index(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $courtIds = $club->courts()->pluck('id');

        // Базовый запрос с фильтрами (группа/статус)
        $filters = function ($q) use ($courtIds, $request) {
            $q->whereIn('court_id', $courtIds);
            if ($gid = $request->get('group_id')) $q->where('group_id', $gid);
            if ($status = $request->get('status')) $q->where('status', $status);
        };

        // Неделя пн–вс для выбранной даты (как в расписании кортов)
        $today = now('Asia/Almaty')->format('Y-m-d');
        $date = $request->get('date', $today);
        $selected = Carbon::parse($date);
        $weekStart = $selected->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $prevWeek = $weekStart->copy()->subWeek()->format('Y-m-d');
        $nextWeek = $weekStart->copy()->addWeek()->format('Y-m-d');
        $weekRange = $weekStart->locale('ru')->isoFormat('D MMM') . ' — ' . $weekEnd->locale('ru')->isoFormat('D MMM YYYY');

        // Занятия недели
        $sessions = ClubGroupSession::where($filters)
            ->whereBetween('date', [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')])
            ->with(['group:id,name,price_per_session', 'court:id,name', 'coach:id,name,first_name,last_name'])
            ->withCount([
                'attendance as attended_count' => fn($q) => $q->where('attended', true),
                'attendance as absent_count' => fn($q) => $q->where('attended', false),
                'attendance as charged_count' => fn($q) => $q->where('charged', true),
            ])
            ->get();

        // Тренеры клуба (фото + инициалы + цвет-заглушка)
        $coaches = $club->clubCoaches()->with('user')->get();
        $palette = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#22c55e', '#06b6d4', '#ef4444', '#14b8a6'];
        $coachMeta = [];
        foreach ($coaches as $cc) {
            if (!$cc->user) continue;
            $coachMeta[$cc->user_id] = $this->buildCoachMeta($cc->user, $cc->photo, $palette);
            $coachMeta[$cc->user_id]['rate_group'] = $cc->rate_group !== null ? (float) $cc->rate_group : null;
        }
        // Тренеры занятий, которых нет в списке клубных тренеров — добиваем по юзеру
        $nowAlmaty = now('Asia/Almaty');
        foreach ($sessions as $s) {
            if ($s->coach_id && !isset($coachMeta[$s->coach_id]) && $s->coach) {
                $coachMeta[$s->coach_id] = $this->buildCoachMeta($s->coach, null, $palette);
            }

            // UI-статус: запланированное, у которого время уже прошло, можно проводить
            $ui = $s->status;
            if ($s->status === 'planned') {
                $endsAt = Carbon::parse($s->date->format('Y-m-d') . ' ' . $s->end_time, 'Asia/Almaty');
                if ($nowAlmaty->gte($endsAt)) $ui = 'ready';
            }
            $s->ui_status = $ui;
        }

        // Строки = времена начала среди видимых занятий
        $times = $sessions->map(fn($s) => Carbon::parse($s->start_time)->format('H:i'))
            ->unique()->sort()->values();

        // Матрица: matrix[time][date] = [сессии]
        $matrix = [];
        foreach ($sessions as $s) {
            $t = Carbon::parse($s->start_time)->format('H:i');
            $d = $s->date->format('Y-m-d');
            $matrix[$t][$d][] = $s;
        }

        // Столбцы — все 7 дней недели (включая пустые), с дневной сводкой
        $columns = [];
        for ($i = 0; $i < 7; $i++) {
            $c = $weekStart->copy()->addDays($i);
            $d = $c->format('Y-m-d');
            $dayS = $sessions->filter(fn($s) => $s->date->format('Y-m-d') === $d);
            $columns[] = [
                'date'      => $d,
                'dayNum'    => $c->format('d'),
                'month'     => $c->locale('ru')->isoFormat('MMMM'),
                'weekday'   => mb_strtoupper($c->locale('ru')->isoFormat('dd')),
                'isToday'   => $d === $today,
                'total'     => $dayS->count(),
                'attended'  => (int) $dayS->where('status', 'held')->sum('attended_count'),
                'cancelled' => $dayS->where('status', 'cancelled')->count(),
            ];
        }

        $totalSessions = ClubGroupSession::where($filters)->count();

        // Занятия «к списанию» (бейдж) — единый расчёт в модели.
        $pendingConductCount = ClubGroupSession::pendingConductCountForClub($club);

        $groups = ClubGroup::where('club_id', $club->id)->orderBy('name')->get();
        $courts = $club->courts()->where('is_active', true)->orderBy('sort_order')->get();

        return view('club.group-sessions.index', compact(
            'columns', 'times', 'matrix', 'coachMeta',
            'prevWeek', 'nextWeek', 'weekRange', 'date', 'totalSessions', 'today',
            'pendingConductCount',
            'groups', 'courts', 'coaches', 'club'
        ));
    }

    /** Метаданные тренера для аватарки: фото / инициалы / цвет-заглушка. */
    private function buildCoachMeta($user, ?string $photo, array $palette): array
    {
        $fn = $user->first_name ?: $user->name;
        $ln = $user->last_name;
        $initials = mb_strtoupper(mb_substr((string) $fn, 0, 1) . ($ln ? mb_substr($ln, 0, 1) : ''));
        return [
            'photo'    => $photo,
            'initials' => $initials ?: '?',
            'color'    => $palette[$user->id % count($palette)],
            'name'     => $user->full_name,
        ];
    }

    /** Отчёт расписания: кто/когда какую группу проводил за период. */
    public function report(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $courtIds = $club->courts()->pluck('id');

        $today = now('Asia/Almaty');
        $from = $request->filled('from')
            ? Carbon::parse($request->get('from'))
            : $today->copy()->startOfWeek(Carbon::MONDAY);
        $to = $request->filled('to')
            ? Carbon::parse($request->get('to'))
            : $today->copy()->endOfWeek(Carbon::SUNDAY);

        $sessions = ClubGroupSession::whereIn('court_id', $courtIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with([
                'group:id,name', 'court:id,name',
                'coach:id,name,first_name,last_name',
                'courtBooking:id,client_name,coach_id',
                'courtBooking.coach:id,name,first_name,last_name',
            ])
            ->withCount([
                'attendance as attended_count' => fn($q) => $q->where('attended', true),
                'attendance as charged_count' => fn($q) => $q->where('charged', true),
            ])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        return view('club.group-sessions.report', [
            'club' => $club,
            'sessions' => $sessions,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $validated = $request->validate([
            'group_id' => 'required|exists:club_groups,id',
            'court_id' => 'required|exists:courts,id',
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'slots' => 'required|integer|min:1|max:8',
            'coach_id' => 'nullable|exists:users,id',
        ]);

        $group = ClubGroup::find($validated['group_id']);
        $court = Court::find($validated['court_id']);
        if ($group->club_id !== $club->id || $court->club_id !== $club->id) abort(403);

        if ($group->status === 'archived') {
            return back()->with('error', 'Группа в архиве — верните её в активные, чтобы создавать занятия');
        }

        $startTime = $validated['start_time'];
        $endTime = Carbon::parse($startTime)->addMinutes($validated['slots'] * ($court->slot_duration ?: 60))->format('H:i');

        if (!$this->scheduleService->canBook($court, $validated['date'], $startTime, $endTime)) {
            return back()->with('error', 'Корт занят на это время');
        }

        $coachId = $validated['coach_id'] ?? $group->coach_id;

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => $validated['date'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'client_name' => 'Группа: ' . $group->name,
            'client_phone' => null,
            'status' => 'confirmed',
            'booked_by' => auth()->id(),
            'price' => 0,
            'booking_type' => 'group',
            'coach_id' => $coachId,
        ]);

        $session = ClubGroupSession::create([
            'group_id' => $group->id,
            'court_id' => $court->id,
            'court_booking_id' => $booking->id,
            'date' => $validated['date'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'coach_id' => $coachId,
            'status' => 'planned',
        ]);

        \App\Models\ActivityLog::log('created', 'ClubGroupSession', $session->id,
            "Занятие группы «{$group->name}»: {$court->name}, {$validated['date']} {$startTime}–{$endTime}", clubId: $club->id);

        return redirect()->route('club.groupSessions.index')->with('success', 'Занятие создано');
    }

    public function show(ClubGroupSession $session)
    {
        $club = $this->getClub();
        $this->authorizeSession($club, $session);

        $session->load(['group.members.client', 'group.members.enrollments', 'group.members.attendance', 'court', 'coach', 'attendance']);
        $members = $session->group->members()->where('status', 'active')->with('client')->get();
        $existing = $session->attendance->keyBy('group_member_id');

        return view('club.group-sessions.show', compact('session', 'members', 'existing', 'club'));
    }

    public function conduct(Request $request, ClubGroupSession $session)
    {
        $club = $this->getClub();
        $this->authorizeSession($club, $session);

        if ($session->status === 'cancelled') {
            return back()->with('error', 'Занятие отменено');
        }

        if ($session->status === 'held') {
            return back()->with('error', 'Занятие уже проведено');
        }

        // Провести можно только после окончания занятия (по расписанию).
        // Время в БД хранится как локальное клубное (Алматы), сервер живёт в UTC —
        // парсим end_time явно в Asia/Almaty и сравниваем с now() в том же TZ.
        $endsAt = Carbon::parse(
            $session->date->format('Y-m-d') . ' ' . $session->end_time,
            'Asia/Almaty'
        );
        if (now('Asia/Almaty')->lt($endsAt)) {
            return back()->with('error', 'Занятие ещё не закончилось — отметить посещаемость можно после ' . $endsAt->format('H:i d.m.Y'));
        }

        $rows = $request->input('attendance', []);
        $sessionDate = $session->date->toDateString();

        // Списать можно только если: пришёл, НЕ пробное, НЕ заморожен и остаток > 0.
        foreach ($rows as $memberId => $row) {
            $member = \App\Models\ClubGroupMember::find($memberId);
            if (!$member || $member->group_id !== $session->group_id) abort(403);

            $attended = !empty($row['attended']);
            $isTrial = !empty($row['is_trial']);
            $frozen = $member->isFrozenOn($sessionDate);
            $wantCharge = $attended && !empty($row['charged']) && !$isTrial && !$frozen;
            if ($wantCharge && $member->remaining <= 0) {
                return back()->with('error', "У участника {$member->client->name} закончились занятия — продлите пакет");
            }
        }

        // Применяем посещаемость
        foreach ($rows as $memberId => $row) {
            $member = \App\Models\ClubGroupMember::find($memberId);
            if (!$member || $member->group_id !== $session->group_id) continue;

            $attended = !empty($row['attended']);
            $isTrial = !empty($row['is_trial']);
            $frozen = $member->isFrozenOn($sessionDate);
            // Заморозка и пробное не тратят пакет.
            $charged = $attended && !empty($row['charged']) && !$isTrial && !$frozen;
            $trialAmount = $isTrial ? (int) ($row['trial_amount'] ?? 0) : null;

            \App\Models\ClubGroupAttendance::updateOrCreate(
                ['session_id' => $session->id, 'group_member_id' => $member->id],
                ['attended' => $attended, 'charged' => $charged, 'is_trial' => $isTrial, 'trial_amount' => $trialAmount]
            );
        }

        $session->update([
            'status' => 'held',
            'held_at' => now(),
            'conducted_by' => auth()->id(),
        ]);

        \App\Models\ActivityLog::log('updated', 'ClubGroupSession', $session->id,
            "Занятие проведено: «{$session->group->name}»", clubId: $club->id);

        return redirect()->route('club.groupSessions.index')->with('success', 'Занятие проведено');
    }

    public function cancel(ClubGroupSession $session)
    {
        $club = $this->getClub();
        $this->authorizeSession($club, $session);

        $session->update(['status' => 'cancelled']);
        if ($session->courtBooking) {
            $session->courtBooking->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }

        \App\Models\ActivityLog::log('cancelled', 'ClubGroupSession', $session->id,
            "Занятие отменено: «{$session->group->name}»", clubId: $club->id);

        return back()->with('success', 'Занятие отменено, корт освобождён');
    }

    /** Добавить пробного гостя (не члена группы) к занятию. Сумма опциональна (0 = бесплатно). */
    public function addTrialGuest(Request $request, ClubGroupSession $session)
    {
        $club = $this->getClub();
        $this->authorizeSession($club, $session);

        if ($session->status === 'cancelled') {
            return back()->with('error', 'Занятие отменено');
        }

        $validated = $request->validate([
            'client_id' => 'required|exists:club_clients,id',
            'trial_amount' => 'nullable|integer|min:0',
        ]);

        $client = \App\Models\ClubClient::find($validated['client_id']);
        if (!$client || $client->club_id !== $club->id) abort(403);

        // Не дублируем, если гость уже добавлен пробным к этому занятию.
        $exists = \App\Models\ClubGroupAttendance::where('session_id', $session->id)
            ->where('client_id', $client->id)->exists();
        if ($exists) {
            return back()->with('error', 'Гость уже добавлен к занятию');
        }

        \App\Models\ClubGroupAttendance::create([
            'session_id' => $session->id,
            'client_id' => $client->id,
            'attended' => true,
            'charged' => false,
            'is_trial' => true,
            'trial_amount' => (int) ($validated['trial_amount'] ?? 0),
        ]);

        return back()->with('success', 'Пробный гость добавлен');
    }

    /** Убрать пробного гостя из занятия. */
    public function removeTrialGuest(ClubGroupSession $session, \App\Models\ClubGroupAttendance $attendance)
    {
        $club = $this->getClub();
        $this->authorizeSession($club, $session);

        if ($attendance->session_id !== $session->id || $attendance->client_id === null) abort(403);

        $attendance->delete();
        return back()->with('success', 'Пробный гость убран');
    }

    private function authorizeSession($club, ClubGroupSession $session): void
    {
        if (!$club) abort(403);
        $courtIds = $club->courts()->pluck('id')->all();
        if (!in_array($session->court_id, $courtIds, true)) abort(403);
        if ($session->group && $session->group->club_id !== $club->id) abort(403);
    }
}

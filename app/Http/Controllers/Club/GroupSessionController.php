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
        $query = ClubGroupSession::whereIn('court_id', $courtIds)
            ->with(['group', 'court', 'coach'])
            ->withCount(['attendance as attended_count' => fn($q) => $q->where('attended', true)]);

        if ($gid = $request->get('group_id')) $query->where('group_id', $gid);
        if ($status = $request->get('status')) $query->where('status', $status);
        if ($date = $request->get('date')) $query->whereDate('date', $date);

        $sessions = $query->orderByDesc('date')->orderByDesc('start_time')->paginate(30)->withQueryString();
        $groups = ClubGroup::where('club_id', $club->id)->orderBy('name')->get();
        $courts = $club->courts()->where('is_active', true)->orderBy('sort_order')->get();
        $coaches = $club->clubCoaches()->with('user')->get();

        return view('club.group-sessions.index', compact('sessions', 'groups', 'courts', 'coaches', 'club'));
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
        $endsAt = Carbon::parse($session->date->format('Y-m-d') . ' ' . $session->end_time);
        if (now()->lt($endsAt)) {
            return back()->with('error', 'Занятие ещё не закончилось — отметить посещаемость можно после ' . $endsAt->format('H:i d.m.Y'));
        }

        $rows = $request->input('attendance', []);

        // Проверка: у отмеченных «пришёл + списать» должен быть остаток > 0
        foreach ($rows as $memberId => $row) {
            $attended = !empty($row['attended']);
            $charged = !empty($row['charged']);
            if ($attended && $charged) {
                $member = \App\Models\ClubGroupMember::find($memberId);
                if (!$member || $member->group_id !== $session->group_id) abort(403);
                if ($member->remaining <= 0) {
                    return back()->with('error', "У участника {$member->client->name} закончились занятия — продлите пакет");
                }
            }
        }

        // Применяем посещаемость
        foreach ($rows as $memberId => $row) {
            $member = \App\Models\ClubGroupMember::find($memberId);
            if (!$member || $member->group_id !== $session->group_id) continue;
            $attended = !empty($row['attended']);
            $charged = $attended && !empty($row['charged']);

            \App\Models\ClubGroupAttendance::updateOrCreate(
                ['session_id' => $session->id, 'group_member_id' => $member->id],
                ['attended' => $attended, 'charged' => $charged]
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

    private function authorizeSession($club, ClubGroupSession $session): void
    {
        if (!$club) abort(403);
        $courtIds = $club->courts()->pluck('id')->all();
        if (!in_array($session->court_id, $courtIds, true)) abort(403);
        if ($session->group && $session->group->club_id !== $club->id) abort(403);
    }
}

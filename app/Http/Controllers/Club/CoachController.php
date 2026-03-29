<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubCoach;
use App\Models\CoachSchedule;
use App\Models\CoachScheduleOverride;
use App\Models\User;
use Illuminate\Http\Request;

class CoachController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function index()
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $coaches = ClubCoach::where('club_id', $club->id)
            ->with(['user', 'schedules'])
            ->get();

        return view('club.coaches.index', compact('coaches'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'specialization' => 'nullable|string|max:255',
            'hourly_rate' => 'nullable|numeric|min:0',
        ]);

        $user = User::findOrFail($validated['user_id']);
        $user->update(['role' => 'coach']);

        ClubCoach::create([
            'club_id' => $club->id,
            'user_id' => $validated['user_id'],
            'specialization' => $validated['specialization'] ?? null,
            'hourly_rate' => $validated['hourly_rate'] ?? null,
        ]);

        return back()->with('success', 'Тренер добавлен!');
    }

    public function update(Request $request, User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $coach = ClubCoach::where('club_id', $club->id)->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'specialization' => 'nullable|string|max:255',
            'hourly_rate' => 'nullable|numeric|min:0',
        ]);

        $coach->update($validated);

        return back()->with('success', 'Тренер обновлён!');
    }

    public function destroy(User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $coach = ClubCoach::where('club_id', $club->id)->where('user_id', $user->id)->firstOrFail();
        $coach->delete();

        $hasOtherCoachProfiles = ClubCoach::where('user_id', $user->id)->exists();
        if (!$hasOtherCoachProfiles) {
            $user->update(['role' => 'player']);
        }

        return back()->with('success', 'Тренер удалён!');
    }

    public function schedule(User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $coach = ClubCoach::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->with(['schedules', 'overrides', 'user'])
            ->firstOrFail();

        $dayNames = [
            1 => 'Понедельник',
            2 => 'Вторник',
            3 => 'Среда',
            4 => 'Четверг',
            5 => 'Пятница',
            6 => 'Суббота',
            7 => 'Воскресенье',
        ];

        return view('club.coaches.schedule', compact('coach', 'dayNames'));
    }

    public function updateSchedule(Request $request, User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $coach = ClubCoach::where('club_id', $club->id)->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'schedules' => 'nullable|array',
            'schedules.*.day_of_week' => 'required|integer|min:1|max:7',
            'schedules.*.start_time' => 'required|date_format:H:i',
            'schedules.*.end_time' => 'required|date_format:H:i',
        ]);

        $coach->schedules()->delete();

        if (!empty($validated['schedules'])) {
            foreach ($validated['schedules'] as $schedule) {
                CoachSchedule::create([
                    'club_coach_id' => $coach->id,
                    'day_of_week' => $schedule['day_of_week'],
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                ]);
            }
        }

        return back()->with('success', 'Расписание обновлено!');
    }

    public function addOverride(Request $request, User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $coach = ClubCoach::where('club_id', $club->id)->where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'is_available' => 'required|boolean',
            'reason' => 'nullable|string|max:255',
        ]);

        $coach->overrides()->where('date', $validated['date'])->delete();

        CoachScheduleOverride::create([
            'club_coach_id' => $coach->id,
            'date' => $validated['date'],
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
            'is_available' => $validated['is_available'],
            'reason' => $validated['reason'] ?? null,
        ]);

        return back()->with('success', 'Исключение добавлено!');
    }

    public function deleteOverride(CoachScheduleOverride $override)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $override->delete();

        return back()->with('success', 'Исключение удалено!');
    }

    public function searchUsers(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return response()->json([]);

        $q = $request->get('q', '');
        if (mb_strlen($q) < 2) return response()->json([]);

        $existingCoachIds = ClubCoach::where('club_id', $club->id)->pluck('user_id');

        $users = User::where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            })
            ->whereNotIn('id', $existingCoachIds)
            ->limit(10)
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->full_name,
                'phone' => $user->phone,
                'email' => $user->email,
            ]);

        return response()->json($users);
    }
}

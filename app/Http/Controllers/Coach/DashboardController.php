<?php

namespace App\Http\Controllers\Coach;

use App\Http\Controllers\Controller;
use App\Models\ClubCoach;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class DashboardController extends Controller
{
    /**
     * Расписание тренера (просмотр, как у админа) + смена пароля.
     */
    public function index(Request $request)
    {
        $cc = ClubCoach::where('user_id', auth()->id())
            ->with(['user', 'schedules', 'overrides', 'rates', 'club'])
            ->first();

        if (!$cc) {
            return view('coach.schedule', ['cc' => null]);
        }

        $date = $request->get('date', now()->format('Y-m-d'));
        ['timeSlots' => $timeSlots, 'schedule' => $schedule] = $cc->daySchedule($date);

        // Кол-во часов занятий (брони) на выбранный день — по фактическим броням.
        $busyHours = 0.0;
        $dayBookings = \App\Models\CourtBooking::where('coach_id', $cc->user_id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->get();
        foreach ($dayBookings as $b) {
            $start = Carbon::parse($b->start_time);
            $end = Carbon::parse($b->end_time);
            $mins = $end->diffInMinutes($start, false);
            if ($mins <= 0) $mins += 1440; // переход через полночь
            $busyHours += $mins / 60;
        }

        // Навигация по неделе
        $selectedDate = Carbon::parse($date);
        $weekStart = $selectedDate->copy()->startOfWeek(Carbon::MONDAY);
        $prevWeek = $weekStart->copy()->subWeek()->format('Y-m-d');
        $nextWeek = $weekStart->copy()->addWeek()->format('Y-m-d');

        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $weekDays[] = [
                'date' => $d->format('Y-m-d'),
                'dayName' => $d->locale('ru')->isoFormat('dd'),
                'dayNum' => $d->format('d'),
                'month' => $d->locale('ru')->isoFormat('MMM'),
                'isSelected' => $d->format('Y-m-d') === $date,
                'isToday' => $d->format('Y-m-d') === now()->format('Y-m-d'),
            ];
        }

        return view('coach.schedule', compact(
            'cc', 'date', 'schedule', 'timeSlots', 'weekDays', 'prevWeek', 'nextWeek', 'busyHours'
        ));
    }

    /**
     * Тренер меняет собственный пароль.
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(6)],
        ], [
            'current_password.required' => 'Введите текущий пароль',
            'current_password.current_password' => 'Текущий пароль указан неверно',
            'password.required' => 'Введите новый пароль',
            'password.confirmed' => 'Новый пароль и подтверждение не совпадают',
            'password.min' => 'Новый пароль должен быть не менее :min символов',
        ]);

        // password имеет cast 'hashed' — хешируется автоматически.
        auth()->user()->update(['password' => $request->input('password')]);

        return back()->with('success', 'Пароль изменён');
    }
}

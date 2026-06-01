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
            $busyHours += $this->bookingMinutes($b) / 60;
        }

        // Навигация по неделе
        $selectedDate = Carbon::parse($date);
        $weekStart = $selectedDate->copy()->startOfWeek(Carbon::MONDAY);
        $prevWeek = $weekStart->copy()->subWeek()->format('Y-m-d');
        $nextWeek = $weekStart->copy()->addWeek()->format('Y-m-d');

        // Часы занятий по каждому дню недели (для бейджей в навигации).
        $weekEnd = $weekStart->copy()->addDays(6);
        $weekBookings = \App\Models\CourtBooking::where('coach_id', $cc->user_id)
            ->whereDate('date', '>=', $weekStart->format('Y-m-d'))
            ->whereDate('date', '<=', $weekEnd->format('Y-m-d'))
            ->where('status', 'confirmed')
            ->get();
        $hoursByDate = [];
        foreach ($weekBookings as $b) {
            $key = Carbon::parse($b->date)->format('Y-m-d');
            $hoursByDate[$key] = ($hoursByDate[$key] ?? 0) + $this->bookingMinutes($b) / 60;
        }

        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $key = $d->format('Y-m-d');
            $weekDays[] = [
                'date' => $key,
                'dayName' => $d->locale('ru')->isoFormat('dd'),
                'dayNum' => $d->format('d'),
                'month' => $d->locale('ru')->isoFormat('MMM'),
                'isSelected' => $key === $date,
                'isToday' => $key === now()->format('Y-m-d'),
                'hours' => $hoursByDate[$key] ?? 0,
            ];
        }

        return view('coach.schedule', compact(
            'cc', 'date', 'schedule', 'timeSlots', 'weekDays', 'prevWeek', 'nextWeek', 'busyHours'
        ));
    }

    /**
     * Длительность брони в минутах (по времени суток, с учётом перехода через полночь).
     */
    private function bookingMinutes($booking): int
    {
        $s = Carbon::parse($booking->start_time);
        $e = Carbon::parse($booking->end_time);
        $mins = ($e->hour * 60 + $e->minute) - ($s->hour * 60 + $s->minute);
        if ($mins <= 0) $mins += 1440; // бронь через полночь
        return $mins;
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

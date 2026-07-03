<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubCoach;
use App\Models\CourtBooking;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileCoachController extends Controller
{
    /**
     * Расписание тренера на день (просмотр) + недельная навигация.
     * Повторяет логику веб-кабинета тренера (Coach\DashboardController).
     */
    public function schedule(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isCoach(), 403, 'Доступно только тренерам');

        $cc = ClubCoach::where('user_id', $user->id)->with('club')->first();
        if (!$cc) {
            return response()->json(
                ['message' => 'Вы не привязаны к клубу как тренер'],
                403
            );
        }

        $date = $request->query('date', now()->format('Y-m-d'));
        ['timeSlots' => $timeSlots, 'schedule' => $schedule] = $cc->daySchedule($date);

        // Слоты дня.
        $slots = [];
        foreach ($timeSlots as $time) {
            $s = $schedule[$time];
            $slot = ['time' => $time, 'status' => $s['status']];
            if ($s['status'] === 'booked' && isset($s['booking'])) {
                $b = $s['booking'];
                $slot['booking'] = [
                    'court' => $b->court?->name,
                    'client' => $b->client_name ?: ($b->bookedByUser?->name),
                    'start' => $this->hm($b->start_time),
                    'end' => $this->hm($b->end_time),
                ];
            }
            $slots[] = $slot;
        }

        // Часы занятости на выбранный день.
        $busyHours = 0.0;
        $dayBookings = CourtBooking::where('coach_id', $cc->user_id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->get();
        foreach ($dayBookings as $b) {
            $busyHours += $this->bookingMinutes($b) / 60;
        }

        // Недельная навигация с часами по дням.
        $selected = Carbon::parse($date);
        $weekStart = $selected->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(6);
        $weekBookings = CourtBooking::where('coach_id', $cc->user_id)
            ->whereDate('date', '>=', $weekStart->format('Y-m-d'))
            ->whereDate('date', '<=', $weekEnd->format('Y-m-d'))
            ->where('status', 'confirmed')
            ->get();
        $hoursByDate = [];
        foreach ($weekBookings as $b) {
            $key = Carbon::parse($b->date)->format('Y-m-d');
            $hoursByDate[$key] = ($hoursByDate[$key] ?? 0) + $this->bookingMinutes($b) / 60;
        }

        $week = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $key = $d->format('Y-m-d');
            $week[] = [
                'date' => $key,
                'day_name' => $d->locale('ru')->isoFormat('dd'),
                'day_num' => $d->format('d'),
                'is_today' => $key === now()->format('Y-m-d'),
                'is_selected' => $key === $date,
                'hours' => round($hoursByDate[$key] ?? 0, 1),
            ];
        }

        return response()->json([
            'coach' => [
                'name' => $user->name,
                'club_name' => $cc->club?->name,
            ],
            'date' => $date,
            'busy_hours' => round($busyHours, 1),
            'slots' => $slots,
            'week' => $week,
        ]);
    }

    private function hm($time): string
    {
        return Carbon::parse($time)->format('H:i');
    }

    private function bookingMinutes($booking): int
    {
        $s = Carbon::parse($booking->start_time);
        $e = Carbon::parse($booking->end_time);
        $mins = ($e->hour * 60 + $e->minute) - ($s->hour * 60 + $s->minute);
        if ($mins <= 0) {
            $mins += 1440;
        }
        return $mins;
    }
}

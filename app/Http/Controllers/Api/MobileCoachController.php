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

        $dayBookings = CourtBooking::where('coach_id', $cc->user_id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->with('court')
            ->get();

        // Полный набор часовых слотов: рабочие часы + часы броней. Так тренер
        // видит свои занятия, даже если рабочее расписание в клубе не задано.
        $times = array_values($timeSlots);
        foreach ($dayBookings as $b) {
            foreach ($this->bookingHourSlots($b) as $t) {
                if (!in_array($t, $times, true)) {
                    $times[] = $t;
                }
            }
        }
        sort($times);

        $slots = [];
        foreach ($times as $time) {
            $st = $schedule[$time] ?? null;
            if ($st === null) {
                $booking = $this->bookingAt($dayBookings, $time);
                $st = $booking
                    ? ['status' => 'booked', 'booking' => $booking]
                    : ['status' => 'free'];
            }
            $slot = ['time' => $time, 'status' => $st['status']];
            if ($st['status'] === 'booked' && isset($st['booking'])) {
                $b = $st['booking'];
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

    private function toMin(string $time): int
    {
        $p = Carbon::parse($time);
        return $p->hour * 60 + $p->minute;
    }

    /** Список часовых слотов (HH:MM), которые покрывает бронь. */
    private function bookingHourSlots($booking): array
    {
        $start = $this->toMin($booking->start_time);
        $end = $this->toMin($booking->end_time);
        if ($end <= $start) {
            $end += 1440;
        }
        $out = [];
        for ($m = $start; $m < $end; $m += 60) {
            $mm = $m % 1440;
            $out[] = sprintf('%02d:%02d', intdiv($mm, 60), $mm % 60);
        }
        return $out;
    }

    /** Бронь, покрывающая указанный часовой слот, либо null. */
    private function bookingAt($bookings, string $time)
    {
        $slotStart = $this->toMin($time);
        return $bookings->first(function ($b) use ($slotStart) {
            $s = $this->toMin($b->start_time);
            $e = $this->toMin($b->end_time);
            if ($e <= $s) {
                $e += 1440;
            }
            return $slotStart >= $s && $slotStart < $e;
        });
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\CourtPriceRange;
use App\Services\CourtScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MobileCourtController extends Controller
{
    public function __construct(
        private CourtScheduleService $scheduleService
    ) {}

    /**
     * Список клубов с кортами
     * GET /api/mobile/courts/clubs?city=...&search=...
     */
    public function clubs(Request $request)
    {
        $query = Club::active()
            ->whereHas('courts', fn($q) => $q->where('is_active', true));

        // Фильтр по фиче courts
        $query->where(function ($q) {
            $q->whereNull('features')
              ->orWhereJsonContains('features->courts', true)
              ->orWhereRaw("json_extract(features, '$.courts') IS NULL");
        });

        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $clubs = $query->withCount(['courts' => fn($q) => $q->where('is_active', true)])
            ->get();

        // Получаем минимальные цены для всех клубов
        $clubIds = $clubs->pluck('id');
        $minPrices = CourtPriceRange::whereHas('court', function ($q) use ($clubIds) {
                $q->whereIn('club_id', $clubIds)->where('is_active', true);
            })
            ->selectRaw('courts.club_id, MIN(court_price_ranges.price) as min_price')
            ->join('courts', 'courts.id', '=', 'court_price_ranges.court_id')
            ->groupBy('courts.club_id')
            ->pluck('min_price', 'club_id');

        $result = $clubs->map(fn($club) => [
            'id' => $club->id,
            'name' => $club->name,
            'address' => $club->address,
            'city' => $club->city,
            'logo' => $club->logo,
            'courts_count' => $club->courts_count,
            'min_price' => isset($minPrices[$club->id]) ? (float) $minPrices[$club->id] : null,
        ]);

        // Уникальные города из найденных клубов
        $cities = $clubs->pluck('city')->filter()->unique()->sort()->values();

        return response()->json([
            'success' => true,
            'clubs' => $result,
            'cities' => $cities,
        ]);
    }

    /**
     * Расписание кортов клуба на дату
     * GET /api/mobile/courts/clubs/{club}/schedule?date=2026-04-01
     */
    public function schedule(Request $request, Club $club)
    {
        $date = $request->input('date', now()->format('Y-m-d'));

        $courts = $club->courts()->where('is_active', true)
            ->with('priceRanges')
            ->orderBy('sort_order')
            ->get();

        $courtsData = $courts->map(function (Court $court) use ($date) {
            $schedule = $this->scheduleService->buildSchedule($court, $date);

            $slots = [];
            foreach ($schedule as $time => $slot) {
                $item = ['time' => $time, 'status' => $slot['status']];
                if ($slot['status'] === 'free') {
                    $item['price'] = $slot['price'];
                }
                $slots[] = $item;
            }

            return [
                'id' => $court->id,
                'name' => $court->name,
                'slot_duration' => $court->slot_duration,
                'slots' => $slots,
            ];
        });

        // Тренеры клуба
        $clubCoaches = $club->clubCoaches()
            ->with(['user', 'schedules', 'overrides', 'blocks', 'rates'])
            ->get();

        // Собираем все уникальные времена слотов
        $allTimes = [];
        foreach ($courtsData as $c) {
            foreach ($c['slots'] as $s) {
                $allTimes[$s['time']] = true;
            }
        }
        $allTimes = array_keys($allTimes);
        sort($allTimes);

        $coachesData = $clubCoaches->map(function ($coach) use ($date, $allTimes) {
            // Ставки: {"hours": rate_per_hour}
            $rates = $coach->rates->pluck('rate', 'hours')
                ->map(fn($v) => (float) $v)
                ->toArray();

            // Доступность по каждому слоту
            $availability = [];
            foreach ($allTimes as $time) {
                $endTime = Carbon::parse($time)->addHour()->format('H:i');
                $availability[$time] = $coach->isFreeAt($date, $time, $endTime);
            }

            return [
                'id' => $coach->user_id,
                'name' => $coach->user->full_name ?? $coach->user->name,
                'hourly_rate' => (float) $coach->hourly_rate,
                'rates' => (object) $rates,
                'availability' => (object) $availability,
            ];
        });

        return response()->json([
            'success' => true,
            'club' => [
                'id' => $club->id,
                'name' => $club->name,
                'address' => $club->address,
            ],
            'date' => $date,
            'courts' => $courtsData,
            'coaches' => $coachesData,
        ]);
    }

    /**
     * Бронирование корта
     * POST /api/mobile/courts/clubs/{club}/book
     */
    public function book(Request $request, Club $club)
    {
        $validated = $request->validate([
            'court_id' => 'required|integer',
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'slots' => 'required|integer|min:1|max:6',
            'client_name' => 'nullable|string|max:255',
            'client_phone' => 'nullable|string|max:50',
            'coach_id' => 'nullable|integer',
            'comment' => 'nullable|string|max:500',
        ]);

        // Проверяем что корт принадлежит клубу и активен
        $court = Court::where('id', $validated['court_id'])
            ->where('club_id', $club->id)
            ->where('is_active', true)
            ->with('priceRanges')
            ->first();

        if (!$court) {
            return response()->json([
                'success' => false,
                'message' => 'Корт не найден или неактивен',
            ], 422);
        }

        // Рассчитываем end_time
        $startTime = Carbon::parse($validated['start_time']);
        $endTime = $startTime->copy()->addMinutes($validated['slots'] * $court->slot_duration);
        $endTimeStr = $endTime->format('H:i');
        $startTimeStr = $startTime->format('H:i');

        // Проверяем доступность через CourtScheduleService
        if (!$this->scheduleService->canBook($court, $validated['date'], $startTimeStr, $endTimeStr)) {
            return response()->json([
                'success' => false,
                'message' => 'Выбранное время недоступно для бронирования',
            ], 422);
        }

        // Проверяем тренера, если указан
        $coachPrice = 0;
        $clubCoach = null;
        if (!empty($validated['coach_id'])) {
            $clubCoach = $club->clubCoaches()
                ->where('user_id', $validated['coach_id'])
                ->with('rates')
                ->first();

            if (!$clubCoach) {
                return response()->json([
                    'success' => false,
                    'message' => 'Тренер не найден в этом клубе',
                ], 422);
            }

            if (!$clubCoach->isFreeAt($validated['date'], $startTimeStr, $endTimeStr)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Тренер занят в выбранное время',
                ], 422);
            }

            $hours = $validated['slots'] * $court->slot_duration / 60;
            $coachPrice = $clubCoach->getRateForHours((int) $hours);
        }

        $user = $request->user();
        $courtPrice = $this->scheduleService->calculatePrice($court, $startTimeStr, $endTimeStr);

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => $validated['date'],
            'start_time' => $startTimeStr,
            'end_time' => $endTimeStr,
            'client_name' => $validated['client_name'] ?? $user->full_name ?? $user->name,
            'client_phone' => $validated['client_phone'] ?? $user->phone,
            'status' => 'confirmed',
            'booked_by' => $user->id,
            'price' => $courtPrice + $coachPrice,
            'is_paid' => false,
            'is_processed' => false,
            'comment' => $validated['comment'] ?? null,
            'coach_id' => $validated['coach_id'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'booking' => [
                'id' => $booking->id,
                'club' => $club->name,
                'court' => $court->name,
                'date' => Carbon::parse($validated['date'])->format('d.m.Y'),
                'start_time' => $startTimeStr,
                'end_time' => $endTimeStr,
                'court_price' => $courtPrice,
                'coach_price' => $coachPrice,
                'total_price' => $courtPrice + $coachPrice,
                'coach' => $clubCoach ? ($clubCoach->user->full_name ?? $clubCoach->user->name) : null,
                'status' => 'confirmed',
            ],
        ]);
    }

    /**
     * Мои бронирования
     * GET /api/mobile/courts/my-bookings
     */
    public function myBookings(Request $request)
    {
        $user = $request->user();

        $bookings = CourtBooking::where('booked_by', $user->id)
            ->with(['court.club', 'coach'])
            ->orderByDesc('date')
            ->orderByDesc('start_time')
            ->get();

        $today = now()->format('Y-m-d');

        $format = function (CourtBooking $b) use ($today) {
            $isFuture = $b->date->format('Y-m-d') >= $today;
            $canCancel = $isFuture && $b->status === 'confirmed';

            return [
                'id' => $b->id,
                'club' => $b->court->club->name ?? '',
                'court' => $b->court->name ?? '',
                'date' => $b->date->format('d.m.Y'),
                'start_time' => Carbon::parse($b->start_time)->format('H:i'),
                'end_time' => Carbon::parse($b->end_time)->format('H:i'),
                'price' => (float) $b->price,
                'coach' => $b->coach ? ($b->coach->full_name ?? $b->coach->name) : null,
                'coach_price' => null,
                'status' => $b->status,
                'is_processed' => (bool) $b->is_processed,
                'can_cancel' => $canCancel,
            ];
        };

        $upcoming = $bookings->filter(
            fn($b) => $b->date->format('Y-m-d') >= $today && $b->status === 'confirmed'
        )->values()->map($format);

        $past = $bookings->filter(
            fn($b) => $b->date->format('Y-m-d') < $today || $b->status === 'cancelled'
        )->values()->map($format);

        return response()->json([
            'success' => true,
            'upcoming' => $upcoming,
            'past' => $past,
        ]);
    }

    /**
     * Отмена бронирования
     * POST /api/mobile/courts/bookings/{booking}/cancel
     */
    public function cancel(Request $request, CourtBooking $booking)
    {
        if ($booking->booked_by !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Нет доступа к этому бронированию',
            ], 403);
        }

        if ($booking->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Бронирование уже отменено',
            ], 422);
        }

        $booking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Бронирование успешно отменено',
        ]);
    }

    /**
     * Загруженность клуба по дням недели
     */
    public function weekOccupancy(Request $request, Club $club)
    {
        $startDate = $request->get('start_date', now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'));
        $courts = $club->courts()->active()->get();

        if ($courts->isEmpty()) {
            return response()->json(['success' => true, 'occupancy' => []]);
        }

        $totalSlots = 0;
        foreach ($courts as $court) {
            $totalSlots += count($this->scheduleService->generateTimeSlots($court));
        }

        $occupancy = [];
        for ($i = 0; $i < 7; $i++) {
            $d = Carbon::parse($startDate)->addDays($i);
            $dayStr = $d->format('Y-m-d');

            if ($totalSlots > 0) {
                $occupiedSlots = 0;
                $bookings = CourtBooking::whereIn('court_id', $courts->pluck('id'))
                    ->whereDate('date', $dayStr)
                    ->where('status', 'confirmed')
                    ->get();
                foreach ($bookings as $b) {
                    $startMin = Carbon::parse($b->start_time)->hour * 60 + Carbon::parse($b->start_time)->minute;
                    $endMin = Carbon::parse($b->end_time)->hour * 60 + Carbon::parse($b->end_time)->minute;
                    if ($endMin <= $startMin) $endMin += 1440;
                    $court = $courts->firstWhere('id', $b->court_id);
                    $duration = $court ? $court->slot_duration : 60;
                    $occupiedSlots += ($endMin - $startMin) / $duration;
                }
                $pct = min(100, round($occupiedSlots / $totalSlots * 100));
            } else {
                $pct = 0;
            }

            $occupancy[$dayStr] = $pct;
        }

        return response()->json(['success' => true, 'occupancy' => $occupancy]);
    }
}

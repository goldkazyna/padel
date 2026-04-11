<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtPriceRange;
use App\Models\CourtBooking;
use App\Models\CourtBlock;
use App\Services\CourtScheduleService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CourtController extends Controller
{
    private CourtScheduleService $scheduleService;

    public function __construct(CourtScheduleService $scheduleService)
    {
        $this->scheduleService = $scheduleService;
    }

    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    // === Расписание (главный экран) ===

    public function schedule(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $courts = Court::where('club_id', $club->id)->active()->orderBy('sort_order')->orderBy('name')->get();
        if ($courts->isEmpty()) return redirect()->route('club.courts.index')->with('error', 'Нет активных кортов. Добавьте корт в настройках.');

        $date = $request->get('date', now()->format('Y-m-d'));

        $schedules = [];
        foreach ($courts as $court) {
            $schedules[$court->id] = $this->scheduleService->buildSchedule($court, $date);
        }

        $allTimes = collect();
        foreach ($courts as $court) {
            $slots = $this->scheduleService->generateTimeSlots($court);
            foreach ($slots as $slot) {
                $allTimes->push($slot['time']);
            }
        }
        $timeSlots = $allTimes->unique()->sort()->values();

        // Неделя: пн-вс для выбранной даты
        $selectedDate = Carbon::parse($date);
        $weekStart = $selectedDate->copy()->startOfWeek(Carbon::MONDAY);
        $prevWeek = $weekStart->copy()->subWeek()->format('Y-m-d');
        $nextWeek = $weekStart->copy()->addWeek()->format('Y-m-d');

        // Считаем слоты для каждого корта отдельно
        $courtSlotCounts = [];
        $totalSlots = 0;
        foreach ($courts as $court) {
            $count = count($this->scheduleService->generateTimeSlots($court));
            $courtSlotCounts[$court->id] = $count;
            $totalSlots += $count;
        }

        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $dayStr = $d->format('Y-m-d');

            $occupancy = 0;
            if ($totalSlots > 0) {
                $occupiedSlots = 0;
                $bookings = \App\Models\CourtBooking::whereIn('court_id', $courts->pluck('id'))
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
                $blocks = \App\Models\CourtBlock::whereIn('court_id', $courts->pluck('id'))
                    ->whereDate('date', $dayStr)
                    ->get();
                foreach ($blocks as $bl) {
                    $startMin = Carbon::parse($bl->start_time)->hour * 60 + Carbon::parse($bl->start_time)->minute;
                    $endMin = Carbon::parse($bl->end_time)->hour * 60 + Carbon::parse($bl->end_time)->minute;
                    if ($endMin <= $startMin) $endMin += 1440;
                    $court = $courts->firstWhere('id', $bl->court_id);
                    $duration = $court ? $court->slot_duration : 60;
                    $occupiedSlots += ($endMin - $startMin) / $duration;
                }
                $occupancy = min(100, round($occupiedSlots / $totalSlots * 100));
            }

            $weekDays[] = [
                'date' => $dayStr,
                'dayName' => $d->locale('ru')->isoFormat('dd'),
                'dayNum' => $d->format('d'),
                'month' => $d->locale('ru')->isoFormat('MMM'),
                'isSelected' => $dayStr === $date,
                'isToday' => $dayStr === now()->format('Y-m-d'),
                'occupancy' => $occupancy,
            ];
        }

        $clubCoaches = $club->clubCoaches()->with(['user', 'schedules', 'overrides', 'blocks', 'rates'])->get();

        // Подготовить доступность тренеров по слотам для JS
        $coachAvailability = [];
        foreach ($clubCoaches as $cc) {
            foreach ($timeSlots as $time) {
                $endTime = Carbon::parse($time)->addHour()->format('H:i');
                $coachAvailability[$cc->user_id][$time] = $cc->isFreeAt($date, $time, $endTime);
            }
        }

        // Необработанные заявки
        $unprocessedBookings = CourtBooking::whereIn('court_id', $courts->pluck('id'))
            ->where('status', 'confirmed')
            ->where('is_processed', false)
            ->with('court')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        return view('club.courts.schedule', compact(
            'club', 'courts', 'schedules', 'timeSlots', 'date',
            'weekDays', 'prevWeek', 'nextWeek', 'clubCoaches', 'coachAvailability',
            'unprocessedBookings'
        ));
    }

    // === CRUD кортов ===

    public function index()
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $courts = Court::where('club_id', $club->id)
            ->with('priceRanges')
            ->orderBy('sort_order')->orderBy('name')->get();

        return view('club.courts.index', compact('courts'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'open_time' => 'required|date_format:H:i',
            'close_time' => 'required|date_format:H:i',
            'price_ranges' => 'required|array|min:1',
            'price_ranges.*.time_from' => 'required|date_format:H:i',
            'price_ranges.*.time_to' => 'required|date_format:H:i',
            'price_ranges.*.price' => 'required|numeric|min:0',
        ]);

        $errors = $this->scheduleService->validatePriceRanges(
            $validated['price_ranges'], $validated['open_time'], $validated['close_time']
        );
        if (!empty($errors)) {
            return back()->with('error', implode('. ', $errors))->withInput();
        }

        $maxSort = Court::where('club_id', $club->id)->max('sort_order') ?? 0;

        $court = Court::create([
            'club_id' => $club->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'open_time' => $validated['open_time'],
            'close_time' => $validated['close_time'],
            'sort_order' => $maxSort + 1,
        ]);

        foreach ($validated['price_ranges'] as $range) {
            CourtPriceRange::create([
                'court_id' => $court->id,
                'time_from' => $range['time_from'],
                'time_to' => $range['time_to'],
                'price' => $range['price'],
            ]);
        }

        return back()->with('success', 'Корт добавлен!');
    }

    public function update(Request $request, Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'open_time' => 'required|date_format:H:i',
            'close_time' => 'required|date_format:H:i',
            'price_ranges' => 'required|array|min:1',
            'price_ranges.*.time_from' => 'required|date_format:H:i',
            'price_ranges.*.time_to' => 'required|date_format:H:i',
            'price_ranges.*.price' => 'required|numeric|min:0',
        ]);

        $errors = $this->scheduleService->validatePriceRanges(
            $validated['price_ranges'], $validated['open_time'], $validated['close_time']
        );
        if (!empty($errors)) {
            return back()->with('error', implode('. ', $errors))->withInput();
        }

        $court->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'open_time' => $validated['open_time'],
            'close_time' => $validated['close_time'],
        ]);

        $court->priceRanges()->delete();
        foreach ($validated['price_ranges'] as $range) {
            CourtPriceRange::create([
                'court_id' => $court->id,
                'time_from' => $range['time_from'],
                'time_to' => $range['time_to'],
                'price' => $range['price'],
            ]);
        }

        return back()->with('success', 'Корт обновлён!');
    }

    public function destroy(Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $hasBookings = CourtBooking::where('court_id', $court->id)
            ->where('status', 'confirmed')
            ->whereDate('date', '>=', now()->format('Y-m-d'))
            ->exists();

        if ($hasBookings) {
            return back()->with('error', 'Нельзя удалить корт с будущими бронированиями');
        }

        $court->priceRanges()->delete();
        $court->blocks()->delete();
        $court->bookings()->delete();
        $court->delete();

        return back()->with('success', 'Корт удалён!');
    }

    public function toggleActive(Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $court->update(['is_active' => !$court->is_active]);

        return back()->with('success', $court->is_active ? 'Корт активирован!' : 'Корт деактивирован!');
    }

    // === Бронирование ===

    public function book(Request $request, Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'slots' => 'required|integer|min:1|max:8',
            'client_name' => 'required|string|max:255',
            'client_phone' => 'required|string|max:50',
            'payment_method' => 'nullable|string|in:cash,card,kaspi,certificate,club_card,deposit,cashback',
            'is_paid' => 'nullable|boolean',
            'comment' => 'nullable|string|max:500',
            'coach_id' => 'nullable|exists:users,id',
            'custom_price' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
        ]);

        $validated['client_phone'] = preg_replace('/\D/', '', $validated['client_phone']);

        $startTime = $validated['start_time'];
        $totalMinutes = $validated['slots'] * $court->slot_duration;
        $endTime = Carbon::parse($startTime)->addMinutes($totalMinutes)->format('H:i');

        if (!$this->scheduleService->canBook($court, $validated['date'], $startTime, $endTime)) {
            return back()->with('error', 'Выбранное время недоступно');
        }

        if (!empty($validated['coach_id'])) {
            $clubCoach = \App\Models\ClubCoach::where('club_id', $club->id)
                ->where('user_id', $validated['coach_id'])
                ->first();
            if (!$clubCoach || !$clubCoach->isFreeAt($validated['date'], $startTime, $endTime)) {
                return back()->with('error', 'Тренер недоступен в это время')->withInput();
            }
        }

        $autoPrice = $this->scheduleService->calculatePrice($court, $startTime, $endTime);
        $customPrice = $validated['custom_price'] ?? $autoPrice;
        $discount = $validated['discount'] ?? 0;
        $price = max(0, $customPrice - $discount);

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => $validated['date'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'client_name' => $validated['client_name'],
            'client_phone' => $validated['client_phone'],
            'booked_by' => auth()->id(),
            'price' => $price,
            'discount' => $discount,
            'payment_method' => $validated['payment_method'] ?? null,
            'is_paid' => $validated['is_paid'] ?? false,
            'comment' => $validated['comment'] ?? null,
            'coach_id' => $validated['coach_id'] ?? null,
        ]);

        // Автоматически добавить клиента в справочник если его нет
        if ($validated['client_phone']) {
            \App\Models\ClubClient::firstOrCreate(
                ['club_id' => $club->id, 'phone' => $validated['client_phone']],
                ['name' => $validated['client_name']]
            );
        }

        \App\Models\ActivityLog::log('created', 'CourtBooking', $booking->id, "Бронирование: {$validated['client_name']}, {$court->name}, {$validated['date']} {$startTime}–{$endTime}");

        return back()->with('success', "Забронировано: {$validated['client_name']}, {$startTime}–{$endTime}, " . number_format($price, 0, '', ' ') . " ₸");
    }

    public function updateBooking(Request $request, CourtBooking $booking)
    {
        $club = $this->getClub();
        $court = $booking->court;
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $wasUnprocessed = !$booking->is_processed;

        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'client_phone' => 'required|string|max:50',
            'payment_method' => 'nullable|string|in:cash,card,kaspi,certificate,club_card,deposit,cashback',
            'is_paid' => 'nullable|boolean',
            'is_processed' => 'nullable|boolean',
            'comment' => 'nullable|string|max:500',
            'coach_id' => 'nullable',
            'custom_price' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
        ]);

        $validated['client_phone'] = preg_replace('/\D/', '', $validated['client_phone']);

        $updateData = [
            'client_name' => $validated['client_name'],
            'client_phone' => $validated['client_phone'],
            'payment_method' => $validated['payment_method'] ?? null,
            'is_paid' => $validated['is_paid'] ?? false,
            'is_processed' => $validated['is_processed'] ?? $booking->is_processed,
            'comment' => $validated['comment'] ?? null,
            'coach_id' => $validated['coach_id'] ?: null,
        ];

        if (isset($validated['custom_price'])) {
            $discount = $validated['discount'] ?? 0;
            $updateData['price'] = max(0, $validated['custom_price'] - $discount);
            $updateData['discount'] = $discount;
        }

        $booking->update($updateData);

        // Push + уведомление при обработке (was unprocessed → now processed)
        if ($wasUnprocessed && $booking->is_processed && $booking->booked_by) {
            try {
                $bookedUser = \App\Models\User::find($booking->booked_by);
                if ($bookedUser) {
                    $date = $booking->date->format('d.m.Y');
                    $time = Carbon::parse($booking->start_time)->format('H:i');
                    $title = 'Бронирование подтверждено ✅';
                    $body = "{$court->name}, {$date} в {$time}";

                    \App\Models\Notification::create([
                        'user_id' => $bookedUser->id,
                        'title' => $title,
                        'body' => $body,
                        'type' => 'booking_confirmed',
                        'category' => 'booking',
                        'data' => ['booking_id' => $booking->id],
                    ]);

                    $fcm = app(\App\Services\FCMNotificationService::class);
                    $fcm->sendToUser($bookedUser, $title, $body, [
                        'type' => 'booking_confirmed',
                        'booking_id' => (string) $booking->id,
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Push booking confirmed error: ' . $e->getMessage());
            }
        }

        \App\Models\ActivityLog::log('updated', 'CourtBooking', $booking->id, "Редактирование брони: {$booking->client_name}, {$booking->court->name}", $booking->getChanges());

        return back()->with('success', 'Бронирование обновлено');
    }

    public function cancelBooking(CourtBooking $booking)
    {
        $club = $this->getClub();
        $court = $booking->court;
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $booking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        // Push + уведомление об отмене
        if ($booking->booked_by) {
            try {
                $bookedUser = \App\Models\User::find($booking->booked_by);
                if ($bookedUser) {
                    $date = $booking->date->format('d.m.Y');
                    $time = Carbon::parse($booking->start_time)->format('H:i');
                    $title = 'Бронирование отменено ❌';
                    $body = "{$court->name}, {$date} в {$time}";

                    \App\Models\Notification::create([
                        'user_id' => $bookedUser->id,
                        'title' => $title,
                        'body' => $body,
                        'type' => 'booking_cancelled',
                        'category' => 'booking',
                        'data' => ['booking_id' => $booking->id],
                    ]);

                    $fcm = app(\App\Services\FCMNotificationService::class);
                    $fcm->sendToUser($bookedUser, $title, $body, [
                        'type' => 'booking_cancelled',
                        'booking_id' => (string) $booking->id,
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Push booking cancelled error: ' . $e->getMessage());
            }
        }

        \App\Models\ActivityLog::log('cancelled', 'CourtBooking', $booking->id, "Отмена брони: {$booking->client_name}, {$court->name}");

        return back()->with('success', 'Бронирование отменено');
    }

    // === Блокировка ===

    public function blockSlot(Request $request, Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'comment' => 'nullable|string|max:500',
        ]);

        if (!$this->scheduleService->canBook($court, $validated['date'], $validated['start_time'], $validated['end_time'])) {
            return back()->with('error', 'Нельзя заблокировать — есть бронирование на это время');
        }

        CourtBlock::create([
            'court_id' => $court->id,
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'comment' => $validated['comment'] ?? null,
        ]);

        \App\Models\ActivityLog::log('blocked', 'CourtBlock', null, "Блокировка: {$court->name}, {$validated['date']} {$validated['start_time']}–{$validated['end_time']}");

        return back()->with('success', 'Слот заблокирован');
    }

    public function unblock(CourtBlock $block)
    {
        $club = $this->getClub();
        $court = $block->court;
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $block->delete();

        \App\Models\ActivityLog::log('unblocked', 'CourtBlock', $block->id, "Разблокировка: {$court->name}");

        return back()->with('success', 'Слот разблокирован');
    }

    public function updateBlock(Request $request, CourtBlock $block)
    {
        $club = $this->getClub();
        $court = $block->court;
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $validated = $request->validate([
            'comment' => 'nullable|string|max:500',
        ]);

        $block->update([
            'comment' => $validated['comment'] ?? null,
        ]);

        return back()->with('success', 'Блокировка обновлена');
    }
}

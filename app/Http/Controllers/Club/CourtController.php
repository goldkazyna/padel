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

        return view('club.courts.schedule', compact('courts', 'schedules', 'timeSlots', 'date'));
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
            'client_phone' => 'nullable|string|max:50',
            'payment_method' => 'nullable|string|in:cash,card,kaspi,certificate,club_card,deposit,cashback',
            'is_paid' => 'nullable|boolean',
        ]);

        $startTime = $validated['start_time'];
        $totalMinutes = $validated['slots'] * $court->slot_duration;
        $endTime = Carbon::parse($startTime)->addMinutes($totalMinutes)->format('H:i');

        if (!$this->scheduleService->canBook($court, $validated['date'], $startTime, $endTime)) {
            return back()->with('error', 'Выбранное время недоступно');
        }

        $price = $this->scheduleService->calculatePrice($court, $startTime, $endTime);

        CourtBooking::create([
            'court_id' => $court->id,
            'date' => $validated['date'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'client_name' => $validated['client_name'],
            'client_phone' => $validated['client_phone'] ?? null,
            'booked_by' => auth()->id(),
            'price' => $price,
            'payment_method' => $validated['payment_method'] ?? null,
            'is_paid' => $validated['is_paid'] ?? false,
        ]);

        return back()->with('success', "Забронировано: {$validated['client_name']}, {$startTime}–{$endTime}, " . number_format($price, 0, '', ' ') . " ₸");
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
        ]);

        if (!$this->scheduleService->canBook($court, $validated['date'], $validated['start_time'], $validated['end_time'])) {
            return back()->with('error', 'Нельзя заблокировать — есть бронирование на это время');
        }

        CourtBlock::create([
            'court_id' => $court->id,
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
        ]);

        return back()->with('success', 'Слот заблокирован');
    }

    public function unblock(CourtBlock $block)
    {
        $club = $this->getClub();
        $court = $block->court;
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $block->delete();

        return back()->with('success', 'Слот разблокирован');
    }
}

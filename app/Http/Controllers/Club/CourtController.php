<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtSlot;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CourtController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return \App\Models\Club::first();
        }

        if ($user->isClubModerator()) {
            return $user->moderatorClubs()->first();
        }

        return $user->adminClubs()->first();
    }

    public function index()
    {
        $club = $this->getClub();

        if (!$club) {
            return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');
        }

        $courts = Court::where('club_id', $club->id)->orderBy('sort_order')->orderBy('name')->get();

        $stats = [
            'total' => $courts->count(),
            'active' => $courts->where('is_active', true)->count(),
            'inactive' => $courts->where('is_active', false)->count(),
        ];

        return view('club.courts.index', compact('courts', 'stats'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();

        if (!$club) {
            return back()->with('error', 'Клуб не найден');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $maxSort = Court::where('club_id', $club->id)->max('sort_order') ?? 0;

        Court::create([
            'club_id' => $club->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => $maxSort + 1,
        ]);

        return back()->with('success', 'Корт добавлен!');
    }

    public function update(Request $request, Court $court)
    {
        $club = $this->getClub();

        if (!$club || $court->club_id !== $club->id) {
            return back()->with('error', 'Нет доступа');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $court->update($validated);

        return back()->with('success', 'Корт обновлён!');
    }

    public function destroy(Court $court)
    {
        $club = $this->getClub();

        if (!$club || $court->club_id !== $club->id) {
            return back()->with('error', 'Нет доступа');
        }

        // Check for confirmed bookings
        $hasBookings = $court->slots()
            ->whereHas('booking', fn($q) => $q->where('status', 'confirmed'))
            ->exists();

        if ($hasBookings) {
            return back()->with('error', 'Нельзя удалить корт с подтверждёнными бронированиями');
        }

        $court->slots()->delete();
        $court->delete();

        return back()->with('success', 'Корт удалён!');
    }

    public function toggleActive(Court $court)
    {
        $club = $this->getClub();

        if (!$club || $court->club_id !== $club->id) {
            return back()->with('error', 'Нет доступа');
        }

        $court->update(['is_active' => !$court->is_active]);

        return back()->with('success', $court->is_active ? 'Корт активирован!' : 'Корт деактивирован!');
    }

    public function slots(Request $request, Court $court)
    {
        $club = $this->getClub();

        if (!$club || $court->club_id !== $club->id) {
            return back()->with('error', 'Нет доступа');
        }

        $date = $request->get('date', now()->format('Y-m-d'));

        $slots = CourtSlot::where('court_id', $court->id)
            ->where('date', $date)
            ->orderBy('start_time')
            ->get();

        return view('club.courts.slots', compact('court', 'slots', 'date'));
    }

    public function generateSlots(Request $request, Court $court)
    {
        $club = $this->getClub();

        if (!$club || $court->club_id !== $club->id) {
            return back()->with('error', 'Нет доступа');
        }

        $validated = $request->validate([
            'date_from' => 'required|date|after_or_equal:today',
            'date_to' => 'required|date|after_or_equal:date_from',
            'time_from' => 'required|date_format:H:i',
            'time_to' => 'required|date_format:H:i|after:time_from',
            'duration_minutes' => 'required|integer|in:30,60,90',
            'price' => 'required|numeric|min:0',
            'weekend_price' => 'nullable|numeric|min:0',
            'weekdays' => 'nullable|array',
            'weekdays.*' => 'integer|between:0,6',
        ]);

        $dateFrom = Carbon::parse($validated['date_from']);
        $dateTo = Carbon::parse($validated['date_to']);
        $duration = (int) $validated['duration_minutes'];
        $weekdays = $validated['weekdays'] ?? [0, 1, 2, 3, 4, 5, 6];
        $weekendPrice = $validated['weekend_price'] ?? null;
        $created = 0;
        $skipped = 0;

        for ($date = $dateFrom->copy(); $date->lte($dateTo); $date->addDay()) {
            // Filter by weekday (0=Sunday, 6=Saturday in Carbon dayOfWeek)
            if (!in_array($date->dayOfWeek, $weekdays)) {
                continue;
            }

            $startTime = Carbon::parse($date->format('Y-m-d') . ' ' . $validated['time_from']);
            $endLimit = Carbon::parse($date->format('Y-m-d') . ' ' . $validated['time_to']);

            // Determine price: weekend (Sat=6, Sun=0) or regular
            $isWeekend = in_array($date->dayOfWeek, [0, 6]);
            $slotPrice = ($isWeekend && $weekendPrice !== null) ? $weekendPrice : $validated['price'];

            while ($startTime->copy()->addMinutes($duration)->lte($endLimit)) {
                $endTime = $startTime->copy()->addMinutes($duration);

                // Check for any overlapping slot (not just exact match)
                $overlaps = CourtSlot::where('court_id', $court->id)
                    ->where('date', $date->format('Y-m-d'))
                    ->where('start_time', '<', $endTime->format('H:i:s'))
                    ->where('end_time', '>', $startTime->format('H:i:s'))
                    ->exists();

                if (!$overlaps) {
                    CourtSlot::create([
                        'court_id' => $court->id,
                        'date' => $date->format('Y-m-d'),
                        'start_time' => $startTime->format('H:i:s'),
                        'end_time' => $endTime->format('H:i:s'),
                        'price' => $slotPrice,
                        'status' => 'available',
                    ]);
                    $created++;
                } else {
                    $skipped++;
                }

                $startTime->addMinutes($duration);
            }
        }

        $message = "Создано слотов: {$created}";
        if ($skipped > 0) {
            $message .= " (пропущено из-за пересечений: {$skipped})";
        }

        return redirect()->route('club.courts.slots', ['court' => $court->id, 'date' => $validated['date_from']])
            ->with('success', $message);
    }

    public function deleteSlot(CourtSlot $slot)
    {
        $club = $this->getClub();
        $court = $slot->court;

        if (!$club || $court->club_id !== $club->id) {
            return back()->with('error', 'Нет доступа');
        }

        if ($slot->isBooked()) {
            return back()->with('error', 'Нельзя удалить забронированный слот');
        }

        $slot->delete();

        return back()->with('success', 'Слот удалён!');
    }

    public function toggleSlotBlock(CourtSlot $slot)
    {
        $club = $this->getClub();
        $court = $slot->court;

        if (!$club || $court->club_id !== $club->id) {
            return back()->with('error', 'Нет доступа');
        }

        if ($slot->isBooked()) {
            return back()->with('error', 'Нельзя изменить забронированный слот');
        }

        $slot->update([
            'status' => $slot->status === 'blocked' ? 'available' : 'blocked',
        ]);

        return back()->with('success', $slot->status === 'available' ? 'Слот разблокирован!' : 'Слот заблокирован!');
    }

    public function storeSlot(Request $request, Court $court)
    {
        $club = $this->getClub();

        if (!$club || $court->club_id !== $club->id) {
            return back()->with('error', 'Нет доступа');
        }

        $validated = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'price' => 'required|numeric|min:0',
        ]);

        // Check for overlapping slots
        $overlaps = CourtSlot::where('court_id', $court->id)
            ->where('date', $validated['date'])
            ->where('start_time', '<', $validated['end_time'] . ':00')
            ->where('end_time', '>', $validated['start_time'] . ':00')
            ->exists();

        if ($overlaps) {
            return back()->with('error', 'Слот пересекается с существующим')->withInput();
        }

        CourtSlot::create([
            'court_id' => $court->id,
            'date' => $validated['date'],
            'start_time' => $validated['start_time'] . ':00',
            'end_time' => $validated['end_time'] . ':00',
            'price' => $validated['price'],
            'status' => 'available',
        ]);

        return redirect()->route('club.courts.slots', ['court' => $court->id, 'date' => $validated['date']])
            ->with('success', 'Слот создан!');
    }

    public function bulkAction(Request $request, Court $court)
    {
        $club = $this->getClub();

        if (!$club || $court->club_id !== $club->id) {
            return back()->with('error', 'Нет доступа');
        }

        $validated = $request->validate([
            'slot_ids' => 'required|array|min:1',
            'slot_ids.*' => 'integer|exists:court_slots,id',
            'action' => 'required|in:delete,block,unblock',
        ]);

        $slots = CourtSlot::where('court_id', $court->id)
            ->whereIn('id', $validated['slot_ids'])
            ->get();

        $processed = 0;
        $skippedBooked = 0;

        foreach ($slots as $slot) {
            if ($slot->isBooked()) {
                $skippedBooked++;
                continue;
            }

            switch ($validated['action']) {
                case 'delete':
                    $slot->delete();
                    $processed++;
                    break;
                case 'block':
                    if ($slot->status !== 'blocked') {
                        $slot->update(['status' => 'blocked']);
                        $processed++;
                    }
                    break;
                case 'unblock':
                    if ($slot->status === 'blocked') {
                        $slot->update(['status' => 'available']);
                        $processed++;
                    }
                    break;
            }
        }

        $actionLabels = ['delete' => 'Удалено', 'block' => 'Заблокировано', 'unblock' => 'Разблокировано'];
        $message = "{$actionLabels[$validated['action']]}: {$processed}";
        if ($skippedBooked > 0) {
            $message .= " (пропущено забронированных: {$skippedBooked})";
        }

        return back()->with('success', $message);
    }
}

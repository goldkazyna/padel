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
        ]);

        $dateFrom = Carbon::parse($validated['date_from']);
        $dateTo = Carbon::parse($validated['date_to']);
        $duration = (int) $validated['duration_minutes'];
        $created = 0;

        for ($date = $dateFrom->copy(); $date->lte($dateTo); $date->addDay()) {
            $startTime = Carbon::parse($date->format('Y-m-d') . ' ' . $validated['time_from']);
            $endLimit = Carbon::parse($date->format('Y-m-d') . ' ' . $validated['time_to']);

            while ($startTime->copy()->addMinutes($duration)->lte($endLimit)) {
                $endTime = $startTime->copy()->addMinutes($duration);

                // Skip if slot already exists
                $exists = CourtSlot::where('court_id', $court->id)
                    ->where('date', $date->format('Y-m-d'))
                    ->where('start_time', $startTime->format('H:i:s'))
                    ->where('end_time', $endTime->format('H:i:s'))
                    ->exists();

                if (!$exists) {
                    CourtSlot::create([
                        'court_id' => $court->id,
                        'date' => $date->format('Y-m-d'),
                        'start_time' => $startTime->format('H:i:s'),
                        'end_time' => $endTime->format('H:i:s'),
                        'price' => $validated['price'],
                        'status' => 'available',
                    ]);
                    $created++;
                }

                $startTime->addMinutes($duration);
            }
        }

        return redirect()->route('club.courts.slots', ['court' => $court->id, 'date' => $validated['date_from']])
            ->with('success', "Создано слотов: {$created}");
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
}

<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftChecklistResult;
use Illuminate\Http\Request;

/**
 * Журнал смен: кто когда работал и какие замечания оставил.
 */
class ShiftJournalController extends Controller
{
    public function index(Request $request)
    {
        $club = $this->club($request);

        $from = $request->filled('from')
            ? \Carbon\Carbon::parse($request->input('from'))->startOfDay()
            : now()->subDays(14)->startOfDay();
        $to = $request->filled('to')
            ? \Carbon\Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        $shifts = Shift::where('club_id', $club->id)
            ->whereBetween('opened_at', [$from, $to])
            ->with(['user', 'results'])
            ->orderByDesc('opened_at')
            ->get();

        // Замечания отдельным блоком: ради них журнал и открывают, иначе
        // пришлось бы разворачивать каждую смену.
        $comments = ShiftChecklistResult::withComment()
            ->whereIn('shift_id', $shifts->pluck('id'))
            ->with('shift.user')
            ->orderByDesc('created_at')
            ->get();

        return view('club.shifts.journal', [
            'club' => $club,
            'shifts' => $shifts,
            'comments' => $comments,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
    }

    public function show(Request $request, Shift $shift)
    {
        $club = $this->club($request);
        abort_unless((int) $shift->club_id === (int) $club->id, 403, 'Смена другого клуба');

        $shift->load(['user', 'results']);

        return view('club.shifts.show', [
            'club' => $club,
            'shift' => $shift,
            'opening' => $shift->results->where('type', 'opening'),
            'closing' => $shift->results->where('type', 'closing'),
        ]);
    }

    private function club(Request $request)
    {
        $user = $request->user();
        $club = $user->isSuperAdmin()
            ? \App\Models\Club::query()->first()
            : $user->adminClubs()->first();

        abort_unless($club, 403, 'Вы не привязаны к клубу');

        return $club;
    }
}

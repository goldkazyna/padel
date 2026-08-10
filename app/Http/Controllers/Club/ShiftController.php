<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Shift;
use App\Models\ShiftChecklistItem;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Смена менеджера: чек-листы открытия и закрытия.
 */
class ShiftController extends Controller
{
    public function __construct(private ShiftService $shifts)
    {
    }

    /** Чек-лист открытия. */
    public function opening(Request $request)
    {
        [$club, $user] = $this->context($request);

        // Смена уже идёт — проходить открытие второй раз незачем.
        if ($this->shifts->currentShift($club, $user)) {
            return redirect()->route('club.dashboard');
        }

        $items = ShiftChecklistItem::forChecklist($club->id, 'opening')->get();

        return view('club.shifts.checklist', [
            'club' => $club,
            'items' => $items,
            'type' => 'opening',
        ]);
    }

    public function open(Request $request)
    {
        [$club, $user] = $this->context($request);

        try {
            $this->shifts->open($club, $user, $this->marks($request));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('club.dashboard')->with('success', 'Смена открыта');
    }

    /** Чек-лист закрытия. */
    public function closing(Request $request)
    {
        [$club, $user] = $this->context($request);

        $shift = $this->shifts->currentShift($club, $user);
        if (!$shift) {
            return redirect()->route('club.shift.opening');
        }

        $items = ShiftChecklistItem::forChecklist($club->id, 'closing')->get();

        return view('club.shifts.checklist', [
            'club' => $club,
            'items' => $items,
            'type' => 'closing',
            'shift' => $shift,
        ]);
    }

    public function close(Request $request)
    {
        [$club, $user] = $this->context($request);

        $shift = $this->shifts->currentShift($club, $user);
        if (!$shift) {
            return redirect()->route('club.shift.opening');
        }

        try {
            $this->shifts->close($shift, $this->marks($request));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        // После закрытия смены менеджеру в системе делать нечего.
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Смена закрыта. Хорошего отдыха!');
    }

    /**
     * Отметки из формы: id пункта → отметка и комментарий.
     *
     * @return array<int, array{done: bool, comment: string}>
     */
    private function marks(Request $request): array
    {
        $out = [];
        foreach ((array) $request->input('items', []) as $itemId => $row) {
            $out[(int) $itemId] = [
                'done' => !empty($row['done']) && $row['done'] !== '0',
                'comment' => (string) ($row['comment'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return array{0: Club, 1: User} */
    private function context(Request $request): array
    {
        $user = $request->user();
        $club = $user->isClubModerator()
            ? $user->moderatorClubs()->first()
            : $user->adminClubs()->first();

        abort_unless($club, 403, 'Вы не привязаны к клубу');

        return [$club, $user];
    }
}

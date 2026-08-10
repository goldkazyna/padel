<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ShiftChecklistItem;
use Illuminate\Http\Request;

/**
 * Управление пунктами чек-листа: что менеджер проверяет при открытии
 * и закрытии смены. Доступно админу клуба.
 */
class ShiftChecklistController extends Controller
{
    public function index(Request $request)
    {
        $club = $this->club($request);

        return view('club.shifts.checklists', [
            'club' => $club,
            'opening' => ShiftChecklistItem::where('club_id', $club->id)
                ->where('type', 'opening')->orderBy('sort_order')->orderBy('id')->get(),
            'closing' => ShiftChecklistItem::where('club_id', $club->id)
                ->where('type', 'closing')->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $club = $this->club($request);

        $data = $request->validate([
            'type' => 'required|in:opening,closing',
            'title' => 'required|string|max:500',
        ]);

        // Новый пункт встаёт в конец своего списка.
        $last = ShiftChecklistItem::where('club_id', $club->id)
            ->where('type', $data['type'])
            ->max('sort_order');

        ShiftChecklistItem::create([
            'club_id' => $club->id,
            'type' => $data['type'],
            'title' => $data['title'],
            'sort_order' => (int) $last + 1,
        ]);

        return back()->with('success', 'Пункт добавлен');
    }

    public function update(Request $request, ShiftChecklistItem $item)
    {
        $this->authorizeItem($request, $item);

        $data = $request->validate([
            'title' => 'sometimes|string|max:500',
            'sort_order' => 'sometimes|integer|min:0|max:999',
            'is_active' => 'sometimes|boolean',
        ]);

        $item->update($data);

        return back()->with('success', 'Пункт обновлён');
    }

    /**
     * «Удаление» отключает пункт: на него ссылаются прошлые смены, и
     * стирание сломало бы журнал.
     */
    public function destroy(Request $request, ShiftChecklistItem $item)
    {
        $this->authorizeItem($request, $item);

        $item->update(['is_active' => false]);

        return back()->with('success', 'Пункт убран из чек-листа');
    }

    /** Вернуть отключённый пункт обратно в чек-лист. */
    public function restore(Request $request, ShiftChecklistItem $item)
    {
        $this->authorizeItem($request, $item);

        $item->update(['is_active' => true]);

        return back()->with('success', 'Пункт возвращён');
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

    private function authorizeItem(Request $request, ShiftChecklistItem $item): void
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            return;
        }

        abort_unless(
            $user->adminClubs()->where('clubs.id', $item->club_id)->exists(),
            403,
            'Этот пункт принадлежит другому клубу'
        );
    }
}

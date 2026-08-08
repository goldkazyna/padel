<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ClubInventoryItem;
use Illuminate\Http\Request;

/**
 * Справочник инвентаря клуба: аренда ракеток, мячи и прочее платное,
 * не связанное с кортами. Пока только справочник — без остатков и продаж.
 */
class InventoryController extends Controller
{
    /** Клуб текущего пользователя — как в остальных разделах клуба. */
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    /** Клуб с включённым модулем, иначе 403. */
    private function requireClub()
    {
        $club = $this->getClub();
        if (!$club || !$club->hasFeature('inventory')) abort(403);

        return $club;
    }

    public function index()
    {
        $club = $this->requireClub();

        $items = ClubInventoryItem::where('club_id', $club->id)
            ->orderBy('name')
            ->get();

        return view('club.inventory.index', compact('club', 'items'));
    }

    public function store(Request $request)
    {
        $club = $this->requireClub();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Укажите название позиции',
            'price.required' => 'Укажите цену',
            'price.numeric' => 'Цена должна быть числом',
            'price.min' => 'Цена не может быть отрицательной',
        ]);

        $data['club_id'] = $club->id;
        $data['is_active'] = $request->boolean('is_active', true);

        $item = ClubInventoryItem::create($data);

        ActivityLog::log('created', 'ClubInventoryItem', $item->id,
            "Инвентарь: добавлена позиция «{$item->name}» — {$item->price} ₸", clubId: $club->id);

        return back()->with('success', 'Позиция добавлена');
    }

    public function update(Request $request, ClubInventoryItem $item)
    {
        $club = $this->requireClub();
        if ($item->club_id !== $club->id) abort(403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Укажите название позиции',
            'price.required' => 'Укажите цену',
            'price.numeric' => 'Цена должна быть числом',
            'price.min' => 'Цена не может быть отрицательной',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        $item->update($data);

        ActivityLog::log('updated', 'ClubInventoryItem', $item->id,
            "Инвентарь: изменена позиция «{$item->name}»", clubId: $club->id);

        return back()->with('success', 'Позиция обновлена');
    }

    public function destroy(ClubInventoryItem $item)
    {
        $club = $this->requireClub();
        if ($item->club_id !== $club->id) abort(403);

        $name = $item->name;
        $item->delete();

        ActivityLog::log('deleted', 'ClubInventoryItem', null,
            "Инвентарь: удалена позиция «{$name}»", clubId: $club->id);

        return back()->with('success', 'Позиция удалена');
    }
}

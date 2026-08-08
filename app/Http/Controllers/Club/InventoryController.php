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

    /**
     * Клуб текущего пользователя, иначе 403.
     * Модуль `inventory` проверяет middleware `club.feature:inventory` на маршрутах —
     * как во всех остальных разделах клуба (он же пропускает супер-админа).
     */
    private function requireClub()
    {
        $club = $this->getClub();
        if (!$club) abort(403, 'У вас нет клуба');

        return $club;
    }

    /** Правила общие для добавления и редактирования. Цена — целые тенге, без копеек. */
    private static function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'price' => 'required|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    /** Русские тексты ошибок валидации. */
    private static function messages(): array
    {
        return [
            'name.required' => 'Укажите название позиции',
            'name.max' => 'Название слишком длинное (максимум :max символов)',
            'price.required' => 'Укажите цену',
            'price.integer' => 'Цена должна быть целым числом тенге, без копеек',
            'price.min' => 'Цена не может быть отрицательной',
        ];
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

        $data = $request->validate(self::rules(), self::messages());

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

        $data = $request->validate(self::rules(), self::messages());

        // Активность меняем ТОЛЬКО если поле реально пришло в запросе. Иначе частичный
        // PUT (например, из будущего мобильного API) молча выключил бы позицию.
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        } else {
            unset($data['is_active']);
        }

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

<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubInventoryItem;
use App\Models\CourtBooking;
use App\Models\CourtBookingInventoryItem;
use Illuminate\Support\Carbon;

/**
 * Запись позиций инвентаря в бронь корта.
 *
 * Цена и название сохраняются снимком на момент сохранения брони: справочник
 * могли потом изменить, а старая бронь должна остаться прежней.
 */
class BookingInventoryService
{
    /** Больше этого количества одной позиции в брони быть не может. */
    private const MAX_QUANTITY = 99;

    /**
     * Заменить строки инвентаря брони переданным набором.
     *
     * @param  array<int, array{item_id?: mixed, quantity?: mixed}> $rows
     * @return int сумма за инвентарь
     */
    public function sync(CourtBooking $booking, Club $club, array $rows): int
    {
        // Схлопываем повторы: одна позиция — одна строка, количества складываются.
        $wanted = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 1);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            $wanted[$itemId] = ($wanted[$itemId] ?? 0) + $qty;
        }

        // Активные позиции этого клуба — нужны и чтобы отобрать новый набор,
        // и чтобы понять, какие из уже существующих строк брони теперь
        // «замороженные»: club_inventory_item_id пуст (позицию удалили из
        // справочника) либо ссылается на позицию, которой больше нет среди
        // активных (выключили). Через пикер такую строку не выбрать заново —
        // она обязана пережить сохранение независимо от присланного набора,
        // иначе правка чего угодно (телефона, способа оплаты) тихо стирает
        // уже списанные за инвентарь деньги.
        $activeItems = ClubInventoryItem::where('club_id', $club->id)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $frozenIds = [];
        $frozenTotal = 0;
        foreach ($booking->inventoryItems as $row) {
            if ($row->club_inventory_item_id === null || !$activeItems->has($row->club_inventory_item_id)) {
                $frozenIds[] = $row->id;
                $frozenTotal += $row->price * $row->quantity;
            }
        }

        // Остальные строки заменяем целиком — так редактирование не задваивает позиции.
        $booking->inventoryItems()->whereNotIn('id', $frozenIds ?: [0])->delete();

        if (empty($wanted)) {
            $booking->load('inventoryItems');
            return $frozenTotal;
        }

        // Собираем все строки и вставляем одним запросом вместо INSERT на каждую позицию
        // (до 50 позиций за вызов — цикл create() давал бы до 50 отдельных запросов).
        $now = Carbon::now();
        $total = $frozenTotal;
        $insertRows = [];
        foreach ($wanted as $itemId => $qty) {
            $item = $activeItems->get($itemId);
            if (!$item) {
                continue; // чужая, выключенная или несуществующая позиция — отбрасываем
            }
            $qty = min($qty, self::MAX_QUANTITY);
            $price = (int) $item->price;

            $insertRows[] = [
                'court_booking_id' => $booking->id,
                'club_inventory_item_id' => $item->id,
                'name' => $item->name,
                'price' => $price,
                'quantity' => $qty,
                // insert() — это массовая вставка, Eloquent не проставляет таймстемпы сам.
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $total += $price * $qty;
        }

        if (!empty($insertRows)) {
            CourtBookingInventoryItem::query()->insert($insertRows);
        }

        $booking->load('inventoryItems');

        return $total;
    }
}

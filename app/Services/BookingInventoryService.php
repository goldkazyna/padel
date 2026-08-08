<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubInventoryItem;
use App\Models\CourtBooking;
use App\Models\CourtBookingInventoryItem;

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

        // Прежние строки заменяем целиком — так редактирование не задваивает позиции.
        $booking->inventoryItems()->delete();

        if (empty($wanted)) {
            $booking->load('inventoryItems');
            return 0;
        }

        // Берём только активные позиции этого клуба — чужие и выключенные отбрасываем.
        $items = ClubInventoryItem::where('club_id', $club->id)
            ->whereIn('id', array_keys($wanted))
            ->where('is_active', true)
            ->get();

        $total = 0;
        foreach ($items as $item) {
            $qty = min($wanted[$item->id], self::MAX_QUANTITY);
            $price = (int) $item->price;

            CourtBookingInventoryItem::create([
                'court_booking_id' => $booking->id,
                'club_inventory_item_id' => $item->id,
                'name' => $item->name,
                'price' => $price,
                'quantity' => $qty,
            ]);

            $total += $price * $qty;
        }

        $booking->load('inventoryItems');

        return $total;
    }
}

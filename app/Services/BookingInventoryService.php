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
 * Название и цена сохраняются снимком на момент ПЕРВОЙ выдачи позиции по этой
 * брони и дальше не меняются: справочник могли потом переименовать, переоценить,
 * выключить или удалить, а уже выданное должно остаться таким, каким его выдали.
 * Позиция, добавленная в бронь впервые, берёт актуальные название и цену.
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
        // Снимок уже существующих (редактируемых) строк — по item_id.
        // Пересборка ниже иначе брала бы ТЕКУЩИЕ название и цену из справочника
        // при каждом сохранении брони, а это противоречит принципу "позиция
        // фиксируется на момент выдачи": правка телефона клиента не должна
        // задним числом переписывать ни стоимость, ни название уже выданного
        // инвентаря.
        $existingPrices = [];
        $existingNames = [];
        foreach ($booking->inventoryItems as $row) {
            if ($row->club_inventory_item_id === null || !$activeItems->has($row->club_inventory_item_id)) {
                $frozenIds[] = $row->id;
                $frozenTotal += $row->price * $row->quantity;
            } else {
                $existingPrices[$row->club_inventory_item_id] = (int) $row->price;
                $existingNames[$row->club_inventory_item_id] = (string) $row->name;
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
            // Позиция уже была в этой брони — сохраняем её прежние название и
            // цену, сколько бы их ни правили в справочнике с тех пор. Позиция,
            // добавленная в бронь впервые, берёт текущие название и цену
            // справочника и фиксирует их как свой снимок с этого момента.
            $price = $existingPrices[$itemId] ?? (int) $item->price;
            $name = $existingNames[$itemId] ?? (string) $item->name;

            $insertRows[] = [
                'court_booking_id' => $booking->id,
                'club_inventory_item_id' => $item->id,
                'name' => $name,
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

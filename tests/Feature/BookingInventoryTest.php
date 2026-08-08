<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubInventoryItem;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\CourtBookingInventoryItem;
use App\Models\Tournament;
use App\Models\User;
use App\Services\BookingInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingInventoryTest extends TestCase
{
    use RefreshDatabase;

    /** Клуб с включёнными модулями, админ, корт. */
    private function setupClub(array $features = []): array
    {
        $club = Club::create([
            'name' => 'C',
            'address' => 'A',
            'features' => array_merge(['inventory' => true, 'courts' => true], $features),
        ]);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);

        return [$club, $admin, $court];
    }

    /** Позиция справочника инвентаря. */
    private function makeItem(Club $club, string $name, int $price, bool $active = true): ClubInventoryItem
    {
        return ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => $name, 'price' => $price, 'is_active' => $active,
        ]);
    }

    /** Обычная бронь корта. */
    private function makeBooking(Court $court, User $admin, int $price = 26000): CourtBooking
    {
        return CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Денис Дудников',
            'client_phone' => '77770000000',
            'status' => 'confirmed',
            'price' => $price,
            'booking_type' => 'individual',
            'booked_by' => $admin->id,
        ]);
    }

    public function test_booking_sums_inventory_rows(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $balls = $this->makeItem($club, 'Мячи', 2000);
        $booking = $this->makeBooking($court, $admin);

        CourtBookingInventoryItem::create([
            'court_booking_id' => $booking->id,
            'club_inventory_item_id' => $racket->id,
            'name' => $racket->name, 'price' => 3000, 'quantity' => 2,
        ]);
        CourtBookingInventoryItem::create([
            'court_booking_id' => $booking->id,
            'club_inventory_item_id' => $balls->id,
            'name' => $balls->name, 'price' => 2000, 'quantity' => 1,
        ]);

        $booking->refresh();
        $this->assertSame(2, $booking->inventoryItems->count());
        // 3000 × 2 + 2000 × 1
        $this->assertSame(8000, $booking->inventoryTotal());
        $this->assertSame(6000, $booking->inventoryItems->first()->total);
    }

    public function test_deleting_catalog_item_keeps_booking_row(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);

        $row = CourtBookingInventoryItem::create([
            'court_booking_id' => $booking->id,
            'club_inventory_item_id' => $racket->id,
            'name' => $racket->name, 'price' => 3000, 'quantity' => 1,
        ]);

        $racket->delete();

        $row->refresh();
        $this->assertNull($row->club_inventory_item_id);
        $this->assertSame('Аренда ракетки', $row->name, 'название сохраняется снимком');
        $this->assertSame(3000, $row->price);
    }

    public function test_deleting_booking_removes_rows(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);

        CourtBookingInventoryItem::create([
            'court_booking_id' => $booking->id,
            'club_inventory_item_id' => $racket->id,
            'name' => $racket->name, 'price' => 3000, 'quantity' => 1,
        ]);

        $booking->delete();

        $this->assertSame(0, CourtBookingInventoryItem::count());
    }

    public function test_sync_writes_rows_with_snapshot_price(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);

        $total = app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 2],
        ]);

        $this->assertSame(6000, $total);
        $row = $booking->fresh()->inventoryItems->first();
        $this->assertSame('Аренда ракетки', $row->name);
        $this->assertSame(3000, $row->price);
        $this->assertSame(2, $row->quantity);
    }

    public function test_snapshot_price_does_not_follow_catalog(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);

        app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 1],
        ]);

        // Клуб поднял цену — старая бронь не должна измениться.
        $racket->update(['price' => 4000]);

        $this->assertSame(3000, $booking->fresh()->inventoryItems->first()->price);
        $this->assertSame(3000, $booking->fresh()->inventoryTotal());
    }

    public function test_sync_replaces_previous_rows(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $balls = $this->makeItem($club, 'Мячи', 2000);
        $booking = $this->makeBooking($court, $admin);
        $service = app(BookingInventoryService::class);

        $service->sync($booking, $club, [['item_id' => $racket->id, 'quantity' => 1]]);
        $service->sync($booking->fresh(), $club, [['item_id' => $balls->id, 'quantity' => 3]]);

        $rows = $booking->fresh()->inventoryItems;
        $this->assertSame(1, $rows->count(), 'строки заменяются, а не задваиваются');
        $this->assertSame('Мячи', $rows->first()->name);
        $this->assertSame(6000, $booking->fresh()->inventoryTotal());
    }

    public function test_foreign_club_item_is_ignored(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $other = Club::create(['name' => 'Чужой', 'address' => 'B', 'features' => ['inventory' => true]]);
        $foreign = $this->makeItem($other, 'Чужая позиция', 5000);
        $booking = $this->makeBooking($court, $admin);

        $total = app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $foreign->id, 'quantity' => 1],
        ]);

        $this->assertSame(0, $total);
        $this->assertSame(0, $booking->fresh()->inventoryItems->count());
    }

    public function test_inactive_item_is_ignored(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $off = $this->makeItem($club, 'Старая ракетка', 1000, active: false);
        $booking = $this->makeBooking($court, $admin);

        $total = app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $off->id, 'quantity' => 1],
        ]);

        $this->assertSame(0, $total);
        $this->assertSame(0, $booking->fresh()->inventoryItems->count());
    }

    public function test_quantity_is_clamped_and_zero_dropped(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $balls = $this->makeItem($club, 'Мячи', 2000);
        $booking = $this->makeBooking($court, $admin);

        $total = app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 0],    // отбрасывается
            ['item_id' => $balls->id, 'quantity' => 500],   // ограничивается 99
        ]);

        $rows = $booking->fresh()->inventoryItems;
        $this->assertSame(1, $rows->count());
        $this->assertSame(99, $rows->first()->quantity);
        $this->assertSame(99 * 2000, $total);
    }

    public function test_sync_preserves_historical_row_and_includes_its_total(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $balls = $this->makeItem($club, 'Мячи', 2000);
        $booking = $this->makeBooking($court, $admin);
        $service = app(BookingInventoryService::class);

        $service->sync($booking, $club, [['item_id' => $racket->id, 'quantity' => 1]]);
        $racket->delete(); // позиция удалена из справочника — строка ракетки становится исторической

        // Новое сохранение с другим набором не должно затронуть историческую строку.
        $total = $service->sync($booking->fresh(), $club, [['item_id' => $balls->id, 'quantity' => 2]]);

        $rows = $booking->fresh()->inventoryItems;
        $this->assertSame(2, $rows->count(), 'историческая строка должна пережить sync() вместе с новой позицией');
        $this->assertTrue($rows->contains(fn ($r) => $r->club_inventory_item_id === null && $r->name === 'Аренда ракетки'),
            'историческая строка ракетки осталась');
        $this->assertTrue($rows->contains(fn ($r) => $r->name === 'Мячи' && $r->quantity === 2),
            'новая позиция записана');
        $this->assertSame(3000 + 4000, $total, 'сумма = историческая строка + новый набор');
        $this->assertSame(3000 + 4000, $booking->fresh()->inventoryTotal());
    }

    /**
     * Тот же класс проблемы, что и с удалённой позицией, но другая ветка:
     * позицию не удалили, а выключили (is_active = false). club_inventory_item_id
     * у строки остаётся, но выборка активных позиций её больше не находит —
     * строка обязана пережить sync() точно так же, как историческая.
     */
    public function test_sync_preserves_inactive_linked_row_and_includes_its_total(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $balls = $this->makeItem($club, 'Мячи', 2000);
        $booking = $this->makeBooking($court, $admin);
        $service = app(BookingInventoryService::class);

        $service->sync($booking, $club, [['item_id' => $racket->id, 'quantity' => 1]]);
        $racket->update(['is_active' => false]); // выключили, но не удалили — id у строки остаётся

        $total = $service->sync($booking->fresh(), $club, [['item_id' => $balls->id, 'quantity' => 2]]);

        $rows = $booking->fresh()->inventoryItems;
        $this->assertSame(2, $rows->count(), 'строка выключенной позиции должна пережить sync() вместе с новой позицией');
        $this->assertTrue($rows->contains(fn ($r) => $r->club_inventory_item_id === $racket->id && $r->name === 'Аренда ракетки'),
            'строка выключенной ракетки осталась, club_inventory_item_id не обнулился');
        $this->assertTrue($rows->contains(fn ($r) => $r->name === 'Мячи' && $r->quantity === 2),
            'новая позиция записана');
        $this->assertSame(3000 + 4000, $total, 'сумма = выключенная позиция + новый набор');
        $this->assertSame(3000 + 4000, $booking->fresh()->inventoryTotal());
    }

    public function test_same_item_twice_is_merged(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);

        $total = app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 1],
            ['item_id' => $racket->id, 'quantity' => 2],
        ]);

        $rows = $booking->fresh()->inventoryItems;
        $this->assertSame(1, $rows->count(), 'одна позиция — одна строка');
        $this->assertSame(3, $rows->first()->quantity);
        $this->assertSame(9000, $total);
    }

    public function test_empty_list_clears_rows(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);
        $service = app(BookingInventoryService::class);

        $service->sync($booking, $club, [['item_id' => $racket->id, 'quantity' => 1]]);
        $total = $service->sync($booking->fresh(), $club, []);

        $this->assertSame(0, $total);
        $this->assertSame(0, $booking->fresh()->inventoryItems->count());
    }

    public function test_store_booking_saves_inventory(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'slots' => 1,
            'client_name' => 'Денис Дудников',
            'client_phone' => '77770000000',
            'payment_method' => 'cash',
            'is_paid' => 0,
            'booking_type' => 'individual',
            'inventory' => [['item_id' => $racket->id, 'quantity' => 2]],
        ])->assertRedirect();

        $booking = CourtBooking::where('court_id', $court->id)->firstOrFail();
        $this->assertSame(6000, $booking->inventoryTotal());

        // Цена корта не должна включать инвентарь — иначе он задвоится при правке.
        // Сравниваем с ценой такой же брони, оформленной без инвентаря.
        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '14:00',
            'slots' => 1,
            'client_name' => 'Денис Дудников',
            'client_phone' => '77770000000',
            'payment_method' => 'cash',
            'is_paid' => 0,
            'booking_type' => 'individual',
        ])->assertRedirect();

        $plain = CourtBooking::where('court_id', $court->id)
            ->where('start_time', '14:00')->firstOrFail();
        $this->assertSame((int) $plain->price, (int) $booking->price,
            'инвентарь не попадает в цену корта');
    }

    public function test_update_booking_replaces_inventory(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $balls = $this->makeItem($club, 'Мячи', 2000);
        $booking = $this->makeBooking($court, $admin);
        app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 1],
        ]);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'client_name' => 'Денис Дудников',
            'client_phone' => '77770000000',
            'payment_method' => 'cash',
            'is_paid' => 0,
            'booking_type' => 'individual',
            'inventory' => [['item_id' => $balls->id, 'quantity' => 2]],
            'inventory_touched' => '1', // маркер из формы: пикер реально участвовал в сохранении
        ])->assertRedirect();

        $rows = $booking->fresh()->inventoryItems;
        $this->assertSame(1, $rows->count());
        $this->assertSame('Мячи', $rows->first()->name);
        $this->assertSame(4000, $booking->fresh()->inventoryTotal());
    }

    /**
     * Регресс: PUT без поля inventory (например, бронь открыли не со своей
     * видимой даты — карта __bookingInventory её не содержала, пикер пришёл
     * пустым) не должен стирать уже записанный инвентарь. Раньше контроллер
     * читал `$request->input('inventory', [])` и синкал с пустым набором —
     * любое сохранение без явного участия пикера тихо всё стирало.
     */
    public function test_update_without_inventory_field_keeps_existing_rows(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);
        app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 2],
        ]);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'client_name' => 'Денис Дудников',
            'client_phone' => '77770000000',
            'payment_method' => 'cash',
            'is_paid' => 0,
            'booking_type' => 'individual',
            // 'inventory' и 'inventory_touched' намеренно не переданы.
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame(6000, $booking->inventoryTotal(), 'инвентарь должен сохраниться, раз поле не передавали');
        $this->assertSame(1, $booking->inventoryItems->count());
    }

    /**
     * Регресс: строка с уже удалённой из справочника позицией обязана
     * пережить обычное сохранение брони (форма шлёт inventory_touched, но
     * список редактируемых позиций пуст) и учитываться в сумме «Итого».
     */
    public function test_historical_row_survives_booking_save_and_counts_in_total(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);
        app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 2],
        ]);

        $racket->delete(); // позиция удалена из справочника — строка становится исторической

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'client_name' => 'Денис Дудников',
            'client_phone' => '77770000000',
            'payment_method' => 'cash',
            'is_paid' => 0,
            'booking_type' => 'individual',
            'inventory_touched' => '1', // форма показывала пикер, но выбрать удалённую позицию уже нельзя
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame(1, $booking->inventoryItems->count(), 'историческая строка должна выжить');
        $this->assertNull($booking->inventoryItems->first()->club_inventory_item_id);
        $this->assertSame(6000, $booking->inventoryTotal(), 'сумма исторической строки учитывается в итоге');
    }

    /**
     * Тот же класс проблемы, что и с удалённой позицией (см. тест выше), но
     * через реальный PUT: строка с выключенной (не удалённой) позицией
     * обязана пережить сохранение брони и учитываться в сумме.
     */
    public function test_inactive_linked_row_survives_booking_save_and_counts_in_total(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);
        app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 2],
        ]);

        $racket->update(['is_active' => false]); // выключили, не удалили

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'client_name' => 'Денис Дудников',
            'client_phone' => '77770000000',
            'payment_method' => 'cash',
            'is_paid' => 0,
            'booking_type' => 'individual',
            'inventory_touched' => '1', // выключенную позицию выбрать заново нельзя — ничего не шлём
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame(1, $booking->inventoryItems->count(), 'строка с выключенной позицией должна выжить');
        $this->assertSame($racket->id, $booking->inventoryItems->first()->club_inventory_item_id,
            'club_inventory_item_id не обнуляется — позицию не удаляли, только выключили');
        $this->assertSame(6000, $booking->inventoryTotal(), 'сумма выключенной позиции учитывается в итоге');
    }

    /**
     * Регресс: смена типа брони на «Мягкая бронь» (soft) — обычный тип, цена
     * не считается автоматически, поэтому инвентарь не должен стираться.
     * До фикса JS сбрасывал выбор при ЛЮБОЙ смене типа в окне редактирования,
     * из-за чего пикер уходил пустым и sync() стирал прежний набор.
     */
    public function test_update_to_soft_type_keeps_inventory(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);
        app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 2],
        ]);

        // Как реально шлёт форма после фикса: тип сменился, но пикер не
        // сбрасывался — набор переотправляется как есть.
        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'client_name' => 'Денис Дудников',
            'client_phone' => '77770000000',
            'payment_method' => 'cash',
            'is_paid' => 0,
            'booking_type' => 'soft',
            'inventory_touched' => '1',
            'inventory' => [['item_id' => $racket->id, 'quantity' => 2]],
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame(6000, $booking->inventoryTotal(), 'при переходе в «мягкую» бронь инвентарь должен сохраниться');
        $this->assertSame(1, $booking->inventoryItems->count());
    }

    /**
     * Регресс: перевод брони в группу очищает обычные строки (цену считает
     * группа), но не должен трогать замороженные — позицию, которую уже
     * удалили из справочника до этого перевода. Раньше здесь стоял прямой
     * inventoryItems()->delete() без исключений.
     */
    public function test_update_to_group_keeps_historical_row(): void
    {
        [$club, $admin, $court] = $this->setupClub(['groups' => true]);
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $balls = $this->makeItem($club, 'Мячи', 2000);
        $booking = $this->makeBooking($court, $admin);
        app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 1],
            ['item_id' => $balls->id, 'quantity' => 1],
        ]);
        $racket->delete(); // одна из строк становится исторической

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'group',
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame(1, $booking->inventoryItems->count(), 'обычная строка (мячи) очищена, историческая (ракетка) — нет');
        $this->assertNull($booking->inventoryItems->first()->club_inventory_item_id);
        $this->assertSame(3000, $booking->inventoryTotal());
    }

    public function test_group_booking_ignores_inventory(): void
    {
        [$club, $admin, $court] = $this->setupClub(['groups' => true]);
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '12:00',
            'slots' => 1,
            'booking_type' => 'group',
            'inventory' => [['item_id' => $racket->id, 'quantity' => 1]],
        ])->assertRedirect();

        $booking = CourtBooking::where('court_id', $court->id)->firstOrFail();
        $this->assertSame(0, $booking->inventoryTotal());
    }

    public function test_disabled_module_ignores_inventory(): void
    {
        [$club, $admin, $court] = $this->setupClub(['inventory' => false]);
        $racket = ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Аренда ракетки', 'price' => 3000, 'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '13:00',
            'slots' => 1,
            'client_name' => 'Денис Дудников',
            'client_phone' => '77770000000',
            'payment_method' => 'cash',
            'is_paid' => 0,
            'booking_type' => 'individual',
            'inventory' => [['item_id' => $racket->id, 'quantity' => 1]],
        ])->assertRedirect();

        $booking = CourtBooking::where('court_id', $court->id)->firstOrFail();
        $this->assertSame(0, $booking->inventoryTotal());
    }

    public function test_schedule_exposes_active_items(): void
    {
        [$club, $admin] = $this->setupClub();
        $this->makeItem($club, 'Аренда ракетки', 3000);
        $this->makeItem($club, 'Старая ракетка', 1000, active: false);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => now()->addDay()->toDateString()]))
            ->assertOk()
            ->assertSee('Аренда ракетки')
            ->assertDontSee('Старая ракетка');
    }

    public function test_week_schedule_exposes_active_items(): void
    {
        [$club, $admin] = $this->setupClub();
        $this->makeItem($club, 'Аренда ракетки', 3000);

        $this->actingAs($admin)
            ->get(route('club.courts.scheduleWeek', ['date' => now()->addDay()->toDateString()]))
            ->assertOk()
            ->assertSee('Аренда ракетки');
    }

    /**
     * Регресс: обычная бронь с инвентарём становится групповой — цена группы
     * считается автоматически, поэтому инвентарь должен полностью очиститься.
     */
    public function test_update_to_group_clears_inventory(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);
        app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 1],
        ]);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'group',
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame(0, $booking->inventoryTotal());
        $this->assertSame(0, $booking->inventoryItems->count());
    }

    /**
     * Регресс: то же самое при смене типа на «Турнир» — цену считает
     * TournamentBookingPriceService по взносам участников.
     */
    public function test_update_to_tournament_clears_inventory(): void
    {
        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);
        $booking = $this->makeBooking($court, $admin);
        app(BookingInventoryService::class)->sync($booking, $club, [
            ['item_id' => $racket->id, 'quantity' => 1],
        ]);
        $tournament = Tournament::create([
            'club_id' => $club->id,
            'name' => 'Американо',
            'type' => 'americano',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'max_participants' => 16,
            'price' => 20000,
        ]);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame(0, $booking->inventoryTotal());
        $this->assertSame(0, $booking->inventoryItems->count());
    }

    /**
     * Повтор брони (repeat/repeat_until) создаёт несколько отдельных броней —
     * у каждой должен быть СВОЙ набор строк инвентаря, а не один общий.
     * Заморозка времени на понедельник: иначе в сб/вс «до конца недели»
     * даёт всего одну дату и тест теряет смысл (см. TournamentCourtBookingTest).
     */
    public function test_repeat_booking_creates_own_inventory_per_date(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-08-10 10:00:00')); // понедельник

        [$club, $admin, $court] = $this->setupClub();
        $racket = $this->makeItem($club, 'Аренда ракетки', 3000);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'slots' => 1,
            'client_name' => 'Денис Дудников',
            'client_phone' => '77770000000',
            'payment_method' => 'cash',
            'is_paid' => 0,
            'booking_type' => 'individual',
            'repeat' => 'daily',
            'repeat_until' => 'week',
            'inventory' => [['item_id' => $racket->id, 'quantity' => 1]],
        ])->assertRedirect();

        $bookings = CourtBooking::where('court_id', $court->id)->orderBy('date')->get();
        $this->assertGreaterThan(1, $bookings->count(), 'повтор должен создать больше одной брони');
        foreach ($bookings as $booking) {
            $this->assertSame(3000, $booking->inventoryTotal(), "у брони на {$booking->date} должен быть свой инвентарь");
        }
    }
}

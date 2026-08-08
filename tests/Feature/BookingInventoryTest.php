<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubInventoryItem;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\CourtBookingInventoryItem;
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
}

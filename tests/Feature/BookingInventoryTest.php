<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubInventoryItem;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\CourtBookingInventoryItem;
use App\Models\User;
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
}

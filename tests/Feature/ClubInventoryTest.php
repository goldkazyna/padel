<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubInventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubInventoryTest extends TestCase
{
    use RefreshDatabase;

    /** Клуб с включённым модулем инвентаря и его администратор. */
    private function setupClub(array $features = []): array
    {
        $club = Club::create([
            'name' => 'C',
            'address' => 'A',
            'features' => array_merge(['inventory' => true], $features),
        ]);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        return [$club, $admin];
    }

    public function test_item_belongs_to_club(): void
    {
        [$club] = $this->setupClub();

        $item = ClubInventoryItem::create([
            'club_id' => $club->id,
            'name' => 'Аренда ракетки',
            'price' => 3000,
            'is_active' => true,
        ]);

        $this->assertSame($club->id, $item->fresh()->club->id);
        $this->assertTrue($club->inventoryItems->contains($item));
        $this->assertSame('3000.00', $item->fresh()->price);
        $this->assertTrue($item->fresh()->is_active);
    }

    public function test_active_scope_skips_disabled_items(): void
    {
        [$club] = $this->setupClub();

        ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Мячи', 'price' => 2000, 'is_active' => true,
        ]);
        ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Старая ракетка', 'price' => 1000, 'is_active' => false,
        ]);

        $names = ClubInventoryItem::where('club_id', $club->id)->active()->pluck('name')->all();

        $this->assertSame(['Мячи'], $names);
    }
}

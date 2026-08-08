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

    public function test_inventory_feature_defaults_to_enabled(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $club = Club::create(['name' => 'C', 'address' => 'A']);

        $this->actingAs($superAdmin)->put(route('admin.clubs.update', $club), [
            'name' => 'C',
            'address' => 'A',
        ])->assertRedirect();

        $this->assertTrue($club->fresh()->hasFeature('inventory'));
    }

    public function test_super_admin_can_disable_inventory_feature(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $club = Club::create(['name' => 'C', 'address' => 'A']);

        $this->actingAs($superAdmin)->put(route('admin.clubs.update', $club), [
            'name' => 'C',
            'address' => 'A',
            'features' => ['inventory' => '0'],
        ])->assertRedirect();

        $this->assertFalse($club->fresh()->hasFeature('inventory'));
    }

    public function test_club_without_inventory_key_has_module_enabled(): void
    {
        // Клуб, созданный до появления модуля: ключа inventory в features нет вовсе.
        $club = Club::create([
            'name' => 'Старый клуб',
            'address' => 'A',
            'features' => ['tournaments' => true],
        ]);

        $this->assertTrue($club->hasFeature('inventory'));
    }
}

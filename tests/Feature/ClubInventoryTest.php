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

    /** Модератор клуба. */
    private function makeModerator(Club $club): User
    {
        $moderator = User::factory()->create(['role' => 'club_moderator']);
        $moderator->moderatorClubs()->attach($club->id);

        return $moderator;
    }

    public function test_admin_creates_item(): void
    {
        [$club, $admin] = $this->setupClub();

        $this->actingAs($admin)->post(route('club.inventory.store'), [
            'name' => 'Аренда ракетки',
            'price' => 3000,
        ])->assertRedirect();

        $item = ClubInventoryItem::where('club_id', $club->id)->first();
        $this->assertNotNull($item);
        $this->assertSame('Аренда ракетки', $item->name);
        $this->assertSame('3000.00', $item->price);
        $this->assertTrue($item->is_active);
    }

    public function test_moderator_can_manage_inventory(): void
    {
        [$club] = $this->setupClub();
        $moderator = $this->makeModerator($club);

        $this->actingAs($moderator)->post(route('club.inventory.store'), [
            'name' => 'Мячи',
            'price' => 2000,
        ])->assertRedirect();

        $this->assertSame(1, ClubInventoryItem::where('club_id', $club->id)->count());
    }

    public function test_index_lists_only_own_club_items(): void
    {
        [$club, $admin] = $this->setupClub();
        ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Мячи', 'price' => 2000, 'is_active' => true,
        ]);

        $other = Club::create(['name' => 'Чужой', 'address' => 'B', 'features' => ['inventory' => true]]);
        ClubInventoryItem::create([
            'club_id' => $other->id, 'name' => 'Чужая позиция', 'price' => 500, 'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('club.inventory.index'))
            ->assertOk()
            ->assertSee('Мячи')
            ->assertDontSee('Чужая позиция');
    }

    public function test_cannot_touch_foreign_club_item(): void
    {
        [, $admin] = $this->setupClub();
        $other = Club::create(['name' => 'Чужой', 'address' => 'B', 'features' => ['inventory' => true]]);
        $foreign = ClubInventoryItem::create([
            'club_id' => $other->id, 'name' => 'Чужая позиция', 'price' => 500, 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('club.inventory.update', $foreign), ['name' => 'Взлом', 'price' => 1])
            ->assertForbidden();
        $this->actingAs($admin)
            ->delete(route('club.inventory.destroy', $foreign))
            ->assertForbidden();

        $this->assertSame('Чужая позиция', $foreign->fresh()->name);
    }

    public function test_disabled_module_forbids_section(): void
    {
        [$club, $admin] = $this->setupClub(['inventory' => false]);
        $item = ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Мячи', 'price' => 2000, 'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('club.inventory.index'))->assertForbidden();
        $this->actingAs($admin)
            ->post(route('club.inventory.store'), ['name' => 'Мячи', 'price' => 2000])
            ->assertForbidden();
        $this->actingAs($admin)
            ->put(route('club.inventory.update', $item), ['name' => 'Мячи 2', 'price' => 2000])
            ->assertForbidden();
        $this->actingAs($admin)
            ->delete(route('club.inventory.destroy', $item))
            ->assertForbidden();
    }

    public function test_validation_rejects_empty_name_and_negative_price(): void
    {
        [, $admin] = $this->setupClub();

        $this->actingAs($admin)
            ->post(route('club.inventory.store'), ['name' => '', 'price' => 3000])
            ->assertSessionHasErrors('name');
        $this->actingAs($admin)
            ->post(route('club.inventory.store'), ['name' => 'Мячи', 'price' => -5])
            ->assertSessionHasErrors('price');

        $this->assertSame(0, ClubInventoryItem::count());
    }

    public function test_item_can_be_updated_and_deactivated(): void
    {
        [$club, $admin] = $this->setupClub();
        $item = ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Мячи', 'price' => 2000, 'is_active' => true,
        ]);

        $this->actingAs($admin)->put(route('club.inventory.update', $item), [
            'name' => 'Мячи (набор)',
            'price' => 2500,
            'is_active' => '0',
        ])->assertRedirect();

        $item->refresh();
        $this->assertSame('Мячи (набор)', $item->name);
        $this->assertSame('2500.00', $item->price);
        $this->assertFalse($item->is_active);
    }

    public function test_item_can_be_deleted(): void
    {
        [$club, $admin] = $this->setupClub();
        $item = ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Мячи', 'price' => 2000, 'is_active' => true,
        ]);

        $this->actingAs($admin)->delete(route('club.inventory.destroy', $item))->assertRedirect();

        $this->assertSame(0, ClubInventoryItem::where('club_id', $club->id)->count());
    }

    public function test_menu_shows_inventory_link_when_module_enabled(): void
    {
        [, $admin] = $this->setupClub();

        $this->actingAs($admin)->get(route('club.inventory.index'))
            ->assertOk()
            ->assertSee(route('club.inventory.index'), escape: false)
            ->assertSee('Инвентарь');
    }

    public function test_inactive_item_is_marked_in_list(): void
    {
        [$club, $admin] = $this->setupClub();
        ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Старая ракетка', 'price' => 1000, 'is_active' => false,
        ]);

        $this->actingAs($admin)->get(route('club.inventory.index'))
            ->assertOk()
            ->assertSee('Старая ракетка')
            ->assertSee('Выключена');
    }
}

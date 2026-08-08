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
        // Цена — целые тенге, без копеек.
        $this->assertSame(3000, $item->fresh()->price);
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
        $this->assertSame(3000, $item->price);
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

    public function test_super_admin_keeps_access_when_module_disabled_for_club(): void
    {
        // Супер-админ администрирует все клубы, поэтому флаг модуля конкретного клуба
        // не должен его отсекать — иначе он не сможет управлять инвентарём вообще нигде.
        Club::create(['name' => 'C', 'address' => 'A', 'features' => ['inventory' => false]]);
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin)->get(route('club.inventory.index'))->assertOk();
        $this->actingAs($superAdmin)
            ->post(route('club.inventory.store'), ['name' => 'Мячи', 'price' => 2000])
            ->assertRedirect();
        $this->assertSame(1, ClubInventoryItem::count());
    }

    public function test_disabled_module_answers_with_russian_message(): void
    {
        [, $admin] = $this->setupClub(['inventory' => false]);

        $this->actingAs($admin)->get(route('club.inventory.index'))
            ->assertForbidden()
            ->assertSee('Этот раздел отключён для вашего клуба');
    }

    public function test_menu_hides_inventory_link_when_module_disabled(): void
    {
        // Смотрим меню на странице, которая доступна при выключенном модуле,
        // — сама страница инвентаря в этом случае отдаёт 403.
        [, $admin] = $this->setupClub(['inventory' => false]);

        $this->actingAs($admin)->get(route('club.help.index'))
            ->assertOk()
            // Меню на этой странице действительно рисуется — соседний пункт на месте.
            ->assertSee(route('club.clients.index'), escape: false)
            ->assertDontSee(route('club.inventory.index'), escape: false);
    }

    public function test_menu_shows_inventory_link_on_other_pages_when_module_enabled(): void
    {
        [, $admin] = $this->setupClub();

        $this->actingAs($admin)->get(route('club.help.index'))
            ->assertOk()
            ->assertSee(route('club.inventory.index'), escape: false);
    }

    public function test_moderator_menu_hides_inventory_link_when_module_disabled(): void
    {
        [$club] = $this->setupClub(['inventory' => false]);
        $moderator = $this->makeModerator($club);

        $this->actingAs($moderator)->get(route('club.help.index'))
            ->assertOk()
            ->assertSee(route('club.clients.index'), escape: false)
            ->assertDontSee(route('club.inventory.index'), escape: false);
    }

    public function test_player_cannot_access_inventory(): void
    {
        [$club] = $this->setupClub();
        $item = ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Мячи', 'price' => 2000, 'is_active' => true,
        ]);
        $player = User::factory()->create(['role' => 'player']);

        $this->actingAs($player)->get(route('club.inventory.index'))->assertForbidden();
        $this->actingAs($player)
            ->post(route('club.inventory.store'), ['name' => 'Взлом', 'price' => 1])
            ->assertForbidden();
        $this->actingAs($player)
            ->put(route('club.inventory.update', $item), ['name' => 'Взлом', 'price' => 1])
            ->assertForbidden();
        $this->actingAs($player)
            ->delete(route('club.inventory.destroy', $item))
            ->assertForbidden();

        $this->assertSame('Мячи', $item->fresh()->name);
        $this->assertSame(1, ClubInventoryItem::count());
    }

    public function test_price_must_be_whole_tenge(): void
    {
        [$club, $admin] = $this->setupClub();

        $this->actingAs($admin)
            ->post(route('club.inventory.store'), ['name' => 'Мячи', 'price' => 2500.50])
            ->assertSessionHasErrors('price');
        $this->assertSame(0, ClubInventoryItem::count());

        $item = ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Мячи', 'price' => 2000, 'is_active' => true,
        ]);
        $this->actingAs($admin)
            ->put(route('club.inventory.update', $item), ['name' => 'Мячи', 'price' => 2500.50])
            ->assertSessionHasErrors('price');
        $this->assertSame(2000, $item->fresh()->price);
    }

    public function test_price_is_shown_without_kopecks(): void
    {
        [$club, $admin] = $this->setupClub();
        ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Аренда ракетки', 'price' => 3000, 'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('club.inventory.index'))
            ->assertOk()
            ->assertSee('3 000 ₸')
            ->assertDontSee('3000.00');
    }

    public function test_update_without_is_active_keeps_current_state(): void
    {
        // Частичное обновление (без ключа is_active) не должно молча выключать позицию.
        [$club, $admin] = $this->setupClub();
        $item = ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Мячи', 'price' => 2000, 'is_active' => true,
        ]);

        $this->actingAs($admin)->put(route('club.inventory.update', $item), [
            'name' => 'Мячи (набор)',
            'price' => 2500,
        ])->assertRedirect();

        $item->refresh();
        $this->assertSame('Мячи (набор)', $item->name);
        $this->assertTrue($item->is_active, 'Позиция не должна выключаться, если поле активности не пришло.');

        // И наоборот: выключенная позиция остаётся выключенной.
        $item->update(['is_active' => false]);
        $this->actingAs($admin)->put(route('club.inventory.update', $item), [
            'name' => 'Мячи (набор)',
            'price' => 2500,
        ])->assertRedirect();

        $this->assertFalse($item->fresh()->is_active);
    }

    public function test_edit_validation_error_does_not_leak_into_add_form(): void
    {
        [$club, $admin] = $this->setupClub();
        $item = ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Мячи', 'price' => 2000, 'is_active' => true,
        ]);

        // Провалившееся редактирование: имя улетает в old(), модалка при этом закрыта.
        $this->actingAs($admin)
            ->from(route('club.inventory.index'))
            ->put(route('club.inventory.update', $item), ['name' => 'Имя из модалки', 'price' => -5])
            ->assertSessionHasErrors('price');

        $this->actingAs($admin)->get(route('club.inventory.index'))
            ->assertOk()
            ->assertDontSee('value="Имя из модалки"', escape: false);
    }

    public function test_failed_add_keeps_entered_values_in_add_form(): void
    {
        [, $admin] = $this->setupClub();

        $this->actingAs($admin)
            ->from(route('club.inventory.index'))
            ->post(route('club.inventory.store'), ['inv_form' => 'create', 'name' => 'Аренда ракетки', 'price' => -5])
            ->assertSessionHasErrors('price');

        $this->actingAs($admin)->get(route('club.inventory.index'))
            ->assertOk()
            ->assertSee('value="Аренда ракетки"', escape: false);
    }

    public function test_edit_form_action_matches_named_route(): void
    {
        // Адрес сохранения в модалке должен собираться из route(), а не из захардкоженного
        // префикса — иначе переименование префикса уведёт сохранение в 404.
        [$club, $admin] = $this->setupClub();
        $item = ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => 'Мячи', 'price' => 2000, 'is_active' => true,
        ]);

        $html = $this->actingAs($admin)->get(route('club.inventory.index'))->assertOk()->getContent();

        preg_match('/INVENTORY_UPDATE_URL\s*=\s*("(?:[^"\\\\]|\\\\.)*")/', $html, $m);
        $this->assertNotEmpty($m, 'Не нашли шаблон адреса сохранения в скрипте страницы.');
        $template = json_decode($m[1]);

        // Подстановка id должна давать ровно тот адрес, который знает роутер.
        $this->assertSame(
            route('club.inventory.update', $item),
            str_replace('__ID__', (string) $item->id, $template)
        );
    }

    public function test_activity_log_shows_russian_subject_and_whole_price(): void
    {
        [$club, $admin] = $this->setupClub(['activity_log' => true]);

        $this->actingAs($admin)->post(route('club.inventory.store'), [
            'name' => 'Аренда ракетки',
            'price' => 3000,
        ])->assertRedirect();

        $log = \App\Models\ActivityLog::where('club_id', $club->id)
            ->where('subject_type', 'ClubInventoryItem')->firstOrFail();
        $this->assertStringContainsString('3000 ₸', $log->description);
        $this->assertStringNotContainsString('3000.00', $log->description);

        $this->actingAs($admin)->get(route('club.activityLog'))
            ->assertOk()
            ->assertSee('Инвентарь')
            ->assertDontSee('ClubInventoryItem');
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
        $this->assertSame(2500, $item->price);
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

    /**
     * Название позиции подставляется в confirm() удаления. Blade экранирует апостроф
     * в HTML-сущность &#039;, но браузер декодирует её обратно в кавычку ДО того,
     * как отдать строку JS-парсеру — значит, экранирование должно быть JS-безопасным
     * (через @js()), а не просто HTML-безопасным.
     */
    public function test_item_name_with_quotes_cannot_break_delete_confirm_script(): void
    {
        [$club, $admin] = $this->setupClub();
        $malicious = "Мяч'); alert(1); //";
        ClubInventoryItem::create([
            'club_id' => $club->id, 'name' => $malicious, 'price' => 100, 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('club.inventory.index'))->assertOk();

        // Достаём именно содержимое атрибута onsubmit (а не всю страницу — в текстовой
        // ячейке таблицы декодированное имя тоже встретится, но там это безопасно,
        // это просто текст, а не JS-контекст).
        preg_match('/onsubmit="([^"]*)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'Не нашли атрибут onsubmit формы удаления на странице.');

        // Имитируем то, что сделает браузер: раскроет HTML-сущности внутри атрибута
        // onsubmit ПЕРЕД тем, как передать содержимое JS-парсеру.
        $decodedAsBrowserWould = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);

        $this->assertStringNotContainsString(
            $malicious,
            $decodedAsBrowserWould,
            'Название позиции не должно попадать в JS-строку как есть — иначе можно оборвать confirm() и выполнить произвольный код.'
        );
    }
}

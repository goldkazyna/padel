# Раздел «Инвентарь» — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Клуб может вести справочник платных позиций — аренда ракетки 3000 ₸, мячи 2000 ₸ и подобное: добавить, изменить, удалить, включить или выключить.

**Architecture:** Отдельная таблица `club_inventory_items` со ссылкой на клуб, модель `ClubInventoryItem`, тонкий контроллер `Club\InventoryController` с четырьмя действиями и одна страница со списком и модалкой правки. Раздел прячется за новым флагом модуля `inventory`. Всё строится по образцу типов клубных карт — той же структуры сущность.

**Tech Stack:** Laravel 12, MySQL (прод) / SQLite (тесты), Blade, PHPUnit.

## Global Constraints

- Спека: `docs/superpowers/specs/2026-08-08-club-inventory-design.md`
- Все комментарии в коде и тексты интерфейса — на русском. Никогда не на английском.
- Флаг модуля называется ровно `inventory`, значение по умолчанию — `true` (включён всем клубам).
- Доступ: администратор клуба и модератор клуба. Позиция чужого клуба — `403`. Выключенный модуль — `403`.
- Поля позиции: только название, цена, активность. Категорий, описания, остатков и продаж на этом этапе нет.
- Образец для подражания во всём — типы клубных карт: `app/Http/Controllers/Club/ClubCardTypeController.php`, `resources/views/club/cards/index.blade.php`, `resources/views/club/cards/_type_modal.blade.php`.
- Прогон тестов только точечный, через `--filter`: в сьюте есть давно падающие тесты, не связанные с этой работой (в частности 2 в `CourtScheduleTest` про `calculatePrice`).
- Полный сьют (`php artisan test` без фильтра) в этом окружении не проходит — упирается в `memory_limit` PHP. Это предсуществующая проблема, чинить её не нужно.

---

## File Structure

| Файл | Ответственность |
|---|---|
| `database/migrations/2026_08_08_000001_create_club_inventory_items_table.php` | Создать: таблица позиций |
| `app/Models/ClubInventoryItem.php` | Создать: модель, касты, связь, скоуп активных |
| `app/Models/Club.php` | Изменить: связь `inventoryItems()` |
| `app/Http/Controllers/Club/InventoryController.php` | Создать: index/store/update/destroy |
| `routes/web.php` | Изменить: четыре маршрута в группе клуба |
| `app/Http/Controllers/Admin/ClubController.php` | Изменить: флаг `inventory` в списке фич |
| `resources/views/admin/clubs/edit.blade.php` | Изменить: чекбокс модуля |
| `resources/views/club/inventory/index.blade.php` | Создать: страница списка + форма + модалка |
| `resources/views/layouts/app.blade.php` | Изменить: пункт меню в двух блоках |
| `tests/Feature/ClubInventoryTest.php` | Создать: тесты доступа, CRUD, валидации |

---

### Task 1: Таблица, модель и связь

**Files:**
- Create: `database/migrations/2026_08_08_000001_create_club_inventory_items_table.php`
- Create: `app/Models/ClubInventoryItem.php`
- Modify: `app/Models/Club.php`
- Test: `tests/Feature/ClubInventoryTest.php`

**Interfaces:**
- Consumes: ничего
- Produces: модель `ClubInventoryItem` с полями `club_id`, `name`, `price`, `is_active`; скоуп `active()`; связи `ClubInventoryItem::club()` и `Club::inventoryItems()`

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Feature/ClubInventoryTest.php`:

```php
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
```

- [ ] **Step 2: Запустить тест и убедиться, что он падает**

Run: `php artisan test --filter=ClubInventoryTest`
Expected: FAIL — класса `ClubInventoryItem` не существует.

- [ ] **Step 3: Создать миграцию**

`database/migrations/2026_08_08_000001_create_club_inventory_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // По этой паре строится список раздела.
            $table->index(['club_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_inventory_items');
    }
};
```

- [ ] **Step 4: Создать модель**

`app/Models/ClubInventoryItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Позиция инвентаря клуба: аренда ракетки, мячи и прочее платное,
 * не связанное с кортами. На этом этапе — только справочник.
 */
class ClubInventoryItem extends Model
{
    protected $fillable = [
        'club_id',
        'name',
        'price',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    /** Только позиции, доступные к продаже. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
```

- [ ] **Step 5: Добавить связь в `Club`**

В `app/Models/Club.php` рядом с остальными связями:

```php
    /** Позиции инвентаря клуба (аренда ракеток, мячи и прочее). */
    public function inventoryItems()
    {
        return $this->hasMany(ClubInventoryItem::class);
    }
```

- [ ] **Step 6: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=ClubInventoryTest`
Expected: PASS, 2 теста.

- [ ] **Step 7: Коммит**

```bash
git add database/migrations/2026_08_08_000001_create_club_inventory_items_table.php app/Models/ClubInventoryItem.php app/Models/Club.php tests/Feature/ClubInventoryTest.php
git commit -m "feat(inventory): таблица и модель позиций инвентаря клуба"
```

---

### Task 2: Модуль `inventory`

**Files:**
- Modify: `app/Http/Controllers/Admin/ClubController.php:97-108`
- Modify: `resources/views/admin/clubs/edit.blade.php:402-408`
- Test: `tests/Feature/ClubInventoryTest.php` (дополняется)

**Interfaces:**
- Consumes: ничего
- Produces: флаг `inventory` в массиве `features` клуба, по умолчанию `true`; чекбокс «Инвентарь» в форме клуба у супер-админа

- [ ] **Step 1: Написать падающий тест**

Добавить в `tests/Feature/ClubInventoryTest.php` (нужен импорт `use App\Models\Club;` — уже есть):

```php
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
```

Маршрут проверен: клубы в админке объявлены как `Route::resource('clubs', ClubController::class)` (`routes/web.php:606`), поэтому обновление — это `PUT` с именем `admin.clubs.update`. Обязательных полей у формы два — `name` и `address` (`Admin/ClubController.php:50-51`), оба переданы в тестах выше.

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter=inventory_feature`
Expected: FAIL — `hasFeature('inventory')` возвращает `false`, потому что ключа в массиве нет.

- [ ] **Step 3: Добавить флаг в список фич**

В `app/Http/Controllers/Admin/ClubController.php` в массив `$validated['features']` (строки 98-108) добавить строку после `'groups'`:

```php
            'inventory' => (bool) ($features['inventory'] ?? true),
```

- [ ] **Step 4: Добавить чекбокс в форму клуба**

В `resources/views/admin/clubs/edit.blade.php` после блока «Групповые занятия» (строки 402-408) добавить:

```blade
                            <label class="form-check">
                                <input type="hidden" name="features[inventory]" value="0">
                                <input type="checkbox" name="features[inventory]" value="1" class="form-check-input"
                                       {{ old('features.inventory', $features['inventory'] ?? true) ? 'checked' : '' }}
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Инвентарь</span>
                            </label>
```

- [ ] **Step 5: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=ClubInventoryTest`
Expected: PASS, 4 теста.

- [ ] **Step 6: Коммит**

```bash
git add app/Http/Controllers/Admin/ClubController.php resources/views/admin/clubs/edit.blade.php tests/Feature/ClubInventoryTest.php
git commit -m "feat(inventory): модуль inventory в настройках клуба"
```

---

### Task 3: Контроллер и маршруты

**Files:**
- Create: `app/Http/Controllers/Club/InventoryController.php`
- Modify: `routes/web.php:302` (рядом с блоком клубных карт)
- Test: `tests/Feature/ClubInventoryTest.php` (дополняется)

**Interfaces:**
- Consumes: модель `ClubInventoryItem` из Task 1, флаг `inventory` из Task 2
- Produces: маршруты `club.inventory.index` (GET `/club/inventory`), `club.inventory.store` (POST `/club/inventory`), `club.inventory.update` (PUT `/club/inventory/{item}`), `club.inventory.destroy` (DELETE `/club/inventory/{item}`); переменная вьюхи `$items`

- [ ] **Step 1: Написать падающие тесты**

Добавить в `tests/Feature/ClubInventoryTest.php`:

```php
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
        [, $admin] = $this->setupClub(['inventory' => false]);

        $this->actingAs($admin)->get(route('club.inventory.index'))->assertForbidden();
        $this->actingAs($admin)
            ->post(route('club.inventory.store'), ['name' => 'Мячи', 'price' => 2000])
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
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter=ClubInventoryTest`
Expected: FAIL — маршрута `club.inventory.index` не существует.

- [ ] **Step 3: Написать контроллер**

`app/Http/Controllers/Club/InventoryController.php`:

```php
<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ClubInventoryItem;
use Illuminate\Http\Request;

/**
 * Справочник инвентаря клуба: аренда ракеток, мячи и прочее платное,
 * не связанное с кортами. Пока только справочник — без остатков и продаж.
 */
class InventoryController extends Controller
{
    /** Клуб текущего пользователя — как в остальных разделах клуба. */
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    /** Клуб с включённым модулем, иначе 403. */
    private function requireClub()
    {
        $club = $this->getClub();
        if (!$club || !$club->hasFeature('inventory')) abort(403);

        return $club;
    }

    public function index()
    {
        $club = $this->requireClub();

        $items = ClubInventoryItem::where('club_id', $club->id)
            ->orderBy('name')
            ->get();

        return view('club.inventory.index', compact('club', 'items'));
    }

    public function store(Request $request)
    {
        $club = $this->requireClub();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Укажите название позиции',
            'price.required' => 'Укажите цену',
            'price.numeric' => 'Цена должна быть числом',
            'price.min' => 'Цена не может быть отрицательной',
        ]);

        $data['club_id'] = $club->id;
        $data['is_active'] = $request->boolean('is_active', true);

        $item = ClubInventoryItem::create($data);

        ActivityLog::log('created', 'ClubInventoryItem', $item->id,
            "Инвентарь: добавлена позиция «{$item->name}» — {$item->price} ₸", clubId: $club->id);

        return back()->with('success', 'Позиция добавлена');
    }

    public function update(Request $request, ClubInventoryItem $item)
    {
        $club = $this->requireClub();
        if ($item->club_id !== $club->id) abort(403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Укажите название позиции',
            'price.required' => 'Укажите цену',
            'price.numeric' => 'Цена должна быть числом',
            'price.min' => 'Цена не может быть отрицательной',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        $item->update($data);

        ActivityLog::log('updated', 'ClubInventoryItem', $item->id,
            "Инвентарь: изменена позиция «{$item->name}»", clubId: $club->id);

        return back()->with('success', 'Позиция обновлена');
    }

    public function destroy(ClubInventoryItem $item)
    {
        $club = $this->requireClub();
        if ($item->club_id !== $club->id) abort(403);

        $name = $item->name;
        $item->delete();

        ActivityLog::log('deleted', 'ClubInventoryItem', null,
            "Инвентарь: удалена позиция «{$name}»", clubId: $club->id);

        return back()->with('success', 'Позиция удалена');
    }
}
```

- [ ] **Step 4: Добавить маршруты**

В `routes/web.php` в группе клуба, сразу перед комментарием `// Клубные карты` (строка 302), вставить:

```php
        // Инвентарь клуба
        Route::get('/inventory', [App\Http\Controllers\Club\InventoryController::class, 'index'])->name('inventory.index');
        Route::post('/inventory', [App\Http\Controllers\Club\InventoryController::class, 'store'])->name('inventory.store');
        Route::put('/inventory/{item}', [App\Http\Controllers\Club\InventoryController::class, 'update'])->name('inventory.update');
        Route::delete('/inventory/{item}', [App\Http\Controllers\Club\InventoryController::class, 'destroy'])->name('inventory.destroy');
```

Параметр маршрута назван `{item}` — он совпадает с именем аргумента `ClubInventoryItem $item`, иначе Laravel не свяжет модель.

- [ ] **Step 5: Создать заглушку вьюхи, чтобы тесты дошли до конца**

Создать `resources/views/club/inventory/index.blade.php` с временным содержимым — полноценная вёрстка в Task 4:

```blade
@extends('layouts.app')

@section('content')
    <h1>Инвентарь</h1>
    <ul>
        @foreach($items as $item)
            <li>{{ $item->name }} — {{ $item->price }}</li>
        @endforeach
    </ul>
@endsection
```

- [ ] **Step 6: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=ClubInventoryTest`
Expected: PASS, 12 тестов.

- [ ] **Step 7: Коммит**

```bash
git add app/Http/Controllers/Club/InventoryController.php routes/web.php resources/views/club/inventory/index.blade.php tests/Feature/ClubInventoryTest.php
git commit -m "feat(inventory): контроллер и маршруты раздела"
```

---

### Task 4: Страница раздела и пункт меню

**Files:**
- Modify: `resources/views/club/inventory/index.blade.php` (заглушка из Task 3 заменяется настоящей вёрсткой)
- Modify: `resources/views/layouts/app.blade.php:1001` (блок меню модератора) и `:1089` (блок меню админа)
- Test: `tests/Feature/ClubInventoryTest.php` (дополняется)

**Interfaces:**
- Consumes: маршруты и `$items` из Task 3, флаг `inventory` из Task 2
- Produces: страница `/club/inventory` и пункт меню «Инвентарь»

- [ ] **Step 1: Написать падающие тесты**

Добавить в `tests/Feature/ClubInventoryTest.php`:

```php
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
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter="menu_shows_inventory|inactive_item_is_marked"`
Expected: FAIL — заглушка не содержит ни слова «Выключена», ни пункта меню.

- [ ] **Step 3: Написать страницу раздела**

Заменить `resources/views/club/inventory/index.blade.php` целиком.

**Важно про стили.** В проекте нет общих классов вида `card-custom` или `table-custom` — каждый раздел клуба несёт собственный CSS с коротким префиксом прямо в шаблоне. У клубных карт это `cc-*` (`resources/views/club/cards/_cards_shared_css.blade.php`) и `ct-modal*` для модалки (`resources/views/club/cards/_type_modal.blade.php`). Инвентарь делает так же, с префиксом `inv-`, и берёт цвета из тех же CSS-переменных (`var(--text-primary)`, `var(--bg-card)`, `var(--border)`, `var(--accent)`), чтобы совпасть с остальным интерфейсом.

```blade
@extends('layouts.app')

@section('content')
<div class="inv-page">
    <div class="inv-head">
        <h1 class="inv-title"><i class="bi bi-box-seam"></i> Инвентарь</h1>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="flash-message flash-error">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    {{-- Добавление позиции --}}
    <form action="{{ route('club.inventory.store') }}" method="POST" class="inv-add">
        @csrf
        <input type="text" name="name" class="inv-input" placeholder="Аренда ракетки"
               value="{{ old('name') }}" required maxlength="255">
        <input type="number" name="price" class="inv-input inv-input-price" placeholder="3000"
               value="{{ old('price') }}" required min="0" step="1">
        <button type="submit" class="inv-btn inv-green">
            <i class="bi bi-plus-lg"></i> Добавить
        </button>
    </form>

    {{-- Список позиций --}}
    @if($items->isEmpty())
        <div class="inv-empty">
            Позиций пока нет. Добавьте первую — например, «Аренда ракетки» за 3000 ₸.
        </div>
    @else
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Название</th>
                    <th>Цена</th>
                    <th>Статус</th>
                    <th class="inv-actions-col"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td>{{ $item->name }}</td>
                        <td>{{ number_format((float) $item->price, 0, ',', ' ') }} ₸</td>
                        <td>
                            @if($item->is_active)
                                <span class="inv-badge inv-on">Активна</span>
                            @else
                                <span class="inv-badge inv-off">Выключена</span>
                            @endif
                        </td>
                        <td class="inv-actions">
                            <button type="button" class="inv-ic"
                                    onclick="openInventoryModal({{ $item->id }}, @js($item->name), {{ (float) $item->price }}, {{ $item->is_active ? 'true' : 'false' }})">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form action="{{ route('club.inventory.destroy', $item) }}" method="POST"
                                  onsubmit="return confirm('Удалить позицию «{{ $item->name }}»? Если она может понадобиться позже, лучше выключите её.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inv-ic inv-ic-del"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- Модалка редактирования — структура повторяет _type_modal у клубных карт --}}
<div id="inventoryModal" class="inv-modal" onclick="if(event.target===this)this.style.display='none'">
    <div class="inv-modal-card" onclick="event.stopPropagation()">
        <div class="inv-modal-head">
            <h5>Позиция инвентаря</h5>
            <button type="button" class="inv-modal-close"
                    onclick="document.getElementById('inventoryModal').style.display='none'">&#10005;</button>
        </div>
        <form id="inventoryEditForm" method="POST">
            @csrf
            @method('PUT')
            <div class="inv-modal-body">
                <label class="inv-label">Название</label>
                <input type="text" name="name" id="inventoryName" class="inv-input" required maxlength="255">

                <label class="inv-label">Цена, ₸</label>
                <input type="number" name="price" id="inventoryPrice" class="inv-input" required min="0" step="1">

                <label class="inv-check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" id="inventoryActive" value="1">
                    <span>Активна</span>
                </label>
            </div>
            <div class="inv-modal-foot">
                <button type="submit" class="inv-btn inv-green">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<style>
.inv-page{max-width:1000px}
.inv-head{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.inv-title{font-size:21px;font-weight:800;margin:0;letter-spacing:-.3px;color:var(--text-primary)}
.inv-btn{border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;padding:9px 15px;display:inline-flex;align-items:center;gap:6px}
.inv-green{background:var(--accent);color:#06210f}
.inv-green:hover{background:var(--accent-dark)}
.inv-add{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.inv-input{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:9px 12px;color:var(--text-primary);font-size:14px;flex:1 1 260px}
.inv-input-price{flex:0 1 160px}
.inv-table{width:100%;border-collapse:collapse;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.inv-table th{text-align:left;padding:11px 14px;font-size:12px;color:var(--text-muted);border-bottom:1px solid var(--border);font-weight:700}
.inv-table td{padding:12px 14px;border-bottom:1px solid var(--border);color:var(--text-primary);font-size:14px}
.inv-table tr:last-child td{border-bottom:none}
.inv-actions-col{width:120px}
.inv-actions{display:flex;gap:6px;align-items:center}
.inv-actions form{display:inline}
.inv-ic{background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary);border-radius:8px;padding:6px 9px;cursor:pointer}
.inv-ic:hover{color:var(--text-primary)}
.inv-ic-del:hover{color:#ef4444;border-color:rgba(239,68,68,.4)}
.inv-badge{font-size:11px;font-weight:800;padding:3px 8px;border-radius:7px}
.inv-on{background:rgba(34,197,94,.18);color:#4ade80}
.inv-off{background:rgba(161,161,170,.15);color:#a1a1aa}
.inv-empty{padding:26px;text-align:center;color:var(--text-muted);background:var(--bg-card);border:1px solid var(--border);border-radius:12px}
.inv-modal{display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,.7)}
.inv-modal-card{background:#111113;border:1px solid #27272a;border-radius:16px;width:460px;max-width:94vw}
.inv-modal-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #27272a}
.inv-modal-head h5{color:#fff;margin:0;font-size:17px}
.inv-modal-close{background:none;border:none;color:#a1a1aa;font-size:18px;cursor:pointer}
.inv-modal-body{padding:18px 20px;display:flex;flex-direction:column;gap:10px}
.inv-modal-foot{padding:14px 20px;border-top:1px solid #27272a;display:flex;justify-content:flex-end}
.inv-label{font-size:12px;color:var(--text-muted);font-weight:700}
.inv-check{display:flex;align-items:center;gap:8px;color:var(--text-primary);font-size:14px;cursor:pointer;margin-top:4px}
</style>

<script>
    function openInventoryModal(id, name, price, isActive) {
        const form = document.getElementById('inventoryEditForm');
        form.action = '{{ url('club/inventory') }}/' + id;
        document.getElementById('inventoryName').value = name;
        document.getElementById('inventoryPrice').value = price;
        document.getElementById('inventoryActive').checked = isActive;
        document.getElementById('inventoryModal').style.display = 'flex';
    }
</script>
@endsection
```

Классы `flash-message`, `flash-success`, `flash-error` — общие в проекте, они уже определены в стилях раздела карт; если на странице инвентаря они окажутся неоформленными, продублировать три правила из `_cards_shared_css.blade.php` в блок `<style>` выше.

- [ ] **Step 4: Добавить пункт меню в блок модератора**

В `resources/views/layouts/app.blade.php` перед блоком «Клубные карты» модератора (строка 1001, `<li class="nav-item">` с `$cardsPendingMod`) вставить:

```blade
					@if(!$modClub || $modClub->hasFeature('inventory'))
					<li class="nav-item">
						<a href="{{ route('club.inventory.index') }}" class="nav-link {{ request()->routeIs('club.inventory.*') ? 'active' : '' }}">
							<i class="bi bi-box-seam"></i>
							<span>Инвентарь</span>
						</a>
					</li>
					@endif
```

- [ ] **Step 5: Добавить пункт меню в блок админа**

В том же файле перед блоком «Клубные карты» админа (строка 1089, `<li class="nav-item">` с `$cardsPendingNav`) вставить то же самое, но с переменной `$navClub`:

```blade
					@if(!$navClub || $navClub->hasFeature('inventory'))
					<li class="nav-item">
						<a href="{{ route('club.inventory.index') }}" class="nav-link {{ request()->routeIs('club.inventory.*') ? 'active' : '' }}">
							<i class="bi bi-box-seam"></i>
							<span>Инвентарь</span>
						</a>
					</li>
					@endif
```

- [ ] **Step 6: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=ClubInventoryTest`
Expected: PASS, 14 тестов.

- [ ] **Step 7: Прогнать смежные сьюты**

Run: `php artisan test --filter="ClubCard|Club"`
Expected: результат не хуже, чем до работы — новых падений быть не должно.

- [ ] **Step 8: Коммит**

```bash
git add resources/views/club/inventory/index.blade.php resources/views/layouts/app.blade.php tests/Feature/ClubInventoryTest.php
git commit -m "feat(inventory): страница раздела и пункт меню"
```

---

## Деплой на прод

```bash
git pull
php artisan migrate --path=database/migrations/2026_08_08_000001_create_club_inventory_items_table.php
php artisan config:clear && php artisan view:clear
```

`npm run build` не нужен — собираемые ассеты не менялись. Миграция создаёт новую таблицу и существующих данных не трогает; откат — `php artisan migrate:rollback --step=1`.

После деплоя раздел «Инвентарь» появится в меню у всех клубов. Выключить его конкретному клубу можно в админке супер-админа, в настройках клуба.

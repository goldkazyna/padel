# Инвентарь в брони корта — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** В модалке брони корта, под блоком «Тренер», выбрать позиции инвентаря клуба с количеством; их стоимость складывается в «Итого» брони. Работает при создании и редактировании, в дневном и недельном расписании.

**Architecture:** Строки инвентаря хранятся в отдельной таблице `court_booking_inventory_items` со снимком названия и цены — по образцу уже работающих `court_booking_coaches`. Вся логика записи вынесена в сервис `BookingInventoryService`, контроллер только вызывает его. Разметка и JS живут в partial'ах и подключаются в обе вьюхи.

**Tech Stack:** Laravel 12, MySQL (прод) / SQLite (тесты), Blade + ванильный JS, PHPUnit.

## Global Constraints

- Спека: `docs/superpowers/specs/2026-08-08-booking-inventory-design.md`
- Все комментарии в коде и тексты интерфейса — на русском. Никогда не на английском.
- Сумма инвентаря **не входит** в `court_bookings.price` — иначе она задваивается при редактировании (поле цены корта заполняется как `price + discount`, `schedule.blade.php:1699` и `:1769`). Хранится отдельными строками, как тренеры.
- Цена позиции фиксируется снимком в момент сохранения брони. Название тоже.
- Инвентарь доступен во всех типах броней, **кроме** групповых и турнирных.
- Скидка применяется только к корту; инвентарь идёт по полной цене.
- Позиции чужого клуба, неактивные и с количеством меньше 1 отбрасываются на сервере.
- Разметка и JS живут в partial'ах `resources/views/club/courts/partials/` и подключаются через `@include`. Копировать код между `schedule.blade.php` и `schedule_week.blade.php` запрещено.
- Пользовательские данные, попадающие в JS-строку или в атрибут обработчика (названия позиций), передаются **только** через `@js(...)`. Использовать для них `@json` нельзя: Blade режет выражение по запятой, второй аргумент уходит в позицию флагов и экранирование теряется.
- Для вывода целых наборов данных в `window.__*` допустим `@json($data, JSON_UNESCAPED_UNICODE)` — ровно так уже сделано для `window.__tournaments`, флаги там передаются намеренно и осознанно.
- Прогон тестов точечный, через `--filter`. Полный сьют в этом окружении не запускается — упирается в `memory_limit` PHP, это предсуществующая проблема.
- Работа идёт в ветке `feature/booking-inventory`.

---

## File Structure

| Файл | Ответственность |
|---|---|
| `database/migrations/2026_08_08_000002_create_court_booking_inventory_items_table.php` | Создать: таблица строк инвентаря брони |
| `app/Models/CourtBookingInventoryItem.php` | Создать: модель строки, касты, связи, сумма строки |
| `app/Models/CourtBooking.php` | Изменить: связь `inventoryItems()`, метод `inventoryTotal()` |
| `app/Services/BookingInventoryService.php` | Создать: запись строк со снимком, проверка клуба и активности |
| `app/Http/Controllers/Club/CourtController.php` | Изменить: валидация, вызов сервиса, данные во вьюхи |
| `resources/views/club/courts/partials/_book_inventory.blade.php` | Создать: блок в модалке создания |
| `resources/views/club/courts/partials/_edit_inventory.blade.php` | Создать: блок в модалке редактирования |
| `resources/views/club/courts/partials/_inventory_js.blade.php` | Создать: общие JS-функции |
| `resources/views/club/courts/schedule.blade.php` | Изменить: три `@include`, строка «Инвентарь» в «Итого», слагаемое в расчёте |
| `resources/views/club/courts/schedule_week.blade.php` | Изменить: то же для недельного вида |
| `tests/Feature/BookingInventoryTest.php` | Создать: тесты сервиса и сквозные сценарии |

---

### Task 1: Таблица, модель и связи

**Files:**
- Create: `database/migrations/2026_08_08_000002_create_court_booking_inventory_items_table.php`
- Create: `app/Models/CourtBookingInventoryItem.php`
- Modify: `app/Models/CourtBooking.php` (рядом со связью `coaches()`, строка 85)
- Test: `tests/Feature/BookingInventoryTest.php`

**Interfaces:**
- Consumes: существующие `CourtBooking`, `ClubInventoryItem`
- Produces: модель `CourtBookingInventoryItem` с полями `court_booking_id`, `club_inventory_item_id`, `name`, `price`, `quantity` и аксессором `total`; связь `CourtBooking::inventoryItems()`; метод `CourtBooking::inventoryTotal(): int`

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Feature/BookingInventoryTest.php`:

```php
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
```

- [ ] **Step 2: Запустить тест и убедиться, что он падает**

Run: `php artisan test --filter=BookingInventoryTest`
Expected: FAIL — класса `CourtBookingInventoryItem` не существует.

- [ ] **Step 3: Создать миграцию**

`database/migrations/2026_08_08_000002_create_court_booking_inventory_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('court_booking_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_booking_id')->constrained('court_bookings')->cascadeOnDelete();
            // Позиция справочника. Позицию могли удалить — строка живёт дальше
            // со снимком названия и цены.
            $table->foreignId('club_inventory_item_id')->nullable()
                  ->constrained('club_inventory_items')->nullOnDelete();
            $table->string('name');                        // снимок названия
            $table->integer('price');                      // снимок цены за единицу, целые тенге
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();

            $table->index('club_inventory_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('court_booking_inventory_items');
    }
};
```

- [ ] **Step 4: Создать модель**

`app/Models/CourtBookingInventoryItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Позиция инвентаря, выданная по брони корта. Название и цена хранятся
 * снимком: справочник могли потом изменить или позицию удалить.
 */
class CourtBookingInventoryItem extends Model
{
    protected $fillable = [
        'court_booking_id',
        'club_inventory_item_id',
        'name',
        'price',
        'quantity',
    ];

    protected $casts = [
        'price' => 'integer',
        'quantity' => 'integer',
    ];

    public function booking()
    {
        return $this->belongsTo(CourtBooking::class, 'court_booking_id');
    }

    public function item()
    {
        return $this->belongsTo(ClubInventoryItem::class, 'club_inventory_item_id');
    }

    /** Стоимость строки: цена за единицу × количество. */
    public function getTotalAttribute(): int
    {
        return (int) $this->price * (int) $this->quantity;
    }
}
```

- [ ] **Step 5: Добавить связь и сумму в `CourtBooking`**

В `app/Models/CourtBooking.php` рядом со связью `coaches()` (строка 85):

```php
    /** Позиции инвентаря, выданные по этой броне. */
    public function inventoryItems()
    {
        return $this->hasMany(CourtBookingInventoryItem::class, 'court_booking_id');
    }

    /** Сумма за инвентарь по броне (в цену корта не входит). */
    public function inventoryTotal(): int
    {
        return (int) $this->inventoryItems->sum(fn ($row) => $row->total);
    }
```

- [ ] **Step 6: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=BookingInventoryTest`
Expected: PASS, 3 теста.

- [ ] **Step 7: Коммит**

```bash
git add database/migrations/2026_08_08_000002_create_court_booking_inventory_items_table.php app/Models/CourtBookingInventoryItem.php app/Models/CourtBooking.php tests/Feature/BookingInventoryTest.php
git commit -m "feat(booking-inventory): таблица и модель позиций инвентаря брони"
```

---

### Task 2: Сервис записи строк

**Files:**
- Create: `app/Services/BookingInventoryService.php`
- Test: `tests/Feature/BookingInventoryTest.php` (дополняется)

**Interfaces:**
- Consumes: `CourtBookingInventoryItem`, `CourtBooking::inventoryItems()` из Task 1
- Produces: `BookingInventoryService::sync(CourtBooking $booking, Club $club, array $rows): int`

- [ ] **Step 1: Написать падающие тесты**

Добавить в `tests/Feature/BookingInventoryTest.php` (нужен импорт `use App\Services\BookingInventoryService;`):

```php
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
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter=BookingInventoryTest`
Expected: FAIL — класса `BookingInventoryService` не существует.

- [ ] **Step 3: Написать сервис**

`app/Services/BookingInventoryService.php`:

```php
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
```

- [ ] **Step 4: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=BookingInventoryTest`
Expected: PASS, 11 тестов.

- [ ] **Step 5: Коммит**

```bash
git add app/Services/BookingInventoryService.php tests/Feature/BookingInventoryTest.php
git commit -m "feat(booking-inventory): сервис записи позиций со снимком цены"
```

---

### Task 3: Контроллер — приём и передача во вьюхи

**Files:**
- Modify: `app/Http/Controllers/Club/CourtController.php` (валидация в `book`, около строки 668; создание брони, около `:930`; валидация в `updateBooking`, около `:1130`; сохранение, около `:1370`; методы `schedule` и `scheduleWeek`)
- Test: `tests/Feature/BookingInventoryTest.php` (дополняется)

**Interfaces:**
- Consumes: `BookingInventoryService::sync()` из Task 2
- Produces: приём поля `inventory` при создании и редактировании брони; переменные вьюх `$inventoryItems` и `$bookingInventory`

- [ ] **Step 1: Написать падающие тесты**

```php
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
        ])->assertRedirect();

        $rows = $booking->fresh()->inventoryItems;
        $this->assertSame(1, $rows->count());
        $this->assertSame('Мячи', $rows->first()->name);
        $this->assertSame(4000, $booking->fresh()->inventoryTotal());
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
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter=BookingInventoryTest`
Expected: FAIL — поле `inventory` не обрабатывается, позиции во вьюхи не передаются.

- [ ] **Step 3: Принять `inventory` при создании брони**

В `CourtController::book` в массив правил валидации (рядом с `'coaches' => 'nullable|array|max:5'`) добавить:

```php
            'inventory' => 'nullable|array|max:50',
            'inventory.*.item_id' => 'required_with:inventory|integer',
            'inventory.*.quantity' => 'nullable|integer|min:1|max:99',
```

и в массив сообщений:

```php
            'inventory.*.quantity.max' => 'Слишком большое количество инвентаря (максимум :max)',
```

- [ ] **Step 4: Записывать инвентарь после создания брони**

В `book`, в цикле по датам, сразу после блока привязки к турниру (тот, что вызывает `syncForBooking`) добавить:

```php
            // Инвентарь: только для обычных броней и только при включённом модуле.
            // У групповых и турнирных цена считается сама, добавка сломала бы расчёт.
            if (!$isGroupBooking && !$isTournamentBooking && $club->hasFeature('inventory')) {
                app(\App\Services\BookingInventoryService::class)
                    ->sync($booking, $club, $validated['inventory'] ?? []);
            }
```

- [ ] **Step 5: Принять и записать `inventory` при редактировании**

В `CourtController::updateBooking` добавить те же три правила валидации и то же сообщение, что в шаге 3.

После `$booking->update($updateData);` добавить:

```php
        // Инвентарь заменяем целиком. Для групповой и турнирной брони — очищаем:
        // у них цена считается автоматически.
        if (!$isGroupBooking && !$isTournamentBooking && $club->hasFeature('inventory')) {
            app(\App\Services\BookingInventoryService::class)
                ->sync($booking->fresh(), $club, $request->input('inventory', []));
        } elseif ($isGroupBooking || $isTournamentBooking) {
            $booking->inventoryItems()->delete();
        }
```

- [ ] **Step 6: Передать позиции во вьюхи**

В `CourtController::schedule`, рядом с блоком `$bookingTournaments`, добавить:

```php
        // Позиции инвентаря для модалки брони + уже выданное по броням этого дня.
        $inventoryItems = $club->hasFeature('inventory')
            ? \App\Models\ClubInventoryItem::where('club_id', $club->id)
                ->where('is_active', true)->orderBy('name')->get()
            : collect();

        $bookingInventory = \App\Models\CourtBookingInventoryItem::whereIn(
                'court_booking_id',
                CourtBooking::whereIn('court_id', $courts->pluck('id'))
                    ->whereDate('date', $date)->pluck('id')
            )
            ->get()
            ->groupBy('court_booking_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => [
                'item_id' => $r->club_inventory_item_id,
                'name' => $r->name,
                'price' => (int) $r->price,
                'quantity' => (int) $r->quantity,
            ])->values());
```

и добавить обе переменные в `compact(...)`.

В `CourtController::scheduleWeek` — то же самое, но диапазон дат недели вместо одной даты:

```php
        $inventoryItems = $club->hasFeature('inventory')
            ? \App\Models\ClubInventoryItem::where('club_id', $club->id)
                ->where('is_active', true)->orderBy('name')->get()
            : collect();

        $weekDates = collect($weekDays)->pluck('date')->all();
        $bookingInventory = \App\Models\CourtBookingInventoryItem::whereIn(
                'court_booking_id',
                CourtBooking::whereIn('court_id', $courts->pluck('id'))
                    ->whereDate('date', '>=', min($weekDates))
                    ->whereDate('date', '<=', max($weekDates))
                    ->pluck('id')
            )
            ->get()
            ->groupBy('court_booking_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => [
                'item_id' => $r->club_inventory_item_id,
                'name' => $r->name,
                'price' => (int) $r->price,
                'quantity' => (int) $r->quantity,
            ])->values());
```

и добавить обе переменные в `compact(...)`.

- [ ] **Step 7: Временно вывести данные во вьюхи, чтобы тесты дошли до конца**

В `resources/views/club/courts/schedule.blade.php` рядом со строкой вывода `window.__tournaments` добавить:

```blade
<script>window.__inventory = @json($inventoryItems ?? [], JSON_UNESCAPED_UNICODE);</script>
```

То же самое в `resources/views/club/courts/schedule_week.blade.php`. Полноценные блоки — в Task 4.

- [ ] **Step 8: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=BookingInventoryTest`
Expected: PASS, 17 тестов.

- [ ] **Step 9: Прогнать смежные сьюты**

Run: `php artisan test --filter="CourtSchedule|TournamentCourtBooking|ClubCardBooking"`
Expected: новых падений нет. Помнить про 2 давно падающих теста `CourtScheduleTest` про `calculatePrice`.

- [ ] **Step 10: Коммит**

```bash
git add app/Http/Controllers/Club/CourtController.php resources/views/club/courts/schedule.blade.php resources/views/club/courts/schedule_week.blade.php tests/Feature/BookingInventoryTest.php
git commit -m "feat(booking-inventory): приём инвентаря в брони и данные во вьюхи"
```

---

### Task 4: Интерфейс выбора инвентаря

**Files:**
- Create: `resources/views/club/courts/partials/_book_inventory.blade.php`
- Create: `resources/views/club/courts/partials/_edit_inventory.blade.php`
- Create: `resources/views/club/courts/partials/_inventory_js.blade.php`
- Modify: `resources/views/club/courts/schedule.blade.php` (блок «Итого» около `:627-640`, блок тренеров около `:642-670`, расчёт `updateFinalPrice` `:1245`, `updateEditFinalPrice` `:1271`, открытие окна редактирования около `:1735`)
- Modify: `resources/views/club/courts/schedule_week.blade.php` (те же места)

**Interfaces:**
- Consumes: `window.__inventory` и `$bookingInventory` из Task 3
- Produces: поля формы `inventory[N][item_id]` и `inventory[N][quantity]`; функции `renderInventoryPicker`, `inventoryTotal`, `applyBookingInventory`

- [ ] **Step 1: Создать partial блока для модалки создания**

`resources/views/club/courts/partials/_book_inventory.blade.php`:

```blade
{{-- Выбор инвентаря в модалке создания брони. Подключается в дневном
     и недельном расписании. Скрывается для групповых и турнирных броней. --}}
@if(($inventoryItems ?? collect())->count())
<div class="modal-section-title js-hide-for-group">Инвентарь</div>
<div class="inv-pick js-hide-for-group" id="bookInventoryPick">
    @foreach($inventoryItems as $inv)
        <button type="button" class="inv-pick-btn" data-item-id="{{ $inv->id }}"
                onclick="addInventory('book', {{ $inv->id }}, @js($inv->name), {{ (int) $inv->price }})">
            <span class="inv-pick-name">{{ $inv->name }}</span>
            <span class="inv-pick-price">{{ number_format((int) $inv->price, 0, ',', ' ') }} ₸</span>
        </button>
    @endforeach
</div>
<div class="inv-chosen js-hide-for-group" id="bookInventoryChosen"></div>
@endif
```

- [ ] **Step 2: Создать partial блока для модалки редактирования**

`resources/views/club/courts/partials/_edit_inventory.blade.php`:

```blade
{{-- Выбор инвентаря в модалке редактирования брони. --}}
@if(($inventoryItems ?? collect())->count())
<div class="modal-section-title js-edit-hide-for-group">Инвентарь</div>
<div class="inv-pick js-edit-hide-for-group" id="editInventoryPick">
    @foreach($inventoryItems as $inv)
        <button type="button" class="inv-pick-btn" data-item-id="{{ $inv->id }}"
                onclick="addInventory('edit', {{ $inv->id }}, @js($inv->name), {{ (int) $inv->price }})">
            <span class="inv-pick-name">{{ $inv->name }}</span>
            <span class="inv-pick-price">{{ number_format((int) $inv->price, 0, ',', ' ') }} ₸</span>
        </button>
    @endforeach
</div>
<div class="inv-chosen js-edit-hide-for-group" id="editInventoryChosen"></div>
@endif
```

- [ ] **Step 3: Создать partial с JS**

`resources/views/club/courts/partials/_inventory_js.blade.php`:

```blade
{{-- Общий JS выбора инвентаря: добавление, количество, сумма.
     Подключается в дневном и недельном расписании. --}}
<script>
    // Выбранное по каждой модалке: { 'book': {itemId: {name, price, qty}}, 'edit': {...} }
    window.__invChosen = { book: {}, edit: {} };

    function invFmt(n) { return new Intl.NumberFormat('ru-RU').format(n); }

    // Добавить позицию или увеличить её количество.
    function addInventory(mode, itemId, name, price) {
        const store = window.__invChosen[mode];
        if (store[itemId]) {
            store[itemId].qty += 1;
        } else {
            store[itemId] = { name: name, price: price, qty: 1 };
        }
        renderInventoryPicker(mode);
    }

    function changeInventoryQty(mode, itemId, delta) {
        const store = window.__invChosen[mode];
        if (!store[itemId]) return;
        store[itemId].qty += delta;
        if (store[itemId].qty <= 0) delete store[itemId];
        renderInventoryPicker(mode);
    }

    function removeInventory(mode, itemId) {
        delete window.__invChosen[mode][itemId];
        renderInventoryPicker(mode);
    }

    // Сумма за инвентарь в выбранной модалке.
    function inventoryTotal(mode) {
        let sum = 0;
        Object.values(window.__invChosen[mode]).forEach(r => { sum += r.price * r.qty; });
        return sum;
    }

    // Перерисовать список выбранного и скрытые поля формы.
    function renderInventoryPicker(mode) {
        const box = document.getElementById(mode + 'InventoryChosen');
        if (!box) return;
        const store = window.__invChosen[mode];
        box.innerHTML = '';

        Object.keys(store).forEach((itemId, i) => {
            const row = store[itemId];
            const el = document.createElement('div');
            el.className = 'inv-row';

            const nameEl = document.createElement('span');
            nameEl.className = 'inv-row-name';
            nameEl.textContent = row.name; // textContent — название пришло от пользователя
            el.appendChild(nameEl);

            const qty = document.createElement('span');
            qty.className = 'inv-qty';
            qty.innerHTML =
                '<button type="button" class="inv-qty-btn" onclick="changeInventoryQty(\'' + mode + '\',' + itemId + ',-1)">−</button>' +
                '<span class="inv-qty-num">' + row.qty + '</span>' +
                '<button type="button" class="inv-qty-btn" onclick="changeInventoryQty(\'' + mode + '\',' + itemId + ',1)">+</button>';
            el.appendChild(qty);

            const sum = document.createElement('span');
            sum.className = 'inv-row-sum';
            sum.textContent = invFmt(row.price * row.qty) + ' ₸';
            el.appendChild(sum);

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'inv-row-del';
            del.innerHTML = '&#10005;';
            del.onclick = () => removeInventory(mode, itemId);
            el.appendChild(del);

            // Скрытые поля формы
            const fId = document.createElement('input');
            fId.type = 'hidden';
            fId.name = 'inventory[' + i + '][item_id]';
            fId.value = itemId;
            el.appendChild(fId);

            const fQty = document.createElement('input');
            fQty.type = 'hidden';
            fQty.name = 'inventory[' + i + '][quantity]';
            fQty.value = row.qty;
            el.appendChild(fQty);

            box.appendChild(el);
        });

        // Пересчёт «Итого» — функции определены во вьюхе.
        if (mode === 'book' && typeof updateFinalPrice === 'function') updateFinalPrice();
        if (mode === 'edit' && typeof updateEditFinalPrice === 'function') updateEditFinalPrice();
    }

    // Подставить инвентарь открытой брони в модалку редактирования.
    function applyBookingInventory(bookingId) {
        window.__invChosen.edit = {};
        const rows = (window.__bookingInventory && window.__bookingInventory[bookingId]) || [];
        rows.forEach(r => {
            if (!r.item_id) return; // позиция удалена из справочника — не подставляем
            window.__invChosen.edit[r.item_id] = { name: r.name, price: r.price, qty: r.quantity };
        });
        renderInventoryPicker('edit');
    }

    // Сбросить выбор в модалке создания.
    function resetBookInventory() {
        window.__invChosen.book = {};
        renderInventoryPicker('book');
    }
</script>

<style>
.inv-pick{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.inv-pick-btn{display:flex;flex-direction:column;align-items:flex-start;gap:2px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:8px 12px;cursor:pointer;color:var(--text-primary)}
.inv-pick-btn:hover{background:var(--bg-card-hover);border-color:var(--accent)}
.inv-pick-name{font-size:13px;font-weight:700}
.inv-pick-price{font-size:12px;color:var(--accent);font-weight:700}
.inv-chosen{display:flex;flex-direction:column;gap:6px}
.inv-row{display:flex;align-items:center;gap:10px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:8px 12px}
.inv-row-name{flex:1;font-size:13px}
.inv-qty{display:flex;align-items:center;gap:8px}
.inv-qty-btn{background:transparent;border:1px solid var(--border);color:var(--text-secondary);border-radius:6px;width:24px;height:24px;cursor:pointer;line-height:1}
.inv-qty-btn:hover{color:var(--text-primary)}
.inv-qty-num{min-width:18px;text-align:center;font-weight:700;font-size:13px}
.inv-row-sum{min-width:90px;text-align:right;font-weight:700;font-size:13px;color:var(--accent)}
.inv-row-del{background:transparent;border:none;color:var(--text-muted);cursor:pointer;font-size:13px}
.inv-row-del:hover{color:#ef4444}
</style>
```

- [ ] **Step 4: Подключить partial'ы в дневную вьюху**

В `resources/views/club/courts/schedule.blade.php`:

- сразу после блока тренеров в модалке создания (после `<div id="bookSelectedCoaches" ...></div>`, около `:653`) вставить `@include('club.courts.partials._book_inventory')`;
- сразу после блока тренеров в модалке редактирования (около `:925`, аналогичное место) вставить `@include('club.courts.partials._edit_inventory')`;
- рядом со строкой `window.__inventory` из Task 3 добавить вывод карты выданного и подключить JS:

```blade
<script>window.__bookingInventory = @json($bookingInventory ?? [], JSON_UNESCAPED_UNICODE);</script>
@include('club.courts.partials._inventory_js')
```

- [ ] **Step 5: Добавить строку «Инвентарь» в блок «Итого»**

В обеих модалках дневной вьюхи, после строки тренера (`bookCoachTotalRow` около `:632`, `editCoachTotalRow` около `:906`), добавить по образцу:

```blade
                            <div class="total-row" id="bookInventoryTotalRow" style="display:none;">
                                <span class="total-sub-label">Инвентарь</span>
                                <span class="total-sub-value" id="bookInventoryTotal"></span>
                            </div>
```

и такую же с префиксом `edit` во второй модалке.

- [ ] **Step 6: Учесть инвентарь в расчёте «Итого»**

В `updateFinalPrice()` (`:1245`) заменить последние строки так, чтобы сумма включала инвентарь:

```javascript
        const invTotal = (typeof inventoryTotal === 'function') ? inventoryTotal('book') : 0;
        const invRow = document.getElementById('bookInventoryTotalRow');
        if (invRow) {
            if (invTotal > 0) {
                document.getElementById('bookInventoryTotal').innerHTML = formatPrice(invTotal) + ' &#8376;';
                invRow.style.display = '';
            } else {
                invRow.style.display = 'none';
            }
        }
        document.getElementById('bookTotalPrice').innerHTML = formatPrice(courtPrice + coachTotal + invTotal) + ' &#8376;';
```

В `updateEditFinalPrice()` (`:1271`) — то же самое с префиксом `edit` и `inventoryTotal('edit')`.

- [ ] **Step 7: Подставлять инвентарь при открытии брони и сбрасывать при создании**

В функции открытия окна редактирования, рядом с подстановкой турнира (около `:1740`), добавить:

```javascript
        if (typeof applyBookingInventory === 'function') applyBookingInventory(data.id);
```

В `resetBookingTypeSelection()` (в `partials/_tournament_js.blade.php`) в конец добавить:

```javascript
        if (typeof resetBookInventory === 'function') resetBookInventory();
```

- [ ] **Step 8: Повторить подключение в недельной вьюхе**

В `resources/views/club/courts/schedule_week.blade.php` сделать то же, что в шагах 4-7: три `@include`, строки «Инвентарь» в обоих блоках «Итого», слагаемое в обеих функциях расчёта, вызов `applyBookingInventory(data.id)` при открытии брони. Разметку и JS не копировать — подключаются те же partial'ы.

Найти якоря:

```bash
grep -n "bookSelectedCoaches\|editSelectedCoaches\|CoachTotalRow\|function updateFinalPrice\|function updateEditFinalPrice\|__tournaments" resources/views/club/courts/schedule_week.blade.php
```

- [ ] **Step 9: Запустить тесты**

Run: `php artisan test --filter=BookingInventoryTest`
Expected: PASS, 17 тестов.

- [ ] **Step 10: Прогнать смежные сьюты**

Run: `php artisan test --filter="CourtSchedule|TournamentCourtBooking|ClubInventory|ClubCardBooking"`
Expected: новых падений нет.

- [ ] **Step 11: Коммит**

```bash
git add resources/views/club/courts/partials/_book_inventory.blade.php resources/views/club/courts/partials/_edit_inventory.blade.php resources/views/club/courts/partials/_inventory_js.blade.php resources/views/club/courts/schedule.blade.php resources/views/club/courts/schedule_week.blade.php
git commit -m "feat(booking-inventory): выбор инвентаря в модалках брони"
```

---

## Деплой на прод

```bash
git pull
php artisan migrate --path=database/migrations/2026_08_08_000002_create_court_booking_inventory_items_table.php
php artisan view:clear && php artisan config:clear
```

`npm run build` не нужен — собираемые ассеты не менялись. Миграция создаёт новую таблицу, существующие данные не трогает; откат — `php artisan migrate:rollback --step=1`.

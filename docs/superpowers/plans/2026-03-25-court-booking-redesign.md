# Редизайн бронирования кортов — План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Переделать систему бронирования кортов: виртуальные слоты вместо ручной генерации, дневной вид расписания со всеми кортами, гибкие ценовые интервалы.

**Architecture:** Слоты не хранятся в БД — вычисляются на лету из настроек корта (open_time, close_time, slot_duration). В БД хранятся только бронирования (court_bookings) и блокировки (court_blocks). Ценообразование — через таблицу court_price_ranges с гибкими временными интервалами.

**Tech Stack:** Laravel 12, Blade + Alpine.js, SQLite, PHPUnit

**Spec:** `docs/superpowers/specs/2026-03-25-court-booking-redesign.md`
**Mockups:** `docs/superpowers/specs/mockup-schedule.html`, `docs/superpowers/specs/mockup-courts-settings.html`

---

## File Structure

### Создаём:
- `app/Models/CourtPriceRange.php` — модель ценовых интервалов
- `app/Models/CourtBlock.php` — модель блокировок
- `app/Services/CourtScheduleService.php` — логика построения расписания, расчёт цен, валидация
- `database/migrations/2026_03_25_000001_add_schedule_fields_to_courts_table.php`
- `database/migrations/2026_03_25_000002_create_court_price_ranges_table.php`
- `database/migrations/2026_03_25_000003_create_court_blocks_table.php`
- `database/migrations/2026_03_25_000004_rebuild_court_bookings_table.php`
- `database/migrations/2026_03_25_000005_drop_court_slots_table.php`
- `tests/Feature/CourtScheduleTest.php` — тесты расписания, бронирования, блокировки

### Модифицируем:
- `app/Models/Court.php` — новые поля, связи с price_ranges, blocks, bookings
- `app/Models/CourtBooking.php` — новая структура (court_id, date, start_time, end_time, client_name, client_phone, booked_by, price)
- `app/Http/Controllers/Club/CourtController.php` — полностью переписываем
- `routes/web.php` — новые роуты вместо старых
- `resources/views/club/courts/index.blade.php` — настройки кортов (карточки + модалки)
- `resources/views/club/courts/schedule.blade.php` — дневной вид расписания

### Удаляем:
- `app/Models/CourtSlot.php`
- `resources/views/club/courts/slots.blade.php`

---

## Task 1: Миграции

**Files:**
- Create: `database/migrations/2026_03_25_000001_add_schedule_fields_to_courts_table.php`
- Create: `database/migrations/2026_03_25_000002_create_court_price_ranges_table.php`
- Create: `database/migrations/2026_03_25_000003_create_court_blocks_table.php`
- Create: `database/migrations/2026_03_25_000004_rebuild_court_bookings_table.php`
- Create: `database/migrations/2026_03_25_000005_drop_court_slots_table.php`

- [ ] **Step 1: Создать миграцию — добавить поля в courts**

```php
// database/migrations/2026_03_25_000001_add_schedule_fields_to_courts_table.php
Schema::table('courts', function (Blueprint $table) {
    $table->time('open_time')->default('08:00:00')->after('sort_order');
    $table->time('close_time')->default('22:00:00')->after('open_time');
    $table->unsignedInteger('slot_duration')->default(60)->after('close_time');
});
```

- [ ] **Step 2: Создать миграцию — таблица court_price_ranges**

```php
// database/migrations/2026_03_25_000002_create_court_price_ranges_table.php
Schema::create('court_price_ranges', function (Blueprint $table) {
    $table->id();
    $table->foreignId('court_id')->constrained()->cascadeOnDelete();
    $table->time('time_from');
    $table->time('time_to');
    $table->decimal('price', 10, 2);
    $table->timestamps();
});
```

- [ ] **Step 3: Создать миграцию — таблица court_blocks**

```php
// database/migrations/2026_03_25_000003_create_court_blocks_table.php
Schema::create('court_blocks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('court_id')->constrained()->cascadeOnDelete();
    $table->date('date');
    $table->time('start_time');
    $table->time('end_time');
    $table->timestamps();
});
```

- [ ] **Step 4: Создать миграцию — пересоздать court_bookings**

```php
// database/migrations/2026_03_25_000004_rebuild_court_bookings_table.php
// up():
Schema::dropIfExists('court_bookings');
Schema::create('court_bookings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('court_id')->constrained()->cascadeOnDelete();
    $table->date('date');
    $table->time('start_time');
    $table->time('end_time');
    $table->string('client_name');
    $table->string('client_phone')->nullable();
    $table->enum('status', ['confirmed', 'cancelled'])->default('confirmed');
    $table->timestamp('cancelled_at')->nullable();
    $table->foreignId('booked_by')->constrained('users')->cascadeOnDelete();
    $table->decimal('price', 10, 2);
    $table->timestamps();
});
```

- [ ] **Step 5: Создать миграцию — удалить court_slots**

```php
// database/migrations/2026_03_25_000005_drop_court_slots_table.php
Schema::dropIfExists('court_slots');
```

- [ ] **Step 6: Запустить миграции**

Run: `php artisan migrate`
Expected: все миграции проходят без ошибок

- [ ] **Step 7: Коммит**

```bash
git add database/migrations/2026_03_25_*.php
git commit -m "feat(courts): миграции для редизайна бронирования кортов"
```

---

## Task 2: Модели

**Files:**
- Modify: `app/Models/Court.php`
- Create: `app/Models/CourtPriceRange.php`
- Create: `app/Models/CourtBlock.php`
- Modify: `app/Models/CourtBooking.php`
- Delete: `app/Models/CourtSlot.php`

- [ ] **Step 1: Обновить модель Court**

```php
// app/Models/Court.php
protected $fillable = [
    'club_id', 'name', 'description', 'is_active', 'sort_order',
    'open_time', 'close_time', 'slot_duration',
];

protected $casts = [
    'is_active' => 'boolean',
    'slot_duration' => 'integer',
];

public function club() { return $this->belongsTo(Club::class); }
public function priceRanges() { return $this->hasMany(CourtPriceRange::class)->orderBy('time_from'); }
public function bookings() { return $this->hasMany(CourtBooking::class); }
public function blocks() { return $this->hasMany(CourtBlock::class); }
public function scopeActive($query) { return $query->where('is_active', true); }

// Убрать связь slots()
```

- [ ] **Step 2: Создать модель CourtPriceRange**

```php
// app/Models/CourtPriceRange.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourtPriceRange extends Model
{
    protected $fillable = ['court_id', 'time_from', 'time_to', 'price'];

    protected $casts = ['price' => 'decimal:2'];

    public function court() { return $this->belongsTo(Court::class); }
}
```

- [ ] **Step 3: Создать модель CourtBlock**

```php
// app/Models/CourtBlock.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourtBlock extends Model
{
    protected $fillable = ['court_id', 'date', 'start_time', 'end_time'];

    protected $casts = ['date' => 'date'];

    public function court() { return $this->belongsTo(Court::class); }
}
```

- [ ] **Step 4: Переписать модель CourtBooking**

```php
// app/Models/CourtBooking.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourtBooking extends Model
{
    protected $fillable = [
        'court_id', 'date', 'start_time', 'end_time',
        'client_name', 'client_phone', 'status',
        'cancelled_at', 'booked_by', 'price',
    ];

    protected $casts = [
        'date' => 'date',
        'cancelled_at' => 'datetime',
        'price' => 'decimal:2',
    ];

    public function court() { return $this->belongsTo(Court::class); }
    public function bookedByUser() { return $this->belongsTo(User::class, 'booked_by'); }
}
```

- [ ] **Step 5: Удалить CourtSlot**

Удалить файл `app/Models/CourtSlot.php`.

- [ ] **Step 6: Коммит**

```bash
git add app/Models/Court.php app/Models/CourtPriceRange.php app/Models/CourtBlock.php app/Models/CourtBooking.php
git rm app/Models/CourtSlot.php
git commit -m "feat(courts): модели для виртуальных слотов"
```

---

## Task 3: CourtScheduleService

**Files:**
- Create: `app/Services/CourtScheduleService.php`
- Create: `tests/Feature/CourtScheduleTest.php`

- [ ] **Step 1: Написать тесты**

```php
// tests/Feature/CourtScheduleTest.php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Court;
use App\Models\Club;
use App\Models\User;
use App\Models\CourtPriceRange;
use App\Models\CourtBooking;
use App\Models\CourtBlock;
use App\Services\CourtScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CourtScheduleTest extends TestCase
{
    use RefreshDatabase;

    private CourtScheduleService $service;
    private Court $court;
    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CourtScheduleService();

        $this->admin = User::factory()->create();
        $this->club = Club::factory()->create();
        $this->court = Court::create([
            'club_id' => $this->club->id,
            'name' => 'Корт 1',
            'open_time' => '08:00:00',
            'close_time' => '12:00:00',
            'slot_duration' => 60,
        ]);

        // 08:00-10:00 = 3000, 10:00-12:00 = 5000
        CourtPriceRange::create(['court_id' => $this->court->id, 'time_from' => '08:00', 'time_to' => '10:00', 'price' => 3000]);
        CourtPriceRange::create(['court_id' => $this->court->id, 'time_from' => '10:00', 'time_to' => '12:00', 'price' => 5000]);
    }

    public function test_generates_time_slots(): void
    {
        $slots = $this->service->generateTimeSlots($this->court);
        $this->assertCount(4, $slots); // 08:00, 09:00, 10:00, 11:00
        $this->assertEquals('08:00', $slots[0]['time']);
        $this->assertEquals('11:00', $slots[3]['time']);
    }

    public function test_slot_prices_from_ranges(): void
    {
        $slots = $this->service->generateTimeSlots($this->court);
        $this->assertEquals(3000, $slots[0]['price']); // 08:00
        $this->assertEquals(3000, $slots[1]['price']); // 09:00
        $this->assertEquals(5000, $slots[2]['price']); // 10:00
        $this->assertEquals(5000, $slots[3]['price']); // 11:00
    }

    public function test_build_schedule_marks_bookings(): void
    {
        $date = '2026-04-01';
        CourtBooking::create([
            'court_id' => $this->court->id,
            'date' => $date,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'client_name' => 'Тест',
            'status' => 'confirmed',
            'booked_by' => $this->admin->id,
            'price' => 3000,
        ]);

        $schedule = $this->service->buildSchedule($this->court, $date);
        $this->assertEquals('free', $schedule['08:00']['status']);
        $this->assertEquals('booked', $schedule['09:00']['status']);
        $this->assertEquals('Тест', $schedule['09:00']['booking']->client_name);
        $this->assertEquals('free', $schedule['10:00']['status']);
    }

    public function test_build_schedule_marks_blocks(): void
    {
        $date = '2026-04-01';
        CourtBlock::create([
            'court_id' => $this->court->id,
            'date' => $date,
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]);

        $schedule = $this->service->buildSchedule($this->court, $date);
        $this->assertEquals('blocked', $schedule['08:00']['status']);
        $this->assertEquals('free', $schedule['09:00']['status']);
    }

    public function test_calculate_price_single_hour(): void
    {
        $price = $this->service->calculatePrice($this->court, '08:00', '09:00');
        $this->assertEquals(3000, $price);
    }

    public function test_calculate_price_multi_hour_cross_range(): void
    {
        // 09:00-11:00 = 1h*3000 + 1h*5000 = 8000
        $price = $this->service->calculatePrice($this->court, '09:00', '11:00');
        $this->assertEquals(8000, $price);
    }

    public function test_max_consecutive_free_slots(): void
    {
        $date = '2026-04-01';
        CourtBooking::create([
            'court_id' => $this->court->id,
            'date' => $date,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Тест',
            'status' => 'confirmed',
            'booked_by' => $this->admin->id,
            'price' => 5000,
        ]);

        $max = $this->service->maxConsecutiveFreeSlots($this->court, $date, '08:00');
        $this->assertEquals(2, $max); // 08:00 и 09:00 свободны, 10:00 занят
    }

    public function test_can_book_returns_true_when_free(): void
    {
        $this->assertTrue($this->service->canBook($this->court, '2026-04-01', '08:00', '10:00'));
    }

    public function test_can_book_returns_false_when_overlap_booking(): void
    {
        CourtBooking::create([
            'court_id' => $this->court->id,
            'date' => '2026-04-01',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'client_name' => 'Тест',
            'status' => 'confirmed',
            'booked_by' => $this->admin->id,
            'price' => 3000,
        ]);

        $this->assertFalse($this->service->canBook($this->court, '2026-04-01', '08:00', '10:00'));
    }

    public function test_can_book_returns_false_when_overlap_block(): void
    {
        CourtBlock::create([
            'court_id' => $this->court->id,
            'date' => '2026-04-01',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);

        $this->assertFalse($this->service->canBook($this->court, '2026-04-01', '08:00', '10:00'));
    }

    public function test_validate_price_ranges_ok(): void
    {
        $ranges = [
            ['time_from' => '08:00', 'time_to' => '12:00', 'price' => 5000],
        ];
        $errors = $this->service->validatePriceRanges($ranges, '08:00', '12:00');
        $this->assertEmpty($errors);
    }

    public function test_validate_price_ranges_gap(): void
    {
        $ranges = [
            ['time_from' => '08:00', 'time_to' => '10:00', 'price' => 3000],
            // gap: 10:00-11:00
            ['time_from' => '11:00', 'time_to' => '12:00', 'price' => 5000],
        ];
        $errors = $this->service->validatePriceRanges($ranges, '08:00', '12:00');
        $this->assertNotEmpty($errors);
    }

    public function test_validate_price_ranges_overlap(): void
    {
        $ranges = [
            ['time_from' => '08:00', 'time_to' => '11:00', 'price' => 3000],
            ['time_from' => '10:00', 'time_to' => '12:00', 'price' => 5000],
        ];
        $errors = $this->service->validatePriceRanges($ranges, '08:00', '12:00');
        $this->assertNotEmpty($errors);
    }
}
```

- [ ] **Step 2: Запустить тесты — убедиться что падают**

Run: `php artisan test tests/Feature/CourtScheduleTest.php`
Expected: FAIL — CourtScheduleService не существует

- [ ] **Step 3: Реализовать CourtScheduleService**

```php
// app/Services/CourtScheduleService.php
namespace App\Services;

use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\CourtBlock;
use Carbon\Carbon;

class CourtScheduleService
{
    /**
     * Генерирует сетку временных слотов из настроек корта.
     * Возвращает массив ['time' => '08:00', 'price' => 3000]
     */
    public function generateTimeSlots(Court $court): array
    {
        $slots = [];
        $start = Carbon::parse($court->open_time);
        $end = Carbon::parse($court->close_time);
        $duration = $court->slot_duration;

        $ranges = $court->priceRanges->sortBy('time_from');

        while ($start->copy()->addMinutes($duration)->lte($end)) {
            $timeStr = $start->format('H:i');
            $price = $this->getPriceForTime($ranges, $timeStr);

            $slots[] = [
                'time' => $timeStr,
                'price' => $price,
            ];

            $start->addMinutes($duration);
        }

        return $slots;
    }

    /**
     * Строит расписание корта на дату.
     * Возвращает ['08:00' => ['status' => 'free|booked|blocked', 'price' => 3000, 'booking' => null|CourtBooking]]
     */
    public function buildSchedule(Court $court, string $date): array
    {
        $timeSlots = $this->generateTimeSlots($court);

        $bookings = CourtBooking::where('court_id', $court->id)
            ->where('date', $date)
            ->where('status', 'confirmed')
            ->get();

        $blocks = CourtBlock::where('court_id', $court->id)
            ->where('date', $date)
            ->get();

        $schedule = [];

        foreach ($timeSlots as $slot) {
            $time = $slot['time'];
            $slotStart = Carbon::parse($time);
            $slotEnd = $slotStart->copy()->addMinutes($court->slot_duration);

            // Проверка бронирований
            $booking = $bookings->first(function ($b) use ($slotStart, $slotEnd) {
                $bStart = Carbon::parse($b->start_time);
                $bEnd = Carbon::parse($b->end_time);
                return $slotStart->gte($bStart) && $slotStart->lt($bEnd);
            });

            if ($booking) {
                $schedule[$time] = [
                    'status' => 'booked',
                    'price' => $slot['price'],
                    'booking' => $booking,
                ];
                continue;
            }

            // Проверка блокировок
            $block = $blocks->first(function ($bl) use ($slotStart, $slotEnd) {
                $blStart = Carbon::parse($bl->start_time);
                $blEnd = Carbon::parse($bl->end_time);
                return $slotStart->gte($blStart) && $slotStart->lt($blEnd);
            });

            if ($block) {
                $schedule[$time] = [
                    'status' => 'blocked',
                    'price' => $slot['price'],
                    'booking' => null,
                ];
                continue;
            }

            $schedule[$time] = [
                'status' => 'free',
                'price' => $slot['price'],
                'booking' => null,
            ];
        }

        return $schedule;
    }

    /**
     * Считает цену бронирования по ценовым интервалам.
     */
    public function calculatePrice(Court $court, string $startTime, string $endTime): float
    {
        $ranges = $court->priceRanges->sortBy('time_from');
        $total = 0;
        $current = Carbon::parse($startTime);
        $end = Carbon::parse($endTime);
        $duration = $court->slot_duration;

        while ($current->lt($end)) {
            $total += $this->getPriceForTime($ranges, $current->format('H:i'));
            $current->addMinutes($duration);
        }

        return $total;
    }

    /**
     * Максимальное количество свободных слотов подряд начиная с указанного времени.
     */
    public function maxConsecutiveFreeSlots(Court $court, string $date, string $fromTime): int
    {
        $schedule = $this->buildSchedule($court, $date);
        $count = 0;
        $started = false;

        foreach ($schedule as $time => $slot) {
            if (!$started) {
                if ($time === $fromTime) {
                    $started = true;
                } else {
                    continue;
                }
            }

            if ($started) {
                if ($slot['status'] === 'free') {
                    $count++;
                } else {
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Можно ли забронировать диапазон.
     */
    public function canBook(Court $court, string $date, string $startTime, string $endTime): bool
    {
        // Проверка пересечения с бронированиями
        $hasBooking = CourtBooking::where('court_id', $court->id)
            ->where('date', $date)
            ->where('status', 'confirmed')
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->exists();

        if ($hasBooking) return false;

        // Проверка пересечения с блокировками
        $hasBlock = CourtBlock::where('court_id', $court->id)
            ->where('date', $date)
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->exists();

        return !$hasBlock;
    }

    /**
     * Валидация ценовых интервалов: покрытие, пересечения.
     */
    public function validatePriceRanges(array $ranges, string $openTime, string $closeTime): array
    {
        $errors = [];

        if (empty($ranges)) {
            return ['Необходимо указать хотя бы один ценовой интервал'];
        }

        // Нормализация всех времён к формату H:i
        $openTime = Carbon::parse($openTime)->format('H:i');
        $closeTime = Carbon::parse($closeTime)->format('H:i');
        foreach ($ranges as &$r) {
            $r['time_from'] = Carbon::parse($r['time_from'])->format('H:i');
            $r['time_to'] = Carbon::parse($r['time_to'])->format('H:i');
        }
        unset($r);

        // Сортировка
        usort($ranges, fn($a, $b) => strcmp($a['time_from'], $b['time_from']));

        // Проверка пересечений
        for ($i = 0; $i < count($ranges) - 1; $i++) {
            if ($ranges[$i]['time_to'] > $ranges[$i + 1]['time_from']) {
                $errors[] = "Интервалы пересекаются: {$ranges[$i]['time_from']}-{$ranges[$i]['time_to']} и {$ranges[$i+1]['time_from']}-{$ranges[$i+1]['time_to']}";
            }
        }

        // Проверка покрытия
        if ($ranges[0]['time_from'] !== $openTime) {
            $errors[] = "Не покрыто время: {$openTime} — {$ranges[0]['time_from']}";
        }

        $lastEnd = $ranges[0]['time_to'];
        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i]['time_from'] !== $lastEnd) {
                $errors[] = "Не покрыто время: {$lastEnd} — {$ranges[$i]['time_from']}";
            }
            $lastEnd = $ranges[$i]['time_to'];
        }

        if ($lastEnd !== $closeTime) {
            $errors[] = "Не покрыто время: {$lastEnd} — {$closeTime}";
        }

        return $errors;
    }

    /**
     * Получить цену для конкретного времени из интервалов.
     */
    private function getPriceForTime($ranges, string $time): float
    {
        foreach ($ranges as $range) {
            $from = is_string($range['time_from']) ? $range['time_from'] : $range->time_from;
            $to = is_string($range['time_to']) ? $range['time_to'] : $range->time_to;
            $price = is_array($range) ? $range['price'] : $range->price;

            // Формат H:i для сравнения
            $from = Carbon::parse($from)->format('H:i');
            $to = Carbon::parse($to)->format('H:i');

            if ($time >= $from && $time < $to) {
                return (float) $price;
            }
        }

        return 0;
    }
}
```

- [ ] **Step 4: Запустить тесты**

Run: `php artisan test tests/Feature/CourtScheduleTest.php`
Expected: все тесты проходят

- [ ] **Step 5: Коммит**

```bash
git add app/Services/CourtScheduleService.php tests/Feature/CourtScheduleTest.php
git commit -m "feat(courts): CourtScheduleService с виртуальными слотами и тестами"
```

---

## Task 4: Контроллер и роуты

**Files:**
- Modify: `app/Http/Controllers/Club/CourtController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Обновить роуты**

В `routes/web.php` **удалить строки 151-159** (весь блок кортов от `Route::get('/courts/schedule'...)` до `Route::post('/courts/slots/{slot}/toggle-block'...)`), и вставить на их место:

```php
// Корты — расписание (главный экран)
Route::get('/courts/schedule', [CourtController::class, 'schedule'])->name('courts.schedule');

// Корты — CRUD + настройки
Route::resource('courts', CourtController::class)->except(['create', 'edit', 'show']);
Route::post('/courts/{court}/toggle-active', [CourtController::class, 'toggleActive'])->name('courts.toggleActive');
Route::post('/courts/{court}/price-ranges', [CourtController::class, 'updatePriceRanges'])->name('courts.updatePriceRanges');

// Бронирования
Route::post('/courts/{court}/book', [CourtController::class, 'book'])->name('courts.book');
Route::post('/courts/bookings/{booking}/cancel', [CourtController::class, 'cancelBooking'])->name('courts.cancelBooking');

// Блокировки
Route::post('/courts/{court}/block', [CourtController::class, 'blockSlot'])->name('courts.blockSlot');
Route::delete('/courts/blocks/{block}', [CourtController::class, 'unblock'])->name('courts.unblock');
```

- [ ] **Step 2: Переписать CourtController**

```php
// app/Http/Controllers/Club/CourtController.php
namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtPriceRange;
use App\Models\CourtBooking;
use App\Models\CourtBlock;
use App\Services\CourtScheduleService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CourtController extends Controller
{
    private CourtScheduleService $scheduleService;

    public function __construct(CourtScheduleService $scheduleService)
    {
        $this->scheduleService = $scheduleService;
    }

    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    // === Расписание (главный экран) ===

    public function schedule(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $courts = Court::where('club_id', $club->id)->active()->orderBy('sort_order')->orderBy('name')->get();
        if ($courts->isEmpty()) return redirect()->route('club.courts.index')->with('error', 'Нет активных кортов. Добавьте корт в настройках.');

        $date = $request->get('date', now()->format('Y-m-d'));

        $schedules = [];
        foreach ($courts as $court) {
            $schedules[$court->id] = $this->scheduleService->buildSchedule($court, $date);
        }

        // Все уникальные временные слоты (объединение всех кортов)
        $allTimes = collect();
        foreach ($courts as $court) {
            $slots = $this->scheduleService->generateTimeSlots($court);
            foreach ($slots as $slot) {
                $allTimes->push($slot['time']);
            }
        }
        $timeSlots = $allTimes->unique()->sort()->values();

        return view('club.courts.schedule', compact('courts', 'schedules', 'timeSlots', 'date'));
    }

    // === CRUD кортов ===

    public function index()
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $courts = Court::where('club_id', $club->id)
            ->with('priceRanges')
            ->orderBy('sort_order')->orderBy('name')->get();

        return view('club.courts.index', compact('courts'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'open_time' => 'required|date_format:H:i',
            'close_time' => 'required|date_format:H:i|after:open_time',
            'price_ranges' => 'required|array|min:1',
            'price_ranges.*.time_from' => 'required|date_format:H:i',
            'price_ranges.*.time_to' => 'required|date_format:H:i',
            'price_ranges.*.price' => 'required|numeric|min:0',
        ]);

        // Валидация ценовых интервалов
        $errors = $this->scheduleService->validatePriceRanges(
            $validated['price_ranges'], $validated['open_time'], $validated['close_time']
        );
        if (!empty($errors)) {
            return back()->with('error', implode('. ', $errors))->withInput();
        }

        $maxSort = Court::where('club_id', $club->id)->max('sort_order') ?? 0;

        $court = Court::create([
            'club_id' => $club->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'open_time' => $validated['open_time'],
            'close_time' => $validated['close_time'],
            'sort_order' => $maxSort + 1,
        ]);

        foreach ($validated['price_ranges'] as $range) {
            CourtPriceRange::create([
                'court_id' => $court->id,
                'time_from' => $range['time_from'],
                'time_to' => $range['time_to'],
                'price' => $range['price'],
            ]);
        }

        return back()->with('success', 'Корт добавлен!');
    }

    public function update(Request $request, Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'open_time' => 'required|date_format:H:i',
            'close_time' => 'required|date_format:H:i|after:open_time',
            'price_ranges' => 'required|array|min:1',
            'price_ranges.*.time_from' => 'required|date_format:H:i',
            'price_ranges.*.time_to' => 'required|date_format:H:i',
            'price_ranges.*.price' => 'required|numeric|min:0',
        ]);

        $errors = $this->scheduleService->validatePriceRanges(
            $validated['price_ranges'], $validated['open_time'], $validated['close_time']
        );
        if (!empty($errors)) {
            return back()->with('error', implode('. ', $errors))->withInput();
        }

        $court->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'open_time' => $validated['open_time'],
            'close_time' => $validated['close_time'],
        ]);

        // Пересоздать ценовые интервалы
        $court->priceRanges()->delete();
        foreach ($validated['price_ranges'] as $range) {
            CourtPriceRange::create([
                'court_id' => $court->id,
                'time_from' => $range['time_from'],
                'time_to' => $range['time_to'],
                'price' => $range['price'],
            ]);
        }

        return back()->with('success', 'Корт обновлён!');
    }

    public function destroy(Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $hasBookings = CourtBooking::where('court_id', $court->id)
            ->where('status', 'confirmed')
            ->where('date', '>=', now()->format('Y-m-d'))
            ->exists();

        if ($hasBookings) {
            return back()->with('error', 'Нельзя удалить корт с будущими бронированиями');
        }

        $court->priceRanges()->delete();
        $court->blocks()->delete();
        $court->bookings()->delete();
        $court->delete();

        return back()->with('success', 'Корт удалён!');
    }

    public function toggleActive(Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $court->update(['is_active' => !$court->is_active]);

        return back()->with('success', $court->is_active ? 'Корт активирован!' : 'Корт деактивирован!');
    }

    // === Бронирование ===

    public function book(Request $request, Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'slots' => 'required|integer|min:1|max:8',
            'client_name' => 'required|string|max:255',
            'client_phone' => 'nullable|string|max:50',
        ]);

        $startTime = $validated['start_time'];
        $totalMinutes = $validated['slots'] * $court->slot_duration;
        $endTime = Carbon::parse($startTime)->addMinutes($totalMinutes)->format('H:i');

        if (!$this->scheduleService->canBook($court, $validated['date'], $startTime, $endTime)) {
            return back()->with('error', 'Выбранное время недоступно');
        }

        $price = $this->scheduleService->calculatePrice($court, $startTime, $endTime);

        CourtBooking::create([
            'court_id' => $court->id,
            'date' => $validated['date'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'client_name' => $validated['client_name'],
            'client_phone' => $validated['client_phone'] ?? null,
            'booked_by' => auth()->id(),
            'price' => $price,
        ]);

        return back()->with('success', "Забронировано: {$validated['client_name']}, {$startTime}–{$endTime}, " . number_format($price, 0, '', ' ') . " ₸");
    }

    public function cancelBooking(CourtBooking $booking)
    {
        $club = $this->getClub();
        $court = $booking->court;
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $booking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return back()->with('success', 'Бронирование отменено');
    }

    // === Блокировка ===

    public function blockSlot(Request $request, Court $court)
    {
        $club = $this->getClub();
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        // Проверка: нет ли бронирований
        $hasBooking = CourtBooking::where('court_id', $court->id)
            ->where('date', $validated['date'])
            ->where('status', 'confirmed')
            ->where('start_time', '<', $validated['end_time'])
            ->where('end_time', '>', $validated['start_time'])
            ->exists();

        if ($hasBooking) {
            return back()->with('error', 'Нельзя заблокировать — есть бронирование на это время');
        }

        CourtBlock::create([
            'court_id' => $court->id,
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
        ]);

        return back()->with('success', 'Слот заблокирован');
    }

    public function unblock(CourtBlock $block)
    {
        $club = $this->getClub();
        $court = $block->court;
        if (!$club || $court->club_id !== $club->id) return back()->with('error', 'Нет доступа');

        $block->delete();

        return back()->with('success', 'Слот разблокирован');
    }
}
```

- [ ] **Step 3: Коммит**

```bash
git add app/Http/Controllers/Club/CourtController.php routes/web.php
git commit -m "feat(courts): контроллер и роуты для виртуальных слотов"
```

---

## Task 5: Вьюшка — Настройки кортов (index)

**Files:**
- Modify: `resources/views/club/courts/index.blade.php`

- [ ] **Step 1: Переписать index.blade.php**

Полностью заменить содержимое на новый дизайн по мокапу `mockup-courts-settings.html`:
- Карточки кортов (название, статус, часы работы, шаг, ценовые интервалы тегами)
- Кнопки: редактировать, деактивировать, удалить
- Модалка создания корта (название, описание, время работы, динамические ценовые интервалы)
- Модалка редактирования корта (те же поля, заполненные текущими значениями)
- Alpine.js для динамического добавления/удаления ценовых интервалов
- Ссылка "Назад к расписанию"

Ключевой момент — форма с ценовыми интервалами:
```html
<div x-data="{ ranges: [{time_from: '08:00', time_to: '22:00', price: 5000}] }">
    <template x-for="(range, index) in ranges" :key="index">
        <div class="price-range-row">
            <input type="time" :name="'price_ranges['+index+'][time_from]'" x-model="range.time_from">
            <input type="time" :name="'price_ranges['+index+'][time_to]'" x-model="range.time_to">
            <input type="number" :name="'price_ranges['+index+'][price]'" x-model="range.price">
            <button @click="ranges.splice(index, 1)" type="button">✕</button>
        </div>
    </template>
    <button @click="ranges.push({time_from:'',time_to:'',price:''})" type="button">+ Добавить интервал</button>
</div>
```

- [ ] **Step 2: Проверить в браузере**

Открыть `/club/courts` — должен работать список кортов, создание, редактирование с ценовыми интервалами.

- [ ] **Step 3: Коммит**

```bash
git add resources/views/club/courts/index.blade.php
git commit -m "feat(courts): настройки кортов — карточки с ценовыми интервалами"
```

---

## Task 6: Вьюшка — Расписание (schedule)

**Files:**
- Modify: `resources/views/club/courts/schedule.blade.php`

- [ ] **Step 1: Переписать schedule.blade.php**

Полностью заменить содержимое на новый дизайн по мокапу `mockup-schedule.html`:
- Навигация по дате (стрелки + "Сегодня")
- Ссылка "Настройки кортов"
- Таблица: строки = часы, колонки = корты
- Ячейки с цветовой кодировкой (свободный/забронирован/заблокирован)
- Объединение ячеек для бронирований на 2+ часов (через rowspan)
- Легенда

Alpine.js модалки:
- Модалка бронирования: инфа о слоте, выбор длительности, имя/телефон, итоговая цена
- Модалка просмотра: инфа о брони + кнопка отмены
- Модалка блокировки: подтверждение блокировки

Логика rowspan для multi-hour бронирований:
```php
@php
    $skipSlots = []; // ['court_id-time' => true] — пропустить ячейку (merged)
    foreach ($courts as $court) {
        $schedule = $schedules[$court->id] ?? [];
        $times = array_keys($schedule);
        for ($i = 0; $i < count($times); $i++) {
            $slot = $schedule[$times[$i]];
            if ($slot['status'] === 'booked' && $slot['booking']) {
                $booking = $slot['booking'];
                $bookingStart = \Carbon\Carbon::parse($booking->start_time)->format('H:i');
                if ($times[$i] === $bookingStart) {
                    // First slot of booking — calculate rowspan
                    $bookingEnd = \Carbon\Carbon::parse($booking->end_time)->format('H:i');
                    $span = 0;
                    for ($j = $i; $j < count($times) && $times[$j] < $bookingEnd; $j++) {
                        $span++;
                        if ($j > $i) $skipSlots[$court->id . '-' . $times[$j]] = true;
                    }
                } elseif (!isset($skipSlots[$court->id . '-' . $times[$i]])) {
                    // Не первый слот и не в skipSlots — что-то пошло не так
                }
            }
        }
    }
@endphp
```

- [ ] **Step 2: Проверить в браузере**

Открыть `/club/courts/schedule` — расписание на сегодня, все корты в колонках, модалки работают.

- [ ] **Step 3: Коммит**

```bash
git add resources/views/club/courts/schedule.blade.php
git commit -m "feat(courts): расписание — дневной вид со всеми кортами"
```

---

## Task 7: Удаление старого кода + финальная проверка

**Files:**
- Delete: `resources/views/club/courts/slots.blade.php`
- Modify: `routes/web.php` (убрать упоминания CourtSlot если остались)

- [ ] **Step 1: Удалить slots.blade.php**

```bash
git rm resources/views/club/courts/slots.blade.php
```

- [ ] **Step 2: Проверить что нет ссылок на CourtSlot**

Run: `grep -r "CourtSlot\|court_slot" app/ routes/ resources/ --include="*.php" --include="*.blade.php" -l`
Expected: ничего не найдено (кроме миграций)

- [ ] **Step 3: Запустить все тесты**

Run: `php artisan test`
Expected: все тесты проходят

- [ ] **Step 4: Проверить в браузере**

1. `/club/courts` — настройки кортов, создание с ценовыми интервалами
2. `/club/courts/schedule` — расписание на сегодня
3. Клик на свободный слот → бронирование
4. Клик на забронированный → просмотр + отмена
5. Блокировка/разблокировка слота

- [ ] **Step 5: Финальный коммит**

```bash
git add -A
git commit -m "feat(courts): редизайн бронирования кортов — виртуальные слоты"
```

# Привязка брони корта к турниру — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** При бронировании корта с типом «Турнир» выбрать конкретный турнир клуба, а цену брони считать как «цена турнира × число оплативших участников», делённую поровну между кортами турнира в пределах одной даты.

**Architecture:** В `court_bookings` добавляется колонка `tournament_id`. Вся арифметика живёт в новом сервисе `TournamentBookingPriceService`, который раскладывает сумму по броням и записывает результат в существующее поле `price` — благодаря этому отчёты, касса и мобильное API не требуют правок. Сервис вызывается лениво при открытии расписания и после любого изменения турнирной брони. UI повторяет уже работающий механизм групповой брони.

**Tech Stack:** Laravel 12, MySQL (прод) / SQLite (тесты), Blade + ванильный JS, PHPUnit.

## Global Constraints

- Спека: `docs/superpowers/specs/2026-08-07-court-booking-tournament-link-design.md`
- Статусы турниров в списке выбора: `open`, `in_progress`.
- Статус броней, участвующих в делении: `confirmed` (значение `cancelled` не участвует).
- Число оплативших участников берётся **только** через `Tournament::approvedParticipantsCount()` — своя логика подсчёта запрещена.
- Скидка и `custom_price` для турнирной брони обнуляются.
- Все комментарии в коде и тексты интерфейса — на русском.
- Прогон тестов только точечный, через `--filter`: в сьюте ~14 давно падающих тестов, не связанных с этой работой.
- Существующий CSS-класс `.js-hide-for-group` не переименовывается.

---

## File Structure

| Файл | Ответственность |
|---|---|
| `database/migrations/2026_08_07_000001_add_tournament_id_to_court_bookings.php` | Создать: колонка связи и индекс |
| `app/Models/CourtBooking.php` | Изменить: `$fillable`, связь `tournament()` |
| `app/Models/Tournament.php` | Изменить: связь `courtBookings()` |
| `app/Services/TournamentBookingPriceService.php` | Создать: вся арифметика цены и данные для выпадающего списка |
| `app/Http/Controllers/Club/CourtController.php` | Изменить: передача данных во вьюхи, сохранение связи, вызовы пересчёта |
| `resources/views/club/courts/schedule.blade.php` | Изменить: блоки турнира в модалках создания и редактирования, JS |
| `resources/views/club/courts/schedule_week.blade.php` | Изменить: то же для недельного вида |
| `tests/Feature/TournamentCourtBookingTest.php` | Создать: тесты расчёта и сквозные сценарии |

---

### Task 1: Колонка связи и модели

**Files:**
- Create: `database/migrations/2026_08_07_000001_add_tournament_id_to_court_bookings.php`
- Modify: `app/Models/CourtBooking.php:9-41` (блок `$fillable`)
- Modify: `app/Models/Tournament.php`
- Test: `tests/Feature/TournamentCourtBookingTest.php`

**Interfaces:**
- Consumes: ничего
- Produces: `CourtBooking::$tournament_id`, связь `CourtBooking::tournament(): BelongsTo`, связь `Tournament::courtBookings(): HasMany`

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Feature/TournamentCourtBookingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentCourtBookingTest extends TestCase
{
    use RefreshDatabase;

    /** Клуб, админ, корт и турнир с ценой — общая заготовка для тестов. */
    private function setupTournament(float $price = 20000): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $tournament = Tournament::create([
            'club_id' => $club->id,
            'name' => 'Американо',
            'type' => 'americano',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'max_participants' => 16,
            'price' => $price,
        ]);

        return [$club, $admin, $court, $tournament];
    }

    public function test_booking_belongs_to_tournament(): void
    {
        [, , $court, $tournament] = $this->setupTournament();

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Турнир: Американо',
            'status' => 'confirmed',
            'price' => 0,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
        ]);

        $this->assertSame($tournament->id, $booking->fresh()->tournament->id);
        $this->assertTrue($tournament->courtBookings->contains($booking));
    }

    public function test_deleting_tournament_keeps_booking(): void
    {
        [, , $court, $tournament] = $this->setupTournament();

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Турнир: Американо',
            'status' => 'confirmed',
            'price' => 50000,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
        ]);

        $tournament->delete();

        $booking->refresh();
        $this->assertNull($booking->tournament_id);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('50000.00', $booking->price);
    }
}
```

- [ ] **Step 2: Запустить тест и убедиться, что он падает**

Run: `php artisan test --filter=test_booking_belongs_to_tournament`
Expected: FAIL — нет колонки `tournament_id` и нет связи `tournament`.

- [ ] **Step 3: Создать миграцию**

`database/migrations/2026_08_07_000001_add_tournament_id_to_court_bookings.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('court_bookings', function (Blueprint $table) {
            // Турнир, за которым закреплена бронь. Удаление турнира не удаляет
            // бронь — она лишь перестаёт быть турнирной и сохраняет последнюю цену.
            $table->foreignId('tournament_id')->nullable()->after('booking_type')
                  ->constrained()->nullOnDelete();
            // По этой паре сервис собирает набор броней для деления суммы.
            $table->index(['tournament_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('court_bookings', function (Blueprint $table) {
            $table->dropIndex(['tournament_id', 'date']);
            $table->dropForeign(['tournament_id']);
            $table->dropColumn('tournament_id');
        });
    }
};
```

- [ ] **Step 4: Добавить поле и связь в `CourtBooking`**

В `app/Models/CourtBooking.php` в массив `$fillable` после строки `'booking_type',` добавить:

```php
        'tournament_id',
```

И метод-связь рядом с остальными связями модели:

```php
    /**
     * Турнир, за которым закреплена бронь (для booking_type = 'tournament').
     */
    public function tournament()
    {
        return $this->belongsTo(\App\Models\Tournament::class);
    }
```

- [ ] **Step 5: Добавить обратную связь в `Tournament`**

В `app/Models/Tournament.php` рядом с остальными связями:

```php
    /**
     * Брони кортов, закреплённые за турниром.
     */
    public function courtBookings()
    {
        return $this->hasMany(\App\Models\CourtBooking::class);
    }
```

- [ ] **Step 6: Запустить тест и убедиться, что он проходит**

Run: `php artisan test --filter=TournamentCourtBookingTest`
Expected: PASS, 2 теста.

- [ ] **Step 7: Коммит**

```bash
git add database/migrations/2026_08_07_000001_add_tournament_id_to_court_bookings.php app/Models/CourtBooking.php app/Models/Tournament.php tests/Feature/TournamentCourtBookingTest.php
git commit -m "feat(courts): связь брони корта с турниром"
```

---

### Task 2: Сервис расчёта цены

**Files:**
- Create: `app/Services/TournamentBookingPriceService.php`
- Test: `tests/Feature/TournamentCourtBookingTest.php` (дополняется)

**Interfaces:**
- Consumes: `CourtBooking::$tournament_id`, `Tournament::courtBookings()` из Task 1; готовый `Tournament::approvedParticipantsCount()` (`app/Models/Tournament.php:626`)
- Produces:
  - `TournamentBookingPriceService::totalForDate(Tournament $tournament): float`
  - `TournamentBookingPriceService::syncForDate(Tournament $tournament, string $date): bool`
  - `TournamentBookingPriceService::syncForBooking(CourtBooking $booking): void`

- [ ] **Step 1: Написать падающие тесты**

Добавить в `tests/Feature/TournamentCourtBookingTest.php`. Сначала импорты в шапку файла:

```php
use App\Models\TournamentParticipant;
use App\Services\TournamentBookingPriceService;
```

Затем хелперы и тесты внутрь класса:

```php
    /** Записать в турнир $count оплативших и $pending заявок на модерации. */
    private function addParticipants(Tournament $tournament, int $count, int $pending = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'registered',
            ]);
        }
        for ($i = 0; $i < $pending; $i++) {
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'pending',
            ]);
        }
    }

    /** Создать турнирную бронь на корте в указанное время. */
    private function makeBooking(Court $court, Tournament $tournament, string $date, string $start): CourtBooking
    {
        return CourtBooking::create([
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => $start,
            'end_time' => '23:00',
            'client_name' => 'Турнир: ' . $tournament->name,
            'status' => 'confirmed',
            'price' => 0,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
        ]);
    }

    public function test_total_is_price_times_paid_participants(): void
    {
        [, , , $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);

        $total = app(TournamentBookingPriceService::class)->totalForDate($tournament->fresh());

        $this->assertSame(100000.0, $total);
    }

    public function test_pending_participants_do_not_count(): void
    {
        [, , , $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5, pending: 3);

        $total = app(TournamentBookingPriceService::class)->totalForDate($tournament->fresh());

        $this->assertSame(100000.0, $total);
    }

    public function test_single_court_gets_full_sum(): void
    {
        [, , $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();
        $booking = $this->makeBooking($court, $tournament, $date, '10:00');

        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);

        $this->assertSame('100000.00', $booking->fresh()->price);
    }

    public function test_sum_splits_evenly_between_four_courts(): void
    {
        [$club, , $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $bookings = [$this->makeBooking($court, $tournament, $date, '10:00')];
        for ($i = 2; $i <= 4; $i++) {
            $extra = Court::create([
                'club_id' => $club->id, 'name' => "Корт {$i}", 'is_active' => true,
                'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
            ]);
            $bookings[] = $this->makeBooking($extra, $tournament, $date, '10:00');
        }

        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);

        foreach ($bookings as $b) {
            $this->assertSame('25000.00', $b->fresh()->price);
        }
    }

    public function test_remainder_goes_to_first_booking(): void
    {
        [$club, , $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $first = $this->makeBooking($court, $tournament, $date, '10:00');
        $rest = [];
        for ($i = 2; $i <= 3; $i++) {
            $extra = Court::create([
                'club_id' => $club->id, 'name' => "Корт {$i}", 'is_active' => true,
                'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
            ]);
            $rest[] = $this->makeBooking($extra, $tournament, $date, '11:00');
        }

        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);

        // 100 000 / 3 = 33 333,33 — остаток достаётся первой по времени броне.
        $this->assertSame('33333.34', $first->fresh()->price);
        $this->assertSame('33333.33', $rest[0]->fresh()->price);
        $this->assertSame('33333.33', $rest[1]->fresh()->price);
        $sum = collect([$first, ...$rest])->sum(fn ($b) => (float) $b->fresh()->price);
        $this->assertSame(100000.0, round($sum, 2));
    }

    public function test_cancelled_booking_is_excluded_from_split(): void
    {
        [$club, , $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $kept = $this->makeBooking($court, $tournament, $date, '10:00');
        $second = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 2', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $cancelled = $this->makeBooking($second, $tournament, $date, '10:00');
        $cancelled->update(['status' => 'cancelled']);

        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);

        $this->assertSame('100000.00', $kept->fresh()->price);
    }

    public function test_sync_without_bookings_returns_false(): void
    {
        [, , , $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);

        $changed = app(TournamentBookingPriceService::class)
            ->syncForDate($tournament->fresh(), now()->addDay()->toDateString());

        $this->assertFalse($changed);
    }
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter=TournamentCourtBookingTest`
Expected: FAIL — класс `TournamentBookingPriceService` не существует.

- [ ] **Step 3: Написать сервис**

`app/Services/TournamentBookingPriceService.php`:

```php
<?php

namespace App\Services;

use App\Models\CourtBooking;
use App\Models\Tournament;

/**
 * Цена турнирных броней корта.
 *
 * Сумма за турнир на дату = цена турнира × число оплативших участников.
 * Она делится поровну между всеми подтверждёнными бронями турнира на эту дату.
 */
class TournamentBookingPriceService
{
    /**
     * Сколько всего должен стоить турнир на дату (до деления между кортами).
     */
    public function totalForDate(Tournament $tournament): float
    {
        // approvedParticipantsCount() сам различает личные турниры (статус
        // 'registered') и командные ('approved'-пары × 2) — свою логику не пишем.
        return (float) $tournament->price * $tournament->approvedParticipantsCount();
    }

    /**
     * Разложить сумму турнира по его броням на дату.
     * Возвращает true, если хотя бы одна цена изменилась.
     */
    public function syncForDate(Tournament $tournament, string $date): bool
    {
        $bookings = CourtBooking::where('tournament_id', $tournament->id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        if ($bookings->isEmpty()) {
            return false;
        }

        $total = $this->totalForDate($tournament);
        $count = $bookings->count();

        // Делим до копеек, остаток отдаём первой броне, чтобы сумма сошлась.
        $share = floor($total / $count * 100) / 100;
        $remainder = round($total - $share * $count, 2);

        $changed = false;
        foreach ($bookings as $i => $booking) {
            $price = $i === 0 ? round($share + $remainder, 2) : $share;
            if ((float) $booking->price !== $price) {
                // Скидка турнирной броне не применяется — цену задаёт турнир.
                $booking->update(['price' => $price, 'discount' => 0]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Пересчитать набор, в который входит бронь. Безопасно вызывать
     * для любой брони — не турнирные просто игнорируются.
     */
    public function syncForBooking(CourtBooking $booking): void
    {
        if (!$booking->tournament_id) {
            return;
        }

        $tournament = Tournament::find($booking->tournament_id);
        if ($tournament) {
            $this->syncForDate($tournament, $booking->date->format('Y-m-d'));
        }
    }
}
```

- [ ] **Step 4: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=TournamentCourtBookingTest`
Expected: PASS, 9 тестов.

- [ ] **Step 5: Коммит**

```bash
git add app/Services/TournamentBookingPriceService.php tests/Feature/TournamentCourtBookingTest.php
git commit -m "feat(courts): сервис расчёта цены турнирной брони"
```

---

### Task 3: Данные о турнирах во вьюхи

**Files:**
- Modify: `app/Services/TournamentBookingPriceService.php` (добавляется `pickerData`)
- Modify: `app/Http/Controllers/Club/CourtController.php:137-158` (метод `schedule`)
- Modify: `app/Http/Controllers/Club/CourtController.php:344-365` (метод `scheduleWeek`)
- Test: `tests/Feature/TournamentCourtBookingTest.php` (дополняется)

**Interfaces:**
- Consumes: `totalForDate`, `syncForDate` из Task 2
- Produces: `TournamentBookingPriceService::pickerData(Club $club, array $dates): array`; переменные вьюх `$bookingTournaments`, `$bookingTournamentIds`

- [ ] **Step 1: Написать падающий тест**

Добавить в `tests/Feature/TournamentCourtBookingTest.php`:

```php
    public function test_schedule_page_exposes_tournaments(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk()
            ->assertSee('Американо')
            ->assertSee('__tournaments', escape: false);
    }

    public function test_completed_tournament_is_not_offered(): void
    {
        [, $admin, , $tournament] = $this->setupTournament(20000);
        $tournament->update(['status' => 'completed', 'name' => 'Прошедший турнир']);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => now()->addDay()->toDateString()]))
            ->assertOk()
            ->assertDontSee('Прошедший турнир');
    }

    public function test_opening_schedule_recalculates_price(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $date = now()->addDay()->toDateString();
        $booking = $this->makeBooking($court, $tournament, $date, '10:00');

        // Игроки записались уже после того, как корт забронировали.
        $this->addParticipants($tournament, 5);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk();

        $this->assertSame('100000.00', $booking->fresh()->price);
    }
```

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter=test_schedule_page_exposes_tournaments`
Expected: FAIL — страница не содержит ни названия турнира, ни `__tournaments`.

- [ ] **Step 3: Добавить `pickerData` в сервис**

Дописать в `app/Services/TournamentBookingPriceService.php` (нужен импорт `use App\Models\Club;`):

```php
    /**
     * Данные о турнирах клуба для выпадающего списка в модалке брони.
     * Попутно пересчитывает цены броней в видимом диапазоне дат —
     * это и есть живой пересчёт при открытии расписания.
     *
     * @param  array<string> $dates даты видимого диапазона, формат Y-m-d
     * @return array<int, array<string, mixed>> ключ — id турнира
     */
    public function pickerData(Club $club, array $dates): array
    {
        $tournaments = Tournament::where('club_id', $club->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->orderBy('start_date', 'desc')
            ->get();

        $result = [];
        foreach ($tournaments as $t) {
            foreach ($dates as $date) {
                $this->syncForDate($t, $date);
            }

            // Сколько турнирных броней уже есть на каждую дату диапазона —
            // клиент по этому числу показывает предварительное деление.
            $bookingsByDate = CourtBooking::where('tournament_id', $t->id)
                ->whereIn('date', $dates)
                ->where('status', 'confirmed')
                ->get()
                ->groupBy(fn ($b) => $b->date->format('Y-m-d'))
                ->map->count()
                ->toArray();

            $result[$t->id] = [
                'id' => $t->id,
                'name' => $t->name,
                'date' => $t->start_date?->format('d.m'),
                'price' => (float) $t->price,
                'paid_count' => $t->approvedParticipantsCount(),
                'total' => $this->totalForDate($t),
                'participants' => $t->participants()
                    ->wherePivot('status', 'registered')
                    ->pluck('name')
                    ->toArray(),
                'bookings_by_date' => $bookingsByDate,
            ];
        }

        return $result;
    }
```

- [ ] **Step 4: Передать данные из `schedule`**

В `app/Http/Controllers/Club/CourtController.php` в методе `schedule`, сразу после блока `$bookingGroupIds` (строка ~152), добавить:

```php
        // Турниры для брони типа «Турнир» + живой пересчёт цен за этот день.
        $bookingTournaments = app(\App\Services\TournamentBookingPriceService::class)
            ->pickerData($club, [$date]);

        // Карта court_booking_id => tournament_id — чтобы окно редактирования
        // подставило турнир в селект.
        $bookingTournamentIds = CourtBooking::whereIn('court_id', $courts->pluck('id'))
            ->whereNotNull('tournament_id')
            ->pluck('tournament_id', 'id');
```

И расширить `compact` в `return view(...)`:

```php
        return view('club.courts.schedule', compact(
            'club', 'courts', 'schedules', 'timeSlots', 'date',
            'weekDays', 'prevWeek', 'nextWeek', 'clubCoaches', 'coachAvailability',
            'unprocessedBookings', 'activeGroups', 'bookingGroupIds',
            'bookingTournaments', 'bookingTournamentIds'
        ));
```

- [ ] **Step 5: Передать данные из `scheduleWeek`**

В методе `scheduleWeek` после блока `$bookingGroupIds` (строка ~359) добавить то же самое, но с диапазоном дат недели:

```php
        // Турниры для брони типа «Турнир» + живой пересчёт цен за неделю.
        $bookingTournaments = app(\App\Services\TournamentBookingPriceService::class)
            ->pickerData($club, collect($weekDays)->pluck('date')->all());

        $bookingTournamentIds = CourtBooking::whereIn('court_id', $courts->pluck('id'))
            ->whereNotNull('tournament_id')
            ->pluck('tournament_id', 'id');
```

И расширить `compact`:

```php
        return view('club.courts.schedule_week', compact(
            'club', 'courts', 'timeSlots', 'date', 'weekDays', 'prevWeek', 'nextWeek',
            'weekRangeLabel', 'freePrices', 'freeSlotsByDate', 'coachAvailability', 'clubCoaches',
            'unprocessedBookings', 'activeGroups', 'bookingGroupIds',
            'bookingTournaments', 'bookingTournamentIds'
        ));
```

Ключ `date` в элементах `$weekDays` подтверждён — `scheduleWeek` собирает их так же, как `schedule` (`CourtController.php:282-283`).

- [ ] **Step 6: Временно вывести данные во вьюху, чтобы тест прошёл**

В `resources/views/club/courts/schedule.blade.php` рядом с существующей строкой `<script>window.__groupMembers = ...` (`:770`) добавить:

```blade
<script>
window.__tournaments = @json($bookingTournaments ?? []);
window.__scheduleDate = @json($date);
</script>
```

То же самое в `resources/views/club/courts/schedule_week.blade.php` — рядом с аналогичной строкой вывода `__groupMembers`. В недельной вьюхе `$date` — это дата, выбранная в навигации; она понадобится как запасное значение, но основную дату там даёт выбранный слот (см. Task 8).

- [ ] **Step 7: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=TournamentCourtBookingTest`
Expected: PASS, 12 тестов.

- [ ] **Step 8: Коммит**

```bash
git add app/Services/TournamentBookingPriceService.php app/Http/Controllers/Club/CourtController.php resources/views/club/courts/schedule.blade.php resources/views/club/courts/schedule_week.blade.php tests/Feature/TournamentCourtBookingTest.php
git commit -m "feat(courts): турниры и живой пересчёт цен в расписании"
```

---

### Task 4: Сохранение турнира при создании брони

**Files:**
- Modify: `app/Http/Controllers/Club/CourtController.php:631-664` (валидация в `storeBooking`)
- Modify: `app/Http/Controllers/Club/CourtController.php:666-704` (ветка автозаполнения)
- Modify: `app/Http/Controllers/Club/CourtController.php:847-851` (расчёт цены)
- Modify: `app/Http/Controllers/Club/CourtController.php:859-882` (создание брони)
- Test: `tests/Feature/TournamentCourtBookingTest.php` (дополняется)

**Interfaces:**
- Consumes: `syncForBooking` из Task 2, `tournament_id` из Task 1
- Produces: приём поля `tournament_id` в запросе создания брони

- [ ] **Step 1: Написать падающий тест**

```php
    public function test_store_booking_links_tournament_and_sets_price(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => $date,
            'start_time' => '10:00',
            'slots' => 1,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
        ])->assertRedirect();

        $booking = CourtBooking::where('tournament_id', $tournament->id)->first();
        $this->assertNotNull($booking);
        $this->assertSame('tournament', $booking->booking_type);
        $this->assertSame('100000.00', $booking->price);
        $this->assertSame('Турнир: Американо', $booking->client_name);
        $this->assertNull($booking->payment_method);
    }
```

Маршрут: `POST /club/courts/{court}/book`, имя `club.courts.book` (`routes/web.php:363`).

- [ ] **Step 2: Запустить тест и убедиться, что он падает**

Run: `php artisan test --filter=test_store_booking_links_tournament_and_sets_price`
Expected: FAIL — требуются `client_name`, `client_phone`, `payment_method`, а `tournament_id` не сохраняется.

- [ ] **Step 3: Расширить валидацию**

В `storeBooking` заменить строку с флагом группы (`:629`):

```php
        $isGroupBooking = ($request->input('booking_type') === 'group');
```

на:

```php
        $isGroupBooking = ($request->input('booking_type') === 'group');
        // Турнирная бронь, как и групповая, не требует клиента и оплаты:
        // цену задаёт турнир, а игроки платят взносы отдельно.
        $isTournamentBooking = ($request->input('booking_type') === 'tournament');
        $isAutoBooking = $isGroupBooking || $isTournamentBooking;
```

В массиве правил заменить четыре правила с `required_unless:booking_type,group` так, чтобы турнир тоже освобождался — `required_unless` принимает несколько значений:

```php
            'client_name' => 'required_unless:booking_type,group,tournament|nullable|string|max:255',
            'client_phone' => 'required_unless:booking_type,group,tournament|nullable|string|max:50',
            'payment_method' => 'required_unless:booking_type,group,tournament|nullable|string|in:cash,card,kaspi,certificate,club_card,deposit,cashback,cashless,free,plexy',
            'is_paid' => 'required_unless:booking_type,group,tournament|nullable|boolean',
```

И добавить в тот же массив правило для турнира:

```php
            'tournament_id' => 'nullable|exists:tournaments,id',
```

- [ ] **Step 4: Добавить ветку автозаполнения**

Заменить условие `if ($isGroupBooking) {` (`:666`) на `if ($isGroupBooking) {` … и добавить новую ветку `elseif` перед `else`. Итоговая структура:

```php
        if ($isGroupBooking) {
            // ... существующий код групповой брони без изменений ...
            $linkedUser = null;
        } elseif ($isTournamentBooking) {
            // Турнирная бронь: клиент и оплата берутся из турнира.
            $tournamentForBooking = !empty($validated['tournament_id'])
                ? \App\Models\Tournament::where('club_id', $club->id)
                    ->find($validated['tournament_id'])
                : null;
            $validated['client_name'] = $tournamentForBooking
                ? ('Турнир: ' . $tournamentForBooking->name)
                : 'Турнир';
            $validated['client_phone'] = null;
            $validated['payment_method'] = null;
            $validated['is_paid'] = false;
            $validated['discount'] = 0;
            $validated['custom_price'] = 0;
            $linkedUser = null;
        } else {
            // ... существующий код обычной брони без изменений ...
        }
```

Объявить `$tournamentForBooking = null;` рядом с `$groupSessionPrice` до условия, чтобы переменная существовала во всех ветках.

- [ ] **Step 5: Обнулить цену при создании и сохранить связь**

В блоке расчёта цены (`:847`) после групповой ветки добавить:

```php
            // Турнирная бронь: цену выставит сервис после создания записи,
            // когда станет известно, сколько кортов делят сумму.
            if ($isTournamentBooking) {
                $price = 0;
                $discount = 0;
            }
```

В массив `CourtBooking::create([...])` (`:859`) после `'booking_type' => ...` добавить:

```php
                'tournament_id' => $isTournamentBooking ? ($validated['tournament_id'] ?? null) : null,
```

- [ ] **Step 6: Вызвать пересчёт после создания**

В том же цикле, сразу после блока привязки к группе (`:931`, закрывающая скобка `}` перед `ActivityLog::log('created', ...)`), добавить:

```php
            // Турнирная бронь: пересчитать сумму по всем кортам турнира на эту дату.
            if ($isTournamentBooking && $booking->tournament_id) {
                app(\App\Services\TournamentBookingPriceService::class)->syncForBooking($booking);
            }
```

- [ ] **Step 7: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=TournamentCourtBookingTest`
Expected: PASS, 13 тестов.

- [ ] **Step 8: Коммит**

```bash
git add app/Http/Controllers/Club/CourtController.php tests/Feature/TournamentCourtBookingTest.php
git commit -m "feat(courts): создание брони с привязкой к турниру"
```

---

### Task 5: Редактирование и отмена турнирной брони

**Files:**
- Modify: `app/Http/Controllers/Club/CourtController.php:1054-1080` (валидация в `updateBooking`)
- Modify: `app/Http/Controllers/Club/CourtController.php:1169-1202` (сборка `$updateData`)
- Modify: `app/Http/Controllers/Club/CourtController.php:1400` (`cancelBooking`)
- Test: `tests/Feature/TournamentCourtBookingTest.php` (дополняется)

**Interfaces:**
- Consumes: `syncForDate`, `syncForBooking` из Task 2
- Produces: приём `tournament_id` при редактировании; пересчёт обоих наборов при смене турнира

- [ ] **Step 1: Написать падающие тесты**

```php
    public function test_changing_tournament_recalculates_both_sets(): void
    {
        [$club, $admin, $court, $first] = $this->setupTournament(20000);
        $this->addParticipants($first, 5);
        $second = Tournament::create([
            'club_id' => $club->id, 'name' => 'Мексикано', 'type' => 'mexicano',
            'status' => 'open', 'start_date' => now()->addDay()->toDateString(),
            'max_participants' => 16, 'price' => 10000,
        ]);
        $this->addParticipants($second, 4);

        $date = now()->addDay()->toDateString();
        $booking = $this->makeBooking($court, $first, $date, '10:00');
        app(TournamentBookingPriceService::class)->syncForDate($first->fresh(), $date);
        $this->assertSame('100000.00', $booking->fresh()->price);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'tournament',
            'tournament_id' => $second->id,
        ])->assertRedirect();

        $this->assertSame($second->id, $booking->fresh()->tournament_id);
        $this->assertSame('40000.00', $booking->fresh()->price);
    }

    public function test_cancelling_one_booking_raises_the_others(): void
    {
        [$club, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $kept = $this->makeBooking($court, $tournament, $date, '10:00');
        $second = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 2', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $dropped = $this->makeBooking($second, $tournament, $date, '10:00');
        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);
        $this->assertSame('50000.00', $kept->fresh()->price);

        $this->actingAs($admin)
            ->post(route('club.courts.cancelBooking', $dropped))
            ->assertRedirect();

        $this->assertSame('100000.00', $kept->fresh()->price);
    }
```

Маршруты: `PUT /club/courts/bookings/{booking}` — `club.courts.updateBooking` (`routes/web.php:365`); `POST /club/courts/bookings/{booking}/cancel` — `club.courts.cancelBooking` (`routes/web.php:364`).

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter=test_changing_tournament_recalculates_both_sets`
Expected: FAIL — `tournament_id` при редактировании не принимается.

- [ ] **Step 3: Расширить валидацию редактирования**

В `updateBooking` заменить флаг (`:1063`):

```php
        $isGroupBooking = ($request->input('booking_type') === 'group');
```

на:

```php
        $isGroupBooking = ($request->input('booking_type') === 'group');
        $isTournamentBooking = ($request->input('booking_type') === 'tournament');
        // Турнир, за которым бронь была закреплена ДО правки — его набор
        // тоже нужно пересчитать, иначе оставшиеся брони сохранят старую долю.
        $previousTournamentId = $booking->tournament_id;
        $previousDate = $booking->date->format('Y-m-d');
```

Правила `required_unless:booking_type,group` заменить на `required_unless:booking_type,group,tournament` — те же четыре поля, что в Task 4, и добавить:

```php
            'tournament_id' => 'nullable|exists:tournaments,id',
```

- [ ] **Step 4: Сохранять связь и чистить клиента**

В сборке `$updateData` (`:1171`) добавить в массив:

```php
            'tournament_id' => $isTournamentBooking ? ($request->input('tournament_id') ?: null) : null,
```

И заменить условие `if (!$isGroupBooking) {` (`:1186`) на `if (!$isGroupBooking && !$isTournamentBooking) {`, чтобы турнирная бронь не требовала клиента и оплаты.

Сразу после этого условия добавить ветку:

```php
        if ($isTournamentBooking) {
            $tournamentForBooking = \App\Models\Tournament::where('club_id', $club->id)
                ->find($request->input('tournament_id'));
            $updateData['client_name'] = $tournamentForBooking
                ? ('Турнир: ' . $tournamentForBooking->name)
                : 'Турнир';
            $updateData['client_phone'] = null;
            $updateData['payment_method'] = null;
            $updateData['is_paid'] = false;
        }
```

- [ ] **Step 5: Пересчитать оба набора после сохранения**

Найти в `updateBooking` место после `$booking->update($updateData);` и добавить:

```php
        // Пересчитываем и новый набор, и прежний — бронь могла сменить турнир,
        // дату или вовсе перестать быть турнирной.
        $priceService = app(\App\Services\TournamentBookingPriceService::class);
        $priceService->syncForBooking($booking->fresh());
        if ($previousTournamentId && $previousTournamentId !== $booking->tournament_id) {
            $previous = \App\Models\Tournament::find($previousTournamentId);
            if ($previous) {
                $priceService->syncForDate($previous, $previousDate);
            }
        }
```

- [ ] **Step 6: Пересчитать после отмены**

В `cancelBooking` (`:1400`) после того, как броне проставлен статус `cancelled` и изменения сохранены, добавить:

```php
        // Отменённая бронь выходит из деления — остальные корты турнира дорожают.
        if ($booking->tournament_id) {
            $tournament = \App\Models\Tournament::find($booking->tournament_id);
            if ($tournament) {
                app(\App\Services\TournamentBookingPriceService::class)
                    ->syncForDate($tournament, $booking->date->format('Y-m-d'));
            }
        }
```

- [ ] **Step 7: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=TournamentCourtBookingTest`
Expected: PASS, 15 тестов.

- [ ] **Step 8: Коммит**

```bash
git add app/Http/Controllers/Club/CourtController.php tests/Feature/TournamentCourtBookingTest.php
git commit -m "feat(courts): редактирование и отмена турнирной брони"
```

---

### Task 6: Выбор турнира в модалке создания

**Files:**
- Modify: `resources/views/club/courts/schedule.blade.php:712-772` (блок после кнопок типа брони)
- Modify: `resources/views/club/courts/schedule.blade.php:2226-2256` (функция `selectBookingType`)

**Interfaces:**
- Consumes: `window.__tournaments` из Task 3, поле `tournament_id` из Task 4
- Produces: `renderTournamentInfo(tournamentId)`; поле формы `tournament_id`

- [ ] **Step 1: Добавить блок выбора турнира**

В `resources/views/club/courts/schedule.blade.php` сразу после закрывающего `@endif` блока групп (`:771`) и перед строкой `<script>window.__coachNames = ...` (`:772`) вставить:

```blade
                        <div id="bookTournamentSelectWrap" style="display:none;">
                            <div class="modal-section-title">Турнир</div>
                            <div class="form-group">
                                <select name="tournament_id" id="bookTournamentSelect" class="form-input" onchange="renderTournamentInfo(this.value)">
                                    <option value="">— выберите турнир —</option>
                                    @foreach(($bookingTournaments ?? []) as $t)
                                        <option value="{{ $t['id'] }}">{{ $t['name'] }}{{ $t['date'] ? ' — ' . $t['date'] : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div id="tnPrice" style="display:none;margin:8px 0;color:#a1a1aa;font-size:13px;"></div>
                            <div id="tournamentInfoBlock" class="group-members-block" style="display:none;">
                                <div class="gm-header">
                                    <span class="gm-title">Оплатившие участники</span>
                                    <span class="gm-count" id="tnCount"></span>
                                </div>
                                <ul id="tnList" class="gm-list"></ul>
                                <div id="tnEmpty" class="gm-empty" style="display:none;">Оплативших пока нет — цена появится после записи игроков</div>
                                <a id="tnLink" href="#" target="_blank" rel="noopener"
                                   style="display:none;align-items:center;justify-content:center;gap:8px;margin-top:10px;padding:10px 12px;border-radius:10px;background:rgba(167,139,250,0.10);border:1px solid rgba(167,139,250,0.35);color:#a78bfa;font-size:13px;font-weight:700;text-decoration:none;">
                                    <i class="bi bi-trophy"></i> Открыть турнир
                                </a>
                            </div>
                        </div>
```

- [ ] **Step 2: Написать `renderTournamentInfo`**

Рядом с `renderGroupMembers` (после её закрывающей скобки) добавить:

```javascript
    // Информация о выбранном турнире: расчёт цены и список оплативших.
    function renderTournamentInfo(tournamentId) {
        const block = document.getElementById('tournamentInfoBlock');
        const list = document.getElementById('tnList');
        const empty = document.getElementById('tnEmpty');
        const count = document.getElementById('tnCount');
        const priceEl = document.getElementById('tnPrice');
        const link = document.getElementById('tnLink');
        if (!block || !list) return;

        if (!tournamentId) {
            block.style.display = 'none';
            list.innerHTML = '';
            if (priceEl) priceEl.style.display = 'none';
            if (link) link.style.display = 'none';
            return;
        }

        const data = (window.__tournaments && window.__tournaments[tournamentId]) || null;
        if (!data) { block.style.display = 'none'; return; }

        const fmt = n => new Intl.NumberFormat('ru-RU').format(n);
        // В дневном расписании дата одна на всю страницу — берём её с сервера.
        // (У скрытого поля name="date" нет id, обращаться к нему нечем.)
        const date = window.__scheduleDate || '';
        // Делитель — уже существующие брони турнира на эту дату плюс создаваемая.
        const existing = (data.bookings_by_date && data.bookings_by_date[date]) || 0;
        const divisor = existing + 1;
        const share = divisor > 0 ? Math.round(data.total / divisor) : 0;

        if (priceEl) {
            if (!data.price) {
                priceEl.innerHTML = 'У турнира не указана цена — <a href="{{ url('club/tournaments') }}/' + data.id + '/edit" target="_blank" style="color:#a78bfa;">укажите цену в турнире</a>';
            } else {
                let html = data.paid_count + ' оплативших × ' + fmt(data.price) + ' = <b>' + fmt(data.total) + ' ₸</b>';
                if (divisor > 1) {
                    html += '<br>делится на ' + divisor + ' корта → <b>' + fmt(share) + ' ₸</b> за этот корт';
                }
                priceEl.innerHTML = html;
            }
            priceEl.style.display = 'block';
        }

        if (link) {
            link.href = '{{ url('club/tournaments') }}/' + data.id;
            link.style.display = 'flex';
        }

        const players = data.participants || [];
        if (count) count.textContent = players.length ? players.length : '';
        list.innerHTML = '';
        players.forEach(n => {
            const li = document.createElement('li');
            li.className = 'gm-item';
            li.textContent = n; // textContent, а не innerHTML — имя пришло от пользователя
            list.appendChild(li);
        });
        if (empty) empty.style.display = players.length ? 'none' : 'block';
        block.style.display = 'block';
    }
```

Класс `gm-item` и способ сборки списка через `createElement` взяты из существующей `renderGroupMembers` (`schedule.blade.php:2300-2306`) — вёрстка совпадает один в один.

- [ ] **Step 3: Подключить ветку в `selectBookingType`**

В функции `selectBookingType` (`:2226`) после строки `const isGroup = input.value === 'group';` добавить:

```javascript
        const isTournament = input.value === 'tournament';
```

После блока `if (groupWrap) { ... }` добавить:

```javascript
        // Селект турнира — только при типе «Турнир»
        const tnWrap = document.getElementById('bookTournamentSelectWrap');
        if (tnWrap) {
            tnWrap.style.display = isTournament ? 'block' : 'none';
            if (!isTournament) {
                const sel = document.getElementById('bookTournamentSelect');
                if (sel) sel.value = '';
                renderTournamentInfo('');
            }
        }
```

Заменить три строки, скрывающие поля для группы, так, чтобы турнир вёл себя так же:

```javascript
        const hideClientFields = isGroup || isTournament;
        document.querySelectorAll('.js-hide-for-group').forEach(el => el.style.display = hideClientFields ? 'none' : '');
        document.querySelectorAll('.js-show-for-group').forEach(el => el.style.display = isGroup ? 'block' : 'none');
        ['bookClientName', 'bookClientPhone'].forEach(id => {
            const e = document.getElementById(id);
            if (e) { e.required = !hideClientFields; if (hideClientFields) e.value = ''; }
        });
        const cardWrap = document.getElementById('bookCardWrap');
        if (cardWrap) cardWrap.style.display = (!hideClientFields && (cardCache.book || []).length) ? '' : 'none';
```

- [ ] **Step 4: Проверить вручную**

Запустить `composer dev`, открыть `/club/courts/schedule`, выбрать свободный слот, нажать «Турнир». Убедиться: появился селект, поля клиента и оплаты скрылись, при выборе турнира показался расчёт и список участников. Сохранить бронь и убедиться, что в слоте видна ожидаемая сумма.

- [ ] **Step 5: Прогнать тесты**

Run: `php artisan test --filter=TournamentCourtBookingTest`
Expected: PASS, 15 тестов — вьюха не должна ничего сломать.

- [ ] **Step 6: Коммит**

```bash
git add resources/views/club/courts/schedule.blade.php
git commit -m "feat(courts): выбор турнира в модалке создания брони"
```

---

### Task 7: Турнир в модалке редактирования

**Files:**
- Modify: `resources/views/club/courts/schedule.blade.php:1010-1012` (кнопки типа брони в окне редактирования)
- Modify: `resources/views/club/courts/schedule.blade.php:2337-2367` (`selectEditBookingType`, `applyEditGroupVisibility`)

**Interfaces:**
- Consumes: `window.__tournaments` из Task 3, `$bookingTournamentIds` из Task 3, `renderTournamentInfo` из Task 6
- Produces: `applyEditTournamentVisibility(isTournament)`; поле формы `tournament_id` в окне редактирования

- [ ] **Step 1: Добавить блок в окно редактирования**

Сразу после `<input type="hidden" name="booking_type" id="editBookingTypeInput">` (`:1012`) вставить:

```blade
                        <div id="editTournamentBlock" style="display:none;">
                            <div class="modal-section-title">Турнир</div>
                            <div class="form-group">
                                <select name="tournament_id" id="editTournamentSelect" class="form-input">
                                    <option value="">— выберите турнир —</option>
                                    @foreach(($bookingTournaments ?? []) as $t)
                                        <option value="{{ $t['id'] }}">{{ $t['name'] }}{{ $t['date'] ? ' — ' . $t['date'] : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div id="editTnPrice" style="margin:8px 0;color:#a1a1aa;font-size:13px;"></div>
                        </div>
```

- [ ] **Step 2: Отдать карту связей в JS**

Рядом со строкой `<script>window.__tournaments = ...</script>` из Task 3 добавить:

```blade
<script>window.__bookingTournaments = @json($bookingTournamentIds ?? []);</script>
```

- [ ] **Step 3: Написать `applyEditTournamentVisibility`**

Рядом с `applyEditGroupVisibility` (`:2348`) добавить:

```javascript
    // Для турнирной брони в окне редактирования прячем поля клиента и оплаты —
    // как в окне создания: цену задаёт турнир, игроки платят взносы отдельно.
    function applyEditTournamentVisibility(isTournament) {
        document.querySelectorAll('.js-edit-hide-for-group').forEach(function (el) {
            el.style.display = isTournament ? 'none' : '';
        });
        const phone = document.getElementById('editClientPhone');
        if (phone) { phone.required = !isTournament; }
        const name = document.getElementById('editClientName');
        if (name) { name.readOnly = isTournament; }
        const label = document.getElementById('editClientLabel');
        if (label && isTournament) { label.textContent = 'Турнир'; }
        const block = document.getElementById('editTournamentBlock');
        if (block) block.style.display = isTournament ? 'block' : 'none';
        if (isTournament) { renderEditTournamentPrice(); }
    }

    // Расчёт под селектом в окне редактирования.
    function renderEditTournamentPrice() {
        const sel = document.getElementById('editTournamentSelect');
        const el = document.getElementById('editTnPrice');
        if (!sel || !el) return;
        const data = (window.__tournaments && window.__tournaments[sel.value]) || null;
        if (!data) { el.innerHTML = ''; return; }
        const fmt = n => new Intl.NumberFormat('ru-RU').format(n);
        el.innerHTML = data.price
            ? (data.paid_count + ' оплативших × ' + fmt(data.price) + ' = <b>' + fmt(data.total) + ' ₸</b> на все корты турнира в этот день')
            : 'У турнира не указана цена';
    }
```

- [ ] **Step 4: Подключить ветку в `selectEditBookingType`**

Заменить тело `selectEditBookingType` (`:2337`) — последнюю строку:

```javascript
        applyEditGroupVisibility(input.value === 'group');
```

на:

```javascript
        applyEditGroupVisibility(input.value === 'group');
        applyEditTournamentVisibility(input.value === 'tournament');
```

Повесить пересчёт на смену турнира — в блоке из Step 1 у селекта добавить атрибут `onchange="renderEditTournamentPrice()"`.

- [ ] **Step 5: Подставлять турнир при открытии окна**

В функции открытия окна редактирования, сразу после блока «Тип брони» (`schedule.blade.php:1721-1726`, где заполняется `editBookingTypeInput`), добавить:

```javascript
        // Подставляем турнир открытой брони, если он есть.
        const tnSelect = document.getElementById('editTournamentSelect');
        if (tnSelect) {
            tnSelect.value = (window.__bookingTournaments && window.__bookingTournaments[data.id]) || '';
        }
        applyEditTournamentVisibility(btVal === 'tournament');
```

`data.id` — id открываемой брони, он же присваивается в `editingBookingId` строкой выше (`:1712`); `btVal` — переменная типа брони из того же блока.

- [ ] **Step 6: Проверить вручную**

Открыть турнирную бронь на редактирование: турнир подставлен, поля клиента скрыты, расчёт виден. Сменить турнир, сохранить, убедиться, что цена пересчиталась.

- [ ] **Step 7: Прогнать тесты**

Run: `php artisan test --filter=TournamentCourtBookingTest`
Expected: PASS, 15 тестов.

- [ ] **Step 8: Коммит**

```bash
git add resources/views/club/courts/schedule.blade.php
git commit -m "feat(courts): турнир в окне редактирования брони"
```

---

### Task 8: Недельный вид

**Files:**
- Modify: `resources/views/club/courts/schedule_week.blade.php`

**Interfaces:**
- Consumes: всё из Task 6 и Task 7
- Produces: та же функциональность в недельном расписании

- [ ] **Step 1: Найти места вставки**

Выполнить, чтобы найти якоря — блок групп, функции выбора типа брони и вывод `__groupMembers`:

```bash
grep -n "bookGroupSelectWrap\|selectBookingType\|applyEditGroupVisibility\|__groupMembers\|editBookingTypeInput" resources/views/club/courts/schedule_week.blade.php
```

- [ ] **Step 2: Перенести блоки и функции**

Повторить в `schedule_week.blade.php` то же, что сделано в Task 6 и Task 7:

- блок `bookTournamentSelectWrap` — после блока групп в модалке создания;
- блок `editTournamentBlock` — после `editBookingTypeInput` в модалке редактирования;
- функции `renderTournamentInfo`, `applyEditTournamentVisibility`, `renderEditTournamentPrice`;
- ветки `isTournament` в `selectBookingType` и `selectEditBookingType`;
- подстановку турнира при открытии окна редактирования.

Код берётся из Task 6 и Task 7 без изменений, кроме получения даты. В недельном виде дата не одна на страницу — её задаёт выбранный слот. Найти скрытое поле даты формы брони:

```bash
grep -n 'name="date"' resources/views/club/courts/schedule_week.blade.php
```

Если у поля нет `id`, добавить его (`id="weekBookDate"`) и в недельной версии `renderTournamentInfo` заменить строку получения даты на:

```javascript
        const dateInput = document.getElementById('weekBookDate');
        const date = dateInput ? dateInput.value : (window.__scheduleDate || '');
```

Остальной код функции идентичен дневному.

- [ ] **Step 3: Проверить вручную**

Открыть `/club/courts/schedule/week`, создать турнирную бронь, затем открыть её на редактирование. Убедиться, что поведение совпадает с дневным видом.

- [ ] **Step 4: Прогнать тесты**

Run: `php artisan test --filter=TournamentCourtBookingTest`
Expected: PASS, 15 тестов.

- [ ] **Step 5: Прогнать смежные сьюты, чтобы убедиться, что ничего не сломано**

Run: `php artisan test --filter=CourtSchedule` затем `php artisan test --filter=ClubGroupSession` и `php artisan test --filter=ClubCardBooking`
Expected: результат не хуже, чем до работы. Помнить про 2 давно падающих теста `CourtScheduleTest` (`calculate price …` — устаревшая сигнатура, к этой задаче отношения не имеют).

- [ ] **Step 6: Коммит**

```bash
git add resources/views/club/courts/schedule_week.blade.php
git commit -m "feat(courts): выбор турнира в недельном расписании"
```

---

## Деплой на прод

После мержа выполнить на сервере:

```bash
git pull
php artisan migrate --path=database/migrations/2026_08_07_000001_add_tournament_id_to_court_bookings.php
php artisan config:clear && php artisan view:clear
npm run build
```

Миграция только добавляет колонку и индекс — данные не трогает, откат безопасен (`migrate:rollback --step=1`).

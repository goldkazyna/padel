# Ручное списание клубных карт — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сделать списание клубных карт-счётчиков ручным (как групповые занятия): убрать авто-крон, показать очередь «к списанию» с бейджем в разделе «Клубные карты», действия «Списать»/«Не списывать».

**Architecture:** Логика в `ClubCardService` (новый `skipBooking`, запрос `pendingForClub`/`pendingCountForClub`, хелпер `bookingEnded`). HTTP — методы `pending`/`charge`/`skip` в `ClubCardController` + маршруты. UI — кнопка-бейдж в `club/cards/index.blade.php` и страница очереди `club/cards/pending.blade.php`. Крон `cards:charge-due` снимается с расписания.

**Tech Stack:** Laravel 12, Blade, PHPUnit (SQLite :memory:), Carbon.

## Global Constraints

- Скоуп — только свой клуб: через `getClub()` и корты клуба; чужая бронь → `abort(403)`.
- Списываются только карты-счётчики (`ClubCardType::isCounter()` → kind `visits`/`trainer`); скидочные в очередь не попадают.
- `chargeBooking()` и `skipBooking()` идемпотентны (повторный вызов — no-op по `card_charged_at`).
- Колонок в БД не добавляем: `card_charged_at` и таблица транзакций уже существуют.
- Время брони хранится в локальном TZ клуба; «завершённость» считать через `config('app.schedule_timezone', 'Asia/Almaty')`, как в `ChargeDueCards::ended()`.
- Тесты — TDD, частые коммиты.

---

### Task 1: `ClubCardService::skipBooking()` — пометить без списания

**Files:**
- Modify: `app/Services/ClubCardService.php`
- Test: `tests/Feature/ClubCardChargeTest.php`

**Interfaces:**
- Consumes: `ClubCard`, `ClubCardTransaction`, `CourtBooking` (уже импортированы в сервисе).
- Produces: `ClubCardService::skipBooking(CourtBooking $booking): ?ClubCardTransaction` — ставит `card_charged_at=now()` без вычитания баланса, пишет транзакцию `amount=0`, `note='Не списано (пропущено)'`. Идемпотентно.

- [ ] **Step 1: Написать падающие тесты**

В `tests/Feature/ClubCardChargeTest.php` добавить методы:

```php
    public function test_skip_marks_handled_without_deduction(): void
    {
        [$club, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '10 ч', 'code_prefix' => 'VIS', 'kind' => 'visits', 'nominal' => 10]);
        $card = (new ClubCardService())->issue($client, $type);
        $b = $this->booking($court, $card->id, now()->subDay()->toDateString(), '10:00', '12:00');

        $tx = (new ClubCardService())->skipBooking($b);

        $this->assertNotNull($tx);
        $this->assertSame(0, $tx->amount);
        $this->assertSame('Не списано (пропущено)', $tx->note);
        $this->assertSame(10, (int) $card->fresh()->balance, 'баланс не тронут');
        $this->assertNotNull($b->fresh()->card_charged_at, 'помечена обработанной');
    }

    public function test_skip_is_idempotent(): void
    {
        [$club, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '10 ч', 'code_prefix' => 'VIS', 'kind' => 'visits', 'nominal' => 10]);
        $card = (new ClubCardService())->issue($client, $type);
        $b = $this->booking($court, $card->id, now()->subDay()->toDateString(), '10:00', '11:00');

        (new ClubCardService())->skipBooking($b);
        (new ClubCardService())->skipBooking($b->fresh());

        $this->assertSame(1, ClubCardTransaction::count(), 'повторный skip не пишет вторую транзакцию');
        $this->assertSame(10, (int) $card->fresh()->balance);
    }
```

- [ ] **Step 2: Запустить тесты — убедиться, что падают**

Run: `php artisan test --filter='ClubCardChargeTest::test_skip'`
Expected: FAIL — `Call to undefined method App\Services\ClubCardService::skipBooking()`.

- [ ] **Step 3: Реализовать `skipBooking`**

В `app/Services/ClubCardService.php` добавить метод (после `chargeBooking`, рядом с `markCharged`):

```php
    /**
     * Пометить бронь обработанной БЕЗ списания (ошибочная бронь / бесплатное
     * занятие). Идемпотентно. Пишет нулевую транзакцию для аудита.
     */
    public function skipBooking(CourtBooking $booking): ?ClubCardTransaction
    {
        if (!$booking->club_card_id) return null;
        if ($booking->card_charged_at) return null; // уже обработана

        $card = ClubCard::find($booking->club_card_id);

        return DB::transaction(function () use ($booking, $card) {
            $freshBooking = CourtBooking::lockForUpdate()->find($booking->id);
            if (!$freshBooking || $freshBooking->card_charged_at) return null;

            $tx = null;
            if ($card) {
                $tx = ClubCardTransaction::create([
                    'club_id' => $card->club_id,
                    'club_card_id' => $card->id,
                    'court_booking_id' => $freshBooking->id,
                    'amount' => 0,
                    'balance_after' => (int) $card->balance,
                    'note' => 'Не списано (пропущено)',
                ]);
            }

            $freshBooking->forceFill(['card_charged_at' => now()])->save();
            return $tx;
        });
    }
```

- [ ] **Step 4: Запустить тесты — убедиться, что проходят**

Run: `php artisan test --filter='ClubCardChargeTest::test_skip'`
Expected: PASS (2 теста).

- [ ] **Step 5: Коммит**

```bash
git add app/Services/ClubCardService.php tests/Feature/ClubCardChargeTest.php
git commit -m "feat(club-cards): ClubCardService::skipBooking — пометить бронь без списания"
```

---

### Task 2: Очередь «к списанию» в сервисе

**Files:**
- Modify: `app/Services/ClubCardService.php`
- Test: `tests/Feature/ClubCardChargeTest.php`

**Interfaces:**
- Consumes: `Club` (добавить `use App\Models\Club;`), `CourtBooking`.
- Produces:
  - `ClubCardService::bookingEnded(CourtBooking $booking, ?\Carbon\Carbon $now = null): bool`
  - `ClubCardService::pendingForClub(Club $club): \Illuminate\Support\Collection` — брони клуба к ручному списанию: счётчик-карта, `confirmed`, время прошло, `card_charged_at IS NULL`. Каждая бронь с загруженными `clubCard.type`, `clubCard.client`, `court`.
  - `ClubCardService::pendingCountForClub(Club $club): int`

- [ ] **Step 1: Написать падающий тест**

В `tests/Feature/ClubCardChargeTest.php` добавить:

```php
    public function test_pending_for_club_lists_only_chargeable_ended_bookings(): void
    {
        [$club, $court, $client] = $this->scene();
        $counter = ClubCardType::create(['club_id' => $club->id, 'name' => '10 ч', 'code_prefix' => 'VIS', 'kind' => 'visits', 'nominal' => 10]);
        $discount = ClubCardType::create(['club_id' => $club->id, 'name' => 'VIP', 'code_prefix' => 'VIP', 'kind' => 'discount_court', 'discount_percent' => 20]);
        $svc = new ClubCardService();
        $cCard = $svc->issue($client, $counter);
        $dCard = $svc->issue($client, $discount);

        $yesterday = now()->subDay()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $past      = $this->booking($court, $cCard->id, $yesterday, '10:00', '11:00');             // ✓ в очереди
        $future    = $this->booking($court, $cCard->id, $tomorrow, '10:00', '11:00');              // ✗ не закончилась
        $discountB = $this->booking($court, $dCard->id, $yesterday, '10:00', '11:00');             // ✗ скидочная
        $cancelled = $this->booking($court, $cCard->id, $yesterday, '10:00', '11:00', 'cancelled'); // ✗ отменена
        $already   = $this->booking($court, $cCard->id, $yesterday, '12:00', '13:00');             // ✗ уже обработана
        $svc->chargeBooking($already);

        $pending = $svc->pendingForClub($club->fresh());
        $ids = $pending->pluck('id');

        $this->assertSame(1, $pending->count());
        $this->assertTrue($ids->contains($past->id));
        $this->assertFalse($ids->contains($future->id));
        $this->assertFalse($ids->contains($discountB->id));
        $this->assertFalse($ids->contains($cancelled->id));
        $this->assertFalse($ids->contains($already->id));
        $this->assertSame(1, $svc->pendingCountForClub($club->fresh()));
    }
```

- [ ] **Step 2: Запустить тест — убедиться, что падает**

Run: `php artisan test --filter='ClubCardChargeTest::test_pending_for_club'`
Expected: FAIL — `Call to undefined method App\Services\ClubCardService::pendingForClub()`.

- [ ] **Step 3: Реализовать методы**

В `app/Services/ClubCardService.php` добавить импорт в шапку (рядом с другими `use App\Models\...`):

```php
use App\Models\Club;
```

И методы (например, после `chargeBooking`/`skipBooking`):

```php
    /**
     * Бронь действительно завершилась: дата+время окончания уже в прошлом.
     * Время хранится в локальном TZ клуба.
     */
    public function bookingEnded(CourtBooking $booking, ?Carbon $now = null): bool
    {
        $now ??= now();
        $date = $booking->date instanceof Carbon
            ? $booking->date->format('Y-m-d')
            : (string) $booking->date;
        $tz = config('app.schedule_timezone', 'Asia/Almaty');
        $end = Carbon::parse($date . ' ' . substr((string) $booking->end_time, 0, 5), $tz);

        return $end->lessThanOrEqualTo($now);
    }

    /**
     * Брони клуба, ожидающие РУЧНОГО списания: карта-счётчик, confirmed,
     * время прошло, ещё не обработана. Скидочные карты исключаем.
     *
     * @return \Illuminate\Support\Collection<int, CourtBooking>
     */
    public function pendingForClub(Club $club): \Illuminate\Support\Collection
    {
        $courtIds = $club->courts()->pluck('id');
        $now = now();

        return CourtBooking::whereIn('court_id', $courtIds)
            ->whereNotNull('club_card_id')
            ->whereNull('card_charged_at')
            ->where('status', 'confirmed')
            ->whereDate('date', '<=', $now->toDateString())
            ->with(['clubCard.type', 'clubCard.client', 'court:id,name'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->filter(fn (CourtBooking $b) => $b->clubCard
                && $b->clubCard->isCounter()
                && $this->bookingEnded($b, $now))
            ->values();
    }

    public function pendingCountForClub(Club $club): int
    {
        return $this->pendingForClub($club)->count();
    }
```

- [ ] **Step 4: Запустить тест — убедиться, что проходит**

Run: `php artisan test --filter='ClubCardChargeTest::test_pending_for_club'`
Expected: PASS.

- [ ] **Step 5: Коммит**

```bash
git add app/Services/ClubCardService.php tests/Feature/ClubCardChargeTest.php
git commit -m "feat(club-cards): pendingForClub/pendingCountForClub + bookingEnded в сервисе"
```

---

### Task 3: Снять `cards:charge-due` с расписания

**Files:**
- Modify: `bootstrap/app.php:40-44` (блок `$schedule->command('cards:charge-due')`)
- Test: `tests/Feature/ClubCardScheduleTest.php` (Create)

**Interfaces:**
- Consumes: ничего нового.
- Produces: команда `cards:charge-due` больше не в расписании (auto-charge не происходит). Сам класс `App\Console\Commands\ChargeDueCards` остаётся в коде для ручного запуска.

- [ ] **Step 1: Написать падающий тест**

Create `tests/Feature/ClubCardScheduleTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ClubCardScheduleTest extends TestCase
{
    public function test_cards_charge_due_is_not_scheduled(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        // sanity: расписание вообще загрузилось
        $this->assertStringContainsString('tournaments:process-moderation', $output);
        // авто-списание карт снято
        $this->assertStringNotContainsString('cards:charge-due', $output);
    }
}
```

- [ ] **Step 2: Запустить тест — убедиться, что падает**

Run: `php artisan test --filter=ClubCardScheduleTest`
Expected: FAIL — вывод `schedule:list` ещё содержит `cards:charge-due`.

- [ ] **Step 3: Убрать блок из расписания**

В `bootstrap/app.php` удалить блок:

```php
        // Списание часов клубных карт за завершённые брони.
        $schedule->command('cards:charge-due')
            ->hourly()
            ->withoutOverlapping();
```

(Остальные `$schedule->command(...)` — `tournaments:*`, `backup:run` — не трогать.)

- [ ] **Step 4: Запустить тест — убедиться, что проходит**

Run: `php artisan test --filter=ClubCardScheduleTest`
Expected: PASS.

- [ ] **Step 5: Коммит**

```bash
git add bootstrap/app.php tests/Feature/ClubCardScheduleTest.php
git commit -m "chore(club-cards): убрать авто-списание cards:charge-due из расписания"
```

---

### Task 4: Контроллер `pending`/`charge`/`skip` + маршруты

**Files:**
- Modify: `app/Http/Controllers/Club/ClubCardController.php`
- Modify: `routes/web.php` (рядом с другими `cards.*`, ~строки 296-300)
- Test: `tests/Feature/ClubCardPendingTest.php` (Create)

**Interfaces:**
- Consumes: `ClubCardService::pendingForClub`, `chargeBooking`, `skipBooking` (Task 1-2); `CourtBooking`.
- Produces:
  - `ClubCardController::pending(ClubCardService $service)` → view `club.cards.pending` с `compact('club', 'bookings')`.
  - `ClubCardController::charge(CourtBooking $booking, ClubCardService $service)` → `back()` с flash.
  - `ClubCardController::skip(CourtBooking $booking, ClubCardService $service)` → `back()` с flash.
  - Маршруты: `club.cards.pending` (GET), `club.cards.pending.charge` (POST), `club.cards.pending.skip` (POST).

- [ ] **Step 1: Написать падающие тесты**

Create `tests/Feature/ClubCardPendingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardTransaction;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Services\ClubCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubCardPendingTest extends TestCase
{
    use RefreshDatabase;

    private function scene(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K1', 'is_active' => true, 'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван', 'phone' => '77770001122']);
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '10 ч', 'code_prefix' => 'VIS', 'kind' => 'visits', 'nominal' => 10]);
        $card = (new ClubCardService())->issue($client, $type);
        return [$club, $admin, $court, $card];
    }

    private function endedBooking(Court $court, int $cardId): CourtBooking
    {
        return CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->subDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '12:00',
            'client_name' => 'Иван', 'client_phone' => '77770001122',
            'booked_by' => null, 'price' => 0, 'status' => 'confirmed',
            'club_card_id' => $cardId,
        ]);
    }

    public function test_pending_page_lists_booking(): void
    {
        [$club, $admin, $court, $card] = $this->scene();
        $b = $this->endedBooking($court, $card->id);

        $this->actingAs($admin)->get(route('club.cards.pending'))
            ->assertOk()
            ->assertSee('Иван');
    }

    public function test_charge_action_deducts_hours(): void
    {
        [$club, $admin, $court, $card] = $this->scene();
        $b = $this->endedBooking($court, $card->id);

        $this->actingAs($admin)
            ->post(route('club.cards.pending.charge', $b))
            ->assertRedirect();

        $this->assertSame(8, (int) $card->fresh()->balance);
        $this->assertNotNull($b->fresh()->card_charged_at);
    }

    public function test_skip_action_marks_without_deduction(): void
    {
        [$club, $admin, $court, $card] = $this->scene();
        $b = $this->endedBooking($court, $card->id);

        $this->actingAs($admin)
            ->post(route('club.cards.pending.skip', $b))
            ->assertRedirect();

        $this->assertSame(10, (int) $card->fresh()->balance);
        $this->assertNotNull($b->fresh()->card_charged_at);
        $this->assertSame(1, ClubCardTransaction::where('amount', 0)->count());
    }

    public function test_other_club_booking_forbidden(): void
    {
        [$club, $admin, $court, $card] = $this->scene();

        $otherClub = Club::create(['name' => 'X', 'address' => 'Y']);
        $otherCourt = Court::create(['club_id' => $otherClub->id, 'name' => 'KX', 'is_active' => true, 'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60]);
        $foreign = $this->endedBooking($otherCourt, $card->id);

        $this->actingAs($admin)
            ->post(route('club.cards.pending.charge', $foreign))
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Запустить тесты — убедиться, что падают**

Run: `php artisan test --filter=ClubCardPendingTest`
Expected: FAIL — маршрут `club.cards.pending` не существует.

- [ ] **Step 3: Добавить маршруты**

В `routes/web.php` в группе карт (рядом со строкой `cards.journal`) добавить:

```php
            Route::get('/cards/pending', [App\Http\Controllers\Club\ClubCardController::class, 'pending'])->name('cards.pending');
            Route::post('/cards/pending/{booking}/charge', [App\Http\Controllers\Club\ClubCardController::class, 'charge'])->name('cards.pending.charge');
            Route::post('/cards/pending/{booking}/skip', [App\Http\Controllers\Club\ClubCardController::class, 'skip'])->name('cards.pending.skip');
```

- [ ] **Step 4: Добавить методы контроллера**

В `app/Http/Controllers/Club/ClubCardController.php` добавить импорт:

```php
use App\Models\CourtBooking;
```

И методы (перед `destroy`):

```php
    /** Очередь броней к ручному списанию с карты. */
    public function pending(ClubCardService $service)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $bookings = $service->pendingForClub($club);

        return view('club.cards.pending', compact('club', 'bookings'));
    }

    /** Списать часы карты за бронь (ручное действие). */
    public function charge(CourtBooking $booking, ClubCardService $service)
    {
        $club = $this->getClub();
        $this->authorizeBooking($club, $booking);

        $service->chargeBooking($booking);

        return back()->with('success', 'Списано с карты');
    }

    /** Пометить бронь обработанной без списания. */
    public function skip(CourtBooking $booking, ClubCardService $service)
    {
        $club = $this->getClub();
        $this->authorizeBooking($club, $booking);

        $service->skipBooking($booking);

        return back()->with('success', 'Бронь помечена без списания');
    }

    /** Бронь должна принадлежать корту своего клуба. */
    private function authorizeBooking($club, CourtBooking $booking): void
    {
        if (!$club) abort(403);
        $courtIds = $club->courts()->pluck('id')->all();
        if (!in_array($booking->court_id, $courtIds, true)) abort(403);
    }
```

- [ ] **Step 5: Запустить тесты — убедиться, что проходят (после Task 5 view готова)**

> Примечание: `test_pending_page_lists_booking` требует view из Task 5. Остальные 3 теста (charge/skip/403) проходят уже сейчас. Запусти их:

Run: `php artisan test --filter='ClubCardPendingTest::test_charge_action_deducts_hours|ClubCardPendingTest::test_skip_action_marks_without_deduction|ClubCardPendingTest::test_other_club_booking_forbidden'`
Expected: PASS (3 теста).

- [ ] **Step 6: Коммит**

```bash
git add app/Http/Controllers/Club/ClubCardController.php routes/web.php tests/Feature/ClubCardPendingTest.php
git commit -m "feat(club-cards): контроллер pending/charge/skip + маршруты"
```

---

### Task 5: UI — кнопка-бейдж + страница очереди

**Files:**
- Modify: `app/Http/Controllers/Club/ClubCardTypeController.php` (метод `index`)
- Modify: `resources/views/club/cards/index.blade.php:8-11` (блок `cards-header-actions`)
- Create: `resources/views/club/cards/pending.blade.php`
- Test: `tests/Feature/ClubCardPendingTest.php` (уже создан в Task 4)

**Interfaces:**
- Consumes: `ClubCardService::pendingCountForClub` (Task 2), `pendingForClub` (через контроллер из Task 4).
- Produces: переменная `$pendingChargeCount` во view `club.cards.index`; страница `club.cards.pending`.

- [ ] **Step 1: Написать падающий тест бейджа**

В `tests/Feature/ClubCardPendingTest.php` добавить:

```php
    public function test_index_shows_pending_badge_count(): void
    {
        [$club, $admin, $court, $card] = $this->scene();
        $this->endedBooking($court, $card->id);
        $this->endedBooking($court, $card->id);

        $this->actingAs($admin)->get(route('club.cards.index'))
            ->assertOk()
            ->assertSee('К списанию')
            ->assertSee('2'); // бейдж = 2 брони
    }
```

- [ ] **Step 2: Запустить тест — убедиться, что падает**

Run: `php artisan test --filter='ClubCardPendingTest::test_index_shows_pending_badge_count'`
Expected: FAIL — текста «К списанию» на странице нет.

- [ ] **Step 3: Прокинуть счётчик из контроллера**

В `app/Http/Controllers/Club/ClubCardTypeController.php` изменить сигнатуру и тело `index`:

Сигнатуру:
```php
    public function index(\App\Services\ClubCardService $cardService)
```

Перед `return view(...)` добавить:
```php
        $pendingChargeCount = $cardService->pendingCountForClub($club);
```

И в `compact(...)` добавить `'pendingChargeCount'`:
```php
        return view('club.cards.index', compact('club', 'types', 'issuedCount', 'actualCount', 'issuedCards', 'pendingChargeCount'));
```

- [ ] **Step 4: Добавить кнопку-бейдж во view индекса**

В `resources/views/club/cards/index.blade.php` в блоке `cards-header-actions` (между «Журнал» и «+ Создать тип карты») вставить:

```blade
            <a href="{{ route('club.cards.pending') }}" class="btn-journal" style="position:relative">
                К списанию
                @if($pendingChargeCount > 0)
                    <span style="display:inline-block;min-width:18px;padding:0 5px;margin-left:6px;border-radius:9px;background:#ef4444;color:#fff;font-size:12px;font-weight:700;text-align:center;line-height:18px">{{ $pendingChargeCount }}</span>
                @endif
            </a>
```

- [ ] **Step 5: Создать страницу очереди**

Create `resources/views/club/cards/pending.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'К списанию')

@section('content')
<div class="cards-page">
    <div class="cards-header">
        <h1 class="cards-title">К списанию <span class="cards-title-club">— {{ $club->name }}</span></h1>
        <div class="cards-header-actions">
            <a href="{{ route('club.cards.index') }}" class="btn-journal">← Клубные карты</a>
        </div>
    </div>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash-message flash-error">{{ session('error') }}</div>@endif

    @if($bookings->isEmpty())
        <div class="cards-empty">Нет броней к списанию.</div>
    @else
    <table class="table" style="width:100%;border-collapse:collapse">
        <thead>
            <tr style="text-align:left">
                <th style="padding:8px">Клиент</th>
                <th style="padding:8px">Карта</th>
                <th style="padding:8px">Дата</th>
                <th style="padding:8px">Время</th>
                <th style="padding:8px">Часов</th>
                <th style="padding:8px">Остаток</th>
                <th style="padding:8px">Действие</th>
            </tr>
        </thead>
        <tbody>
            @foreach($bookings as $b)
            @php
                $card = $b->clubCard;
                $hours = (int) round(max(0, \Carbon\Carbon::parse(substr($b->start_time,0,5))->diffInMinutes(\Carbon\Carbon::parse(substr($b->end_time,0,5)))) / 60);
            @endphp
            <tr style="border-top:1px solid #2a2a2a">
                <td style="padding:8px">{{ $card?->client?->name ?? $b->client_name }}</td>
                <td style="padding:8px">{{ $card?->code }} <span style="color:#888">{{ $card?->type?->name }}</span></td>
                <td style="padding:8px">{{ \Carbon\Carbon::parse($b->date)->format('d.m.Y') }}</td>
                <td style="padding:8px">{{ substr($b->start_time,0,5) }}–{{ substr($b->end_time,0,5) }}</td>
                <td style="padding:8px">{{ $hours }} ч</td>
                <td style="padding:8px">{{ $card?->balance }}</td>
                <td style="padding:8px;white-space:nowrap">
                    <form action="{{ route('club.cards.pending.charge', $b) }}" method="POST" class="d-inline">
                        @csrf
                        <button class="btn-add" type="submit" onclick="return confirm('Списать {{ $hours }} ч с карты {{ $card?->code }}?')">Списать</button>
                    </form>
                    <form action="{{ route('club.cards.pending.skip', $b) }}" method="POST" class="d-inline">
                        @csrf
                        <button class="btn-journal" type="submit" onclick="return confirm('Пометить без списания?')">Не списывать</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>
@endsection
```

- [ ] **Step 6: Запустить весь набор тестов карт — убедиться, что всё зелёное**

Run: `php artisan test --filter='ClubCardPendingTest|ClubCardChargeTest|ClubCardScheduleTest'`
Expected: PASS (все тесты, включая `test_pending_page_lists_booking` и `test_index_shows_pending_badge_count`).

- [ ] **Step 7: Коммит**

```bash
git add app/Http/Controllers/Club/ClubCardTypeController.php resources/views/club/cards/index.blade.php resources/views/club/cards/pending.blade.php tests/Feature/ClubCardPendingTest.php
git commit -m "feat(club-cards): кнопка-бейдж «К списанию» + страница очереди списания"
```

---

## Деплой

Чистый код, миграций нет:
```bash
git pull origin main
php artisan config:clear
```
Системный `schedule:run` не меняется — расписание перечитается из `bootstrap/app.php` (крон `cards:charge-due` больше не запускается).

## Self-Review (выполнено при написании плана)

- **Покрытие спеки:** убрать крон (Task 3 ✓), очередь живым запросом (Task 2 ✓), бейдж+кнопка в «Клубные карты» (Task 5 ✓), действия списать/не списывать (Task 1, 4 ✓), скидочные исключены (Task 2 тест ✓), скоуп клуба/403 (Task 4 тест ✓).
- **Плейсхолдеров нет:** весь код приведён.
- **Согласованность типов:** `pendingForClub(Club): Collection`, `pendingCountForClub(Club): int`, `skipBooking(CourtBooking): ?ClubCardTransaction`, `bookingEnded(CourtBooking, ?Carbon): bool` — имена едины во всех задачах.

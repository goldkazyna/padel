# Таймер модерации заявок на турнир — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Дать турниру необязательный «таймер модерации» (в часах): неоплаченная (= неодобренная) заявка авто-отменяется по дедлайну, а первый из листа ожидания авто-продвигается; игрок видит локальный countdown.

**Architecture:** Дедлайн хранится явно на участнике/паре. Cron-команда раз в минуту отменяет просрочку (`cancelled`/`rejected`), продвигает лист ожидания и шлёт пуши (подача/напоминание/удаление). Приложение тикает отсчёт локально от `moderation_deadline` (без вебсокетов).

**Tech Stack:** Laravel 12 (Eloquent, Artisan console, scheduler в `bootstrap/app.php`, FCMNotificationService), PHPUnit; Flutter (Timer.periodic, provider).

**Spec:** `docs/superpowers/specs/2026-05-30-moderation-timer-design.md`

**Соглашения:** ветку не меняем (работаем в `main`, как принято в проекте). Каждый бэкенд-коммит — `git push` по готовности раздела. Тесты бэкенда: `vendor/bin/phpunit --filter <name>`. Flutter: `cd /c/projects/padel_app && flutter analyze <files>`.

---

## BACKEND (`C:\projects\padel`)

### Task 1: Миграция — поля таймера

**Files:**
- Create: `database/migrations/2026_05_31_000001_add_moderation_timer.php`

- [ ] **Step 1: Создать миграцию**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedInteger('moderation_hours')->nullable()->after('waitlist_size');
        });
        Schema::table('tournament_participants', function (Blueprint $table) {
            $table->timestamp('moderation_deadline')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
        });
        Schema::table('tournament_teams', function (Blueprint $table) {
            $table->timestamp('moderation_deadline')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', fn (Blueprint $t) => $t->dropColumn('moderation_hours'));
        Schema::table('tournament_participants', fn (Blueprint $t) => $t->dropColumn(['moderation_deadline', 'reminder_sent_at']));
        Schema::table('tournament_teams', fn (Blueprint $t) => $t->dropColumn(['moderation_deadline', 'reminder_sent_at']));
    }
};
```

- [ ] **Step 2: Применить миграцию**

Run: `php artisan migrate --path=database/migrations/2026_05_31_000001_add_moderation_timer.php`
Expected: `DONE`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_31_000001_add_moderation_timer.php
git commit -m "feat(moderation): миграция полей таймера модерации"
```

---

### Task 2: Модель Tournament — fillable, cast, хелпер дедлайна, pivot

**Files:**
- Modify: `app/Models/Tournament.php`

- [ ] **Step 1: Добавить `moderation_hours` в `$fillable`** (рядом с `waitlist_size`) и в `$casts`:

```php
'moderation_hours' => 'integer',
```

- [ ] **Step 2: Расширить relation `participants()` новыми pivot-колонками**

Найти метод `participants()` и заменить `->withPivot('status')` на:

```php
->withPivot('status', 'moderation_deadline', 'reminder_sent_at')
```

- [ ] **Step 3: Добавить хелпер дедлайна** (в любом месте класса, рядом с другими методами):

```php
/** Дедлайн модерации для новой pending-заявки (или null, если таймер выключен). */
public function moderationDeadline(): ?\Carbon\Carbon
{
    $hours = (int) ($this->moderation_hours ?? 0);
    return $hours > 0 ? now()->addHours($hours) : null;
}
```

- [ ] **Step 4: Проверить синтаксис**

Run: `php -l app/Models/Tournament.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Tournament.php
git commit -m "feat(moderation): Tournament — moderation_hours, дедлайн-хелпер, pivot"
```

---

### Task 3: Сервис уведомлений модерации

**Files:**
- Create: `app/Services/ModerationNotifier.php`
- Test: `tests/Feature/ModerationNotifierTest.php`

- [ ] **Step 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use App\Models\Notification;
use App\Services\ModerationNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ModerationNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_notification_created(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        $user = User::factory()->create();

        app(ModerationNotifier::class)->pending($user, $t, now()->addHours(48));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id, 'type' => 'tournament_moderation_pending',
        ]);
    }
}
```

- [ ] **Step 2: Запустить — упадёт**

Run: `vendor/bin/phpunit --filter ModerationNotifierTest`
Expected: FAIL (класс `ModerationNotifier` не найден).

- [ ] **Step 3: Реализовать сервис**

```php
<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Tournament;
use App\Models\User;
use Carbon\Carbon;

class ModerationNotifier
{
    public function __construct(private FCMNotificationService $fcm) {}

    public function pending(User $user, Tournament $t, Carbon $deadline): void
    {
        $when = $deadline->copy()->timezone('Asia/Almaty')->format('d.m H:i');
        $this->send($user, $t, 'tournament_moderation_pending',
            'Заявка на модерации',
            "Оплатите участие в «{$t->name}» до {$when}, иначе заявку снимут");
    }

    public function reminder(User $user, Tournament $t, Carbon $deadline): void
    {
        $left = now()->diffForHumans($deadline, ['parts' => 2, 'short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE]);
        $this->send($user, $t, 'tournament_moderation_reminder',
            'Скоро снимем заявку',
            "Осталось {$left} — оплатите участие в «{$t->name}»");
    }

    public function expired(User $user, Tournament $t): void
    {
        $this->send($user, $t, 'tournament_moderation_expired',
            'Заявка снята',
            "Заявка на «{$t->name}» снята — оплата не поступила вовремя");
    }

    private function send(User $user, Tournament $t, string $type, string $title, string $body): void
    {
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'category' => 'tournament',
            'data' => ['tournament_id' => $t->id],
        ]);
        try {
            $this->fcm->sendToUser($user, $title, $body, [
                'type' => $type,
                'tournament_id' => (string) $t->id,
            ]);
        } catch (\Throwable $e) {
            // пуш не критичен
        }
    }
}
```

- [ ] **Step 4: Запустить — пройдёт**

Run: `vendor/bin/phpunit --filter ModerationNotifierTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ModerationNotifier.php tests/Feature/ModerationNotifierTest.php
git commit -m "feat(moderation): сервис уведомлений модерации"
```

---

### Task 4: Сохранение `moderation_hours` в формах турнира

**Files:**
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentController.php` (метод `store`, и `update` если есть в `MobileAdminTournamentDetailController`)
- Modify: `app/Http/Controllers/Club/TournamentController.php` (`store` + `update`)
- Test: `tests/Feature/ModerationTimerStoreTest.php`

- [ ] **Step 1: Тест — mobile admin store сохраняет moderation_hours**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationTimerStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_admin_store_saves_moderation_hours(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", [
            'name' => 'Кубок', 'type' => 'americano', 'max_participants' => 8,
            'start_date' => now()->addDays(3)->toIso8601String(),
            'registration_deadline' => now()->addDay()->toIso8601String(),
            'moderation_hours' => 48,
        ])->assertOk();

        $this->assertSame(48, (int) Tournament::first()->moderation_hours);
    }
}
```

> Примечание: если поля `registration_deadline`/`start_date` в этом эндпоинте называются иначе или формат другой — посмотреть валидатор в `MobileAdminTournamentController@store` и привести тело запроса к нему. Главная проверка — что `moderation_hours` сохранился.

- [ ] **Step 2: Запустить — упадёт**

Run: `vendor/bin/phpunit --filter ModerationTimerStoreTest`
Expected: FAIL (moderation_hours null).

- [ ] **Step 3: Добавить валидацию+сохранение в `MobileAdminTournamentController@store`**

В массив правил валидатора (рядом с `'waitlist_size'`) добавить:

```php
'moderation_hours' => 'nullable|integer|min:0|max:720',
```

`$validated` уже идёт в `Tournament::create(...)` — `moderation_hours` подхватится (поле в `$fillable`). Если в этом контроллере `Tournament::create` собирается вручную — добавить `'moderation_hours' => $validated['moderation_hours'] ?? null`.

- [ ] **Step 4: То же в `Club\TournamentController@store` и `@update`**

В оба валидатора (рядом с `'waitlist_size'`) добавить ту же строку правила. Эти контроллеры тоже создают/обновляют через `$validated` → поле подхватится.

- [ ] **Step 5: То же в `MobileAdminTournamentDetailController@update`** (если редактирование турнира идёт там) — добавить правило и убедиться, что `$validated` уходит в `$tournament->update(...)`.

- [ ] **Step 6: Запустить — пройдёт**

Run: `vendor/bin/phpunit --filter ModerationTimerStoreTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/MobileAdminTournamentController.php app/Http/Controllers/Club/TournamentController.php app/Http/Controllers/Api/MobileAdminTournamentDetailController.php tests/Feature/ModerationTimerStoreTest.php
git commit -m "feat(moderation): сохранение moderation_hours при создании/редактировании турнира"
```

---

### Task 5: Дедлайн при self-register (solo)

**Files:**
- Modify: `app/Http/Controllers/Api/MobileTournamentController.php` (метод `register`)
- Test: `tests/Feature/ModerationRegisterTest.php`

- [ ] **Step 1: Тест — запись при включённом таймере проставляет дедлайн и шлёт пуш**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function openTournament(int $hours = 48): Tournament
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        return Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4, 'waitlist_size' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => $hours,
        ]);
    }

    public function test_register_sets_moderation_deadline(): void
    {
        $t = $this->openTournament(48);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/tournaments/{$t->id}/register")->assertOk();

        $row = $t->participants()->where('user_id', $user->id)->first();
        $this->assertSame('pending', $row->pivot->status);
        $this->assertNotNull($row->pivot->moderation_deadline);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id, 'type' => 'tournament_moderation_pending',
        ]);
    }

    public function test_register_without_timer_leaves_deadline_null(): void
    {
        $t = $this->openTournament(0); // moderation_hours = 0 → выключен
        $t->update(['moderation_hours' => null]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/tournaments/{$t->id}/register")->assertOk();

        $row = $t->participants()->where('user_id', $user->id)->first();
        $this->assertNull($row->pivot->moderation_deadline);
    }
}
```

> Примечание: если `register` требует доп. полей (например подтверждение) — посмотреть метод и при необходимости дополнить тело. Если запись «с другом» — нас интересует ветка одиночной записи.

- [ ] **Step 2: Запустить — упадёт**

Run: `vendor/bin/phpunit --filter ModerationRegisterTest`
Expected: FAIL.

- [ ] **Step 3: В `register` при attach pending добавить дедлайн + пуш**

Найти в `register` ветку, где основное место есть и идёт `attach($user->id, ['status' => 'pending'])`. Заменить на:

```php
$deadline = $tournament->moderationDeadline();
$pivot = ['status' => 'pending'];
if ($deadline) $pivot['moderation_deadline'] = $deadline;
$tournament->participants()->attach($user->id, $pivot);
if ($friend) {
    $tournament->participants()->attach($friend->id, $pivot);
}
```

(если в коде есть отдельный attach друга со статусом pending — применить тот же `$pivot`).

После успешной записи (вне транзакции, где `$outcome === 'registered'`) добавить пуш:

```php
if ($deadline) {
    app(\App\Services\ModerationNotifier::class)->pending($user, $tournament, $deadline);
}
```

- [ ] **Step 3b: Разрешить повторную запись после `cancelled`**

В `register` найти проверку «уже записан». Нужно, чтобы строка со статусом `cancelled` НЕ считалась активной записью. Внутри транзакции, перед attach-логикой, удалить старую отменённую строку этого юзера (иначе `attach` нарушит unique):

```php
$tournament->participants()
    ->wherePivot('status', 'cancelled')
    ->detach($user->id);
```

В проверке «уже участвует» считать активными только `['registered','pending','waiting']` (исключить `cancelled`). Добавить тест в `ModerationRegisterTest`:

```php
public function test_can_reregister_after_cancelled(): void
{
    $t = $this->openTournament(48);
    $user = User::factory()->create();
    $t->participants()->attach($user->id, ['status' => 'cancelled']);
    Sanctum::actingAs($user);

    $this->postJson("/api/mobile/tournaments/{$t->id}/register")->assertOk();

    $row = $t->participants()->where('user_id', $user->id)->first();
    $this->assertSame('pending', $row->pivot->status);
    $this->assertNotNull($row->pivot->moderation_deadline);
}
```

- [ ] **Step 4: Запустить — пройдёт**

Run: `vendor/bin/phpunit --filter ModerationRegisterTest`
Expected: PASS (3 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileTournamentController.php tests/Feature/ModerationRegisterTest.php
git commit -m "feat(moderation): дедлайн+пуш при self-register"
```

---

### Task 6: Дедлайн при принятии приглашения + продление в web-записи и registerTeam

**Files:**
- Modify: `app/Http/Controllers/Api/MobileTournamentInvitationController.php` (`accept`)
- Modify: `app/Http/Controllers/Api/MobileTournamentController.php` (`registerTeam`)
- Modify: `app/Http/Controllers/Club/TournamentController.php` (web-запись/добавление в pending, если есть самозапись)
- Test: `tests/Feature/ModerationInvitationAcceptTest.php`

- [ ] **Step 1: Тест — accept приглашения ставит дедлайн**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use App\Models\TournamentInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationInvitationAcceptTest extends TestCase
{
    use RefreshDatabase;

    public function test_accept_sets_moderation_deadline(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 24,
        ]);
        $player = User::factory()->create();
        $inv = TournamentInvitation::create([
            'tournament_id' => $t->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);
        Sanctum::actingAs($player);

        $this->postJson("/api/mobile/tournaments/invitations/{$inv->id}/accept")->assertOk();

        $row = $t->participants()->where('user_id', $player->id)->first();
        $this->assertNotNull($row->pivot->moderation_deadline);
    }
}
```

- [ ] **Step 2: Запустить — упадёт**

Run: `vendor/bin/phpunit --filter ModerationInvitationAcceptTest`
Expected: FAIL.

- [ ] **Step 3: В `accept` при attach pending добавить дедлайн**

В `MobileTournamentInvitationController@accept`, в транзакции, где `attach($userId, ['status' => 'pending'])`, заменить на:

```php
$pivot = ['status' => 'pending'];
$deadline = $tournament->moderationDeadline();
if ($deadline) $pivot['moderation_deadline'] = $deadline;
$tournament->participants()->attach($userId, $pivot);
```

После успешного accept (где статус стал pending, не waiting) — пуш:

```php
if ($outcome === 'registered' && ($deadline ?? null)) {
    app(\App\Services\ModerationNotifier::class)->pending($request->user(), $tournament, $deadline);
}
```

- [ ] **Step 4: registerTeam — дедлайн на пару**

В `MobileTournamentController@registerTeam` найти создание `TournamentTeam` со `status => 'pending'` и добавить в массив создания:

```php
'moderation_deadline' => $tournament->moderationDeadline(),
```

(пуши обоим игрокам пары — опционально; в этой задаче достаточно дедлайна. Если хочется — вызвать `pending()` для `player1` и `player2`).

- [ ] **Step 5: Web-самозапись** — если в `Club\TournamentController` есть путь, где игрок попадает в `pending` (а не админ-добавление в registered), добавить туда `moderation_deadline` тем же образом. Если такого пути нет (в web только админ-операции) — пропустить.

- [ ] **Step 6: Запустить — пройдёт**

Run: `vendor/bin/phpunit --filter ModerationInvitationAcceptTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/MobileTournamentInvitationController.php app/Http/Controllers/Api/MobileTournamentController.php app/Http/Controllers/Club/TournamentController.php tests/Feature/ModerationInvitationAcceptTest.php
git commit -m "feat(moderation): дедлайн при accept приглашения и registerTeam"
```

---

### Task 7: Снятие таймера при одобрении

**Files:**
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` (`approveParticipant`, `approveTeam`)
- Test: `tests/Feature/ModerationApproveClearsTest.php`

- [ ] **Step 1: Тест — approve обнуляет moderation_deadline**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationApproveClearsTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_clears_deadline(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 24,
        ]);
        $player = User::factory()->create();
        $t->participants()->attach($player->id, [
            'status' => 'pending', 'moderation_deadline' => now()->addHours(24),
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$player->id}/approve")
            ->assertOk();

        $row = $t->participants()->where('user_id', $player->id)->first();
        $this->assertSame('registered', $row->pivot->status);
        $this->assertNull($row->pivot->moderation_deadline);
    }
}
```

- [ ] **Step 2: Запустить — упадёт**

Run: `vendor/bin/phpunit --filter ModerationApproveClearsTest`
Expected: FAIL (deadline ещё стоит).

- [ ] **Step 3: В `approveParticipant` обнулять дедлайн**

Найти `updateExistingPivot($user->id, ['status' => 'registered'])` и заменить на:

```php
$tournament->participants()->updateExistingPivot($user->id, [
    'status' => 'registered',
    'moderation_deadline' => null,
    'reminder_sent_at' => null,
]);
```

- [ ] **Step 4: В `approveTeam`** — при `$team->update(['status' => 'approved'])` добавить `'moderation_deadline' => null, 'reminder_sent_at' => null`.

- [ ] **Step 5: Запустить — пройдёт**

Run: `vendor/bin/phpunit --filter ModerationApproveClearsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/MobileAdminTournamentDetailController.php tests/Feature/ModerationApproveClearsTest.php
git commit -m "feat(moderation): одобрение снимает таймер"
```

---

### Task 8: Console-команда — solo: просрочка + продвижение + напоминание

**Files:**
- Create: `app/Console/Commands/ProcessModerationTimers.php`
- Test: `tests/Feature/ProcessModerationTimersTest.php`

- [ ] **Step 1: Тесты команды (solo)**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProcessModerationTimersTest extends TestCase
{
    use RefreshDatabase;

    private function tournament(): Tournament
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        return Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 2, 'waitlist_size' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 48,
        ]);
    }

    public function test_expired_pending_cancelled_and_waitlist_promoted(): void
    {
        $t = $this->tournament();
        $late = User::factory()->create();
        $waiter = User::factory()->create();
        $t->participants()->attach($late->id, [
            'status' => 'pending', 'moderation_deadline' => now()->subMinute(),
        ]);
        $t->participants()->attach($waiter->id, ['status' => 'waiting', 'created_at' => now()->subHour()]);

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);

        $lateRow = $t->participants()->where('user_id', $late->id)->first();
        $this->assertSame('cancelled', $lateRow->pivot->status);
        $this->assertNull($lateRow->pivot->moderation_deadline);

        $waiterRow = $t->participants()->where('user_id', $waiter->id)->first();
        $this->assertSame('pending', $waiterRow->pivot->status);
        $this->assertNotNull($waiterRow->pivot->moderation_deadline);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $late->id, 'type' => 'tournament_moderation_expired',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $waiter->id, 'type' => 'tournament_moderation_pending',
        ]);
    }

    public function test_future_deadline_untouched(): void
    {
        $t = $this->tournament();
        $u = User::factory()->create();
        $t->participants()->attach($u->id, [
            'status' => 'pending', 'moderation_deadline' => now()->addHours(10),
        ]);

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);

        $row = $t->participants()->where('user_id', $u->id)->first();
        $this->assertSame('pending', $row->pivot->status);
    }

    public function test_reminder_sent_once_when_near_deadline(): void
    {
        $t = $this->tournament(); // окно 48ч → 20% = 9.6ч
        $u = User::factory()->create();
        // осталось 1 час → точно в зоне напоминания
        $t->participants()->attach($u->id, [
            'status' => 'pending', 'moderation_deadline' => now()->addHour(),
        ]);

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $u->id, 'type' => 'tournament_moderation_reminder',
        ]);

        // повторный прогон — второго напоминания нет
        $this->artisan('tournaments:process-moderation')->assertExitCode(0);
        $this->assertSame(1, \App\Models\Notification::where('user_id', $u->id)
            ->where('type', 'tournament_moderation_reminder')->count());
    }
}
```

- [ ] **Step 2: Запустить — упадут**

Run: `vendor/bin/phpunit --filter ProcessModerationTimersTest`
Expected: FAIL (команда не найдена).

- [ ] **Step 3: Реализовать команду (solo-часть)**

```php
<?php

namespace App\Console\Commands;

use App\Models\Tournament;
use App\Services\ModerationNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessModerationTimers extends Command
{
    protected $signature = 'tournaments:process-moderation';
    protected $description = 'Снимает просроченные заявки на модерации и продвигает лист ожидания';

    public function handle(ModerationNotifier $notifier): int
    {
        $tournaments = Tournament::where('status', 'open')
            ->whereNotNull('moderation_hours')
            ->where('moderation_hours', '>', 0)
            ->get();

        foreach ($tournaments as $t) {
            $this->processSolo($t, $notifier);
            // team-часть добавляется в Task 9
        }

        return self::SUCCESS;
    }

    private function processSolo(Tournament $t, ModerationNotifier $notifier): void
    {
        $now = now();
        $windowSeconds = (int) $t->moderation_hours * 3600;
        $reminderLead = max($windowSeconds * 0.2, 1800); // 20% окна, минимум 30 мин

        $pending = $t->participants()
            ->wherePivot('status', 'pending')
            ->wherePivotNotNull('moderation_deadline')
            ->get();

        foreach ($pending as $p) {
            $deadline = Carbon::parse($p->pivot->moderation_deadline);
            $remaining = $deadline->getTimestamp() - $now->getTimestamp(); // секунд до дедлайна

            if ($remaining <= 0) {
                DB::transaction(function () use ($t, $p, $notifier) {
                    $t->participants()->updateExistingPivot($p->id, [
                        'status' => 'cancelled', 'moderation_deadline' => null, 'reminder_sent_at' => null,
                    ]);
                    $notifier->expired($p, $t);
                    $this->promoteFirstWaiting($t, $notifier);
                });
                continue;
            }

            // напоминание
            if (!$p->pivot->reminder_sent_at && $remaining <= $reminderLead) {
                $t->participants()->updateExistingPivot($p->id, ['reminder_sent_at' => $now]);
                $notifier->reminder($p, $t, $deadline);
            }
        }
    }

    private function promoteFirstWaiting(Tournament $t, ModerationNotifier $notifier): void
    {
        $waiter = $t->participants()
            ->wherePivot('status', 'waiting')
            ->orderBy('tournament_participants.created_at')
            ->first();
        if (!$waiter) return;

        $deadline = $t->moderationDeadline();
        $t->participants()->updateExistingPivot($waiter->id, [
            'status' => 'pending',
            'moderation_deadline' => $deadline,
            'reminder_sent_at' => null,
        ]);
        if ($deadline) {
            $notifier->pending($waiter, $t, $deadline);
        }
    }
}
```

- [ ] **Step 4: Запустить — пройдут**

Run: `vendor/bin/phpunit --filter ProcessModerationTimersTest`
Expected: PASS (3 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ProcessModerationTimers.php tests/Feature/ProcessModerationTimersTest.php
git commit -m "feat(moderation): команда process-moderation (solo)"
```

---

### Task 9: Команда — team-часть

**Files:**
- Modify: `app/Console/Commands/ProcessModerationTimers.php`
- Modify: `app/Models/TournamentTeam.php` (cast moderation_deadline/reminder_sent_at в datetime; fillable)
- Test: `tests/Feature/ProcessModerationTeamTest.php`

- [ ] **Step 1: Тест — просрочка пары → rejected + продвижение waiting-пары**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProcessModerationTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_expiry_and_promotion(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Team', 'type' => 'team',
            'status' => 'open', 'max_participants' => 4, 'waitlist_size' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 24,
        ]);
        $late = TournamentTeam::create([
            'tournament_id' => $t->id, 'player1_id' => User::factory()->create()->id,
            'player2_id' => User::factory()->create()->id, 'status' => 'pending',
            'moderation_deadline' => now()->subMinute(),
        ]);
        $waiting = TournamentTeam::create([
            'tournament_id' => $t->id, 'player1_id' => User::factory()->create()->id,
            'player2_id' => User::factory()->create()->id, 'status' => 'waiting',
            'created_at' => now()->subHour(),
        ]);

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);

        $this->assertSame('rejected', $late->fresh()->status);
        $this->assertSame('pending', $waiting->fresh()->status);
        $this->assertNotNull($waiting->fresh()->moderation_deadline);
    }
}
```

- [ ] **Step 2: Запустить — упадёт**

Run: `vendor/bin/phpunit --filter ProcessModerationTeamTest`
Expected: FAIL.

- [ ] **Step 3: TournamentTeam — fillable + cast**

В `app/Models/TournamentTeam.php` добавить в `$fillable` (если есть): `'moderation_deadline'`, `'reminder_sent_at'`; в `$casts`:

```php
'moderation_deadline' => 'datetime',
'reminder_sent_at' => 'datetime',
```

- [ ] **Step 4: Добавить `processTeams` в команду и вызвать в `handle`**

В `handle`, в цикле после `$this->processSolo(...)` добавить `$this->processTeams($t, $notifier);`. Метод:

```php
private function processTeams(Tournament $t, ModerationNotifier $notifier): void
{
    if ($t->type !== 'team') return;

    $now = now();
    $windowSeconds = (int) $t->moderation_hours * 3600;
    $reminderLead = max($windowSeconds * 0.2, 1800);

    $pending = \App\Models\TournamentTeam::where('tournament_id', $t->id)
        ->where('status', 'pending')
        ->whereNotNull('moderation_deadline')
        ->get();

    foreach ($pending as $team) {
        $deadline = $team->moderation_deadline;
        $remaining = $deadline->getTimestamp() - $now->getTimestamp();

        if ($remaining <= 0) {
            DB::transaction(function () use ($t, $team, $notifier) {
                $team->update(['status' => 'rejected', 'moderation_deadline' => null, 'reminder_sent_at' => null]);
                if ($team->player1) $notifier->expired($team->player1, $t);
                $this->promoteFirstWaitingTeam($t, $notifier);
            });
            continue;
        }

        if (!$team->reminder_sent_at && $remaining <= $reminderLead) {
            $team->update(['reminder_sent_at' => $now]);
            if ($team->player1) $notifier->reminder($team->player1, $t, $deadline);
        }
    }
}

private function promoteFirstWaitingTeam(Tournament $t, ModerationNotifier $notifier): void
{
    $team = \App\Models\TournamentTeam::where('tournament_id', $t->id)
        ->where('status', 'waiting')
        ->orderBy('created_at')
        ->first();
    if (!$team) return;

    $deadline = $t->moderationDeadline();
    $team->update(['status' => 'pending', 'moderation_deadline' => $deadline, 'reminder_sent_at' => null]);
    if ($deadline && $team->player1) {
        $notifier->pending($team->player1, $t, $deadline);
    }
}
```

> `player1` — связь на `User` (проверить имя связи в модели; если `player1()` belongsTo — `$team->player1` корректно).

- [ ] **Step 5: Запустить — пройдёт**

Run: `vendor/bin/phpunit --filter "ProcessModerationTeamTest|ProcessModerationTimersTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/ProcessModerationTimers.php app/Models/TournamentTeam.php tests/Feature/ProcessModerationTeamTest.php
git commit -m "feat(moderation): команда process-moderation (team)"
```

---

### Task 10: Планировщик (cron) каждую минуту

**Files:**
- Modify: `bootstrap/app.php`

- [ ] **Step 1: Добавить расписание**

В `bootstrap/app.php` в вызов `Application::configure(...)` добавить (рядом с `->withRouting`/`->withMiddleware`) блок `->withSchedule`:

```php
->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
    $schedule->command('tournaments:process-moderation')
        ->everyMinute()
        ->withoutOverlapping();
})
```

- [ ] **Step 2: Проверить, что команда видна планировщику**

Run: `php artisan schedule:list`
Expected: в списке `tournaments:process-moderation ... every minute`.

- [ ] **Step 3: Прогнать вручную (без ошибок)**

Run: `php artisan tournaments:process-moderation`
Expected: завершается без вывода ошибок (exit 0).

- [ ] **Step 4: Commit**

```bash
git add bootstrap/app.php
git commit -m "feat(moderation): планировщик process-moderation каждую минуту"
```

> ⚠️ ПРОД: после деплоя нужен системный cron `* * * * * cd /path/to/padel && php artisan schedule:run >> /dev/null 2>&1`.

---

### Task 11: API — отдать moderation_deadline в приложение

**Files:**
- Modify: `app/Http/Controllers/Api/MobileTournamentController.php` (метод `getUserRegistration`)
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` (метод `participants` — solo-ветка)
- Test: `tests/Feature/ModerationApiExposureTest.php`

- [ ] **Step 1: Тест — «Мои турниры» отдаёт moderation_deadline для pending**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationApiExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_tournaments_expose_deadline(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 24,
        ]);
        $user = User::factory()->create();
        $t->participants()->attach($user->id, [
            'status' => 'pending', 'moderation_deadline' => now()->addHours(24),
        ]);
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/mobile/tournaments/my')->assertOk();
        $res->assertJsonPath('tournaments.0.moderation_deadline', fn ($v) => $v !== null);
    }
}
```

- [ ] **Step 2: Запустить — упадёт**

Run: `vendor/bin/phpunit --filter ModerationApiExposureTest`
Expected: FAIL.

- [ ] **Step 3: В `getUserRegistration` вернуть дедлайн**

Найти метод `getUserRegistration(Tournament $t, $user): array` в `MobileTournamentController`. В возвращаемый массив добавить ключ `moderation_deadline`. Источник — pivot текущего пользователя:

```php
$pivotRow = $t->participants()->where('users.id', $user->id)->first();
$deadline = $pivotRow && $pivotRow->pivot->status === 'pending'
    ? optional($pivotRow->pivot->moderation_deadline)
    : null;
// ... в массив результата:
'moderation_deadline' => $deadline ? \Carbon\Carbon::parse($deadline)->toIso8601String() : null,
```

В `formatTournament` (трейт `FormatsTournaments`) блок `if ($user && $includeRegistration)` уже копирует ключи `getUserRegistration` в `$data` — добавить туда:

```php
$data['moderation_deadline'] = $registration['moderation_deadline'] ?? null;
```

- [ ] **Step 4: Админ-участники — отдать дедлайн**

В `MobileAdminTournamentDetailController@participants`, solo-ветка (где формируется `$arr = $this->formatUser($u); $arr['status'] = ...; $arr['registered_at'] = ...;`) добавить:

```php
$arr['moderation_deadline'] = $u->pivot->moderation_deadline
    ? \Carbon\Carbon::parse($u->pivot->moderation_deadline)->toIso8601String() : null;
```

И убедиться, что relation `participants()` в админ-выборке тянет pivot (`withPivot('status','created_at','moderation_deadline')` — добавить `moderation_deadline` в `withPivot` этого запроса, если он переопределён локально).

- [ ] **Step 5: Запустить — пройдёт**

Run: `vendor/bin/phpunit --filter ModerationApiExposureTest`
Expected: PASS.

- [ ] **Step 6: Прогнать весь модерационный набор + смежные**

Run: `vendor/bin/phpunit --filter "Moderation|ProcessModeration|TournamentInvitation|MobileTournament"`
Expected: всё зелёное.

- [ ] **Step 7: Commit + push**

```bash
git add app/Http/Controllers/Api/MobileTournamentController.php app/Http/Controllers/Api/MobileAdminTournamentDetailController.php tests/Feature/ModerationApiExposureTest.php
git commit -m "feat(moderation): moderation_deadline в API (мои турниры + админ-участники)"
git push
```

---

## FRONTEND (`C:\projects\padel_app`)

### Task 12: Модели — moderationDeadline

**Files:**
- Modify: `lib/models/tournament.dart`
- Modify: `lib/models/admin_participant.dart`

- [ ] **Step 1: Tournament — поле + парсинг**

Добавить поле `final DateTime? moderationDeadline;`, в конструктор `this.moderationDeadline`, в `fromJson`:

```dart
moderationDeadline: json['moderation_deadline'] != null
    ? DateTime.tryParse(json['moderation_deadline'] as String)
    : null,
```

- [ ] **Step 2: AdminParticipant — поле + парсинг**

Добавить `final DateTime? moderationDeadline;`, в конструктор `this.moderationDeadline`, в `fromJson`:

```dart
moderationDeadline: DateTime.tryParse(json['moderation_deadline'] as String? ?? ''),
```

- [ ] **Step 3: Анализ**

Run: `cd /c/projects/padel_app && flutter analyze lib/models/tournament.dart lib/models/admin_participant.dart`
Expected: No issues.

- [ ] **Step 4: Commit**

```bash
cd /c/projects/padel_app && git add lib/models/tournament.dart lib/models/admin_participant.dart && git commit -m "feat(moderation): moderationDeadline в моделях"
```

---

### Task 13: Виджет ModerationCountdown

**Files:**
- Create: `lib/widgets/moderation_countdown.dart`

- [ ] **Step 1: Реализовать виджет**

```dart
import 'dart:async';
import 'package:flutter/material.dart';
import '../theme/app_theme.dart';

/// Локальный обратный отсчёт до дедлайна модерации (без сети/вебсокетов).
class ModerationCountdown extends StatefulWidget {
  final DateTime deadline;
  final bool compact;
  const ModerationCountdown({super.key, required this.deadline, this.compact = false});

  @override
  State<ModerationCountdown> createState() => _ModerationCountdownState();
}

class _ModerationCountdownState extends State<ModerationCountdown> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  String _fmt(Duration d) {
    if (d.isNegative) return 'время вышло';
    final days = d.inDays;
    final h = d.inHours % 24;
    final m = d.inMinutes % 60;
    final s = d.inSeconds % 60;
    if (days > 0) return '${days}д ${h}ч ${m}м';
    if (h > 0) return '${h}ч ${m}м ${s}с';
    return '${m}м ${s}с';
  }

  @override
  Widget build(BuildContext context) {
    final left = widget.deadline.difference(DateTime.now());
    final urgent = left.inHours < 3;
    final color = left.isNegative
        ? AppTheme.error
        : (urgent ? AppTheme.amber : AppTheme.accent);

    if (widget.compact) {
      return Text('⏳ ${_fmt(left)}',
          style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w700));
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: color.withAlpha(24),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withAlpha(80)),
      ),
      child: Row(
        children: [
          Icon(Icons.timer_outlined, size: 18, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              left.isNegative
                  ? 'Время на оплату вышло'
                  : 'Оплатите в течение ${_fmt(left)}',
              style: TextStyle(color: color, fontSize: 13, fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}
```

- [ ] **Step 2: Анализ**

Run: `cd /c/projects/padel_app && flutter analyze lib/widgets/moderation_countdown.dart`
Expected: No issues.

- [ ] **Step 3: Commit**

```bash
cd /c/projects/padel_app && git add lib/widgets/moderation_countdown.dart && git commit -m "feat(moderation): виджет countdown"
```

---

### Task 14: Поле «Таймер модерации» при создании турнира

**Files:**
- Modify: `lib/screens/admin/admin_create_tournament_screen.dart`
- Modify: `lib/services/admin_service.dart` (`createTournament` — пробросить moderation_hours)

- [ ] **Step 1: В `admin_service.createTournament`** добавить опциональный параметр и включить его в тело запроса:

```dart
// сигнатура: добавить именованный параметр
int? moderationHours,
// в body:
if (moderationHours != null) 'moderation_hours': moderationHours,
```

- [ ] **Step 2: В экране создания** добавить контроллер `final _moderationHours = TextEditingController();`, текстовое поле (числовое, необязательное) с подписью «Таймер модерации, часов (пусто = без таймера)», и при отправке передать `moderationHours: int.tryParse(_moderationHours.text)`. Не забыть `dispose()`.

- [ ] **Step 3: Анализ**

Run: `cd /c/projects/padel_app && flutter analyze lib/screens/admin/admin_create_tournament_screen.dart lib/services/admin_service.dart`
Expected: No issues (кроме предсуществующих info).

- [ ] **Step 4: Commit**

```bash
cd /c/projects/padel_app && git add lib/screens/admin/admin_create_tournament_screen.dart lib/services/admin_service.dart && git commit -m "feat(moderation): поле таймера при создании турнира"
```

---

### Task 15: Countdown в «Мои турниры» и деталке турнира

**Files:**
- Modify: `lib/screens/my_tournaments_screen.dart`
- Modify: `lib/screens/tournament_detail_screen.dart`

- [ ] **Step 1: В «Мои турниры»** — где рендерится карточка/строка турнира с моим статусом, если `t.moderationDeadline != null` и статус регистрации `pending`, под карточкой показать `ModerationCountdown(deadline: t.moderationDeadline!)`. Импорт `../widgets/moderation_countdown.dart`.

- [ ] **Step 2: В деталке турнира** — в блоке, где показывается мой статус «на модерации», если `tournament.moderationDeadline != null` добавить `ModerationCountdown(deadline: tournament.moderationDeadline!)`. Импорт виджета.

- [ ] **Step 3: Анализ**

Run: `cd /c/projects/padel_app && flutter analyze lib/screens/my_tournaments_screen.dart lib/screens/tournament_detail_screen.dart`
Expected: No issues (кроме предсуществующих).

- [ ] **Step 4: Commit**

```bash
cd /c/projects/padel_app && git add lib/screens/my_tournaments_screen.dart lib/screens/tournament_detail_screen.dart && git commit -m "feat(moderation): countdown в моих турнирах и деталке"
```

---

### Task 16: Countdown у админа в участниках

**Files:**
- Modify: `lib/screens/admin/admin_tournament_detail_screen.dart`

- [ ] **Step 1: В `_buildPendingTile`** (заявки на модерации) под `_nameAndMeta` добавить, если `p.moderationDeadline != null`:

```dart
if (p.moderationDeadline != null) ...[
  const SizedBox(width: 8),
  ModerationCountdown(deadline: p.moderationDeadline!, compact: true),
],
```

(встроить в `Row` строки между мета и меню «три точки»). Импорт `../../widgets/moderation_countdown.dart`.

- [ ] **Step 2: Анализ**

Run: `cd /c/projects/padel_app && flutter analyze lib/screens/admin/admin_tournament_detail_screen.dart`
Expected: нет новых error/warning.

- [ ] **Step 3: Commit + push**

```bash
cd /c/projects/padel_app && git add lib/screens/admin/admin_tournament_detail_screen.dart && git commit -m "feat(moderation): countdown у админа в участниках" && git push
```

---

## Финал

- [ ] Прогнать весь бэкенд-набор модерации: `vendor/bin/phpunit --filter "Moderation|ProcessModeration"` — всё зелёное.
- [ ] `flutter analyze` по всем затронутым экранам — без новых ошибок.
- [ ] Деплой-памятка прода:
  - `php artisan migrate --path=database/migrations/2026_05_31_000001_add_moderation_timer.php`
  - `php artisan config:clear && php artisan cache:clear`
  - Настроить системный cron: `* * * * * cd /path/to/padel && php artisan schedule:run >> /dev/null 2>&1`
  - Приложение — пересборка.

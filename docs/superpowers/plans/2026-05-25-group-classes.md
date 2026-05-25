# Групповые занятия — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Дать клубам в веб-админке вести групповые занятия: группы, участники с предоплаченным пакетом, разовые сессии с занятием корта в общем расписании, проведение + посещаемость со списанием занятий.

**Architecture:** 5 новых таблиц (`club_groups`, `club_group_members`, `club_group_enrollments`, `club_group_sessions`, `club_group_attendance`). Сессия резервирует корт через связанную обычную бронь `court_bookings` (`booking_type='group'`, `price=0`) — расписание/конфликты работают без переделок. Два веб-раздела: «Группы» (`Club\GroupController`) и «Журнал занятий» (`Club\GroupSessionController`). Доступ за фича-флагом `groups`.

**Tech Stack:** Laravel 12, Blade, MySQL (prod) / sqlite (tests), PHPUnit, существующий `ScheduleService` (метод `canBook`), `ActivityLog`, паттерн `getClub()`.

**Спека:** `docs/superpowers/specs/2026-05-25-group-classes-design.md`

---

## Справочные паттерны (прочитать перед стартом)

- `app/Http/Controllers/Club/ClientController.php` — `getClub()`, валидация, `ActivityLog::log()`, структура index (список+карточка).
- `app/Http/Controllers/Club/CourtController.php:564-693` — создание брони, `$this->scheduleService->canBook($court,$date,$start,$end)`, `CourtBooking::create`, `booking_type='group'`.
- `app/Models/ClubClient.php`, `app/Models/CourtBooking.php` — стиль моделей.
- `routes/web.php:184-256` — группа `club.` + middleware `club.feature:...`.
- `app/Http/Middleware/CheckClubFeature.php` — фича-флаг (generic, ключ передаётся в роуте). `hasFeature()` для неизвестного ключа возвращает true по умолчанию.
- `resources/views/club/clients/index.blade.php` — визуальный паттерн списка/карточки/модалок (использовать как образец стиля для новых view).
- `app/Http/Controllers/Admin/ClubController.php:68-78` — массив features (добавить `groups`); `resources/views/admin/clubs/edit.blade.php` — чекбоксы фич.

---

## Task 1: Миграции (5 таблиц)

**Files:**
- Create: `database/migrations/2026_05_25_000001_create_club_groups_table.php`
- Create: `database/migrations/2026_05_25_000002_create_club_group_members_table.php`
- Create: `database/migrations/2026_05_25_000003_create_club_group_enrollments_table.php`
- Create: `database/migrations/2026_05_25_000004_create_club_group_sessions_table.php`
- Create: `database/migrations/2026_05_25_000005_create_club_group_attendance_table.php`

- [ ] **Step 1: club_groups**

`database/migrations/2026_05_25_000001_create_club_groups_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('club_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubs')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('price_per_session', 10, 2)->default(0);
            $table->unsignedInteger('capacity')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['club_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_groups');
    }
};
```

- [ ] **Step 2: club_group_members**

`database/migrations/2026_05_25_000002_create_club_group_members_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('club_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('club_groups')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('club_clients')->cascadeOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->unique(['group_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_group_members');
    }
};
```

- [ ] **Step 3: club_group_enrollments**

`database/migrations/2026_05_25_000003_create_club_group_enrollments_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('club_group_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_member_id')->constrained('club_group_members')->cascadeOnDelete();
            $table->unsignedInteger('sessions');
            $table->decimal('amount', 10, 2)->default(0);
            $table->boolean('is_paid')->default(false);
            $table->string('payment_method')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('group_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_group_enrollments');
    }
};
```

- [ ] **Step 4: club_group_sessions**

`database/migrations/2026_05_25_000004_create_club_group_sessions_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('club_group_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('club_groups')->cascadeOnDelete();
            $table->foreignId('court_id')->constrained('courts')->cascadeOnDelete();
            $table->foreignId('court_booking_id')->nullable()->constrained('court_bookings')->nullOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['planned', 'held', 'cancelled'])->default('planned');
            $table->timestamp('held_at')->nullable();
            $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['group_id', 'date']);
            $table->index(['court_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_group_sessions');
    }
};
```

- [ ] **Step 5: club_group_attendance**

`database/migrations/2026_05_25_000005_create_club_group_attendance_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('club_group_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('club_group_sessions')->cascadeOnDelete();
            $table->foreignId('group_member_id')->constrained('club_group_members')->cascadeOnDelete();
            $table->boolean('attended')->default(false);
            $table->boolean('charged')->default(false);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'group_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_group_attendance');
    }
};
```

- [ ] **Step 6: Run migrations on sqlite to verify**

Run: `php artisan migrate --database=sqlite` (или `php artisan test --filter=__nonexistent__` чтобы прогнать RefreshDatabase). Ожидается: миграции применяются без ошибок.

- [ ] **Step 7: Commit**
```bash
git add database/migrations/2026_05_25_000001_create_club_groups_table.php database/migrations/2026_05_25_000002_create_club_group_members_table.php database/migrations/2026_05_25_000003_create_club_group_enrollments_table.php database/migrations/2026_05_25_000004_create_club_group_sessions_table.php database/migrations/2026_05_25_000005_create_club_group_attendance_table.php
git commit -m "feat(groups): миграции таблиц групповых занятий"
```

---

## Task 2: Модели (5 шт) + аксессор остатка

**Files:**
- Create: `app/Models/ClubGroup.php`, `ClubGroupMember.php`, `ClubGroupEnrollment.php`, `ClubGroupSession.php`, `ClubGroupAttendance.php`
- Test: `tests/Unit/ClubGroupMemberRemainingTest.php`

- [ ] **Step 1: Failing test для остатка занятий**

`tests/Unit/ClubGroupMemberRemainingTest.php`:
```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Club;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubGroupMemberRemainingTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_equals_bought_minus_charged(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван']);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'price_per_session' => 1000]);
        $member = ClubGroupMember::create(['group_id' => $group->id, 'client_id' => $client->id]);
        ClubGroupEnrollment::create(['group_member_id' => $member->id, 'sessions' => 8, 'amount' => 8000]);

        $this->assertSame(8, $member->fresh()->remaining);
    }
}
```

- [ ] **Step 2: Run test — fails**

Run: `php artisan test --filter=ClubGroupMemberRemainingTest`
Expected: FAIL (классы моделей не существуют).

- [ ] **Step 3: ClubGroup**

`app/Models/ClubGroup.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroup extends Model
{
    protected $fillable = [
        'club_id', 'name', 'coach_id', 'price_per_session', 'capacity', 'status', 'note',
    ];

    protected $casts = [
        'price_per_session' => 'decimal:2',
        'capacity' => 'integer',
    ];

    public function club() { return $this->belongsTo(Club::class); }
    public function coach() { return $this->belongsTo(User::class, 'coach_id'); }
    public function members() { return $this->hasMany(ClubGroupMember::class, 'group_id'); }
    public function sessions() { return $this->hasMany(ClubGroupSession::class, 'group_id'); }
}
```

- [ ] **Step 4: ClubGroupMember (+ remaining)**

`app/Models/ClubGroupMember.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroupMember extends Model
{
    protected $fillable = ['group_id', 'client_id', 'status'];

    public function group() { return $this->belongsTo(ClubGroup::class, 'group_id'); }
    public function client() { return $this->belongsTo(ClubClient::class, 'client_id'); }
    public function enrollments() { return $this->hasMany(ClubGroupEnrollment::class, 'group_member_id'); }
    public function attendance() { return $this->hasMany(ClubGroupAttendance::class, 'group_member_id'); }

    public function getRemainingAttribute(): int
    {
        $bought = (int) $this->enrollments()->sum('sessions');
        $used = (int) $this->attendance()->where('charged', true)->count();
        return $bought - $used;
    }
}
```

- [ ] **Step 5: ClubGroupEnrollment**

`app/Models/ClubGroupEnrollment.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroupEnrollment extends Model
{
    protected $fillable = [
        'group_member_id', 'sessions', 'amount', 'is_paid', 'payment_method', 'created_by',
    ];

    protected $casts = [
        'sessions' => 'integer',
        'amount' => 'decimal:2',
        'is_paid' => 'boolean',
    ];

    public function member() { return $this->belongsTo(ClubGroupMember::class, 'group_member_id'); }
}
```

- [ ] **Step 6: ClubGroupSession**

`app/Models/ClubGroupSession.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroupSession extends Model
{
    protected $fillable = [
        'group_id', 'court_id', 'court_booking_id', 'date', 'start_time', 'end_time',
        'coach_id', 'status', 'held_at', 'conducted_by',
    ];

    protected $casts = [
        'date' => 'date',
        'held_at' => 'datetime',
    ];

    public function group() { return $this->belongsTo(ClubGroup::class, 'group_id'); }
    public function court() { return $this->belongsTo(Court::class, 'court_id'); }
    public function coach() { return $this->belongsTo(User::class, 'coach_id'); }
    public function courtBooking() { return $this->belongsTo(CourtBooking::class, 'court_booking_id'); }
    public function attendance() { return $this->hasMany(ClubGroupAttendance::class, 'session_id'); }
}
```

- [ ] **Step 7: ClubGroupAttendance**

`app/Models/ClubGroupAttendance.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroupAttendance extends Model
{
    protected $table = 'club_group_attendance';

    protected $fillable = ['session_id', 'group_member_id', 'attended', 'charged', 'note'];

    protected $casts = [
        'attended' => 'boolean',
        'charged' => 'boolean',
    ];

    public function session() { return $this->belongsTo(ClubGroupSession::class, 'session_id'); }
    public function member() { return $this->belongsTo(ClubGroupMember::class, 'group_member_id'); }
}
```

- [ ] **Step 8: Run test — passes**

Run: `php artisan test --filter=ClubGroupMemberRemainingTest`
Expected: PASS.

- [ ] **Step 9: Commit**
```bash
git add app/Models/ClubGroup.php app/Models/ClubGroupMember.php app/Models/ClubGroupEnrollment.php app/Models/ClubGroupSession.php app/Models/ClubGroupAttendance.php tests/Unit/ClubGroupMemberRemainingTest.php
git commit -m "feat(groups): модели групповых занятий + остаток занятий"
```

---

## Task 3: Фича-флаг `groups`

**Files:**
- Modify: `app/Http/Controllers/Admin/ClubController.php:68-78` (массив features)
- Modify: `resources/views/admin/clubs/edit.blade.php` (чекбокс фичи в блоке «Доступные модули»)

- [ ] **Step 1: Добавить `groups` в features admin-контроллера**

В `app/Http/Controllers/Admin/ClubController.php`, в массиве `$validated['features']` (после `'moderators' => ...`) добавить строку:
```php
            'groups' => (bool) ($features['groups'] ?? true),
```

- [ ] **Step 2: Чекбокс в супер-админ форме**

В `resources/views/admin/clubs/edit.blade.php`, внутри блока «Доступные модули» (после чекбокса `features[moderators]`), добавить:
```blade
                            <label class="form-check">
                                <input type="hidden" name="features[groups]" value="0">
                                <input type="checkbox" name="features[groups]" value="1" class="form-check-input"
                                       {{ old('features.groups', $features['groups'] ?? true) ? 'checked' : '' }}
                                       style="background-color: var(--bg-secondary); border-color: var(--border);">
                                <span class="form-check-label">Групповые занятия</span>
                            </label>
```

- [ ] **Step 3: Verify blade compiles**

Run: `php artisan view:cache && php artisan view:clear`
Expected: `Blade templates cached successfully.`

- [ ] **Step 4: Commit**
```bash
git add app/Http/Controllers/Admin/ClubController.php resources/views/admin/clubs/edit.blade.php
git commit -m "feat(groups): фича-флаг groups в карточке клуба (супер-админ)"
```

---

## Task 4: Группы — CRUD + список + карточка

**Files:**
- Create: `app/Http/Controllers/Club/GroupController.php`
- Create: `resources/views/club/groups/index.blade.php`, `resources/views/club/groups/show.blade.php`
- Modify: `routes/web.php` (группа роутов под `club.feature:groups`)
- Modify: `resources/views/layouts/app.blade.php` (пункты навигации)
- Test: `tests/Feature/ClubGroupCrudTest.php`

- [ ] **Step 1: Failing test (создание группы)**

`tests/Feature/ClubGroupCrudTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\ClubGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubGroupCrudTest extends TestCase
{
    use RefreshDatabase;

    private function adminClub(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        return [$club, $admin];
    }

    public function test_admin_creates_group(): void
    {
        [$club, $admin] = $this->adminClub();

        $this->actingAs($admin)->post(route('club.groups.store'), [
            'name' => 'Утренняя группа',
            'price_per_session' => 5000,
            'capacity' => 4,
        ])->assertRedirect();

        $g = ClubGroup::where('club_id', $club->id)->first();
        $this->assertNotNull($g);
        $this->assertSame('Утренняя группа', $g->name);
        $this->assertSame(4, (int) $g->capacity);
    }

    public function test_other_club_group_forbidden(): void
    {
        [, $admin] = $this->adminClub();
        $otherClub = Club::create(['name' => 'X', 'address' => 'Y']);
        $foreign = ClubGroup::create(['club_id' => $otherClub->id, 'name' => 'F']);

        $this->actingAs($admin)->get(route('club.groups.show', $foreign))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test — fails**

Run: `php artisan test --filter=ClubGroupCrudTest`
Expected: FAIL (роут/контроллер не существуют).

- [ ] **Step 3: GroupController (index/store/show/update/archive)**

`app/Http/Controllers/Club/GroupController.php`:
```php
<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubGroup;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function index()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $groups = ClubGroup::where('club_id', $club->id)
            ->withCount(['members as active_members_count' => fn($q) => $q->where('status', 'active')])
            ->orderByRaw("status = 'archived'")
            ->orderBy('name')
            ->get();

        $coaches = $club->clubCoaches()->with('user')->get();

        return view('club.groups.index', compact('groups', 'club', 'coaches'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'coach_id' => 'nullable|exists:users,id',
            'price_per_session' => 'nullable|numeric|min:0',
            'capacity' => 'nullable|integer|min:1|max:100',
            'note' => 'nullable|string|max:1000',
        ]);
        $validated['club_id'] = $club->id;
        $validated['price_per_session'] = $validated['price_per_session'] ?? 0;

        $group = ClubGroup::create($validated);
        \App\Models\ActivityLog::log('created', 'ClubGroup', $group->id, "Группа создана: {$group->name}", clubId: $club->id);

        return redirect()->route('club.groups.show', $group)->with('success', 'Группа создана');
    }

    public function show(ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $group->load(['coach', 'members.client', 'members.enrollments']);
        $sessions = $group->sessions()->with('court')->orderByDesc('date')->orderByDesc('start_time')->get();
        $coaches = $club->clubCoaches()->with('user')->get();

        return view('club.groups.show', compact('group', 'club', 'sessions', 'coaches'));
    }

    public function update(Request $request, ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'coach_id' => 'nullable|exists:users,id',
            'price_per_session' => 'nullable|numeric|min:0',
            'capacity' => 'nullable|integer|min:1|max:100',
            'note' => 'nullable|string|max:1000',
            'status' => 'nullable|in:active,archived',
        ]);
        $validated['price_per_session'] = $validated['price_per_session'] ?? 0;

        $group->update($validated);

        return back()->with('success', 'Группа обновлена');
    }
}
```

- [ ] **Step 4: Routes**

В `routes/web.php`, внутри группы `club.` (после блока «Клиенты», перед «Корты»), добавить:
```php
        // Групповые занятия
        Route::middleware('club.feature:groups')->group(function () {
            Route::get('/groups', [App\Http\Controllers\Club\GroupController::class, 'index'])->name('groups.index');
            Route::post('/groups', [App\Http\Controllers\Club\GroupController::class, 'store'])->name('groups.store');
            Route::get('/groups/{group}', [App\Http\Controllers\Club\GroupController::class, 'show'])->name('groups.show');
            Route::put('/groups/{group}', [App\Http\Controllers\Club\GroupController::class, 'update'])->name('groups.update');
        });
```

- [ ] **Step 5: Views (index + show) по образцу club/clients**

Создать `resources/views/club/groups/index.blade.php` — `@extends('layouts.app')`, заголовок «Группы» + кнопка «Создать группу» (модалка с полями: name, coach_id (select из `$coaches`), price_per_session, capacity, note), таблица групп (name, тренер, участников `active_members_count`, цена, статус) со ссылкой на `route('club.groups.show', $group)`. Стиль/разметку взять из `resources/views/club/clients/index.blade.php` (карточки, модалки, alert success/error).

Создать `resources/views/club/groups/show.blade.php` — шапка с названием группы + кнопка «Редактировать» (модалка update), блок участников (таблица: клиент, остаток `{{ $member->remaining }}`, действия — заполнится в Task 5), блок занятий группы `$sessions` (дата, время, корт, статус) со ссылкой в журнал (заполнится в Task 7). Пока разделы-заглушки с корректной разметкой и без действий.

- [ ] **Step 6: Навигация (сайдбар)**

В `resources/views/layouts/app.blade.php`, в секциях «Модератор» и «Админ клуба» (рядом с пунктом «Клиенты», который условен по `hasFeature('clients')`), добавить два пункта под аналогичным условием `hasFeature('groups')`:
```blade
@if(!$modClub || $modClub->hasFeature('groups'))
<li class="nav-item">
    <a href="{{ route('club.groups.index') }}" class="nav-link {{ request()->routeIs('club.groups.*') ? 'active' : '' }}">
        <i class="bi bi-people"></i>
        <span>Группы</span>
    </a>
</li>
<li class="nav-item">
    <a href="{{ route('club.groupSessions.index') }}" class="nav-link {{ request()->routeIs('club.groupSessions.*') ? 'active' : '' }}">
        <i class="bi bi-journal-check"></i>
        <span>Журнал занятий</span>
    </a>
</li>
@endif
```
(в админ-секции аналогично, с переменной клуба этой секции — повторить условие по образцу пункта «Клиенты» в каждой секции). Маршрут `club.groupSessions.index` появится в Task 7 — добавить оба пункта здесь, чтобы навигация была цельной (роут будет зарегистрирован до первого рендера после Task 7; если запускаешь раздельно — закомментируй второй пункт до Task 7).

- [ ] **Step 7: Run test — passes**

Run: `php artisan test --filter=ClubGroupCrudTest`
Expected: PASS.

- [ ] **Step 8: Commit**
```bash
git add app/Http/Controllers/Club/GroupController.php resources/views/club/groups routes/web.php resources/views/layouts/app.blade.php tests/Feature/ClubGroupCrudTest.php
git commit -m "feat(groups): раздел «Группы» — CRUD, список, карточка, навигация"
```

---

## Task 5: Участники группы + пакеты (добавить/продлить/убрать)

**Files:**
- Modify: `app/Http/Controllers/Club/GroupController.php` (методы addMember, enroll, removeMember)
- Modify: `routes/web.php` (роуты участников)
- Modify: `resources/views/club/groups/show.blade.php` (UI участников)
- Test: `tests/Feature/ClubGroupMembersTest.php`

- [ ] **Step 1: Failing test (добавление участника + пакет, продление)**

`tests/Feature/ClubGroupMembersTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubGroupMembersTest extends TestCase
{
    use RefreshDatabase;

    private function setup3(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'price_per_session' => 1000, 'capacity' => 2]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван']);
        return [$club, $admin, $group, $client];
    }

    public function test_add_member_with_package_sets_remaining(): void
    {
        [, $admin, $group, $client] = $this->setup3();

        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id,
            'sessions' => 8,
            'amount' => 8000,
            'is_paid' => 1,
        ])->assertRedirect();

        $member = ClubGroupMember::where('group_id', $group->id)->where('client_id', $client->id)->first();
        $this->assertNotNull($member);
        $this->assertSame(8, $member->remaining);
        $this->assertSame(8000.0, (float) $member->enrollments()->sum('amount'));
    }

    public function test_enroll_extends_remaining(): void
    {
        [, $admin, $group, $client] = $this->setup3();
        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id, 'sessions' => 8, 'amount' => 8000, 'is_paid' => 1,
        ]);
        $member = ClubGroupMember::first();

        $this->actingAs($admin)->post(route('club.groups.members.enroll', [$group, $member]), [
            'sessions' => 4, 'amount' => 4000, 'is_paid' => 0,
        ])->assertRedirect();

        $this->assertSame(12, $member->fresh()->remaining);
    }

    public function test_capacity_blocks_third_member(): void
    {
        [$club, $admin, $group, $client] = $this->setup3();
        $c2 = ClubClient::create(['club_id' => $club->id, 'name' => 'Пётр']);
        $c3 = ClubClient::create(['club_id' => $club->id, 'name' => 'Сидор']);
        foreach ([$client, $c2] as $c) {
            $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
                'client_id' => $c->id, 'sessions' => 1, 'amount' => 1000, 'is_paid' => 1,
            ]);
        }
        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $c3->id, 'sessions' => 1, 'amount' => 1000, 'is_paid' => 1,
        ])->assertSessionHas('error');

        $this->assertSame(2, $group->members()->count());
    }
}
```

- [ ] **Step 2: Run test — fails**

Run: `php artisan test --filter=ClubGroupMembersTest`
Expected: FAIL (роуты не существуют).

- [ ] **Step 3: Методы в GroupController**

Добавить в `app/Http/Controllers/Club/GroupController.php`:
```php
    public function addMember(Request $request, ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $validated = $request->validate([
            'client_id' => 'required|exists:club_clients,id',
            'sessions' => 'required|integer|min:1|max:200',
            'amount' => 'nullable|numeric|min:0',
            'is_paid' => 'nullable|boolean',
        ]);

        $client = \App\Models\ClubClient::find($validated['client_id']);
        if (!$client || $client->club_id !== $club->id) abort(403);

        // Уже участник?
        if ($group->members()->where('client_id', $client->id)->exists()) {
            return back()->with('error', 'Клиент уже в этой группе');
        }

        // Вместимость
        if ($group->capacity !== null
            && $group->members()->where('status', 'active')->count() >= $group->capacity) {
            return back()->with('error', 'Группа заполнена (достигнута вместимость)');
        }

        $member = \App\Models\ClubGroupMember::create([
            'group_id' => $group->id,
            'client_id' => $client->id,
        ]);
        $this->createEnrollment($member, $validated);

        \App\Models\ActivityLog::log('created', 'ClubGroupMember', $member->id,
            "В группу «{$group->name}» добавлен {$client->name} ({$validated['sessions']} занятий)", clubId: $club->id);

        return back()->with('success', 'Участник добавлен');
    }

    public function enroll(Request $request, ClubGroup $group, \App\Models\ClubGroupMember $member)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id || $member->group_id !== $group->id) abort(403);

        $validated = $request->validate([
            'sessions' => 'required|integer|min:1|max:200',
            'amount' => 'nullable|numeric|min:0',
            'is_paid' => 'nullable|boolean',
        ]);
        $this->createEnrollment($member, $validated);

        return back()->with('success', 'Пакет занятий добавлен');
    }

    public function removeMember(ClubGroup $group, \App\Models\ClubGroupMember $member)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id || $member->group_id !== $group->id) abort(403);

        $member->delete();
        return back()->with('success', 'Участник убран из группы');
    }

    private function createEnrollment(\App\Models\ClubGroupMember $member, array $validated): void
    {
        \App\Models\ClubGroupEnrollment::create([
            'group_member_id' => $member->id,
            'sessions' => $validated['sessions'],
            'amount' => $validated['amount'] ?? 0,
            'is_paid' => (bool) ($validated['is_paid'] ?? false),
            'created_by' => auth()->id(),
        ]);
    }
```

- [ ] **Step 4: Routes**

В блок `club.feature:groups` добавить:
```php
            Route::post('/groups/{group}/members', [App\Http\Controllers\Club\GroupController::class, 'addMember'])->name('groups.members.store');
            Route::post('/groups/{group}/members/{member}/enroll', [App\Http\Controllers\Club\GroupController::class, 'enroll'])->name('groups.members.enroll');
            Route::delete('/groups/{group}/members/{member}', [App\Http\Controllers\Club\GroupController::class, 'removeMember'])->name('groups.members.destroy');
```

- [ ] **Step 5: UI участников в show.blade.php**

В `resources/views/club/groups/show.blade.php` в блоке участников: таблица (клиент, остаток `{{ $member->remaining }}`, кнопки «Продлить» (модалка → `groups.members.enroll`), «Убрать» (форма DELETE → `groups.members.destroy`)). Кнопка «Добавить участника» → модалка с полем поиска клиента (select из клиентов клуба или ajax `route('club.clients.search')`), полями sessions/amount/is_paid → POST `groups.members.store`. Для select клиентов можно передать `$clients = ClubClient::where('club_id',$club->id)->orderBy('name')->get()` из `show()` (добавить в compact).

Добавить в `show()` контроллера `$clients`:
```php
        $clients = \App\Models\ClubClient::where('club_id', $club->id)->orderBy('name')->get();
```
и в `compact(... 'clients')`.

- [ ] **Step 6: Run test — passes**

Run: `php artisan test --filter=ClubGroupMembersTest`
Expected: PASS.

- [ ] **Step 7: Commit**
```bash
git add app/Http/Controllers/Club/GroupController.php routes/web.php resources/views/club/groups/show.blade.php tests/Feature/ClubGroupMembersTest.php
git commit -m "feat(groups): участники группы — добавление с пакетом, продление, удаление, вместимость"
```

---

## Task 6: Журнал занятий — создание сессии (с занятием корта)

**Files:**
- Create: `app/Http/Controllers/Club/GroupSessionController.php`
- Create: `resources/views/club/group-sessions/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ClubGroupSessionTest.php`

- [ ] **Step 1: Failing test (создание сессии резервирует корт; конфликт)**

`tests/Feature/ClubGroupSessionTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\User;
use App\Models\ClubGroup;
use App\Models\ClubGroupSession;
use App\Models\CourtBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubGroupSessionTest extends TestCase
{
    use RefreshDatabase;

    private function setupCourt(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'price_per_session' => 1000]);
        return [$club, $admin, $court, $group];
    }

    public function test_create_session_reserves_court(): void
    {
        [, $admin, $court, $group] = $this->setupCourt();

        $this->actingAs($admin)->post(route('club.groupSessions.store'), [
            'group_id' => $group->id,
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'slots' => 1,
        ])->assertRedirect();

        $session = ClubGroupSession::first();
        $this->assertNotNull($session);
        $this->assertNotNull($session->court_booking_id);
        $booking = CourtBooking::find($session->court_booking_id);
        $this->assertSame('group', $booking->booking_type);
        $this->assertSame('confirmed', $booking->status);
    }

    public function test_conflict_with_existing_booking_blocked(): void
    {
        [, $admin, $court, $group] = $this->setupCourt();
        $date = now()->addDay()->toDateString();
        CourtBooking::create([
            'court_id' => $court->id, 'date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
            'client_name' => 'X', 'client_phone' => '7700', 'status' => 'confirmed',
            'booked_by' => $admin->id, 'price' => 0,
        ]);

        $this->actingAs($admin)->post(route('club.groupSessions.store'), [
            'group_id' => $group->id, 'court_id' => $court->id, 'date' => $date,
            'start_time' => '10:00', 'slots' => 1,
        ])->assertSessionHas('error');

        $this->assertSame(0, ClubGroupSession::count());
    }
}
```

- [ ] **Step 2: Run test — fails**

Run: `php artisan test --filter=ClubGroupSessionTest`
Expected: FAIL.

- [ ] **Step 3: GroupSessionController (index/store) с canBook + связанная бронь**

`app/Http/Controllers/Club/GroupSessionController.php`:
```php
<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubGroup;
use App\Models\ClubGroupSession;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GroupSessionController extends Controller
{
    public function __construct(private ScheduleService $scheduleService) {}

    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function index(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $courtIds = $club->courts()->pluck('id');
        $query = ClubGroupSession::whereIn('court_id', $courtIds)
            ->with(['group', 'court', 'coach'])
            ->withCount(['attendance as attended_count' => fn($q) => $q->where('attended', true)]);

        if ($gid = $request->get('group_id')) $query->where('group_id', $gid);
        if ($status = $request->get('status')) $query->where('status', $status);
        if ($date = $request->get('date')) $query->whereDate('date', $date);

        $sessions = $query->orderByDesc('date')->orderByDesc('start_time')->paginate(30)->withQueryString();
        $groups = ClubGroup::where('club_id', $club->id)->orderBy('name')->get();
        $courts = $club->courts()->where('is_active', true)->orderBy('sort_order')->get();
        $coaches = $club->clubCoaches()->with('user')->get();

        return view('club.group-sessions.index', compact('sessions', 'groups', 'courts', 'coaches', 'club'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $validated = $request->validate([
            'group_id' => 'required|exists:club_groups,id',
            'court_id' => 'required|exists:courts,id',
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'slots' => 'required|integer|min:1|max:8',
            'coach_id' => 'nullable|exists:users,id',
        ]);

        $group = ClubGroup::find($validated['group_id']);
        $court = Court::find($validated['court_id']);
        if ($group->club_id !== $club->id || $court->club_id !== $club->id) abort(403);

        $startTime = $validated['start_time'];
        $endTime = Carbon::parse($startTime)->addMinutes($validated['slots'] * ($court->slot_duration ?: 60))->format('H:i');

        if (!$this->scheduleService->canBook($court, $validated['date'], $startTime, $endTime)) {
            return back()->with('error', 'Корт занят на это время');
        }

        $coachId = $validated['coach_id'] ?? $group->coach_id;

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => $validated['date'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'client_name' => 'Группа: ' . $group->name,
            'client_phone' => null,
            'status' => 'confirmed',
            'booked_by' => auth()->id(),
            'price' => 0,
            'booking_type' => 'group',
            'coach_id' => $coachId,
        ]);

        $session = ClubGroupSession::create([
            'group_id' => $group->id,
            'court_id' => $court->id,
            'court_booking_id' => $booking->id,
            'date' => $validated['date'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'coach_id' => $coachId,
            'status' => 'planned',
        ]);

        \App\Models\ActivityLog::log('created', 'ClubGroupSession', $session->id,
            "Занятие группы «{$group->name}»: {$court->name}, {$validated['date']} {$startTime}–{$endTime}", clubId: $club->id);

        return redirect()->route('club.groupSessions.index')->with('success', 'Занятие создано');
    }
}
```

- [ ] **Step 4: Routes**

В блок `club.feature:groups` добавить:
```php
            Route::get('/group-sessions', [App\Http\Controllers\Club\GroupSessionController::class, 'index'])->name('groupSessions.index');
            Route::post('/group-sessions', [App\Http\Controllers\Club\GroupSessionController::class, 'store'])->name('groupSessions.store');
```

- [ ] **Step 5: View index (Журнал занятий)**

`resources/views/club/group-sessions/index.blade.php` — `@extends('layouts.app')`, заголовок «Журнал занятий», фильтры (group_id/status/date GET-форма), кнопка «Создать занятие» (модалка: group_id, court_id, date, start_time, slots, coach_id → POST `groupSessions.store`), таблица сессий (дата, время, группа, тренер, корт, статус-бейдж, `attended_count`) со ссылкой на карточку занятия `route('club.groupSessions.show', $session)` (появится в Task 7). Стиль — по образцу `club/clients/index.blade.php`.

- [ ] **Step 6: Run test — passes**

Run: `php artisan test --filter=ClubGroupSessionTest`
Expected: PASS.

- [ ] **Step 7: Commit**
```bash
git add app/Http/Controllers/Club/GroupSessionController.php resources/views/club/group-sessions routes/web.php tests/Feature/ClubGroupSessionTest.php
git commit -m "feat(groups): журнал занятий — создание сессии с резервом корта"
```

---

## Task 7: Проведение занятия + посещаемость (списание, блок при 0, отмена)

**Files:**
- Modify: `app/Http/Controllers/Club/GroupSessionController.php` (show, conduct, cancel)
- Create: `resources/views/club/group-sessions/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ClubGroupAttendanceTest.php`

- [ ] **Step 1: Failing test (списание, блок при 0, отмена)**

`tests/Feature/ClubGroupAttendanceTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\User;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupEnrollment;
use App\Models\ClubGroupSession;
use App\Models\CourtBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubGroupAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(int $sessions = 2): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K1', 'is_active' => true, 'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60]);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'price_per_session' => 1000]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван']);
        $member = ClubGroupMember::create(['group_id' => $group->id, 'client_id' => $client->id]);
        ClubGroupEnrollment::create(['group_member_id' => $member->id, 'sessions' => $sessions, 'amount' => $sessions * 1000]);
        $booking = CourtBooking::create(['court_id' => $court->id, 'date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'client_name' => 'Группа: G', 'status' => 'confirmed', 'booked_by' => $admin->id, 'price' => 0, 'booking_type' => 'group']);
        $session = ClubGroupSession::create(['group_id' => $group->id, 'court_id' => $court->id, 'court_booking_id' => $booking->id, 'date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'planned']);
        return [$club, $admin, $group, $member, $session, $booking];
    }

    public function test_conduct_charges_attendee(): void
    {
        [, $admin, , $member, $session] = $this->scenario(2);

        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['attended' => 1, 'charged' => 1]],
        ])->assertRedirect();

        $this->assertSame('held', $session->fresh()->status);
        $this->assertSame(1, $member->fresh()->remaining); // 2 - 1
    }

    public function test_conduct_blocked_when_zero_remaining(): void
    {
        [, $admin, , $member, $session] = $this->scenario(0);

        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['attended' => 1, 'charged' => 1]],
        ])->assertSessionHas('error');

        $this->assertSame('planned', $session->fresh()->status);
    }

    public function test_cancel_frees_court_no_charge(): void
    {
        [, $admin, , $member, $session, $booking] = $this->scenario(2);

        $this->actingAs($admin)->post(route('club.groupSessions.cancel', $session))->assertRedirect();

        $this->assertSame('cancelled', $session->fresh()->status);
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(2, $member->fresh()->remaining);
    }
}
```

- [ ] **Step 2: Run test — fails**

Run: `php artisan test --filter=ClubGroupAttendanceTest`
Expected: FAIL.

- [ ] **Step 3: Методы show/conduct/cancel в GroupSessionController**

Добавить в `app/Http/Controllers/Club/GroupSessionController.php`:
```php
    public function show(ClubGroupSession $session)
    {
        $club = $this->getClub();
        $this->authorizeSession($club, $session);

        $session->load(['group.members.client', 'group.members.enrollments', 'group.members.attendance', 'court', 'coach', 'attendance']);
        $members = $session->group->members()->where('status', 'active')->with('client')->get();
        $existing = $session->attendance->keyBy('group_member_id');

        return view('club.group-sessions.show', compact('session', 'members', 'existing', 'club'));
    }

    public function conduct(Request $request, ClubGroupSession $session)
    {
        $club = $this->getClub();
        $this->authorizeSession($club, $session);

        if ($session->status === 'cancelled') {
            return back()->with('error', 'Занятие отменено');
        }

        $rows = $request->input('attendance', []);

        // Проверка: у отмеченных «пришёл + списать» должен быть остаток > 0
        foreach ($rows as $memberId => $row) {
            $attended = !empty($row['attended']);
            $charged = !empty($row['charged']);
            if ($attended && $charged) {
                $member = \App\Models\ClubGroupMember::find($memberId);
                if (!$member || $member->group_id !== $session->group_id) abort(403);
                if ($member->remaining <= 0) {
                    return back()->with('error', "У участника {$member->client->name} закончились занятия — продлите пакет");
                }
            }
        }

        // Применяем посещаемость
        foreach ($rows as $memberId => $row) {
            $member = \App\Models\ClubGroupMember::find($memberId);
            if (!$member || $member->group_id !== $session->group_id) continue;
            $attended = !empty($row['attended']);
            $charged = $attended && !empty($row['charged']);

            \App\Models\ClubGroupAttendance::updateOrCreate(
                ['session_id' => $session->id, 'group_member_id' => $member->id],
                ['attended' => $attended, 'charged' => $charged]
            );
        }

        $session->update([
            'status' => 'held',
            'held_at' => now(),
            'conducted_by' => auth()->id(),
        ]);

        \App\Models\ActivityLog::log('updated', 'ClubGroupSession', $session->id,
            "Занятие проведено: «{$session->group->name}»", clubId: $club->id);

        return redirect()->route('club.groupSessions.index')->with('success', 'Занятие проведено');
    }

    public function cancel(ClubGroupSession $session)
    {
        $club = $this->getClub();
        $this->authorizeSession($club, $session);

        $session->update(['status' => 'cancelled']);
        if ($session->courtBooking) {
            $session->courtBooking->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }

        \App\Models\ActivityLog::log('cancelled', 'ClubGroupSession', $session->id,
            "Занятие отменено: «{$session->group->name}»", clubId: $club->id);

        return back()->with('success', 'Занятие отменено, корт освобождён');
    }

    private function authorizeSession($club, ClubGroupSession $session): void
    {
        if (!$club) abort(403);
        $courtIds = $club->courts()->pluck('id')->all();
        if (!in_array($session->court_id, $courtIds, true)) abort(403);
    }
```

- [ ] **Step 4: Routes**

В блок `club.feature:groups`:
```php
            Route::get('/group-sessions/{session}', [App\Http\Controllers\Club\GroupSessionController::class, 'show'])->name('groupSessions.show');
            Route::post('/group-sessions/{session}/conduct', [App\Http\Controllers\Club\GroupSessionController::class, 'conduct'])->name('groupSessions.conduct');
            Route::post('/group-sessions/{session}/cancel', [App\Http\Controllers\Club\GroupSessionController::class, 'cancel'])->name('groupSessions.cancel');
```
(Разместить `/group-sessions/{session}` ПОСЛЕ статичного `/group-sessions` из Task 6, чтобы не перехватывать.)

- [ ] **Step 5: View карточки занятия**

`resources/views/club/group-sessions/show.blade.php` — шапка (группа, дата, время, корт, тренер, статус). Если `planned`: форма с ростером `$members` — на каждого чекбоксы `attendance[{{$member->id}}][attended]` и `attendance[{{$member->id}}][charged]`, рядом остаток `{{ $member->remaining }}` (если 0 — пометка «нужно продлить», чекбокс «charged» по умолчанию off/disabled). Кнопка «Провести занятие» (POST `groupSessions.conduct`) и «Отменить занятие» (POST `groupSessions.cancel`). Если `held`: показать кто пришёл (из `$existing`) только для чтения. Стиль — по образцу существующих view.

- [ ] **Step 6: Run test — passes**

Run: `php artisan test --filter=ClubGroupAttendanceTest`
Expected: PASS.

- [ ] **Step 7: Commit**
```bash
git add app/Http/Controllers/Club/GroupSessionController.php resources/views/club/group-sessions/show.blade.php routes/web.php tests/Feature/ClubGroupAttendanceTest.php
git commit -m "feat(groups): проведение занятия, посещаемость, списание, отмена с освобождением корта"
```

---

## Task 8: Блок «Группы» в карточке клиента

**Files:**
- Modify: `app/Http/Controllers/Club/ClientController.php` (index/show — подгрузка групп клиента)
- Modify: `resources/views/club/clients/index.blade.php` (блок в карточке клиента)
- Test: `tests/Feature/ClientGroupsBlockTest.php`

- [ ] **Step 1: Failing test (карточка клиента показывает группы и остаток)**

`tests/Feature/ClientGroupsBlockTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClientGroupsBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_page_shows_group_with_remaining(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван']);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'Утро']);
        $member = ClubGroupMember::create(['group_id' => $group->id, 'client_id' => $client->id]);
        ClubGroupEnrollment::create(['group_member_id' => $member->id, 'sessions' => 5, 'amount' => 5000]);

        $this->actingAs($admin)
            ->get(route('club.clients.index', ['selected' => $client->id]))
            ->assertOk()
            ->assertSee('Утро');
    }
}
```

- [ ] **Step 2: Run test — fails**

Run: `php artisan test --filter=ClientGroupsBlockTest`
Expected: FAIL (имя группы не выводится).

- [ ] **Step 3: Подгрузка групп клиента в ClientController@index**

В `app/Http/Controllers/Club/ClientController.php@index`, после получения `$selectedClient`, добавить (если выбран клиент):
```php
        $clientGroups = collect();
        if ($selectedClient) {
            $clientGroups = \App\Models\ClubGroupMember::where('client_id', $selectedClient->id)
                ->whereHas('group', fn($q) => $q->where('club_id', $club->id))
                ->with('group')
                ->get();
        }
```
и добавить `$clientGroups` в `compact(...)` к возврату view.

- [ ] **Step 4: Блок в карточке клиента (index.blade.php)**

В правой панели карточки клиента (`resources/views/club/clients/index.blade.php`), после блока «Информация», добавить:
```blade
            @if(isset($clientGroups) && $clientGroups->count())
                <div style="margin-top:16px;">
                    <div style="font-size:13px;color:var(--users-text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Группы</div>
                    @foreach($clientGroups as $gm)
                        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--users-border);">
                            <a href="{{ route('club.groups.show', $gm->group) }}" style="color:var(--users-text);text-decoration:none;">{{ $gm->group->name }}</a>
                            <span style="color:var(--users-text-dim);">осталось: {{ $gm->remaining }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
```
(если переменные стилей `--users-*` отсутствуют на этой странице — использовать те, что уже применяются в `clients/index.blade.php`.)

- [ ] **Step 5: Run test — passes**

Run: `php artisan test --filter=ClientGroupsBlockTest`
Expected: PASS.

- [ ] **Step 6: Commit**
```bash
git add app/Http/Controllers/Club/ClientController.php resources/views/club/clients/index.blade.php tests/Feature/ClientGroupsBlockTest.php
git commit -m "feat(groups): блок «Группы» с остатком занятий в карточке клиента"
```

---

## Task 9: Финальная проверка

- [ ] **Step 1: Прогнать все новые тесты + связанные**

Run: `php artisan test --filter="ClubGroup|ClubGroupMembers|ClubGroupSession|ClubGroupAttendance|ClientGroupsBlock|ClubGroupMemberRemaining|AdminClub|MobileAdminModerator"`
Expected: все PASS.

- [ ] **Step 2: Компиляция всех blade**

Run: `php artisan view:cache && php artisan view:clear`
Expected: `Blade templates cached successfully.`

- [ ] **Step 3: Полный прогон тест-сьюта (sanity)**

Run: `php artisan test`
Expected: новые зелёные; ранее известные падающие Breeze-заготовки игнорируем (Auth/Registration/Profile/Example) — это не регрессия.

- [ ] **Step 4: Финальный commit (если остались правки)**
```bash
git add -A
git commit -m "test(groups): финальная проверка групповых занятий"
```

---

## Деплой на прод (после мержа)
```bash
git pull
php artisan migrate --path=database/migrations/2026_05_25_000001_create_club_groups_table.php
php artisan migrate --path=database/migrations/2026_05_25_000002_create_club_group_members_table.php
php artisan migrate --path=database/migrations/2026_05_25_000003_create_club_group_enrollments_table.php
php artisan migrate --path=database/migrations/2026_05_25_000004_create_club_group_sessions_table.php
php artisan migrate --path=database/migrations/2026_05_25_000005_create_club_group_attendance_table.php
php artisan optimize:clear
```

## Self-Review (выполнено при написании)
- **Покрытие спеки:** 5 таблиц (T1), модели+остаток (T2), фича-флаг (T3), группы CRUD+нав (T4), участники+пакеты+вместимость (T5), сессии+резерв корта+конфликт (T6), проведение+посещаемость+списание+блок при 0+отмена (T7), блок в карточке клиента (T8). Связь с расписанием — через `court_bookings type=group` (T6). Доступ/клуб-резолв во всех контроллерах.
- **Не входит (по спеке):** приложение, повтор групп, отдельный модуль абонементов, отдельные отчёты — не запланированы намеренно.
- **Типы/имена согласованы:** модели и поля совпадают между задачами (`remaining`, `court_booking_id`, `attendance[member][attended|charged]`, роуты `groups.*` / `groupSessions.*`).

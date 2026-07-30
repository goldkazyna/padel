# Games Module — Backend S12 (Напоминания об играх: команда games:send-reminders) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Напоминать принятым участникам о начале игры за сутки/2ч/1ч (пуш + запись в notifications), по образцу `SendBookingReminders`.

**Architecture:** Новая artisan-команда `App\Console\Commands\SendGameReminders` (`games:send-reminders`), регистрируется в `bootstrap/app.php` (`withSchedule`). Флаги-однократности `reminded_1d_at`/`reminded_2h_at`/`reminded_1h_at` на таблице `games` (новая миграция). Пуш через существующий `FCMNotificationService::sendToUser` + запись `Notification` (category='game').

**Tech Stack:** Laravel 12, Sanctum (не нужен для команды), PHPUnit sqlite :memory:.

## Design-решения (записаны намеренно)
- **Кого напоминаем:** всех `accepted`-игроков игры в статусе `open`/`full`/`in_progress` (ещё не завершённой/отменённой), у которой `starts_at` в будущем и попадает в один из порогов.
- **Пороги (как у брони):** ≤24ч → `reminded_1d_at`, ≤2ч → `reminded_2h_at`, ≤1ч → `reminded_1h_at`. Каждый порог срабатывает один раз (guard по колонке-флагу, сразу проставляем).
- **Тексты:** «Игра скоро начнётся» + время. type=`game_reminder`, category=`game`, data `{game_id, kind:'1d'|'2h'|'1h'}`.
- FCM обёрнут в try/catch с логом (как в SendBookingReminders), чтобы падение пуша одному не рушило рассылку.
- **Флаги на games** (а не на игрока): напоминание отправляется всем участникам разом, флаг фиксирует факт рассылки этого порога по игре.

## Global Constraints
- НЕ трогать RatingCalculator/AmericanoRanking/AmericanoService/challenge. Никаких записей рейтинга.
- Регистрация расписания — ТОЛЬКО в `bootstrap/app.php` (Laravel 12), рядом с `bookings:send-reminders`. `everyMinute()->withoutOverlapping()`.
- Миграция даётся к деплою отдельным `--path=` (правило проекта). Колонки nullable timestamp, без дефолта.
- Ветка от main (`feature/games-backend-s12`), не работать на main, не пушить.

---

### Task 1: Миграция флагов напоминаний на games

**Files:**
- Create: `database/migrations/2026_07_30_000001_add_reminder_flags_to_games_table.php`
- Modify: `app/Models/Game.php` (fillable + casts для 3 колонок)
- Test: `tests/Feature/Games/GameReminderFlagsSchemaTest.php`

**Interfaces:**
- Produces: колонки `reminded_1d_at`, `reminded_2h_at`, `reminded_1h_at` (nullable timestamp) на `games`; в модели — в `$fillable` и `$casts` (datetime).

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameReminderFlagsSchemaTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GameReminderFlagsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_games_table_has_reminder_flag_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('games', 'reminded_1d_at'));
        $this->assertTrue(Schema::hasColumn('games', 'reminded_2h_at'));
        $this->assertTrue(Schema::hasColumn('games', 'reminded_1h_at'));
    }

    public function test_flags_are_fillable_and_cast_datetime(): void
    {
        $game = Game::factory()->create();
        $game->update(['reminded_1d_at' => now()]);
        $this->assertNotNull($game->fresh()->reminded_1d_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $game->fresh()->reminded_1d_at);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameReminderFlagsSchemaTest.php`
Expected: FAIL — колонок нет.

- [ ] **Step 3: Реализовать**

`database/migrations/2026_07_30_000001_add_reminder_flags_to_games_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->timestamp('reminded_1d_at')->nullable()->after('score_locked');
            $table->timestamp('reminded_2h_at')->nullable()->after('reminded_1d_at');
            $table->timestamp('reminded_1h_at')->nullable()->after('reminded_2h_at');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['reminded_1d_at', 'reminded_2h_at', 'reminded_1h_at']);
        });
    }
};
```

В `app/Models/Game.php` добавить в `$fillable` три колонки и в `$casts`:
```php
        'reminded_1d_at' => 'datetime',
        'reminded_2h_at' => 'datetime',
        'reminded_1h_at' => 'datetime',
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameReminderFlagsSchemaTest.php` → PASS (2).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_30_000001_add_reminder_flags_to_games_table.php app/Models/Game.php tests/Feature/Games/GameReminderFlagsSchemaTest.php
git commit -m "feat(games): колонки флагов напоминаний на games (S12)"
```

---

### Task 2: Команда games:send-reminders + регистрация в расписании

**Files:**
- Create: `app/Console/Commands/SendGameReminders.php`
- Modify: `bootstrap/app.php` (регистрация в `withSchedule`)
- Test: `tests/Feature/Games/GameRemindersCommandTest.php`

**Interfaces:**
- Consumes: колонки-флаги (Task 1); `Notification`, `FCMNotificationService`.
- Produces: команда `games:send-reminders`, которая для каждой незавершённой будущей игры в пороге отправляет напоминание всем accepted-игрокам (Notification + FCM) и выставляет флаг соответствующего порога один раз.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameRemindersCommandTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Notification;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GameRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Пуш не шлём реально.
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->app->instance(FCMNotificationService::class, $mock);
    }

    private function gameStartingIn(int $minutes): array
    {
        $game = Game::factory()->create([
            'status' => 'full', 'format' => 'sets',
            'starts_at' => now()->addMinutes($minutes),
            'ends_at' => now()->addMinutes($minutes + 90),
        ]);
        $u = User::factory()->create();
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        return [$game, $u];
    }

    public function test_sends_1h_reminder_and_sets_flag_once(): void
    {
        [$game, $u] = $this->gameStartingIn(50); // ≤1ч

        $this->artisan('games:send-reminders')->assertExitCode(0);

        $this->assertNotNull($game->fresh()->reminded_1h_at);
        $this->assertSame(1, Notification::where('user_id', $u->id)->where('type', 'game_reminder')->count());

        // Повторный запуск не шлёт второй раз.
        $this->artisan('games:send-reminders')->assertExitCode(0);
        $this->assertSame(1, Notification::where('user_id', $u->id)->where('type', 'game_reminder')->count());
    }

    public function test_does_not_remind_far_future_game(): void
    {
        [$game, $u] = $this->gameStartingIn(60 * 48); // через 2 суток

        $this->artisan('games:send-reminders')->assertExitCode(0);

        $this->assertNull($game->fresh()->reminded_1d_at);
        $this->assertSame(0, Notification::where('user_id', $u->id)->count());
    }

    public function test_does_not_remind_finished_game(): void
    {
        [$game, $u] = $this->gameStartingIn(50);
        $game->update(['status' => 'finished']);

        $this->artisan('games:send-reminders')->assertExitCode(0);

        $this->assertNull($game->fresh()->reminded_1h_at);
        $this->assertSame(0, Notification::where('user_id', $u->id)->count());
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameRemindersCommandTest.php`
Expected: FAIL — команды нет.

- [ ] **Step 3: Реализовать**

`app/Console/Commands/SendGameReminders.php`:
```php
<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Notification;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendGameReminders extends Command
{
    protected $signature = 'games:send-reminders';
    protected $description = 'Напоминания участникам о начале игры за сутки, за 2 часа и за час';

    public function handle(): int
    {
        $now = now();
        $games = Game::whereIn('status', [Game::STATUS_OPEN, Game::STATUS_FULL, Game::STATUS_IN_PROGRESS])
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', (clone $now)->addDay())
            ->get();

        foreach ($games as $game) {
            $seconds = $now->diffInSeconds($game->starts_at, false);
            if ($seconds < 0) {
                continue;
            }

            $threshold = null;   // ['column', 'kind']
            if ($seconds <= 3600 && !$game->reminded_1h_at) {
                $threshold = ['reminded_1h_at', '1h'];
            } elseif ($seconds <= 7200 && !$game->reminded_2h_at) {
                $threshold = ['reminded_2h_at', '2h'];
            } elseif ($seconds <= 86400 && !$game->reminded_1d_at) {
                $threshold = ['reminded_1d_at', '1d'];
            }
            if ($threshold === null) {
                continue;
            }

            $game->update([$threshold[0] => $now]);

            $accepted = $game->players()
                ->where('status', GamePlayer::STATUS_ACCEPTED)
                ->with('user')
                ->get();
            foreach ($accepted as $gp) {
                if (!$gp->user) {
                    continue;
                }
                $this->send($gp->user, $game, $threshold[1]);
            }
        }

        return self::SUCCESS;
    }

    private function send(User $user, Game $game, string $kind): void
    {
        $title = 'Скоро игра';
        $body = 'Ваша игра скоро начнётся';

        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => 'game_reminder',
            'category' => 'game',
            'data' => ['game_id' => $game->id, 'kind' => $kind],
        ]);

        try {
            app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
                'type' => 'game_reminder',
                'game_id' => (string) $game->id,
                'kind' => $kind,
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->warning('Game reminder FCM failed: ' . $e->getMessage());
        }
    }
}
```

В `bootstrap/app.php`, внутри `->withSchedule(function (Schedule $schedule) { ... })`, рядом с `bookings:send-reminders`, добавить:
```php
        $schedule->command('games:send-reminders')->everyMinute()->withoutOverlapping();
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameRemindersCommandTest.php` → PASS (3).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/SendGameReminders.php bootstrap/app.php tests/Feature/Games/GameRemindersCommandTest.php
git commit -m "feat(games): команда напоминаний об играх + расписание (S12)"
```

---

## Порядок выполнения
Task 1 (миграция+модель) → 2 (команда использует колонки).

## Деплой
Миграцию накатывать отдельно: `php artisan migrate --path=database/migrations/2026_07_30_000001_add_reminder_flags_to_games_table.php`.

## Не входит (следующие слайсы)
Спор (dispute) — Фаза 2; S13 Flutter-экраны; S14 удаление старого challenge.

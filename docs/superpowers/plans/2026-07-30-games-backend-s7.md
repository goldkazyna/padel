# Games Module — Backend S7 (Журнал действий игры: game_action_logs) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Вести журнал ключевых действий по игре (`game_action_logs`) для прозрачности/«отменяемости» и отдавать его участникам через отдельный эндпоинт.

**Architecture:** Расширяем `Api\MobileGameController` (S0–S8 в main). Добавляем константы действий в `GameActionLog`, приватный `logGameAction(Game,int,string,array)`, вызовы в стейт-меняющих методах, и `GET /games/{game}/logs`.

**Tech Stack:** Laravel 12, Sanctum, PHPUnit sqlite :memory:.

## Design-решения (записаны намеренно)
- **Скоуп «отменяемости»:** реверт состояния уже покрыт существующими эндпоинтами (`start/cancel` отменяет старт; `rounds` PUT/DELETE правят/удаляют счёт). S7 добавляет ЖУРНАЛ (аудит-трейл) этих действий + чтение, без нового generic-undo движка.
- **Что логируем** (действия организатора/жизненного цикла): `start`, `start_cancel`, `finish`, `round_add`, `round_update`, `round_delete`, `player_remove`, `schedule_regenerate`. Членские действия (invite/accept/…) уже шлют уведомления — их не логируем в этом слайсе (можно добавить позже).
- **Payload** — небольшой JSON-контекст (например, `round_no`, `removed_user_id`), не весь объект.
- **Чтение** — любой принятый участник ИЛИ организатор видит журнал игры.

## Global Constraints
- НЕ трогать RatingCalculator/AmericanoRanking/AmericanoService/challenge. Никаких записей рейтинга.
- Логирование — аддитивно: существующие ответы/поведение методов не менять сверх добавления записи в журнал (и то в конце успешной операции).
- Ошибки чтения → `403/404`. Журнал отдаём по убыванию времени.
- Ветка от main (`feature/games-backend-s7`), не работать на main, не пушить. Новых миграций нет (таблица `game_action_logs` уже создана).

---

### Task 1: Хелпер logGameAction + константы + запись в стейт-методах

**Files:**
- Modify: `app/Models/GameActionLog.php` (константы действий)
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (приватный `logGameAction`; вызовы в `start`, `startCancel`, `finish`, `addRound`, `updateRound`, `deleteRound`, `removePlayer`, `regenerateSchedule`)
- Test: `tests/Feature/Games/GameActionLogTest.php`

**Interfaces:**
- Produces:
  - Константы в `GameActionLog`: `ACTION_START='start'`, `ACTION_START_CANCEL='start_cancel'`, `ACTION_FINISH='finish'`, `ACTION_ROUND_ADD='round_add'`, `ACTION_ROUND_UPDATE='round_update'`, `ACTION_ROUND_DELETE='round_delete'`, `ACTION_PLAYER_REMOVE='player_remove'`, `ACTION_SCHEDULE_REGENERATE='schedule_regenerate'`.
  - `private function logGameAction(Game $game, int $userId, string $action, array $payload = []): void` — `GameActionLog::create([...])`.
  - Вызов `logGameAction(...)` в конце успешной ветки каждого из перечисленных методов (до формирования ответа).

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameActionLogTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GameActionLog;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameActionLogTest extends TestCase
{
    use RefreshDatabase;

    private function fullGame(User $organizer): array
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'full', 'format' => 'sets']);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        return [$game, $ids];
    }

    public function test_start_logs_action(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->fullGame($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();

        $log = GameActionLog::where('game_id', $game->id)->where('action', GameActionLog::ACTION_START)->first();
        $this->assertNotNull($log);
        $this->assertSame($organizer->id, $log->user_id);
    }

    public function test_round_add_logs_action_with_round_no(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->fullGame($organizer);
        $game->update(['status' => 'in_progress']);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/rounds", [
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
            'score_a' => 6, 'score_b' => 3,
        ])->assertOk();

        $log = GameActionLog::where('game_id', $game->id)->where('action', GameActionLog::ACTION_ROUND_ADD)->first();
        $this->assertNotNull($log);
        $this->assertSame(1, $log->payload['round_no'] ?? null);
    }

    public function test_player_remove_logs_action(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->fullGame($organizer);
        Sanctum::actingAs($organizer);
        $target = GamePlayer::where('game_id', $game->id)->where('user_id', $ids[1])->first();

        $this->postJson("/api/mobile/games/{$game->id}/players/{$target->id}/remove")->assertOk();

        $log = GameActionLog::where('game_id', $game->id)->where('action', GameActionLog::ACTION_PLAYER_REMOVE)->first();
        $this->assertNotNull($log);
        $this->assertSame($ids[1], $log->payload['removed_user_id'] ?? null);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameActionLogTest.php`
Expected: FAIL — константы/логи отсутствуют.

- [ ] **Step 3: Реализовать**

В `app/Models/GameActionLog.php` добавить константы (в начале класса):
```php
    const ACTION_START = 'start';
    const ACTION_START_CANCEL = 'start_cancel';
    const ACTION_FINISH = 'finish';
    const ACTION_ROUND_ADD = 'round_add';
    const ACTION_ROUND_UPDATE = 'round_update';
    const ACTION_ROUND_DELETE = 'round_delete';
    const ACTION_PLAYER_REMOVE = 'player_remove';
    const ACTION_SCHEDULE_REGENERATE = 'schedule_regenerate';
```

В `MobileGameController` добавить импорт `use App\Models\GameActionLog;` (в шапку) и приватный хелпер (рядом с `notifyGame`):
```php
    /** Записать действие в журнал игры. */
    private function logGameAction(Game $game, int $userId, string $action, array $payload = []): void
    {
        GameActionLog::create([
            'game_id' => $game->id,
            'user_id' => $userId,
            'action' => $action,
            'payload' => $payload ?: null,
        ]);
    }
```

Добавить вызовы в конце успешной ветки каждого метода (перед `return response()->json(...)`):
- `start`: `$this->logGameAction($game, $user->id, GameActionLog::ACTION_START);`
- `startCancel`: `$this->logGameAction($game, $user->id, GameActionLog::ACTION_START_CANCEL);`
- `finish`: `$this->logGameAction($game, $user->id, GameActionLog::ACTION_FINISH);`
- `addRound` (после создания `$round`): `$this->logGameAction($game, $user->id, GameActionLog::ACTION_ROUND_ADD, ['round_no' => $nextNo]);`
- `updateRound`: `$this->logGameAction($game, $user->id, GameActionLog::ACTION_ROUND_UPDATE, ['round_no' => $round->round_no]);`
- `deleteRound` (до `$round->delete()` зафиксировать номер): `$this->logGameAction($game, $user->id, GameActionLog::ACTION_ROUND_DELETE, ['round_no' => $round->round_no]);`
- `removePlayer` (зная удаляемого `$player->user_id`): `$this->logGameAction($game, $user->id, GameActionLog::ACTION_PLAYER_REMOVE, ['removed_user_id' => $player->user_id]);`
- `regenerateSchedule`: `$this->logGameAction($game, $user->id, GameActionLog::ACTION_SCHEDULE_REGENERATE);`

> ПРИМЕЧАНИЕ реализатору: вставляй вызов после того, как операция реально применена (например, в `removePlayer` — после установки статуса removed; в `deleteRound` — считай `round_no` ДО удаления), и до сериализации ответа. Не менять существующие проверки/статусы.

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameActionLogTest.php` → PASS (3).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Models/GameActionLog.php app/Http/Controllers/Api/MobileGameController.php tests/Feature/Games/GameActionLogTest.php
git commit -m "feat(games): журнал действий игры — запись в стейт-методах (S7)"
```

---

### Task 2: GET /games/{game}/logs — чтение журнала

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (метод `logs`)
- Modify: `routes/api.php` (роут GET `/games/{game}/logs`)
- Test: `tests/Feature/Games/GameLogsReadTest.php`

**Interfaces:**
- Consumes: `logGameAction` (Task 1).
- Produces:
  - `public function logs(Request $request, Game $game)` — организатор ИЛИ принятый участник; иначе 403. Возвращает журнал по убыванию `created_at`: `[{id, user_id, user_name, action, payload, created_at}]`.
  - Роут: `Route::get('/games/{game}/logs', [MobileGameController::class, 'logs']);`

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameLogsReadTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GameActionLog;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameLogsReadTest extends TestCase
{
    use RefreshDatabase;

    private function gameWithLog(User $organizer): Game
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress', 'format' => 'sets']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        GameActionLog::create(['game_id' => $game->id, 'user_id' => $organizer->id, 'action' => GameActionLog::ACTION_START, 'payload' => null]);
        return $game;
    }

    public function test_organizer_reads_logs(): void
    {
        $organizer = User::factory()->create();
        $game = $this->gameWithLog($organizer);
        Sanctum::actingAs($organizer);

        $this->getJson("/api/mobile/games/{$game->id}/logs")
            ->assertOk()
            ->assertJsonPath('data.0.action', GameActionLog::ACTION_START)
            ->assertJsonPath('data.0.user_id', $organizer->id);
    }

    public function test_outsider_cannot_read_logs(): void
    {
        $organizer = User::factory()->create();
        $game = $this->gameWithLog($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/games/{$game->id}/logs")->assertStatus(403);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameLogsReadTest.php`
Expected: FAIL — метод/роут не существуют.

- [ ] **Step 3: Реализовать**

Добавить метод в `MobileGameController` (например, после `deleteRound`):
```php
    /** Журнал действий игры (организатор или принятый участник). */
    public function logs(Request $request, Game $game)
    {
        $user = $request->user();
        $isParticipant = $game->players()
            ->where('user_id', $user->id)
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->exists();
        if (!$game->isOrganizer($user->id) && !$isParticipant) {
            return response()->json(['success' => false, 'message' => 'Нет доступа к журналу'], 403);
        }

        $logs = GameActionLog::where('game_id', $game->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'user_id' => $l->user_id,
                'user_name' => $l->user->name ?? null,
                'action' => $l->action,
                'payload' => $l->payload,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $logs]);
    }
```

В `routes/api.php`, в блоке игр (рядом с `rounds`), добавить:
```php
        Route::get('/games/{game}/logs', [MobileGameController::class, 'logs']);
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameLogsReadTest.php` → PASS (2).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameLogsReadTest.php
git commit -m "feat(games): чтение журнала действий игры (S7)"
```

---

## Порядок выполнения
Task 1 → 2 (Task 2 читает то, что пишет Task 1).

## Не входит (следующие слайсы)
S9 передача прав организатора; S10 лента+фильтры+пагинация; S11 инбокс приглашений; S12 пуши/напоминания; спор (dispute) — Фаза 2; S13 Flutter; S14 удаление challenge.

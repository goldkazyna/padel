# Games Module — Backend S9 (Передача прав организатора: game_transfers) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Организатор может передать права другому принятому участнику: инициировать передачу (pending), отменить её; целевой участник — принять (тогда `creator_id` игры меняется) или отклонить.

**Architecture:** Расширяем `Api\MobileGameController` (S0–S8 в main). Используем существующую таблицу/модель `game_transfers` (константы статусов уже есть). Добавляем 4 эндпоинта + роуты. Уведомления через существующий `notifyGame`.

**Tech Stack:** Laravel 12, Sanctum, PHPUnit sqlite :memory:.

## Design-решения (записаны намеренно)
- **Инициация:** только текущий организатор; цель — принятый (`accepted`) участник, не сам организатор; игра не `finished`/`cancelled`. Создаётся строка `game_transfers` (pending). Если уже есть pending — переиспользуем/обновляем (одна активная передача за раз).
- **Отмена:** только инициатор (организатор), пока pending → статус `cancelled`.
- **Принятие:** только целевой пользователь, пока pending → `creator_id` игры = `to_user_id`, статус `accepted`. Прочие pending-передачи этой игры (если вдруг) закрываются `cancelled`.
- **Отклонение:** только целевой пользователь, пока pending → `declined`.
- Уведомления: цель получает `game_transfer_offer` при инициации; инициатор — `game_transfer_accepted`/`game_transfer_declined`.

## Global Constraints
- НЕ трогать RatingCalculator/AmericanoRanking/AmericanoService/challenge. Никаких записей рейтинга.
- Смена `creator_id` — единственное, что меняет владельца; роль организатора везде определяется `isOrganizer()` (`creator_id === userId`), так что смены достаточно.
- Ошибки → `403/422 {success:false, message}`.
- Ветка от main (`feature/games-backend-s9`), не работать на main, не пушить. Новых миграций нет.

---

### Task 1: Инициация и отмена передачи

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (`transferInitiate`, `transferCancel`)
- Modify: `routes/api.php` (2 роута)
- Test: `tests/Feature/Games/GameTransferInitiateTest.php`

**Interfaces:**
- Produces:
  - `public function transferInitiate(Request $request, Game $game)` — body `to_user_id`. Организатор; цель — accepted-участник ≠ организатор; игра не finished/cancelled. Обновляет-или-создаёт pending `GameTransfer(from=organizer,to=target)`. Уведомляет цель (`game_transfer_offer`). Возвращает `formatGame`.
  - `public function transferCancel(Request $request, Game $game)` — организатор; берёт последнюю pending-передачу игры от организатора → `cancelled`. Возвращает `formatGame`.
  - Роуты: `POST /games/{game}/transfer` (initiate), `POST /games/{game}/transfer/cancel`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameTransferInitiateTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameTransferInitiateTest extends TestCase
{
    use RefreshDatabase;

    /** [game, [organizer_id, other_id]] с двумя accepted. */
    private function game(User $organizer): array
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'full', 'format' => 'sets']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        $other = User::factory()->create();
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $other->id, 'position' => 2, 'status' => GamePlayer::STATUS_ACCEPTED]);
        return [$game, [$organizer->id, $other->id]];
    }

    public function test_organizer_initiates_transfer(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->game($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/transfer", ['to_user_id' => $ids[1]])->assertOk();

        $t = GameTransfer::where('game_id', $game->id)->first();
        $this->assertNotNull($t);
        $this->assertSame(GameTransfer::STATUS_PENDING, $t->status);
        $this->assertSame($ids[0], $t->from_user_id);
        $this->assertSame($ids[1], $t->to_user_id);
    }

    public function test_cannot_transfer_to_non_participant(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->game($organizer);
        $outsider = User::factory()->create();
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/transfer", ['to_user_id' => $outsider->id])->assertStatus(422);
    }

    public function test_non_organizer_cannot_initiate(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->game($organizer);
        Sanctum::actingAs(User::find($ids[1]));

        $this->postJson("/api/mobile/games/{$game->id}/transfer", ['to_user_id' => $ids[0]])->assertStatus(403);
    }

    public function test_organizer_cancels_pending_transfer(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->game($organizer);
        Sanctum::actingAs($organizer);
        $this->postJson("/api/mobile/games/{$game->id}/transfer", ['to_user_id' => $ids[1]])->assertOk();

        $this->postJson("/api/mobile/games/{$game->id}/transfer/cancel")->assertOk();
        $this->assertSame(GameTransfer::STATUS_CANCELLED, GameTransfer::where('game_id', $game->id)->first()->status);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameTransferInitiateTest.php`
Expected: FAIL — методы/роуты не существуют.

- [ ] **Step 3: Реализовать**

Добавить импорт `use App\Models\GameTransfer;` и методы в `MobileGameController` (например, после `removePlayer`):
```php
    /** Инициировать передачу прав организатора принятому участнику. */
    public function transferInitiate(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if (in_array($game->status, [Game::STATUS_FINISHED, Game::STATUS_CANCELLED], true)) {
            return response()->json(['success' => false, 'message' => 'Игра завершена'], 422);
        }
        $data = $request->validate(['to_user_id' => 'required|integer']);
        if ((int) $data['to_user_id'] === $user->id) {
            return response()->json(['success' => false, 'message' => 'Нельзя передать самому себе'], 422);
        }
        $isAccepted = $game->players()
            ->where('user_id', $data['to_user_id'])
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->exists();
        if (!$isAccepted) {
            return response()->json(['success' => false, 'message' => 'Получатель должен быть участником игры'], 422);
        }

        $transfer = GameTransfer::where('game_id', $game->id)
            ->where('status', GameTransfer::STATUS_PENDING)
            ->first();
        if ($transfer) {
            $transfer->update(['from_user_id' => $user->id, 'to_user_id' => $data['to_user_id']]);
        } else {
            GameTransfer::create([
                'game_id' => $game->id,
                'from_user_id' => $user->id,
                'to_user_id' => $data['to_user_id'],
                'status' => GameTransfer::STATUS_PENDING,
            ]);
        }

        $target = User::find($data['to_user_id']);
        if ($target) {
            $this->notifyGame($target, 'Передача прав', 'Вам предлагают стать организатором игры', 'game_transfer_offer', $game->id);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Отменить свою pending-передачу (организатор). */
    public function transferCancel(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        $transfer = GameTransfer::where('game_id', $game->id)
            ->where('status', GameTransfer::STATUS_PENDING)
            ->latest('id')
            ->first();
        if (!$transfer) {
            return response()->json(['success' => false, 'message' => 'Нет активной передачи'], 422);
        }
        $transfer->update(['status' => GameTransfer::STATUS_CANCELLED]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }
```

В `routes/api.php`, в блоке игр:
```php
        Route::post('/games/{game}/transfer', [MobileGameController::class, 'transferInitiate']);
        Route::post('/games/{game}/transfer/cancel', [MobileGameController::class, 'transferCancel']);
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameTransferInitiateTest.php` → PASS (4).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameTransferInitiateTest.php
git commit -m "feat(games): инициация и отмена передачи прав (S9)"
```

---

### Task 2: Принятие и отклонение передачи

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (`transferAccept`, `transferDecline`)
- Modify: `routes/api.php` (2 роута)
- Test: `tests/Feature/Games/GameTransferRespondTest.php`

**Interfaces:**
- Consumes: pending `GameTransfer` из Task 1.
- Produces:
  - `public function transferAccept(Request $request, Game $game)` — только `to_user_id` активной pending-передачи; ставит `creator_id=to_user_id`, передачу → `accepted`, прочие pending этой игры → `cancelled`. Уведомляет прежнего организатора (`game_transfer_accepted`). Возвращает `formatGame`.
  - `public function transferDecline(Request $request, Game $game)` — только цель; передачу → `declined`; уведомляет инициатора (`game_transfer_declined`). Возвращает `formatGame`.
  - Роуты: `POST /games/{game}/transfer/accept`, `POST /games/{game}/transfer/decline`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameTransferRespondTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameTransferRespondTest extends TestCase
{
    use RefreshDatabase;

    /** Игра с pending-передачей organizer→other. [game, organizerId, otherId]. */
    private function pendingTransfer(): array
    {
        $organizer = User::factory()->create();
        $other = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'full', 'format' => 'sets']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $other->id, 'position' => 2, 'status' => GamePlayer::STATUS_ACCEPTED]);
        GameTransfer::create(['game_id' => $game->id, 'from_user_id' => $organizer->id, 'to_user_id' => $other->id, 'status' => GameTransfer::STATUS_PENDING]);
        return [$game, $organizer->id, $other->id];
    }

    public function test_target_accepts_and_becomes_organizer(): void
    {
        [$game, $orgId, $otherId] = $this->pendingTransfer();
        Sanctum::actingAs(User::find($otherId));

        $this->postJson("/api/mobile/games/{$game->id}/transfer/accept")->assertOk();

        $this->assertSame($otherId, $game->fresh()->creator_id);
        $this->assertSame(GameTransfer::STATUS_ACCEPTED, GameTransfer::where('game_id', $game->id)->first()->status);
    }

    public function test_non_target_cannot_accept(): void
    {
        [$game, $orgId] = $this->pendingTransfer();
        Sanctum::actingAs(User::find($orgId)); // инициатор, не цель

        $this->postJson("/api/mobile/games/{$game->id}/transfer/accept")->assertStatus(403);
        $this->assertSame($orgId, $game->fresh()->creator_id);
    }

    public function test_target_declines(): void
    {
        [$game, $orgId, $otherId] = $this->pendingTransfer();
        Sanctum::actingAs(User::find($otherId));

        $this->postJson("/api/mobile/games/{$game->id}/transfer/decline")->assertOk();

        $this->assertSame($orgId, $game->fresh()->creator_id);
        $this->assertSame(GameTransfer::STATUS_DECLINED, GameTransfer::where('game_id', $game->id)->first()->status);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameTransferRespondTest.php`
Expected: FAIL — методы/роуты не существуют.

- [ ] **Step 3: Реализовать**

Добавить методы в `MobileGameController` (после `transferCancel`):
```php
    /** Принять передачу прав (только целевой участник). */
    public function transferAccept(Request $request, Game $game)
    {
        $user = $request->user();
        $transfer = GameTransfer::where('game_id', $game->id)
            ->where('status', GameTransfer::STATUS_PENDING)
            ->where('to_user_id', $user->id)
            ->latest('id')
            ->first();
        if (!$transfer) {
            return response()->json(['success' => false, 'message' => 'Нет передачи для вас'], 403);
        }

        $previousOwner = $game->creator_id;
        $game->update(['creator_id' => $user->id]);
        $transfer->update(['status' => GameTransfer::STATUS_ACCEPTED]);
        // Прочие pending этой игры закрываем.
        GameTransfer::where('game_id', $game->id)
            ->where('status', GameTransfer::STATUS_PENDING)
            ->update(['status' => GameTransfer::STATUS_CANCELLED]);

        $prev = User::find($previousOwner);
        if ($prev) {
            $this->notifyGame($prev, 'Права переданы', 'Участник принял роль организатора', 'game_transfer_accepted', $game->id);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Отклонить передачу прав (только целевой участник). */
    public function transferDecline(Request $request, Game $game)
    {
        $user = $request->user();
        $transfer = GameTransfer::where('game_id', $game->id)
            ->where('status', GameTransfer::STATUS_PENDING)
            ->where('to_user_id', $user->id)
            ->latest('id')
            ->first();
        if (!$transfer) {
            return response()->json(['success' => false, 'message' => 'Нет передачи для вас'], 403);
        }

        $transfer->update(['status' => GameTransfer::STATUS_DECLINED]);
        $initiator = User::find($transfer->from_user_id);
        if ($initiator) {
            $this->notifyGame($initiator, 'Передача отклонена', 'Участник отказался стать организатором', 'game_transfer_declined', $game->id);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }
```

В `routes/api.php`, рядом с `transfer/cancel`:
```php
        Route::post('/games/{game}/transfer/accept', [MobileGameController::class, 'transferAccept']);
        Route::post('/games/{game}/transfer/decline', [MobileGameController::class, 'transferDecline']);
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameTransferRespondTest.php` → PASS (3).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameTransferRespondTest.php
git commit -m "feat(games): принятие и отклонение передачи прав (S9)"
```

---

## Порядок выполнения
Task 1 → 2 (Task 2 отвечает на передачу из Task 1).

## Не входит (следующие слайсы)
S10 лента+фильтры+пагинация; S11 инбокс приглашений; S12 пуши/напоминания; спор (dispute) — Фаза 2; S13 Flutter; S14 удаление challenge.

# Games Module — Backend S4 (Движок счёта: старт + раунды) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Провести игру: старт (full → in_progress) и ведение счёта через универсальные раунды (`game_rounds`) — добавить/изменить/удалить раунд с парами и счётом. Формат `sets` (сет = раунд, пары на уровне сета). ELO НЕ начисляем (это S8).

**Architecture:** Расширяем `Api\MobileGameController` (S0–S3 в main). Универсальные раунд-эндпоинты работают для `sets` и `points` (ручные пары); `americano` авто-генерит раунды на старте (S6). `formatGame` дополняется массивом `rounds` (аддитивно). Раунды хранятся в `game_rounds` (S0): `pair_a`/`pair_b` (JSON [user_id,user_id]), `score_a`/`score_b`, `tiebreak_a`/`tiebreak_b`, `is_played`.

**Tech Stack:** Laravel 12, Sanctum, PHPUnit sqlite :memory:.

## Global Constraints
- НЕ трогать `RatingCalculator`, `AmericanoRanking`, старый `challenge`. Никаких записей рейтинга в этом слайсе.
- Мутирующие раунд-действия — только организатор (403), только пока `score_locked=false` (иначе 422), только когда игра `in_progress` (иначе 422).
- Пары раунда: `pair_a` и `pair_b` — по 2 разных `user_id`, все 4 различны, каждый — `accepted`-участник этой игры.
- `formatGame` дополняется ключом `rounds` (список), существующие ключи не меняются.
- Ответ `{success, data}`.
- Ветка от main (`feature/games-backend-s4`), не работать на main, не пушить.

---

### Task 1: Старт игры и отмена старта (start / startCancel)

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (методы `start`, `startCancel`)
- Modify: `routes/api.php` (2 роута)
- Test: `tests/Feature/Games/GameStartTest.php`

**Interfaces:**
- Produces:
  - `start(Request, Game)` — только организатор (403). Игра должна быть `full` (4 accepted) (иначе 422 «Соберите 4 игроков»). Не начата (`status` не in_progress/finished/disputed, иначе 422). Ставит `status=in_progress`. Ответ `{success, data: formatGame}`.
  - `startCancel(Request, Game)` — только организатор (403). Игра `in_progress` и `score_locked=false` (иначе 422). Возвращает `status` через `syncFullness` (пересчёт open/full). Но `syncFullness` работает только для [open,full]; поэтому сперва ставим `status=full` затем `syncFullness`. Ответ `{success, data: formatGame}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameStartTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameStartTest extends TestCase
{
    use RefreshDatabase;

    private function fullGame(User $organizer): Game
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'full']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        return $game;
    }

    public function test_organizer_starts_full_game(): void
    {
        $organizer = User::factory()->create();
        $game = $this->fullGame($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertSame('in_progress', $game->fresh()->status);
    }

    public function test_cannot_start_non_full_game(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertStatus(422);
    }

    public function test_non_organizer_cannot_start(): void
    {
        $organizer = User::factory()->create();
        $game = $this->fullGame($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertStatus(403);
    }

    public function test_cancel_start_returns_to_full(): void
    {
        $organizer = User::factory()->create();
        $game = $this->fullGame($organizer);
        $game->update(['status' => 'in_progress']);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start/cancel")->assertOk();
        $this->assertSame('full', $game->fresh()->status);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameStartTest.php`
Expected: FAIL — роуты не зарегистрированы.

- [ ] **Step 3: Реализовать**

```php
    /** Начать игру: full → in_progress (только организатор). */
    public function start(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if (in_array($game->status, [Game::STATUS_IN_PROGRESS, Game::STATUS_FINISHED, Game::STATUS_DISPUTED], true)) {
            return response()->json(['success' => false, 'message' => 'Игра уже начата'], 422);
        }
        if ($game->status !== Game::STATUS_FULL || $game->acceptedCount() < (int) $game->capacity) {
            return response()->json(['success' => false, 'message' => 'Соберите всех игроков перед стартом'], 422);
        }

        $game->update(['status' => Game::STATUS_IN_PROGRESS]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Отменить старт: in_progress → full/open (пока счёт не залочен). */
    public function startCancel(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Старт нельзя отменить'], 422);
        }

        $game->update(['status' => Game::STATUS_FULL]);
        $this->syncFullness($game);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }
```

Роуты (auth-группа):
```php
        Route::post('/games/{game}/start', [MobileGameController::class, 'start']);
        Route::post('/games/{game}/start/cancel', [MobileGameController::class, 'startCancel']);
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameStartTest.php`
Expected: PASS (4 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameStartTest.php
git commit -m "feat(games): старт игры + отмена старта (S4)"
```

---

### Task 2: formatGame → rounds + добавление раунда (addRound)

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (расширить `formatGame`; приватный `validateRoundPairs`; метод `addRound`)
- Modify: `routes/api.php` (роут)
- Test: `tests/Feature/Games/GameRoundAddTest.php`

**Interfaces:**
- Consumes: `GameRound`.
- Produces:
  - `formatGame` дополнительно возвращает `rounds` — массив `[{id, round_no, pair_a, pair_b, score_a, score_b, tiebreak_a, tiebreak_b, is_played}]` (из `$game->rounds`, отсортированы по `round_no`).
  - `private function validateRoundPairs(Game $game, array $pairA, array $pairB): ?string` — возвращает текст ошибки или null. Проверяет: в `pairA` и `pairB` ровно по 2 элемента; все 4 различны; каждый `user_id` — `accepted`-участник игры.
  - `addRound(Request, Game)` — организатор (403); игра `in_progress` и `!score_locked` (иначе 422); body `pair_a` (array 2), `pair_b` (array 2), опц `score_a`/`score_b`/`tiebreak_a`/`tiebreak_b` (int>=0). Валидирует пары (`validateRoundPairs`, ошибка → 422). Создаёт `GameRound` с `round_no = (max round_no)+1`, `is_played = (score_a !== null && score_b !== null)`. Ответ `{success, data: formatGame}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameRoundAddTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameRoundAddTest extends TestCase
{
    use RefreshDatabase;

    /** in_progress игра с 4 accepted; возвращает [game, [u1,u2,u3,u4]]. */
    private function startedGame(User $organizer): array
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress']);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        return [$game, $ids];
    }

    public function test_organizer_adds_round_with_score(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->startedGame($organizer);
        Sanctum::actingAs($organizer);

        $res = $this->postJson("/api/mobile/games/{$game->id}/rounds", [
            'pair_a' => [$ids[0], $ids[1]],
            'pair_b' => [$ids[2], $ids[3]],
            'score_a' => 6, 'score_b' => 4,
        ])->assertOk();

        $res->assertJsonPath('data.rounds.0.round_no', 1);
        $round = GameRound::where('game_id', $game->id)->first();
        $this->assertSame(6, $round->score_a);
        $this->assertTrue((bool) $round->is_played);
        $this->assertSame([$ids[0], $ids[1]], $round->pair_a);
    }

    public function test_round_pairs_must_be_accepted_players(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->startedGame($organizer);
        $outsider = User::factory()->create();
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/rounds", [
            'pair_a' => [$ids[0], $outsider->id],
            'pair_b' => [$ids[2], $ids[3]],
        ])->assertStatus(422);
    }

    public function test_non_organizer_cannot_add_round(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->startedGame($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/rounds", [
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
        ])->assertStatus(403);
    }

    public function test_cannot_add_round_when_not_in_progress(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->startedGame($organizer);
        $game->update(['status' => 'full']);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/rounds", [
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameRoundAddTest.php`
Expected: FAIL — роут/ключ rounds отсутствуют.

- [ ] **Step 3: Реализовать**

Добавить `use App\Models\GameRound;`.

Расширить `formatGame`: перед `return [...]` добавить сбор раундов, и в возвращаемый массив (перед закрывающей `];`) добавить ключ `rounds`:
```php
        $rounds = $game->relationLoaded('rounds')
            ? $game->rounds
            : $game->rounds()->orderBy('round_no')->get();
```
```php
            'rounds' => $rounds->map(fn ($r) => [
                'id' => $r->id,
                'round_no' => $r->round_no,
                'pair_a' => $r->pair_a,
                'pair_b' => $r->pair_b,
                'score_a' => $r->score_a,
                'score_b' => $r->score_b,
                'tiebreak_a' => $r->tiebreak_a,
                'tiebreak_b' => $r->tiebreak_b,
                'is_played' => (bool) $r->is_played,
            ])->values(),
```

Добавить валидатор пар и метод:
```php
    /** Проверка пар раунда. Возвращает текст ошибки или null. */
    private function validateRoundPairs(Game $game, array $pairA, array $pairB): ?string
    {
        if (count($pairA) !== 2 || count($pairB) !== 2) {
            return 'В каждой паре должно быть по 2 игрока';
        }
        $all = array_merge($pairA, $pairB);
        if (count(array_unique($all)) !== 4) {
            return 'Игроки в парах не должны повторяться';
        }
        $acceptedIds = $game->players()
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->pluck('user_id')->all();
        foreach ($all as $uid) {
            if (!in_array($uid, $acceptedIds)) {
                return 'Все игроки раунда должны быть участниками игры';
            }
        }
        return null;
    }

    /** Добавить раунд (сет/партию) с парами и опциональным счётом. */
    public function addRound(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Счёт можно вводить только у идущей игры'], 422);
        }

        $data = $request->validate([
            'pair_a' => 'required|array',
            'pair_b' => 'required|array',
            'pair_a.*' => 'integer',
            'pair_b.*' => 'integer',
            'score_a' => 'nullable|integer|min:0',
            'score_b' => 'nullable|integer|min:0',
            'tiebreak_a' => 'nullable|integer|min:0',
            'tiebreak_b' => 'nullable|integer|min:0',
        ]);

        $err = $this->validateRoundPairs($game, $data['pair_a'], $data['pair_b']);
        if ($err !== null) {
            return response()->json(['success' => false, 'message' => $err], 422);
        }

        $nextNo = (int) ($game->rounds()->max('round_no') ?? 0) + 1;
        $played = array_key_exists('score_a', $data) && $data['score_a'] !== null
            && array_key_exists('score_b', $data) && $data['score_b'] !== null;

        GameRound::create([
            'game_id' => $game->id,
            'round_no' => $nextNo,
            'pair_a' => array_values($data['pair_a']),
            'pair_b' => array_values($data['pair_b']),
            'score_a' => $data['score_a'] ?? null,
            'score_b' => $data['score_b'] ?? null,
            'tiebreak_a' => $data['tiebreak_a'] ?? null,
            'tiebreak_b' => $data['tiebreak_b'] ?? null,
            'is_played' => $played,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }
```

Роут (auth-группа):
```php
        Route::post('/games/{game}/rounds', [MobileGameController::class, 'addRound']);
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameRoundAddTest.php`
Expected: PASS (4 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameRoundAddTest.php
git commit -m "feat(games): rounds в formatGame + добавление раунда (S4)"
```

---

### Task 3: Изменение счёта раунда (updateRound)

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (метод `updateRound`)
- Modify: `routes/api.php` (роут)
- Test: `tests/Feature/Games/GameRoundUpdateTest.php`

**Interfaces:**
- Produces: `updateRound(Request, Game, GameRound $round)` — организатор (403); `round->game_id === game->id` иначе 422; игра `in_progress` и `!score_locked` иначе 422. Body опц `pair_a`/`pair_b` (если оба переданы — валидировать), `score_a`/`score_b`/`tiebreak_a`/`tiebreak_b` (int>=0). Обновляет переданные поля; пересчитывает `is_played` = (итоговые score_a и score_b не null). Ответ `{success, data: formatGame}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameRoundUpdateTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameRoundUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function gameWithRound(User $organizer): array
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress']);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        $round = GameRound::create([
            'game_id' => $game->id, 'round_no' => 1,
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
            'score_a' => null, 'score_b' => null, 'is_played' => false,
        ]);
        return [$game, $round];
    }

    public function test_organizer_updates_score_sets_is_played(): void
    {
        $organizer = User::factory()->create();
        [$game, $round] = $this->gameWithRound($organizer);
        Sanctum::actingAs($organizer);

        $this->putJson("/api/mobile/games/{$game->id}/rounds/{$round->id}", ['score_a' => 6, 'score_b' => 3])->assertOk();
        $round->refresh();
        $this->assertSame(6, $round->score_a);
        $this->assertTrue((bool) $round->is_played);
    }

    public function test_non_organizer_cannot_update_round(): void
    {
        $organizer = User::factory()->create();
        [$game, $round] = $this->gameWithRound($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/mobile/games/{$game->id}/rounds/{$round->id}", ['score_a' => 6, 'score_b' => 3])->assertStatus(403);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameRoundUpdateTest.php`
Expected: FAIL — роут не зарегистрирован.

- [ ] **Step 3: Реализовать**

```php
    /** Изменить раунд (счёт/пары). */
    public function updateRound(Request $request, Game $game, GameRound $round)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($round->game_id !== $game->id) {
            return response()->json(['success' => false, 'message' => 'Раунд не найден'], 422);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Счёт можно менять только у идущей игры'], 422);
        }

        $data = $request->validate([
            'pair_a' => 'nullable|array',
            'pair_b' => 'nullable|array',
            'pair_a.*' => 'integer',
            'pair_b.*' => 'integer',
            'score_a' => 'nullable|integer|min:0',
            'score_b' => 'nullable|integer|min:0',
            'tiebreak_a' => 'nullable|integer|min:0',
            'tiebreak_b' => 'nullable|integer|min:0',
        ]);

        if (isset($data['pair_a'], $data['pair_b'])) {
            $err = $this->validateRoundPairs($game, $data['pair_a'], $data['pair_b']);
            if ($err !== null) {
                return response()->json(['success' => false, 'message' => $err], 422);
            }
            $round->pair_a = array_values($data['pair_a']);
            $round->pair_b = array_values($data['pair_b']);
        }

        foreach (['score_a', 'score_b', 'tiebreak_a', 'tiebreak_b'] as $field) {
            if (array_key_exists($field, $data)) {
                $round->{$field} = $data[$field];
            }
        }
        $round->is_played = $round->score_a !== null && $round->score_b !== null;
        $round->save();

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }
```

Роут (auth-группа):
```php
        Route::put('/games/{game}/rounds/{round}', [MobileGameController::class, 'updateRound']);
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameRoundUpdateTest.php`
Expected: PASS (2 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameRoundUpdateTest.php
git commit -m "feat(games): изменение счёта раунда (S4)"
```

---

### Task 4: Удаление раунда (deleteRound)

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (метод `deleteRound`)
- Modify: `routes/api.php` (роут)
- Test: `tests/Feature/Games/GameRoundDeleteTest.php`

**Interfaces:**
- Produces: `deleteRound(Request, Game, GameRound $round)` — организатор (403); `round->game_id === game->id` иначе 422; игра `in_progress` и `!score_locked` иначе 422. Удаляет раунд. Ответ `{success, data: formatGame}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameRoundDeleteTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameRoundDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_deletes_round(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        $round = GameRound::create(['game_id' => $game->id, 'round_no' => 1, 'pair_a' => [1, 2], 'pair_b' => [3, 4], 'is_played' => false]);
        Sanctum::actingAs($organizer);

        $this->deleteJson("/api/mobile/games/{$game->id}/rounds/{$round->id}")->assertOk();
        $this->assertNull(GameRound::find($round->id));
    }

    public function test_non_organizer_cannot_delete_round(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress']);
        $round = GameRound::create(['game_id' => $game->id, 'round_no' => 1, 'pair_a' => [1, 2], 'pair_b' => [3, 4], 'is_played' => false]);
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/mobile/games/{$game->id}/rounds/{$round->id}")->assertStatus(403);
        $this->assertNotNull(GameRound::find($round->id));
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameRoundDeleteTest.php`
Expected: FAIL — роут не зарегистрирован.

- [ ] **Step 3: Реализовать**

```php
    /** Удалить раунд. */
    public function deleteRound(Request $request, Game $game, GameRound $round)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($round->game_id !== $game->id) {
            return response()->json(['success' => false, 'message' => 'Раунд не найден'], 422);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Раунд можно удалить только у идущей игры'], 422);
        }

        $round->delete();

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }
```

Роут (auth-группа):
```php
        Route::delete('/games/{game}/rounds/{round}', [MobileGameController::class, 'deleteRound']);
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameRoundDeleteTest.php` → PASS (2).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameRoundDeleteTest.php
git commit -m "feat(games): удаление раунда (S4)"
```

---

## Порядок выполнения
Task 1 → 2 → 3 → 4. Task 3/4 используют `validateRoundPairs` и `rounds`-эндпоинт из Task 2.

## Не входит (следующие слайсы)
S5 points (format_meta first_to/total/target/cap, «партии»); S6 americano (авто-расписание на старте + личное ранжирование); S7 отменяемость (action_log, undo); S8 утверждение/подтверждение/спор + ELO (sets/points — средний рейтинг команд, americano — AmericanoRanking); S9 передача прав; S10 лента-пагинация; S11 инбокс; S12 пуши/напоминания; S13 Flutter; S14 удаление challenge.

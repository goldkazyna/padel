# Games Module — Backend S8 (Финал игры: подтверждение счёта + ELO) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Завершение игры «как поединок» (challenge): организатор фиксирует счёт → участники подтверждают → при полном подтверждении игра переходит в `finished`, и для rated-игр начисляется ELO (тем же `RatingCalculator`, что у турниров/поединков) в общий рейтинг игроков.

**Architecture:** Расширяем `Api\MobileGameController` (S0–S6 в main). Добавляем `use App\Traits\RatingCalculator;` в контроллер (как в `MobileChallengeController`), эндпоинты `finish` и `confirmScore` + роуты, и приватные `applyGameElo`/`applyPlayerRating` (миррор challenge). Счёт уже хранится по раундам (`game_rounds`), отдельного шага ввода счёта не нужно — `finish` лишь замораживает уже введённые раунды.

**Tech Stack:** Laravel 12, Sanctum, PHPUnit sqlite :memory:.

## Design-решения (записаны намеренно; можно пересмотреть)
- **Спор (dispute) — НЕ в S8** (перенесён в Фазу 2 по решению юзера). Статус `disputed` не используется в этом слайсе.
- **Поток «как challenge»**, адаптированный под то, что счёт в играх вводится по раундам:
  1. `finish()` — организатор, `in_progress`, есть ≥1 сыгранный раунд, `!score_locked`. Замораживает счёт (`score_locked=true`), авто-подтверждает организатора (`score_confirmed=true`). Игра остаётся `in_progress` + `score_locked=true` = «фаза подтверждения».
  2. `confirmScore()` — любой принятый участник в фазе подтверждения ставит своё `score_confirmed=true`. Когда подтвердили ВСЕ принятые игроки → статус `finished` и (если `type=rated`) начисляется ELO.
- **ELO — единообразно по сыгранным раундам** (как `AmericanoService::calculateEloForMatch`, обобщённое на все форматы): каждый сыгранный раунд = мини-матч 2×2, командный средний рейтинг → `calculateRatingChange` → `applyRatingChange` (пол 1000) с накоплением по игроку; коммит один раз. Для одно-раундовой sets/points игры это сводится к одному расчёту как у challenge. `GameAmericanoRanking` (S6) — только для отображения мест, не для ELO-математики.
- **Куда пишем (подтверждено юзером):** rated-игра пишет `game_players.rating_*`, `users.rating`(+level через `updateLevel`) и строку `rating_history` c `reason='game'`, `tournament_id=null`. Friendly-игра НЕ трогает рейтинг (как friendly-challenge), но всё равно завершается.
- **Идемпотентность:** ELO начисляется только в момент перехода в `finished` и только если текущий статус ещё не `finished`.

## Global Constraints
- НЕ трогать `RatingCalculator` (трейт — только `use` и вызов его методов), `AmericanoRanking`, `AmericanoTie`, `AmericanoService`, старый `challenge`. Трейт остаётся без изменений.
- ELO пишется ТОЛЬКО для `type === Game::TYPE_RATED`. Формула — существующий трейт (командный средний, адаптивный K, множитель разгрома, 0:0→без изменений, пол `minRating`=1000).
- Точная последовательность записи на игрока (миррор `MobileChallengeController::applyPlayerRating`): `GamePlayer.update(rating_before/after/change)` → `users.rating=after` → `updateLevel($user)` → `RatingHistory::create([... reason='game', tournament_id=null])`.
- Гварды организатора/статуса как у существующих методов. Ошибки → `422/403 {success:false, message}`.
- Ветка от main (`feature/games-backend-s8`), не работать на main, не пушить.
- Новых миграций НЕТ: `game_players.rating_before/rating_after/rating_change/score_confirmed` и `games.status='finished'`/`score_locked` уже существуют.

---

### Task 1: finish() — заморозка счёта + фаза подтверждения

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (метод `finish`; `score_confirmed` + `my_score_confirmed` в `formatGame`)
- Modify: `routes/api.php` (роут POST `/games/{game}/finish`)
- Test: `tests/Feature/Games/GameFinishTest.php`

**Interfaces:**
- Produces:
  - `public function finish(Request $request, Game $game)` — организатор; `status===IN_PROGRESS`; `!score_locked`; есть ≥1 раунд с `is_played=true`. Ставит `score_locked=true`, авто-подтверждает организатора (`GamePlayer.score_confirmed=true` для creator). Ответ — `formatGame`.
  - `formatGame` дополнительно кладёт `score_confirmed` в каждый элемент `players` и `my_score_confirmed` на верхнем уровне.
  - Роут: `Route::post('/games/{game}/finish', [MobileGameController::class, 'finish']);`

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameFinishTest.php`:
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

class GameFinishTest extends TestCase
{
    use RefreshDatabase;

    /** in_progress игра с 4 accepted и одним сыгранным раундом. Возвращает [game, [u1..u4]]. */
    private function playedGame(User $organizer, string $type = 'rated'): array
    {
        $game = Game::factory()->create([
            'creator_id' => $organizer->id, 'status' => 'in_progress',
            'type' => $type, 'format' => 'sets',
        ]);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        GameRound::create([
            'game_id' => $game->id, 'round_no' => 1,
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
            'score_a' => 6, 'score_b' => 3, 'is_played' => true,
        ]);
        return [$game, $ids];
    }

    public function test_organizer_finishes_locks_and_autoconfirms(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->playedGame($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/finish")
            ->assertOk()
            ->assertJsonPath('data.score_locked', true)
            ->assertJsonPath('data.my_score_confirmed', true);

        $this->assertTrue((bool) $game->fresh()->score_locked);
        $this->assertSame('in_progress', $game->fresh()->status); // фаза подтверждения
        $org = GamePlayer::where('game_id', $game->id)->where('user_id', $organizer->id)->first();
        $this->assertTrue((bool) $org->score_confirmed);
    }

    public function test_finish_requires_played_round(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress', 'format' => 'sets']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertStatus(422);
    }

    public function test_non_organizer_cannot_finish(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->playedGame($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertStatus(403);
    }

    public function test_cannot_finish_twice(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->playedGame($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertStatus(422); // уже score_locked
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameFinishTest.php`
Expected: FAIL — метод/роут не существуют.

- [ ] **Step 3: Реализовать**

Добавить метод в `MobileGameController` (например, после `startCancel`):
```php
    /** Зафиксировать счёт и открыть фазу подтверждения (только организатор). */
    public function finish(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Игру нельзя завершить в этом статусе'], 422);
        }
        if (!$game->rounds()->where('is_played', true)->exists()) {
            return response()->json(['success' => false, 'message' => 'Введите счёт хотя бы одного раунда'], 422);
        }

        $game->update(['score_locked' => true]);
        // Организатор автоматически подтверждает счёт.
        $game->players()
            ->where('user_id', $user->id)
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->update(['score_confirmed' => true]);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }
```

В `formatGame()`, в маппинге `players` добавить ключ `score_confirmed` (рядом с `status`):
```php
                'score_confirmed' => (bool) $p->score_confirmed,
```
и на верхнем уровне возвращаемого массива (рядом с `my_status`) добавить:
```php
            'my_score_confirmed' => (bool) ($mine?->score_confirmed),
```

В `routes/api.php`, в блоке игр (рядом с `start/cancel`), добавить:
```php
        Route::post('/games/{game}/finish', [MobileGameController::class, 'finish']);
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameFinishTest.php`
Expected: PASS (4 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameFinishTest.php
git commit -m "feat(games): завершение — заморозка счёта + фаза подтверждения (S8)"
```

---

### Task 2: confirmScore() — подтверждение участниками, переход в finished (без ELO)

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (метод `confirmScore`; приватный `allScoreConfirmed`)
- Modify: `routes/api.php` (роут POST `/games/{game}/confirm-score`)
- Test: `tests/Feature/Games/GameConfirmScoreTest.php`

**Interfaces:**
- Consumes: `finish` (Task 1) — фаза подтверждения = `in_progress && score_locked`.
- Produces:
  - `public function confirmScore(Request $request, Game $game)` — принятый участник; фаза подтверждения (`status===IN_PROGRESS && score_locked`). Ставит своё `score_confirmed=true`. Если подтвердили все принятые → `status=FINISHED`. (ELO в этой задаче НЕ начисляется — только статус; хук для ELO добавит Task 3.)
  - `private function allScoreConfirmed(Game $game): bool` — true, если у всех `accepted` игроков `score_confirmed=true`.
  - Роут: `Route::post('/games/{game}/confirm-score', [MobileGameController::class, 'confirmScore']);`

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameConfirmScoreTest.php`:
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

class GameConfirmScoreTest extends TestCase
{
    use RefreshDatabase;

    /** Игра в фазе подтверждения (score_locked, организатор уже подтвердил). [game, [u1..u4]]. */
    private function pendingGame(User $organizer, string $type = 'friendly'): array
    {
        $game = Game::factory()->create([
            'creator_id' => $organizer->id, 'status' => 'in_progress',
            'type' => $type, 'format' => 'sets', 'score_locked' => true,
        ]);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED, 'score_confirmed' => true]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED, 'score_confirmed' => false]);
        }
        GameRound::create([
            'game_id' => $game->id, 'round_no' => 1,
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
            'score_a' => 6, 'score_b' => 3, 'is_played' => true,
        ]);
        return [$game, $ids];
    }

    public function test_partial_confirmation_keeps_in_progress(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingGame($organizer);
        Sanctum::actingAs(User::find($ids[1]));

        $this->postJson("/api/mobile/games/{$game->id}/confirm-score")->assertOk();
        $this->assertSame('in_progress', $game->fresh()->status);
        $p = GamePlayer::where('game_id', $game->id)->where('user_id', $ids[1])->first();
        $this->assertTrue((bool) $p->score_confirmed);
    }

    public function test_all_confirmed_finishes_game(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingGame($organizer);

        // Подтверждают u2, u3, u4 (организатор уже подтверждён).
        foreach ([$ids[1], $ids[2], $ids[3]] as $uid) {
            Sanctum::actingAs(User::find($uid));
            $this->postJson("/api/mobile/games/{$game->id}/confirm-score")->assertOk();
        }

        $this->assertSame('finished', $game->fresh()->status);
    }

    public function test_non_participant_cannot_confirm(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->pendingGame($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/confirm-score")->assertStatus(403);
    }

    public function test_cannot_confirm_when_not_pending(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingGame($organizer);
        $game->update(['score_locked' => false]); // не фаза подтверждения
        Sanctum::actingAs(User::find($ids[1]));

        $this->postJson("/api/mobile/games/{$game->id}/confirm-score")->assertStatus(422);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameConfirmScoreTest.php`
Expected: FAIL — метод/роут не существуют.

- [ ] **Step 3: Реализовать**

Добавить в `MobileGameController` (например, после `finish`):
```php
    /** Подтвердить счёт участником; при полном подтверждении — завершить игру. */
    public function confirmScore(Request $request, Game $game)
    {
        $user = $request->user();
        $player = $game->players()
            ->where('user_id', $user->id)
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->first();
        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Только участник игры'], 403);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || !$game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Счёт сейчас не подтверждается'], 422);
        }

        $player->update(['score_confirmed' => true]);

        if ($this->allScoreConfirmed($game)) {
            $game->update(['status' => Game::STATUS_FINISHED]);
            // ELO начисляется в Task 3 (хук здесь).
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }

    /** Все ли принятые игроки подтвердили счёт. */
    private function allScoreConfirmed(Game $game): bool
    {
        return $game->players()
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->where('score_confirmed', false)
            ->count() === 0;
    }
```

В `routes/api.php`, рядом с `finish`:
```php
        Route::post('/games/{game}/confirm-score', [MobileGameController::class, 'confirmScore']);
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameConfirmScoreTest.php`
Expected: PASS (4 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameConfirmScoreTest.php
git commit -m "feat(games): подтверждение счёта участниками → finished (S8)"
```

---

### Task 3: Начисление ELO при завершении rated-игры

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (`use RatingCalculator`; приватные `applyGameElo`, `applyPlayerRating`; вызов в `confirmScore` при переходе в finished)
- Test: `tests/Feature/Games/GameEloTest.php`

**Interfaces:**
- Consumes: `confirmScore` (Task 2) — точка перехода в `FINISHED`.
- Produces:
  - `private function applyGameElo(Game $game): void` — если `type===RATED`: по каждому сыгранному раунду (`is_played`, `score_a`/`score_b` не null) накапливает командный ELO (`calculateRatingChange`/`applyRatingChange` из трейта) по игрокам (сид — `users.rating` принятых игроков), затем коммитит один раз через `applyPlayerRating`. Friendly → no-op.
  - `private function applyPlayerRating(GamePlayer $player, int $before, int $after): void` — пишет `game_players.rating_*`, `users.rating`, `updateLevel`, `RatingHistory(reason='game')`.
  - `confirmScore` при переходе в `FINISHED` вызывает `applyGameElo($game)` до формирования ответа.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameEloTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\RatingHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameEloTest extends TestCase
{
    use RefreshDatabase;

    /** Фаза подтверждения (score_locked), организатор подтверждён; все игроки rating=1500. */
    private function pendingRated(User $organizer, int $scoreA, int $scoreB): array
    {
        $organizer->update(['rating' => 1500]);
        $game = Game::factory()->create([
            'creator_id' => $organizer->id, 'status' => 'in_progress',
            'type' => 'rated', 'format' => 'sets', 'score_locked' => true,
        ]);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED, 'score_confirmed' => true]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create(['rating' => 1500]);
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED, 'score_confirmed' => false]);
        }
        GameRound::create([
            'game_id' => $game->id, 'round_no' => 1,
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
            'score_a' => $scoreA, 'score_b' => $scoreB, 'is_played' => true,
        ]);
        return [$game, $ids];
    }

    private function confirmAll(Game $game, array $ids): void
    {
        foreach ([$ids[1], $ids[2], $ids[3]] as $uid) {
            Sanctum::actingAs(User::find($uid));
            $this->postJson("/api/mobile/games/{$game->id}/confirm-score")->assertOk();
        }
    }

    public function test_rated_game_applies_elo_winners_up_losers_down(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingRated($organizer, 6, 2); // team A (u1,u2) выигрывает
        $this->confirmAll($game, $ids);

        $this->assertSame('finished', $game->fresh()->status);

        $u1 = User::find($ids[0]);
        $u3 = User::find($ids[2]);
        $this->assertGreaterThan(1500, $u1->rating); // победитель вырос
        $this->assertLessThan(1500, $u3->rating);    // проигравший упал

        // Записи game_players и rating_history для всех 4.
        $this->assertSame(4, RatingHistory::where('reason', 'game')->count());
        $gpWinner = GamePlayer::where('game_id', $game->id)->where('user_id', $ids[0])->first();
        $this->assertSame(1500, $gpWinner->rating_before);
        $this->assertGreaterThan(0, $gpWinner->rating_change);
        $this->assertSame($gpWinner->rating_after, $u1->rating);
    }

    public function test_friendly_game_does_not_touch_rating(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingRated($organizer, 6, 2);
        $game->update(['type' => 'friendly']);
        $this->confirmAll($game, $ids);

        $this->assertSame('finished', $game->fresh()->status);
        $this->assertSame(1500, User::find($ids[0])->rating);
        $this->assertSame(0, RatingHistory::where('reason', 'game')->count());
        $gp = GamePlayer::where('game_id', $game->id)->where('user_id', $ids[0])->first();
        $this->assertNull($gp->rating_change);
    }

    public function test_zero_zero_round_no_rating_change(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingRated($organizer, 0, 0); // 0:0 → трейт не меняет рейтинг
        $this->confirmAll($game, $ids);

        $this->assertSame('finished', $game->fresh()->status);
        $this->assertSame(1500, User::find($ids[0])->rating);
        $this->assertSame(1500, User::find($ids[2])->rating);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameEloTest.php`
Expected: FAIL — ELO не начисляется (рейтинги остаются 1500, нет rating_history).

- [ ] **Step 3: Реализовать**

В шапке `MobileGameController` добавить импорты и подключить трейт:
```php
use App\Models\RatingHistory;
use App\Traits\RatingCalculator;
```
В объявление класса добавить `use RatingCalculator;` (первой строкой в теле класса, рядом с прочими `use`-трейтами, если есть).

Добавить приватные методы (например, рядом с `allScoreConfirmed`):
```php
    /** Начислить ELO завершённой rated-игре по сыгранным раундам (миррор challenge/americano). */
    private function applyGameElo(Game $game): void
    {
        if ($game->type !== Game::TYPE_RATED) {
            return;
        }

        $accepted = $game->players()
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->with('user')
            ->get();

        // Сид: живой рейтинг принятых игроков.
        $before = [];   // user_id => rating_before (снимок)
        $current = [];  // user_id => накопительный рейтинг
        $players = [];  // user_id => GamePlayer
        foreach ($accepted as $gp) {
            if (!$gp->user) continue;
            $before[$gp->user_id] = (int) $gp->user->rating;
            $current[$gp->user_id] = (int) $gp->user->rating;
            $players[$gp->user_id] = $gp;
        }

        // Накопление по каждому сыгранному раунду (2×2 командный средний).
        $rounds = $game->relationLoaded('rounds') ? $game->rounds : $game->rounds()->get();
        foreach ($rounds as $round) {
            if (!$round->is_played || $round->score_a === null || $round->score_b === null) {
                continue;
            }
            $pairA = array_values(array_filter((array) $round->pair_a, fn ($id) => isset($current[$id])));
            $pairB = array_values(array_filter((array) $round->pair_b, fn ($id) => isset($current[$id])));
            if (count($pairA) < 1 || count($pairB) < 1) {
                continue;
            }
            $avgA = array_sum(array_map(fn ($id) => $current[$id], $pairA)) / count($pairA);
            $avgB = array_sum(array_map(fn ($id) => $current[$id], $pairB)) / count($pairB);
            $changes = $this->calculateRatingChange($avgA, $avgB, (int) $round->score_a, (int) $round->score_b);
            foreach ($pairA as $id) {
                $current[$id] = $this->applyRatingChange($current[$id], $changes['change1']);
            }
            foreach ($pairB as $id) {
                $current[$id] = $this->applyRatingChange($current[$id], $changes['change2']);
            }
        }

        // Коммит один раз на игрока.
        foreach ($players as $uid => $gp) {
            $this->applyPlayerRating($gp, $before[$uid], $current[$uid]);
        }
    }

    /** Записать итог рейтинга игрока (миррор MobileChallengeController::applyPlayerRating). */
    private function applyPlayerRating(GamePlayer $player, int $before, int $after): void
    {
        $user = $player->user;
        if (!$user) {
            return;
        }

        $player->update([
            'rating_before' => $before,
            'rating_after' => $after,
            'rating_change' => $after - $before,
        ]);

        $user->update(['rating' => $after]);
        $this->updateLevel($user);

        RatingHistory::create([
            'user_id' => $user->id,
            'tournament_id' => null,
            'rating_before' => $before,
            'rating_after' => $after,
            'change' => $after - $before,
            'reason' => 'game',
        ]);
    }
```

В `confirmScore`, заменить строку-хук на реальный вызов — блок перехода в finished должен стать:
```php
        if ($this->allScoreConfirmed($game)) {
            $game->update(['status' => Game::STATUS_FINISHED]);
            $this->applyGameElo($game);
        }
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameEloTest.php` → PASS (3).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php tests/Feature/Games/GameEloTest.php
git commit -m "feat(games): начисление ELO при завершении rated-игры (S8)"
```

---

## Порядок выполнения
Task 1 → 2 (использует score_locked фазу из Task 1) → 3 (вешает ELO на переход в finished из Task 2).

## Не входит (следующие слайсы)
S7 отмена/undo + action_log; спор (dispute) — Фаза 2; S9 передача прав; S10 лента-пагинация/фильтры; S11 инбокс приглашений; S12 пуши/напоминания; S13 Flutter-экраны; S14 удаление challenge.

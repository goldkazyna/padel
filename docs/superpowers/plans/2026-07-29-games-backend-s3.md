# Games Module — Backend S3 (Фильтр по рейтингу + out_of_range) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Диапазон рейтинга работает как фильтр «самотёка»: в ленте по умолчанию скрыты игры вне уровня пользователя (тумблер «показывать вне уровня»); при заявке/приглашении на GamePlayer выставляется флаг `out_of_range`.

**Architecture:** Расширяем существующий `Api\MobileGameController` (S0/S1/S2 в main). Уровень пользователя — `users.level` (шкала 1–5.75), как в challenge min_level/max_level. `rating_min`/`rating_max` у игры nullable (null = без ограничения).

**Tech Stack:** Laravel 12, Sanctum, PHPUnit sqlite :memory:.

## Global Constraints
- НЕ трогать `RatingCalculator`, `AmericanoRanking`, старый `challenge`.
- НЕ менять сигнатуры существующих методов; правки `index/invite/apply` — аддитивные (новое поведение фильтра/флага), существующие ключи ответа не убирать.
- Диапазон = ориентир/фильтр, НЕ жёсткое ограничение: заявка вне диапазона всё равно проходит (организатор решает), но помечается `out_of_range=true`. Персональное приглашение перекрывает фильтр (приглашённый попадает, `out_of_range` показывает несоответствие).
- Ответ `{success, data}`.
- Ветка от main (`feature/games-backend-s3`), НЕ работать на main. Не пушить.

---

### Task 1: Хелпер userInRange + фильтр ленты по уровню (тумблер)

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (приватный `userInRange`; правка `index`)
- Test: `tests/Feature/Games/GameFeedRatingFilterTest.php`

**Interfaces:**
- Produces:
  - `private function userInRange(Game $game, User $user): bool` — `(rating_min === null || level >= rating_min) && (rating_max === null || level <= rating_max)`, уровень = `(float) $user->level`.
  - `index(Request)` — новый query-параметр `show_out_of_level` (bool, по умолчанию false). Когда false — из ленты исключаются игры, где уровень пользователя вне [rating_min, rating_max] (игры с null-границами показываются всегда). Когда true — фильтр по уровню не применяется. Остальные условия (public, [open,full], future, сортировка) без изменений.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameFeedRatingFilterTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameFeedRatingFilterTest extends TestCase
{
    use RefreshDatabase;

    private function publicGame(array $override = []): Game
    {
        return Game::factory()->create(array_merge([
            'visibility' => 'public', 'status' => 'open', 'starts_at' => now()->addDay(),
        ], $override));
    }

    public function test_feed_hides_games_out_of_user_level_by_default(): void
    {
        $inRange = $this->publicGame(['rating_min' => 2.0, 'rating_max' => 4.0]);
        $tooHigh = $this->publicGame(['rating_min' => 4.5, 'rating_max' => 5.5]);
        $noLimit = $this->publicGame(['rating_min' => null, 'rating_max' => null]);

        Sanctum::actingAs(User::factory()->create(['level' => 3.0]));

        $ids = collect($this->getJson('/api/mobile/games')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($inRange->id, $ids);
        $this->assertContains($noLimit->id, $ids);
        $this->assertNotContains($tooHigh->id, $ids);
    }

    public function test_toggle_shows_out_of_level_games(): void
    {
        $tooHigh = $this->publicGame(['rating_min' => 4.5, 'rating_max' => 5.5]);
        Sanctum::actingAs(User::factory()->create(['level' => 3.0]));

        $ids = collect($this->getJson('/api/mobile/games?show_out_of_level=1')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($tooHigh->id, $ids);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameFeedRatingFilterTest.php`
Expected: FAIL — `tooHigh` попадает в ленту (фильтра ещё нет).

- [ ] **Step 3: Реализовать**

Добавить приватный хелпер:
```php
    /** Уровень пользователя в диапазоне игры (null-границы = без ограничения). */
    private function userInRange(Game $game, User $user): bool
    {
        $level = (float) $user->level;
        if ($game->rating_min !== null && $level < (float) $game->rating_min) {
            return false;
        }
        if ($game->rating_max !== null && $level > (float) $game->rating_max) {
            return false;
        }
        return true;
    }
```

В `index()` после построения базового запроса (public, whereIn status, starts_at, orderBy) и ДО `->get()` добавить фильтр по уровню. Заменить существующее тело `index` так, чтобы запрос собирался в переменную и применялся тумблер:
```php
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Game::with(['creator', 'club', 'court', 'players.user'])
            ->where('visibility', Game::VISIBILITY_PUBLIC)
            ->whereIn('status', [Game::STATUS_OPEN, Game::STATUS_FULL])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at');

        // Диапазон рейтинга — фильтр «самотёка»: по умолчанию прячем игры вне уровня.
        if (!$request->boolean('show_out_of_level')) {
            $level = (float) $user->level;
            $query->where(function ($q) use ($level) {
                $q->whereNull('rating_min')->orWhere('rating_min', '<=', $level);
            })->where(function ($q) use ($level) {
                $q->whereNull('rating_max')->orWhere('rating_max', '>=', $level);
            });
        }

        $games = $query->get()->map(fn ($g) => $this->formatGame($g, $user));

        return response()->json(['success' => true, 'data' => $games]);
    }
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameFeedRatingFilterTest.php`
Expected: PASS (2 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php tests/Feature/Games/GameFeedRatingFilterTest.php
git commit -m "feat(games): фильтр ленты по уровню + тумблер show_out_of_level (S3)"
```

---

### Task 2: Флаг out_of_range при приглашении и заявке

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (в `invite` и `apply` выставлять `out_of_range`)
- Test: `tests/Feature/Games/GameOutOfRangeTest.php`

**Interfaces:**
- Consumes: `userInRange` (Task 1).
- Produces: при создании/реактивации `GamePlayer` в `invite` и `apply` поле `out_of_range` = `!userInRange(game, target)`. (У `invite` target = приглашённый; у `apply` target = текущий пользователь.) Значение выставляется и в ветке `create`, и в ветке `update` (реактивация терминальной строки).

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameOutOfRangeTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class GameOutOfRangeTest extends TestCase
{
    use RefreshDatabase;

    private function fakePush(): void
    {
        $m = Mockery::mock(\App\Services\FCMNotificationService::class);
        $m->shouldReceive('sendToUser')->andReturnNull();
        $this->instance(\App\Services\FCMNotificationService::class, $m);
    }

    public function test_apply_out_of_range_sets_flag(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open', 'rating_min' => 4.0, 'rating_max' => 5.0]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);

        $applicant = User::factory()->create(['level' => 2.5]); // вне диапазона
        Sanctum::actingAs($applicant);

        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();
        $this->assertTrue((bool) GamePlayer::where('game_id', $game->id)->where('user_id', $applicant->id)->first()->out_of_range);
    }

    public function test_apply_in_range_flag_false(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open', 'rating_min' => 2.0, 'rating_max' => 4.0]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);

        $applicant = User::factory()->create(['level' => 3.0]);
        Sanctum::actingAs($applicant);

        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();
        $this->assertFalse((bool) GamePlayer::where('game_id', $game->id)->where('user_id', $applicant->id)->first()->out_of_range);
    }

    public function test_invite_out_of_range_sets_flag(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'rating_min' => 4.0, 'rating_max' => 5.0]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        $invitee = User::factory()->create(['level' => 2.0]); // вне диапазона
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/invite", ['user_id' => $invitee->id])->assertOk();
        $this->assertTrue((bool) GamePlayer::where('game_id', $game->id)->where('user_id', $invitee->id)->first()->out_of_range);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameOutOfRangeTest.php`
Expected: FAIL — `out_of_range` не выставляется (по умолчанию false).

- [ ] **Step 3: Реализовать**

В `invite()`: вычислить флаг для приглашённого и добавить его в оба массива (create и update реактивации). Найти строки, где определяется `$invitee`/позиция, и перед созданием/обновлением `GamePlayer` вычислить:
```php
        $invitee = User::find($data['user_id']);
        $outOfRange = !$this->userInRange($game, $invitee);
```
Затем в массив `GamePlayer::create([...])` и в `$existing->update([...])` добавить ключ `'out_of_range' => $outOfRange,`.
(Если в текущем коде `$invitee` определяется позже — перенести вычисление `$outOfRange` выше, до записи GamePlayer.)

В `apply()`: перед созданием/обновлением `GamePlayer` вычислить `$outOfRange = !$this->userInRange($game, $user);` и добавить `'out_of_range' => $outOfRange,` в массив `create` и в `update` (реактивация).

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameOutOfRangeTest.php` → PASS (3).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php tests/Feature/Games/GameOutOfRangeTest.php
git commit -m "feat(games): out_of_range при invite/apply (S3)"
```

---

## Порядок выполнения
Task 1 → 2 (Task 2 использует `userInRange` из Task 1).

## Не входит (следующие слайсы)
S4-6 движки счёта; S7 отмена; S8 утверждение/спор; S9 передача прав; S10 лента-пагинация; S11 инбокс; S12 пуши/напоминания; S13 Flutter; S14 удаление challenge. Предупреждение при открытии карточки вне диапазона — фронт (S13).

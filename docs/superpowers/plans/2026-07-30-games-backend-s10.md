# Games Module — Backend S10 (Лента игр: фильтры + пагинация + «Мои игры») Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Расширить ленту игр (`GET /games`) фильтрами (клуб/формат/тип/дата) и пагинацией, и добавить отдельный список «Мои игры» (`GET /games/my`).

**Architecture:** Расширяем `Api\MobileGameController::index` (S0–S8 в main) query-параметрами и пагинацией; добавляем `myGames`. Существующий фильтр рейтинга (`show_out_of_level` из S3) сохраняем.

**Tech Stack:** Laravel 12, Sanctum, PHPUnit sqlite :memory:.

## Design-решения (записаны намеренно)
- **Фильтры ленты (`index`)** — все опциональны, применяются поверх текущей базовой выборки (public, open/full, будущие): `club_id` (int), `format` (sets|points|americano), `type` (rated|friendly), `date_from`/`date_to` (ISO, по `starts_at`). Фильтр рейтинга `show_out_of_level` не трогаем.
- **Пагинация** — `page` (default 1), `per_page` (default 20, max 50). Ответ — конверт `{success, data:[...], meta:{current_page, per_page, total, last_page}}`.
- **«Мои игры» (`myGames`)** — игры, где текущий пользователь организатор ИЛИ участник (любой статус кроме declined/left/removed), любых статусов (включая in_progress/finished), сортировка по `starts_at` DESC, та же пагинация. Опциональный `status` фильтр.
- Сериализация — существующий `formatGame`.

## Global Constraints
- НЕ трогать RatingCalculator/AmericanoRanking/AmericanoService/challenge. Никаких записей рейтинга.
- Изменение формата ответа `index` (плоский массив → конверт с `meta`) — единственное осознанное breaking-изменение; фронт под него подстроится (в приложении лента ещё не финализирована).
- Ошибки валидации фильтров → `422`.
- Ветка от main (`feature/games-backend-s10`), не работать на main, не пушить. Новых миграций нет.

---

### Task 1: Фильтры + пагинация в index

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (`index`)
- Test: `tests/Feature/Games/GameFeedFiltersTest.php`

**Interfaces:**
- Produces:
  - `index(Request $request)` — принимает опциональные `club_id`, `format`, `type`, `date_from`, `date_to`, `page`, `per_page` (+ существующий `show_out_of_level`). Возвращает `{success, data:[formatGame...], meta:{current_page, per_page, total, last_page}}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameFeedFiltersTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameFeedFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function openGame(array $override = []): Game
    {
        return Game::factory()->create(array_merge([
            'status' => 'open', 'visibility' => 'public',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
            'rating_min' => null, 'rating_max' => null,
        ], $override));
    }

    public function test_filter_by_format(): void
    {
        $user = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($user);
        $this->openGame(['format' => 'sets']);
        $this->openGame(['format' => 'americano', 'format_meta' => ['sub' => 'by_points', 'target' => 24]]);

        $res = $this->getJson('/api/mobile/games?format=americano')->assertOk();
        $res->assertJsonPath('meta.total', 1);
        $this->assertSame('americano', $res->json('data.0.format'));
    }

    public function test_filter_by_club(): void
    {
        $user = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($user);
        $club = Club::factory()->create();
        $this->openGame(['club_id' => $club->id]);
        $this->openGame(); // другой клуб

        $res = $this->getJson("/api/mobile/games?club_id={$club->id}")->assertOk();
        $res->assertJsonPath('meta.total', 1);
        $this->assertSame($club->id, $res->json('data.0.club.id'));
    }

    public function test_pagination_meta(): void
    {
        $user = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($user);
        for ($i = 0; $i < 3; $i++) {
            $this->openGame(['starts_at' => now()->addDays($i + 1), 'ends_at' => now()->addDays($i + 1)->addHour()]);
        }

        $res = $this->getJson('/api/mobile/games?per_page=2&page=1')->assertOk();
        $res->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);
        $this->assertCount(2, $res->json('data'));
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameFeedFiltersTest.php`
Expected: FAIL — фильтров/`meta` нет (текущий index отдаёт плоский массив).

- [ ] **Step 3: Реализовать**

Заменить тело `index` (сохранив базовую выборку и rating-фильтр S3):
```php
    /** Лента игр — публичные, набирающие состав, будущие; фильтры + пагинация. */
    public function index(Request $request)
    {
        $user = $request->user();

        $filters = $request->validate([
            'club_id' => 'nullable|integer',
            'format' => 'nullable|in:sets,points,americano',
            'type' => 'nullable|in:rated,friendly',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:50',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = Game::with(['creator', 'club', 'court', 'players.user'])
            ->where('visibility', Game::VISIBILITY_PUBLIC)
            ->whereIn('status', [Game::STATUS_OPEN, Game::STATUS_FULL])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at');

        if (!empty($filters['club_id'])) {
            $query->where('club_id', $filters['club_id']);
        }
        if (!empty($filters['format'])) {
            $query->where('format', $filters['format']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('starts_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('starts_at', '<=', $filters['date_to']);
        }

        // Диапазон рейтинга — фильтр «самотёка» (S3).
        if (!$request->boolean('show_out_of_level')) {
            $level = (float) $user->level;
            $query->where(function ($q) use ($level) {
                $q->whereNull('rating_min')->orWhere('rating_min', '<=', $level);
            })->where(function ($q) use ($level) {
                $q->whereNull('rating_max')->orWhere('rating_max', '>=', $level);
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $query->paginate($perPage);
        $data = collect($paginator->items())->map(fn ($g) => $this->formatGame($g, $user));

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameFeedFiltersTest.php` → PASS (3).
Run: `php artisan test tests/Feature/Games` → всё зелёное (если существующий тест ленты ожидал плоский `data` без пагинации — обнови его на конверт с `meta`; НЕ меняй прочую логику).

> ПРИМЕЧАНИЕ реализатору: возможно, существующий тест (например GameShowIndexTest) ассертит старую форму `index`. Если он падает только из-за нового `meta`-конверта — обнови его ассерты под новую форму (данные те же). Если падает по другой причине — стоп, разберись.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php tests/Feature/Games/GameFeedFiltersTest.php
# если правил существующий тест ленты — добавь и его
git commit -m "feat(games): фильтры и пагинация ленты игр (S10)"
```

---

### Task 2: GET /games/my — мои игры

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (`myGames`)
- Modify: `routes/api.php` (роут GET `/games/my`)
- Test: `tests/Feature/Games/GameMyGamesTest.php`

**Interfaces:**
- Produces:
  - `public function myGames(Request $request)` — игры, где пользователь организатор ИЛИ участник со статусом НЕ в (declined,left,removed); опциональный `status`; пагинация `page`/`per_page` (default 20, max 50); сортировка `starts_at` DESC. Ответ — тот же конверт `{success, data, meta}`.
  - Роут: `Route::get('/games/my', [MobileGameController::class, 'myGames']);` — ВАЖНО: объявить ДО `Route::get('/games/{game}', ...)`, иначе `my` уйдёт в `{game}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameMyGamesTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameMyGamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_games_where_organizer_or_participant(): void
    {
        $me = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($me);

        // Организатор.
        $mine = Game::factory()->create(['creator_id' => $me->id, 'status' => 'finished', 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour()]);
        // Участник.
        $joined = Game::factory()->create(['status' => 'in_progress', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        GamePlayer::factory()->create(['game_id' => $joined->id, 'user_id' => $me->id, 'position' => 2, 'status' => GamePlayer::STATUS_ACCEPTED]);
        // Чужая игра — не участвую.
        Game::factory()->create(['status' => 'open', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        // Игра, где я вышел (left) — не показывать.
        $leftGame = Game::factory()->create(['status' => 'open', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        GamePlayer::factory()->create(['game_id' => $leftGame->id, 'user_id' => $me->id, 'position' => 3, 'status' => GamePlayer::STATUS_LEFT]);

        $res = $this->getJson('/api/mobile/games/my')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertContains($joined->id, $ids);
        $this->assertSame(2, $res->json('meta.total'));
    }

    public function test_status_filter(): void
    {
        $me = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($me);
        Game::factory()->create(['creator_id' => $me->id, 'status' => 'finished', 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour()]);
        Game::factory()->create(['creator_id' => $me->id, 'status' => 'open', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);

        $res = $this->getJson('/api/mobile/games/my?status=finished')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('finished', $res->json('data.0.status'));
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameMyGamesTest.php`
Expected: FAIL — метод/роут не существуют.

- [ ] **Step 3: Реализовать**

Добавить метод в `MobileGameController` (например, после `index`):
```php
    /** Мои игры: где я организатор или активный участник. */
    public function myGames(Request $request)
    {
        $user = $request->user();
        $filters = $request->validate([
            'status' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:50',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = Game::with(['creator', 'club', 'court', 'players.user'])
            ->where(function ($q) use ($user) {
                $q->where('creator_id', $user->id)
                    ->orWhereHas('players', function ($p) use ($user) {
                        $p->where('user_id', $user->id)
                            ->whereNotIn('status', [
                                GamePlayer::STATUS_DECLINED,
                                GamePlayer::STATUS_LEFT,
                                GamePlayer::STATUS_REMOVED,
                            ]);
                    });
            })
            ->orderByDesc('starts_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $query->paginate($perPage);
        $data = collect($paginator->items())->map(fn ($g) => $this->formatGame($g, $user));

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
```

В `routes/api.php`, в блоке игр, ДО строки `Route::get('/games/{game}', ...)` добавить:
```php
        Route::get('/games/my', [MobileGameController::class, 'myGames']);
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameMyGamesTest.php` → PASS (2).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameMyGamesTest.php
git commit -m "feat(games): список «Мои игры» (S10)"
```

---

## Порядок выполнения
Task 1 → 2 (независимы, но 2 переиспользует конверт-форму из 1).

## Не входит (следующие слайсы)
S11 инбокс приглашений; S12 пуши/напоминания; спор (dispute) — Фаза 2; S13 Flutter; S14 удаление challenge.

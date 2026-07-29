# Games Module — Backend S6 (Американо: авто-расписание на старте + regenerate + личное ранжирование) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Для игр формата `americano` автоматически генерировать классическое расписание Американо (4 игрока / 3 раунда, каждый партнёрит каждого 1 раз) при старте, дать эндпоинт перегенерации (пока счёт не введён), и считать личное ранжирование (очки → победы → разница → user_id) для отдачи в деталях игры.

**Architecture:** Расширяем `Api\MobileGameController` (S0–S5 в main). Добавляем приватный хелпер `generateAmericanoRounds(Game): void` (вызов в `start()`), публичный `regenerateSchedule()` + роут, и новый read-only класс `App\Support\GameAmericanoRanking` (миррор логики `App\Support\AmericanoRanking`, но читает `GameRound`; турнирный НЕ трогаем). Ранжирование отдаём в `formatGame()` ключом `americano_ranking`.

**Tech Stack:** Laravel 12, Sanctum, PHPUnit sqlite :memory:.

## Global Constraints
- НЕ трогать `RatingCalculator`, `AmericanoRanking`, `AmericanoTie`, `AmericanoService`, старый `challenge`. Никаких записей рейтинга (ELO — слайс S8).
- Игры всегда 4 игрока / 1 корт (`capacity` захардкожен 4 в `store()`). Расписание Американо определено только для 4 игроков.
- Авто-генерация раундов срабатывает ТОЛЬКО для `format === Game::FORMAT_AMERICANO` и ТОЛЬКО если у игры ещё нет раундов (идемпотентность при повторном старте после `start/cancel`).
- Существующие ключи ответа и поведение не менять сверх добавленного. Новый ключ `americano_ranking` — аддитивный.
- Ошибки → `422 {success:false, message}`. Гварды как у `addRound` (организатор, `in_progress`, `!score_locked`).
- Ветка от main (`feature/games-backend-s6`), не работать на main, не пушить.

**Классическое расписание Американо 4/1 (слоты 0..3, 3 раунда):**
- R1: (0,1) vs (2,3)
- R2: (0,2) vs (1,3)
- R3: (0,3) vs (1,2)

Каждая из 6 пар игроков встречается партнёрами ровно 1 раз. Слоты → user_id по перемешанному списку принятых игроков (перемешивание даёт разные партнёрства при regenerate).

---

### Task 1: Авто-генерация расписания Американо при старте

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (приватная константа-расписание + приватный `generateAmericanoRounds`; вызов в `start()`)
- Test: `tests/Feature/Games/GameAmericanoScheduleTest.php`

**Interfaces:**
- Produces:
  - `private function generateAmericanoRounds(Game $game): void` — если `format === FORMAT_AMERICANO`, ровно 4 принятых игрока и у игры нет раундов, создаёт 3 `GameRound` (round_no 1..3, `pair_a`/`pair_b` из user_id по расписанию, `is_played=false`). Иначе no-op.
  - `start()` после перехода в `in_progress` вызывает `generateAmericanoRounds($game)` до формирования ответа.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameAmericanoScheduleTest.php`:
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

class GameAmericanoScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** full-игра заданного формата с 4 accepted; возвращает [game, [u1..u4]]. */
    private function fullGame(User $organizer, string $format): array
    {
        $game = Game::factory()->create([
            'creator_id' => $organizer->id,
            'status' => 'full',
            'format' => $format,
            'format_meta' => $format === 'americano' ? ['sub' => 'by_points', 'target' => 24] : null,
        ]);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        return [$game, $ids];
    }

    public function test_start_americano_generates_three_rounds(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->fullGame($organizer, 'americano');
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();

        $rounds = GameRound::where('game_id', $game->id)->orderBy('round_no')->get();
        $this->assertCount(3, $rounds);
        $this->assertSame([1, 2, 3], $rounds->pluck('round_no')->all());

        // Каждый раунд: 4 разных принятых игрока, счёт пуст (is_played=false).
        foreach ($rounds as $r) {
            $this->assertFalse((bool) $r->is_played);
            $this->assertNull($r->score_a);
            $four = array_merge($r->pair_a, $r->pair_b);
            $this->assertCount(4, array_unique($four));
            foreach ($four as $uid) {
                $this->assertContains($uid, $ids);
            }
        }

        // Каждая из 6 пар партнёров встречается ровно 1 раз (свойство Американо).
        $partnerKeys = [];
        foreach ($rounds as $r) {
            foreach ([$r->pair_a, $r->pair_b] as $pair) {
                sort($pair);
                $partnerKeys[] = implode('-', $pair);
            }
        }
        $this->assertCount(6, $partnerKeys);
        $this->assertCount(6, array_unique($partnerKeys));
    }

    public function test_start_non_americano_generates_no_rounds(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->fullGame($organizer, 'sets');
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();

        $this->assertSame(0, GameRound::where('game_id', $game->id)->count());
    }

    public function test_restart_does_not_duplicate_rounds(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->fullGame($organizer, 'americano');
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/start/cancel")->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();

        // Повторный старт не плодит раунды (у игры уже есть расписание).
        $this->assertSame(3, GameRound::where('game_id', $game->id)->count());
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameAmericanoScheduleTest.php`
Expected: FAIL — раунды не создаются (генерации ещё нет).

- [ ] **Step 3: Реализовать**

В `MobileGameController` добавить приватную константу и хелпер (например, рядом с `validateRoundPairs`):
```php
    /** Классическое расписание Американо 4/1: слоты 0..3, 3 раунда, каждый партнёрит каждого 1 раз. */
    private const AMERICANO_4_SCHEDULE = [
        [[0, 1], [2, 3]],
        [[0, 2], [1, 3]],
        [[0, 3], [1, 2]],
    ];

    /** Генерирует раунды Американо при старте (4 игрока, если раундов ещё нет). No-op иначе. */
    private function generateAmericanoRounds(Game $game): void
    {
        if ($game->format !== Game::FORMAT_AMERICANO) {
            return;
        }
        if ($game->rounds()->exists()) {
            return; // расписание уже есть — не дублируем при повторном старте
        }

        $userIds = $game->players()
            ->where('status', GamePlayer::STATUS_ACCEPTED)
            ->orderBy('position')
            ->pluck('user_id')
            ->all();

        if (count($userIds) !== 4) {
            return; // расписание определено только для 4 игроков
        }

        shuffle($userIds); // слот→игрок случайно: варьирует партнёрства

        $roundNo = 1;
        foreach (self::AMERICANO_4_SCHEDULE as $slots) {
            [$a, $b] = $slots;
            GameRound::create([
                'game_id' => $game->id,
                'round_no' => $roundNo++,
                'pair_a' => [$userIds[$a[0]], $userIds[$a[1]]],
                'pair_b' => [$userIds[$b[0]], $userIds[$b[1]]],
                'is_played' => false,
            ]);
        }
    }
```

В `start()`, сразу после `$game->update(['status' => Game::STATUS_IN_PROGRESS]);` и до формирования ответа, добавить:
```php
        $this->generateAmericanoRounds($game);
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameAmericanoScheduleTest.php`
Expected: PASS (3 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php tests/Feature/Games/GameAmericanoScheduleTest.php
git commit -m "feat(games): авто-расписание Американо при старте (S6)"
```

---

### Task 2: Эндпоинт перегенерации расписания

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (публичный `regenerateSchedule`)
- Modify: `routes/api.php` (роут POST `/games/{game}/schedule/regenerate`)
- Test: `tests/Feature/Games/GameScheduleRegenerateTest.php`

**Interfaces:**
- Consumes: `generateAmericanoRounds` (Task 1).
- Produces:
  - `public function regenerateSchedule(Request $request, Game $game)` — организатор, `format === FORMAT_AMERICANO`, `status === IN_PROGRESS`, `!score_locked`, и ни один раунд не сыгран (`is_played` везде false). Удаляет все `GameRound` игры и заново вызывает `generateAmericanoRounds`. Ответ — `formatGame`.
  - Роут: `Route::post('/games/{game}/schedule/regenerate', [MobileGameController::class, 'regenerateSchedule']);`

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameScheduleRegenerateTest.php`:
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

class GameScheduleRegenerateTest extends TestCase
{
    use RefreshDatabase;

    /** in_progress американо с 4 accepted и сгенерированным расписанием. */
    private function startedAmericano(User $organizer): array
    {
        $game = Game::factory()->create([
            'creator_id' => $organizer->id,
            'status' => 'full',
            'format' => 'americano',
            'format_meta' => ['sub' => 'by_points', 'target' => 24],
        ]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        Sanctum::actingAs($organizer);
        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();
        return [$game];
    }

    public function test_regenerate_replaces_rounds(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->startedAmericano($organizer);

        $before = GameRound::where('game_id', $game->id)->orderBy('round_no')->pluck('id')->all();
        $this->assertCount(3, $before);

        $this->postJson("/api/mobile/games/{$game->id}/schedule/regenerate")->assertOk();

        $after = GameRound::where('game_id', $game->id)->orderBy('round_no')->pluck('id')->all();
        $this->assertCount(3, $after);
        // Старые строки удалены, созданы новые.
        $this->assertEmpty(array_intersect($before, $after));
        $this->assertSame([1, 2, 3], GameRound::where('game_id', $game->id)->orderBy('round_no')->pluck('round_no')->all());
    }

    public function test_regenerate_blocked_after_score_entered(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->startedAmericano($organizer);

        $first = GameRound::where('game_id', $game->id)->orderBy('round_no')->first();
        $first->update(['score_a' => 24, 'score_b' => 18, 'is_played' => true]);

        $this->postJson("/api/mobile/games/{$game->id}/schedule/regenerate")->assertStatus(422);
        $this->assertSame(3, GameRound::where('game_id', $game->id)->count());
    }

    public function test_regenerate_non_organizer_forbidden(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->startedAmericano($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/schedule/regenerate")->assertStatus(403);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameScheduleRegenerateTest.php`
Expected: FAIL — роут/метод не существуют (404/405 или method not found).

- [ ] **Step 3: Реализовать**

Добавить метод в `MobileGameController` (например, после `startCancel`):
```php
    /** Перегенерировать расписание Американо (пока счёт не введён; только организатор). */
    public function regenerateSchedule(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($game->format !== Game::FORMAT_AMERICANO) {
            return response()->json(['success' => false, 'message' => 'Расписание есть только у Американо'], 422);
        }
        if ($game->status !== Game::STATUS_IN_PROGRESS || $game->score_locked) {
            return response()->json(['success' => false, 'message' => 'Перегенерация недоступна'], 422);
        }
        if ($game->rounds()->where('is_played', true)->exists()) {
            return response()->json(['success' => false, 'message' => 'Нельзя перегенерировать: уже введён счёт'], 422);
        }

        $game->rounds()->delete();
        $this->generateAmericanoRounds($game);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user', 'rounds']), $user),
        ]);
    }
```

В `routes/api.php`, в блоке игр (рядом с `start/cancel`), добавить:
```php
        Route::post('/games/{game}/schedule/regenerate', [MobileGameController::class, 'regenerateSchedule']);
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameScheduleRegenerateTest.php`
Expected: PASS (3 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameScheduleRegenerateTest.php
git commit -m "feat(games): перегенерация расписания Американо (S6)"
```

---

### Task 3: Личное ранжирование Американо (GameAmericanoRanking) + отдача в formatGame

**Files:**
- Create: `app/Support/GameAmericanoRanking.php`
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (ключ `americano_ranking` в `formatGame`)
- Test: `tests/Feature/Games/GameAmericanoRankingTest.php`

**Interfaces:**
- Produces:
  - `App\Support\GameAmericanoRanking::orderedIds(Game $game): int[]` — user_id по местам (1-е место первым): очки → победы → разница → user_id ASC.
  - `::place(Game $game, int $userId): ?int` — место (1-based) или null.
  - `::table(Game $game): array` — `[['user_id'=>int,'points'=>int,'wins'=>int,'diff'=>int,'place'=>int], ...]` в порядке мест.
  - `formatGame()` возвращает `'americano_ranking' => ($game->format === FORMAT_AMERICANO ? GameAmericanoRanking::table($game) : null)`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameAmericanoRankingTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use App\Support\GameAmericanoRanking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameAmericanoRankingTest extends TestCase
{
    use RefreshDatabase;

    /** Американо-игра с 4 игроками u1..u4 и переданными раундами. */
    private function gameWithRounds(array $userIds, array $rounds): Game
    {
        $game = Game::factory()->create(['format' => 'americano', 'status' => 'in_progress']);
        foreach ($userIds as $i => $uid) {
            GamePlayer::factory()->create([
                'game_id' => $game->id, 'user_id' => $uid, 'position' => $i + 1,
                'status' => GamePlayer::STATUS_ACCEPTED,
            ]);
        }
        $no = 1;
        foreach ($rounds as $r) {
            GameRound::create([
                'game_id' => $game->id, 'round_no' => $no++,
                'pair_a' => $r['a'], 'pair_b' => $r['b'],
                'score_a' => $r['sa'], 'score_b' => $r['sb'], 'is_played' => true,
            ]);
        }
        return $game;
    }

    public function test_ranking_orders_by_points_then_wins_then_diff(): void
    {
        $u = User::factory()->count(4)->create()->pluck('id')->all(); // u[0..3]

        // Классические 3 раунда Американо. Считаем сумму личных очков.
        // R1: (u0,u1)=24 vs (u2,u3)=18  → u0,u1 +24; u2,u3 +18
        // R2: (u0,u2)=24 vs (u1,u3)=20  → u0,u2 +24; u1,u3 +20
        // R3: (u0,u3)=24 vs (u1,u2)=15  → u0,u3 +24; u1,u2 +15
        // Итог очки: u0=72, u1=59, u2=57, u3=62 → порядок u0,u3,u1,u2
        $game = $this->gameWithRounds($u, [
            ['a' => [$u[0], $u[1]], 'b' => [$u[2], $u[3]], 'sa' => 24, 'sb' => 18],
            ['a' => [$u[0], $u[2]], 'b' => [$u[1], $u[3]], 'sa' => 24, 'sb' => 20],
            ['a' => [$u[0], $u[3]], 'b' => [$u[1], $u[2]], 'sa' => 24, 'sb' => 15],
        ]);

        $this->assertSame([$u[0], $u[3], $u[1], $u[2]], GameAmericanoRanking::orderedIds($game));
        $this->assertSame(1, GameAmericanoRanking::place($game, $u[0]));
        $this->assertSame(2, GameAmericanoRanking::place($game, $u[3]));

        $table = GameAmericanoRanking::table($game);
        $this->assertSame($u[0], $table[0]['user_id']);
        $this->assertSame(72, $table[0]['points']);
        $this->assertSame(1, $table[0]['place']);
        $this->assertSame(3, $table[0]['wins']); // u0 выиграл все 3 раунда
    }

    public function test_place_null_for_non_participant(): void
    {
        $u = User::factory()->count(4)->create()->pluck('id')->all();
        $game = $this->gameWithRounds($u, []);
        $outsider = User::factory()->create();

        $this->assertNull(GameAmericanoRanking::place($game, $outsider->id));
        // Все 4 участника присутствуют в таблице даже без сыгранных раундов.
        $this->assertCount(4, GameAmericanoRanking::table($game));
    }

    public function test_show_includes_americano_ranking(): void
    {
        $u = User::factory()->count(4)->create()->pluck('id')->all();
        $game = $this->gameWithRounds($u, [
            ['a' => [$u[0], $u[1]], 'b' => [$u[2], $u[3]], 'sa' => 24, 'sb' => 10],
        ]);
        \Laravel\Sanctum\Sanctum::actingAs(User::find($u[0]));

        $this->getJson("/api/mobile/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.americano_ranking.0.place', 1);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameAmericanoRankingTest.php`
Expected: FAIL — класс `GameAmericanoRanking` не существует; ключа `americano_ranking` нет.

- [ ] **Step 3: Реализовать**

Создать `app/Support/GameAmericanoRanking.php`:
```php
<?php

namespace App\Support;

use App\Models\Game;
use App\Models\GamePlayer;

/**
 * Порядок игроков Американо для ИГРЫ (не турнира):
 *   очки → победы → разница очков → user_id (детерминированный добор).
 *
 * Миррор логики App\Support\AmericanoRanking, но читает GameRound
 * (pair_a/pair_b/score_a/score_b). Турнирный AmericanoRanking/AmericanoTie НЕ трогаем.
 */
class GameAmericanoRanking
{
    /** Отсортированные строки статистики (1-е место первым). */
    private static function computeSorted(Game $game): array
    {
        $stats = [];
        $ensure = function (int $id) use (&$stats) {
            if (!isset($stats[$id])) {
                $stats[$id] = ['id' => $id, 'points' => 0, 'wins' => 0, 'for' => 0, 'against' => 0];
            }
        };

        // Все принятые игроки попадают в таблицу, даже сыгравшие 0 раундов.
        foreach ($game->acceptedPlayers()->pluck('user_id') as $uid) {
            $ensure((int) $uid);
        }

        $rounds = $game->relationLoaded('rounds') ? $game->rounds : $game->rounds()->get();
        foreach ($rounds as $round) {
            if (!$round->is_played || $round->score_a === null || $round->score_b === null) {
                continue;
            }
            $pairA = is_array($round->pair_a) ? $round->pair_a : [];
            $pairB = is_array($round->pair_b) ? $round->pair_b : [];
            $sa = (int) $round->score_a;
            $sb = (int) $round->score_b;

            foreach ($pairA as $uid) {
                $uid = (int) $uid;
                $ensure($uid);
                $stats[$uid]['points'] += $sa;
                $stats[$uid]['for'] += $sa;
                $stats[$uid]['against'] += $sb;
                if ($sa > $sb) $stats[$uid]['wins']++;
            }
            foreach ($pairB as $uid) {
                $uid = (int) $uid;
                $ensure($uid);
                $stats[$uid]['points'] += $sb;
                $stats[$uid]['for'] += $sb;
                $stats[$uid]['against'] += $sa;
                if ($sb > $sa) $stats[$uid]['wins']++;
            }
        }

        $list = array_values($stats);
        usort($list, function ($a, $b) {
            if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
            if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
            $da = $a['for'] - $a['against'];
            $db = $b['for'] - $b['against'];
            if ($da !== $db) return $db <=> $da;
            return $a['id'] <=> $b['id'];
        });

        return $list;
    }

    /** @return int[] user_id по местам (1-е место первым). */
    public static function orderedIds(Game $game): array
    {
        return array_map(fn ($s) => (int) $s['id'], self::computeSorted($game));
    }

    /** Место игрока (1-based) или null, если он не участвовал. */
    public static function place(Game $game, int $userId): ?int
    {
        $idx = array_search($userId, self::orderedIds($game), true);
        return $idx === false ? null : $idx + 1;
    }

    /** Таблица для сериализации: [{user_id, points, wins, diff, place}], в порядке мест. */
    public static function table(Game $game): array
    {
        $out = [];
        foreach (self::computeSorted($game) as $i => $s) {
            $out[] = [
                'user_id' => (int) $s['id'],
                'points' => (int) $s['points'],
                'wins' => (int) $s['wins'],
                'diff' => (int) ($s['for'] - $s['against']),
                'place' => $i + 1,
            ];
        }
        return $out;
    }
}
```

В `formatGame()`, добавить `use App\Support\GameAmericanoRanking;` в шапку контроллера (если ещё нет) и новый ключ в возвращаемый массив (например, сразу после `'rounds' => ...`):
```php
            'americano_ranking' => $game->format === Game::FORMAT_AMERICANO
                ? GameAmericanoRanking::table($game)
                : null,
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameAmericanoRankingTest.php` → PASS (3).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Support/GameAmericanoRanking.php app/Http/Controllers/Api/MobileGameController.php tests/Feature/Games/GameAmericanoRankingTest.php
git commit -m "feat(games): личное ранжирование Американо + отдача в деталях (S6)"
```

---

## Порядок выполнения
Task 1 → 2 (использует хелпер Task 1) → 3 (независим, но идёт последним).

## Не входит (следующие слайсы)
S7 отмена/undo + action_log; S8 финал/утверждение счёта + ELO (запись в `game_players.rating_*` через RatingCalculator по среднему рейтингу команд для sets/points и по личным очкам для americano — НЕ модифицируя трейт); S9 передача прав; S10 лента-пагинация/фильтры; S11 инбокс приглашений; S12 пуши/напоминания; S13 Flutter-экраны; S14 удаление challenge.
```

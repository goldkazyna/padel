# Games Module — Backend S5 (Формат «до N очков»: валидация format_meta) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Валидировать параметры формата (`format_meta`) при создании/редактировании игры по типу формата: `points` требует корректный `points_mode`/`points_target`, `americano` — `sub`/`target`, `sets` — опциональный `tiebreak`. Механика раундов уже реализована в S4 (универсальные раунды подходят для «партий» points).

**Architecture:** Расширяем `Api\MobileGameController` (S0–S4 в main). Добавляем приватный `validateFormatMeta(string $format, ?array $meta): ?string` (возвращает текст ошибки или null) и вызываем его в `store` и `update` после базовой валидации.

**Tech Stack:** Laravel 12, Sanctum, PHPUnit sqlite :memory:.

## Global Constraints
- НЕ трогать `RatingCalculator`, `AmericanoRanking`, старый `challenge`. Нет записей рейтинга.
- Правки только в `store`/`update` (+ новый приватный хелпер). Существующие ключи ответа и поведение не менять сверх добавления валидации.
- Ошибка валидации формата → `422 {success:false, message}`.
- Ветка от main (`feature/games-backend-s5`), не работать на main, не пушить.

**Правила format_meta по форматам:**
- `sets`: `format_meta` опционален. Если есть `tiebreak` — должен быть boolean. Иначе ок.
- `points`: `format_meta` обязателен. `points_mode` ∈ {`first_to`,`total`}. Для `first_to` — `points_target` целое ≥ 1. `points_cap` (опц.) целое ≥ 1. Для `total` — `points_target` опц.
- `americano`: `format_meta` обязателен. `sub` ∈ {`by_sets`,`by_tiebreak`,`by_points`}. `target` целое ≥ 1.

---

### Task 1: Хелпер validateFormatMeta + вызов в store

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (приватный `validateFormatMeta`; вызов в `store`)
- Test: `tests/Feature/Games/GameFormatMetaTest.php`

**Interfaces:**
- Produces:
  - `private function validateFormatMeta(string $format, ?array $meta): ?string` — возвращает текст ошибки или null по правилам выше.
  - `store` после `validateGame(...)` и до создания игры вызывает `validateFormatMeta($validated['format'], $validated['format_meta'] ?? null)`; при ошибке → `422 {success:false, message}` (до записи в БД).

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameFormatMetaTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameFormatMetaTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Club $club, array $override = []): array
    {
        return array_merge([
            'club_id' => $club->id,
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addMinutes(90)->toIso8601String(),
            'type' => 'rated',
            'visibility' => 'public',
            'format' => 'sets',
        ], $override);
    }

    public function test_points_requires_valid_mode(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        // points без format_meta → 422
        $this->postJson('/api/mobile/games', $this->payload($club, ['format' => 'points']))
            ->assertStatus(422);

        // points с некорректным mode → 422
        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'weird', 'points_target' => 21],
        ]))->assertStatus(422);
    }

    public function test_points_first_to_requires_target(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'first_to'], // без target
        ]))->assertStatus(422);

        // корректный points → 201
        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'first_to', 'points_target' => 21],
        ]))->assertCreated();
    }

    public function test_americano_requires_sub_and_target(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'americano',
            'format_meta' => ['sub' => 'nope', 'target' => 7],
        ]))->assertStatus(422);

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'americano',
            'format_meta' => ['sub' => 'by_tiebreak', 'target' => 11],
        ]))->assertCreated();
    }

    public function test_sets_meta_optional(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        // sets без meta → 201
        $this->postJson('/api/mobile/games', $this->payload($club, ['format' => 'sets']))
            ->assertCreated();

        // sets с tiebreak bool → 201
        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'sets', 'format_meta' => ['tiebreak' => true],
        ]))->assertCreated();
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameFormatMetaTest.php`
Expected: FAIL — валидные-невалидные points/americano проходят как 201 (валидации format_meta ещё нет).

- [ ] **Step 3: Реализовать**

Добавить приватный хелпер (например, рядом с `validateGame`):
```php
    /** Валидация format_meta по формату. Возвращает текст ошибки или null. */
    private function validateFormatMeta(string $format, ?array $meta): ?string
    {
        $meta = $meta ?? [];

        if ($format === Game::FORMAT_POINTS) {
            $mode = $meta['points_mode'] ?? null;
            if (!in_array($mode, ['first_to', 'total'], true)) {
                return 'Укажите режим: до N очков или на сумму очков';
            }
            if ($mode === 'first_to') {
                $target = $meta['points_target'] ?? null;
                if (!is_int($target) || $target < 1) {
                    return 'Укажите целевое количество очков';
                }
            }
            if (array_key_exists('points_cap', $meta) && $meta['points_cap'] !== null) {
                if (!is_int($meta['points_cap']) || $meta['points_cap'] < 1) {
                    return 'Некорректный лимит очков';
                }
            }
            return null;
        }

        if ($format === Game::FORMAT_AMERICANO) {
            $sub = $meta['sub'] ?? null;
            if (!in_array($sub, ['by_sets', 'by_tiebreak', 'by_points'], true)) {
                return 'Выберите подформат Американо';
            }
            $target = $meta['target'] ?? null;
            if (!is_int($target) || $target < 1) {
                return 'Укажите значение для подформата';
            }
            return null;
        }

        // sets: format_meta опционален; если есть tiebreak — должен быть boolean.
        if (array_key_exists('tiebreak', $meta) && !is_bool($meta['tiebreak'])) {
            return 'Некорректное значение тай-брейка';
        }
        return null;
    }
```

В `store`, сразу после строки с `$validated = $this->validateGame($request);` (до вычисления длительности/создания игры) добавить:
```php
        $metaErr = $this->validateFormatMeta($validated['format'], $validated['format_meta'] ?? null);
        if ($metaErr !== null) {
            return response()->json(['success' => false, 'message' => $metaErr], 422);
        }
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameFormatMetaTest.php`
Expected: PASS (4 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php tests/Feature/Games/GameFormatMetaTest.php
git commit -m "feat(games): валидация format_meta по формату при создании (S5)"
```

---

### Task 2: Валидация format_meta в update

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (вызов `validateFormatMeta` в `update`)
- Test: `tests/Feature/Games/GameFormatMetaUpdateTest.php`

**Interfaces:**
- Consumes: `validateFormatMeta` (Task 1).
- Produces: `update` после `validateGame(...)` и до `->update(...)` вызывает `validateFormatMeta($validated['format'], $validated['format_meta'] ?? null)`; при ошибке → `422` (до записи).

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameFormatMetaUpdateTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameFormatMetaUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function editPayload(Game $game, array $override = []): array
    {
        return array_merge([
            'club_id' => $game->club_id,
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(2)->addMinutes(90)->toIso8601String(),
            'type' => 'rated',
            'visibility' => 'public',
            'format' => 'sets',
        ], $override);
    }

    public function test_update_rejects_invalid_points_meta(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->putJson("/api/mobile/games/{$game->id}", $this->editPayload($game, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'first_to'], // без target
        ]))->assertStatus(422);
    }

    public function test_update_accepts_valid_points_meta(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->putJson("/api/mobile/games/{$game->id}", $this->editPayload($game, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'first_to', 'points_target' => 24],
        ]))->assertOk()->assertJsonPath('data.format', 'points');
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameFormatMetaUpdateTest.php`
Expected: FAIL — невалидный points-meta проходит в update как 200.

- [ ] **Step 3: Реализовать**

В `update`, сразу после `$validated = $this->validateGame($request);` (до проверки длительности / `->update(...)`) добавить:
```php
        $metaErr = $this->validateFormatMeta($validated['format'], $validated['format_meta'] ?? null);
        if ($metaErr !== null) {
            return response()->json(['success' => false, 'message' => $metaErr], 422);
        }
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameFormatMetaUpdateTest.php` → PASS (2).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php tests/Feature/Games/GameFormatMetaUpdateTest.php
git commit -m "feat(games): валидация format_meta при редактировании (S5)"
```

---

## Порядок выполнения
Task 1 → 2 (Task 2 использует хелпер из Task 1).

## Не входит (следующие слайсы)
S6 americano (авто-расписание на старте + regenerate + личное ранжирование при финале); S7 отмена/undo; S8 утверждение/спор + ELO; S9 передача прав; S10 лента-пагинация; S11 инбокс; S12 пуши/напоминания; S13 Flutter; S14 удаление challenge.

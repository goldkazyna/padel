# Достижения игрока — план реализации

> **Для агентов:** реализовывать по задачам, шаги отмечены чекбоксами. Спека: `docs/superpowers/specs/2026-08-16-achievements-design.md`.

**Цель:** значки за реальные результаты, посчитанные из уже имеющейся истории матчей, турниров и рейтинга; видны всем на карточке игрока; при получении — пуш.

**Архитектура:** история игрока собирается один раз в снимок `PlayerHistory`, по нему прогоняются правила-классы из `app/Achievements/`. Прогресс лежит в `user_achievements`. Экран считает на лету и молчит, пуши шлёт крон.

**Стек:** Laravel 12, PHP 8.3, MySQL (прод) / SQLite in-memory (тесты), Flutter 3.38.

## Общие требования

- Весь текст в коде, комментариях и коммитах — на русском.
- Комментарии объясняют «почему», а не «что». Пустых комментариев не писать.
- Тесты запускать точечно через `php artisan test --filter`, полный прогон — `php -d memory_limit=1G vendor/bin/phpunit`.
- Базовый уровень падений полного прогона: **3 ошибки, 20 падений**. Любое отклонение — регрессия.
- Коммитить после каждой задачи, пушить в конце.
- Прод: миграции накатывать точечно через `--path`.
- `FCMNotificationService::sendToUser()` **не** фильтруется по `PUSH_TEST_PHONES`. Всё, что зовёт его, шлёт на живых людей.

---

### Задача 1: Хранилище прогресса

**Файлы:**
- Создать: `database/migrations/2026_08_17_000001_create_user_achievements_table.php`
- Создать: `app/Models/UserAchievement.php`
- Тест: `tests/Feature/AchievementStorageTest.php`

**Отдаёт дальше:** модель `UserAchievement` с полями `user_id`, `code`, `progress`, `target`, `unlocked_at`, `notified_at`.

- [ ] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class AchievementStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_is_stored_per_player_and_code(): void
    {
        $user = User::factory()->create();

        $row = UserAchievement::create([
            'user_id' => $user->id,
            'code' => 'streak_5',
            'progress' => 3,
            'target' => 5,
        ]);

        $this->assertNull($row->unlocked_at, 'значок ещё не получен');
        $this->assertNull($row->notified_at, 'пуш ещё не отправлен');
    }

    public function test_same_code_cannot_repeat_for_one_player(): void
    {
        $user = User::factory()->create();
        UserAchievement::create([
            'user_id' => $user->id, 'code' => 'debut', 'progress' => 1, 'target' => 1,
        ]);

        $this->expectException(QueryException::class);
        UserAchievement::create([
            'user_id' => $user->id, 'code' => 'debut', 'progress' => 1, 'target' => 1,
        ]);
    }

    public function test_unlocked_and_notified_are_dates(): void
    {
        $user = User::factory()->create();
        $row = UserAchievement::create([
            'user_id' => $user->id, 'code' => 'first_win', 'progress' => 1, 'target' => 1,
            'unlocked_at' => now(), 'notified_at' => now(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $row->fresh()->unlocked_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $row->fresh()->notified_at);
    }
}
```

- [ ] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=AchievementStorageTest`
Ожидание: FAIL — класс `UserAchievement` не найден.

- [ ] **Шаг 3: Миграция**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Прогресс игрока по значкам.
     *
     * target хранится снимком: если порог значка потом поправят, уже выданное
     * достижение и текст старого пуша не разъедутся с тем, что на экране.
     * notified_at отделён от unlocked_at, чтобы заливка истории могла отметить
     * значки как «уведомление отправлено», не отправляя ничего.
     */
    public function up(): void
    {
        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->integer('progress')->default(0);
            $table->integer('target');
            $table->timestamp('unlocked_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'code']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
    }
};
```

Модель:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Прогресс игрока по одному значку.
 * Определение значка (название, иконка, условие) живёт в app/Achievements/.
 */
class UserAchievement extends Model
{
    protected $fillable = ['user_id', 'code', 'progress', 'target', 'unlocked_at', 'notified_at'];

    protected $casts = [
        'progress' => 'integer',
        'target' => 'integer',
        'unlocked_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isUnlocked(): bool
    {
        return $this->unlocked_at !== null;
    }
}
```

- [ ] **Шаг 4: Тест зелёный**

Запуск: `php artisan test --filter=AchievementStorageTest`
Ожидание: 3 passed.

- [ ] **Шаг 5: Коммит**

```bash
git add database/migrations/2026_08_17_000001_create_user_achievements_table.php app/Models/UserAchievement.php tests/Feature/AchievementStorageTest.php
git commit -m "feat(achievements): таблица прогресса по значкам"
```

---

### Задача 2: Вынос истории матчей в сервис

**Файлы:**
- Создать: `app/Services/PlayerMatchHistory.php`
- Изменить: `app/Http/Controllers/Api/MobileMatchController.php` (полностью — вся приватная сборка уезжает в сервис)
- Тест: `tests/Feature/PlayerMatchHistoryTest.php`

**Отдаёт дальше:** `PlayerMatchHistory::for(User $user): array` — список матчей, у каждого ключи: `id`, `tournament_id`, `tournament_name`, `tournament_type`, `club_id`, `date`, `format`, `result`, `score`, `partner`, `opponents`, `sort_date`.

**Почему:** сейчас сборка приватная и отдаёт наружу обрезанный вид. Достижениям нужны три поля, которых в ответе нет: `tournament_id` (для «Без потерь» — имена турниров повторяются), `tournament_type` (для счёта форматов; поле `format` для этого не годится — оно смешивает форматы со стадиями, классика приходит как `group`/`playoff`) и `club_id` (для «Тура по клубам» — названия клубов меняются).

- [ ] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\TournamentGroupMatch;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\PlayerMatchHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Сборка истории матчей игрока. Достижения считают по типу турнира и клубу,
 * поэтому эти поля обязаны быть в каждой записи.
 */
class PlayerMatchHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tournament, 1: array<int, User>} */
    private function classicTournamentWithMatch(): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);
        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'team',
            'status' => 'completed',
            'start_date' => now()->subDay(),
        ]);

        $players = [];
        for ($i = 0; $i < 4; $i++) {
            $players[] = User::factory()->create(['name' => "И{$i}"]);
        }

        $teamA = TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $players[0]->id, 'player2_id' => $players[1]->id,
            'status' => 'approved',
        ]);
        $teamB = TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $players[2]->id, 'player2_id' => $players[3]->id,
            'status' => 'approved',
        ]);

        $group = TournamentGroup::create(['tournament_id' => $tournament->id, 'name' => 'Группа A']);
        TournamentGroupMatch::create([
            'tournament_group_id' => $group->id,
            'team1_id' => $teamA->id, 'team2_id' => $teamB->id,
            'team1_score' => 6, 'team2_score' => 3,
            'status' => 'completed',
        ]);

        return [$tournament, $players];
    }

    public function test_match_carries_tournament_id_type_and_club(): void
    {
        [$tournament, $players] = $this->classicTournamentWithMatch();

        $matches = app(PlayerMatchHistory::class)->for($players[0]);

        $this->assertCount(1, $matches);
        $this->assertSame($tournament->id, $matches[0]['tournament_id']);
        $this->assertSame('team', $matches[0]['tournament_type'], 'тип турнира, а не стадия матча');
        $this->assertSame($tournament->club_id, $matches[0]['club_id']);
        $this->assertSame('win', $matches[0]['result']);
        $this->assertSame($players[1]->id, $matches[0]['partner']['id']);
    }

    public function test_history_endpoint_answer_is_unchanged(): void
    {
        [, $players] = $this->classicTournamentWithMatch();
        Sanctum::actingAs($players[0]);

        $response = $this->getJson('/api/mobile/matches/history')->assertOk();

        // Набор полей в ответе тот же, что до выноса: служебные поля наружу не текут.
        $keys = array_keys($response->json('matches.0'));
        sort($keys);
        $this->assertSame(
            ['date', 'format', 'id', 'opponents', 'partner', 'result', 'score', 'tournament_name'],
            $keys
        );
    }
}
```

- [ ] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=PlayerMatchHistoryTest`
Ожидание: FAIL — класс `PlayerMatchHistory` не найден.

- [ ] **Шаг 3: Перенести сборку в сервис**

Создать `app/Services/PlayerMatchHistory.php`. Целиком перенести из `MobileMatchController` приватные `getAllMatches`, `formatPlayerMatch`, `formatBaliMatch`, `formatTeamMatch`, сделав `for(User $user): array` публичной точкой входа. В трёх форматтерах в возвращаемый массив добавить:

```php
'tournament_id' => $tournament?->id,
'tournament_type' => $tournament?->type,
'club_id' => $tournament?->club_id,
```

`MobileMatchController::history()` берёт матчи из сервиса и перед отдачей срезает служебные поля:

```php
$matches = app(PlayerMatchHistory::class)->for($user);
// ...сортировка и пагинация без изменений...
$items = array_map(function ($m) {
    // Наружу отдаём тот же набор, что и раньше: tournament_id, tournament_type,
    // club_id и sort_date нужны достижениям, а не приложению.
    unset($m['sort_date'], $m['tournament_id'], $m['tournament_type'], $m['club_id']);
    return $m;
}, $items);
```

- [ ] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=PlayerMatchHistoryTest`
Ожидание: 2 passed.

- [ ] **Шаг 5: Коммит**

```bash
git add app/Services/PlayerMatchHistory.php app/Http/Controllers/Api/MobileMatchController.php tests/Feature/PlayerMatchHistoryTest.php
git commit -m "refactor(matches): сборка истории матчей вынесена в сервис"
```

---

### Задача 3: Снимок истории игрока

**Файлы:**
- Создать: `app/Achievements/PlayerHistory.php`
- Тест: `tests/Feature/PlayerHistorySnapshotTest.php`

**Берёт:** `PlayerMatchHistory::for()` из задачи 2.
**Отдаёт дальше:** `PlayerHistory` со свойствами `matches` (array), `ratingEntries` (Collection моделей `RatingHistory`), `tournamentStats` (array из `User::getTournamentStats()`), `user` (User). Статический конструктор `PlayerHistory::for(User $user): self`.

**Почему один снимок:** без него пятнадцать правил означают пятнадцать проходов по десяти таблицам форматов на игрока. На пересчёте после турнира на 24 человека это 360 обходов.

- [ ] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Achievements\PlayerHistory;
use App\Models\RatingHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerHistorySnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_collects_matches_rating_and_tournaments(): void
    {
        $user = User::factory()->create(['rating' => 1200]);
        RatingHistory::create([
            'user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1200,
            'change' => 200, 'reason' => 'Турнир',
        ]);

        $history = PlayerHistory::for($user);

        $this->assertSame($user->id, $history->user->id);
        $this->assertIsArray($history->matches);
        $this->assertCount(1, $history->ratingEntries);
        $this->assertArrayHasKey('total', $history->tournamentStats);
    }

    public function test_empty_player_gives_empty_snapshot(): void
    {
        $history = PlayerHistory::for(User::factory()->create());

        $this->assertSame([], $history->matches);
        $this->assertCount(0, $history->ratingEntries);
        $this->assertSame(0, $history->tournamentStats['total']);
    }
}
```

- [ ] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=PlayerHistorySnapshotTest`
Ожидание: FAIL — класс не найден.

- [ ] **Шаг 3: Реализация**

```php
<?php

namespace App\Achievements;

use App\Models\RatingHistory;
use App\Models\User;
use App\Services\PlayerMatchHistory;
use Illuminate\Support\Collection;

/**
 * Вся история игрока, собранная один раз.
 *
 * Правила получают готовый снимок, а не ходят в базу сами: иначе пятнадцать
 * значков означали бы пятнадцать проходов по десяти таблицам форматов.
 */
class PlayerHistory
{
    /**
     * @param array<int, array<string, mixed>> $matches
     * @param Collection<int, RatingHistory> $ratingEntries
     * @param array<string, mixed> $tournamentStats
     */
    public function __construct(
        public readonly User $user,
        public readonly array $matches,
        public readonly Collection $ratingEntries,
        public readonly array $tournamentStats,
    ) {
    }

    public static function for(User $user): self
    {
        $matches = app(PlayerMatchHistory::class)->for($user);
        usort($matches, fn ($a, $b) => $a['sort_date'] <=> $b['sort_date']);

        return new self(
            user: $user,
            matches: $matches,
            ratingEntries: RatingHistory::where('user_id', $user->id)
                ->orderBy('created_at')
                ->get(),
            tournamentStats: $user->getTournamentStats(),
        );
    }

    /**
     * Матчи, сгруппированные по турниру. Нужны значкам, которые смотрят на
     * турнир целиком, а не на отдельные матчи.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function matchesByTournament(): array
    {
        $grouped = [];
        foreach ($this->matches as $match) {
            $id = $match['tournament_id'];
            if ($id === null) {
                continue;
            }
            $grouped[$id][] = $match;
        }

        return $grouped;
    }
}
```

Матчи сортируются по возрастанию даты — от этого зависит подсчёт серии побед.

- [ ] **Шаг 4: Тест зелёный**

Запуск: `php artisan test --filter=PlayerHistorySnapshotTest`
Ожидание: 2 passed.

- [ ] **Шаг 5: Коммит**

```bash
git add app/Achievements/PlayerHistory.php tests/Feature/PlayerHistorySnapshotTest.php
git commit -m "feat(achievements): снимок истории игрока"
```

---

### Задача 4: Интерфейс правила и реестр

**Файлы:**
- Создать: `app/Achievements/Achievement.php`
- Создать: `app/Achievements/AchievementRegistry.php`
- Создать: `app/Achievements/Rules/FirstWin.php`
- Тест: `tests/Feature/AchievementRuleTest.php`

**Берёт:** `PlayerHistory` из задачи 3.
**Отдаёт дальше:** интерфейс `Achievement` (методы `code`, `title`, `description`, `icon`, `group`, `target`, `progress`), `AchievementRegistry::all(): array<Achievement>` и `AchievementRegistry::byCode(string): ?Achievement`.

- [ ] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Achievements\AchievementRegistry;
use App\Achievements\PlayerHistory;
use App\Achievements\Rules\FirstWin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementRuleTest extends TestCase
{
    use RefreshDatabase;

    private function historyWithMatches(array $results): PlayerHistory
    {
        $user = User::factory()->create();
        $matches = [];
        foreach ($results as $i => $result) {
            $matches[] = [
                'id' => $i + 1,
                'tournament_id' => 1,
                'tournament_type' => 'americano',
                'club_id' => 1,
                'result' => $result,
                'partner' => ['id' => 99, 'name' => 'П', 'avatar' => null],
                'sort_date' => $i,
            ];
        }

        return new PlayerHistory($user, $matches, collect(), ['total' => 0, 'wins' => 0, 'by_type' => []]);
    }

    public function test_first_win_counts_one_won_match(): void
    {
        $rule = new FirstWin();

        $this->assertSame(0, $rule->progress($this->historyWithMatches(['loss', 'draw'])));
        $this->assertSame(1, $rule->progress($this->historyWithMatches(['loss', 'win'])));
        $this->assertSame(1, $rule->progress($this->historyWithMatches(['win', 'win'])),
            'больше цели прогресс не растёт');
    }

    public function test_registry_finds_rule_by_code(): void
    {
        $registry = app(AchievementRegistry::class);

        $this->assertInstanceOf(FirstWin::class, $registry->byCode('first_win'));
        $this->assertNull($registry->byCode('нет такого'));
    }

    public function test_registry_codes_are_unique(): void
    {
        $codes = array_map(fn ($r) => $r->code(), app(AchievementRegistry::class)->all());

        $this->assertSame($codes, array_unique($codes), 'коды значков не должны повторяться');
    }
}
```

- [ ] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=AchievementRuleTest`
Ожидание: FAIL — классы не найдены.

- [ ] **Шаг 3: Реализация**

```php
<?php

namespace App\Achievements;

/**
 * Правило одного значка.
 *
 * Правила живут классами, а не строками в базе: «пять побед подряд» или
 * «все матчи турнира выиграны» — это логика, а не число в колонке.
 */
interface Achievement
{
    public function code(): string;
    public function title(): string;
    public function description(): string;
    /** Имя иконки для приложения. */
    public function icon(): string;
    /** Группа для показа: first_steps | wins | rating | variety | together. */
    public function group(): string;
    public function target(): int;
    /** Сколько уже сделано. Не больше target. */
    public function progress(PlayerHistory $history): int;
}
```

```php
<?php

namespace App\Achievements;

use App\Achievements\Rules\FirstWin;

/**
 * Все значки в порядке показа. Добавить значок — добавить класс и строку сюда.
 */
class AchievementRegistry
{
    /** @return array<int, Achievement> */
    public function all(): array
    {
        return [
            new FirstWin(),
        ];
    }

    public function byCode(string $code): ?Achievement
    {
        foreach ($this->all() as $rule) {
            if ($rule->code() === $code) {
                return $rule;
            }
        }

        return null;
    }
}
```

```php
<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class FirstWin implements Achievement
{
    public function code(): string { return 'first_win'; }
    public function title(): string { return 'Первая победа'; }
    public function description(): string { return 'Выиграть первый матч'; }
    public function icon(): string { return 'emoji_events'; }
    public function group(): string { return 'first_steps'; }
    public function target(): int { return 1; }

    public function progress(PlayerHistory $history): int
    {
        foreach ($history->matches as $match) {
            if ($match['result'] === 'win') {
                return 1;
            }
        }

        return 0;
    }
}
```

- [ ] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=AchievementRuleTest`
Ожидание: 3 passed.

- [ ] **Шаг 5: Коммит**

```bash
git add app/Achievements tests/Feature/AchievementRuleTest.php
git commit -m "feat(achievements): интерфейс правила, реестр и первый значок"
```

---

### Задача 5: Остальные четырнадцать значков

**Файлы:**
- Создать: `app/Achievements/Rules/` — по классу на значок (14 штук)
- Изменить: `app/Achievements/AchievementRegistry.php` — перечислить все
- Тест: `tests/Feature/AchievementRulesSetTest.php`

**Берёт:** интерфейс `Achievement` из задачи 4.

Каждый класс повторяет форму `FirstWin`: те же семь методов, меняются значения и тело `progress()`. Прогресс всегда обрезается по `target` через `min()`.

| Класс | code | Название | group | target | Что считает `progress()` |
|---|---|---|---|---|---|
| `Debut` | `debut` | Дебют | first_steps | 1 | `min(1, $h->tournamentStats['total'])` |
| `Regular5` | `regular_5` | Пятёрка | first_steps | 5 | `min(5, $h->tournamentStats['total'])` |
| `Regular10` | `regular_10` | Постоянный | first_steps | 10 | `min(10, $h->tournamentStats['total'])` |
| `Veteran50` | `veteran_50` | Ветеран | first_steps | 50 | `min(50, $h->tournamentStats['total'])` |
| `FirstGold` | `first_gold` | Первое золото | wins | 1 | `min(1, $h->tournamentStats['wins'])` |
| `Gold3` | `gold_3` | Трижды первый | wins | 3 | `min(3, $h->tournamentStats['wins'])` |
| `Streak5` | `streak_5` | Серия | wins | 5 | самая длинная серия подряд идущих `win`, обрезанная по 5 |
| `Flawless` | `flawless` | Без потерь | wins | 1 | 1, если есть турнир, где все матчи `win` (и матчей ≥ 1) |
| `Jump100` | `jump_100` | Рывок | rating | 1 | 1, если есть запись рейтинга с `change >= 100` |
| `LevelUp` | `level_up` | Новый уровень | rating | 1 | 1, если уровень по `rating_after` где-то выше уровня по первому `rating_before` |
| `Formats5` | `formats_5` | Многоборец | variety | 5 | число разных непустых `tournament_type`, обрезанное по 5 |
| `FormatsAll` | `formats_all` | Знаток форматов | variety | 10 | то же, обрезанное по 10 |
| `Clubs3` | `clubs_3` | Тур по клубам | variety | 3 | число разных непустых `club_id`, обрезанное по 3 |
| `Duo10` | `duo_10` | Сыгранный дуэт | together | 10 | максимум матчей с одним `partner.id`, обрезанный по 10 |

Уровень считается как в `RatingCalculator::updateLevel()`: `max(1.0, min(5.75, floor($rating / 250) * 0.25))`.

- [ ] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Achievements\AchievementRegistry;
use App\Achievements\PlayerHistory;
use App\Achievements\Rules\Clubs3;
use App\Achievements\Rules\Duo10;
use App\Achievements\Rules\Flawless;
use App\Achievements\Rules\Formats5;
use App\Achievements\Rules\LevelUp;
use App\Achievements\Rules\Streak5;
use App\Models\RatingHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Правила, которые считают не по одному числу, а по истории.
 * Простые счётчики турниров проверяются составом реестра.
 */
class AchievementRulesSetTest extends TestCase
{
    use RefreshDatabase;

    private function history(array $matches, $ratingEntries = null, array $stats = []): PlayerHistory
    {
        return new PlayerHistory(
            User::factory()->create(),
            $matches,
            $ratingEntries ?? collect(),
            array_merge(['total' => 0, 'wins' => 0, 'by_type' => []], $stats),
        );
    }

    private function match(array $over = []): array
    {
        return array_merge([
            'id' => 1, 'tournament_id' => 1, 'tournament_type' => 'americano',
            'club_id' => 1, 'result' => 'win',
            'partner' => ['id' => 99, 'name' => 'П', 'avatar' => null],
            'sort_date' => 1,
        ], $over);
    }

    public function test_streak_breaks_on_any_non_win(): void
    {
        $rule = new Streak5();

        $wins = array_map(fn ($i) => $this->match(['sort_date' => $i]), range(1, 5));
        $this->assertSame(5, $rule->progress($this->history($wins)));

        // Ничья прерывает серию так же, как поражение.
        $broken = [
            $this->match(['sort_date' => 1]),
            $this->match(['sort_date' => 2]),
            $this->match(['sort_date' => 3, 'result' => 'draw']),
            $this->match(['sort_date' => 4]),
            $this->match(['sort_date' => 5]),
        ];
        $this->assertSame(2, $rule->progress($this->history($broken)));
    }

    public function test_flawless_needs_every_match_of_one_tournament_won(): void
    {
        $rule = new Flawless();

        $mixed = [
            $this->match(['tournament_id' => 1]),
            $this->match(['tournament_id' => 1, 'result' => 'loss']),
        ];
        $this->assertSame(0, $rule->progress($this->history($mixed)));

        // Ничья тоже ломает «без потерь».
        $withDraw = [
            $this->match(['tournament_id' => 2]),
            $this->match(['tournament_id' => 2, 'result' => 'draw']),
        ];
        $this->assertSame(0, $rule->progress($this->history($withDraw)));

        $clean = [
            $this->match(['tournament_id' => 3]),
            $this->match(['tournament_id' => 3]),
        ];
        $this->assertSame(1, $rule->progress($this->history($clean)));
    }

    public function test_formats_count_tournament_type_not_stage(): void
    {
        $rule = new Formats5();

        // Классический турнир даёт матчи групп и плей-офф — это один формат.
        $matches = [
            $this->match(['tournament_type' => 'classic', 'tournament_id' => 1]),
            $this->match(['tournament_type' => 'classic', 'tournament_id' => 1]),
            $this->match(['tournament_type' => 'americano', 'tournament_id' => 2]),
        ];

        $this->assertSame(2, $rule->progress($this->history($matches)));
    }

    public function test_clubs_counted_by_id(): void
    {
        $rule = new Clubs3();
        $matches = [
            $this->match(['club_id' => 1]),
            $this->match(['club_id' => 2]),
            $this->match(['club_id' => 2]),
            $this->match(['club_id' => null]),
        ];

        $this->assertSame(2, $rule->progress($this->history($matches)));
    }

    public function test_duo_counts_the_most_frequent_partner(): void
    {
        $rule = new Duo10();
        $matches = [
            $this->match(['partner' => ['id' => 1, 'name' => 'А', 'avatar' => null]]),
            $this->match(['partner' => ['id' => 1, 'name' => 'А', 'avatar' => null]]),
            $this->match(['partner' => ['id' => 2, 'name' => 'Б', 'avatar' => null]]),
            $this->match(['partner' => null]),
        ];

        $this->assertSame(2, $rule->progress($this->history($matches)));
    }

    public function test_level_up_compares_against_starting_level(): void
    {
        $rule = new LevelUp();
        $user = User::factory()->create();

        // 1000 → уровень 1.0, 1300 → уровень 1.25.
        $grew = collect([
            new RatingHistory(['user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1300, 'change' => 300]),
        ]);
        $this->assertSame(1, $rule->progress($this->history([], $grew)));

        $flat = collect([
            new RatingHistory(['user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1100, 'change' => 100]),
        ]);
        $this->assertSame(0, $rule->progress($this->history([], $flat)), '1100 — тот же уровень 1.0');
    }

    public function test_registry_has_fifteen_rules(): void
    {
        $this->assertCount(15, app(AchievementRegistry::class)->all());
    }

    public function test_every_rule_returns_zero_on_empty_history(): void
    {
        foreach (app(AchievementRegistry::class)->all() as $rule) {
            $this->assertSame(0, $rule->progress($this->history([])),
                "правило {$rule->code()} должно давать 0 на пустой истории");
        }
    }
}
```

- [ ] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=AchievementRulesSetTest`
Ожидание: FAIL — классы правил не найдены.

- [ ] **Шаг 3: Написать 14 классов и дополнить реестр**

Форма классов — как `FirstWin`. Тела `progress()` для непростых правил:

```php
// Streak5
public function progress(PlayerHistory $history): int
{
    $best = 0;
    $current = 0;
    // Матчи в снимке отсортированы по дате, поэтому серия считается одним проходом.
    foreach ($history->matches as $match) {
        if ($match['result'] === 'win') {
            $current++;
            $best = max($best, $current);
        } else {
            $current = 0;
        }
    }

    return min($this->target(), $best);
}

// Flawless
public function progress(PlayerHistory $history): int
{
    foreach ($history->matchesByTournament() as $matches) {
        $allWon = true;
        foreach ($matches as $match) {
            if ($match['result'] !== 'win') {
                $allWon = false;
                break;
            }
        }
        if ($allWon && $matches !== []) {
            return 1;
        }
    }

    return 0;
}

// Jump100
public function progress(PlayerHistory $history): int
{
    foreach ($history->ratingEntries as $entry) {
        if ((int) $entry->change >= 100) {
            return 1;
        }
    }

    return 0;
}

// LevelUp — уровень считается как в RatingCalculator::updateLevel()
public function progress(PlayerHistory $history): int
{
    $first = $history->ratingEntries->first();
    if (!$first) {
        return 0;
    }

    $startLevel = $this->levelOf((int) $first->rating_before);
    foreach ($history->ratingEntries as $entry) {
        if ($this->levelOf((int) $entry->rating_after) > $startLevel) {
            return 1;
        }
    }

    return 0;
}

private function levelOf(int $rating): float
{
    return max(1.0, min(5.75, floor($rating / 250) * 0.25));
}

// Formats5 и FormatsAll
public function progress(PlayerHistory $history): int
{
    $types = [];
    foreach ($history->matches as $match) {
        if (!empty($match['tournament_type'])) {
            $types[$match['tournament_type']] = true;
        }
    }

    return min($this->target(), count($types));
}

// Clubs3
public function progress(PlayerHistory $history): int
{
    $clubs = [];
    foreach ($history->matches as $match) {
        if (!empty($match['club_id'])) {
            $clubs[$match['club_id']] = true;
        }
    }

    return min($this->target(), count($clubs));
}

// Duo10
public function progress(PlayerHistory $history): int
{
    $byPartner = [];
    foreach ($history->matches as $match) {
        $id = $match['partner']['id'] ?? null;
        if ($id === null) {
            continue;
        }
        $byPartner[$id] = ($byPartner[$id] ?? 0) + 1;
    }

    return min($this->target(), $byPartner === [] ? 0 : max($byPartner));
}
```

Простые счётчики (`Debut`, `Regular5`, `Regular10`, `Veteran50`, `FirstGold`, `Gold3`) отличаются только строкой:

```php
public function progress(PlayerHistory $history): int
{
    return min($this->target(), (int) ($history->tournamentStats['total'] ?? 0));
}
```

— и `'wins'` вместо `'total'` для `FirstGold` и `Gold3`.

Реестр перечисляет все пятнадцать в порядке показа: первые шаги, победы, рейтинг, кругозор, вместе.

- [ ] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=AchievementRulesSetTest`
Ожидание: 8 passed.

- [ ] **Шаг 5: Коммит**

```bash
git add app/Achievements tests/Feature/AchievementRulesSetTest.php
git commit -m "feat(achievements): все пятнадцать значков"
```

---

### Задача 6: Пересчёт и выдача

**Файлы:**
- Создать: `app/Services/AchievementService.php`
- Тест: `tests/Feature/AchievementServiceTest.php`

**Берёт:** `AchievementRegistry`, `PlayerHistory`, модель `UserAchievement`.
**Отдаёт дальше:**
- `AchievementService::sync(User $user): array` — пересчитывает, пишет в базу, возвращает коды значков, которые получены **впервые** этим вызовом.
- `AchievementService::forOwner(User $user): array` — все значки с прогрессом, для своего профиля.
- `AchievementService::forVisitor(User $user): array` — только полученные, для чужой карточки.

Каждый значок в ответе: `code`, `title`, `description`, `icon`, `group`, `progress`, `target`, `unlocked_at` (ISO 8601 или null).

- [ ] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Models\RatingHistory;
use App\Models\User;
use App\Models\UserAchievement;
use App\Services\AchievementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementServiceTest extends TestCase
{
    use RefreshDatabase;

    private function playerWithJump(): User
    {
        $user = User::factory()->create(['rating' => 1300]);
        RatingHistory::create([
            'user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1300,
            'change' => 300, 'reason' => 'Турнир',
        ]);

        return $user;
    }

    public function test_sync_unlocks_and_reports_new_codes(): void
    {
        $user = $this->playerWithJump();

        $fresh = app(AchievementService::class)->sync($user);

        $this->assertContains('jump_100', $fresh);
        $this->assertContains('level_up', $fresh);
        $this->assertNotNull(
            UserAchievement::where('user_id', $user->id)->where('code', 'jump_100')->value('unlocked_at')
        );
    }

    public function test_repeat_sync_reports_nothing_new(): void
    {
        $user = $this->playerWithJump();
        $service = app(AchievementService::class);
        $service->sync($user);

        $this->assertSame([], $service->sync($user), 'второй проход не выдаёт те же значки заново');
        $this->assertSame(15, UserAchievement::where('user_id', $user->id)->count(),
            'на игрока заводится по строке на каждый значок, дублей нет');
    }

    public function test_unlocked_at_does_not_move_on_repeat(): void
    {
        $user = $this->playerWithJump();
        $service = app(AchievementService::class);
        $service->sync($user);

        $first = UserAchievement::where('user_id', $user->id)->where('code', 'jump_100')->value('unlocked_at');
        $this->travel(1)->days();
        $service->sync($user);

        $this->assertEquals(
            $first,
            UserAchievement::where('user_id', $user->id)->where('code', 'jump_100')->value('unlocked_at'),
            'дата получения не переписывается'
        );
    }

    public function test_owner_sees_progress_visitor_sees_only_unlocked(): void
    {
        $user = $this->playerWithJump();
        $service = app(AchievementService::class);
        $service->sync($user);

        $owner = $service->forOwner($user);
        $visitor = $service->forVisitor($user);

        $this->assertCount(15, $owner, 'владелец видит и незакрытые значки');
        $this->assertNotEmpty($visitor);
        foreach ($visitor as $item) {
            $this->assertNotNull($item['unlocked_at'], 'гостю показываем только полученное');
        }
        $this->assertArrayHasKey('progress', $owner[0]);
        $this->assertArrayHasKey('title', $owner[0]);
    }

    public function test_visitor_view_does_not_recalculate(): void
    {
        $user = $this->playerWithJump();

        // Пересчёта не было — гость видит пусто, а не свежий расчёт.
        $this->assertSame([], app(AchievementService::class)->forVisitor($user));
        $this->assertSame(0, UserAchievement::where('user_id', $user->id)->count());
    }
}
```

- [ ] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=AchievementServiceTest`
Ожидание: FAIL — класс не найден.

- [ ] **Шаг 3: Реализация**

```php
<?php

namespace App\Services;

use App\Achievements\Achievement;
use App\Achievements\AchievementRegistry;
use App\Achievements\PlayerHistory;
use App\Models\User;
use App\Models\UserAchievement;

/**
 * Пересчёт значков игрока и подготовка их к показу.
 *
 * Пуши этот сервис не шлёт: рассылка живёт в команде синхронизации, чтобы
 * открытие экрана не порождало уведомление о том, что и так на экране.
 */
class AchievementService
{
    public function __construct(private readonly AchievementRegistry $registry)
    {
    }

    /**
     * Пересчитать значки игрока.
     *
     * @return array<int, string> коды значков, полученных именно этим вызовом
     */
    public function sync(User $user): array
    {
        $history = PlayerHistory::for($user);
        $rows = UserAchievement::where('user_id', $user->id)->get()->keyBy('code');
        $fresh = [];

        foreach ($this->registry->all() as $rule) {
            $progress = $rule->progress($history);
            $row = $rows->get($rule->code());
            $wasUnlocked = $row?->unlocked_at !== null;
            $isUnlocked = $progress >= $rule->target();

            UserAchievement::updateOrCreate(
                ['user_id' => $user->id, 'code' => $rule->code()],
                [
                    // Прогресс не откатываем: правку счёта задним числом
                    // игрок не должен воспринимать как отобранную награду.
                    'progress' => max($progress, (int) ($row->progress ?? 0)),
                    'target' => $rule->target(),
                    'unlocked_at' => $wasUnlocked ? $row->unlocked_at : ($isUnlocked ? now() : null),
                ]
            );

            if ($isUnlocked && !$wasUnlocked) {
                $fresh[] = $rule->code();
            }
        }

        return $fresh;
    }

    /** Все значки игрока с прогрессом — для своего профиля. */
    public function forOwner(User $user): array
    {
        $rows = UserAchievement::where('user_id', $user->id)->get()->keyBy('code');

        return array_map(
            fn (Achievement $rule) => $this->present($rule, $rows->get($rule->code())),
            $this->registry->all()
        );
    }

    /** Только полученные — для чужой карточки. Без пересчёта. */
    public function forVisitor(User $user): array
    {
        $rows = UserAchievement::where('user_id', $user->id)
            ->whereNotNull('unlocked_at')
            ->get()
            ->keyBy('code');

        $result = [];
        foreach ($this->registry->all() as $rule) {
            $row = $rows->get($rule->code());
            if ($row) {
                $result[] = $this->present($rule, $row);
            }
        }

        return $result;
    }

    private function present(Achievement $rule, ?UserAchievement $row): array
    {
        return [
            'code' => $rule->code(),
            'title' => $rule->title(),
            'description' => $rule->description(),
            'icon' => $rule->icon(),
            'group' => $rule->group(),
            'progress' => (int) ($row->progress ?? 0),
            'target' => $rule->target(),
            'unlocked_at' => $row?->unlocked_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=AchievementServiceTest`
Ожидание: 5 passed.

- [ ] **Шаг 5: Коммит**

```bash
git add app/Services/AchievementService.php tests/Feature/AchievementServiceTest.php
git commit -m "feat(achievements): пересчёт и выдача значков"
```

---

### Задача 7: API

**Файлы:**
- Создать: `app/Http/Controllers/Api/MobileAchievementController.php`
- Изменить: `routes/api.php` — после блока «Матчи» (около строки 233)
- Тест: `tests/Feature/AchievementApiTest.php`

**Берёт:** `AchievementService` из задачи 6.
**Отдаёт дальше:**
- `GET /api/mobile/achievements` — свои: пересчитывает и отдаёт все с прогрессом.
- `GET /api/mobile/achievements/player/{user}` — чужие: только полученные, без пересчёта.

- [ ] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Models\RatingHistory;
use App\Models\User;
use App\Models\UserAchievement;
use App\Services\AchievementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AchievementApiTest extends TestCase
{
    use RefreshDatabase;

    private function playerWithJump(): User
    {
        $user = User::factory()->create(['rating' => 1300]);
        RatingHistory::create([
            'user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1300,
            'change' => 300, 'reason' => 'Турнир',
        ]);

        return $user;
    }

    public function test_own_achievements_are_recalculated_on_open(): void
    {
        $user = $this->playerWithJump();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/achievements')->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertCount(15, $response->json('achievements'));
        $this->assertSame(15, UserAchievement::where('user_id', $user->id)->count(),
            'открытие экрана пересчитывает значки');

        $jump = collect($response->json('achievements'))->firstWhere('code', 'jump_100');
        $this->assertNotNull($jump['unlocked_at']);
        $this->assertSame('Рывок', $jump['title']);
    }

    public function test_open_does_not_send_push(): void
    {
        $user = $this->playerWithJump();
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/achievements')->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $user->id, 'type' => 'achievement',
        ]);
        $this->assertNull(
            UserAchievement::where('user_id', $user->id)->where('code', 'jump_100')->value('notified_at'),
            'экран не помечает значок как отправленный — иначе крон промолчит'
        );
    }

    public function test_other_player_shows_only_unlocked(): void
    {
        $other = $this->playerWithJump();
        app(AchievementService::class)->sync($other);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson("/api/mobile/achievements/player/{$other->id}")->assertOk();

        $items = $response->json('achievements');
        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertNotNull($item['unlocked_at']);
        }
    }

    public function test_other_player_view_does_not_recalculate(): void
    {
        $other = $this->playerWithJump();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/achievements/player/{$other->id}")->assertOk();

        $this->assertSame(0, UserAchievement::where('user_id', $other->id)->count());
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/mobile/achievements')->assertUnauthorized();
    }
}
```

- [ ] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=AchievementApiTest`
Ожидание: FAIL — маршрут не найден (404).

- [ ] **Шаг 3: Реализация**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AchievementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Значки игрока.
 *
 * Свой профиль пересчитывается при открытии — игрок всегда видит честное
 * состояние. Чужой только читается: чужие карточки открывают часто, а их
 * значки обновит либо крон, либо сам владелец.
 */
class MobileAchievementController extends Controller
{
    public function __construct(private readonly AchievementService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->service->sync($user);

        return response()->json([
            'success' => true,
            'achievements' => $this->service->forOwner($user),
        ]);
    }

    public function player(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'achievements' => $this->service->forVisitor($user),
        ]);
    }
}
```

В `routes/api.php` сразу после строки с `matches/history`:

```php
        // Достижения
        Route::get('/achievements', [MobileAchievementController::class, 'index']);
        Route::get('/achievements/player/{user}', [MobileAchievementController::class, 'player']);
```

и импорт `use App\Http\Controllers\Api\MobileAchievementController;` к остальным в шапке файла.

- [ ] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=AchievementApiTest`
Ожидание: 5 passed.

- [ ] **Шаг 5: Коммит**

```bash
git add app/Http/Controllers/Api/MobileAchievementController.php routes/api.php tests/Feature/AchievementApiTest.php
git commit -m "feat(achievements): эндпоинты своих и чужих значков"
```

---

### Задача 8: Рассылка и категория уведомлений

**Файлы:**
- Создать: `app/Services/AchievementNotifier.php`
- Создать: `app/Console/Commands/SyncAchievements.php`
- Изменить: `app/Http/Controllers/Api/MobileNotificationController.php:49-55` — добавить категорию
- Изменить: `bootstrap/app.php:32-62` — расписание
- Тест: `tests/Feature/AchievementNotifyTest.php`

**Берёт:** `AchievementService::sync()` из задачи 6.
**Отдаёт дальше:** команда `achievements:sync`, класс `AchievementNotifier::notify(User $user, array $codes): void`.

Категория `achievement` с цветом `#EAB34E` добавляется в список в `categories()` — там сейчас пять: tournament, booking, challenge, support, general.

- [ ] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AchievementNotifyTest extends TestCase
{
    use RefreshDatabase;

    private function playerOfRecentTournament(): User
    {
        $user = User::factory()->create(['rating' => 1300]);
        $tournament = Tournament::factory()->create([
            'status' => 'completed',
            'updated_at' => now()->subMinutes(5),
        ]);
        $tournament->participants()->attach($user->id, ['status' => 'registered']);
        RatingHistory::create([
            'user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1300,
            'change' => 300, 'reason' => 'Турнир',
        ]);

        return $user;
    }

    public function test_command_sends_one_notification_for_the_whole_batch(): void
    {
        $user = $this->playerOfRecentTournament();

        $this->artisan('achievements:sync')->assertSuccessful();

        // Значков открылось несколько, уведомление одно: пять подряд — спам.
        $this->assertSame(1, \App\Models\Notification::where('user_id', $user->id)
            ->where('type', 'achievement')->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'category' => 'achievement',
        ]);
    }

    public function test_second_run_stays_quiet(): void
    {
        $user = $this->playerOfRecentTournament();
        $this->artisan('achievements:sync');

        $this->artisan('achievements:sync')->assertSuccessful();

        $this->assertSame(1, \App\Models\Notification::where('user_id', $user->id)
            ->where('type', 'achievement')->count());
    }

    public function test_notified_at_is_stamped(): void
    {
        $user = $this->playerOfRecentTournament();

        $this->artisan('achievements:sync');

        $this->assertSame(0, UserAchievement::where('user_id', $user->id)
            ->whereNotNull('unlocked_at')->whereNull('notified_at')->count());
    }

    public function test_players_without_recent_tournaments_are_skipped(): void
    {
        $user = User::factory()->create();
        RatingHistory::create([
            'user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1300,
            'change' => 300, 'reason' => 'Старое',
        ]);

        $this->artisan('achievements:sync')->assertSuccessful();

        $this->assertSame(0, UserAchievement::where('user_id', $user->id)->count());
    }

    public function test_category_is_listed_for_the_app(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $keys = collect($this->getJson('/api/mobile/notifications/categories')->assertOk()
            ->json('categories'))->pluck('key');

        $this->assertContains('achievement', $keys->all());
    }
}
```

- [ ] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=AchievementNotifyTest`
Ожидание: FAIL — команда `achievements:sync` не найдена.

- [ ] **Шаг 3: Реализация**

`AchievementNotifier` — единственное место, откуда уходит пуш про значки:

```php
<?php

namespace App\Services;

use App\Achievements\AchievementRegistry;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserAchievement;

/**
 * Уведомление о новых значках.
 *
 * Пуш один на пачку: за один турнир может открыться сразу пять значков,
 * и пять уведомлений подряд читаются как спам.
 */
class AchievementNotifier
{
    public function __construct(private readonly AchievementRegistry $registry)
    {
    }

    /** @param array<int, string> $codes коды значков, полученных только что */
    public function notify(User $user, array $codes): void
    {
        if ($codes === []) {
            return;
        }

        [$title, $body] = $this->text($codes);

        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => 'achievement',
            'category' => 'achievement',
            'data' => ['achievement_code' => $codes[0]],
        ]);

        try {
            app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
                'type' => 'achievement',
                'achievement_code' => (string) $codes[0],
            ]);
        } catch (\Throwable $e) {
            // Пуш не критичен: значок уже виден на экране.
        }

        UserAchievement::where('user_id', $user->id)
            ->whereIn('code', $codes)
            ->update(['notified_at' => now()]);
    }

    /** @param array<int, string> $codes */
    private function text(array $codes): array
    {
        if (count($codes) === 1) {
            $rule = $this->registry->byCode($codes[0]);

            return ['Новое достижение', $rule?->title() ?? 'Открыт новый значок'];
        }

        return ['Новые достижения', 'Открыто значков: ' . count($codes)];
    }
}
```

Команда:

```php
<?php

namespace App\Console\Commands;

use App\Models\Tournament;
use App\Models\User;
use App\Services\AchievementNotifier;
use App\Services\AchievementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Пересчитать значки тем, кто только что доиграл, и уведомить о новых.
 *
 * Берём участников недавно завершённых турниров, а не всех подряд: полный
 * проход по базе означал бы обход десяти таблиц форматов на каждого игрока.
 */
class SyncAchievements extends Command
{
    protected $signature = 'achievements:sync {--hours=1 : за сколько часов брать турниры}';
    protected $description = 'Пересчитать значки недавно игравших и отправить уведомления';

    public function handle(AchievementService $service, AchievementNotifier $notifier): int
    {
        $since = now()->subHours((int) $this->option('hours'));

        $userIds = Tournament::where('status', 'completed')
            ->where('updated_at', '>=', $since)
            ->with('participants:id')
            ->get()
            ->flatMap(fn ($t) => $t->participants->pluck('id'))
            ->unique()
            ->values();

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            try {
                $notifier->notify($user, $service->sync($user));
            } catch (\Throwable $e) {
                // Один сломанный профиль не должен ронять рассылку остальным.
                Log::error('achievements:sync упал на игроке', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Обработано игроков: {$userIds->count()}");

        return self::SUCCESS;
    }
}
```

В `MobileNotificationController::categories()` в массив `$cats` добавить `['key' => 'achievement', 'color' => '#EAB34E']`.

В `bootstrap/app.php` в блок расписания:

```php
        // Значки за только что сыгранное + уведомления о новых.
        $schedule->command('achievements:sync')
            ->everyTenMinutes()
            ->withoutOverlapping();
```

- [ ] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=AchievementNotifyTest`
Ожидание: 5 passed.

- [ ] **Шаг 5: Коммит**

```bash
git add app/Services/AchievementNotifier.php app/Console/Commands/SyncAchievements.php app/Http/Controllers/Api/MobileNotificationController.php bootstrap/app.php tests/Feature/AchievementNotifyTest.php
git commit -m "feat(achievements): рассылка о новых значках и категория уведомлений"
```

---

### Задача 9: Тихая заливка истории

**Файлы:**
- Создать: `app/Console/Commands/BackfillAchievements.php`
- Тест: `tests/Feature/AchievementBackfillTest.php`

**Берёт:** `AchievementService::sync()` из задачи 6.

**Почему отдельная команда:** на релизе у каждого игрока разом откроется вся история, и честный крон разослал бы пуши за то, что заработано полгода назад. Заливка проставляет `notified_at` задним числом.

**Команда физически не умеет слать пуши** — она не знает про `AchievementNotifier`. Не флаг `--no-push`, который можно забыть: `FCMNotificationService::sendToUser()` не фильтруется по `PUSH_TEST_PHONES`, и ошибка означала бы веерную рассылку на всю базу без возможности остановить.

- [ ] **Шаг 1: Написать падающий тест**

```php
<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function playerWithHistory(): User
    {
        $user = User::factory()->create(['rating' => 1300]);
        RatingHistory::create([
            'user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1300,
            'change' => 300, 'reason' => 'Турнир',
        ]);

        return $user;
    }

    public function test_backfill_marks_history_as_already_notified(): void
    {
        $user = $this->playerWithHistory();

        $this->artisan('achievements:backfill')->assertSuccessful();

        $unlocked = UserAchievement::where('user_id', $user->id)->whereNotNull('unlocked_at')->get();
        $this->assertNotEmpty($unlocked);
        foreach ($unlocked as $row) {
            $this->assertNotNull($row->notified_at, 'исторический значок помечен как уведомлённый');
        }
    }

    public function test_backfill_sends_nothing(): void
    {
        $user = $this->playerWithHistory();

        $this->artisan('achievements:backfill');

        $this->assertSame(0, Notification::where('user_id', $user->id)->count());
    }

    public function test_sync_after_backfill_is_quiet_about_old_badges(): void
    {
        $user = $this->playerWithHistory();
        $tournament = Tournament::factory()->create([
            'status' => 'completed', 'updated_at' => now()->subMinutes(5),
        ]);
        $tournament->participants()->attach($user->id, ['status' => 'registered']);

        $this->artisan('achievements:backfill');
        $this->artisan('achievements:sync');

        $this->assertSame(0, Notification::where('user_id', $user->id)
            ->where('type', 'achievement')->count(),
            'за старое пушей нет');
    }

    public function test_repeat_backfill_is_safe(): void
    {
        $user = $this->playerWithHistory();
        $this->artisan('achievements:backfill');
        $stamped = UserAchievement::where('user_id', $user->id)->whereNotNull('notified_at')->count();

        $this->artisan('achievements:backfill')->assertSuccessful();

        $this->assertSame($stamped, UserAchievement::where('user_id', $user->id)
            ->whereNotNull('notified_at')->count());
        $this->assertSame(0, Notification::where('user_id', $user->id)->count());
    }
}
```

- [ ] **Шаг 2: Убедиться, что тест падает**

Запуск: `php artisan test --filter=AchievementBackfillTest`
Ожидание: FAIL — команда `achievements:backfill` не найдена.

- [ ] **Шаг 3: Реализация**

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserAchievement;
use App\Services\AchievementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Разовая заливка истории при выпуске достижений.
 *
 * На релизе у каждого игрока разом открывается вся его история. Если оставить
 * это крону, человек получит пуш за то, что заработал полгода назад. Здесь
 * значки проставляются молча: уже полученным сразу ставится notified_at.
 *
 * Команда не умеет слать уведомления — она не знает про AchievementNotifier.
 * Это не забывчивость, а защита: sendToUser не фильтруется по PUSH_TEST_PHONES,
 * и ошибка означала бы рассылку на всю базу без возможности остановить.
 */
class BackfillAchievements extends Command
{
    protected $signature = 'achievements:backfill {--chunk=200 : размер пачки игроков}';
    protected $description = 'Залить значки по истории, ничего не отправляя';

    public function handle(AchievementService $service): int
    {
        $processed = 0;

        User::query()->orderBy('id')->chunkById((int) $this->option('chunk'), function ($users) use ($service, &$processed) {
            foreach ($users as $user) {
                try {
                    $service->sync($user);

                    // Всё, что открылось по истории, считаем уже сообщённым.
                    UserAchievement::where('user_id', $user->id)
                        ->whereNotNull('unlocked_at')
                        ->whereNull('notified_at')
                        ->update(['notified_at' => now()]);
                } catch (\Throwable $e) {
                    Log::error('achievements:backfill упал на игроке', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                $processed++;
            }

            $this->info("Обработано: {$processed}");
        });

        $this->info("Готово. Игроков: {$processed}");

        return self::SUCCESS;
    }
}
```

- [ ] **Шаг 4: Тесты зелёные**

Запуск: `php artisan test --filter=AchievementBackfillTest`
Ожидание: 4 passed.

- [ ] **Шаг 5: Полный прогон и коммит**

Запуск: `php -d memory_limit=1G vendor/bin/phpunit`
Ожидание: 3 ошибки, 20 падений — как в базовом уровне. Больше — регрессия, чинить.

```bash
git add app/Console/Commands/BackfillAchievements.php tests/Feature/AchievementBackfillTest.php
git commit -m "feat(achievements): тихая заливка истории при запуске"
git push
```

---

### Задача 10: Приложение — модель и сервис

**Файлы:**
- Создать: `C:\projects\padel_app\lib\models\achievement.dart`
- Создать: `C:\projects\padel_app\lib\services\achievement_service.dart`

**Берёт:** эндпоинты из задачи 7.
**Отдаёт дальше:** класс `Achievement` с полями `code`, `title`, `description`, `icon`, `group`, `progress`, `target`, `unlockedAt`, геттерами `isUnlocked` и `progressRatio`; `AchievementService.mine()` и `AchievementService.ofPlayer(int userId)`.

- [ ] **Шаг 1: Модель**

```dart
/// Значок игрока: что это, сколько сделано и получен ли.
class Achievement {
  final String code;
  final String title;
  final String description;
  final String icon;
  final String group;
  final int progress;
  final int target;
  final DateTime? unlockedAt;

  const Achievement({
    required this.code,
    required this.title,
    required this.description,
    required this.icon,
    required this.group,
    required this.progress,
    required this.target,
    this.unlockedAt,
  });

  bool get isUnlocked => unlockedAt != null;

  /// Доля выполнения от 0 до 1 — для полоски прогресса.
  double get progressRatio =>
      target <= 0 ? 0 : (progress / target).clamp(0.0, 1.0);

  factory Achievement.fromJson(Map<String, dynamic> json) {
    final unlocked = json['unlocked_at'] as String?;
    return Achievement(
      code: json['code'] as String? ?? '',
      title: json['title'] as String? ?? '',
      description: json['description'] as String? ?? '',
      icon: json['icon'] as String? ?? 'emoji_events',
      group: json['group'] as String? ?? 'first_steps',
      progress: json['progress'] as int? ?? 0,
      target: json['target'] as int? ?? 1,
      unlockedAt: unlocked == null ? null : DateTime.tryParse(unlocked),
    );
  }
}
```

- [ ] **Шаг 2: Сервис**

```dart
import '../models/achievement.dart';
import 'api_service.dart';
import 'storage_service.dart';

/// Значки: свои с прогрессом, чужие только полученные.
class AchievementService {
  final ApiService _api;
  final StorageService _storage;

  AchievementService(this._api, this._storage);

  Future<List<Achievement>> mine() => _load('/achievements');

  Future<List<Achievement>> ofPlayer(int userId) =>
      _load('/achievements/player/$userId');

  Future<List<Achievement>> _load(String endpoint) async {
    final token = await _storage.getToken();
    final response = await _api.get(endpoint, token);
    final list = (response['achievements'] as List?) ?? const [];
    return list
        .map((j) => Achievement.fromJson(j as Map<String, dynamic>))
        .toList();
  }
}
```

- [ ] **Шаг 3: Зарегистрировать сервис**

Добавить `AchievementService` в провайдеры рядом с остальными сервисами в `lib/main.dart` — тем же способом, каким там зарегистрирован `TournamentService`.

- [ ] **Шаг 4: Проверить**

Запуск: `flutter analyze lib/models/achievement.dart lib/services/achievement_service.dart`
Ожидание: 0 errors.

- [ ] **Шаг 5: Коммит**

```bash
git add lib/models/achievement.dart lib/services/achievement_service.dart lib/main.dart
git commit -m "feat(achievements): модель и сервис значков"
```

---

### Задача 11: Приложение — карточка значка и блок в профиле

**Файлы:**
- Создать: `C:\projects\padel_app\lib\widgets\achievements\achievement_badge.dart`
- Переписать: `C:\projects\padel_app\lib\widgets\profile\achievements_section.dart`
- Изменить: `C:\projects\padel_app\lib\screens\profile_screen.dart` — подключить блок

**Берёт:** модель и сервис из задачи 10.
**Отдаёт дальше:** виджет `AchievementBadge({required Achievement achievement, bool showProgress})`.

Виджет `achievements_section.dart` сейчас показывает четыре выдуманных значка и нигде не подключён — переписываем на реальные данные и включаем в профиль.

- [ ] **Шаг 1: Карточка значка**

Полученный — цветной, с иконкой по имени из `achievement.icon`; неполученный — приглушённый с полоской прогресса и подписью «8 / 10». Имя иконки отображается в `IconData` через явную карту (динамический доступ к иконкам ломает tree shaking):

```dart
const _icons = <String, IconData>{
  'emoji_events': Icons.emoji_events,
  'bolt': Icons.bolt,
  'star': Icons.star,
  'calendar_month': Icons.calendar_month,
  'military_tech': Icons.military_tech,
  'trending_up': Icons.trending_up,
  'workspace_premium': Icons.workspace_premium,
  'explore': Icons.explore,
  'handshake': Icons.handshake,
  'location_city': Icons.location_city,
};
```

Неизвестное имя падает на `Icons.emoji_events`.

- [ ] **Шаг 2: Блок в профиле**

Горизонтальный список: сначала полученные, затем ближайшие к получению (сортировка по `progressRatio` по убыванию). Заголовок «Достижения», справа «Все» — переход на экран из задачи 12. Если данные ещё грузятся — та же карусель со скелетонами, чтобы блок не прыгал.

- [ ] **Шаг 3: Подключить в профиль**

В `profile_screen.dart` добавить блок после `RatingDynamicsCard`.

- [ ] **Шаг 4: Проверить**

Запуск: `flutter analyze lib/`
Ожидание: 0 errors.

- [ ] **Шаг 5: Коммит**

```bash
git add lib/widgets/achievements lib/widgets/profile/achievements_section.dart lib/screens/profile_screen.dart
git commit -m "feat(achievements): блок значков в профиле на реальных данных"
```

---

### Задача 12: Приложение — экран всех значков и чужая карточка

**Файлы:**
- Создать: `C:\projects\padel_app\lib\screens\achievements_screen.dart`
- Изменить: `C:\projects\padel_app\lib\screens\player_profile_screen.dart`
- Изменить: `C:\projects\padel_app\lib\services\push_notification_service.dart`

**Берёт:** виджет и сервис из задач 10–11.

- [ ] **Шаг 1: Экран всех значков**

Значки по группам, у каждой группы заголовок: «Первые шаги», «Победы», «Рейтинг», «Кругозор», «Вместе» (по полю `group`: first_steps, wins, rating, variety, together). Сверху счётчик «Получено 7 из 15». Pull-to-refresh перезапрашивает.

- [ ] **Шаг 2: Блок на чужой карточке**

В `player_profile_screen.dart` — только полученные значки через `AchievementService.ofPlayer(playerId)`. Пустой ответ означает, что блок не показывается вовсе: пустых ячеек и счётчика «3 из 15» на чужой карточке быть не должно.

- [ ] **Шаг 3: Обработка пуша**

В `push_notification_service.dart` добавить `type == 'achievement'` к разбору — открывать экран достижений, тем же способом, каким там обрабатываются остальные типы.

- [ ] **Шаг 4: Проверить**

Запуск: `flutter analyze lib/`
Ожидание: 0 errors.

- [ ] **Шаг 5: Коммит и пуш**

```bash
git add lib/screens/achievements_screen.dart lib/screens/player_profile_screen.dart lib/services/push_notification_service.dart
git commit -m "feat(achievements): экран значков, чужая карточка и переход из пуша"
git push
```

---

## Деплой

```bash
git pull
php artisan migrate --path=database/migrations/2026_08_17_000001_create_user_achievements_table.php
php artisan achievements:backfill
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

Заливка идёт долго — проход по десяти таблицам форматов на каждого игрока. Запускать один раз, после миграции и до того, как крон впервые дёрнет `achievements:sync`. Повторный запуск безопасен.

Крон уже настроен в проекте, отдельных действий не требует.

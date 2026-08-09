# Эскалера, веб-версия — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Новый тип турнира «Эскалера» в вебе: создать, расставить игроков по кортам, сыграть раунды с подъёмом и спуском между кортами, закрыть турнир с тремя наградами и начислением рейтинга.

**Architecture:** Пять таблиц (игроки, раунды, корты раунда, короткие матчи, результаты раунда), вся логика формата в `EscaleraService`, контроллер `Club\EscaleraController` только принимает запросы. Структура повторяет уже работающий Just Padel It, но с одним принципиальным отличием: на корте три матча за раунд, а в общую таблицу идут не очки, а позиция игрока в общем строю.

**Tech Stack:** Laravel 12, MySQL (прод) / SQLite (тесты), Blade, PHPUnit.

## Global Constraints

- Спека: `docs/superpowers/specs/2026-08-09-escalera-web-design.md`
- Исходная спецификация формата: `C:\Users\Denis\Downloads\escalera-final.md`
- Все комментарии в коде и тексты интерфейса — на русском. Никогда не на английском.
- Число участников строго равно `courts_count × 4`. Иначе турнир не стартует.
- В общую таблицу идёт **позиция**, не очки: `позиция = (корт − 1) × 4 + место`, `баллы = всего игроков − позиция + 1`.
- Очки внутри корта нужны только для ранжирования четвёрки и в сумму баллов не входят.
- Порядок матчей на корте от посадки: 1+4 против 2+3, затем 1+3 против 2+4, затем 1+2 против 3+4.
- Полное равенство после всех тай-брейков решается рейтингом. Жребия нет.
- Таймер раунда, лимит времени, публичная ссылка, экраны игрока и пуши — **вне рамок**.
- Рейтинг начисляется по каждому короткому матчу существующей формулой из трейта `RatingCalculator` — той же, что используют остальные форматы.
- Прогон тестов точечный, через `--filter`. Полный сьют в этом окружении не запускается (`memory_limit`), это предсуществующая проблема.
- Работа в ветке `feature/escalera` (уже создана и активна).

---

## File Structure

| Файл | Ответственность |
|---|---|
| `database/migrations/2026_08_09_000001_add_escalera_to_tournaments_type_enum.php` | Создать: тип `escalera` в enum |
| `database/migrations/2026_08_09_000002_add_escalera_fields_to_tournaments.php` | Создать: очки в матче, режим ранжирования |
| `database/migrations/2026_08_09_000003_create_escalera_tables.php` | Создать: пять таблиц формата |
| `app/Models/EscaleraPlayer.php` | Создать: участник турнира |
| `app/Models/EscaleraRound.php` | Создать: раунд |
| `app/Models/EscaleraRoundCourt.php` | Создать: корт раунда с посадкой |
| `app/Models/EscaleraMatch.php` | Создать: короткий матч |
| `app/Models/EscaleraRoundResult.php` | Создать: результат игрока за раунд |
| `app/Models/Tournament.php` | Изменить: `isEscalera()`, связи, подпись типа |
| `app/Services/EscaleraService.php` | Создать: вся логика формата |
| `app/Http/Controllers/Club/EscaleraController.php` | Создать: посев, старт, счёт, закрытие раунда |
| `routes/web.php` | Изменить: маршруты формата |
| `resources/views/club/tournaments/escalera/seeding.blade.php` | Создать: стартовая расстановка |
| `resources/views/club/tournaments/escalera/show.blade.php` | Создать: экран турнира |
| `resources/views/club/tournaments/escalera/partials/` | Создать: карточки кортов, таблица, награды |
| `resources/views/club/tournaments/create.blade.php` | Изменить: блок параметров эскалеры |
| `tests/Unit/Services/EscaleraServiceTest.php` | Создать: тесты расчётов |
| `tests/Feature/EscaleraFlowTest.php` | Создать: сквозной прогон турнира |

---

### Task 1: Структура данных

**Files:**
- Create: три миграции из таблицы выше
- Create: пять моделей из таблицы выше
- Modify: `app/Models/Tournament.php`
- Test: `tests/Unit/Services/EscaleraServiceTest.php`

**Interfaces:**
- Consumes: существующие `Tournament`, `User`
- Produces: тип `escalera`; модели `EscaleraPlayer`, `EscaleraRound`, `EscaleraRoundCourt`, `EscaleraMatch`, `EscaleraRoundResult`; `Tournament::isEscalera(): bool`; связи `Tournament::escaleraPlayers()`, `escaleraRounds()`

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Unit/Services/EscaleraServiceTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Club;
use App\Models\EscaleraPlayer;
use App\Models\EscaleraRound;
use App\Models\EscaleraRoundCourt;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscaleraServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Турнир-эскалера на заданное число кортов (игроков = корты × 4). */
    private function makeTournament(int $courts = 3, string $mode = 'points', int $matchPoints = 12): Tournament
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);

        return Tournament::create([
            'club_id' => $club->id,
            'name' => 'Эскалера',
            'type' => 'escalera',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'courts_count' => $courts,
            'max_participants' => $courts * 4,
            'escalera_match_points' => $matchPoints,
            'escalera_rank_mode' => $mode,
        ]);
    }

    public function test_tournament_type_and_relations(): void
    {
        $t = $this->makeTournament();

        $this->assertTrue($t->isEscalera());
        $this->assertSame(12, $t->escalera_match_points);
        $this->assertSame('points', $t->escalera_rank_mode);

        $user = User::factory()->create(['rating' => 1500]);
        EscaleraPlayer::create([
            'tournament_id' => $t->id,
            'user_id' => $user->id,
            'start_court' => 1,
            'current_court' => 1,
        ]);

        $this->assertSame(1, $t->fresh()->escaleraPlayers->count());
        $this->assertSame(0, $t->fresh()->escaleraPlayers->first()->total_points);
    }

    public function test_round_court_holds_four_players_in_seating_order(): void
    {
        $t = $this->makeTournament();
        $round = EscaleraRound::create([
            'tournament_id' => $t->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);

        $players = User::factory()->count(4)->create();
        $court = EscaleraRoundCourt::create([
            'escalera_round_id' => $round->id,
            'court_number' => 1,
            'player1_id' => $players[0]->id,
            'player2_id' => $players[1]->id,
            'player3_id' => $players[2]->id,
            'player4_id' => $players[3]->id,
        ]);

        $this->assertSame($round->id, $court->fresh()->round->id);
        $this->assertSame($players[0]->id, $court->fresh()->player1_id);
        $this->assertSame(1, $t->fresh()->escaleraRounds->count());
    }
}
```

- [ ] **Step 2: Запустить тест и убедиться, что он падает**

Run: `php artisan test --filter=EscaleraServiceTest`
Expected: FAIL — типа `escalera` нет в enum, моделей не существует.

- [ ] **Step 3: Добавить тип в enum**

`database/migrations/2026_08_09_000001_add_escalera_to_tournaments_type_enum.php` — по образцу `2026_07_05_000002_add_just_padel_it_to_tournaments_type_enum.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic','americano','mexicano','team','king_of_court','bali_koc','americano_flex','round_robin','just_padel_it','escalera') NOT NULL DEFAULT 'classic'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic','americano','mexicano','team','king_of_court','bali_koc','americano_flex','round_robin','just_padel_it') NOT NULL DEFAULT 'classic'");
    }
};
```

Перед написанием сверить текущий список значений enum с последней миграцией, которая его меняла, — если после Just Padel It добавлялись ещё типы, перечислить их все, иначе миграция их сотрёт.

- [ ] **Step 4: Добавить поля параметров турнира**

`database/migrations/2026_08_09_000002_add_escalera_fields_to_tournaments.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Сколько очков разыгрывается в коротком матче (не «до», а ровно столько).
            $table->unsignedSmallInteger('escalera_match_points')->nullable();
            // Как ранжируется четвёрка внутри корта: по сумме очков или по победам.
            $table->enum('escalera_rank_mode', ['points', 'wins'])->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['escalera_match_points', 'escalera_rank_mode']);
        });
    }
};
```

- [ ] **Step 5: Создать таблицы формата**

`database/migrations/2026_08_09_000003_create_escalera_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Участник турнира. start_court нужен для награды «Восхождение»,
        // wins — для первого тай-брейка итоговой таблицы.
        Schema::create('escalera_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('total_points')->default(0);
            $table->unsignedSmallInteger('start_court')->nullable();
            $table->unsignedSmallInteger('current_court')->nullable();
            $table->integer('wins')->default(0);
            $table->integer('rating_before')->nullable();
            $table->integer('rating_after')->nullable();
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']);
        });

        Schema::create('escalera_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->integer('round_number');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamps();

            $table->unique(['tournament_id', 'round_number']);
        });

        // Корт в раунде. Порядок игроков — это посадка, от неё строится
        // очерёдность трёх матчей.
        Schema::create('escalera_round_courts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escalera_round_id')->constrained('escalera_rounds')->cascadeOnDelete();
            $table->unsignedSmallInteger('court_number');
            $table->foreignId('player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player2_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player3_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player4_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['escalera_round_id', 'court_number'], 'esc_round_court_unique');
        });

        // Короткий матч: три на корт за раунд.
        Schema::create('escalera_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escalera_round_court_id')->constrained('escalera_round_courts')->cascadeOnDelete();
            $table->unsignedTinyInteger('match_number'); // 1..3
            $table->foreignId('team1_player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team1_player2_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team2_player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team2_player2_id')->constrained('users')->cascadeOnDelete();
            $table->integer('team1_score')->nullable();
            $table->integer('team2_score')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->timestamps();

            $table->unique(['escalera_round_court_id', 'match_number'], 'esc_court_match_unique');
        });

        // Результат игрока за раунд: место на корте, позиция в общем строю, баллы.
        // Нужен для истории движения и для колонки «изменение позиции».
        Schema::create('escalera_round_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escalera_round_id')->constrained('escalera_rounds')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('court_number');
            $table->unsignedTinyInteger('place_on_court'); // 1..4
            $table->unsignedSmallInteger('overall_position');
            $table->integer('points');
            $table->timestamps();

            $table->unique(['escalera_round_id', 'user_id'], 'esc_round_result_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalera_round_results');
        Schema::dropIfExists('escalera_matches');
        Schema::dropIfExists('escalera_round_courts');
        Schema::dropIfExists('escalera_rounds');
        Schema::dropIfExists('escalera_players');
    }
};
```

- [ ] **Step 6: Создать модели**

Пять моделей в `app/Models/`, все с русскими докблоками. Образец стиля — `app/Models/JustPadelItRound.php` и соседние.

`EscaleraPlayer.php`: `$fillable` = `tournament_id`, `user_id`, `total_points`, `start_court`, `current_court`, `wins`, `rating_before`, `rating_after`; связи `tournament()`, `user()`.

`EscaleraRound.php`: `$fillable` = `tournament_id`, `round_number`, `status`; связи `tournament()`, `courts()` (hasMany `EscaleraRoundCourt`, сортировка по `court_number`), `results()`; метод `isCompleted(): bool`.

`EscaleraRoundCourt.php`: `$fillable` = `escalera_round_id`, `court_number`, `player1_id`…`player4_id`; связи `round()`, `matches()` (сортировка по `match_number`), `player1()`…`player4()`; метод `playerIds(): array` — четыре id в порядке посадки.

`EscaleraMatch.php`: `$fillable` = все поля счёта и игроков; связь `court()`; метод `isCompleted(): bool`.

`EscaleraRoundResult.php`: `$fillable` = `escalera_round_id`, `user_id`, `court_number`, `place_on_court`, `overall_position`, `points`; связи `round()`, `user()`.

- [ ] **Step 7: Подключить тип в `Tournament`**

В `app/Models/Tournament.php`:

- в массив подписей типов (около строки 434, где `'just_padel_it' => 'Just Padel It'`) добавить `'escalera' => 'Эскалера'`;
- рядом с `isJustPadelIt()` (строка 469) добавить:

```php
	public function isEscalera(): bool
	{
		return $this->type === 'escalera';
	}
```

- добавить в `$fillable` поля `escalera_match_points` и `escalera_rank_mode`;
- добавить связи:

```php
	public function escaleraPlayers()
	{
		return $this->hasMany(EscaleraPlayer::class);
	}

	public function escaleraRounds()
	{
		return $this->hasMany(EscaleraRound::class)->orderBy('round_number');
	}
```

- [ ] **Step 8: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=EscaleraServiceTest`
Expected: PASS, 2 теста.

- [ ] **Step 9: Коммит**

```bash
git add database/migrations/2026_08_09_00000*.php app/Models/Escalera*.php app/Models/Tournament.php tests/Unit/Services/EscaleraServiceTest.php
git commit -m "feat(escalera): структура данных формата"
```

---

### Task 2: Расчёты формата

**Files:**
- Create: `app/Services/EscaleraService.php`
- Test: `tests/Unit/Services/EscaleraServiceTest.php` (дополняется)

**Interfaces:**
- Consumes: модели из Task 1
- Produces:
  - `EscaleraService::rankCourt(EscaleraRoundCourt $court, string $mode): array` — id игроков в порядке мест с первого по четвёртое
  - `EscaleraService::positionFor(int $courtNumber, int $place): int`
  - `EscaleraService::pointsFor(int $position, int $totalPlayers): int`
  - `EscaleraService::planMovements(array $courtRankings): array` — новая раскладка игроков по кортам
  - `EscaleraService::matchLineup(array $seating): array` — три матча из посадки

- [ ] **Step 1: Написать падающие тесты**

Добавить в `tests/Unit/Services/EscaleraServiceTest.php`. Понадобится хелпер, создающий корт с четырьмя игроками и тремя матчами с заданными счетами:

```php
    /**
     * Корт с четырьмя игроками и тремя матчами.
     * $scores — три пары [очки команды 1, очки команды 2] по порядку матчей.
     *
     * @param  array<int, array{0:int,1:int}> $scores
     * @return array{0: EscaleraRoundCourt, 1: array<int, User>}
     */
    private function makeCourtWithScores(Tournament $t, array $scores, array $ratings = [1600, 1500, 1400, 1300]): array
    {
        $round = EscaleraRound::create([
            'tournament_id' => $t->id, 'round_number' => 1, 'status' => 'in_progress',
        ]);

        $players = [];
        foreach ($ratings as $rating) {
            $players[] = User::factory()->create(['rating' => $rating]);
        }

        $court = EscaleraRoundCourt::create([
            'escalera_round_id' => $round->id,
            'court_number' => 1,
            'player1_id' => $players[0]->id,
            'player2_id' => $players[1]->id,
            'player3_id' => $players[2]->id,
            'player4_id' => $players[3]->id,
        ]);

        // Пары по порядку матчей: 1+4 vs 2+3, 1+3 vs 2+4, 1+2 vs 3+4.
        $lineup = [
            [[0, 3], [1, 2]],
            [[0, 2], [1, 3]],
            [[0, 1], [2, 3]],
        ];

        foreach ($lineup as $i => [$teamA, $teamB]) {
            EscaleraMatch::create([
                'escalera_round_court_id' => $court->id,
                'match_number' => $i + 1,
                'team1_player1_id' => $players[$teamA[0]]->id,
                'team1_player2_id' => $players[$teamA[1]]->id,
                'team2_player1_id' => $players[$teamB[0]]->id,
                'team2_player2_id' => $players[$teamB[1]]->id,
                'team1_score' => $scores[$i][0],
                'team2_score' => $scores[$i][1],
                'status' => 'completed',
            ]);
        }

        return [$court->fresh(), $players];
    }

    public function test_position_formula(): void
    {
        $service = app(EscaleraService::class);

        // Первый на первом корте — первый в общем строю.
        $this->assertSame(1, $service->positionFor(1, 1));
        // Четвёртый на первом корте — четвёртый.
        $this->assertSame(4, $service->positionFor(1, 4));
        // Первый на втором корте — пятый.
        $this->assertSame(5, $service->positionFor(2, 1));
        // Третий на третьем корте — одиннадцатый.
        $this->assertSame(11, $service->positionFor(3, 3));
    }

    public function test_points_formula(): void
    {
        $service = app(EscaleraService::class);

        // При 12 игроках первая позиция стоит 12 баллов, последняя — 1.
        $this->assertSame(12, $service->pointsFor(1, 12));
        $this->assertSame(1, $service->pointsFor(12, 12));
        $this->assertSame(8, $service->pointsFor(5, 12));
        // При 16 игроках шкала другая.
        $this->assertSame(16, $service->pointsFor(1, 16));
    }

    public function test_rank_court_by_points(): void
    {
        $t = $this->makeTournament(mode: 'points');
        // Матч 1: (P1+P4) 7:5 (P2+P3); матч 2: (P1+P3) 8:4 (P2+P4); матч 3: (P1+P2) 6:6 (P3+P4).
        // Сумма очков: P1 = 7+8+6 = 21; P2 = 5+4+6 = 15; P3 = 5+8+6 = 19; P4 = 7+4+6 = 17.
        [$court, $players] = $this->makeCourtWithScores($t, [[7, 5], [8, 4], [6, 6]]);

        $order = app(EscaleraService::class)->rankCourt($court, 'points');

        $this->assertSame(
            [$players[0]->id, $players[2]->id, $players[3]->id, $players[1]->id],
            $order,
            'порядок по сумме очков: P1, P3, P4, P2'
        );
    }

    public function test_rank_court_by_wins_with_points_tiebreak(): void
    {
        $t = $this->makeTournament(mode: 'wins');
        // Матч 1: (P1+P4) 7:5 (P2+P3) — победа P1, P4
        // Матч 2: (P1+P3) 8:4 (P2+P4) — победа P1, P3
        // Матч 3: (P1+P2) 9:3 (P3+P4) — победа P1, P2
        // Победы: P1 = 3; P2 = 1; P3 = 1; P4 = 1.
        // Тай-брейк по очкам: P2 = 5+4+9 = 18; P3 = 5+8+3 = 16; P4 = 7+4+3 = 14.
        [$court, $players] = $this->makeCourtWithScores($t, [[7, 5], [8, 4], [9, 3]]);

        $order = app(EscaleraService::class)->rankCourt($court, 'wins');

        $this->assertSame(
            [$players[0]->id, $players[1]->id, $players[2]->id, $players[3]->id],
            $order,
            'P1 по победам, дальше по сумме очков'
        );
    }

    public function test_full_tie_resolved_by_rating(): void
    {
        $t = $this->makeTournament(mode: 'points');
        // Все три матча вничью — суммы очков у всех равны.
        // Порядок должен определиться рейтингом: 1600, 1500, 1400, 1300.
        [$court, $players] = $this->makeCourtWithScores($t, [[6, 6], [6, 6], [6, 6]]);

        $order = app(EscaleraService::class)->rankCourt($court, 'points');

        $this->assertSame(
            [$players[0]->id, $players[1]->id, $players[2]->id, $players[3]->id],
            $order,
            'при полном равенстве выше игрок с большим рейтингом'
        );
    }

    public function test_match_lineup_pairs_everyone_once(): void
    {
        $seating = [10, 20, 30, 40]; // id игроков в порядке посадки

        $lineup = app(EscaleraService::class)->matchLineup($seating);

        $this->assertSame([[10, 40], [20, 30]], $lineup[0], 'матч 1: 1+4 против 2+3');
        $this->assertSame([[10, 30], [20, 40]], $lineup[1], 'матч 2: 1+3 против 2+4');
        $this->assertSame([[10, 20], [30, 40]], $lineup[2], 'матч 3: 1+2 против 3+4');
    }

    public function test_movements_middle_court(): void
    {
        // Три корта, на каждом четвёрка в порядке мест с первого по четвёртое.
        $rankings = [
            1 => [101, 102, 103, 104],
            2 => [201, 202, 203, 204],
            3 => [301, 302, 303, 304],
        ];

        $next = app(EscaleraService::class)->planMovements($rankings);

        // Верхний корт: первые трое остаются, четвёртый вниз; снизу приходит первый со второго.
        $this->assertEqualsCanonicalizing([101, 102, 103, 201], $next[1]);
        // Средний: пришёл 104 сверху и 301 снизу, остались 202 и 203.
        $this->assertEqualsCanonicalizing([104, 202, 203, 301], $next[2]);
        // Нижний: пришёл 204 сверху, остались 302, 303, 304.
        $this->assertEqualsCanonicalizing([204, 302, 303, 304], $next[3]);
    }

    public function test_movements_two_courts(): void
    {
        // Минимально допустимая конфигурация: только верхний и нижний корт.
        $rankings = [
            1 => [101, 102, 103, 104],
            2 => [201, 202, 203, 204],
        ];

        $next = app(EscaleraService::class)->planMovements($rankings);

        $this->assertEqualsCanonicalizing([101, 102, 103, 201], $next[1]);
        $this->assertEqualsCanonicalizing([104, 202, 203, 204], $next[2]);
    }

    public function test_every_court_keeps_four_players(): void
    {
        $rankings = [
            1 => [101, 102, 103, 104],
            2 => [201, 202, 203, 204],
            3 => [301, 302, 303, 304],
            4 => [401, 402, 403, 404],
        ];

        $next = app(EscaleraService::class)->planMovements($rankings);

        foreach ($next as $courtNumber => $players) {
            $this->assertCount(4, $players, "на корте {$courtNumber} должно быть четверо");
        }
        // Никто не потерялся и не задвоился.
        $all = array_merge(...array_values($next));
        $this->assertCount(16, array_unique($all));
    }
```

Понадобятся импорты `use App\Models\EscaleraMatch;` и `use App\Services\EscaleraService;`.

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter=EscaleraServiceTest`
Expected: FAIL — класса `EscaleraService` не существует.

- [ ] **Step 3: Написать расчётную часть сервиса**

`app/Services/EscaleraService.php` — на этом шаге только чистые расчёты, без записи в базу:

```php
<?php

namespace App\Services;

use App\Models\EscaleraRoundCourt;

/**
 * Логика формата «Эскалера».
 *
 * Корты выстроены сверху вниз, на каждом четыре игрока и три коротких матча
 * за раунд. В общую таблицу идут не очки, а позиция игрока в общем строю всех
 * участников — номер корта уже встроен в это число, поэтому единственный
 * способ улучшить результат — подняться на корт выше.
 */
class EscaleraService
{
    /** Позиция в общем строю: корты упорядочены по силе, на каждом четверо. */
    public function positionFor(int $courtNumber, int $place): int
    {
        return ($courtNumber - 1) * 4 + $place;
    }

    /** Баллы за позицию: первый в строю получает столько, сколько всего игроков. */
    public function pointsFor(int $position, int $totalPlayers): int
    {
        return $totalPlayers - $position + 1;
    }

    /**
     * Три матча из посадки: каждый играет в паре с каждым по разу.
     * Первым идёт самый ровный матч — сильнейший с четвёртым.
     *
     * @param  array<int, int> $seating четыре id в порядке посадки
     * @return array<int, array{0: array<int,int>, 1: array<int,int>}>
     */
    public function matchLineup(array $seating): array
    {
        [$p1, $p2, $p3, $p4] = $seating;

        return [
            [[$p1, $p4], [$p2, $p3]],
            [[$p1, $p3], [$p2, $p4]],
            [[$p1, $p2], [$p3, $p4]],
        ];
    }

    /**
     * Ранжировать четвёрку на корте. Возвращает id игроков в порядке мест
     * с первого по четвёртое.
     *
     * Режим 'points' — по сумме очков за три матча.
     * Режим 'wins' — по числу побед, затем по очкам, затем по личной встрече.
     * Полное равенство решается рейтингом.
     *
     * @return array<int, int>
     */
    public function rankCourt(EscaleraRoundCourt $court, string $mode): array
    {
        $ids = $court->playerIds();
        $stats = [];
        foreach ($ids as $id) {
            $stats[$id] = ['points' => 0, 'wins' => 0, 'rating' => 0];
        }

        // Рейтинги нужны как последний разделитель при полном равенстве.
        $ratings = \App\Models\User::whereIn('id', $ids)->pluck('rating', 'id');
        foreach ($ids as $id) {
            $stats[$id]['rating'] = (int) ($ratings[$id] ?? 0);
        }

        foreach ($court->matches as $match) {
            $team1 = [$match->team1_player1_id, $match->team1_player2_id];
            $team2 = [$match->team2_player1_id, $match->team2_player2_id];
            $s1 = (int) $match->team1_score;
            $s2 = (int) $match->team2_score;

            foreach ($team1 as $id) {
                $stats[$id]['points'] += $s1;
                if ($s1 > $s2) $stats[$id]['wins']++;
            }
            foreach ($team2 as $id) {
                $stats[$id]['points'] += $s2;
                if ($s2 > $s1) $stats[$id]['wins']++;
            }
        }

        $order = $ids;
        usort($order, function ($a, $b) use ($stats, $mode, $court) {
            if ($mode === 'wins') {
                // Сначала победы, затем сумма очков.
                if ($stats[$a]['wins'] !== $stats[$b]['wins']) {
                    return $stats[$b]['wins'] <=> $stats[$a]['wins'];
                }
                if ($stats[$a]['points'] !== $stats[$b]['points']) {
                    return $stats[$b]['points'] <=> $stats[$a]['points'];
                }
                // Личная встреча: очки в тех матчах, где эти двое были соперниками.
                $h2h = $this->headToHead($court, $a, $b);
                if ($h2h !== 0) {
                    return $h2h;
                }
            } elseif ($stats[$a]['points'] !== $stats[$b]['points']) {
                return $stats[$b]['points'] <=> $stats[$a]['points'];
            }

            // Полное равенство — выше игрок с большим рейтингом.
            return $stats[$b]['rating'] <=> $stats[$a]['rating'];
        });

        return $order;
    }

    /**
     * Личная встреча: сравнение по сумме очков в матчах, где игроки были
     * соперниками. Возвращает результат сравнения для usort (0 — равенство).
     */
    private function headToHead(EscaleraRoundCourt $court, int $a, int $b): int
    {
        $scoreA = 0;
        $scoreB = 0;

        foreach ($court->matches as $match) {
            $team1 = [$match->team1_player1_id, $match->team1_player2_id];
            $team2 = [$match->team2_player1_id, $match->team2_player2_id];

            $aIn1 = in_array($a, $team1, true);
            $bIn1 = in_array($b, $team1, true);
            $aIn2 = in_array($a, $team2, true);
            $bIn2 = in_array($b, $team2, true);

            // Интересуют только матчи, где они по разные стороны сетки.
            if ($aIn1 && $bIn2) {
                $scoreA += (int) $match->team1_score;
                $scoreB += (int) $match->team2_score;
            } elseif ($aIn2 && $bIn1) {
                $scoreA += (int) $match->team2_score;
                $scoreB += (int) $match->team1_score;
            }
        }

        return $scoreB <=> $scoreA;
    }

    /**
     * Куда поедут игроки после раунда.
     * Первый на корте вверх, четвёртый вниз, двое средних остаются.
     * На верхнем корте вниз уходит только четвёртый, на нижнем вверх — только первый.
     *
     * @param  array<int, array<int,int>> $courtRankings корт => четвёрка по местам
     * @return array<int, array<int,int>> корт => состав на следующий раунд
     */
    public function planMovements(array $courtRankings): array
    {
        ksort($courtRankings);
        $courts = array_keys($courtRankings);
        $top = min($courts);
        $bottom = max($courts);

        $next = [];
        foreach ($courts as $court) {
            $next[$court] = [];
        }

        foreach ($courtRankings as $court => $places) {
            [$first, $second, $third, $fourth] = $places;

            // Первый идёт наверх, но с верхнего корта уходить некуда.
            if ($court === $top) {
                $next[$court][] = $first;
            } else {
                $next[$court - 1][] = $first;
            }

            // Двое средних всегда остаются.
            $next[$court][] = $second;
            $next[$court][] = $third;

            // Четвёртый идёт вниз, но с нижнего корта опускаться некуда.
            if ($court === $bottom) {
                $next[$court][] = $fourth;
            } else {
                $next[$court + 1][] = $fourth;
            }
        }

        // Целостность: после всех перемещений на каждом корте ровно четверо.
        foreach ($next as $court => $players) {
            if (count($players) !== 4) {
                throw new \RuntimeException(
                    "После перемещений на корте {$court} оказалось " . count($players) . " игроков вместо четырёх"
                );
            }
        }

        return $next;
    }
}
```

Проверка на бумаге для трёх кортов: корт 1 отдаёт четвёртого вниз и оставляет первого, второго, третьего — трое, плюс первый со второго корта, итого четверо. Корт 2 отдаёт первого наверх и четвёртого вниз, оставляет двоих, получает четвёртого с первого и первого с третьего — четверо. Корт 3 отдаёт первого наверх, оставляет троих, получает четвёртого со второго — четверо.

Исключение из `planMovements` вызывающий код ловит и превращает в ошибку интерфейса: раунд не закрывается.

- [ ] **Step 4: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=EscaleraServiceTest`
Expected: PASS, 10 тестов.

- [ ] **Step 5: Коммит**

```bash
git add app/Services/EscaleraService.php tests/Unit/Services/EscaleraServiceTest.php
git commit -m "feat(escalera): расчёты формата — ранжирование, позиции, перемещения"
```

---

### Task 3: Проведение турнира

**Files:**
- Modify: `app/Services/EscaleraService.php`
- Test: `tests/Feature/EscaleraFlowTest.php`

**Interfaces:**
- Consumes: расчёты из Task 2
- Produces:
  - `startTournament(Tournament $tournament): bool`
  - `saveMatchResult(EscaleraMatch $match, int $team1Score, int $team2Score): void`
  - `canCloseRound(Tournament $tournament): bool`
  - `previewRoundClose(Tournament $tournament): array`
  - `closeRound(Tournament $tournament): bool`
  - `generateNextRound(Tournament $tournament): bool`
  - `finishTournament(Tournament $tournament): bool`
  - `standings(Tournament $tournament): array`
  - `awards(Tournament $tournament): array`

- [ ] **Step 1: Написать падающие тесты**

Создать `tests/Feature/EscaleraFlowTest.php` — сквозной прогон. Тесты:

- старт заблокирован, когда участников не `корты × 4`: 11 игроков при трёх кортах — `startTournament` возвращает `false`, раундов не создано;
- старт при верном числе: создан раунд 1, кортов ровно `courts_count`, на каждом три матча, посадка первого корта — четыре сильнейших по рейтингу;
- `canCloseRound` возвращает `false`, пока хоть один счёт не внесён, и `true` после всех;
- `closeRound` пишет результаты: у каждого игрока есть `overall_position` и `points`, сумма баллов в `escalera_players` обновлена;
- `generateNextRound` создаёт раунд 2, где составы кортов соответствуют перемещениям, и `current_court` игроков обновлён;
- `finishTournament` считает три награды и начисляет рейтинг: у всех игроков `rating_after` не пуст, изменения рейтинга записаны в историю;
- правка счёта после закрытия раунда пересчитывает баллы, но не двигает игроков между кортами.

Для наград подготовить данные так, чтобы победители были однозначны: чемпион — с наибольшей суммой баллов, «Восхождение» — игрок, поднявшийся с нижнего корта выше всех, «Король корта» — победитель последнего раунда на первом корте.

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter=EscaleraFlowTest`
Expected: FAIL — методов проведения не существует.

- [ ] **Step 3: Реализовать старт и раунды**

Дописать в `EscaleraService`:

- `startTournament` — проверить, что зарегистрированных ровно `courts_count × 4`; создать `escalera_players` со `start_court` и `current_court`; отсортировать по рейтингу (игроки без рейтинга ниже всех); разложить по кортам сверху вниз; создать раунд 1 с кортами и тремя матчами на каждом через `matchLineup`; перевести турнир в `in_progress`.
- `saveMatchResult` — проверить, что сумма очков равна `escalera_match_points`, иначе бросить исключение с русским сообщением; записать счёт и статус.
- `canCloseRound` — все матчи текущего раунда завершены.
- `previewRoundClose` — вернуть по каждому корту места и стрелки перемещений, ничего не записывая.
- `closeRound` — посчитать места, позиции и баллы, записать `escalera_round_results`, обновить `total_points` и `wins` в `escalera_players`, применить перемещения к `current_court`, закрыть раунд.
- `generateNextRound` — создать следующий раунд из `current_court` игроков; посадка внутри корта по текущему месту в общей таблице.
- `standings` — таблица: сумма баллов, текущий корт, изменение позиции с прошлого раунда; тай-брейки по порядку из спеки.

- [ ] **Step 4: Реализовать завершение и награды**

- `awards` — три награды по правилам спеки.
- `finishTournament` — начислить рейтинг по каждому короткому матчу существующей парной формулой из трейта `RatingCalculator` (тем же способом, что в других форматах: накопить изменения по всем матчам, затем применить к игрокам и записать в `RatingHistory`); перевести турнир в `completed`.

Начисление рейтинга должно быть идемпотентно в том смысле, что повторный вызов на уже завершённом турнире не должен начислять второй раз — сверить с тем, как это решено в других сервисах, и повторить тот же приём.

- [ ] **Step 5: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter="EscaleraFlowTest|EscaleraServiceTest"`
Expected: PASS.

- [ ] **Step 6: Коммит**

```bash
git add app/Services/EscaleraService.php tests/Feature/EscaleraFlowTest.php
git commit -m "feat(escalera): проведение турнира — старт, раунды, награды, рейтинг"
```

---

### Task 4: Веб-интерфейс

**Files:**
- Create: `app/Http/Controllers/Club/EscaleraController.php`
- Modify: `routes/web.php` (рядом с маршрутами Just Padel It, около строки 494)
- Create: `resources/views/club/tournaments/escalera/seeding.blade.php`
- Create: `resources/views/club/tournaments/escalera/show.blade.php`
- Create: `resources/views/club/tournaments/escalera/partials/_courts.blade.php`, `_standings.blade.php`, `_awards.blade.php`
- Modify: `resources/views/club/tournaments/create.blade.php`
- Modify: `app/Http/Controllers/Club/TournamentController.php` (валидация параметров, ветка показа и старта)

**Interfaces:**
- Consumes: весь `EscaleraService`
- Produces: маршруты `club.escalera.seeding`, `club.escalera.start`, `club.escalera.saveScore`, `club.escalera.closeRound`, `club.escalera.nextRound`, `club.escalera.finish`

- [ ] **Step 1: Написать падающие тесты**

Добавить в `tests/Feature/EscaleraFlowTest.php` проверки через HTTP:

- страница посева открывается администратором клуба и показывает игроков по кортам;
- старт через маршрут создаёт раунд и редиректит на турнир;
- сохранение счёта с неверной суммой очков возвращает ошибку валидации;
- закрытие раунда недоступно, пока не все счета внесены;
- страница турнира показывает карточки кортов и таблицу;
- завершение турнира показывает награды.

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter=EscaleraFlowTest`
Expected: FAIL — маршрутов не существует.

- [ ] **Step 3: Добавить параметры в форму создания**

В `resources/views/club/tournaments/create.blade.php` добавить блок эскалеры по образцу блока Just Padel It: количество кортов (от 2 до 10), очков в коротком матче (8, 10, 12, 16 — по умолчанию 12), режим ранжирования (по очкам / по победам). Блок показывается только при выборе типа «Эскалера».

Число участников проставляется автоматически как корты × 4 — при смене числа кортов поле участников пересчитывается и остаётся недоступным для правки.

В `Club\TournamentController` добавить валидацию новых полей и сохранение; для типа `escalera` принудительно выставлять `max_participants = courts_count * 4`.

- [ ] **Step 4: Написать контроллер и маршруты**

`app/Http/Controllers/Club/EscaleraController.php` — по образцу `JustPadelItController`: получение клуба, проверка принадлежности турнира клубу, вызовы сервиса, редиректы с сообщениями на русском.

Маршруты в `routes/web.php` рядом с блоком Just Padel It (около строки 494), тем же стилем:

```php
            Route::get('/escalera/{tournament}/seeding', [EscaleraController::class, 'seeding'])
                ->name('escalera.seeding');
            Route::post('/escalera/{tournament}/seeding', [EscaleraController::class, 'saveSeeding'])
                ->name('escalera.saveSeeding');
            Route::post('/escalera/{tournament}/start', [EscaleraController::class, 'start'])
                ->name('escalera.start');
            Route::post('/escalera/match/{match}/score', [EscaleraController::class, 'saveScore'])
                ->name('escalera.saveScore');
            Route::post('/escalera/{tournament}/close-round', [EscaleraController::class, 'closeRound'])
                ->name('escalera.closeRound');
            Route::post('/escalera/{tournament}/next-round', [EscaleraController::class, 'nextRound'])
                ->name('escalera.nextRound');
            Route::post('/escalera/{tournament}/finish', [EscaleraController::class, 'finish'])
                ->name('escalera.finish');
```

В `Club\TournamentController` добавить ветки для эскалеры: показ турнира (строка 242, где разбор по типам) и переход к посеву при старте (строка 490).

- [ ] **Step 5: Написать вьюхи**

Стиль брать из `resources/views/club/tournaments/justpadelit/` — там уже есть посев и экран турнира с раундами. Цвета только из переменных темы, никакого хардкода: в проекте есть светлая тема.

`seeding.blade.php`: игроки по кортам сверху вниз, возможность поменять двух местами, кнопка старта. Пока участников не `корты × 4`, кнопка заблокирована и показано расхождение.

`show.blade.php`: карточки кортов сверху вниз, на каждой четыре имени и три матча с полями счёта; цвет карточки по заполненности; под кортами таблица; кнопки закрытия раунда, следующего раунда и завершения турнира; после завершения — награды.

Пользовательские данные в JavaScript передавать только через `@js(...)`, в DOM — через `textContent`.

- [ ] **Step 6: Запустить тесты**

Run: `php artisan test --filter="EscaleraFlowTest|EscaleraServiceTest"`
Expected: PASS.

- [ ] **Step 7: Прогнать смежные сьюты**

Run: `php artisan test --filter="Tournament|JustPadelIt|KingOfCourt"`
Expected: новых падений нет. Помнить про известные давние падения.

- [ ] **Step 8: Коммит**

```bash
git add app/Http/Controllers/Club/EscaleraController.php routes/web.php resources/views/club/tournaments/escalera app/Http/Controllers/Club/TournamentController.php resources/views/club/tournaments/create.blade.php tests/Feature/EscaleraFlowTest.php
git commit -m "feat(escalera): веб-интерфейс проведения турнира"
```

---

## Деплой на прод

```bash
git pull
php artisan migrate --path=database/migrations/2026_08_09_000001_add_escalera_to_tournaments_type_enum.php
php artisan migrate --path=database/migrations/2026_08_09_000002_add_escalera_fields_to_tournaments.php
php artisan migrate --path=database/migrations/2026_08_09_000003_create_escalera_tables.php
php artisan route:clear && php artisan view:clear && php artisan config:clear
```

Первая миграция меняет enum существующей таблицы `tournaments` — перед применением убедиться, что в ней перечислены **все** текущие типы, иначе значения, которых нет в списке, будут потеряны. Остальные две только добавляют колонки и создают новые таблицы.

`npm run build` не нужен — собираемые ассеты не меняются.

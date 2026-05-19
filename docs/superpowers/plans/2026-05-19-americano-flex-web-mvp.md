# Americano Flex — Web MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Реализовать новый тип турнира `americano_flex` (адаптивная очередь игроков на M кортах) для веб-админки клуба. Только запуск, ведение, управление счётом, лидерборд и расчёт ELO. **Без мобильного API.**

**Architecture:** Новый сервис `AmericanoFlexService`, 5 новых таблиц БД, отдельные модели, blade-партиал для UI ведения турнира. Алгоритм пар берётся из логики Mexicano (`mexicano_pair_history` style, изолированная копия). Управление раундами — модель «Король Корта» (админ сам нажимает «Следующий раунд» / «Завершить турнир»).

**Tech Stack:** Laravel 12, MySQL/SQLite, Blade, Tailwind, Alpine.js, PHPUnit.

**Reference spec:** `docs/superpowers/specs/2026-05-19-americano-flex-design.md`

---

## File Structure

### Создаётся

```
database/migrations/
├── 2026_05_19_000001_add_americano_flex_to_tournaments_type.php
├── 2026_05_19_000002_create_americano_flex_tables.php

app/Models/
├── AmericanoFlexRound.php
├── AmericanoFlexMatch.php
├── AmericanoFlexBye.php
├── AmericanoFlexPlayer.php
├── AmericanoFlexPairHistory.php

app/Services/
└── AmericanoFlexService.php

app/Http/Controllers/Club/
└── AmericanoFlexController.php

resources/views/club/tournaments/partials/
└── _americano_flex.blade.php

tests/Unit/Services/
└── AmericanoFlexServiceTest.php
```

### Модифицируется

```
routes/web.php                                          (новые маршруты)
app/Models/Tournament.php                               (связи, isAmericanoFlex())
app/Http/Controllers/Club/TournamentController.php      (поддержка нового типа в start/show)
resources/views/club/tournaments/create.blade.php       (опция в селекторе типа)
resources/views/club/tournaments/show.blade.php         (routing к новому партиалу)
```

---

## Phase 1: База данных и модели

### Task 1.1: Миграция — добавить тип в enum tournaments.type

**Files:**
- Create: `database/migrations/2026_05_19_000001_add_americano_flex_to_tournaments_type.php`

- [ ] **Step 1.1.1: Создать файл миграции**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite не поддерживает ALTER ENUM, поэтому проверяем драйвер
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic','americano','mexicano','team','king_of_court','bali_koc','americano_flex') NOT NULL DEFAULT 'classic'");
        }
        // На SQLite enum — это просто string-колонка, ничего делать не нужно
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic','americano','mexicano','team','king_of_court','bali_koc') NOT NULL DEFAULT 'classic'");
        }
    }
};
```

- [ ] **Step 1.1.2: Запустить миграцию локально**

Run: `php artisan migrate --path=database/migrations/2026_05_19_000001_add_americano_flex_to_tournaments_type.php`
Expected: `Migrated: 2026_05_19_000001_add_americano_flex_to_tournaments_type`

- [ ] **Step 1.1.3: Commit**

```bash
git add database/migrations/2026_05_19_000001_add_americano_flex_to_tournaments_type.php
git commit -m "feat(americano-flex): добавить тип americano_flex в enum tournaments.type"
```

### Task 1.2: Миграция — таблицы данных режима

**Files:**
- Create: `database/migrations/2026_05_19_000002_create_americano_flex_tables.php`

- [ ] **Step 1.2.1: Создать файл миграции с 5 таблицами**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('americano_flex_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->integer('round_number');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('in_progress');
            $table->timestamps();
            $table->index(['tournament_id', 'round_number']);
        });

        Schema::create('americano_flex_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('americano_flex_round_id')->constrained()->cascadeOnDelete();
            $table->integer('court_number');
            $table->foreignId('team1_player1_id')->constrained('users');
            $table->foreignId('team1_player2_id')->constrained('users');
            $table->foreignId('team2_player1_id')->constrained('users');
            $table->foreignId('team2_player2_id')->constrained('users');
            $table->integer('team1_score')->nullable();
            $table->integer('team2_score')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->timestamps();
            $table->index('americano_flex_round_id');
        });

        Schema::create('americano_flex_byes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('americano_flex_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
            $table->unique(['americano_flex_round_id', 'user_id']);
        });

        Schema::create('americano_flex_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->integer('total_points')->default(0);
            $table->integer('matches_played')->default(0);
            $table->integer('bye_count')->default(0);
            $table->integer('bye_streak')->default(0);
            $table->integer('rating_before')->nullable();
            $table->integer('rating_after')->nullable();
            $table->timestamps();
            $table->unique(['tournament_id', 'user_id']);
        });

        Schema::create('americano_flex_pair_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player1_id')->constrained('users');
            $table->foreignId('player2_id')->constrained('users');
            $table->integer('times_as_partners')->default(0);
            $table->integer('times_as_opponents')->default(0);
            $table->timestamps();
            $table->unique(['tournament_id', 'player1_id', 'player2_id'], 'flex_pair_history_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('americano_flex_pair_history');
        Schema::dropIfExists('americano_flex_players');
        Schema::dropIfExists('americano_flex_byes');
        Schema::dropIfExists('americano_flex_matches');
        Schema::dropIfExists('americano_flex_rounds');
    }
};
```

- [ ] **Step 1.2.2: Запустить миграцию**

Run: `php artisan migrate --path=database/migrations/2026_05_19_000002_create_americano_flex_tables.php`
Expected: 5 таблиц создано без ошибок.

- [ ] **Step 1.2.3: Commit**

```bash
git add database/migrations/2026_05_19_000002_create_americano_flex_tables.php
git commit -m "feat(americano-flex): таблицы rounds/matches/byes/players/pair_history"
```

### Task 1.3: Модели

**Files:**
- Create: `app/Models/AmericanoFlexRound.php`
- Create: `app/Models/AmericanoFlexMatch.php`
- Create: `app/Models/AmericanoFlexBye.php`
- Create: `app/Models/AmericanoFlexPlayer.php`
- Create: `app/Models/AmericanoFlexPairHistory.php`
- Modify: `app/Models/Tournament.php`

- [ ] **Step 1.3.1: Создать AmericanoFlexRound**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmericanoFlexRound extends Model
{
    protected $fillable = ['tournament_id', 'round_number', 'status'];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function matches()
    {
        return $this->hasMany(AmericanoFlexMatch::class);
    }

    public function byes()
    {
        return $this->hasMany(AmericanoFlexBye::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
```

- [ ] **Step 1.3.2: Создать AmericanoFlexMatch**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmericanoFlexMatch extends Model
{
    protected $fillable = [
        'americano_flex_round_id', 'court_number',
        'team1_player1_id', 'team1_player2_id', 'team2_player1_id', 'team2_player2_id',
        'team1_score', 'team2_score', 'status',
    ];

    public function round()
    {
        return $this->belongsTo(AmericanoFlexRound::class, 'americano_flex_round_id');
    }

    public function team1Player1() { return $this->belongsTo(User::class, 'team1_player1_id'); }
    public function team1Player2() { return $this->belongsTo(User::class, 'team1_player2_id'); }
    public function team2Player1() { return $this->belongsTo(User::class, 'team2_player1_id'); }
    public function team2Player2() { return $this->belongsTo(User::class, 'team2_player2_id'); }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
```

- [ ] **Step 1.3.3: Создать AmericanoFlexBye**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmericanoFlexBye extends Model
{
    protected $fillable = ['americano_flex_round_id', 'user_id'];

    public function round()
    {
        return $this->belongsTo(AmericanoFlexRound::class, 'americano_flex_round_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 1.3.4: Создать AmericanoFlexPlayer**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmericanoFlexPlayer extends Model
{
    protected $fillable = [
        'tournament_id', 'user_id',
        'total_points', 'matches_played', 'bye_count', 'bye_streak',
        'rating_before', 'rating_after',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Среднее очков на матч (для лидерборда). */
    public function getAverageScoreAttribute(): float
    {
        return $this->matches_played > 0
            ? round($this->total_points / $this->matches_played, 2)
            : 0.0;
    }
}
```

- [ ] **Step 1.3.5: Создать AmericanoFlexPairHistory**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmericanoFlexPairHistory extends Model
{
    protected $table = 'americano_flex_pair_history';

    protected $fillable = [
        'tournament_id', 'player1_id', 'player2_id',
        'times_as_partners', 'times_as_opponents',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    /** Нормализованная пара ключей (меньший id первым). */
    public static function normalizeIds(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }
}
```

- [ ] **Step 1.3.6: Добавить связи и хелпер в Tournament**

Найти в `app/Models/Tournament.php` место рядом с `playoffMatches()` и добавить:

```php
public function americanoFlexRounds()
{
    return $this->hasMany(AmericanoFlexRound::class);
}

public function americanoFlexPlayers()
{
    return $this->hasMany(AmericanoFlexPlayer::class);
}

public function isAmericanoFlex(): bool
{
    return $this->type === 'americano_flex';
}
```

- [ ] **Step 1.3.7: Lint модели**

Run: `php -l app/Models/AmericanoFlexRound.php app/Models/AmericanoFlexMatch.php app/Models/AmericanoFlexBye.php app/Models/AmericanoFlexPlayer.php app/Models/AmericanoFlexPairHistory.php app/Models/Tournament.php`
Expected: `No syntax errors detected` для каждого.

- [ ] **Step 1.3.8: Commit**

```bash
git add app/Models/AmericanoFlex*.php app/Models/Tournament.php
git commit -m "feat(americano-flex): модели Round/Match/Bye/Player/PairHistory + связи в Tournament"
```

---

## Phase 2: AmericanoFlexService — алгоритм

### Task 2.1: Скелет сервиса и публичный API

**Files:**
- Create: `app/Services/AmericanoFlexService.php`

- [ ] **Step 2.1.1: Создать сервис со скелетом методов**

```php
<?php

namespace App\Services;

use App\Models\AmericanoFlexBye;
use App\Models\AmericanoFlexMatch;
use App\Models\AmericanoFlexPairHistory;
use App\Models\AmericanoFlexPlayer;
use App\Models\AmericanoFlexRound;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Traits\RatingCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AmericanoFlexService
{
    use RatingCalculator;

    /**
     * Запустить турнир: создать AmericanoFlexPlayer для каждого участника,
     * сгенерировать первый раунд.
     */
    public function startTournament(Tournament $tournament): void
    {
        DB::transaction(function () use ($tournament) {
            $participants = TournamentParticipant::where('tournament_id', $tournament->id)
                ->where('status', 'registered')
                ->with('user')
                ->get();

            foreach ($participants as $p) {
                AmericanoFlexPlayer::firstOrCreate(
                    ['tournament_id' => $tournament->id, 'user_id' => $p->user_id],
                    [
                        'rating_before' => $p->user->rating,
                        'total_points' => 0,
                        'matches_played' => 0,
                        'bye_count' => 0,
                        'bye_streak' => 0,
                    ]
                );
            }

            $tournament->update(['status' => 'in_progress']);
            $this->generateNextRound($tournament);
        });
    }

    /**
     * Сгенерировать следующий раунд.
     */
    public function generateNextRound(Tournament $tournament): AmericanoFlexRound
    {
        // реализовано в Task 2.3
        throw new \RuntimeException('not implemented');
    }

    /**
     * Сохранить счёт матча, обновить points/matches_played игроков и pair_history.
     */
    public function saveMatchResult(AmericanoFlexMatch $match, int $score1, int $score2): void
    {
        // реализовано в Task 2.4
        throw new \RuntimeException('not implemented');
    }

    /**
     * Текущий открытый раунд (последний по round_number).
     */
    public function getCurrentRound(Tournament $tournament): ?AmericanoFlexRound
    {
        return $tournament->americanoFlexRounds()
            ->orderByDesc('round_number')
            ->first();
    }

    /**
     * Все матчи раунда завершены?
     */
    public function isRoundCompleted(AmericanoFlexRound $round): bool
    {
        return $round->matches()->where('status', '!=', 'completed')->count() === 0;
    }

    /**
     * Завершить турнир: посчитать ELO для всех игроков, выставить статус.
     */
    public function completeTournament(Tournament $tournament): void
    {
        // реализовано в Task 2.5
        throw new \RuntimeException('not implemented');
    }

    /**
     * Лидерборд: коллекция AmericanoFlexPlayer, сортировка по среднему DESC.
     */
    public function getLeaderboard(Tournament $tournament): Collection
    {
        return $tournament->americanoFlexPlayers()
            ->with('user')
            ->get()
            ->sortByDesc(function ($p) {
                return $p->matches_played > 0
                    ? $p->total_points / $p->matches_played
                    : 0;
            })
            ->values();
    }
}
```

- [ ] **Step 2.1.2: Lint**

Run: `php -l app/Services/AmericanoFlexService.php`
Expected: `No syntax errors detected`.

- [ ] **Step 2.1.3: Commit**

```bash
git add app/Services/AmericanoFlexService.php
git commit -m "feat(americano-flex): скелет AmericanoFlexService и startTournament"
```

### Task 2.2: Тест-фикстура для сервиса

**Files:**
- Create: `tests/Unit/Services/AmericanoFlexServiceTest.php`

- [ ] **Step 2.2.1: Создать заготовку теста с фабрикой 10 игроков**

```php
<?php

namespace Tests\Unit\Services;

use App\Models\AmericanoFlexPlayer;
use App\Models\AmericanoFlexRound;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\AmericanoFlexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmericanoFlexServiceTest extends TestCase
{
    use RefreshDatabase;

    private AmericanoFlexService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AmericanoFlexService();
    }

    /** 10 игроков, 2 корта (courts_count=2). */
    private function makeTournament(int $playersCount = 10, int $courtsCount = 2): Tournament
    {
        $tournament = Tournament::factory()->create([
            'type' => 'americano_flex',
            'status' => 'open',
            'max_participants' => $playersCount,
            'courts_count' => $courtsCount,
        ]);

        for ($i = 1; $i <= $playersCount; $i++) {
            $user = User::factory()->create(['rating' => 1500]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
        }

        return $tournament;
    }

    public function test_start_tournament_creates_players(): void
    {
        $tournament = $this->makeTournament();
        $this->service->startTournament($tournament);

        $this->assertEquals('in_progress', $tournament->fresh()->status);
        $this->assertEquals(10, AmericanoFlexPlayer::where('tournament_id', $tournament->id)->count());
    }
}
```

- [ ] **Step 2.2.2: Запустить тест — должен УПАСТЬ (т.к. generateNextRound бросает)**

Run: `php artisan test --filter=AmericanoFlexServiceTest::test_start_tournament_creates_players`
Expected: FAIL с `RuntimeException: not implemented`.

> Это ожидаемо — мы пишем тест **до** реализации generateNextRound. Подтверждает правильность направления.

- [ ] **Step 2.2.3: Commit**

```bash
git add tests/Unit/Services/AmericanoFlexServiceTest.php
git commit -m "test(americano-flex): фикстура с 10 игроками + проверка startTournament"
```

### Task 2.3: Реализация generateNextRound

**Files:**
- Modify: `app/Services/AmericanoFlexService.php`

- [ ] **Step 2.3.1: Реализовать selectPlayersForRound (приватный)**

В `AmericanoFlexService.php` добавить:

```php
/**
 * Выбрать M*4 игроков для нового раунда.
 * Приоритеты: bye_streak DESC → matches_played ASC → рандом.
 */
private function selectPlayersForRound(Tournament $tournament): array
{
    $needed = $tournament->courts_count * 4;
    $players = $tournament->americanoFlexPlayers()
        ->with('user')
        ->get()
        ->shuffle()  // рандом для tie-breaker
        ->sortBy([
            ['bye_streak', 'desc'],
            ['matches_played', 'asc'],
        ])
        ->values();

    if ($players->count() <= $needed) {
        return $players->all();  // все играют, никто не отдыхает
    }

    return $players->take($needed)->all();
}
```

- [ ] **Step 2.3.2: Реализовать generatePairsForRound (приватный)**

В `AmericanoFlexService.php` добавить:

```php
/**
 * Сформировать M матчей из массива M*4 AmericanoFlexPlayer.
 * Минимизирует times_as_partners + times_as_opponents через pair_history.
 * Возвращает массив матчей: [['team1' => [id1, id2], 'team2' => [id3, id4], 'court' => 1], ...]
 */
private function generatePairsForRound(Tournament $tournament, array $players): array
{
    $playerIds = array_map(fn($p) => $p->user_id, $players);
    $history = AmericanoFlexPairHistory::where('tournament_id', $tournament->id)
        ->whereIn('player1_id', $playerIds)
        ->whereIn('player2_id', $playerIds)
        ->get()
        ->keyBy(fn($h) => $h->player1_id . '-' . $h->player2_id);

    $cost = function (int $a, int $b) use ($history) {
        [$lo, $hi] = AmericanoFlexPairHistory::normalizeIds($a, $b);
        $key = "{$lo}-{$hi}";
        $row = $history[$key] ?? null;
        return $row ? ($row->times_as_partners + $row->times_as_opponents) : 0;
    };

    $matches = [];
    $remaining = $playerIds;
    $courtNum = 1;

    while (count($remaining) >= 4) {
        // Перебираем все возможные комбинации первой четвёрки, выбираем минимум cost
        $bestMatch = null;
        $bestCost = PHP_INT_MAX;

        for ($i = 0; $i < count($remaining); $i++) {
            for ($j = $i + 1; $j < count($remaining); $j++) {
                for ($k = $j + 1; $k < count($remaining); $k++) {
                    for ($l = $k + 1; $l < count($remaining); $l++) {
                        $A = $remaining[$i]; $B = $remaining[$j];
                        $C = $remaining[$k]; $D = $remaining[$l];

                        // 3 варианта разделения 4 игроков на 2 команды
                        $variants = [
                            ['t1' => [$A, $B], 't2' => [$C, $D]],
                            ['t1' => [$A, $C], 't2' => [$B, $D]],
                            ['t1' => [$A, $D], 't2' => [$B, $C]],
                        ];

                        foreach ($variants as $v) {
                            $matchCost =
                                $cost($v['t1'][0], $v['t1'][1]) +
                                $cost($v['t2'][0], $v['t2'][1]) +
                                $cost($v['t1'][0], $v['t2'][0]) +
                                $cost($v['t1'][0], $v['t2'][1]) +
                                $cost($v['t1'][1], $v['t2'][0]) +
                                $cost($v['t1'][1], $v['t2'][1]);

                            if ($matchCost < $bestCost) {
                                $bestCost = $matchCost;
                                $bestMatch = ['indices' => [$i, $j, $k, $l], 'teams' => $v];
                            }
                        }
                    }
                }
            }
        }

        if (!$bestMatch) break;

        $matches[] = [
            'team1' => $bestMatch['teams']['t1'],
            'team2' => $bestMatch['teams']['t2'],
            'court' => $courtNum++,
        ];

        // Удаляем использованных игроков
        rsort($bestMatch['indices']);
        foreach ($bestMatch['indices'] as $idx) {
            array_splice($remaining, $idx, 1);
        }
    }

    return $matches;
}
```

> **Сложность:** O(N^4 × 3) на матч, для N=8 это 8*7*6*5/24*3 ≈ 210 операций — мгновенно. Для N=16 ≈ 1820*3 = 5460. Всё ещё ОК.

- [ ] **Step 2.3.3: Реализовать generateNextRound**

Заменить заглушку в `AmericanoFlexService.php`:

```php
public function generateNextRound(Tournament $tournament): AmericanoFlexRound
{
    return DB::transaction(function () use ($tournament) {
        $lastRound = $this->getCurrentRound($tournament);
        $nextNumber = $lastRound ? $lastRound->round_number + 1 : 1;

        // 1. Выбираем играющих
        $playing = $this->selectPlayersForRound($tournament);
        $playingIds = array_map(fn($p) => $p->user_id, $playing);

        // 2. Остальные — отдыхают
        $allPlayers = $tournament->americanoFlexPlayers()->get();
        $resting = $allPlayers->whereNotIn('user_id', $playingIds);

        // 3. Создаём раунд
        $round = AmericanoFlexRound::create([
            'tournament_id' => $tournament->id,
            'round_number' => $nextNumber,
            'status' => 'in_progress',
        ]);

        // 4. Формируем пары и создаём матчи
        $matches = $this->generatePairsForRound($tournament, $playing);
        foreach ($matches as $m) {
            AmericanoFlexMatch::create([
                'americano_flex_round_id' => $round->id,
                'court_number' => $m['court'],
                'team1_player1_id' => $m['team1'][0],
                'team1_player2_id' => $m['team1'][1],
                'team2_player1_id' => $m['team2'][0],
                'team2_player2_id' => $m['team2'][1],
                'status' => 'pending',
            ]);
        }

        // 5. Записываем отдыхающих
        foreach ($resting as $r) {
            AmericanoFlexBye::create([
                'americano_flex_round_id' => $round->id,
                'user_id' => $r->user_id,
            ]);
        }

        // 6. Обновляем bye_streak
        AmericanoFlexPlayer::where('tournament_id', $tournament->id)
            ->whereIn('user_id', $playingIds)
            ->update(['bye_streak' => 0]);
        AmericanoFlexPlayer::where('tournament_id', $tournament->id)
            ->whereNotIn('user_id', $playingIds)
            ->increment('bye_streak');
        AmericanoFlexPlayer::where('tournament_id', $tournament->id)
            ->whereNotIn('user_id', $playingIds)
            ->increment('bye_count');

        return $round;
    });
}
```

- [ ] **Step 2.3.4: Запустить тест и убедиться что startTournament проходит**

Run: `php artisan test --filter=AmericanoFlexServiceTest::test_start_tournament_creates_players`
Expected: PASS.

- [ ] **Step 2.3.5: Добавить тесты на генерацию первого раунда**

В `tests/Unit/Services/AmericanoFlexServiceTest.php` добавить:

```php
public function test_first_round_has_correct_matches_and_byes(): void
{
    $tournament = $this->makeTournament(10, 2);
    $this->service->startTournament($tournament);

    $round = $tournament->americanoFlexRounds()->first();
    $this->assertNotNull($round);
    $this->assertEquals(1, $round->round_number);
    $this->assertEquals(2, $round->matches()->count(), 'на 2 кортах 2 матча');
    $this->assertEquals(2, $round->byes()->count(), '10 - 8 = 2 отдыхают');
}

public function test_bye_streak_increments_for_resting_players(): void
{
    $tournament = $this->makeTournament(10, 2);
    $this->service->startTournament($tournament);

    $restingIds = $tournament->americanoFlexRounds()->first()->byes()->pluck('user_id');
    $resting = AmericanoFlexPlayer::where('tournament_id', $tournament->id)
        ->whereIn('user_id', $restingIds)
        ->get();
    foreach ($resting as $r) {
        $this->assertEquals(1, $r->bye_streak);
        $this->assertEquals(1, $r->bye_count);
    }
}
```

- [ ] **Step 2.3.6: Запустить тесты**

Run: `php artisan test --filter=AmericanoFlexServiceTest`
Expected: 3 теста PASS.

- [ ] **Step 2.3.7: Commit**

```bash
git add app/Services/AmericanoFlexService.php tests/Unit/Services/AmericanoFlexServiceTest.php
git commit -m "feat(americano-flex): generateNextRound с приоритетами + Mexicano-pairs алгоритм"
```

### Task 2.4: Реализация saveMatchResult

**Files:**
- Modify: `app/Services/AmericanoFlexService.php`
- Modify: `tests/Unit/Services/AmericanoFlexServiceTest.php`

- [ ] **Step 2.4.1: Реализовать saveMatchResult**

Заменить заглушку:

```php
public function saveMatchResult(AmericanoFlexMatch $match, int $score1, int $score2): void
{
    DB::transaction(function () use ($match, $score1, $score2) {
        $match->update([
            'team1_score' => $score1,
            'team2_score' => $score2,
            'status' => 'completed',
        ]);

        $tournamentId = $match->round->tournament_id;
        $team1Ids = [$match->team1_player1_id, $match->team1_player2_id];
        $team2Ids = [$match->team2_player1_id, $match->team2_player2_id];

        // Очки игроков: команда получает свой счёт; matches_played +1
        AmericanoFlexPlayer::where('tournament_id', $tournamentId)
            ->whereIn('user_id', $team1Ids)
            ->update([
                'total_points' => DB::raw("total_points + {$score1}"),
                'matches_played' => DB::raw('matches_played + 1'),
            ]);
        AmericanoFlexPlayer::where('tournament_id', $tournamentId)
            ->whereIn('user_id', $team2Ids)
            ->update([
                'total_points' => DB::raw("total_points + {$score2}"),
                'matches_played' => DB::raw('matches_played + 1'),
            ]);

        // pair_history: +1 partners для команд, +1 opponents для крестов
        $this->incrementPairHistory($tournamentId, $team1Ids[0], $team1Ids[1], 'partners');
        $this->incrementPairHistory($tournamentId, $team2Ids[0], $team2Ids[1], 'partners');
        foreach ($team1Ids as $a) {
            foreach ($team2Ids as $b) {
                $this->incrementPairHistory($tournamentId, $a, $b, 'opponents');
            }
        }

        // Если все матчи раунда завершены — пометить раунд completed
        if ($this->isRoundCompleted($match->round)) {
            $match->round->update(['status' => 'completed']);
        }
    });
}

private function incrementPairHistory(int $tournamentId, int $a, int $b, string $kind): void
{
    [$lo, $hi] = AmericanoFlexPairHistory::normalizeIds($a, $b);
    $col = $kind === 'partners' ? 'times_as_partners' : 'times_as_opponents';
    $row = AmericanoFlexPairHistory::firstOrCreate(
        ['tournament_id' => $tournamentId, 'player1_id' => $lo, 'player2_id' => $hi],
        ['times_as_partners' => 0, 'times_as_opponents' => 0]
    );
    $row->increment($col);
}
```

- [ ] **Step 2.4.2: Добавить тесты на saveMatchResult**

```php
public function test_save_match_result_updates_points_and_history(): void
{
    $tournament = $this->makeTournament(10, 2);
    $this->service->startTournament($tournament);

    $match = $tournament->americanoFlexRounds()->first()->matches()->first();
    $this->service->saveMatchResult($match, 24, 18);

    $match->refresh();
    $this->assertEquals('completed', $match->status);
    $this->assertEquals(24, $match->team1_score);

    $team1Player = AmericanoFlexPlayer::where('tournament_id', $tournament->id)
        ->where('user_id', $match->team1_player1_id)->first();
    $this->assertEquals(24, $team1Player->total_points);
    $this->assertEquals(1, $team1Player->matches_played);

    $team2Player = AmericanoFlexPlayer::where('tournament_id', $tournament->id)
        ->where('user_id', $match->team2_player1_id)->first();
    $this->assertEquals(18, $team2Player->total_points);
}
```

- [ ] **Step 2.4.3: Запустить тесты**

Run: `php artisan test --filter=AmericanoFlexServiceTest`
Expected: 4 теста PASS.

- [ ] **Step 2.4.4: Commit**

```bash
git add app/Services/AmericanoFlexService.php tests/Unit/Services/AmericanoFlexServiceTest.php
git commit -m "feat(americano-flex): saveMatchResult с обновлением points и pair_history"
```

### Task 2.5: completeTournament + ELO

**Files:**
- Modify: `app/Services/AmericanoFlexService.php`
- Modify: `tests/Unit/Services/AmericanoFlexServiceTest.php`

- [ ] **Step 2.5.1: Реализовать completeTournament**

Заменить заглушку:

```php
public function completeTournament(Tournament $tournament): void
{
    DB::transaction(function () use ($tournament) {
        // Идём по всем матчам в порядке создания, применяем ELO дельты последовательно.
        $matches = AmericanoFlexMatch::whereIn(
                'americano_flex_round_id',
                $tournament->americanoFlexRounds()->pluck('id')
            )
            ->where('status', 'completed')
            ->orderBy('id')
            ->get();

        // Стартовые рейтинги — из AmericanoFlexPlayer.rating_before
        $players = $tournament->americanoFlexPlayers()->get()->keyBy('user_id');
        $currentRatings = [];
        foreach ($players as $p) {
            $currentRatings[$p->user_id] = $p->rating_before ?? 1500;
        }

        foreach ($matches as $match) {
            $r11 = $currentRatings[$match->team1_player1_id];
            $r12 = $currentRatings[$match->team1_player2_id];
            $r21 = $currentRatings[$match->team2_player1_id];
            $r22 = $currentRatings[$match->team2_player2_id];

            $t1 = ($r11 + $r12) / 2;
            $t2 = ($r21 + $r22) / 2;

            $result = $this->calculateRatingChange($t1, $t2, $match->team1_score, $match->team2_score);

            $currentRatings[$match->team1_player1_id] = $this->applyRatingChange($r11, $result['change1']);
            $currentRatings[$match->team1_player2_id] = $this->applyRatingChange($r12, $result['change1']);
            $currentRatings[$match->team2_player1_id] = $this->applyRatingChange($r21, $result['change2']);
            $currentRatings[$match->team2_player2_id] = $this->applyRatingChange($r22, $result['change2']);
        }

        // Сохраняем rating_after в players + обновляем users.rating
        foreach ($currentRatings as $userId => $newRating) {
            $players[$userId]->update(['rating_after' => $newRating]);
            \App\Models\User::where('id', $userId)->update(['rating' => $newRating]);
        }

        $tournament->update(['status' => 'completed']);
    });
}
```

- [ ] **Step 2.5.2: Добавить тест на completeTournament**

```php
public function test_complete_tournament_calculates_elo(): void
{
    $tournament = $this->makeTournament(10, 2);
    $this->service->startTournament($tournament);

    // Закрываем все матчи первого раунда
    $matches = $tournament->americanoFlexRounds()->first()->matches;
    foreach ($matches as $m) {
        $this->service->saveMatchResult($m, 24, 18);
    }

    $this->service->completeTournament($tournament);

    $this->assertEquals('completed', $tournament->fresh()->status);
    $players = AmericanoFlexPlayer::where('tournament_id', $tournament->id)->get();
    foreach ($players as $p) {
        $this->assertNotNull($p->rating_after, "у игрока {$p->user_id} должен быть rating_after");
    }
}
```

- [ ] **Step 2.5.3: Запустить тесты**

Run: `php artisan test --filter=AmericanoFlexServiceTest`
Expected: 5 тестов PASS.

- [ ] **Step 2.5.4: Commit**

```bash
git add app/Services/AmericanoFlexService.php tests/Unit/Services/AmericanoFlexServiceTest.php
git commit -m "feat(americano-flex): completeTournament с расчётом ELO по каждому матчу"
```

---

## Phase 3: Маршруты и контроллер

### Task 3.1: AmericanoFlexController

**Files:**
- Create: `app/Http/Controllers/Club/AmericanoFlexController.php`
- Modify: `routes/web.php`

- [ ] **Step 3.1.1: Создать контроллер**

```php
<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\AmericanoFlexMatch;
use App\Models\Tournament;
use App\Services\AmericanoFlexService;
use Illuminate\Http\Request;

class AmericanoFlexController extends Controller
{
    public function __construct(private AmericanoFlexService $service) {}

    /** POST /club/tournaments/{tournament}/flex/start */
    public function start(Tournament $tournament)
    {
        if (!$tournament->isAmericanoFlex()) {
            return back()->with('error', 'Этот турнир не Americano Flex');
        }
        if ($tournament->status !== 'open' && $tournament->status !== 'closed') {
            return back()->with('error', 'Турнир уже запущен или завершён');
        }

        // Spec §4: минимум игроков = M × 4 + 1, иначе Flex теряет смысл (предлагаем обычное Американо).
        $registered = \App\Models\TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('status', 'registered')
            ->count();
        $minRequired = max(4, $tournament->courts_count * 4);
        if ($registered < $minRequired) {
            return back()->with('error', "Недостаточно зарегистрированных игроков: {$registered}. Минимум для {$tournament->courts_count} корта/кортов — {$minRequired}.");
        }

        $this->service->startTournament($tournament);
        return back()->with('success', 'Турнир запущен, первый раунд сгенерирован');
    }

    /** POST /club/tournaments/{tournament}/flex/next-round */
    public function nextRound(Tournament $tournament)
    {
        $current = $this->service->getCurrentRound($tournament);
        if ($current && !$this->service->isRoundCompleted($current)) {
            return back()->with('error', 'Текущий раунд ещё не завершён');
        }

        $this->service->generateNextRound($tournament);
        return back()->with('success', 'Следующий раунд сгенерирован');
    }

    /** POST /club/tournaments/{tournament}/flex/complete */
    public function complete(Tournament $tournament)
    {
        $this->service->completeTournament($tournament);
        return back()->with('success', 'Турнир завершён, рейтинги обновлены');
    }

    /** POST /club/tournaments/flex/matches/{match}/score */
    public function saveScore(Request $request, AmericanoFlexMatch $match)
    {
        $data = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);
        $this->service->saveMatchResult($match, $data['team1_score'], $data['team2_score']);
        return back()->with('success', 'Счёт сохранён');
    }

    /** PUT /club/tournaments/flex/matches/{match}/score */
    public function updateScore(Request $request, AmericanoFlexMatch $match)
    {
        return $this->saveScore($request, $match);
    }
}
```

- [ ] **Step 3.1.2: Добавить маршруты**

В `routes/web.php` найти секцию club tournaments (где `Route::resource('tournaments', ...)`) и добавить рядом:

```php
// Americano Flex
Route::post('/tournaments/{tournament}/flex/start', [App\Http\Controllers\Club\AmericanoFlexController::class, 'start'])->name('tournaments.flex.start');
Route::post('/tournaments/{tournament}/flex/next-round', [App\Http\Controllers\Club\AmericanoFlexController::class, 'nextRound'])->name('tournaments.flex.nextRound');
Route::post('/tournaments/{tournament}/flex/complete', [App\Http\Controllers\Club\AmericanoFlexController::class, 'complete'])->name('tournaments.flex.complete');
Route::post('/tournaments/flex/matches/{match}/score', [App\Http\Controllers\Club\AmericanoFlexController::class, 'saveScore'])->name('tournaments.flex.matches.score');
Route::put('/tournaments/flex/matches/{match}/score', [App\Http\Controllers\Club\AmericanoFlexController::class, 'updateScore'])->name('tournaments.flex.matches.updateScore');
```

Параметр `{match}` будет резолвить `AmericanoFlexMatch` через Route Model Binding (имя совпадает с переменной).

- [ ] **Step 3.1.3: Проверить routes**

Run: `php artisan route:list --path=flex`
Expected: 5 маршрутов перечислены.

- [ ] **Step 3.1.4: Lint**

Run: `php -l app/Http/Controllers/Club/AmericanoFlexController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3.1.5: Commit**

```bash
git add app/Http/Controllers/Club/AmericanoFlexController.php routes/web.php
git commit -m "feat(americano-flex): контроллер + маршруты для запуска/раундов/счёта"
```

---

## Phase 4: UI клуба

### Task 4.1: Опция в форме создания турнира

**Files:**
- Modify: `resources/views/club/tournaments/create.blade.php`

- [ ] **Step 4.1.1: Найти селектор типа турнира**

Run: `grep -n "americano\|king_of_court\|bali_koc" resources/views/club/tournaments/create.blade.php`
Найти блок `<select name="type">` или radio с типами.

- [ ] **Step 4.1.2: Добавить опцию «Americano Flex»**

Внутри селектора типа добавить **после** опции `americano`:

```html
<option value="americano_flex" data-min-players="5">Americano Flex — с очередью игроков</option>
```

Если используются radio-кнопки, добавить аналогичную:

```html
<label class="...">
    <input type="radio" name="type" value="americano_flex">
    <span>Americano Flex</span>
    <small>Адаптивная очередь, для нечётного числа игроков</small>
</label>
```

- [ ] **Step 4.1.3: Проверить — открыть форму в браузере**

Run: запустить `php artisan serve` и открыть `/club/tournaments/create`. Убедиться что опция видна.

- [ ] **Step 4.1.4: Commit**

```bash
git add resources/views/club/tournaments/create.blade.php
git commit -m "feat(americano-flex): опция в форме создания турнира клуба"
```

### Task 4.2: Партиал _americano_flex.blade.php

**Files:**
- Create: `resources/views/club/tournaments/partials/_americano_flex.blade.php`

- [ ] **Step 4.2.1: Создать партиал**

Полный код партиала. Использует `$tournament` и сервис `AmericanoFlexService` через DI в Blade нельзя — резолвим через `app()`:

```blade
@php
    $flex = app(\App\Services\AmericanoFlexService::class);
    $currentRound = $flex->getCurrentRound($tournament);
    $leaderboard = $flex->getLeaderboard($tournament);
    $allMatchesCompleted = $currentRound ? $flex->isRoundCompleted($currentRound) : false;
    $allPlayersEqual = $leaderboard->count() > 0
        && $leaderboard->min('matches_played') === $leaderboard->max('matches_played');
@endphp

<div class="space-y-6">
    {{-- Заголовок турнира + действия --}}
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-xl font-bold">{{ $tournament->name }}</h2>
            <p class="text-sm text-gray-500">Americano Flex · {{ $tournament->status }}</p>
        </div>
        <div class="flex gap-2">
            @if($tournament->status === 'open' || $tournament->status === 'closed')
                <form method="POST" action="{{ route('club.tournaments.flex.start', $tournament) }}"
                      onsubmit="return confirm('Запустить турнир? Изменить участников будет нельзя.')">
                    @csrf
                    <button class="btn-primary">Запустить турнир</button>
                </form>
            @endif

            @if($tournament->status === 'in_progress' && $allMatchesCompleted)
                <form method="POST" action="{{ route('club.tournaments.flex.nextRound', $tournament) }}">
                    @csrf
                    <button class="btn-primary">Следующий раунд</button>
                </form>
                <form method="POST" action="{{ route('club.tournaments.flex.complete', $tournament) }}"
                      onsubmit="return confirm('Завершить турнир? Рейтинги игроков обновятся.')">
                    @csrf
                    <button class="btn-danger">Завершить турнир</button>
                </form>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-2 rounded">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-2 rounded">{{ session('error') }}</div>
    @endif

    {{-- Подсказка про равенство --}}
    @if($allPlayersEqual && $leaderboard->count() > 0)
        <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded text-sm">
            ℹ️ Каждый игрок сыграл по {{ $leaderboard->first()->matches_played }} матчей — все на равных. Хороший момент завершить турнир.
        </div>
    @endif

    {{-- Текущий раунд --}}
    @if($currentRound)
        <div>
            <h3 class="text-lg font-bold mb-3">Раунд {{ $currentRound->round_number }}</h3>

            @foreach($currentRound->matches as $match)
                <div class="border rounded-lg p-4 mb-3 bg-white">
                    <div class="flex justify-between items-center mb-2">
                        <div class="text-sm font-semibold text-gray-600">Корт {{ $match->court_number }}</div>
                        <div class="text-xs text-gray-500">{{ $match->status === 'completed' ? '✓ Завершён' : 'В игре' }}</div>
                    </div>

                    <form method="POST" action="{{ $match->status === 'completed'
                        ? route('club.tournaments.flex.matches.updateScore', $match)
                        : route('club.tournaments.flex.matches.score', $match) }}">
                        @csrf
                        @if($match->status === 'completed')
                            @method('PUT')
                        @endif

                        <div class="grid grid-cols-[1fr_auto_1fr] gap-3 items-center">
                            <div class="text-right">
                                <div class="font-medium">{{ $match->team1Player1->full_name }}</div>
                                <div class="text-sm text-gray-500">{{ $match->team1Player2->full_name }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="number" name="team1_score" min="0" required
                                       value="{{ $match->team1_score }}"
                                       class="w-16 text-center border rounded p-1">
                                <span class="text-gray-400">:</span>
                                <input type="number" name="team2_score" min="0" required
                                       value="{{ $match->team2_score }}"
                                       class="w-16 text-center border rounded p-1">
                            </div>
                            <div>
                                <div class="font-medium">{{ $match->team2Player1->full_name }}</div>
                                <div class="text-sm text-gray-500">{{ $match->team2Player2->full_name }}</div>
                            </div>
                        </div>

                        <button class="mt-2 btn-secondary text-sm">
                            {{ $match->status === 'completed' ? 'Обновить счёт' : 'Сохранить счёт' }}
                        </button>
                    </form>
                </div>
            @endforeach

            {{-- Отдыхающие --}}
            @if($currentRound->byes->count() > 0)
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-3 mt-3">
                    <div class="text-sm font-semibold text-orange-800 mb-1">💤 Отдыхают в этом раунде:</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach($currentRound->byes as $bye)
                            <span class="text-sm bg-white border border-orange-300 rounded px-2 py-1">
                                {{ $bye->user->full_name }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Лидерборд --}}
    @if($leaderboard->count() > 0)
        <div>
            <h3 class="text-lg font-bold mb-3">Лидерборд</h3>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100 text-sm">
                        <th class="p-2 text-left">#</th>
                        <th class="p-2 text-left">Игрок</th>
                        <th class="p-2 text-right">Очки</th>
                        <th class="p-2 text-right">Матчей</th>
                        <th class="p-2 text-right">Среднее</th>
                        <th class="p-2 text-right">Рейтинг</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($leaderboard as $i => $player)
                        <tr class="border-b">
                            <td class="p-2">{{ $i + 1 }}</td>
                            <td class="p-2">{{ $player->user->full_name }}</td>
                            <td class="p-2 text-right">{{ $player->total_points }}</td>
                            <td class="p-2 text-right">{{ $player->matches_played }}</td>
                            <td class="p-2 text-right font-bold">{{ number_format($player->average_score, 2) }}</td>
                            <td class="p-2 text-right text-sm text-gray-600">
                                {{ $player->rating_before ?? '—' }} → {{ $player->rating_after ?? '?' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
```

- [ ] **Step 4.2.2: Commit**

```bash
git add resources/views/club/tournaments/partials/_americano_flex.blade.php
git commit -m "feat(americano-flex): партиал ведения турнира клуба"
```

### Task 4.3: Подключить партиал в show

**Files:**
- Modify: `resources/views/club/tournaments/show.blade.php`

- [ ] **Step 4.3.1: Найти routing по типу турнира**

Run: `grep -n "isAmericano\|isMexicano\|isTeam\|@include.*partials" resources/views/club/tournaments/show.blade.php`
Найти блок `@if($tournament->isMexicano()) @include('club.tournaments.partials._mexicano') @elseif(...)`.

- [ ] **Step 4.3.2: Добавить ветку для Flex**

В блок условных включений добавить (рядом с `_mexicano`):

```blade
@elseif($tournament->isAmericanoFlex())
    @include('club.tournaments.partials._americano_flex')
```

- [ ] **Step 4.3.3: Commit**

```bash
git add resources/views/club/tournaments/show.blade.php
git commit -m "feat(americano-flex): подключение партиала к странице show"
```

---

## Phase 5: Ручное тестирование на dev

### Task 5.1: End-to-end ручной прогон

**Цель:** Убедиться, что весь flow от создания до завершения турнира работает.

- [ ] **Step 5.1.1: Создать в /club/tournaments тестовый турнир**

- Тип: Americano Flex
- Max players: 10
- Courts: 2
- Дата: сегодня

- [ ] **Step 5.1.2: Зарегистрировать 10 игроков**

Через имеющуюся UI добавления участников. Использовать 10 разных пользователей с реальными рейтингами.

- [ ] **Step 5.1.3: Закрыть регистрацию (если такой шаг есть) и нажать «Запустить турнир»**

Проверить:
- В БД создались 10 записей `americano_flex_players`
- Создан раунд №1
- Создано 2 матча, 2 записи в byes
- В UI отображаются 2 карточки матчей и блок «Отдыхают»

- [ ] **Step 5.1.4: Ввести счёт обоих матчей раунда 1**

После каждого сохранения проверить лидерборд (среднее и matches_played меняются).

- [ ] **Step 5.1.5: Нажать «Следующий раунд»**

Проверить:
- В раунд 2 попадают двое из отдыхавших (bye_streak=1)
- В UI правильно отображается раунд 2 + новый блок отдыхающих

- [ ] **Step 5.1.6: Прогнать ещё 3 раунда (всего 5)**

После 5 раундов:
- Все игроки имеют `matches_played` = 4
- В UI появилась плашка «Все игроки на равных, можно завершать»

- [ ] **Step 5.1.7: Нажать «Завершить турнир»**

Проверить:
- Турнир в статусе `completed`
- У всех `rating_after` заполнен
- В таблице `users` рейтинги обновились

- [ ] **Step 5.1.8: Если что-то не работает — фикс и коммит**

Опционально: завести issue / отдельный коммит.

---

## Phase 6: Финал

### Task 6.1: Обновить spec со статусом

**Files:**
- Modify: `docs/superpowers/specs/2026-05-19-americano-flex-design.md`

- [ ] **Step 6.1.1: Поставить статус "MVP-1 реализован (веб)"**

В шапке spec'а заменить `**Статус:** дизайн на ревью у пользователя, реализация не начата.` на:

```
**Статус:** MVP-1 (web) реализован 2026-05-19. Мобильное API и late entry / early exit вынесены в v2.
```

- [ ] **Step 6.1.2: Commit**

```bash
git add docs/superpowers/specs/2026-05-19-americano-flex-design.md
git commit -m "docs(americano-flex): отметить статус MVP-1 (web) как реализованный"
```

---

## Что НЕ делается в этом плане (явно)

- Мобильное API для Americano Flex (отдельный план в будущем)
- Push-уведомления
- Late entry / early exit / skip round
- Таймер раунда в UI
- Локализация EN/KK (только русский)
- Дашборд клуба со списком Flex-турниров (используется существующий список)

---

## Self-review checklist

После завершения всех Tasks убедись:

- [ ] Все 5 миграций применены без ошибок локально
- [ ] `php artisan test --filter=AmericanoFlexServiceTest` — все 5 тестов зелёные
- [ ] `php artisan route:list --path=flex` — 5 маршрутов на месте
- [ ] Можно создать турнир Americano Flex через UI клуба
- [ ] Можно ввести счёт и нажать «Следующий раунд» / «Завершить»
- [ ] После завершения у игроков `users.rating` обновился
- [ ] Лидерборд сортируется по среднему DESC

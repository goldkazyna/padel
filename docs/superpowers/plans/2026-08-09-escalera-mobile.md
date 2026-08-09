# Эскалера в мобильном приложении — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Админ создаёт и проводит турнир «Эскалера» из мобильного приложения.

**Architecture:** Вся логика формата уже живёт в `App\Services\EscaleraService` и меняться не должна — задачи добавляют только транспорт (эндпоинты мобильного API) и экраны Flutter. Ответ `matches` укладывается в ту же структуру `groups[0] → rounds[] → matches[]`, что у «Короля корта», поэтому приложение рисует его существующим рендером, а кнопки «Сгенерировать раунд» и «Завершить турнир» включаются флагами `summary.can_generate_next_round` и `summary.can_finish`.

**Tech Stack:** Laravel 12 + Sanctum (репозиторий `C:\projects\padel`), Flutter (отдельный репозиторий `C:\projects\padel_app`, свои коммиты).

## Global Constraints

- Все комментарии в коде, тексты ошибок и подписи в UI — на русском языке.
- `EscaleraService` не меняется ни в одной задаче: логика формата уже покрыта 48 тестами веб-версии. Если задача упирается в поведение сервиса — это повод остановиться и спросить, а не править сервис.
- Счёт короткого матча свободный: `integer|min:0|max:99` для обеих команд. Правило `different:team1_score` из «Короля корта» НЕ применять — в эскалере ничья допустима.
- Участников в эскалере строго `courts_count × 4`. Сервер считает `max_participants` сам, присланное значение игнорируется.
- Режим зачёта: `escalera_standings_mode` ∈ `points` (по умолчанию) | `raw_points`.
- Полный прогон тестов в этом окружении падает по `memory_limit` — проверять только точечно через `--filter`.
- Известные давно падающие тесты (не регрессия): `AmericanoFlexServiceTest::complete tournament calculates elo`, `ModerationMinutesTest`, 2× `TournamentRemindersTest`, 2× `CourtScheduleTest::calculatePrice`, Breeze-заготовки.
- Задачи 1–5 коммитятся в `C:\projects\padel`, задачи 6–8 — в `C:\projects\padel_app`. Это разные git-репозитории.

## Файлы

**Backend (`C:\projects\padel`):**

| Файл | Ответственность |
|---|---|
| `routes/api.php` | маршрут счёта эскалеры |
| `app/Http/Controllers/Api/MobileAdminTournamentController.php` | создание турнира: тип в валидации, пересчёт участников |
| `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` | редактирование, старт, `matches`, счёт, `next-round`, `finish` |
| `tests/Feature/MobileAdminEscaleraTest.php` | новый файл: весь мобильный цикл эскалеры |

**Flutter (`C:\projects\padel_app`):**

| Файл | Ответственность |
|---|---|
| `lib/screens/admin/admin_create_tournament_screen.dart` | карточка типа, поле кортов, переключатель зачёта |
| `lib/services/admin_service.dart` | `saveEscaleraScore` |
| `lib/screens/admin/admin_tournament_detail_screen.dart` | ветка счёта, сворачивание раундов |

---

### Task 1: Создание эскалеры через мобильный API

**Files:**
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentController.php` (метод `tournamentValidationRules()`, метод `finalizeTournamentCreate()`)
- Test: `tests/Feature/MobileAdminEscaleraTest.php` (создать)

**Interfaces:**
- Produces: тип `escalera` принимается эндпоинтом `POST /api/mobile/admin/tournaments`; поля запроса — `courts_count` (2–10) и `escalera_standings_mode`; сервер выставляет `max_participants = courts_count × 4`.

- [ ] **Step 1: Написать падающий тест**

Создать файл `tests/Feature/MobileAdminEscaleraTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Мобильная админка «Эскалеры»: создание, старт, матчи, счёт, раунды, финиш.
 */
class MobileAdminEscaleraTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Club,1:User} */
    private function makeClubAdmin(): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        return [$club, $admin];
    }

    public function test_create_sets_participants_from_courts(): void
    {
        [$club, $admin] = $this->makeClubAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/mobile/admin/tournaments', [
            'club_id' => $club->id,
            'type' => 'escalera',
            'name' => 'Эскалера вечер',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1,
            'max_level' => 5.75,
            // Намеренно неверное число: сервер считает участников сам.
            'max_participants' => 30,
            'status' => 'open',
            'courts_count' => 4,
            'escalera_standings_mode' => 'raw_points',
        ])->assertOk()->assertJsonPath('success', true);

        $t = Tournament::where('name', 'Эскалера вечер')->firstOrFail();
        $this->assertSame('escalera', $t->type);
        $this->assertSame(4, (int) $t->courts_count);
        $this->assertSame(16, (int) $t->max_participants, 'участников ровно кортов × 4');
        $this->assertSame('raw_points', $t->escalera_standings_mode);
    }

    public function test_create_defaults_standings_mode_to_points(): void
    {
        [$club, $admin] = $this->makeClubAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/mobile/admin/tournaments', [
            'club_id' => $club->id,
            'type' => 'escalera',
            'name' => 'Эскалера без режима',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1,
            'max_level' => 5.75,
            'max_participants' => 12,
            'status' => 'open',
            'courts_count' => 3,
        ])->assertOk();

        $t = Tournament::where('name', 'Эскалера без режима')->firstOrFail();
        $this->assertSame('points', $t->escalera_standings_mode);
        $this->assertSame(12, (int) $t->max_participants);
    }

    public function test_create_rejects_courts_out_of_range(): void
    {
        [$club, $admin] = $this->makeClubAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/mobile/admin/tournaments', [
            'club_id' => $club->id,
            'type' => 'escalera',
            'name' => 'Эскалера один корт',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1,
            'max_level' => 5.75,
            'max_participants' => 4,
            'status' => 'open',
            'courts_count' => 1,
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Запустить тест и убедиться, что падает**

Run: `php artisan test --filter=MobileAdminEscaleraTest`
Expected: FAIL — валидация не пропускает `type=escalera` (422 на первом тесте).

- [ ] **Step 3: Разрешить тип и посчитать участников**

В `tournamentValidationRules()` дописать `escalera` в правило типа и добавить два правила:

```php
            'type' => 'required|in:king_of_court,americano,americano_flex,bali_koc,team,round_robin,just_padel_it,mexicano,escalera',
```

```php
            'escalera_standings_mode' => 'nullable|in:points,raw_points',
```

Правило `courts_count` оставить как есть (`nullable|integer|min:1|max:32`) — диапазон эскалеры проверяется отдельно, потому что для остальных типов он другой.

В `finalizeTournamentCreate()`, сразу перед `$tournament = Tournament::create($validated);`, добавить:

```php
        // Эскалера: играют строго кортов × 4, поэтому число участников
        // считает сервер, а не форма. Кортов минимум два — иначе лестницы
        // не получится, подниматься некуда.
        if ($type === 'escalera') {
            $courts = (int) ($validated['courts_count'] ?? 0);
            if ($courts < 2 || $courts > 10) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'courts_count' => 'В эскалере от 2 до 10 кортов',
                ]);
            }
            $validated['max_participants'] = $courts * 4;
            $validated['escalera_standings_mode'] = $validated['escalera_standings_mode'] ?? 'points';
        } else {
            unset($validated['escalera_standings_mode']);
        }
```

- [ ] **Step 4: Запустить тест и убедиться, что проходит**

Run: `php artisan test --filter=MobileAdminEscaleraTest`
Expected: PASS (3 теста).

- [ ] **Step 5: Коммит**

```bash
git add app/Http/Controllers/Api/MobileAdminTournamentController.php tests/Feature/MobileAdminEscaleraTest.php
git commit -m "feat(escalera): создание турнира через мобильный API"
```

---

### Task 2: Старт эскалеры и правка кортов до старта

**Files:**
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` (метод `start()`, метод `update()`)
- Test: `tests/Feature/MobileAdminEscaleraTest.php`

**Interfaces:**
- Consumes: турнир типа `escalera` со статусом `open`, созданный в Task 1.
- Produces: `POST /api/mobile/admin/tournaments/{t}/start` создаёт первый раунд; `PUT /api/mobile/admin/tournaments/{t}` до старта пересчитывает участников из кортов.

- [ ] **Step 1: Написать падающие тесты**

Добавить в `tests/Feature/MobileAdminEscaleraTest.php` приватный помощник и два теста:

```php
    /**
     * Готовый к старту турнир: кортов × 4 зарегистрированных игрока.
     *
     * @return array{0:Club,1:User,2:Tournament}
     */
    private function makeReadyTournament(int $courts = 3): array
    {
        [$club, $admin] = $this->makeClubAdmin();

        $t = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'escalera',
            'status' => 'open',
            'courts_count' => $courts,
            'max_participants' => $courts * 4,
            'escalera_standings_mode' => 'points',
        ]);

        for ($i = 1; $i <= $courts * 4; $i++) {
            $user = User::factory()->create(['rating' => 1000 + $i * 50]);
            TournamentParticipant::create([
                'tournament_id' => $t->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
        }

        return [$club, $admin, $t];
    }

    public function test_start_creates_first_round(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")
            ->assertOk()
            ->assertJsonPath('success', true);

        $t->refresh();
        $this->assertSame('in_progress', $t->status);
        $this->assertSame(1, $t->escaleraRounds()->count());
        $this->assertSame(3, $t->escaleraRounds()->first()->courts()->count());
        $this->assertSame(12, $t->escaleraPlayers()->count());
    }

    public function test_start_blocked_when_participants_do_not_match_courts(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        // Убираем одного игрока — двенадцати уже нет.
        TournamentParticipant::where('tournament_id', $t->id)->first()->delete();
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('open', $t->fresh()->status);
    }

    public function test_update_recalculates_participants_before_start(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        Sanctum::actingAs($admin);

        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", [
            'name' => $t->name,
            'start_date' => now()->addDays(2)->toIso8601String(),
            'min_level' => 1,
            'max_level' => 5.75,
            'max_participants' => 99,
            'status' => 'open',
            'courts_count' => 5,
        ])->assertOk();

        $t->refresh();
        $this->assertSame(5, (int) $t->courts_count);
        $this->assertSame(20, (int) $t->max_participants);
    }
```

- [ ] **Step 2: Запустить тесты и убедиться, что падают**

Run: `php artisan test --filter=MobileAdminEscaleraTest`
Expected: FAIL — `start` не знает эскалеру, турнир остаётся `open`.

- [ ] **Step 3: Добавить ветку старта**

В `start()` добавить параметр сервиса в сигнатуру:

```php
        \App\Services\EscaleraService $escalera
```

и ветку в цепочку `elseif` (перед финальным `else`):

```php
        } elseif ($tournament->isEscalera()) {
            $registered = $tournament->participants()->wherePivot('status', 'registered')->count();
            $need = (int) $tournament->courts_count * 4;
            if ($registered !== $need) {
                return response()->json([
                    'success' => false,
                    'message' => "В эскалере играют ровно {$need} игроков (кортов × 4), сейчас записано {$registered}",
                ], 422);
            }
            $ok = $escalera->startTournament($tournament);
```

- [ ] **Step 4: Добавить пересчёт участников при редактировании**

В `update()` найти место, где применяется `syncCourtNames()`, и перед сохранением добавить:

```php
        // Эскалера: до старта число участников всегда пересчитывается из
        // кортов, после старта корты и участники заморожены — иначе
        // рассыпется уже построенная лестница.
        if ($tournament->isEscalera()) {
            if (in_array($tournament->status, ['draft', 'open'], true)) {
                $courts = (int) ($validated['courts_count'] ?? $tournament->courts_count);
                if ($courts >= 2 && $courts <= 10) {
                    $validated['courts_count'] = $courts;
                    $validated['max_participants'] = $courts * 4;
                }
            } else {
                unset($validated['courts_count'], $validated['max_participants']);
            }
        }
```

- [ ] **Step 5: Запустить тесты и убедиться, что проходят**

Run: `php artisan test --filter=MobileAdminEscaleraTest`
Expected: PASS (6 тестов).

- [ ] **Step 6: Коммит**

```bash
git add app/Http/Controllers/Api/MobileAdminTournamentDetailController.php tests/Feature/MobileAdminEscaleraTest.php
git commit -m "feat(escalera): старт и правка кортов через мобильный API"
```

---

### Task 3: Ответ `matches` для эскалеры

**Files:**
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` (метод `matches()`, новые приватные методы)
- Test: `tests/Feature/MobileAdminEscaleraTest.php`

**Interfaces:**
- Consumes: стартовавший турнир из Task 2.
- Produces: `GET /api/mobile/admin/tournaments/{t}/matches` для типа `escalera` возвращает `groups[0].rounds[].matches[]` с `court_number` и `groups[0].leaderboard[]`; `summary.can_generate_next_round`, `summary.can_finish`.

- [ ] **Step 1: Написать падающий тест**

Добавить в `tests/Feature/MobileAdminEscaleraTest.php`:

```php
    public function test_matches_returns_rounds_courts_and_leaderboard(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        Sanctum::actingAs($admin);

        $res = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/matches")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('type', 'escalera')
            ->assertJsonPath('playoff', null)
            ->assertJsonPath('summary.matches_total', 9)
            ->assertJsonPath('summary.matches_played', 0)
            ->assertJsonPath('summary.can_generate_next_round', false)
            ->assertJsonPath('summary.can_finish', false);

        $rounds = $res->json('groups.0.rounds');
        $this->assertCount(1, $rounds);
        $this->assertSame(1, $rounds[0]['round_number']);
        $this->assertCount(9, $rounds[0]['matches'], 'три корта по три матча');

        // Матчи несут номер корта — по нему приложение группирует карточки.
        $courts = array_unique(array_column($rounds[0]['matches'], 'court_number'));
        sort($courts);
        $this->assertSame([1, 2, 3], $courts);

        // В матче обе пары по два игрока с именами.
        $first = $rounds[0]['matches'][0];
        $this->assertCount(2, $first['team1']['players']);
        $this->assertCount(2, $first['team2']['players']);
        $this->assertNotEmpty($first['team1']['players'][0]['name']);

        $leaderboard = $res->json('groups.0.leaderboard');
        $this->assertCount(12, $leaderboard);
    }

    public function test_matches_leaderboard_carries_scored_and_conceded(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        $service = app(\App\Services\EscaleraService::class);
        $service->startTournament($t);

        // Первый корт: 12:0, 11:1, 10:2 — у первой посадки 33 забитых и 3 пропущенных.
        $court = $t->fresh()->escaleraRounds()->first()->courts()->orderBy('court_number')->first();
        $scores = [[12, 0], [11, 1], [10, 2]];
        foreach ($court->matches()->orderBy('match_number')->get() as $i => $match) {
            $service->saveMatchResult($match, $scores[$i][0], $scores[$i][1]);
        }
        $seatingFirst = $court->playerIds()[0];

        Sanctum::actingAs($admin);
        $res = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/matches")->assertOk();

        $row = collect($res->json('groups.0.leaderboard'))
            ->firstWhere('id', $seatingFirst);

        $this->assertSame(33, $row['points_for'], 'забито');
        $this->assertSame(3, $row['points_against'], 'пропущено');
        $this->assertSame(3, $row['wins']);
        $this->assertSame(0, $row['losses']);
        $this->assertSame(69, $row['ball_percent'], '33 из 36');
    }

    public function test_matches_flags_ready_round(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        $service = app(\App\Services\EscaleraService::class);
        $service->startTournament($t);

        // Все счета внесены — можно и генерировать следующий раунд, и завершать.
        foreach ($t->fresh()->escaleraRounds()->first()->courts as $court) {
            foreach ($court->matches()->orderBy('match_number')->get() as $match) {
                $service->saveMatchResult($match, 7, 5);
            }
        }

        Sanctum::actingAs($admin);
        $this->getJson("/api/mobile/admin/tournaments/{$t->id}/matches")
            ->assertOk()
            ->assertJsonPath('summary.matches_played', 9)
            ->assertJsonPath('summary.can_generate_next_round', true)
            ->assertJsonPath('summary.can_finish', true);
    }
```

- [ ] **Step 2: Запустить тесты и убедиться, что падают**

Run: `php artisan test --filter=MobileAdminEscaleraTest`
Expected: FAIL — ответ содержит `unsupported: true`, ключа `groups` нет.

- [ ] **Step 3: Добавить ветку в `matches()` и построить ответ**

В `matches()` перед финальным `return` добавить:

```php
        if ($tournament->isEscalera()) {
            return response()->json($this->buildEscaleraMatches($tournament));
        }
```

Рядом с `buildKingOfCourtMatches()` добавить два приватных метода:

```php
    /**
     * Ответ мобильной админки для «Эскалеры».
     *
     * Матчи всех кортов раунда идут одним списком: приложение группирует их
     * по court_number, как это делает веб-версия. Обёртка в одну виртуальную
     * «группу» — тот же приём, что у «Короля корта»: фронт переиспользует
     * готовый рендер «группа → раунды → таблица».
     */
    private function buildEscaleraMatches(Tournament $tournament): array
    {
        $tournament->load([
            'escaleraRounds.courts.matches.team1Player1',
            'escaleraRounds.courts.matches.team1Player2',
            'escaleraRounds.courts.matches.team2Player1',
            'escaleraRounds.courts.matches.team2Player2',
            'escaleraPlayers.user',
        ]);

        $matchesTotal = 0;
        $matchesPlayed = 0;

        $rounds = $tournament->escaleraRounds
            ->sortBy('round_number')
            ->values()
            ->map(function ($round) use (&$matchesTotal, &$matchesPlayed) {
                $matches = [];
                foreach ($round->courts->sortBy('court_number') as $court) {
                    foreach ($court->matches->sortBy('match_number') as $match) {
                        $matchesTotal++;
                        if ($match->isCompleted()) {
                            $matchesPlayed++;
                        }
                        $matches[] = $this->formatEscaleraMatch($match, (int) $court->court_number);
                    }
                }

                return [
                    'id' => $round->id,
                    'round_number' => (int) $round->round_number,
                    'status' => $round->status,
                    'matches' => $matches,
                ];
            });

        $service = app(\App\Services\EscaleraService::class);

        // Набор ключей — тот же, что у buildKingOfCourtLeaderboard в этом же
        // файле: приложение разбирает обе таблицы одной моделью.
        $leaderboard = [];
        foreach ($service->standings($tournament) as $row) {
            $user = $row['user'];
            if (!$user) {
                continue;
            }
            $scored = (int) $row['raw_points'];
            $conceded = (int) $row['points_against'];
            $balls = $scored + $conceded;
            $games = (int) $row['wins'] + (int) $row['losses'];

            $leaderboard[] = [
                'position' => (int) $row['position'],
                'id' => $user->id,
                'name' => $user->full_name ?? $user->name,
                'avatar' => $user->avatar,
                'verified' => (bool) $user->level_verified,
                'rating' => (int) ($user->rating ?? 0),
                'wins' => (int) $row['wins'],
                'losses' => (int) $row['losses'],
                'draws' => 0,
                'points_for' => $scored,
                'points_against' => $conceded,
                'total_points' => (int) $row['points'],
                'games_played' => $games,
                'point_diff' => $scored - $conceded,
                'win_percent' => $games > 0 ? (int) round((int) $row['wins'] / $games * 100) : 0,
                'ball_percent' => $balls > 0 ? (int) round($scored / $balls * 100) : 0,
            ];
        }

        $isLive = $tournament->status === 'in_progress';
        // Завершить можно и не закрывая раунд: finish закроет его сам,
        // если все счета внесены.
        $canClose = $isLive && $service->canCloseRound($tournament);

        return [
            'success' => true,
            'type' => 'escalera',
            'standings_mode' => $tournament->escalera_standings_mode ?? 'points',
            'groups' => [[
                'id' => 0,
                'name' => '',
                'rounds' => $rounds,
                'leaderboard' => $leaderboard,
            ]],
            'playoff' => null,
            'summary' => [
                'matches_total' => $matchesTotal,
                'matches_played' => $matchesPlayed,
                'all_group_matches_played' => $matchesTotal > 0 && $matchesTotal === $matchesPlayed,
                'can_finish' => $canClose || ($isLive && $service->canFinishTournament($tournament)),
                'can_generate_playoff' => false,
                'can_generate_next_round' => $canClose,
            ],
        ];
    }

    private function formatEscaleraMatch(\App\Models\EscaleraMatch $match, int $courtNumber): array
    {
        return [
            'id' => $match->id,
            'court_number' => $courtNumber,
            'match_number' => (int) $match->match_number,
            'status' => $match->status,
            'team1' => [
                'players' => array_values(array_filter([
                    $this->formatMatchPlayer($match->team1Player1),
                    $this->formatMatchPlayer($match->team1Player2),
                ])),
                'score' => $match->team1_score,
            ],
            'team2' => [
                'players' => array_values(array_filter([
                    $this->formatMatchPlayer($match->team2Player1),
                    $this->formatMatchPlayer($match->team2Player2),
                ])),
                'score' => $match->team2_score,
            ],
        ];
    }
```

Перед реализацией открыть `formatKingOfCourtMatch()` в этом же файле и повторить точную форму ключей `team1`/`team2`, которую ждёт `AdminMatch.fromJson` в приложении.

- [ ] **Step 4: Запустить тесты и убедиться, что проходят**

Run: `php artisan test --filter=MobileAdminEscaleraTest`
Expected: PASS (9 тестов).

- [ ] **Step 5: Коммит**

```bash
git add app/Http/Controllers/Api/MobileAdminTournamentDetailController.php tests/Feature/MobileAdminEscaleraTest.php
git commit -m "feat(escalera): матчи и таблица в мобильной админке"
```

---

### Task 4: Ввод счёта через мобильный API

**Files:**
- Modify: `routes/api.php` (рядом с маршрутом счёта `bali_koc`, около строки 209)
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` (новый метод `saveEscaleraScore`)
- Test: `tests/Feature/MobileAdminEscaleraTest.php`

**Interfaces:**
- Consumes: матчи из Task 3.
- Produces: `POST|PUT /api/mobile/admin/tournaments/{tournament}/escalera/matches/{match}/score` с телом `{team1_score, team2_score}`.

- [ ] **Step 1: Написать падающий тест**

Добавить в `tests/Feature/MobileAdminEscaleraTest.php`:

```php
    public function test_save_score_accepts_any_sum_and_draw(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        $match = $t->fresh()->escaleraRounds()->first()->courts()->first()->matches()->first();
        Sanctum::actingAs($admin);

        // Свободный счёт: сумма ничем не ограничена.
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/escalera/matches/{$match->id}/score", [
            'team1_score' => 12,
            'team2_score' => 10,
        ])->assertOk()->assertJsonPath('success', true);

        $match->refresh();
        $this->assertSame(12, (int) $match->team1_score);
        $this->assertSame('completed', $match->status);

        // Ничья допустима — в эскалере она не победа и не поражение.
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}/escalera/matches/{$match->id}/score", [
            'team1_score' => 6,
            'team2_score' => 6,
        ])->assertOk();

        $this->assertSame(6, (int) $match->fresh()->team2_score);
    }

    public function test_save_score_rejects_negative(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        $match = $t->fresh()->escaleraRounds()->first()->courts()->first()->matches()->first();
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/escalera/matches/{$match->id}/score", [
            'team1_score' => -1,
            'team2_score' => 5,
        ])->assertStatus(422);

        $this->assertNull($match->fresh()->team1_score);
    }

    public function test_save_score_rejects_match_from_other_tournament(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);

        [$otherClub, $otherAdmin, $other] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($other);
        $foreign = $other->fresh()->escaleraRounds()->first()->courts()->first()->matches()->first();

        Sanctum::actingAs($admin);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/escalera/matches/{$foreign->id}/score", [
            'team1_score' => 7,
            'team2_score' => 5,
        ])->assertStatus(404);

        $this->assertNull($foreign->fresh()->team1_score);
    }

    public function test_save_score_forbidden_for_foreign_admin(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        $match = $t->fresh()->escaleraRounds()->first()->courts()->first()->matches()->first();

        $stranger = User::factory()->create(['role' => 'club_admin']);
        Sanctum::actingAs($stranger);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/escalera/matches/{$match->id}/score", [
            'team1_score' => 7,
            'team2_score' => 5,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->team1_score);
    }
```

- [ ] **Step 2: Запустить тесты и убедиться, что падают**

Run: `php artisan test --filter=MobileAdminEscaleraTest`
Expected: FAIL — маршрута нет, ответ 404 на всех четырёх.

- [ ] **Step 3: Добавить маршрут**

В `routes/api.php`, рядом с маршрутом счёта Bali KOC (около строки 209):

```php
        // Эскалера — счёт короткого матча (POST и PUT одинаковы: у формата
        // сохранение и правка — одна операция)
        Route::match(['POST', 'PUT'], '/admin/tournaments/{tournament}/escalera/matches/{match}/score', [MobileAdminTournamentDetailController::class, 'saveEscaleraScore']);
```

- [ ] **Step 4: Добавить обработчик**

Рядом с `saveKingOfCourtScore()` добавить:

```php
    /**
     * POST|PUT /api/mobile/admin/tournaments/{tournament}/escalera/matches/{match}/score
     *
     * Счёт свободный: сумма ничем не ограничена, ничья допустима —
     * формат короткого матча организаторы согласовывают на площадке.
     */
    public function saveEscaleraScore(
        Request $request,
        Tournament $tournament,
        \App\Models\EscaleraMatch $match,
        \App\Services\EscaleraService $service
    ): JsonResponse {
        if (!$this->canManageTournament($request->user(), $tournament)) {
            return $this->forbidden();
        }

        $match->loadMissing('court.round');
        if (!$match->court || !$match->court->round
            || (int) $match->court->round->tournament_id !== (int) $tournament->id) {
            return $this->error('Матч не принадлежит этому турниру', 404);
        }

        $validator = Validator::make($request->all(), [
            'team1_score' => 'required|integer|min:0|max:99',
            'team2_score' => 'required|integer|min:0|max:99',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            $service->saveMatchResult(
                $match,
                (int) $request->input('team1_score'),
                (int) $request->input('team2_score'),
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        $match->refresh();

        return response()->json([
            'success' => true,
            'match' => [
                'id' => $match->id,
                'team1_score' => $match->team1_score,
                'team2_score' => $match->team2_score,
                'status' => $match->status,
            ],
        ]);
    }
```

- [ ] **Step 5: Запустить тесты и убедиться, что проходят**

Run: `php artisan test --filter=MobileAdminEscaleraTest`
Expected: PASS (13 тестов).

- [ ] **Step 6: Коммит**

```bash
git add routes/api.php app/Http/Controllers/Api/MobileAdminTournamentDetailController.php tests/Feature/MobileAdminEscaleraTest.php
git commit -m "feat(escalera): ввод счёта через мобильный API"
```

---

### Task 5: Генерация раунда и завершение турнира

**Files:**
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` (методы `nextRound()`, `finish()`)
- Test: `tests/Feature/MobileAdminEscaleraTest.php`

**Interfaces:**
- Consumes: счёт из Task 4, ответ `matches` из Task 3.
- Produces: `POST /api/mobile/admin/tournaments/{t}/next-round` закрывает раунд и создаёт следующий, возвращая свежий `buildEscaleraMatches()`; `POST /api/mobile/admin/tournaments/{t}/finish` завершает турнир, закрыв открытый раунд.

- [ ] **Step 1: Написать падающие тесты**

Добавить в `tests/Feature/MobileAdminEscaleraTest.php`:

```php
    /** Внести счёт во все матчи текущего раунда. */
    private function playCurrentRound(Tournament $tournament): void
    {
        $service = app(\App\Services\EscaleraService::class);
        $round = $tournament->fresh()->escaleraRounds()->reorder('round_number', 'desc')->first();

        foreach ($round->courts as $court) {
            $scores = [[12, 0], [11, 1], [10, 2]];
            foreach ($court->matches()->orderBy('match_number')->get() as $i => $match) {
                $service->saveMatchResult($match, $scores[$i][0], $scores[$i][1]);
            }
        }
    }

    public function test_next_round_closes_current_and_creates_next(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        $this->playCurrentRound($t);
        Sanctum::actingAs($admin);

        $res = $this->postJson("/api/mobile/admin/tournaments/{$t->id}/next-round")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('type', 'escalera');

        $t->refresh();
        $this->assertSame(2, $t->escaleraRounds()->count(), 'следующий раунд создан');
        $this->assertTrue(
            $t->escaleraRounds()->where('round_number', 1)->first()->isCompleted(),
            'первый раунд закрыт'
        );

        // Ответ уже содержит оба раунда — приложение не делает второй запрос.
        $this->assertCount(2, $res->json('groups.0.rounds'));
    }

    public function test_next_round_blocked_until_scores_entered(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/next-round")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, $t->fresh()->escaleraRounds()->count());
    }

    public function test_finish_closes_open_round_and_awards_rating(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        $this->playCurrentRound($t);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/finish")
            ->assertOk()
            ->assertJsonPath('success', true);

        $t->refresh();
        $this->assertSame('completed', $t->status);
        $this->assertSame(1, $t->escaleraRounds()->count(), 'лишний раунд не создан');
        $this->assertTrue($t->escaleraRounds()->first()->isCompleted());

        // Рейтинг начислен: у каждого игрока проставлен rating_after.
        $this->assertSame(0, $t->escaleraPlayers()->whereNull('rating_after')->count());
    }

    public function test_finish_blocked_while_scores_missing(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/finish")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('in_progress', $t->fresh()->status);
    }
```

- [ ] **Step 2: Запустить тесты и убедиться, что падают**

Run: `php artisan test --filter=MobileAdminEscaleraTest`
Expected: FAIL — `next-round` не знает эскалеру, раунд не создаётся.

- [ ] **Step 3: Добавить ветку в `nextRound()`**

В сигнатуру `nextRound()` добавить параметр:

```php
        \App\Services\EscaleraService $escalera
```

и ветку рядом с веткой «Короля корта»:

```php
        if ($tournament->isEscalera()) {
            if (!$escalera->canCloseRound($tournament)) {
                return $this->error('Сначала внесите счета всех матчей на всех кортах');
            }

            // Для админа это одно действие: закрыть раунд и сразу получить
            // следующий — так же, как кнопка «Сгенерировать раунд» в вебе.
            try {
                if (!$escalera->closeRound($tournament)) {
                    return $this->error('Не удалось закрыть раунд');
                }
                $escalera->generateNextRound($tournament);
            } catch (\RuntimeException $e) {
                return $this->error($e->getMessage());
            }

            $tournament->refresh();

            return response()->json($this->buildEscaleraMatches($tournament));
        }
```

- [ ] **Step 4: Добавить ветку в `finish()`**

В сигнатуру `finish()` добавить параметр:

```php
        \App\Services\EscaleraService $escalera
```

и ветку в цепочку `elseif`:

```php
        } elseif ($tournament->isEscalera()) {
            // Раунд с внесёнными счетами закрываем здесь же: иначе админу
            // пришлось бы сгенерировать лишний раунд ради кнопки завершения.
            if (!$escalera->canFinishTournament($tournament) && $escalera->canCloseRound($tournament)) {
                try {
                    $escalera->closeRound($tournament);
                } catch (\RuntimeException $e) {
                    return $this->error($e->getMessage());
                }
            }
            if (!$escalera->canFinishTournament($tournament)) {
                return $this->error('Сначала внесите счета всех матчей на всех кортах');
            }
            $ok = $escalera->finishTournament($tournament);
```

- [ ] **Step 5: Запустить тесты и убедиться, что проходят**

Run: `php artisan test --filter=MobileAdminEscaleraTest`
Expected: PASS (17 тестов).

- [ ] **Step 6: Проверить, что веб-версия не задета**

Run: `php artisan test --filter=Escalera`
Expected: PASS — 48 тестов веб-версии плюс новые мобильные.

- [ ] **Step 7: Коммит**

```bash
git add app/Http/Controllers/Api/MobileAdminTournamentDetailController.php tests/Feature/MobileAdminEscaleraTest.php
git commit -m "feat(escalera): генерация раунда и завершение через мобильный API"
```

---

### Task 6: Экран создания турнира во Flutter

**Files (репозиторий `C:\projects\padel_app`):**
- Modify: `lib/screens/admin/admin_create_tournament_screen.dart`

**Interfaces:**
- Consumes: `POST /api/mobile/admin/tournaments` из Task 1 — поля `type: 'escalera'`, `courts_count`, `escalera_standings_mode`.

- [ ] **Step 1: Добавить карточку типа**

В методе с горизонтальной лентой типов (около строки 800), после карточки `bali_koc`, добавить:

```dart
            card(
              value: 'escalera',
              title: 'Эскалера',
              subtitle: 'Лестница из кортов',
              icon: Icons.stairs_rounded,
            ),
```

- [ ] **Step 2: Добавить состояние режима зачёта**

Рядом с `bool _flexIsPaired = false;` (около строки 53) добавить:

```dart
  /// Эскалера: зачёт по баллам за позиции либо по сумме забитых очков.
  String _escaleraStandingsMode = 'points';
```

- [ ] **Step 3: Развернуть зависимость кортов и участников**

В геттере `_courtsCount` (около строки 106), сразу после ветки `americano_flex`, добавить:

```dart
    if (_type == 'escalera') {
      // У эскалеры зависимость обратная: корты задаёт админ, а участники
      // считаются из них. Автоподстановка «участники ÷ 4» здесь не нужна.
      final manual = int.tryParse(_teamCourts.text.trim());
      return (manual ?? 3).clamp(2, 10);
    }
```

- [ ] **Step 4: Скрыть поле участников и показать поле кортов с подписью**

В секции «Игроки и уровень» (около строки 598) обернуть поле `_maxParticipants`:

```dart
            if (_type != 'escalera') ...[
              _label('Макс. участников (2–128)'),
              _textField(
                _maxParticipants,
                hint: '16',
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              ),
              const SizedBox(height: 12),
            ],
            if (_type == 'escalera') ...[
              _label('Количество кортов (2–10)'),
              _textField(
                _teamCourts,
                hint: '3',
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              ),
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  'Участников будет ${_courtsCount * 4}: на каждом корте четверо.',
                  style: const TextStyle(color: AppTheme.textDim, fontSize: 11),
                ),
              ),
              const SizedBox(height: 12),
              _label('Итоговая таблица'),
              _escaleraModeControl(),
              const SizedBox(height: 12),
            ],
```

- [ ] **Step 5: Добавить переключатель зачёта**

Рядом с методом `_jpiRankControl()` добавить метод по его образцу — открыть `_jpiRankControl()` и повторить его разметку, заменив содержимое на два варианта:

```dart
  /// Эскалера: по чему считается итоговое место.
  Widget _escaleraModeControl() {
    Widget option(String value, String title, String subtitle) {
      final selected = _escaleraStandingsMode == value;
      return InkWell(
        onTap: () => setState(() => _escaleraStandingsMode = value),
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
            color: selected ? AppTheme.accent.withOpacity(0.12) : Colors.transparent,
            border: Border.all(
              color: selected ? AppTheme.accent : AppTheme.border,
            ),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            children: [
              Icon(
                selected ? Icons.radio_button_checked : Icons.radio_button_off,
                size: 18,
                color: selected ? AppTheme.accent : AppTheme.textDim,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title,
                        style: const TextStyle(
                            fontSize: 13, fontWeight: FontWeight.w700)),
                    Text(subtitle,
                        style: const TextStyle(
                            color: AppTheme.textDim, fontSize: 11)),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
    }

    return Column(
      children: [
        option('points', 'По баллам за позиции',
            'Родной зачёт формата: номер корта уже учтён'),
        const SizedBox(height: 8),
        option('raw_points', 'По сумме очков',
            'Считаются забитые очки за все короткие матчи'),
      ],
    );
  }
```

- [ ] **Step 6: Отправить поля на сервер**

В сборке тела запроса (около строки 277, где `'courts_count': _courtsCount`) добавить:

```dart
      if (_type == 'escalera') 'escalera_standings_mode': _escaleraStandingsMode,
```

Значение `max_participants` для эскалеры сервер пересчитывает сам, поэтому текущая отправка поля остаётся без изменений.

- [ ] **Step 7: Проверить сборку**

Run (в `C:\projects\padel_app`): `flutter analyze lib/screens/admin/admin_create_tournament_screen.dart`
Expected: без ошибок (предупреждения существующего кода допустимы).

- [ ] **Step 8: Коммит**

```bash
git add lib/screens/admin/admin_create_tournament_screen.dart
git commit -m "feat(escalera): создание турнира в приложении"
```

---

### Task 7: Ввод счёта эскалеры во Flutter

**Files (репозиторий `C:\projects\padel_app`):**
- Modify: `lib/services/admin_service.dart`
- Modify: `lib/screens/admin/admin_tournament_detail_screen.dart`

**Interfaces:**
- Consumes: эндпоинт счёта из Task 4.
- Produces: `AdminService.saveEscaleraScore(int tournamentId, int matchId, {required int team1Score, required int team2Score})`.

- [ ] **Step 1: Добавить метод сервиса**

В `lib/services/admin_service.dart`, рядом с `saveKingOfCourtScore` (около строки 740):

```dart
  /// Сохранить счёт короткого матча «Эскалеры». POST и PUT одинаковы —
  /// у формата сохранение и правка это одна операция.
  Future<void> saveEscaleraScore(
    int tournamentId,
    int matchId, {
    required int team1Score,
    required int team2Score,
  }) async {
    final token = await _storage.getToken();
    await _api.post(
      '/admin/tournaments/$tournamentId/escalera/matches/$matchId/score',
      {
        'team1_score': team1Score,
        'team2_score': team2Score,
      },
      token,
    );
  }
```

- [ ] **Step 2: Добавить ветку в диспетчер счёта**

В `admin_tournament_detail_screen.dart` найти цепочку `if (isBali) ... else if (isFlex) ...` (около строки 5280). Рядом с определением остальных флагов типа добавить:

```dart
    final isEscalera = _matches?.type == 'escalera';
```

и ветку перед финальным `else`:

```dart
      } else if (isEscalera) {
        await context.read<AdminService>().saveEscaleraScore(
              tournamentId,
              matchId,
              team1Score: result.score1,
              team2Score: result.score2,
            );
```

- [ ] **Step 3: Разрешить равный счёт в диалоге ввода**

Открыть диалог ввода счёта (около строки 5192, где объявлен `isKoc`) и проверить, запрещает ли он равные значения. Если запрет есть и он общий — для `escalera` его снять: в этом формате ничья допустима. Если запрета нет — шаг ничего не меняет.

- [ ] **Step 4: Проверить сборку**

Run (в `C:\projects\padel_app`): `flutter analyze lib/services/admin_service.dart lib/screens/admin/admin_tournament_detail_screen.dart`
Expected: без ошибок.

- [ ] **Step 5: Коммит**

```bash
git add lib/services/admin_service.dart lib/screens/admin/admin_tournament_detail_screen.dart
git commit -m "feat(escalera): ввод счёта в приложении"
```

---

### Task 8: Отображение раундов эскалеры во Flutter

**Files (репозиторий `C:\projects\padel_app`):**
- Modify: `lib/screens/admin/admin_tournament_detail_screen.dart`

**Interfaces:**
- Consumes: ответ `matches` из Task 3 (`type: 'escalera'`, у матчей `court_number`).

- [ ] **Step 1: Сворачивать закрытые раунды**

В `_buildRoundBlock()` (около строки 4688) добавить тип в список сворачиваемых — раундов в эскалере много, и без этого экран превращается в бесконечную ленту:

```dart
    final collapsible = _matches?.type == 'round_robin'
        || _matches?.type == 'americano_flex'
        || _matches?.type == 'king_of_court'
        || _matches?.type == 'bali_koc'
        || _matches?.type == 'just_padel_it'
        || _matches?.type == 'escalera';
```

- [ ] **Step 2: Подписать кнопку генерации по формату**

Найти кнопку с текстом `'Сгенерировать следующий раунд'` (около строки 3818). Для эскалеры подпись должна называть номер — админ видит, какой раунд получит:

```dart
                label: Text(
                  _matches?.type == 'escalera'
                      ? 'Сгенерировать раунд ${(_matches?.groups.first.rounds.length ?? 0) + 1}'
                      : 'Сгенерировать следующий раунд',
                ),
```

Перед правкой убедиться, что `groups` не пуст: если `_matches?.groups` пустой, выражение `groups.first` бросит исключение. Использовать безопасный вариант:

```dart
                label: Text(_nextRoundLabel()),
```

и добавить рядом метод:

```dart
  /// Подпись кнопки генерации. У эскалеры называем номер раунда — так админ
  /// понимает, что получит, а не просто «следующий».
  String _nextRoundLabel() {
    if (_matches?.type != 'escalera') return 'Сгенерировать следующий раунд';
    final rounds = _matches?.groups.isNotEmpty == true
        ? _matches!.groups.first.rounds.length
        : 0;
    return 'Сгенерировать раунд ${rounds + 1}';
  }
```

- [ ] **Step 3: Проверить сборку**

Run (в `C:\projects\padel_app`): `flutter analyze lib/screens/admin/admin_tournament_detail_screen.dart`
Expected: без ошибок.

- [ ] **Step 4: Ручная проверка на живом турнире**

Запустить приложение на локальном API, создать эскалеру на 3 корта, добавить 12 игроков, стартовать, внести счета, сгенерировать раунд, завершить турнир. Убедиться, что: матчи сгруппированы по кортам с номерами, таблица показывает забитые и пропущенные, кнопка называет номер раунда, завершение доступно сразу после ввода всех счетов.

- [ ] **Step 5: Коммит**

```bash
git add lib/screens/admin/admin_tournament_detail_screen.dart
git commit -m "feat(escalera): раунды и кнопки в экране турнира"
```

---

## Порядок и зависимости

Задачи 1–5 строго последовательны: каждая опирается на предыдущую. Задачи 6–8 требуют готового бэкенда (1–5), между собой Task 7 и Task 8 независимы, но живут в одном файле — выполнять по порядку, чтобы не ловить конфликты.

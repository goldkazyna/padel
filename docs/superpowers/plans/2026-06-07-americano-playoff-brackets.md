# Americano Playoff: полуфинал для 1 группы + нижняя сетка — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить в Американо плей-офф полуфинал для 1 группы (топ-8) и опциональную нижнюю сетку (утешительный нокаут для следующего тира игроков) — для 1 и 2 групп, на вебе и в мобилке.

**Architecture:** Вся генерация в `AmericanoService`. Нижняя сетка = та же структура верхней, применённая к следующему тиру игроков (смещение = размер верхней). Матчи различаются полем `bracket` ('upper'/'lower') в существующей таблице `tournament_playoff_matches`. Стадии остаются русскими. «Чемпион» и условие завершения = финал верхней сетки.

**Tech Stack:** Laravel 11 (PHPUnit feature/unit-тесты, factories, RefreshDatabase), Blade, Flutter (Dart).

**Spec:** `docs/superpowers/specs/2026-06-07-americano-playoff-brackets-design.md`

---

## Файловая структура

**Фаза 1 (бэкенд + веб):**
- Modify: `app/Services/AmericanoService.php` — генерация (топ-8 для 1 группы, нижняя сетка, `bracket`), `updateFinalAfterSemifinal` по сетке, `canFinishTournament` + победитель по `bracket='upper'`.
- Modify: `resources/views/club/tournaments/create.blade.php` — JS показа полуфинала для 1 группы; чекбоксы «Нижняя сетка» / «Матч за 3-е место»; подсказки.
- Modify: `resources/views/club/tournaments/edit.blade.php` + `Club/TournamentController@update` — выровнять валидацию/поля с create.
- Modify: `resources/views/club/tournaments/partials/_americano_playoff.blade.php` — две секции по `bracket`, победитель из верхнего финала.
- Create: `tests/Unit/Services/AmericanoPlayoffTest.php` — тесты генерации.

**Фаза 2 (мобилка):**
- Modify: `app/Http/Controllers/Api/MobileTournamentController.php` — live + результаты: обе сетки, чемпион по `bracket='upper'`.
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` — ввод счёта в нижней сетке, продвижение по сетке.
- Modify: `C:\projects\padel_app\lib\screens\tournament_live_screen.dart` + `tournament_results_screen.dart` — рисовать нижнюю сетку.
- Verify: `app/Models/User.php` (превью рейтинга) + `MobileRatingController` — матчи нижней сетки учитываются в Эло.

---

# ФАЗА 1 — Бэкенд + веб

## Task 1: Полуфинал для 1 группы (топ-8) в AmericanoService

**Files:**
- Modify: `app/Services/AmericanoService.php` (методы `generatePlayoff` ~852, `createSemifinalMatches` ~1009)
- Test: `tests/Unit/Services/AmericanoPlayoffTest.php` (create)

- [ ] **Step 1: Создать тестовый файл с хелпером и первым падающим тестом**

```php
<?php

namespace Tests\Unit\Services;

use App\Models\AmericanoMatch;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\TournamentParticipant;
use App\Models\TournamentPlayoffMatch;
use App\Models\User;
use App\Services\AmericanoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmericanoPlayoffTest extends TestCase
{
    use RefreshDatabase;

    private AmericanoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AmericanoService();
    }

    /**
     * Создаёт Американо-турнир в статусе in_progress с одной группой,
     * N игроков с известным порядком очков (player_1 — больше всех очков и т.д.),
     * все групповые раунды completed. Возвращает турнир.
     */
    private function makeFinishedSingleGroup(int $players, array $playoffAttrs): Tournament
    {
        $tournament = Tournament::factory()->create(array_merge([
            'type' => 'americano',
            'status' => 'in_progress',
            'groups_count' => 1,
            'max_participants' => $players,
            'rounds_count' => 1,
            'has_playoff' => true,
        ], $playoffAttrs));

        $group = TournamentGroup::create([
            'tournament_id' => $tournament->id,
            'name' => 'Группа A',
        ]);

        // Игроки: total_points убывает с индексом → seed 1 = больше всех очков
        for ($i = 0; $i < $players; $i++) {
            $user = User::factory()->create(['rating' => 1500, 'name' => 'P' . ($i + 1)]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
            $group->players()->attach($user->id, [
                'total_points' => ($players - $i) * 10,
                'rating_before' => 1500,
                'rating_after' => null,
            ]);
        }

        return $tournament->fresh();
    }

    /** Вернуть User по seed-позиции (1-based) в группе по total_points desc. */
    private function seed(Tournament $t, int $position): User
    {
        $group = $t->groups()->first();
        $ordered = $group->players()->orderByPivot('total_points', 'desc')->get();
        return $ordered[$position - 1];
    }

    public function test_single_group_semifinal_creates_two_semis_and_empty_final(): void
    {
        $t = $this->makeFinishedSingleGroup(8, [
            'playoff_type' => 'semifinal_final',
            'playoff_format' => 'mix',
        ]);

        $ok = $this->service->generatePlayoff($t);

        $this->assertTrue($ok);
        $semis = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Полуфинал')->where('bracket', 'upper')->get();
        $final = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Финал')->where('bracket', 'upper')->get();

        $this->assertCount(2, $semis, 'должно быть 2 полуфинала');
        $this->assertCount(1, $final, 'должен быть 1 финал');
        $this->assertNull($final->first()->team1_player1_id, 'финал пустой до полуфиналов');
    }
}
```

- [ ] **Step 2: Запустить тест — убедиться, что падает**

Run: `php artisan test --filter=test_single_group_semifinal_creates_two_semis_and_empty_final`
Expected: FAIL (сейчас для 1 группы semifinal `createSemifinalMatches` делает `return` → 0 полуфиналов).

- [ ] **Step 3: В `generatePlayoff` проставлять bracket='upper' и разрешить semifinal для 1 группы**

В `app/Services/AmericanoService.php` в методе `generatePlayoff`, заменить финальный блок выбора (строки ~931-937):

```php
		if ($tournament->isFinalOnly()) {
			$this->createFinalMatch($tournament, $leaders, 'upper');
		} else {
			$this->createSemifinalMatches($tournament, $leaders, 'upper');
		}

		return true;
```

Также в начале сбора лидеров увеличить срез с 4 до 8, чтобы хватило на топ-8 (строка ~925 `array_slice($playerStats, 0, 4)`):

```php
			// Берём топ-8 (хватит и на финал из топ-4, и на полуфинал из топ-8)
			$topPlayers = collect(array_slice($playerStats, 0, 8))
				->map(fn($stat) => $stat['player']);
```

- [ ] **Step 4: Добавить bracket-параметр и 1-групповую ветку в `createSemifinalMatches`**

Заменить сигнатуру и начало метода `createSemifinalMatches` (~1009):

```php
	protected function createSemifinalMatches(Tournament $tournament, array $leaders, string $bracket = 'upper'): void
	{
		$groupNames = array_keys($leaders);

		// === 1 группа: топ-8 → 2 полуфинала (форматы mix/tops/balanced) ===
		if (count($groupNames) === 1) {
			$p = $leaders[$groupNames[0]]->values();
			if ($p->count() < 8) {
				return; // недостаточно игроков на полуфинал
			}
			$format = $tournament->playoff_format ?? 'mix';
			// индексы 0..7 = seed 1..8
			switch ($format) {
				case 'tops': // 1+2 vs 7+8 | 3+4 vs 5+6
					$semi1 = ['team1' => [$p[0]->id, $p[1]->id], 'team2' => [$p[6]->id, $p[7]->id]];
					$semi2 = ['team1' => [$p[2]->id, $p[3]->id], 'team2' => [$p[4]->id, $p[5]->id]];
					break;
				case 'balanced': // 1+4 vs 5+8 | 2+3 vs 6+7
					$semi1 = ['team1' => [$p[0]->id, $p[3]->id], 'team2' => [$p[4]->id, $p[7]->id]];
					$semi2 = ['team1' => [$p[1]->id, $p[2]->id], 'team2' => [$p[5]->id, $p[6]->id]];
					break;
				case 'mix':
				default: // 1+8 vs 4+5 | 2+7 vs 3+6
					$semi1 = ['team1' => [$p[0]->id, $p[7]->id], 'team2' => [$p[3]->id, $p[4]->id]];
					$semi2 = ['team1' => [$p[1]->id, $p[6]->id], 'team2' => [$p[2]->id, $p[5]->id]];
					break;
			}
			$this->persistSemifinalSet($tournament, $semi1, $semi2, $bracket);
			return;
		}

		if (count($groupNames) < 2) {
			return;
		}
```

Далее существующий 2-групповой код остаётся, НО блок создания матчей (строки ~1081-1123) заменить вызовом общего метода. После вычисления `$semi1`/`$semi2` для 2 групп заменить весь хвост на:

```php
		$this->persistSemifinalSet($tournament, $semi1, $semi2, $bracket);
	}
```

И добавить новый метод `persistSemifinalSet` (после `createSemifinalMatches`):

```php
	/**
	 * Записать пару полуфиналов + пустой финал + опц. матч за 3-е место для заданной сетки.
	 */
	protected function persistSemifinalSet(Tournament $tournament, array $semi1, array $semi2, string $bracket): void
	{
		TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id, 'stage' => 'Полуфинал', 'bracket' => $bracket,
			'match_number' => 1,
			'team1_player1_id' => $semi1['team1'][0], 'team1_player2_id' => $semi1['team1'][1],
			'team2_player1_id' => $semi1['team2'][0], 'team2_player2_id' => $semi1['team2'][1],
			'status' => 'pending',
		]);
		TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id, 'stage' => 'Полуфинал', 'bracket' => $bracket,
			'match_number' => 2,
			'team1_player1_id' => $semi2['team1'][0], 'team1_player2_id' => $semi2['team1'][1],
			'team2_player1_id' => $semi2['team2'][0], 'team2_player2_id' => $semi2['team2'][1],
			'status' => 'pending',
		]);
		TournamentPlayoffMatch::create([
			'tournament_id' => $tournament->id, 'stage' => 'Финал', 'bracket' => $bracket,
			'match_number' => 1, 'status' => 'pending',
		]);
		if ($tournament->has_bronze_match) {
			TournamentPlayoffMatch::create([
				'tournament_id' => $tournament->id, 'stage' => 'Матч за 3-е место', 'bracket' => $bracket,
				'match_number' => 1, 'is_bronze' => true, 'status' => 'pending',
			]);
		}
	}
```

- [ ] **Step 5: Добавить bracket-параметр в `createFinalMatch`**

Изменить сигнатуру `createFinalMatch` (~943) и во всех трёх вызовах `TournamentPlayoffMatch::create` внутри добавить `'bracket' => $bracket,`:

```php
	protected function createFinalMatch(Tournament $tournament, array $leaders, string $bracket = 'upper'): void
```

(в каждом `create([...])` внутри добавить ключ `'bracket' => $bracket,` рядом со `'stage' => 'Финал'`).

- [ ] **Step 6: Запустить тест — убедиться, что проходит**

Run: `php artisan test --filter=test_single_group_semifinal_creates_two_semis_and_empty_final`
Expected: PASS

- [ ] **Step 7: Добавить тест на корректность сидинга (mix) и прогнать**

```php
    public function test_single_group_semifinal_mix_seeding(): void
    {
        $t = $this->makeFinishedSingleGroup(8, [
            'playoff_type' => 'semifinal_final', 'playoff_format' => 'mix',
        ]);
        $this->service->generatePlayoff($t);

        $semi1 = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Полуфинал')->where('match_number', 1)->first();

        // mix: 1+8 vs 4+5
        $this->assertEqualsCanonicalizing(
            [$this->seed($t, 1)->id, $this->seed($t, 8)->id],
            [$semi1->team1_player1_id, $semi1->team1_player2_id]
        );
        $this->assertEqualsCanonicalizing(
            [$this->seed($t, 4)->id, $this->seed($t, 5)->id],
            [$semi1->team2_player1_id, $semi1->team2_player2_id]
        );
    }
```

Run: `php artisan test --filter=AmericanoPlayoffTest`
Expected: PASS (оба теста).

- [ ] **Step 8: Commit**

```bash
git add app/Services/AmericanoService.php tests/Unit/Services/AmericanoPlayoffTest.php
git commit -m "feat(americano): полуфинал для 1 группы (топ-8) + bracket на плей-офф матчах"
```

---

## Task 2: Нижняя сетка (следующий тир) в AmericanoService

**Files:**
- Modify: `app/Services/AmericanoService.php` (`generatePlayoff`)
- Test: `tests/Unit/Services/AmericanoPlayoffTest.php`

- [ ] **Step 1: Написать падающий тест нижней сетки (1 группа, финал, 8 игроков)**

```php
    public function test_single_group_final_lower_bracket_uses_places_5_to_8(): void
    {
        $t = $this->makeFinishedSingleGroup(8, [
            'playoff_type' => 'final_only', 'playoff_format' => 'cross',
            'has_lower_bracket' => true,
        ]);
        $this->service->generatePlayoff($t);

        $lowerFinal = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Финал')->where('bracket', 'lower')->first();
        $this->assertNotNull($lowerFinal, 'нижний финал создан');

        $lowerIds = [$lowerFinal->team1_player1_id, $lowerFinal->team1_player2_id,
                     $lowerFinal->team2_player1_id, $lowerFinal->team2_player2_id];
        $expected = [$this->seed($t,5)->id, $this->seed($t,6)->id,
                     $this->seed($t,7)->id, $this->seed($t,8)->id];
        $this->assertEqualsCanonicalizing($expected, $lowerIds);
    }
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `php artisan test --filter=test_single_group_final_lower_bracket_uses_places_5_to_8`
Expected: FAIL (нижний финал не создаётся, `$lowerFinal` null).

- [ ] **Step 3: Вынести сбор лидеров в метод `collectLeaders(offset, size)`**

В `generatePlayoff` заменить блок сбора `$leaders` (строки ~858-929) на вызов нового метода, а сам алгоритм ранжирования вынести в `rankGroupPlayers`. Добавить методы:

```php
	/**
	 * Лидеры по группам: для каждой группы — Collection из $size игроков,
	 * начиная с позиции $offset (0-based) в отсортированном рейтинге группы.
	 * @return array<string, \Illuminate\Support\Collection>
	 */
	protected function collectLeaders(Tournament $tournament, int $offset, int $size): array
	{
		$leaders = [];
		foreach ($tournament->groups as $group) {
			$ranked = $this->rankGroupPlayers($group); // Collection<User>
			$leaders[$group->name] = $ranked->slice($offset, $size)->values();
		}
		return $leaders;
	}

	/**
	 * Игроки группы, отсортированные: очки → победы → разница → личная встреча.
	 * @return \Illuminate\Support\Collection<int, \App\Models\User>
	 */
	protected function rankGroupPlayers(TournamentGroup $group): \Illuminate\Support\Collection
	{
		$playerStats = [];
		foreach ($group->players as $player) {
			$playerStats[$player->id] = [
				'player' => $player,
				'total_points' => $player->pivot->total_points,
				'wins' => 0, 'points_for' => 0, 'points_against' => 0,
			];
		}
		foreach ($group->rounds as $round) {
			foreach ($round->matches as $match) {
				if ($match->status !== 'completed') continue;
				$t1 = [$match->team1_player1_id, $match->team1_player2_id];
				$t2 = [$match->team2_player1_id, $match->team2_player2_id];
				foreach ($t1 as $pId) {
					if (isset($playerStats[$pId])) {
						$playerStats[$pId]['points_for'] += $match->team1_score;
						$playerStats[$pId]['points_against'] += $match->team2_score;
						if ($match->team1_score > $match->team2_score) $playerStats[$pId]['wins']++;
					}
				}
				foreach ($t2 as $pId) {
					if (isset($playerStats[$pId])) {
						$playerStats[$pId]['points_for'] += $match->team2_score;
						$playerStats[$pId]['points_against'] += $match->team1_score;
						if ($match->team2_score > $match->team1_score) $playerStats[$pId]['wins']++;
					}
				}
			}
		}
		$h2h = \App\Support\AmericanoTie::fromGroups([$group]);
		uasort($playerStats, function ($a, $b) use ($h2h) {
			if ($a['total_points'] !== $b['total_points']) return $b['total_points'] <=> $a['total_points'];
			if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
			$diffA = $a['points_for'] - $a['points_against'];
			$diffB = $b['points_for'] - $b['points_against'];
			if ($diffA !== $diffB) return $diffB <=> $diffA;
			return \App\Support\AmericanoTie::compare($h2h, $a['player']->id, $b['player']->id);
		});
		return collect(array_values($playerStats))->map(fn($s) => $s['player']);
	}
```

- [ ] **Step 4: Переписать конец `generatePlayoff` — верхняя + нижняя сетки**

Заменить блок генерации (после сбора лидеров) на:

```php
		$perGroupUpperSize = $this->upperBracketSize($tournament);
		$upperLeaders = $this->collectLeaders($tournament, 0, $perGroupUpperSize);

		if ($tournament->isFinalOnly()) {
			$this->createFinalMatch($tournament, $upperLeaders, 'upper');
		} else {
			$this->createSemifinalMatches($tournament, $upperLeaders, 'upper');
		}

		if ($tournament->has_lower_bracket) {
			$lowerLeaders = $this->collectLeaders($tournament, $perGroupUpperSize, $perGroupUpperSize);
			// строим только если в КАЖДОЙ группе хватило игроков на полный тир
			$enough = collect($lowerLeaders)->every(fn($c) => $c->count() >= $perGroupUpperSize);
			if ($enough) {
				if ($tournament->isFinalOnly()) {
					$this->createFinalMatch($tournament, $lowerLeaders, 'lower');
				} else {
					$this->createSemifinalMatches($tournament, $lowerLeaders, 'lower');
				}
			}
		}

		return true;
```

Добавить метод:

```php
	/** Сколько игроков из группы идёт в верхнюю сетку (на тир). */
	protected function upperBracketSize(Tournament $tournament): int
	{
		$groups = $tournament->groups->count();
		if ($tournament->isFinalOnly()) {
			return $groups === 1 ? 4 : 2;
		}
		return $groups === 1 ? 8 : 4;
	}
```

Примечание: в `collectLeaders` для нижней сетки 1 группы финала нужно 4 игрока на местах 5-8 — `createFinalMatch` для 1 группы использует индексы 0..3 переданной коллекции (места 5-8 становятся 0..3). Совместимо без изменений.

- [ ] **Step 5: Запустить тест — PASS**

Run: `php artisan test --filter=test_single_group_final_lower_bracket_uses_places_5_to_8`
Expected: PASS

- [ ] **Step 6: Тест «мало игроков — нижняя сетка не строится»**

```php
    public function test_lower_bracket_skipped_when_not_enough_players(): void
    {
        $t = $this->makeFinishedSingleGroup(6, [ // 6 < 8, на места 5-8 не хватает
            'playoff_type' => 'final_only', 'playoff_format' => 'cross',
            'has_lower_bracket' => true,
        ]);
        $this->service->generatePlayoff($t);

        $lower = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('bracket', 'lower')->count();
        $this->assertEquals(0, $lower, 'нижняя сетка не строится при нехватке игроков');
    }
```

Run: `php artisan test --filter=AmericanoPlayoffTest`
Expected: PASS (все тесты).

- [ ] **Step 7: Commit**

```bash
git add app/Services/AmericanoService.php tests/Unit/Services/AmericanoPlayoffTest.php
git commit -m "feat(americano): нижняя сетка плей-офф (следующий тир игроков)"
```

---

## Task 3: Bracket-aware завершение, продвижение и победитель

**Files:**
- Modify: `app/Services/AmericanoService.php` (`updateFinalAfterSemifinal` ~1128, `canFinishTournament` ~438)
- Test: `tests/Unit/Services/AmericanoPlayoffTest.php`

- [ ] **Step 1: Тест — каждая сетка обновляет СВОЙ финал**

```php
    public function test_semifinal_updates_only_its_own_bracket_final(): void
    {
        $t = $this->makeFinishedSingleGroup(16, [
            'playoff_type' => 'semifinal_final', 'playoff_format' => 'mix',
            'has_lower_bracket' => true,
        ]);
        $this->service->generatePlayoff($t);

        // Доиграть оба полуфинала ВЕРХНЕЙ сетки
        $upperSemis = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Полуфинал')->where('bracket', 'upper')->get();
        foreach ($upperSemis as $s) {
            $s->update(['team1_score' => 6, 'team2_score' => 3, 'status' => 'completed']);
            $this->service->updateFinalAfterSemifinal($s->fresh());
        }

        $upperFinal = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Финал')->where('bracket', 'upper')->first();
        $lowerFinal = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Финал')->where('bracket', 'lower')->first();

        $this->assertNotNull($upperFinal->team1_player1_id, 'верхний финал заполнен победителями верхних ПФ');
        $this->assertNull($lowerFinal->team1_player1_id, 'нижний финал ещё пуст');
    }
```

- [ ] **Step 2: Запустить — FAIL**

Run: `php artisan test --filter=test_semifinal_updates_only_its_own_bracket_final`
Expected: FAIL (текущий `updateFinalAfterSemifinal` берёт ВСЕ полуфиналы и единственный финал — без скоупа по bracket заполнит не тот финал / сломается).

- [ ] **Step 3: Скоупить `updateFinalAfterSemifinal` по bracket**

В методе `updateFinalAfterSemifinal` (~1128) добавить `$bracket` и фильтры:

```php
	public function updateFinalAfterSemifinal(TournamentPlayoffMatch $semifinalMatch): void
	{
		$tournament = $semifinalMatch->tournament;
		$bracket = $semifinalMatch->bracket ?? 'upper';

		$semifinals = $tournament->playoffMatches()
			->where('stage', 'Полуфинал')
			->where('bracket', $bracket)
			->get();

		$allCompleted = $semifinals->every(fn($m) => $m->status === 'completed');
		if (!$allCompleted) {
			return;
		}
```

Далее в этом методе заменить выборки финала и бронзы на скоуп по bracket:

```php
		$final = $tournament->playoffMatches()
			->where('stage', 'Финал')->where('bracket', $bracket)->first();
```

```php
			$bronze = $tournament->playoffMatches()
				->where('is_bronze', true)->where('bracket', $bracket)->first();
```

- [ ] **Step 4: Запустить — PASS**

Run: `php artisan test --filter=test_semifinal_updates_only_its_own_bracket_final`
Expected: PASS

- [ ] **Step 5: Скоупить победителя/финал в `canFinishTournament` (учитывая старые null-bracket)**

В `canFinishTournament` (~450) заменить выборку финала:

```php
			$final = $tournament->playoffMatches()
				->where('stage', 'Финал')
				->where(function ($q) {
					$q->where('bracket', 'upper')->orWhereNull('bracket');
				})
				->first();
```

И бронзу верхней сетки (~461):

```php
				$bronze = $tournament->playoffMatches()
					->where('is_bronze', true)
					->where(function ($q) {
						$q->where('bracket', 'upper')->orWhereNull('bracket');
					})
					->first();
```

- [ ] **Step 6: Тест завершения — нужен только верхний финал**

```php
    public function test_can_finish_requires_only_upper_final(): void
    {
        $t = $this->makeFinishedSingleGroup(8, [
            'playoff_type' => 'final_only', 'playoff_format' => 'cross',
            'has_lower_bracket' => true,
        ]);
        $this->service->generatePlayoff($t);

        // доигрываем только ВЕРХНИЙ финал, нижний оставляем pending
        $upperFinal = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Финал')->where('bracket', 'upper')->first();
        $upperFinal->update(['team1_score' => 6, 'team2_score' => 2, 'status' => 'completed']);

        $this->assertTrue($this->service->canFinishTournament($t->fresh()));
    }
```

Run: `php artisan test --filter=AmericanoPlayoffTest`
Expected: PASS (все тесты).

- [ ] **Step 7: Commit**

```bash
git add app/Services/AmericanoService.php tests/Unit/Services/AmericanoPlayoffTest.php
git commit -m "feat(americano): завершение/продвижение/победитель по bracket=upper"
```

---

## Task 4: Форма создания — полуфинал для 1 группы + чекбоксы нижней сетки/бронзы

**Files:**
- Modify: `resources/views/club/tournaments/create.blade.php`

- [ ] **Step 1: Показывать «Полуфинал + Финал» для 1 группы**

В `togglePlayoffOptions()` (строки ~487-503) убрать принудительный finalOnly при 1 группе — всегда показывать полуфинал:

```javascript
function togglePlayoffOptions() {
    const semifinalOption = document.getElementById('semifinalOption');
    if (semifinalOption) {
        semifinalOption.style.display = 'block'; // доступно и для 1, и для 2 групп
    }
}
```

- [ ] **Step 2: Добавить чекбоксы «Нижняя сетка» и «Матч за 3-е место» в `#americanoFields`**

После блока плей-офф опций (после закрывающего `</div>` для `playoff-options`, перед alert-success, строка ~197) вставить:

```html
							<div class="row mt-2" id="americanoBracketOptions" style="display:none;">
								<div class="col-md-6 mb-2">
									<div class="form-check">
										<input type="checkbox" name="has_lower_bracket" value="1" id="americanoLowerBracket" class="form-check-input"
											{{ old('has_lower_bracket') ? 'checked' : '' }}>
										<label for="americanoLowerBracket" class="form-check-label">
											Нижняя сетка <small class="text-muted">(утешительная — для следующего тира игроков)</small>
										</label>
									</div>
									<small class="text-secondary d-block" id="americanoLowerHint"></small>
								</div>
								<div class="col-md-6 mb-2" id="americanoBronzeWrap">
									<div class="form-check">
										<input type="checkbox" name="has_bronze_match" value="1" id="americanoBronze" class="form-check-input"
											{{ old('has_bronze_match') ? 'checked' : '' }}>
										<label for="americanoBronze" class="form-check-label">Матч за 3-е место</label>
									</div>
								</div>
							</div>
```

- [ ] **Step 3: JS — показывать опции сеток только при включённом плей-офф; бронзу — только при ПФ+финал; подсказка о мин. игроках**

В функции `togglePlayoffFormat()` в конце (перед закрывающей `}`) добавить управление видимостью новых опций:

```javascript
    // Опции сеток Американо
    const bracketOptions = document.getElementById('americanoBracketOptions');
    const bronzeWrap = document.getElementById('americanoBronzeWrap');
    const lowerHint = document.getElementById('americanoLowerHint');
    if (bracketOptions) {
        bracketOptions.style.display = hasPlayoff.checked ? 'flex' : 'none';
        const isSemi = semifinalFinal && semifinalFinal.checked;
        // бронза осмысленна только при ПФ+финал
        if (bronzeWrap) bronzeWrap.style.display = isSemi ? 'block' : 'none';
        if (!isSemi) {
            const bronze = document.getElementById('americanoBronze');
            if (bronze) bronze.checked = false;
        }
        // подсказка о минимуме игроков для нижней сетки
        const groups = parseInt(groupsCount.value);
        let minPlayers;
        if (groups === 1) minPlayers = isSemi ? 16 : 8;
        else minPlayers = isSemi ? 16 : 8; // 2 группы: по 8 / по 4 на тир
        if (lowerHint) lowerHint.textContent = 'Нужно минимум ' + minPlayers + ' участников';
    }
```

- [ ] **Step 4: Ручная проверка формы**

Run: `php artisan serve` (или существующий локальный хост), открыть `/club/tournaments/create`.
Expected:
- Тип «Американо», 1 группа, «Добавить плей-офф» → видны радио «Только финал» И «Полуфинал + Финал».
- Появляются чекбоксы «Нижняя сетка» и (только при ПФ+финал) «Матч за 3-е место».
- Подсказка о минимуме игроков отображается.

- [ ] **Step 5: Commit**

```bash
git add resources/views/club/tournaments/create.blade.php
git commit -m "feat(americano-form): полуфинал для 1 группы + чекбоксы нижней сетки и бронзы"
```

---

## Task 5: Веб-рендеринг — две секции по bracket

**Files:**
- Modify: `resources/views/club/tournaments/partials/_americano_playoff.blade.php`

- [ ] **Step 1: Группировать матчи по bracket и рисовать две секции**

Заменить блок `@php $stages = ... @endphp` и `<div class="playoff-bracket">...</div>` (строки ~9-130) на цикл по сеткам. Ввести группировку:

```blade
        @php
            $brackets = $tournament->playoffMatches->groupBy(fn($m) => $m->bracket ?: 'upper');
            $bracketTitles = ['upper' => 'Верхняя сетка', 'lower' => 'Нижняя сетка'];
            $stageOrder = ['Полуфинал' => 'Полуфинал', 'Матч за 3-е место' => 'Матч за 3-е место', 'Финал' => 'Финал'];
        @endphp

        @foreach(['upper','lower'] as $bracketKey)
            @if(isset($brackets[$bracketKey]))
                @php $stages = $brackets[$bracketKey]->groupBy('stage'); @endphp
                @if($brackets->count() > 1)
                    <h6 class="text-secondary mt-3 mb-2">{{ $bracketTitles[$bracketKey] }}</h6>
                @endif
                <div class="playoff-bracket">
                    @foreach($stageOrder as $stageKey => $stageName)
                        @if(isset($stages[$stageKey]))
                            <div class="playoff-stage">
                                <div class="stage-title">{{ $stageName }}</div>
                                <div class="stage-matches">
                                    @foreach($stages[$stageKey] as $match)
                                        @include('club.tournaments.partials._americano_playoff_match', ['match' => $match, 'stageName' => $stageName, 'tournament' => $tournament])
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        @endforeach
```

- [ ] **Step 2: Вынести карточку матча + модалки в партиал `_americano_playoff_match.blade.php`**

Create `resources/views/club/tournaments/partials/_americano_playoff_match.blade.php` — перенести в него существующую разметку одного матча (строки ~21-124 оригинала: вычисление имён, карточка, модалка ввода, модалка редактирования). Использовать переменные `$match`, `$stageName`, `$tournament`. (Разметка идентична текущей — просто извлечена в отдельный файл, чтобы переиспользовать в обеих секциях.)

- [ ] **Step 3: Победитель — из финала ВЕРХНЕЙ сетки**

Заменить строку ~133:

```blade
        @php $finalMatch = $tournament->playoffMatches
            ->first(fn($m) => $m->stage === 'Финал' && (($m->bracket ?: 'upper') === 'upper')); @endphp
```

- [ ] **Step 4: Ручная проверка на вебе (полный прогон)**

Создать тестовый Американо (1 группа, 8 игроков, финал + нижняя сетка), добавить тест-игроков, запустить, доиграть группу, сгенерировать плей-офф, ввести счета.
Expected: видны две секции «Верхняя сетка» / «Нижняя сетка»; счёт вводится в обеих; победитель турнира берётся из верхнего финала; турнир завершается.

- [ ] **Step 5: Commit**

```bash
git add resources/views/club/tournaments/partials/_americano_playoff.blade.php resources/views/club/tournaments/partials/_americano_playoff_match.blade.php
git commit -m "feat(americano-web): рендеринг верхней и нижней сетки плей-офф"
```

---

## Task 6: Выровнять форму/валидацию редактирования

**Files:**
- Modify: `app/Http/Controllers/Club/TournamentController.php` (`update` ~255-279)
- Modify: `resources/views/club/tournaments/edit.blade.php`

- [ ] **Step 1: Дополнить валидацию `update`**

В `update` (строки ~265-279) привести валидацию к виду `store`:

```php
			'has_playoff' => 'nullable|boolean',
			'has_lower_bracket' => 'nullable|boolean',
			'has_bronze_match' => 'nullable|boolean',
			'playoff_type' => 'nullable|in:final_only,semifinal_final',
			'playoff_format' => 'nullable|in:mix,group_vs,tops,cross,balanced',
```

И после валидации (рядом со строкой ~274) добавить чтение чекбоксов:

```php
		$validated['has_lower_bracket'] = $request->has('has_lower_bracket');
		$validated['has_bronze_match'] = $request->has('has_bronze_match');
```

- [ ] **Step 2: Проверить, что edit.blade.php содержит те же поля для Американо**

Read `resources/views/club/tournaments/edit.blade.php` — если плей-офф поля Американо отсутствуют/устаревшие, перенести разметку из create (Task 4 Steps 2-3). Если редактирование плей-офф там не предусмотрено (турнир редактируется только до старта) — задокументировать и пропустить.

- [ ] **Step 3: Ручная проверка** — открыть редактирование черновика Американо, убедиться, что поля сохраняются.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Club/TournamentController.php resources/views/club/tournaments/edit.blade.php
git commit -m "feat(americano-form): поля нижней сетки/бронзы в редактировании турнира"
```

---

# ФАЗА 2 — Мобилка

## Task 7: API live + результаты — обе сетки

**Files:**
- Modify: `app/Http/Controllers/Api/MobileTournamentController.php` (~1218, 1406, 1830, 1858, 2559)
- Test: `tests/Feature/MobileAmericanoPlayoffTest.php` (create)

- [ ] **Step 1: Изучить текущую сериализацию плей-офф**

Read `MobileTournamentController.php` строки 1180-1260 (live) и 1380-1460 (результаты) и 1800-1880 (победитель). Зафиксировать структуру JSON `playoff` (массив стадий/матчей).

- [ ] **Step 2: Падающий feature-тест — в ответе есть обе сетки**

```php
    public function test_live_returns_upper_and_lower_brackets(): void
    {
        // arrange: завершённый групповой этап Американо 1 группа 8 игроков,
        // playoff_type=final_only, has_lower_bracket=true, плей-офф сгенерирован
        // (использовать AmericanoService::generatePlayoff)
        // act: GET /api/mobile/tournaments/{id}/live с токеном участника
        // assert: в JSON playoff присутствуют матчи с bracket 'upper' и 'lower'
        $response->assertJsonPath('playoff.brackets.upper.0.stage', 'Финал');
        $response->assertJsonPath('playoff.brackets.lower.0.stage', 'Финал');
    }
```

(Точный путь `playoff.*` уточнить по Step 1; тест служит спецификацией нового формата.)

- [ ] **Step 3: Добавить `bracket` в сериализацию и группировать ответ по сеткам**

В сериализаторе плей-офф добавить в каждый матч поле `'bracket' => $m->bracket ?: 'upper'`, и (если фронт ждёт сгруппированную структуру) сгруппировать: `playoff.brackets.upper[]`, `playoff.brackets.lower[]`. Сохранить обратную совместимость: если нижней сетки нет — `lower` пустой/отсутствует.

- [ ] **Step 4: Победитель/чемпион — по `bracket='upper'`**

Во всех местах определения чемпиона (~1830) и упорядочивания (~1218, 1406) фильтровать финал: `->where('stage','Финал')->where(fn($q)=>$q->where('bracket','upper')->orWhereNull('bracket'))`.

- [ ] **Step 5: Запустить тест — PASS**

Run: `php artisan test --filter=MobileAmericanoPlayoffTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/MobileTournamentController.php tests/Feature/MobileAmericanoPlayoffTest.php
git commit -m "feat(api): обе сетки плей-офф Американо в live/результатах"
```

---

## Task 8: API админ — ввод счёта в нижней сетке

**Files:**
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` (~1282)

- [ ] **Step 1: Прочитать обработку ввода счёта плей-офф (~1260-1320)**

Зафиксировать, как сейчас сохраняется счёт и вызывается продвижение (`if ($match->stage === 'Полуфинал') ... updateFinalAfterSemifinal`).

- [ ] **Step 2: Убедиться, что продвижение использует bracket матча**

`updateFinalAfterSemifinal` уже скоупится по `$match->bracket` (Task 3). Здесь — только проверить, что контроллер передаёт сам матч (не пересобирает по стадии без bracket). Если фильтрует список плей-офф матчей по стадии без bracket — добавить bracket в выборку.

- [ ] **Step 3: Ручная проверка** — в мобильной админке доиграть нижнюю сетку, убедиться, что финал нижней заполняется победителями её полуфиналов, а чемпион турнира не меняется.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/MobileAdminTournamentDetailController.php
git commit -m "feat(api-admin): ввод счёта и продвижение в нижней сетке Американо"
```

---

## Task 9: Flutter — рендеринг нижней сетки

**Files:**
- Modify: `C:\projects\padel_app\lib\screens\tournament_live_screen.dart`
- Modify: `C:\projects\padel_app\lib\screens\tournament_results_screen.dart`

- [ ] **Step 1: Найти текущий рендер плей-офф Американо**

Grep в обоих файлах по `playoff`, `Полуфинал`, `Финал`. Зафиксировать модель/виджет, рисующий стадии.

- [ ] **Step 2: Распарсить новый формат (brackets.upper / brackets.lower)**

Обновить парсинг ответа: если есть `brackets`, рисовать две секции; иначе (обратная совместимость) — как раньше (одна = upper).

- [ ] **Step 3: Виджет двух секций**

Под существующей секцией плей-офф добавить заголовок «Нижняя сетка» (через `AppLocalizations`, RU+EN — добавить ключи `playoffUpperBracket`, `playoffLowerBracket`) и тот же виджет стадий для `lower`. Переиспользовать существующий виджет матча/стадии.

- [ ] **Step 4: Локализация**

Добавить в `lib/l10n` (RU + EN) ключи `playoffUpperBracket` = «Верхняя сетка»/«Upper bracket», `playoffLowerBracket` = «Нижняя сетка»/«Lower bracket». Run: `flutter gen-l10n`.

- [ ] **Step 5: Проверка**

Run: `flutter analyze` (Expected: без новых ошибок). Затем `flutter run` на турнире с нижней сеткой — убедиться, что обе секции рисуются в live и в результатах.

- [ ] **Step 6: Commit**

```bash
cd C:\projects\padel_app
git add lib/screens/tournament_live_screen.dart lib/screens/tournament_results_screen.dart lib/l10n
git commit -m "feat(live): рендеринг нижней сетки плей-офф Американо"
```

---

## Task 10: Проверка влияния нижней сетки на рейтинг

**Files:**
- Verify/Modify: `app/Models/User.php` (~993-1096), `app/Http/Controllers/Api/MobileRatingController.php` (~622, 651)
- Test: `tests/Unit/Services/AmericanoPlayoffTest.php`

- [ ] **Step 1: Тест — матчи нижней сетки входят в расчёт Эло при finishTournament**

```php
    public function test_lower_bracket_matches_affect_rating(): void
    {
        $t = $this->makeFinishedSingleGroup(8, [
            'playoff_type' => 'final_only', 'playoff_format' => 'cross',
            'has_lower_bracket' => true,
        ]);
        $this->service->generatePlayoff($t);

        // доиграть все матчи обеих сеток
        foreach (TournamentPlayoffMatch::where('tournament_id', $t->id)->get() as $m) {
            $m->update(['team1_score' => 6, 'team2_score' => 3, 'status' => 'completed']);
        }
        // группа уже completed в хелпере? если нет — пометить раунды completed

        $loserSeed5 = $this->seed($t, 5);
        $ratingBefore = $loserSeed5->rating;
        $this->service->finishTournament($t->fresh());

        $this->assertNotEquals($ratingBefore, $loserSeed5->fresh()->rating,
            'рейтинг игрока из нижней сетки должен измениться');
    }
```

- [ ] **Step 2: Запустить тест**

Run: `php artisan test --filter=test_lower_bracket_matches_affect_rating`
Expected: PASS, если `finishTournament` собирает все `playoffMatches` со `status='completed'` без фильтра по стадии (по коду — да). Если FAIL — снять лишний фильтр по стадии в сборе плей-офф матчей.

- [ ] **Step 3: Проверить превью истории рейтинга (`User.php` ~993-1096) и `MobileRatingController`**

Убедиться, что выборки `whereIn('stage', ['final','Финал'])` / `['semi','Полуфинал']` не дублируют и не теряют матчи нижней сетки (они тоже стадии «Финал»/«Полуфинал», но bracket='lower'). Для истории рейтинга это корректно (учитываются все плей-офф матчи игрока). Если где-то логика «место в турнире» опирается на финал — скоупить по `bracket='upper'`.

- [ ] **Step 4: Запустить весь набор тестов**

Run: `php artisan test --filter=AmericanoPlayoffTest`
Expected: PASS (все).

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php app/Http/Controllers/Api/MobileRatingController.php tests/Unit/Services/AmericanoPlayoffTest.php
git commit -m "test(americano): нижняя сетка учитывается в рейтинге; фикс скоупа места по bracket"
```

---

## Финальная проверка

- [ ] Прогнать весь backend-набор: `php artisan test` — Expected: зелёный.
- [ ] `flutter analyze` в `C:\projects\padel_app` — без новых ошибок.
- [ ] Ручной сквозной прогон на вебе: 1 группа 16 игроков, ПФ+финал, нижняя сетка, матч за 3-е место — обе сетки + бронза в обеих.
- [ ] Ручной прогон в мобилке (live + результаты + админ-ввод).

# Количество кортов у турнира — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сервер приводит названия кортов к их количеству при каждом сохранении турнира; в приложении число кортов можно менять у всех типов, кроме Americano Flex, с автозначением «участники ÷ 4» и возможностью ручной правки.

**Architecture:** На сервере — один метод модели `Tournament::syncCourtNames()`, вызываемый из трёх точек сохранения (мобильное API, создание и редактирование из веба). В приложении — расширение условий показа и отправки поля кортов плюс автоподстановка с флагом ручной правки.

**Tech Stack:** Laravel 12 (`C:\projects\padel`), Flutter (`C:\projects\padel_app`) — **два разных репозитория**, коммиты раздельные.

## Global Constraints

- Спека: `docs/superpowers/specs/2026-08-09-tournament-courts-count-design.md`
- Все комментарии в коде и тексты интерфейса — на русском. Никогда не на английском.
- **Americano Flex не трогаем вообще** — у него собственный ручной ввод кортов, поведение остаётся прежним.
- Автозначение — `ceil(участники / 4)`, существующая формула, менять её нельзя.
- Диапазон количества кортов — от 1 до 32, как в серверной валидации.
- Названия кортов приводятся к счётчику **на сервере**, независимо от того, что прислал клиент.
- Laravel-репозиторий: работа в ветке `feature/tournament-courts-count` (уже создана и активна).
- Flutter-репозиторий `C:\projects\padel_app` сейчас на `main`. Перед правками создать там ветку `feature/tournament-courts-count`.
- APK не собирать.
- Прогон тестов точечный, через `--filter`. Полный сьют в этом окружении не запускается — упирается в `memory_limit` PHP, это предсуществующая проблема. Во Flutter-проекте автотестов на экраны нет.

---

## File Structure

| Файл | Ответственность |
|---|---|
| `app/Models/Tournament.php` | Изменить: метод `syncCourtNames()` |
| `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` | Изменить: вызов после сохранения (`:233`) |
| `app/Http/Controllers/Club/TournamentController.php` | Изменить: вызовы после создания (`:187`) и обновления (`:394`) |
| `tests/Feature/TournamentCourtsCountTest.php` | Создать: тесты синхронизации названий |
| `padel_app/lib/screens/admin/admin_tournament_detail_screen.dart` | Изменить: показ и отправка поля кортов, автоподстановка |
| `padel_app/lib/screens/admin/admin_create_tournament_screen.dart` | Изменить: поле вместо текста, автоподстановка |

---

### Task 1: Сервер — синхронизация названий кортов

**Files:**
- Modify: `app/Models/Tournament.php` (рядом с `getCourtName()`, строка 733)
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php:233`
- Modify: `app/Http/Controllers/Club/TournamentController.php:187` и `:394`
- Test: `tests/Feature/TournamentCourtsCountTest.php`

**Interfaces:**
- Consumes: существующие поля `tournaments.courts` (каст `array`) и `tournaments.courts_count`
- Produces: `Tournament::syncCourtNames(): void`

- [ ] **Step 1: Написать падающие тесты**

Создать `tests/Feature/TournamentCourtsCountTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentCourtsCountTest extends TestCase
{
    use RefreshDatabase;

    /** Клуб и его администратор. */
    private function setupClub(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        return [$club, $admin];
    }

    /** Турнир с заданными кортами. */
    private function makeTournament(Club $club, ?int $count, ?array $courts): Tournament
    {
        return Tournament::create([
            'club_id' => $club->id,
            'name' => 'Турнир',
            'type' => 'king_of_court',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'max_participants' => 16,
            'courts_count' => $count,
            'courts' => $courts,
        ]);
    }

    public function test_shrinking_count_drops_extra_names(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeTournament($club, 3, ['Корт 1', 'Корт 2', 'Корт 3', 'Корт 4']);

        $t->syncCourtNames();

        $this->assertSame(['Корт 1', 'Корт 2', 'Корт 3'], $t->fresh()->courts);
    }

    public function test_growing_count_pads_with_empty(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeTournament($club, 5, ['Корт 1', 'Корт 2', 'Корт 3']);

        $t->syncCourtNames();

        $courts = $t->fresh()->courts;
        $this->assertCount(5, $courts);
        $this->assertSame('Корт 3', $courts[2]);
        $this->assertNull($courts[3], 'недостающие названия — пустые');
        $this->assertNull($courts[4]);
        // Подпись для добитых кортов генерируется по умолчанию.
        $this->assertSame('Корт 4', $t->fresh()->getCourtName(4));
    }

    public function test_null_count_leaves_names_untouched(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeTournament($club, null, ['Центральный', 'Дальний']);

        $t->syncCourtNames();

        $this->assertSame(['Центральный', 'Дальний'], $t->fresh()->courts);
    }

    public function test_all_empty_names_become_null(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeTournament($club, 3, [null, null, null, null]);

        $t->syncCourtNames();

        $this->assertNull($t->fresh()->courts);
    }

    public function test_no_names_at_all_stays_null(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeTournament($club, 3, null);

        $t->syncCourtNames();

        $this->assertNull($t->fresh()->courts);
    }

    public function test_mobile_update_fixes_broken_record(): void
    {
        [$club, $admin] = $this->setupClub();
        // Уже испорченная запись: счётчик 3, названий 4.
        $t = $this->makeTournament($club, 3, ['Корт 1', 'Корт 2', 'Корт 3', 'Корт 4']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/mobile/admin/tournaments/{$t->id}", [
                'name' => 'Турнир',
                'start_date' => now()->addDay()->toDateString(),
                'min_level' => 1,
                'max_level' => 5,
                'max_participants' => 16,
                'courts_count' => 3,
            ])
            ->assertOk();

        $this->assertCount(3, $t->fresh()->courts);
    }

    public function test_web_update_fixes_broken_record(): void
    {
        [$club, $admin] = $this->setupClub();
        $t = $this->makeTournament($club, 3, ['Корт 1', 'Корт 2', 'Корт 3', 'Корт 4']);

        $this->actingAs($admin)->put(route('club.tournaments.update', $t), [
            'name' => 'Турнир',
            'type' => 'king_of_court',
            'start_date' => now()->addDay()->toDateString(),
            'min_level' => 1,
            'max_level' => 5,
            'max_participants' => 16,
            'courts_count' => 3,
        ])->assertRedirect();

        $this->assertCount(3, $t->fresh()->courts);
    }
}
```

Маршруты проверены: мобильный — `PUT /api/mobile/admin/tournaments/{tournament}` (`routes/api.php:141`); веб — `Route::resource('tournaments', ClubTournamentController::class)` (`routes/web.php:401`), то есть `club.tournaments.update` методом PUT.

Для мобильного API в проекте используется `Sanctum::actingAs($user)` (образец — `tests/Feature/AutoVerificationGateTest.php:78`), а не `actingAs($admin, 'sanctum')` — привести тест к этому виду. Если валидация потребует ещё обязательных полей, добавить минимальные значения: цель тестов — поведение синхронизации, а не проверка форм.

- [ ] **Step 2: Запустить тесты и убедиться, что они падают**

Run: `php artisan test --filter=TournamentCourtsCountTest`
Expected: FAIL — метода `syncCourtNames` не существует.

- [ ] **Step 3: Добавить метод в модель**

В `app/Models/Tournament.php` рядом с `getCourtName()` (строка 733):

```php
	/**
	 * Привести названия кортов к их количеству.
	 *
	 * Клиенты присылают courts_count отдельно от названий (мобильное
	 * приложение названия вообще не шлёт), поэтому массив легко расходится
	 * со счётчиком: уменьшили корты — остались лишние названия, увеличили —
	 * не хватает. Приводим здесь, после каждого сохранения турнира.
	 */
	public function syncCourtNames(): void
	{
		$count = (int) $this->courts_count;
		if ($count <= 0) {
			return; // количество не задано — названия не трогаем
		}

		$courts = is_array($this->courts) ? $this->courts : [];
		// Лишние отбрасываем, недостающие добиваем пустыми — подпись
		// таких кортов сгенерирует getCourtName().
		$courts = array_slice($courts, 0, $count);
		$courts = array_pad($courts, $count, null);
		$courts = array_map(fn ($name) => $name !== null && $name !== '' ? $name : null, $courts);

		$this->update([
			'courts' => empty(array_filter($courts)) ? null : $courts,
		]);
	}
```

- [ ] **Step 4: Вызвать после сохранения в мобильном API**

В `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` сразу после `$tournament->update($validated);` (строка 233) добавить:

```php
        // Названия кортов приводим к их количеству: приложение названия не шлёт,
        // поэтому без этого массив расходится со счётчиком.
        $tournament->syncCourtNames();
```

- [ ] **Step 5: Вызвать после создания и обновления в вебе**

В `app/Http/Controllers/Club/TournamentController.php` после `$tournament = Tournament::create($validated);` (строка 187):

```php
        $tournament->syncCourtNames();
```

И после `$tournament->update($validated);` (строка 394):

```php
		$tournament->syncCourtNames();
```

- [ ] **Step 6: Запустить тесты и убедиться, что они проходят**

Run: `php artisan test --filter=TournamentCourtsCountTest`
Expected: PASS, 7 тестов.

- [ ] **Step 7: Прогнать смежные сьюты**

Run: `php artisan test --filter="Tournament|KingOfCourt|BaliKoc|JustPadelIt"`
Expected: новых падений нет. Помнить про известные давние падения (`AmericanoFlexServiceTest` про ELO, date-зависимые `TournamentReminders`).

- [ ] **Step 8: Коммит**

```bash
git add app/Models/Tournament.php app/Http/Controllers/Api/MobileAdminTournamentDetailController.php app/Http/Controllers/Club/TournamentController.php tests/Feature/TournamentCourtsCountTest.php
git commit -m "fix(tournaments): названия кортов приводятся к их количеству"
```

---

### Task 2: Приложение — поле кортов в экране редактирования

**Files:**
- Modify: `C:\projects\padel_app\lib\screens\admin\admin_tournament_detail_screen.dart`

**Interfaces:**
- Consumes: серверный приём `courts_count` для любого типа (уже работает)
- Produces: поле «Количество кортов» у всех типов кроме Americano Flex; автоподстановка от числа участников

- [ ] **Step 1: Создать ветку в репозитории приложения**

```bash
cd C:/projects/padel_app
git checkout -b feature/tournament-courts-count
```

- [ ] **Step 2: Добавить флаг ручной правки и автоподстановку**

Рядом с объявлением `_teamCourts` (строка 80) добавить поле состояния:

```dart
  // Админ правил число кортов руками — автоподстановка от участников
  // больше не перетирает его значение.
  bool _courtsTouchedManually = false;
```

В `initState`, где заполняются контроллеры (рядом со строкой 203), добавить слушатели:

```dart
    // Ручная правка поля кортов отключает автоподстановку. Полная очистка
    // поля возвращает автоматический режим.
    _teamCourts.addListener(() {
      final text = _teamCourts.text.trim();
      if (text.isEmpty) {
        _courtsTouchedManually = false;
      }
    });
    // Смена числа участников подставляет корты, пока админ их не трогал.
    _maxParticipants.addListener(_autofillCourts);
```

Добавить метод автоподстановки рядом с геттерами `_isFlex` / `_isJpiSolo` (около строки 484):

```dart
  /// Подставить число кортов от количества участников: 1 корт = 4 игрока.
  /// Не трогает поле, если админ уже вводил своё значение или это Flex —
  /// у Flex собственный блок кортов.
  void _autofillCourts() {
    if (_isFlex || _courtsTouchedManually) return;

    final maxP = int.tryParse(_maxParticipants.text.trim());
    if (maxP == null || maxP <= 0) return;

    final auto = (maxP / 4).ceil().clamp(1, 32);
    final next = '$auto';
    if (_teamCourts.text.trim() == next) return;

    // Программная подстановка не должна взводить флаг ручной правки.
    final wasTouched = _courtsTouchedManually;
    _teamCourts.text = next;
    _courtsTouchedManually = wasTouched;
  }
```

Отметить ручную правку в самом поле — в `onChanged` виджета поля кортов (шаг 4).

- [ ] **Step 3: Показать поле всем типам, кроме Flex**

Найти три блока «Количество кортов» (около строк 1349, 1382, 1449 — условия `_isJpiSolo`, `_isFlex` и командного турнира):

```bash
grep -n "Количество кортов" lib/screens/admin/admin_tournament_detail_screen.dart
```

Блок `_isFlex` оставить как есть. Блоки `_isJpiSolo` и командного объединить в один, показываемый при `!_isFlex` — чтобы поле было у всех остальных типов и не дублировалось. Разметку взять из существующего блока командного турнира (строки 1442-1462): заголовок «Количество кортов», `TextField` с контроллером `_teamCourts`, `enabled: !disabled`, числовая клавиатура, подсказка «оставьте пустым для авто».

Под полем — пояснение на русском:

```dart
                Text(
                  'Пусто — автоматически по числу участников (1 корт = 4 игрока).',
                  style: TextStyle(color: AppTheme.textDim, fontSize: 11),
                ),
```

- [ ] **Step 4: Взводить флаг при ручном вводе**

В этом же `TextField` добавить обработчик:

```dart
                  onChanged: (value) {
                    // Пустое поле возвращает автоматический режим.
                    _courtsTouchedManually = value.trim().isNotEmpty;
                  },
```

- [ ] **Step 5: Расширить условие отправки**

В вызове `updateTournament` (строки 294-298) заменить условие по трём типам на «все, кроме Flex»:

```dart
            courtsCount: t.type != 'americano_flex' &&
                    _teamCourts.text.trim().isNotEmpty
                ? int.tryParse(_teamCourts.text.trim())
                : null,
```

У Americano Flex число кортов задаётся своим блоком и отправляется отдельно — проверить, как именно оно уходит сейчас, и это поведение не менять.

- [ ] **Step 6: Проверить сборку**

Run: `flutter analyze lib/screens/admin/admin_tournament_detail_screen.dart`
Expected: без ошибок. Предупреждения, существовавшие до правки, допустимы.

APK не собирать.

- [ ] **Step 7: Пройти код глазами**

Проверить по коду два пути: открыли турнир на 16 участников — в поле кортов 4; изменили участников на 12 — стало 3; ввели руками 2 — изменение участников на 20 больше не перетирает 2; очистили поле — при следующем изменении участников снова подставилось автоматически.

- [ ] **Step 8: Коммит**

```bash
cd C:/projects/padel_app
git add lib/screens/admin/admin_tournament_detail_screen.dart
git commit -m "feat(tournaments): число кортов правится у всех типов кроме Flex"
```

---

### Task 3: Приложение — поле кортов в экране создания

**Files:**
- Modify: `C:\projects\padel_app\lib\screens\admin\admin_create_tournament_screen.dart`

**Interfaces:**
- Consumes: серверный приём `courts_count` при создании
- Produces: поле «Количество кортов» вместо статического текста для всех типов кроме Flex

- [ ] **Step 1: Заменить текст на поле ввода**

В `lib/screens/admin/admin_create_tournament_screen.dart` в блоке «Корты» (строки 1105-1133) ветка `else` сейчас выводит статический текст:

```dart
        Text(
          'Кол-во кортов: $_courtsCount  (автоматически: участников ÷ 4)',
          ...
        ),
```

Заменить на поле ввода с тем же контроллером `_teamCourts`, что уже используется для командного турнира, и подсказкой автозначения:

```dart
        _textField(
          _teamCourts,
          hint: '$_courtsCount',
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        ),
        const SizedBox(height: 4),
        Text(
          'Пусто — автоматически по числу участников (1 корт = 4 игрока): $_courtsCount.',
          style: TextStyle(color: AppTheme.textDim, fontSize: 11),
        ),
        const SizedBox(height: 12),
```

Проверить, что блок командного турнира (строки 1057-1070) при этом не дублирует то же поле — если дублирует, оставить только один.

- [ ] **Step 2: Учесть ручной ввод в расчёте**

Геттер `_courtsCount` (строки 106-121) сейчас читает `_teamCourts` только для типа `team`. Расширить на все типы кроме `americano_flex`:

```dart
  int get _courtsCount {
    if (_type == 'americano_flex') {
      // Flex: ручной ввод, иначе авто floor(игроки/4). Корт = 4 игрока.
      final manual = int.tryParse(_flexCourts.text.trim());
      if (manual != null && manual >= 1) return manual.clamp(1, _flexMaxCourts);
      return _flexMaxCourts;
    }
    final maxP = int.tryParse(_maxParticipants.text.trim()) ?? 16;
    // Ручной ввод кортов доступен всем типам, кроме Flex; пусто = авто.
    final manual = int.tryParse(_teamCourts.text.trim());
    if (manual != null && manual >= 1) return manual.clamp(1, 32);

    final n = (maxP / 4).ceil();
    return n.clamp(1, 32);
  }
```

- [ ] **Step 3: Проверить, что значение уходит на сервер**

В теле запроса создания (строка 278) уже передаётся `'courts_count': _courtsCount` — значит расширенный геттер подхватится автоматически. Убедиться, что это так, и что названия кортов (строка 240) генерируются по тому же `_courtsCount`.

- [ ] **Step 4: Проверить сборку**

Run: `flutter analyze lib/screens/admin/admin_create_tournament_screen.dart`
Expected: без ошибок.

- [ ] **Step 5: Пройти код глазами**

Проверить: выбрали короля корта, поставили 16 участников — подсказка показывает 4; ввели 3 — в запрос уходит 3 и генерируются три поля названий; выбрали Americano Flex — работает прежний блок Flex, ничего не сломалось.

- [ ] **Step 6: Коммит**

```bash
cd C:/projects/padel_app
git add lib/screens/admin/admin_create_tournament_screen.dart
git commit -m "feat(tournaments): число кортов вводится вручную при создании"
```

---

## Деплой

**Сервер:**

```bash
git pull
php artisan config:clear
```

Миграций нет, ассеты не менялись.

**Приложение:** правки попадут в следующую сборку. APK в рамках этой задачи не собираем.

После деплоя сервера уже испорченные записи выправятся сами при первом сохранении турнира. Чтобы починить всё разом, можно один раз выполнить на проде:

```sql
SELECT id, name, courts_count, JSON_LENGTH(courts) AS names
FROM tournaments
WHERE courts IS NOT NULL AND courts_count IS NOT NULL
  AND JSON_LENGTH(courts) <> courts_count;
```

Запрос покажет турниры с расхождением — их достаточно открыть и сохранить, либо сказать мне, и я подготовлю точечный UPDATE.

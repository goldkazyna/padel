# Перезапуск турнира (мобильное приложение) — дизайн

**Дата:** 2026-05-23
**Статус:** утверждён к реализации
**Затрагивает:** backend `C:\projects\padel` (Laravel) + mobile `C:\projects\padel_app` (Flutter)

## Цель

Дать админу/модератору клуба из мобильного приложения «перезапустить» уже запущенный турнир: вернуть его в статус набора (`open`), удалив сгенерированную сетку, чтобы поправить состав участников и запустить заново. Сценарий: турнир запустили заранее (видны корты), кто-то не пришёл — нужно заменить участника и стартовать снова.

## Решения (согласованы с заказчиком)

- Меню «три точки» — на **админском** экране турнира (`admin_tournament_detail_screen.dart`).
- В меню два пункта: **«Запустить турнир»** (дубль существующего запуска) и **«Перезапустить турнир»**.
- **«Перезапустить» активна только когда:** `status == 'in_progress'` И первый раунд НЕ завершён.
- Перезапуск: удаляет всю сетку/раунды/матчи/счёт (даже частичный), ставит `status = 'open'`; **участники и команды сохраняются**.
- Перед перезапуском — диалог подтверждения (необратимо).

## Что НЕ входит / важное

- Рейтинг начисляется только при `completed`, поэтому перезапуск до первого раунда рейтинг не затрагивает.
- Брони кортов (`court_bookings` / расписание) — отдельная сущность, при перезапуске **не трогаются**.
- Тип `classic` — legacy (новые не создаются); перезапуск чистит для него только playoff-матчи (см. ниже), отдельной сетки у него в текущем коде нет.

---

## Часть 1. Backend (Laravel)

### 1.1. `Tournament::firstRoundCompleted(): bool`
Файл: `app/Models/Tournament.php`. Определяет, пройден ли первый раунд, по `type`:
- `americano`: существует группа, у которой раунд `round_number = 1` имеет `status = 'completed'`.
- `mexicano`: `mexicanoRounds()` где `round_number = 1` имеет `status = 'completed'`.
- `king_of_court`: `kingofcourt_rounds` где `round_number = 1` `status = 'completed'`.
- `bali_koc`: `bali_koc_rounds` где `round_number = 1` `status = 'completed'`.
- `americano_flex`: `americano_flex_rounds` где `round_number = 1` `status = 'completed'`.
- `team`: есть хотя бы один `tournament_group_matches` со `status = 'completed'`.
- иначе (`classic`): есть хотя бы один `tournament_playoff_matches` со `status = 'completed'`.

Реализация должна использовать существующие связи модели (`groups`, `mexicanoRounds`, `playoffMatches` и т.п.); имена связей уточнить по факту в коде. Запросы — через `exists()`, без загрузки коллекций.

### 1.2. `Tournament::canRestart(): bool`
```
return $this->status === 'in_progress' && ! $this->firstRoundCompleted();
```
Также добавить `Tournament::canStart(): bool` если такого ещё нет (для дубля кнопки запуска): условие как в существующем `start` (status `open`/`closed`, мест набрано столько, сколько нужно). Если логика «можно ли запустить» уже выражена в контроллере `start`, вынести её в этот метод и переиспользовать.

### 1.3. `App\Services\TournamentResetService`
Новый сервис, метод `reset(Tournament $tournament): void`. Внутри `DB::transaction`. По `type` удаляет корневые сущности сетки (каскад БД сам чистит детей — у всех таблиц `onDelete cascade`), затем `tournament->update(['status' => 'open'])`.

Что удалять по типам (участники/команды НЕ трогаем):

| Тип | Удаляем (корневые; дети — каскадом) |
|---|---|
| americano | `tournament_groups` (→ rounds, matches, group_players) + `tournament_playoff_matches` |
| mexicano | `mexicano_rounds` (→ matches), `mexicano_pair_history`, `mexicano_players` |
| team | `tournament_team_groups` (→ standings, group_matches) + `tournament_playoff_matches` (teams сохраняем) |
| king_of_court | `kingofcourt_rounds` (→ matches), `kingofcourt_players` |
| bali_koc | `bali_koc_rounds` (→ matches), `bali_koc_pairs` |
| americano_flex | `americano_flex_rounds` (→ matches, byes), `americano_flex_pair_history`, `americano_flex_players` |
| classic | `tournament_playoff_matches` |

Удаление выполнять через Eloquent-связи модели (`$tournament->groups()->delete()` и т.п.), повторно используя существующие связи. Где связь только через промежуточную таблицу (например playoff по `tournament_id`) — удалять напрямую по `tournament_id`.

### 1.4. Endpoint перезапуска
`POST /api/mobile/admin/tournaments/{id}/restart` — в том же admin-контроллере, где `start` (рядом с `POST /admin/tournaments/{id}/start`). Под той же авторизацией/проверкой прав, что и `start`.
- Если `! $tournament->canRestart()` → `422` с сообщением `«Перезапуск недоступен: турнир не запущен или первый раунд уже сыгран»`.
- Иначе: `app(TournamentResetService::class)->reset($tournament)`, вернуть обновлённый турнир в том же формате, что отдаёт `start` (admin tournament detail payload).

### 1.5. Флаги в admin-payload турнира
В сериализацию админского турнира (тот payload, что использует `AdminTournamentDetail` во Flutter) добавить:
- `can_restart` = `$tournament->canRestart()`
- `can_start` = `$tournament->canStart()`

Чтобы Flutter не вычислял логику раундов на клиенте.

---

## Часть 2. Mobile (Flutter, padel_app)

### 2.1. `AdminService::restartTournament(int id)`
Файл: `lib/services/admin_service.dart` (рядом с `startTournament`, ~line 126).
```dart
Future<AdminTournamentDetail> restartTournament(int id) async {
  final token = await _storage.getToken();
  final response = await _api.post('/admin/tournaments/$id/restart', const {}, token);
  return AdminTournamentDetail.fromJson(response['tournament'] as Map<String, dynamic>);
}
```

### 2.2. Модель `AdminTournamentDetail`
Добавить поля `bool canRestart` и `bool canStart`, парсить из payload (`can_restart`, `can_start`; дефолт `false`).

### 2.3. AppBar админского экрана
Файл: `lib/screens/admin/admin_tournament_detail_screen.dart`. В правый верхний угол AppBar добавить `PopupMenuButton` (иконка трёх точек) с пунктами:
- **«Запустить турнир»** — `enabled: detail.canStart`; при выборе — существующая логика запуска (`AdminService.startTournament`).
- **«Перезапустить турнир»** — `enabled: detail.canRestart`; при выборе — диалог подтверждения, затем `restartTournament`.

Неактивные пункты — стандартно серые/`enabled:false`.

### 2.4. Подтверждение и обработка
Переиспользовать существующий `_confirm(...)` (в этом же экране, ~line 252):
```
title: «Перезапустить турнир?»
message: «Сетка и результаты будут удалены, участников можно будет изменить. Действие необратимо.»
okText: «Перезапустить», destructive: true
```
После подтверждения: вызвать `restartTournament`, по успеху — перезагрузить экран (turнир станет `open`, участники редактируемы), показать snackbar/сообщение об успехе; при ошибке (422) — показать сообщение от сервера.

### 2.5. Локализация
Добавить ключи в `lib/l10n/app_ru.arb`, `app_en.arb`, `app_kk.arb` (RU как канон):
- `restartTournament` — «Перезапустить турнир»
- `restartTournamentConfirmTitle` — «Перезапустить турнир?»
- `restartTournamentConfirmMessage` — «Сетка и результаты будут удалены, участников можно будет изменить. Действие необратимо.»
- `restartTournamentSuccess` — «Турнир перезапущен»
- (для дубля) `startTournament` — если ключа ещё нет.

Регенерировать локализации (`flutter pub get` / gen-l10n) по принятому в проекте процессу.

---

## Тестирование

### Backend (PHPUnit)
- `firstRoundCompleted`: для americano — false до завершения раунда 1, true после; аналогично для mexicano (минимум два типа покрыть).
- `canRestart`: true только при `in_progress` и незавершённом первом раунде; false для `open`, `completed`, и при завершённом раунде 1.
- `TournamentResetService::reset`: на americano-турнире с сгенерированной сеткой — после reset нет групп/раундов/матчей, `tournament_participants` на месте, `status='open'`. Для team — `tournament_teams` сохраняются, группы/плейофф удалены.
- Endpoint: `restart` возвращает 200 и `status=open` когда `canRestart`; 422 когда нельзя; недоступен без прав админа.

### Mobile
- Ручная проверка: меню «три точки» на админском экране; «Перезапустить» активна только для запущенного турнира до первого раунда; диалог подтверждения; после перезапуска статус `open` и участники редактируемы. (Автотесты Flutter — по принятому в проекте минимуму; основная проверка ручная в эмуляторе/устройстве.)

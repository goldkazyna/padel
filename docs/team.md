# Командный турнир (type=`team`) — Live и отображение

Документ описывает, как сейчас работает командный («Групповой + Плей-офф», пары
по 2 игрока) турнир в части отображения на главной, live-экрана, истории рейтинга
и верификации игроков. Обновлено: 21 июня 2026.

Бэкенд: `C:\projects\padel` · Приложение (Flutter): `C:\projects\padel_app`.

---

## 1. Главный экран — карточка «Активный турнир»

**Бэкенд:** `app/Http/Controllers/Api/MobileHomeController.php` → `getActiveTournament()`.

- Для командного турнира участники хранятся в `tournament_teams` (не в
  `tournament_participants`), поэтому он ищется отдельной веткой.
- Берём **все** id командных регистраций юзера (`player1_id`/`player2_id`, статус
  `approved`/`pending`) и среди них ищем турнир со `status='in_progress'`.

  > Раньше брался первый попавшийся `tournament_id` через `pluck()->first()` и
  > проверялся на `in_progress`. На аккаунте с несколькими командными
  > регистрациями это часто был id старого завершённого турнира → фильтр не
  > проходил → карточка не показывалась. Исправлено: собираем все id и фильтруем
  > по `in_progress` (по аналогии с `getNearestTournament`).

- Статус `in_progress` ставится при старте: `TeamTournamentService::startTournament()`
  (и `startTournamentWithAssignments()`). Статус команд при старте **не меняется**
  — остаётся `approved`.

**Приложение:** `lib/screens/home_screen.dart` + `lib/widgets/home/active_tournament_card.dart`.

- Карточка универсальна (не фильтрует по типу). Тап по живому турниру
  (`status == 'in_progress'`) вызывает `openTournamentLiveByType(...)`.

---

## 2. Навигация на Live-экран

**Приложение:** `lib/utils/tournament_navigation.dart` → `openTournamentLiveByType()`.

Единый роутер по типу турнира:

| type | Экран |
|------|-------|
| `mexicano` | `TournamentLiveMexicanoScreen` |
| `team` | `TournamentLiveTeamScreen` (+ `highlightPlayerId`) |
| `king_of_court` / `round_robin` | `TournamentLiveKingOfCourtScreen` |
| `bali_koc` | `TournamentLiveBaliKocScreen` |
| `americano` / `americano_flex` / прочее | `TournamentLiveScreen` |

Параметр `highlightPlayerId` прокидывается в `team`, `king_of_court`, `bali_koc` и
общий экран. Используется при открытии из чужого профиля.

---

## 3. Live-экран командного турнира

**Бэкенд:** `MobileTournamentController` → `live()` → `liveTeam()`
(`GET /api/mobile/tournaments/{id}/live`).

Возвращает:
- `tournament` — name, club_name, format_name, status, has_playoff;
- `groups[]` — `standings[]` (таблица команд) и `rounds[]` (матчи по турам);
- `playoff[]` — стадии плей-оффа (через `getPlayoffForLive()`).

Таблица команд сортируется через `TeamTournamentService::getSortedStandings()`
(учитывает личную встречу при равных очках, как в админке).

Каждый матч содержит `team1`/`team2` с `player1`/`player2`, `score`, `has_me`,
`court_number`, `status`, `my_rating_change` (личная дельта рейтинга по матчу для
авторизованного зрителя, если турнир рейтинговый).

**Приложение:** `lib/screens/tournament_live_team_screen.dart`.

- Две вкладки: **Раунды** и **Таблица**; переключатель групп, если групп > 1.
- Раунд раскрывается/сворачивается; активный раунд (`in_progress`) раскрыт по
  умолчанию.
- Внизу секция **Плей-офф**, если он есть.
- Тап по имени/аватару игрока → `PlayerProfileScreen`.

---

## 4. Верификация игрока (бейдж)

**Бэкенд:** в `liveTeam()` хелпер `$fmtPlayer` и в `getPlayoffForLive()` хелпер
`$fmtP` возвращают игрока с полем **`verified`** (из `User::level_verified`,
boolean). Ключ называется `verified` — единая конвенция API (как в americano live).

**Приложение:** виджет `lib/widgets/verified_badge.dart` (`VerifiedBadge`).

- Показывается синяя галочка рядом с именем, **только если `verified == true`**.
- Бейдж присутствует в **таблице команд**, **раундах** и **плей-офф** (все идут
  через хелперы `_standingName` / `_matchName` в team-экране).
- Тап по бейджу открывает модалку «кто верифицировал» (`LevelVerificationSheet`).

---

## 5. Подсветка команды (свой / чужой профиль)

`TournamentLiveTeamScreen` принимает `highlightPlayerId`:

- **Не задан** (главная, свой профиль): подсветка строк таблицы и матчей берётся
  из `has_me` (бэкенд считает по авторизованному пользователю). Показываются чип
  «Вы играете» и личная дельта рейтинга `_MatchRatingPill`.
- **Задан** (открыто из чужого профиля): подсветка считается на клиенте по
  **id игроков команды** (`_isHighlightTeam`), а `has_me` игнорируется. Чип
  «Вы играете» и личная дельта **скрыты** — они относятся к «мне», а не к
  просматриваемому игроку.

Логика в `_buildMatch`: разделены `highlight` (тинт фона) и `isMe`
(чип/дельта). В `_stRow` так же: `hasMe = highlightPlayerId != null ?
_isHighlightTeam(p1,p2) : s['has_me']`.

---

## 6. История рейтинга в профиле игрока

**Свой профиль:** `lib/widgets/profile/tournament_history.dart` →
`openTournamentLive()` → `openTournamentLiveByType()`.

**Чужой профиль:** `lib/screens/player_profile_screen.dart` → `_buildHistoryRow()`.

- Тап по турниру в истории рейтинга использует тот же `openTournamentLiveByType()`,
  что и свой профиль (раньше был кастомный `switch`, где `team` попадал в
  `default` → старый `TournamentResultsScreen` и отображался криво).
- Передаётся `highlightPlayerId: widget.playerId` — командный турнир открывается в
  `TournamentLiveTeamScreen` с подсветкой команды просматриваемого игрока.

Поле `is_paired` (для парного флекса) и расчёт «места по паре» — см. историю
коммитов `fix(flex): …` (отдельная фича, не относится к `team`).

---

## 7. Деплой

Бэкенд-правки чистые (без миграций):

```bash
git pull origin main
php artisan config:clear
```

Приложение — пересобрать сборку (APK/IPA) при необходимости.

# Americano Flex Paired (парный флекс) — план реализации

**Идея:** парная версия Americano Flex. Пары ФИКСИРОВАНЫ (партнёр не меняется),
ротируются только соперники и отдых. Игроки записываются по одному, админ
распределяет пары, затем флекс-раунды где каждая пара = атомарная команда.

## Архитектурное решение

**НЕ новый тип, а флаг `is_paired` на `type='americano_flex'`.** Так
переиспользуются: live-экран, история профиля, ввод счёта, рейтинг, отображение
у админа — всё «как обычный флекс».

**Распределение пар = готовый механизм `pairing_mode=admin`** (экран «Сбор пар»
web+mobile, таблица `tournament_teams`, `TeamTournamentService::getPairingState/
createPair/autoBalancePairs`). Они универсальны (`max_participants/2`), не завязаны
на старт team-турнира. Парный флекс ставит `pairing_mode='admin'`.

**На уровне матча парный флекс ИДЕНТИЧЕН обычному флексу:** `americano_flex_matches`
хранит 4 user_id (team1_player1/2, team2_player1/2) + счёт. Поэтому ввод счёта,
рейтинг (`completeTournament`), история (`getMatchHistory` уже включает
AmericanoFlexMatch), live — РАБОТАЮТ КАК ЕСТЬ. Отличия только в: создании,
старте/генерации раундов, расписании, группировке лидерборда по парам.

## Решения (согласованы с пользователем)

- Лидерборд: **по парам** (одна строка = пара).
- Рейтинг: **да, как во флексе** (Elo на обоих игроков пары), учитывает флаг
  «Рейтинговый турнир» (`is_rated`).
- Плей-оффа НЕТ (как обычный флекс), итог по среднему за матч у пары.
- Чётное число игроков — обязательная проверка (пары закреплены).
- Распределение: solo-регистрация → админ собирает пары → старт.

## Логика расписания (ядро)

P пар (P = игроки/2), C кортов. Каждый раунд: C матчей → играют 2C пар,
отдыхают P−2C пар.
- 10 игроков = 5 пар, 2 корта → 2 матча, 1 пара отдыхает, 5 раундов = каждая
  пара встречает каждую + отдыхает 1 раз.
- 12 игроков = 6 пар, 2 корта → 2 матча, 2 пары отдыхают.

Приоритеты (как в обычном флексе): 1) ровный отдых + минимум отдыха подряд
(жёсткое правило «отдохнул → играешь»); 2) разнообразие соперников.

**Таблицы:** `database/data/americano_flex_paired_schedules.json`, ключ «P-C»
(пары-корты), раунды = matches [[pairSlotA,pairSlotB],...] + bye pair slots.
Генератор офлайн (по образцу flex_schedule_gen.php, но пары = атомы — проще, это
round-robin с байями на C кортов). Алгоритм-фолбэк для конфигов без таблицы.

## Фазы

### Phase 1 — фундамент бэкенда
- [ ] migration: `tournaments.is_paired` boolean default false
- [ ] Tournament: `is_paired` в fillable+casts; `isPairedFlex()`; relax
  `isAdminPairing()` → team-admin ИЛИ paired-flex; `usesSoloRegistration()` ок
- [ ] Создание (web Club\TournamentController + mobile MobileAdminTournamentController):
  принять `is_paired`; для paired flex форсить `pairing_mode='admin'`;
  валидация чётности `max_participants`

### Phase 2 — старт + генерация раундов
- [ ] AmericanoFlexService: при старте paired — роутер = пары из tournament_teams
  (approved), фиксируем AmericanoFlexPlayer на всех игроков пар
- [ ] tableRound/generateNextRound: ветка paired — слоты = пары, matches = pairA
  vs pairB, byes = оба игрока отдыхающих пар. Загрузка paired-таблиц + фолбэк

### Phase 3 — расписание
- [ ] генератор `flex_paired_gen.php` (untracked) + verify
- [ ] `americano_flex_paired_schedules.json` для популярных P-C (3-1,4-1,5-2,6-2,
  7-2,8-2,5-1,6-3,7-3,8-3,...) — приоритет ровного отдыха

### Phase 4 — лидерборд по парам
- [ ] liveAmericanoFlex + admin leaderboard: при is_paired группировать строки по
  паре (имя «A + B», очки/среднее/отдых пары). Признак paired в ответе live

### Phase 5 — веб
- [ ] _americano_flex.blade.php: лидерборд по парам если is_paired; «Сбор пар»
  ссылка/кнопка для paired (как team-admin)

### Phase 6 — мобилка
- [ ] admin_create_tournament_screen: чекбокс «Парный» у americano_flex + поле
  кортов + валидация чётности; payload is_paired
- [ ] tournament_live_screen: лидерборд по парам при paired
- [ ] admin_tournament_detail: flex-рендер + переход на экран «Сбор пар» для paired
- [ ] регистрация: solo (usesSoloRegistration уже true)

### Phase 7 — история/рейтинг
- РАБОТАЕТ КАК ЕСТЬ (AmericanoFlexMatch уже в getMatchHistory/getAllMatchesStats,
  completeTournament начисляет рейтинг). Проверить отображение пар в результатах.

## Ключевые файлы (из разведки)
- Service: app/Services/AmericanoFlexService.php (tableRound, generateNextRound,
  startTournament, saveMatchResult, completeTournament, getLeaderboard)
- Pairing: app/Services/TeamTournamentService.php (getPairingState/createPair/
  autoBalancePairs — переиспользуем)
- Live: MobileTournamentController::liveAmericanoFlex (~2439)
- Admin score: MobileAdminTournamentDetailController americano_flex score (~3008)
- Web: resources/views/club/tournaments/partials/_americano_flex.blade.php
- Mobile live: lib/screens/tournament_live_screen.dart (_buildFlexLeaderboard)
- Mobile create: lib/screens/admin/admin_create_tournament_screen.dart
- Mobile pairing: lib/screens/admin/admin_pairing_screen.dart
- Tables: database/data/americano_flex_schedules.json (образец)

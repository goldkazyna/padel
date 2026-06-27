# Король корта с фиксированными парами

**Дата:** 2026-06-27
**Тип:** `king_of_court` + флаг `is_paired = true` (без нового типа турнира)

## Идея

Вариант «Король корта», где игроки регистрируются по одному, затем админ
собирает **фиксированные пары**, и турнир идёт парами (пары не перемешиваются
между раундами). Счёт, очки и ELO — как в обычном Короле корта. Турнирная
таблица отображается по парам.

Отличие от **Bali KOC**: Bali считает по геймам и даёт «очки за место корта»
(N+2−K); здесь — обычная КК-механика (счёт по мячам, `total_points` = сумма
набранных очков). Отличие от обычного **КК**: пары фиксированы (не миксуются),
таблица по парам.

Решения (согласовано): счёт/очки как в обычном КК; оформляем галочкой
`is_paired` в Короле корта (переиспользуем существующую колонку), не новым типом.

## Данные

- **Новая таблица `kingofcourt_pairs`**: `id, tournament_id (FK), player1_id (FK users), player2_id (FK users), timestamps`, индекс по `tournament_id`. Модель `KingOfCourtPair` (хелперы `display_name`).
- Переиспользуем колонку `tournaments.is_paired` (boolean). Новый хелпер
  `Tournament::isPairedKingOfCourt(): bool` = `isKingOfCourt() && is_paired`.
- `kingofcourt_matches` НЕ меняем: в paired-режиме команда матча = пара
  (team1_player1/2 = пара A; team2_player1/2 = пара B). Per-player статистика и
  ELO работают без изменений; у двух игроков одной пары статистика идентична.

## Логика — `KingOfCourtService` (ветки по `is_paired`)

- `createPairs(Tournament, array $pairs): array{0:bool,1:string}` — валидация:
  4×N игроков (кратно 4), ровно `participants/2` пар, каждый игрок ровно в одной
  паре, все зарегистрированы; сохраняет `KingOfCourtPair`. По образцу
  `BaliKocService::createPairs`.
- `arePairsCreated(Tournament): bool`.
- `startTournament` (paired-ветка): требует созданные пары (иначе `false`);
  создаёт `KingOfCourtPlayer`; раскидывает **пары** случайно по кортам (2 пары
  на корт); матч: team1 = пара A (оба игрока), team2 = пара B.
  Новый helper `createRoundFromPairs(tournament, roundNumber, courts)`, где
  каждый корт = `[pairA, pairB]`.
- `generateNextRound` (paired-ветка): ротация как в Bali (победившая пара ↑,
  проигравшая ↓; корт 1 — победители k0+k1; средние — loser(i-1)+winner(i+1);
  последний — loser(N-2)+loser(N-1)), **пары целые, без shuffle**.
- Очки/ELO/`finishTournament` — без изменений (общий код КК).

## Таблица по парам

- `getPairStandings(Tournament): array` — для каждой `KingOfCourtPair` суммируем
  стат двух её игроков из `KingOfCourtPlayer` (`total_points`, `wins`, `losses`,
  `points_for`, `points_against`), сортируем как КК: `total_points` DESC →
  `(points_for - points_against)` DESC → win% DESC.
- Лидерборд-вью ветвится: `is_paired` → строки по парам; иначе — по игрокам (как сейчас).

## Создание турнира

- **Веб** `create.blade.php`: в блоке King of Court — чекбокс «Фиксированные
  пары» (`name=is_paired`). Описание: после набора админ создаёт пары.
- **Мобайл API** `MobileAdminTournamentController::finalizeTournamentCreate`:
  разрешить `is_paired` для `king_of_court` (сейчас обрабатывается только для
  `americano_flex`). `pairing_mode` для КК не выставляем.
- Старт (веб `TournamentController::start`, мобайл
  `MobileAdminTournamentDetailController::start`): если KOC + `is_paired` и пар
  нет → не стартуем, возвращаем ошибку с флагом `pairs_required` (как у Bali).

## Контроллеры и маршруты

- **Веб** `KingOfCourtController`: `pairs(Tournament)` (форма создания пар),
  `storePairs(Request, Tournament)` (сохранение через сервис). Маршруты
  `GET/POST /kingofcourt/{tournament}/pairs` (по образцу Bali).
- **Мобайл** `MobileAdminTournamentDetailController`: `kocPairs()` (получить
  состояние пар), `saveKocPairs()` (сохранить); старт с проверкой пар; в `show`/
  standings отдаём таблицу по парам, когда `is_paired`. Маршруты в `routes/api.php`
  по образцу `bali_koc/pairs`.

## Вьюхи (веб)

- `kingofcourt/pairs.blade.php` — создание пар (паттерн `bali_koc/pairs.blade.php`:
  2 селекта на пару, кнопка «Авто: сильный + слабый», запрет дублей через JS).
- `kingofcourt/partials/_leaderboard.blade.php` — ветка «по парам» (как в Bali
  leaderboard: два аватара, имена обоих).
- `kingofcourt/partials/_header.blade.php` — для paired показывать «Создать пары»
  (когда набран полный состав, без резервов); старт доступен после создания пар.
- `kingofcourt/partials/_rounds.blade.php` — составы отображаются как пары
  (team1/team2 — это пары; визуально допустимо оставить как есть, 2+2 игрока).

## Вне области (YAGNI)

- Не трогаем Bali KOC и обычный (соло) КК — соло-режим обязан работать как раньше.
- Flutter-UI приложения (`padel_app`) — отдельная задача; здесь только backend + API.
- Без нового значения `type` в enum.

## Тесты (TDD)

- paired KOC: старт без пар → блок (`pairs_required`/`false`); с парами → раунд 1,
  команды матчей = пары.
- ротация paired: между раундами пары не распадаются (та же пара играет вместе).
- `getPairStandings`: сумма стат двух игроков пары, корректная сортировка.
- очки/ELO в paired совпадают с обычным КК (соло-режим не задет — отдельный тест,
  что соло-старт и ротация работают по-прежнему).
- `createPairs`: валидация (дубли игрока, неверное число пар).

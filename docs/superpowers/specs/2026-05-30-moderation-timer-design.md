# Таймер модерации заявок на турнир — дизайн

**Дата:** 2026-05-30
**Статус:** согласован к реализации
**Затрагивает:** бэкенд `C:\projects\padel` (web + mobile API + планировщик) и приложение `C:\projects\padel_app`.

## Проблема и цель

Игроки записываются на турнир и «висят» в модерации сколько угодно, занимая место, пока в листе ожидания есть готовые играть. Нужен **дедлайн оплаты**: если организатор не одобрил заявку (= оплата не подтверждена) за заданное время — заявка авто-отменяется, а первый из листа ожидания автоматически встаёт на модерацию.

«Оплата» = офлайн; подтверждается **одобрением админа** (`pending → registered`). Онлайн-оплаты турниров в проекте нет и в этой задаче не делаем.

## Согласованные решения

- Поле таймера у турнира — в **часах**, необязательное (`null`/0 = бессрочно, как сейчас).
- Таймер **персональный**: стартует в момент, когда участник/пара становится `pending`.
- На просрочке статус → terminal (историю сохраняем, не удаляем строку): solo → **`cancelled`**, пары → **`rejected`** (своя строковая колонка статуса). Игрок может **записаться снова** — повторная запись переводит его строку обратно в `pending` со свежим дедлайном (или в лист ожидания, если мест нет).
- При авто-отмене — **авто-продвижение первого из листа ожидания** (FIFO) в `pending` со своим свежим таймером.
- Работает для **всех типов** турниров: solo (`tournament_participants`) и парные (`tournament_teams`).
- **Без вебсокетов**: отсчёт тикает в приложении локально от дедлайна. Сервер раз в минуту чистит просрочку (cron).
- Пуши: при подаче/продвижении, напоминание перед истечением, при удалении.

## Часть 1. Модель данных

- `tournaments.moderation_hours` — `unsignedInteger` nullable. `null`/0 = таймер выключен.
- `tournament_participants` (pivot, solo): `moderation_deadline` (`timestamp` nullable), `reminder_sent_at` (`timestamp` nullable).
- `tournament_teams`: `moderation_deadline` (`timestamp` nullable), `reminder_sent_at` (`timestamp` nullable).

Дедлайн **храним явно** (а не считаем от времени записи): при продвижении из листа ожидания таймер должен стартовать заново в момент продвижения.

## Часть 2. Где выставляется дедлайн

При переходе в статус `pending` и включённом таймере `moderation_deadline = now + moderation_hours`, `reminder_sent_at = null`:
- самозапись solo (`MobileTournamentController@register`) и web-запись (`Club\TournamentController`);
- запись пары (`registerTeam`) — на `tournament_teams`;
- принятие приглашения (`MobileTournamentInvitationController@accept`);
- продвижение из листа ожидания (планировщик).

При одобрении админом (`approveParticipant` / approveTeam): статус → `registered`/`approved`, `moderation_deadline = null` (таймер снят).

Повторная запись после `cancelled`: обновляем существующую строку (`updateExistingPivot` / `team->update`) обратно в `pending` + новый дедлайн (а не вставка — иначе нарушим unique). Если мест нет — в лист ожидания (`waiting`), без таймера.

Хелпер `Tournament::moderationDeadline(): ?Carbon` (вернёт `now()->addHours(moderation_hours)` или `null`) — единая точка расчёта.

## Часть 3. Планировщик (cron)

Artisan-команда `tournaments:process-moderation`, регистрируется в `bootstrap/app.php` через `->withSchedule(fn($s) => $s->command('tournaments:process-moderation')->everyMinute()->withoutOverlapping())`.

Логика за один прогон — по каждому **открытому** турниру с `moderation_hours`:
1. **Просрочка** (`status = pending`/team `pending`, `moderation_deadline <= now`): в транзакции:
   - **запоминаем первого в листе ожидания ДО любых изменений** (`status = waiting`, по `created_at` FIFO; для пар — первую `waiting`-команду);
   - если у турнира **есть лист ожидания** (`waitlist_size > 0`) → просрочившего **переносим в конец листа ожидания** (`status = waiting`, `created_at = now`, дедлайн/напоминание сброшены) → пуш «перемещены в лист ожидания» (`tournament_moderation_demoted`). Если листа ожидания **нет** → статус `cancelled` (solo) / `rejected` (team) → пуш «вас убрали» (`tournament_moderation_expired`);
   - **запомненного** первого из листа ожидания (если был) переводим в `pending` + новый дедлайн → пуш «ваша очередь, оплатите до …». Если лист был пуст — продвигать некого, просрочивший просто остаётся в листе ожидания (даже при свободных местах). Запоминание ДО переноса не даёт просрочившему продвинуть самого себя обратно.
2. **Напоминание**: если `reminder_sent_at IS NULL` и осталось ≤ 20% окна (минимум — за 30 минут до дедлайна) → пуш-напоминание, выставить `reminder_sent_at = now`.

Команда идемпотентна и безопасна при частых запусках (`withoutOverlapping`).

⚠️ **Прод:** требуется один системный cron `* * * * * php artisan schedule:run`. Сейчас планировщик в проекте не настроен — добавляем `->withSchedule(...)` в `bootstrap/app.php`.

## Часть 4. Пуши (существующие `Notification` + `FCMNotificationService`)

| Событие | type | Текст |
|--------|------|-------|
| Подача заявки / продвижение из листа | `tournament_moderation_pending` | «Вы на модерации: {турнир}. Оплатите до {дата}» |
| Напоминание | `tournament_moderation_reminder` | «Осталось {время} — оплатите, иначе заявку снимут» |
| Авто-отмена | `tournament_moderation_expired` | «Заявка на {турнир} снята — оплата не поступила вовремя» |

`data`: `{ tournament_id }`. Тап → деталка турнира (уже маршрутизируется по `tournament_id`).

## Часть 5. API

- `formatTournament` (трейт `FormatsTournaments`) при `includeRegistration=true`: в блок регистрации добавить `moderation_deadline` (ISO, либо null) — для countdown в «Мои турниры» и деталке.
- Админский список участников (`participants`) и `formatUser`: к pending-записям добавить `moderation_deadline` — админ видит остаток.
- Tournament create/update (mobile `MobileAdminTournamentController`, web `Club\TournamentController`): валидируем и сохраняем `moderation_hours` (`nullable|integer|min:0|max:720`).

## Часть 6. Приложение (Flutter)

- **Создание турнира** (`admin_create_tournament_screen`): поле «Таймер модерации, часов» (необязательное, 0/пусто = без таймера). Передаём в `createTournament`.
- **Модель** `Tournament`: поле `moderationDeadline` (DateTime?) в блоке регистрации; админская `AdminParticipant`: `moderationDeadline`.
- **Countdown-виджет** `ModerationCountdown(deadline)`: `Timer.periodic` (1 сек), показывает «1д 23ч 50м», при ≤0 — «время вышло». Без сети.
- **Мои турниры / деталка турнира**: на своей `pending`-регистрации — плашка с countdown «Оплатите в течение …».
- **Админ, участники**: рядом с pending — остаток времени (тот же виджет, компактный).

## Часть 7. Тестирование (PHPUnit)

- Команда снимает просроченный `pending` в `cancelled` и продвигает первого `waiting` в `pending` с новым дедлайном.
- Не трогает заявки с `deadline` в будущем; не трогает турниры без `moderation_hours`.
- Одобрение до дедлайна снимает таймер (`moderation_deadline = null`), команда такого не трогает.
- Напоминание ставится один раз (`reminder_sent_at`), повторно не шлётся.
- Повторная запись после `cancelled` снова делает `pending` + дедлайн.
- Регистрация при включённом таймере проставляет `moderation_deadline`; админское прямое добавление (`registered`) — нет.
- Парный сценарий: просрочка `tournament_teams` → `rejected` + продвижение `waiting`-команды в `pending`.

## Не входит

- Онлайн-оплата турниров.
- Вебсокеты / реалтайм-пуш таймера.
- Настраиваемое число/тайминг напоминаний (одно, фиксированное правило).

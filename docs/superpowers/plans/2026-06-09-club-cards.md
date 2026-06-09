# Клубные карты — план реализации

> Спек: `docs/superpowers/specs/2026-06-09-club-cards-design.md`. Реализуем поэтапно,
> каждый этап = свои коммиты + проверка вживую. Стек: Laravel 12 + Blade, клубная админка.

**Цель:** система клубных карт (типы, выпуск клиентам, авто-списание по броням, журнал).

---

## Этап 1 — Данные + типы карт (CRUD в меню)

### Task 1.1 — Миграции
**Создать:**
- `database/migrations/2026_06_09_000001_create_club_card_types_table.php`
- `database/migrations/2026_06_09_000002_create_club_cards_table.php`
- `database/migrations/2026_06_09_000003_create_club_card_transactions_table.php`
- `database/migrations/2026_06_09_000004_add_club_card_to_court_bookings.php`

Поля — по спеку. `club_card_types`: club_id, name, kind(enum), nominal(nullable),
discount_percent(nullable), default_validity_days(nullable), is_active(default true).
`club_cards`: club_id, club_card_type_id, club_client_id, code(unique), balance(nullable),
initial_balance(nullable), expires_at(date nullable), status(enum active/archived default active).
`club_card_transactions`: club_id, club_card_id, court_booking_id(nullable, nullOnDelete),
amount(int), balance_after(int), note(nullable), timestamp created_at (only).
`court_bookings`: + club_card_id(fk nullable nullOnDelete), + card_charged_at(timestamp nullable).

### Task 1.2 — Модели
**Создать:** `app/Models/ClubCardType.php`, `app/Models/ClubCard.php`, `app/Models/ClubCardTransaction.php`.
- `ClubCardType`: fillable; relations club(), cards(); helper `isCounter()` (kind in visits/trainer),
  `isDiscount()`; scope active.
- `ClubCard`: fillable; relations type(), client(), transactions(); accessor `isActual()` =
  status=active && (expires_at null || future) && (!isCounter || balance>0); helper `isCounter()` via type.
- `ClubCardTransaction`: fillable; relations card(), booking().
- `CourtBooking`: add `clubCard()` relation + club_card_id, card_charged_at to fillable/casts.

### Task 1.3 — Сервис (заготовка) + генерация кода
**Создать:** `app/Services/ClubCardService.php`. Метод `generateCode(): string` — уникальный 8-симв.

### Task 1.4 — Контроллер типов + роуты + меню
**Создать:** `app/Http/Controllers/Club/ClubCardTypeController.php` (index/store/update/destroy).
**Изменить:** `routes/web.php` (группа club, feature-gate если нужно), `resources/views/layouts/app.blade.php`
(пункт меню «Клубные карты»).
**Создать вью:** `resources/views/club/cards/index.blade.php` (список типов + модал создания/правки;
на этом этапе ещё без «выпущенных», добавим в Этапе 2).
- destroy: блок если есть активные `club_cards` этого типа → flash error «сначала отвяжите карты».

### Task 1.5 — Тест Этапа 1
**Создать:** `tests/Feature/ClubCardTypeTest.php` — создание типа, нельзя удалить тип с активной картой.

**Проверка вживую:** меню «Клубные карты» → создать тип каждого вида → удалить.

---

## Этап 2 — Выпуск карт клиентам + статистика + отвязка

### Task 2.1 — `ClubCardService::issue($client, $type, $balanceOverride, $expiresAt)`
Генерит code, ставит balance (override ?? type.nominal для счётчиков), initial_balance, expires_at.
Тест: выпуск из номинала и с override.

### Task 2.2 — Контроллер карт + выпуск/отвязка
**Создать:** `app/Http/Controllers/Club/ClubCardController.php` (store=выпуск, destroy=отвязка/удаление).
- destroy: если balance>0 (счётчик) — предупреждение (через confirm на фронте), но удаление разрешено.

### Task 2.3 — Блок в карточке клиента
**Изменить:** `resources/views/club/clients/show.blade.php` (или edit) — блок «Клубные карты»:
карты клиента с остатком, кнопка «Привязать карту» (модал: тип, остаток, срок), история по карте.

### Task 2.4 — Раздел «Клубные карты» статистика
**Изменить:** `club/cards/index.blade.php` — счётчики «выпущено всего» / «актуально сейчас»;
список выпущенных карт по клиентам (drill-down).

**Проверка:** привязать карту клиенту, увидеть в карточке клиента и в разделе.

---

## Этап 3 — Интеграция с бронью

### Task 3.1 — Данные карты клиента в окно брони
**Изменить:** `CourtController::schedule` — отдать карты клиентов (или подгружать AJAX по выбору клиента).
Проще: AJAX-эндпоинт `GET /club/clients/{client}/cards` → актуальные карты.

### Task 3.2 — UI выбора карты в окне брони/редактирования
**Изменить:** `schedule.blade.php` — после выбора клиента показать «Клубная карта» (актуальные),
остаток; для discount — применить % к цене; ставить `club_card_id`. Только не-групповые.

### Task 3.3 — Сохранение `club_card_id` в store/update
**Изменить:** `CourtController::store/update` — валидировать club_card_id (принадлежит клубу/клиенту),
для discount применить скидку.

**Проверка:** бронь с картой-счётчиком (привязалась), бронь со скидочной (цена упала).

---

## Этап 4 — Крон авто-списания + журнал

### Task 4.1 — `ClubCardService::chargeBooking($booking)`
Списывает по длительности (round((end-start)/60), min 1, min(amount,balance)), ставит card_charged_at,
пишет transaction. Идемпотентно (если card_charged_at задан — пропуск).
Тест: списание по 1ч и 2ч, защита двойного списания, скидочные не списываются.

### Task 4.2 — Команда `cards:charge-due`
**Создать:** `app/Console/Commands/ChargeDueCards.php` — выбрать подходящие брони (confirmed,
club_card_id, счётчик, card_charged_at null, end<now), вызвать chargeBooking. Зарегистрировать hourly.

### Task 4.3 — Раздел «Журнал карт»
**Создать:** `ClubCardJournalController` + `club/cards/journal.blade.php` + пункт меню.

**Проверка:** провести бронь, дождаться/прогнать команду, увидеть списание в журнале и остаток.

---

## Тесты (итог)
`tests/Feature/`: ClubCardTypeTest (Этап 1), ClubCardIssueTest (Этап 2),
ClubCardChargeTest (Этап 4: списание, двойное списание, скидочные).

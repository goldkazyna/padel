# Заморозка и пробная тренировка в групповых занятиях

**Дата:** 2026-06-26
**Страницы:** `/club/groups/{group}` (карточка группы), `/club/group-sessions/{session}` (проведение занятия)

## Контекст текущего флоу

- Участник группы (`club_group_members`) покупает пакеты занятий (`club_group_enrollments`, поле `sessions`).
- Остаток = `сумма enrollments.sessions − count(attendance где charged=true)` (`ClubGroupMember::getRemainingAttribute`).
- При проведении занятия (`GroupSessionController::conduct`) для каждого участника отмечают `attended` и `charged`; `charged=true` тратит занятие из пакета.
- Выплата тренеру (`CoachesReportService::payoutTotals`) считается от факта `session.status='held'` × ставка — **не зависит** от посещаемости/списаний.

Заморозки и пробных в коде нет — добавляем с нуля.

## Решения (согласовано)

- **Заморозка** = пауза на период (даты). В эти дни участник не списывается и не считается активным. Авто-разморозка по дате окончания. Возможны несколько периодов + история.
- **Пробная** = два сценария: новый член группы с пробным первым занятием и гость (не член). Сумма указывается при отметке (0 = бесплатно), оплачивается отдельно, пакет не тратит.

## Модель данных

### Новая таблица `club_group_member_freezes`
```
id
group_member_id  FK → club_group_members (cascade delete)
freeze_from      date
freeze_until     date
note             string nullable
created_by       FK → users nullable
timestamps
```
Модель `ClubGroupMemberFreeze`. Участник заморожен на дату D, если есть строка с `freeze_from ≤ D ≤ freeze_until`.

Хелпер на `ClubGroupMember`:
```php
public function isFrozenOn($date): bool   // есть активный фриз на дату
public function activeFreezeOn($date): ?ClubGroupMemberFreeze
```

### Расширение `club_group_attendance`
- `is_trial`        boolean default false
- `trial_amount`    integer nullable  (сумма за пробную; 0 = бесплатно)
- `group_member_id` → делаем **nullable** (у гостя нет членства)
- `client_id`       FK → club_clients nullable (пробный гость)

Инвариант строки: задан ровно один из `group_member_id` / `client_id`.
Остаток (`getRemainingAttribute`) **не меняется** — у пробных и замороженных `charged=false`.

## Заморозка — поведение

1. Карточка группы (`groups/show.blade.php`): у каждого участника кнопка «Заморозить» → форма (с / по / заметка) → `POST /groups/{group}/members/{member}/freeze`. Список текущих/будущих заморозок со «снять» → `DELETE /groups/{group}/members/{member}/freezes/{freeze}`.
2. Проведение занятия (`conduct`): для участника, замороженного на `session.date`:
   - в UI галка «списать» заблокирована, метка «заморожен до DD.MM»;
   - на сервере списание принудительно `charged=false` (защита от обхода формы).
3. Цена аренды корта (`court_bookings.price` групповой брони) **не пересчитывается** при заморозке — заморозка влияет только на списание у клиента.

## Пробная — поведение

1. **Член с пробным первым занятием:** участник уже в группе (0 в пакете). На странице занятия у него тумблер «пробное» + поле суммы. Результат: `attendance.is_trial=true, trial_amount=N, charged=false`. Пакет не тратится.
2. **Гость:** на странице занятия блок «+ Пробный гость» → поиск по `club_clients` клуба + сумма → `attendance` с `client_id`, `is_trial=true`, `attended=true`. В группу не добавляется.
3. Доход клуба за занятие на карточке = `списано × price_per_session` + `сумма trial_amount`.

## conduct() — изменения

- Валидация: замороженному на дату занятия нельзя `charged=true`.
- Обработка `is_trial` для членов: `charged` форсится в false, сохраняется `trial_amount`.
- Приём гостевых пробных строк (по `client_id`): создаются записи attendance.
- Остальная логика (переход в `held`, `held_at`, `conducted_by`) без изменений.

## Маршруты (новые)

```
POST   /groups/{group}/members/{member}/freeze           → freezeMember
DELETE /groups/{group}/members/{member}/freezes/{freeze} → unfreezeMember
POST   /group-sessions/{session}/trial-guest             → addTrialGuest
DELETE /group-sessions/{session}/trial-guest/{attendance}→ removeTrialGuest
```

**Разделение ответственности (без двусмысленности):**
- **Пробное у члена** — обрабатывается внутри `conduct()` через поля строки участника (`is_trial`, `trial_amount`).
- **Пробный гость** — добавляется отдельным экшеном `addTrialGuest` (можно до проведения), создаёт строку attendance с `client_id` сразу; строка видна в списке и удаляется через `removeTrialGuest`. `conduct()` гостевые строки не пересоздаёт.

## Вне области (YAGNI)

- Выплата тренеру не меняется.
- Цена аренды корта при заморозке не пересчитывается.
- Без авто-уведомлений клиенту, без лимита числа заморозок, без отдельного отчёта по пробным (доход показываем на карточке занятия).

## Тесты (TDD)

- `isFrozenOn(date)` — границы периода включительно.
- Замороженный на дату занятия не списывается (остаток не меняется), даже если прислан `charged=1`.
- Вне периода заморозки — списывается как обычно.
- Пробное у члена: `charged=false`, остаток не меняется, `trial_amount` сохранён.
- Пробный гость: строка attendance по `client_id`, участник в группу не добавлен, остатки членов не задеты.
- Остаток корректен при наличии trial-строк.

# Бронирование кортов в мобильном приложении — План реализации

**Goal:** Пользователь мобильного приложения может забронировать корт: выбрать клуб → дату → корт → слот → тренера → подтвердить.

**Architecture:** Новые API-эндпоинты в группе `/api/mobile/courts`, контроллер `MobileCourtController`. Flutter-экраны: баннер на главной, выбор клуба, расписание, форма бронирования, подтверждение.

**Tech Stack:** Laravel 12 (API), Flutter (Dart), Sanctum auth

---

## Часть 1: Backend — API эндпоинты

### Task 1: MobileCourtController — список клубов с кортами

**API:** `GET /api/mobile/courts/clubs`

**Параметры:** `?city=Алматы&search=padel`

**Ответ:**
```json
{
  "success": true,
  "clubs": [
    {
      "id": 1,
      "name": "ADD PADEL",
      "address": "Алматы, ул. Тимирязева 42",
      "city": "Алматы",
      "logo": null,
      "description": "...",
      "courts_count": 3,
      "min_price": 30000
    }
  ],
  "cities": ["Алматы", "Астана", "Шымкент"]
}
```

**Логика:**
- Только активные клубы с фичей `courts` и хотя бы 1 активным кортом
- `min_price` — минимальная цена из `court_price_ranges` всех кортов клуба
- `courts_count` — кол-во активных кортов
- Фильтрация по городу и поиск по имени

**Files:**
- Create: `app/Http/Controllers/Api/MobileCourtController.php`
- Modify: `routes/api.php` — добавить роуты

---

### Task 2: API — расписание кортов клуба на дату

**API:** `GET /api/mobile/courts/clubs/{club}/schedule?date=2026-04-01`

**Ответ:**
```json
{
  "success": true,
  "club": { "id": 1, "name": "ADD PADEL", "address": "..." },
  "date": "2026-04-01",
  "courts": [
    {
      "id": 1,
      "name": "Корт 1",
      "slots": [
        { "time": "09:00", "status": "free", "price": 30000 },
        { "time": "10:00", "status": "booked" },
        { "time": "11:00", "status": "free", "price": 30000 },
        { "time": "16:00", "status": "blocked" }
      ]
    }
  ],
  "coaches": [
    {
      "id": 724,
      "name": "Кирилл Колганов",
      "hourly_rate": 20000,
      "rates": { "1": 20000, "2": 18000, "3": 15000 },
      "availability": {
        "09:00": true, "10:00": true, "11:00": false
      }
    }
  ]
}
```

**Логика:**
- Использовать `CourtScheduleService::generateTimeSlots()` и `buildSchedule()`
- Для каждого корта — список слотов со статусом (free/booked/blocked) и ценой
- Тренеры — с доступностью по слотам (через `ClubCoach::isFreeAt()`)
- Ставки тренеров — объект `rates` с ценой за час по длительности

**Files:**
- Modify: `app/Http/Controllers/Api/MobileCourtController.php`

---

### Task 3: API — создание бронирования

**API:** `POST /api/mobile/courts/clubs/{club}/book`

**Body:**
```json
{
  "court_id": 1,
  "date": "2026-04-01",
  "start_time": "14:00",
  "slots": 2,
  "coach_id": 724,
  "comment": "Подготовка к турниру"
}
```

**Ответ (success):**
```json
{
  "success": true,
  "booking": {
    "id": 123,
    "court": "Корт 1",
    "club": "ADD PADEL",
    "date": "2026-04-01",
    "start_time": "14:00",
    "end_time": "16:00",
    "court_price": 64000,
    "coach_name": "Кирилл Колганов",
    "coach_price": 36000,
    "total_price": 100000
  }
}
```

**Логика:**
- Валидация: корт принадлежит клубу, слоты свободны, тренер доступен
- Имя и телефон берём из auth user (не из body)
- `client_name` = `$user->full_name`, `client_phone` = `$user->phone`
- Расчёт цены корта через `CourtScheduleService::calculatePrice()`
- Расчёт цены тренера через `ClubCoach::getRateForHours()`
- Создание `CourtBooking` с `booked_by` = auth user, `is_paid` = false

**Files:**
- Modify: `app/Http/Controllers/Api/MobileCourtController.php`

---

### Task 4: API — мои бронирования

**API:** `GET /api/mobile/courts/my-bookings`

**Ответ:**
```json
{
  "success": true,
  "upcoming": [
    {
      "id": 123,
      "club": "ADD PADEL",
      "court": "Корт 1",
      "date": "2026-04-01",
      "start_time": "14:00",
      "end_time": "16:00",
      "price": 64000,
      "coach": "Кирилл",
      "coach_price": 36000,
      "status": "confirmed"
    }
  ],
  "past": [...]
}
```

**Логика:**
- Бронирования текущего пользователя (`booked_by` или по `client_phone`)
- Разделить на upcoming (дата >= сегодня) и past
- Сортировка: upcoming по дате ASC, past по дате DESC

**Files:**
- Modify: `app/Http/Controllers/Api/MobileCourtController.php`

---

### Task 5: API — отмена бронирования

**API:** `POST /api/mobile/courts/bookings/{booking}/cancel`

**Логика:**
- Проверить что бронь принадлежит пользователю
- Проверить `booking_cancel_hours` клуба (можно ли ещё отменить)
- Обновить статус на `cancelled`

**Files:**
- Modify: `app/Http/Controllers/Api/MobileCourtController.php`

---

### Task 6: Роуты API

**File:** `routes/api.php`

```php
// Бронирование кортов
Route::get('/courts/clubs', [MobileCourtController::class, 'clubs']);
Route::get('/courts/clubs/{club}/schedule', [MobileCourtController::class, 'schedule']);
Route::post('/courts/clubs/{club}/book', [MobileCourtController::class, 'book']);
Route::get('/courts/my-bookings', [MobileCourtController::class, 'myBookings']);
Route::post('/courts/bookings/{booking}/cancel', [MobileCourtController::class, 'cancel']);
```

---

### Task 7: Баннер на главной — добавить в MobileHomeController

**API:** `GET /api/mobile/home` — добавить поле `has_court_booking` (есть ли клубы с кортами)

**File:** `app/Http/Controllers/Api/MobileHomeController.php`

Добавить в ответ:
```json
{
  "court_booking_available": true
}
```

---

## Часть 2: Frontend — Flutter экраны

### Task 8: Баннер на главном экране

Зелёный баннер «Забронировать корт» на HomeScreen, между рейтингом и турнирами. При нажатии → навигация на экран выбора клуба.

### Task 9: Экран выбора клуба

- Поиск по имени
- Фильтр по городу (табы)
- Карточки клубов (фото, название, адрес, кол-во кортов, мин. цена)
- Нажатие → экран расписания

### Task 10: Экран расписания кортов

- Горизонтальный выбор даты (7 дней)
- Табы кортов
- Список слотов (свободен/занят/заблокирован)
- Нажатие на свободный → экран бронирования

### Task 11: Экран бронирования

- Сводка (клуб, корт, дата, время)
- Кнопки длительности (1-3ч)
- Чипы тренеров (доступные/недоступные)
- Имя и телефон (предзаполнено из профиля)
- Комментарий
- Кнопка «Забронировать — XX ₸»

### Task 12: Экран подтверждения

- Галочка + «Бронь подтверждена»
- Детали: клуб, корт, дата, время, тренер, цены
- Кнопки: «Мои бронирования», «На главную»

### Task 13: Экран «Мои бронирования»

- Доступен из профиля или экрана подтверждения
- Предстоящие / Прошедшие
- Карточки бронирований с деталями
- Кнопка отмены (для предстоящих)

---

## Порядок выполнения

**Backend первый (Tasks 1-7):**
1. Task 6 — Роуты
2. Task 1 — Список клубов
3. Task 2 — Расписание
4. Task 3 — Бронирование
5. Task 4 — Мои бронирования
6. Task 5 — Отмена
7. Task 7 — Баннер на главной

**Frontend после (Tasks 8-13):**
8. Task 8 — Баннер
9. Task 9 — Выбор клуба
10. Task 10 — Расписание
11. Task 11 — Бронирование
12. Task 12 — Подтверждение
13. Task 13 — Мои бронирования

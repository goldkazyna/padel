# Клиенты клуба — спецификация

## Суть

Справочник клиентов клуба. Ручной ввод (имя, телефон, заметка, пол, дата рождения). Без привязки к User. UI: список слева + карточка выбранного клиента справа.

## Таблица `club_clients`

| Поле | Тип | Описание |
|------|-----|----------|
| id | bigint PK | Auto-increment |
| club_id | foreignId → clubs | Клуб-владелец |
| name | string | Имя клиента (обязательное) |
| phone | string, nullable | Телефон |
| note | text, nullable | Заметка |
| gender | enum('male','female'), nullable | Пол |
| birth_date | date, nullable | Дата рождения |
| created_at, updated_at | timestamps | |

Unique constraint: нет (один клуб может иметь клиентов с одинаковыми именами).

## Модель `ClubClient`

- `club()` — BelongsTo Club
- Fillable: name, phone, note, gender, birth_date, club_id
- Cast: birth_date → date

## Контроллер `Club\ClientController`

### `index(Request $request)`
- Получает клиентов текущего клуба
- Поиск по `name` и `phone` (параметр `search`)
- Пагинация: 20 записей
- Возвращает view `club.clients.index`

### `store(Request $request)`
- Валидация: name (required, max:255), phone (nullable, max:20), note (nullable, max:1000), gender (nullable, in:male,female), birth_date (nullable, date)
- Привязка к текущему клубу
- Redirect back с flash-сообщением

### `update(Request $request, ClubClient $client)`
- Та же валидация что и store
- Проверка что клиент принадлежит текущему клубу
- Redirect back

### `destroy(ClubClient $client)`
- Проверка что клиент принадлежит текущему клубу
- Удаление, redirect back

## UI — страница `club/clients/index.blade.php`

### Макет: список + боковая панель (вариант 5)

**Шапка:**
- Иконка `bi bi-person-lines-fill` (монохромная)
- Заголовок «Клиенты» + бейдж с количеством
- Кнопка «Добавить»

**Поиск:**
- Одно поле с иконкой лупы
- Поиск по имени и телефону

**Основная область — два столбца:**

*Левый столбец (список):*
- Список клиентов: аватар (инициалы), имя, телефон
- Выбранный клиент подсвечен (border-left accent + фон)
- Пагинация внизу

*Правый столбец (панель деталей, sticky):*
- Аватар (большой), имя, телефон
- Блок «Информация»: пол, дата рождения, дата добавления
- Блок «Заметка»
- Кнопки: «Редактировать», «Удалить»

**Добавление/редактирование:**
- Модальное окно с полями: имя, телефон, пол, дата рождения, заметка

**Стили:**
- Тёмная тема, CSS-переменные как в остальных разделах CRM
- Иконки Bootstrap Icons, монохромные
- Адаптив: на узких экранах панель скрывается, клик по клиенту открывает модалку

## Роуты

```php
Route::middleware('club.feature:clients')->group(function () {
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
});
```

Внутри группы `/club` с middleware `auth` + `role:club_admin,club_moderator,super_admin`.

## Sidebar

Новый пункт «Клиенты» с иконкой `bi bi-person-lines-fill`, защищён feature toggle `clients`. Расположение: после «Пользователи».

## Feature toggle

Добавить `clients` в список доступных модулей клуба. По умолчанию включён.

## Что НЕ входит

- Привязка к User (зарегистрированным пользователям)
- Аналитика по клиентам
- Импорт из бронирований/турниров
- API для мобильного приложения

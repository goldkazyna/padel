# Редактирование карточки клуба из приложения — дизайн

**Дата:** 2026-05-23
**Статус:** утверждён к реализации
**Затрагивает:** backend `C:\projects\padel` (Laravel) + mobile `C:\projects\padel_app` (Flutter)

## Цель

Дать владельцу клуба (`club_admin`) редактировать карточку своего клуба из мобильного приложения — кнопкой «Редактировать карточку клуба» под кнопкой создания турнира. Форма повторяет веб `/admin/clubs/{id}/edit`, но без супер-админских полей и без логотипа.

## Решения (согласованы)

- **Редактируемые поля:** `name`, `address`, `city`, `phone`, `email`, `description`, `payment_url`.
- **НЕ редактируются (только супер-админ, остаются в вебе):** `is_active`, `telegram_channel_id`, `telegram_bot_token`, `features` (модули).
- **Логотип — НЕ входит** (отложено).
- **Права:** только владелец (`club_admin` этого клуба) и `super_admin`. Модератор НЕ видит кнопку и получает 403 на endpoint.
- `city` — выбор из фиксированного списка: Алматы, Астана, Шымкент, Караганда, Актобе.

## Часть 1. Backend (Laravel)

### 1.1. `app/Http/Controllers/Api/MobileAdminClubController.php` (новый)

Приватный хелпер авторизации (только владелец, без модератора):
```php
private function canEditClub($user, Club $club): bool
{
    if (!$user) return false;
    if ($user->isSuperAdmin()) return true;
    return $user->adminClubs()->where('clubs.id', $club->id)->exists();
}
```

**`show(Request $request, Club $club)`** — GET. Если `!canEditClub` → 403 (json `{success:false,message:'Доступ запрещён'}`). Иначе вернуть:
```php
return response()->json([
    'success' => true,
    'club' => [
        'id' => $club->id,
        'name' => $club->name,
        'address' => $club->address,
        'city' => $club->city,
        'phone' => $club->phone,
        'email' => $club->email,
        'description' => $club->description,
        'payment_url' => $club->payment_url,
    ],
]);
```

**`update(Request $request, Club $club)`** — PUT. Если `!canEditClub` → 403. Валидация (зеркало веб-формы, без супер-админских полей):
```php
$validated = $request->validate([
    'name' => 'required|string|max:255',
    'address' => 'required|string|max:255',
    'city' => 'nullable|string|in:Алматы,Астана,Шымкент,Караганда,Актобе',
    'phone' => 'nullable|string|max:20',
    'email' => 'nullable|email|max:255',
    'description' => 'nullable|string',
    'payment_url' => 'nullable|url|max:500',
]);
$club->update($validated);
```
Вернуть тот же формат, что `show` (обновлённый клуб) с `success:true`. Поля `is_active`, `telegram_*`, `features` не передаются и не трогаются.

### 1.2. Маршруты (`routes/api.php`)
В той же группе mobile-admin (`auth:sanctum`, prefix как у `/admin/clubs/{club}/...`):
```php
Route::get('/admin/clubs/{club}', [\App\Http\Controllers\Api\MobileAdminClubController::class, 'show']);
Route::put('/admin/clubs/{club}', [\App\Http\Controllers\Api\MobileAdminClubController::class, 'update']);
```
Разместить рядом с существующими `/admin/clubs/{club}/...` маршрутами. Убедиться, что статический `{club}` не конфликтует с существующими подпутями (`/admin/clubs/{club}/tournaments` и т.п. — они длиннее, конфликта нет).

## Часть 2. Mobile (Flutter, padel_app)

### 2.1. Модель `lib/models/admin_club_edit.dart` (новая)
Поля: `int id; String name; String address; String? city; String? phone; String? email; String? description; String? paymentUrl;` + `fromJson` (читает snake_case) и `toJson`/Map для PUT (snake_case: `name, address, city, phone, email, description, payment_url`).

### 2.2. `lib/services/admin_service.dart`
```dart
Future<AdminClubEdit> getClub(int clubId) async {
  final token = await _storage.getToken();
  final response = await _api.get('/admin/clubs/$clubId', token);
  return AdminClubEdit.fromJson(response['club'] as Map<String, dynamic>);
}

Future<AdminClubEdit> updateClub(int clubId, Map<String, dynamic> body) async {
  final token = await _storage.getToken();
  final response = await _api.put('/admin/clubs/$clubId', body, token);
  return AdminClubEdit.fromJson(response['club'] as Map<String, dynamic>);
}
```
Использовать точные сигнатуры `_api.get`/`_api.put`, как в существующих методах.

### 2.3. Кнопка `lib/widgets/home/admin_club_block.dart`
Под кнопкой «Создать турнир» добавить `_AdminCta` «Редактировать карточку клуба» (подзаголовок «Название, контакты, описание»), синий/циан градиент. Показывать **только владельцу** — обернуть в условие, что пользователь не модератор (блок уже различает модератора через `isModerator`/аналогичный флаг — показывать кнопку только когда `!isModerator`). По нажатию — переход на `AdminEditClubScreen(clubId: club.id)`.

### 2.4. Экран `lib/screens/admin/admin_edit_club_screen.dart` (новый)
По образцу `edit_profile_screen.dart`, но без фото:
- `initState`/`_load()`: `AdminService.getClub(clubId)` → заполнить контроллеры.
- Поля: Название (обяз.), Адрес (обяз.), Город (Dropdown из 5 значений + пусто), Телефон, Email, Описание (многострочное), Ссылка оплаты.
- Сохранение `_save()`: собрать Map, `updateClub(clubId, body)`, по успеху — сообщение + `Navigator.pop`; ошибки/валидация — показать (использовать тот же паттерн алертов/снэкбаров, что в экране-образце).
- Заголовок с кнопкой назад и индикатором загрузки/сохранения.

### 2.5. Локализация ru/en/kk
Ключи: `editClubCard`, `editClubCardSubtitle`, `clubName`, `clubAddress`, `clubCity`, `clubPhone`, `clubEmail`, `clubDescription`, `clubPaymentUrl`, `clubSaved` (+ при необходимости общие «Сохранить»/«Обязательное поле», если их ещё нет). RU — канон, регенерация `flutter gen-l10n`.

## Не входит
Логотип; права модератора; супер-админские поля (`is_active`, telegram-настройки, модули) — остаются только в веб-админке.

## Тестирование

### Backend (PHPUnit) — `tests/Feature/MobileClubEditTest.php`
- `show`/`update` доступны владельцу клуба (200); обновляют только разрешённые поля.
- `update` НЕ меняет `is_active`/`features`/`telegram_*` даже если их подсунуть в теле запроса (assert эти поля без изменений).
- `city` вне списка → 422; `email`/`payment_url` невалидные → 422.
- Модератор клуба → 403 на `show` и `update`. Чужой клуб/не-админ → 403.

### Mobile
Ручная проверка: кнопка видна владельцу (не модератору); форма грузит текущие данные; сохранение меняет карточку; город — выпадающий список; невалидные email/url показывают ошибку. (Flutter-автотесты — по принятому в проекте минимуму; основная проверка ручная.)

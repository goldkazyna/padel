# Поле «Телеграм-канал» у клуба — дизайн

**Дата:** 2026-05-23
**Статус:** утверждён к реализации
**Затрагивает:** backend `C:\projects\padel` (Laravel) + mobile `C:\projects\padel_app` (Flutter)

## Цель

Добавить клубу публичное поле «Телеграм-канал» — ссылку `https://t.me/...` (отдельно от технических супер-админских `telegram_channel_id` / `telegram_bot_token`). Поле:
- редактируется в веб-форме `/admin/clubs/edit` и в приложении владельцем (`club_admin`);
- показывается игрокам в карточке клуба как кнопка, открывающая канал.

## Решения (согласованы)

- Колонка/ключ: **`telegram_url`** (nullable string). Валидация — `nullable|url|max:500`.
- Редактируемое поле (не супер-админское): доступно `club_admin` в приложении и на вебе.
- Игроку показывается кнопка «Телеграм-канал» только если ссылка задана; открытие через `launchUrl(externalApplication)`.

## Часть 1. Backend (Laravel)

1. **Миграция** `database/migrations/2026_05_23_000001_add_telegram_url_to_clubs_table.php`:
   ```php
   Schema::table('clubs', function (Blueprint $table) {
       $table->string('telegram_url')->nullable()->after('payment_url');
   });
   ```
   down(): `dropColumn('telegram_url')`.
2. **`app/Models/Club.php`:** добавить `'telegram_url'` в `$fillable`.
3. **Веб-форма** `resources/views/admin/clubs/edit.blade.php`: добавить поле «Телеграм-канал (ссылка)» (например после `payment_url`, ДО супер-админских telegram-настроек), `value="{{ old('telegram_url', $club->telegram_url) }}"`, placeholder `https://t.me/yourchannel`. **`Admin\ClubController::update`** validation: `'telegram_url' => 'nullable|url|max:500'`.
4. **`MobileAdminClubController`** (редактирование владельцем):
   - В `payload()` добавить `'telegram_url' => $club->telegram_url`.
   - В `update()` валидации добавить `'telegram_url' => 'nullable|url|max:500'` (станет 8-м редактируемым полем). `$club->update($validated)` уже подхватит.
5. **Публичные payload игроку** `MobileClubController`: в массивы клуба в `index` (~строки 47-56) и `show` (~строки 91-106) добавить `'telegram_url' => $club->telegram_url`.

## Часть 2. Mobile (Flutter, padel_app)

### Редактирование (владелец)
6. **`lib/models/admin_club_edit.dart`:** добавить `String? telegramUrl;` (+ конструктор, `fromJson: telegramUrl: json['telegram_url'] as String?`).
7. **`lib/screens/admin/admin_edit_club_screen.dart`:** добавить контроллер `_telegramUrl`, заполнять в `_load()` из `club.telegramUrl`, поле в форме «Телеграм-канал» (url-клавиатура), в `_save()` body — `'telegram_url': _telegramUrl.text.trim().isEmpty ? null : _telegramUrl.text.trim()`. Не забыть dispose.

### Отображение игроку
8. **`lib/models/club.dart`** (модель игрока): добавить `String? telegramUrl;` (+ `fromJson: telegramUrl: json['telegram_url'] as String?`).
9. **`lib/screens/club_detail_screen.dart`:** после строки телефона добавить строку «Телеграм-канал» (icon `Icons.send` или аналог), видна только если `club.telegramUrl != null && непустая`; `onTap` открывает ссылку:
   ```dart
   Future<void> _openTelegram(String url) async {
     final uri = Uri.parse(url);
     if (await canLaunchUrl(uri)) {
       await launchUrl(uri, mode: LaunchMode.externalApplication);
     }
   }
   ```
   (по образцу существующих maps/phone launchUrl в этом файле).

### Локализация ru/en/kk
10. Ключи: `clubTelegram` («Телеграм-канал» — метка поля и строки), при необходимости `openTelegramChannel`. RU — канон, регенерация `flutter gen-l10n`.

## Не входит
Супер-админские telegram-настройки (`telegram_channel_id`, `telegram_bot_token`) — не трогаются. Логотип — по-прежнему вне scope.

## Тестирование

### Backend (PHPUnit)
- Расширить `tests/Feature/MobileClubEditTest.php`: владелец сохраняет `telegram_url` (валидный) → 200, поле обновилось; невалидный (не url) → 422; `show` возвращает `telegram_url`.
- (Публичный payload `MobileClubController` — если есть существующий тест на него, добавить проверку наличия ключа; иначе ручная проверка.)

### Mobile
Ручная проверка: владелец редактирует ссылку → сохраняется; в карточке клуба игрока кнопка «Телеграм-канал» открывает t.me; при пустой ссылке кнопки нет.

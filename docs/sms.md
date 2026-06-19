# SMS / OTP — авторизация по номеру, удаление аккаунта, смена номера

Документ описывает всё, что связано с SMS в проекте: шлюз, хранение кодов,
эндпоинты, флоу и экраны приложения. Бэкенд — `padel` (Laravel), приложение —
`padell_app` (Flutter).

---

## 1. SMS-шлюз (KazInfoTeh)

- **Сервис:** `app/Services/SmsService.php` → `send(string $phone, string $message): bool`
- **Конфиг:** `config/services.php` → ключ `kazinfoteh`
- **Запрос:** `GET http://kazinfoteh.org:9507/api?action=sendmessage&username=...&password=...&recipient=77XXXXXXXXX&messagetype=SMS:TEXT&originator=KiT_Notify&messagedata=<текст>`
- **Успех:** XML-ответ `<acceptreport><statuscode>0</statuscode>` → метод возвращает `true`.
- **Номер** нормализуется до формата `77XXXXXXXXX` (8→7, 10 цифр → +7).

### .env (на сервере должны быть ВСЕ 6 строк)

```
KAZINFOTEH_SCHEME=http
KAZINFOTEH_HOST=kazinfoteh.org
KAZINFOTEH_PORT=9507
KAZINFOTEH_USERNAME=<логин_у_оператора>
KAZINFOTEH_PASSWORD=<пароль_у_оператора>
KAZINFOTEH_ORIGINATOR=KiT_Notify
```

> 🔒 Реальные логин/пароль шлюза — ТОЛЬКО в `.env` на сервере, в репозиторий не
> коммитить. Если утекли — сменить у оператора (KazInfoTeh) и обновить `.env`.

После правки `.env` → `php artisan config:clear`.

> ⚠️ Если `username`/`password` пустые → `SmsService::send` сразу вернёт `false`
> («not configured»). Проверка: `php artisan tinker --execute="dump(config('services.kazinfoteh'));"`

> ⚠️ Тестовый доступ оператора может доставлять SMS **только на зарегистрированный
> тестовый номер**. Шлюз при этом принимает (statuscode 0), но не доставляет.

### Диагностика

```bash
# Прямой тест отправки (true = принято шлюзом):
php artisan tinker --execute="dump(app(\App\Services\SmsService::class)->send('77774333822', 'Ваш код OTP: 1234'));"
# Логи (могут быть в посуточном файле):
ls -t storage/logs/*.log | head -1 | xargs grep -i kazinfoteh
```

---

## 2. Хранение кодов (Cache, TTL 5 мин)

| Ключ | Назначение |
|---|---|
| `sms_code_{phone}` | код входа по SMS |
| `delete_code_{user_id}` | код удаления аккаунта |
| `phone_old_{user_id}` | код на текущий номер (смена) |
| `phone_change_allowed_{user_id}` | флаг «старый номер подтверждён» (TTL 10 мин) |
| `phone_new_code_{user_id}` | код на новый номер (смена) |
| `phone_new_value_{user_id}` | сам новый номер (смена) |

**Тестовый код `1111`** работает всегда (для App Store review и тестов без SMS).

### Тексты сообщений

- Вход / смена номера: `Ваш код OTP: NNNN`
- Удаление аккаунта: `Код для удаления аккаунта: NNNN`

---

## 3. Эндпоинты (`MobileAuthController`)

Все под префиксом `/api/mobile`.

### Без токена

| Метод | Путь | Тело | Ответ |
|---|---|---|---|
| POST | `/auth/send-code` (throttle 5/мин) | `{phone}` | `{success, message}` — шлёт код любому номеру |
| POST | `/auth/verify-code` | `{phone, code}` | `{success, is_new, token, user}` |

`verify-code`: если юзер есть → `is_new:false` + токен. Если нет → создаёт
минимального юзера (`name=''`, `first_name=''`, `last_name=''`, `phone`,
`role=player`, `rating=1000`, `level=1.00`) и отдаёт `is_new:true`.

### С токеном (`auth:sanctum`)

| Метод | Путь | Тело | Назначение |
|---|---|---|---|
| POST | `/auth/account/send-delete-code` (throttle 5/мин) | — | код на телефон юзера |
| DELETE | `/auth/account` | `{code}` или `{password}` | анонимизация (см. ниже) |
| POST | `/auth/phone/send-old-code` (throttle 5/мин) | — | код на текущий номер |
| POST | `/auth/phone/confirm-old` | `{code}` | подтвердить старый → флаг разрешения |
| POST | `/auth/phone/send-new-code` (throttle 5/мин) | `{phone}` | код на новый (с проверкой занятости) |
| POST | `/auth/phone/confirm-new` | `{code}` | сменить `users.phone` на новый |

---

## 4. Флоу

### 4.1. Вход по SMS + регистрация нового

1. Логин → кнопка **«Войти по SMS»** → экран ввода номера (маска +7).
2. `send-code` → экран ввода 4-значного кода.
3. `verify-code`:
   - **новый** (`is_new:true`) → экран регистрации (ФИО, Город, Дата рождения,
     Пол — все обязательны) → `PUT /profile` → квиз → главная;
   - **существующий** → сразу в приложение.

### 4.2. Удаление аккаунта (анонимизация)

1. Профиль → «Удалить аккаунт» → диалог «Это действие необратимо…».
2. `send-delete-code` → SMS → экран ввода кода.
3. `DELETE /auth/account {code}` → **анонимизация** (НЕ hard delete):
   - `name='Удалённый игрок'`, `first_name`/`last_name` затёрты;
   - `email`, `phone`, `password`, `avatar`, `telegram_id`, `google_id`,
     `apple_id` → `null`;
   - `hidden_from_rating=true` (пропадает из рейтинга через `scopeVisibleInRating`);
   - все Sanctum-токены удаляются (войти нельзя).
4. Logout → экран входа.

**Почему не hard delete:** все FK по игрокам — `cascade`. Жёсткое удаление
снесло бы строки матчей, а матч общий на 4 игроков → сломалась бы история
турниров у остальных. Анонимизация сохраняет историю, телефон освобождается →
можно зарегистрироваться заново (новый чистый аккаунт).

### 4.3. Смена номера

1. Профиль (редактирование) → у телефона кнопка **«Изменить номер»**.
2. Шаг 1: `send-old-code` (авто при открытии) → ввод кода со **старого** номера → `confirm-old`.
3. Шаг 2: ввод **нового** номера → `send-new-code` (проверка занятости: «Этот
   номер уже занят» / «Это ваш текущий номер»).
4. Шаг 3: ввод кода с **нового** номера → `confirm-new` → `users.phone` обновлён.

Занятость нового номера проверяется дважды: при `send-new-code` (до отправки SMS)
и повторно при `confirm-new`.

---

## 5. Flutter — экраны и виджеты

| Файл | Назначение |
|---|---|
| `screens/auth/login_screen.dart` | кнопка «Войти по SMS» (`_BrandSms`) после Telegram |
| `screens/auth/phone_login_screen.dart` | ввод номера (маска +7) |
| `screens/auth/verify_code_screen.dart` | ввод кода входа, ветка `is_new` |
| `screens/auth/sms_registration_screen.dart` | профиль нового юзера → `PUT /profile` |
| `screens/auth/delete_account_code_screen.dart` | код удаления (красный) |
| `screens/auth/change_phone_screen.dart` | смена номера (3 шага) |
| `widgets/otp_code_input.dart` | красивый ввод кода 4 боксами |
| `widgets/resend_code_button.dart` | «отправить повторно», активна через 60 сек |
| `widgets/profile/profile_menu.dart` | диалог удаления → код |
| `screens/edit_profile_screen.dart` | «Изменить номер» → `ChangePhoneScreen` |

`providers/auth_provider.dart` + `services/auth_service.dart`: `sendCode`,
`verifyCode` (отдаёт `lastVerifyIsNew`), `sendDeleteCode`, `deleteAccount({code,password})`.

---

## 6. Деплой (бэкенд)

```bash
git pull
# миграция email→nullable (один раз, нужна для новых SMS-юзеров без email):
php artisan migrate --path=database/migrations/2026_06_19_000001_make_email_nullable_in_users_table.php
# .env: добавить все KAZINFOTEH_* (особенно ORIGINATOR=KiT_Notify), затем:
php artisan config:clear
```

Flutter-часть требует пересборки APK.

# Служба поддержки (тикеты) — дизайн

Дата: 2026-06-30

## Цель
Двусторонняя система обращений в поддержку: игрок создаёт тикет из приложения,
супер-админ отвечает из веб-панели, игрок получает пуш и бейдж непрочитанного.

## Где что живёт
- **Игрок** — Flutter-приложение + mobile API (`/api/mobile/...`, sanctum).
- **Супер-админ** — веб-панель Laravel (`role:super_admin`). Пункт «Тикеты» в сайдбаре
  `resources/views/layouts/app.blade.php` (блок «Супер-админ»), контроллер
  `app/Http/Controllers/Admin/TicketController.php`, роуты в группе `role:super_admin`.
  В мобильном приложении супер-админа нет.

## Данные
**support_tickets**
- `id`, `user_id` (FK users, игрок), `subject` (string), `status` enum(`open`,`answered`,`closed`) default `open`,
  `last_message_at` (timestamp), timestamps.

**support_ticket_messages**
- `id`, `support_ticket_id` (FK cascade), `author_type` enum(`player`,`support`),
  `author_id` (FK users, кто написал; для support — супер-админ), `body` (text),
  `read_at` (nullable — когда получатель прочитал), `created_at`.

**support_ticket_attachments**
- `id`, `support_ticket_message_id` (FK cascade), `path` (string, webp в public-диске), `created_at`.

## Фото → WebP
- Конвертация на бэке через встроенный GD `imagewebp()` (без новых composer-зависимостей).
- Хранение: `Storage::disk('public')`, путь `support/{ticket_id}/{uniq}.webp`, отдаём `url('/storage/...')`.
- Валидация: до 5 фото на сообщение, `image|mimes:jpeg,jpg,png,webp|max:8192` каждое.
- Хелпер `app/Support/WebpConverter.php`: принимает UploadedFile → сохраняет webp → возвращает путь.
- Видео — вне scope v1.

## Поток игрока (mobile API)
| Метод | Эндпоинт | Описание |
|---|---|---|
| GET | `/support/tickets` | список своих тикетов (последнее сообщение, статус, есть ли непрочитанное) |
| POST | `/support/tickets` | создать тикет: `subject`, `body`, `photos[]` → тикет + первое сообщение (player) |
| GET | `/support/tickets/{ticket}` | детали + все сообщения с вложениями (только свой тикет) |
| POST | `/support/tickets/{ticket}/messages` | дописать сообщение `body` + `photos[]` (переоткрывает closed → open) |
| GET | `/support/unread-count` | `{count}` — непрочитанные ответы поддержки (для бейджа кнопки) |

- Открытие тикета (`GET show`) помечает сообщения поддержки прочитанными (`read_at=now`)
  и гасит связанные support-уведомления в «колокольчике».
- Доступ строго к своим тикетам (`$request->user()->id`).

## Поток супер-админа (веб)
- `GET /admin/tickets` — список (открытые/answered сверху, бейдж новых), фильтр по статусу.
- `GET /admin/tickets/{ticket}` — переписка; отметка сообщений игрока прочитанными.
- `POST /admin/tickets/{ticket}/reply` — ответ текстом (author_type=support). Статус → `answered`.
- `POST /admin/tickets/{ticket}/close` / `reopen` — смена статуса.
- Ответ админа в v1 — только текст (фото от админа — позже).

## Уведомления и бейджи (переиспользуем существующее)
- На ответ поддержки: (1) `support_ticket_messages` (author_type=support), (2) строка в `notifications`
  (`category=support`, `type=support_reply`, `data.ticket_id`), (3) пуш
  `FCMNotificationService::sendToUser($player, $title, $body, [...])`.
- Бейдж кнопки «Служба поддержки» = `/support/unread-count` (непрочитанные ответы поддержки).
- При открытии тикета игроком гасим непрочитанные сообщения + связанные support-уведомления.
- Обновление переписки: запрос при открытии + pull-to-refresh (без авто-поллинга).

## Статусы
- `open` — новый/ждёт ответа; `answered` — ответил админ; `closed` — закрыт админом.
- Новое сообщение игрока в `closed` → снова `open`.

## Тестирование (TDD, бэкенд)
- Создание тикета (+первое сообщение, +webp-вложение), доступ только к своим.
- Дописывание сообщения, переоткрытие closed.
- Ответ поддержки → notification + статус answered + (мок) пуш.
- unread-count считает только непрочитанные ответы поддержки; обнуляется при открытии.
- WebpConverter: jpeg/png на входе → webp-файл на выходе.
- Права: чужой тикет — 403/404; супер-админ видит все.

## Flutter (после бэка)
- `SupportService` (api), модели `SupportTicket`/`SupportMessage`.
- Профиль: пункт «Служба поддержки» + бейдж (как у уведомлений).
- Экраны: список тикетов, создание (тема+текст+фото), тред (сообщения, дописать, pull-to-refresh).
- Обработка пуша `type=support_reply` → открыть тикет.

## Вне scope v1
Видео-вложения; фото в ответах админа; обработка тикетов клуб-админами; авто-поллинг.

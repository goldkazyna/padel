# Приглашение игрока на турнир — дизайн

**Дата:** 2026-05-30
**Статус:** согласован к реализации
**Затрагивает:** бэкенд `C:\projects\padel` (mobile API) + приложение `C:\projects\padel_app` (Flutter).

## Цель

Дать админу/модератору клуба **приглашать** игрока на индивидуальный турнир из мобильного приложения: найти игрока (телефон / id / имя), отправить приглашение → игроку приходит пуш. Игрок видит приглашения в профиле (кнопка под «Мои турниры»), может **Принять** (записывается на турнир на модерацию) или **Отклонить**.

«Пригласить» — отдельная опция рядом с существующим «Добавить участника» (прямое добавление без спроса). Обе остаются.

## Согласованные решения

- Только **индивидуальные** турниры (не team).
- Одно приглашение на пару (турнир, игрок) — повторное приглашение обновляет существующее (unique).
- **Принять**: если есть места → запись со статусом `pending` (на модерацию, как самозапись); если переполнено → лист ожидания `waiting`. После принятия — переход на страницу турнира.
- **Отклонить**: помечает приглашение `declined`, убирает из списка.

## Часть 1. Модель данных

Новая таблица `tournament_invitations`:
```
id
tournament_id   FK tournaments, cascadeOnDelete
user_id         FK users, cascadeOnDelete           // приглашённый игрок
invited_by      FK users, nullOnDelete              // кто пригласил (админ/модератор)
status          enum('pending','accepted','declined') default 'pending'
responded_at    timestamp nullable
timestamps
unique [tournament_id, user_id]
index [user_id, status]
```

Модель `TournamentInvitation`: belongsTo `tournament`, `user`, `inviter` (invited_by).

## Часть 2. Бэкенд (mobile API)

### Админ (группа admin-турнира, гард `canManageTournament`)
- `GET /admin/tournaments/{t}/players/search?q=` — расширить текущий поиск: телефон / имя / **id** (если q числовой).
- `POST /admin/tournaments/{t}/invite` `{user_id}`:
  - гард: тип ≠ team (иначе ошибка «приглашения только для индивидуальных»);
  - игрок ещё не участник (иначе ошибка);
  - `updateOrCreate` приглашения (tournament_id, user_id) → status `pending`, invited_by, responded_at = null;
  - `Notification::create` (type `tournament_invite`, category `tournament`, data {tournament_id, invitation_id}) + `FCMNotificationService::sendToUser` «Приглашение на турнир: {название}».

### Игрок (mobile auth)
- `GET /tournaments/invitations` — pending-приглашения текущего юзера: турнир (id, название, дата, клуб, тип) + кто пригласил (имя).
- `GET /tournaments/invitations/count` — число pending (для бейджа на кнопке профиля).
- `POST /tournaments/invitations/{inv}/accept`:
  - проверка принадлежности приглашения юзеру и статуса pending;
  - в транзакции: если уже участник — просто пометить accepted; иначе считаем занятые слоты (`registered`+`pending`); есть место → attach `pending`, нет → attach `waiting`;
  - invitation → accepted, responded_at = now;
  - ответ: `{ success, tournament_id, waitlisted }`.
- `POST /tournaments/invitations/{inv}/decline` — статус `declined`, responded_at = now.

## Часть 3. Приложение — админ

На экране управления участниками турнира рядом с «Добавить участника» — действие **«Пригласить»**. Экран/лист с поиском (телефон / id / имя) → список игроков → тап «Пригласить» → `POST invite` → тост «Приглашение отправлено».

## Часть 4. Приложение — игрок

В профиле **под кнопкой «Мои турниры»** — кнопка **«Приглашения на турнир»** с бейджем количества pending. Внутри — `InvitationsScreen`: список приглашений (турнир: название/дата/клуб + «пригласил: {имя}»), кнопки **Принять** / **Отклонить**. Принять → API → переход на `TournamentDetail`. Отклонить → убрать из списка.

## Не входит

- Командные (team) турниры.
- Массовые приглашения, приглашение по ссылке, повторные напоминания.
- Веб-админка (только мобильное приложение).

## Тестирование (PHPUnit)

- Приглашение создаётся (pending) + Notification создаётся; team-турнир → ошибка; уже участник → ошибка; повтор → updateOrCreate без дубля.
- Список/счётчик pending у игрока.
- Accept при наличии мест → участник `pending`, invitation `accepted`; переполнено → `waiting`; чужое приглашение → 403/404.
- Decline → `declined`, из списка исчезает.

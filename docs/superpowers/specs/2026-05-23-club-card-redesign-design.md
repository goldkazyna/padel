# Карточка клуба/комьюнити (редизайн) — дизайн

**Дата:** 2026-05-23
**Статус:** утверждён к реализации
**Затрагивает:** backend `C:\projects\padel` (Laravel) + mobile `C:\projects\padel_app` (Flutter)

## Цель

Переделать экран карточки клуба/комьюнити в приложении по новому макету: обложка фоном, лого + бейдж + название поверх неё, кнопки соц-связи, блок открытых турниров с переходом, адрес/город/телефон, «О клубе». Использовать существующую тему (`AppTheme`, зелёный accent `#22C47A`).

## Решения (согласованы)

- Блок «Открытые турниры» — **только количество** (без «N ближайших · ещё M»), показывается только если `open_tournaments_count > 0`.
- Нажатие на блок → существующий `TournamentsScreen` с предзаполненным фильтром `clubIds={clubId}` (отдельного endpoint не делаем).
- Кнопка **Share** — `Share.share` текстом (название + ссылка telegram/instagram, если есть).
- Кнопки Звонок/Telegram/Instagram — показываем только те, для которых есть данные.
- Бейдж: **КОМЬЮНИТИ** если `is_community`, иначе **КЛУБ**.

## Часть 1. Backend (Laravel)

**`app/Http/Controllers/Api/MobileClubController.php` → `show()`** payload: добавить три ключа (mirror `url($club->logo)` для cover):
```php
'cover' => $club->cover ? url($club->cover) : null,
'is_community' => (bool) $club->is_community,
'open_tournaments_count' => $club->tournaments()
    ->where('status', 'open')
    ->where('start_date', '>', now())
    ->count(),
```
Остальной payload не меняется. (`Club::tournaments()` — `hasMany`, уже есть.)

## Часть 2. Mobile (Flutter, padel_app)

### 2.1. `lib/models/club.dart`
Добавить поля: `String? cover; bool isCommunity; int openTournamentsCount;` + `fromJson`:
- `cover: json['cover'] as String?`
- `isCommunity: json['is_community'] as bool? ?? false`
- `openTournamentsCount: json['open_tournaments_count'] as int? ?? 0`

### 2.2. Редизайн `lib/screens/club_detail_screen.dart`
Сохранить загрузку (`ClubService.getClub`), launch-хелперы (`_openMaps/_callPhone/_openTelegram/_openInstagram`), `_buildInfoRow`, описание, hide-toggle. Переделать вёрстку под макет:

1. **Обложка-хедер** (высота ~32% экрана): `Image.network(club.cover)` с `BoxFit.cover` и `errorBuilder`→нейтральный фон (`AppTheme.card`); если `cover == null` — сразу нейтральный фон. Поверх — затемняющий градиент снизу (для читаемости). Круглые кнопки **Назад** (слева сверху) и **Share** (справа сверху) на полупрозрачном фоне.
2. **Оверлей у низа обложки:** круглый логотип (Image.network + initials fallback) + строка [бейдж (КОМЬЮНИТИ/КЛУБ, зелёный фон `AppTheme.accentSoft`/`accent`) + название клуба белым].
3. **Ряд кнопок** (3 в ряд, карточки `AppTheme.card`, зелёная иконка): Звонок (`_callPhone`, если `phone` есть), Telegram (`_openTelegram`, если `telegramUrl`), Instagram (`_openInstagram`, если `instagramUrl`). Показываем только доступные; если доступна 1-2 — ряд распределяет их.
4. **Блок «Открытые турниры»** (зелёная карточка `AppTheme.accent`): иконка календаря + «Открытые турниры» + бейдж с числом `openTournamentsCount` + стрелка. Только если `openTournamentsCount > 0`. По нажатию → навигация в список турниров с фильтром по клубу (см. 2.3).
5. **Карточка адрес/город/телефон** через `_buildInfoRow` (телефон кликабельный, как сейчас).
6. **«О клубе»** — описание (как сейчас).
7. **Hide-toggle** — оставить внизу без изменений.

### 2.3. Навигация на турниры клуба
По тапу на блок турниров открыть `TournamentsScreen` с предустановленным фильтром по клубу. `TournamentsScreen`/`TournamentsFilter` поддерживает `clubIds: Set<int>`. Реализация: открыть экран турниров так, чтобы фильтр содержал `{club.id}` (например через конструкторный параметр `initialClubId`/`initialFilter`, либо установив фильтр в провайдере перед переходом — выбрать минимально инвазивный путь по факту кода). Игрок видит только турниры этого клуба.

### 2.4. Share
`Share.share` (пакет `share_plus` уже в проекте): текст = название клуба + первая доступная ссылка (`telegramUrl` или `instagramUrl`), если есть; иначе просто название.

## Не входит
Детальная строка «N ближайших», отдельный endpoint турниров клуба, обложка в публичном СПИСКЕ клубов (только в детали), редактирование обложки из приложения (обложка грузится только в веб-админке).

## Тестирование

### Backend (PHPUnit)
- Расширить публичный тест клуба (или `MobileClubEditTest`/новый): `GET /api/mobile/clubs/{id}` возвращает `cover` (url или null), `is_community` (bool), `open_tournaments_count` (число). Создать клуб с 1 открытым турниром (`status=open`, `start_date` в будущем) и 1 завершённым → count == 1.

### Mobile
Ручная проверка: обложка отображается (и заглушка при отсутствии); бейдж КОМЬЮНИТИ/КЛУБ; кнопки соц только при наличии данных; блок турниров виден при count>0 и открывает отфильтрованный список; share работает; адрес/город/телефон и описание на месте. `flutter analyze` без ошибок.

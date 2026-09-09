# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel 12 Padel tournament management application with Telegram Mini App integration. Supports multiple tournament formats (classic, americano, mexicano, team) with ELO-based player ratings.

## Essential Commands

```bash
# Full setup (install deps, generate key, migrate, build assets)
composer setup

# Development (starts server, queue, logs, vite concurrently)
composer dev

# Run tests
composer test

# Frontend only
npm run dev      # Dev server with HMR
npm run build    # Production build
```

## Architecture

### Backend Structure
- **Controllers** in `app/Http/Controllers/` organized by role:
  - `Admin/` - Super admin (club management)
  - `Api/` - Telegram Mini App endpoints
  - `Club/` - Club admin/moderator features
  - `Moderator/` - Moderator views
- **Services** in `app/Services/` handle business logic:
  - `AmericanoService`, `MexicanoService`, `TeamTournamentService` - Tournament format logic
  - `EloService` - Rating calculations
  - `TelegramChannelService`, `TelegramNotificationService` - Bot integration
- **Traits** in `app/Traits/`:
  - `RatingCalculator` - Shared ELO calculation logic used by all tournament services

### Key Controllers
- `Club/TournamentController.php` - Main tournament management (676 lines)
- `Api/TelegramMiniAppController.php` - Telegram Mini App API (767 lines)

### Authentication
- Laravel Breeze (email/password)
- Telegram Mini App (custom token validation via `TelegramMiniAppAuth` middleware)

### Role-Based Access
Roles: `player`, `club_admin`, `club_moderator`, `super_admin`
Protected via `RoleMiddleware` on route groups.

## Время: всегда Алматы (UTC+5)

**Правило без исключений.** В базе даты турниров, игр, броней и занятий лежат
**в местных часах Алматы** — 20:00 в поле значит 20:00 на корте, — хотя
`config('app.timezone')` = `UTC`. Поэтому:

- сравнивать с «сейчас» только через `now('Asia/Almaty')`;
- отдавать наружу через `->shiftTimezone('Asia/Almaty')->toIso8601String()`, чтобы
  метка несла `+05:00` и телефон не добавлял свои пять часов;
- «сегодня/завтра» сравнивать по календарному дню (`format('Y-m-d')`), а не по
  моменту: полночь в Алматы — не полночь UTC;
- исключение — настоящие моменты (`created_at`, `sent_at`, `now()` как отметка
  события): они записаны в UTC и отдаются как есть.

Ошибки этого класса выглядят как «турнир в 20:00 показан в 01:00» или как
«сегодняшний турнир подписан завтрашней датой». Подробности и известная кривизна
поля `datetime` в карточке турнира — в `docs/SYSTEM_RULES.md`, раздел «Время и
часовой пояс».

## Tournament System

**Four tournament types:**
1. Classic - Knockout or group + playoff
2. Americano - Round-robin groups with individual rankings
3. Mexicano - Rotation-based format with pair history tracking
4. Team - Doubles format with team-based groups and playoffs

**Playoff formats:** `mix`, `group_vs`, `tops`, `cross`, `balanced`

## Rating System (ELO-based)

- Base/minimum rating: 1000
- K-factor varies: K=48 (<2000), K=36 (2000-2500), K=24 (2500-4000)
- Player levels derived from rating (1-5+ scale)

## Routes

- Web: `routes/web.php`
- API (Telegram): `routes/api.php` - prefix `/api/tg/`
- Public: Rating preview routes, Telegram webhook at `/api/telegram/webhook`

## Database

SQLite by default. 18 Eloquent models in `app/Models/`. Key entities:
- Users, Clubs, ClubAdmins, ClubModerators
- Tournaments, TournamentParticipants, TournamentGroups
- Matches, AmericanoMatches, MexicanoMatches, TournamentPlayoffMatches
- RatingHistory for analytics

## Frontend

**Design system:** правила вёрстки веб-CRM — в `docs/CRM_DESIGN_SYSTEM.md`.
Кратко: классы берём из `resources/views/layouts/app.blade.php` (`page-header`,
`card-dark`, `btn-primary-custom`, `form-control`, `table-dark-custom`,
`badge-*-custom`), придуманных классов не изобретаем, ширина страницы 1200px,
иконки только `bi-*`, сложные условия — блочным `@php`, не инлайновым `@if`.
Новый общий класс кладём в layout и описываем в том же документе.

- Blade templates in `resources/views/` organized by feature
- Tailwind CSS for styling
- Alpine.js for interactivity
- Vite for bundling

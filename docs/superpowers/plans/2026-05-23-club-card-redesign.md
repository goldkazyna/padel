# Club Card Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the player club-detail screen to match the new mockup (cover background, logo+badge+name overlay, social buttons, green "open tournaments" card, address/city/phone, about) and enrich the public club payload.

**Architecture:** Backend adds `cover`, `is_community`, `open_tournaments_count` to `MobileClubController@show`. Flutter adds those fields to the `Club` model and rebuilds `club_detail_screen.dart` per the mockup, reusing existing launch helpers, `AppTheme` tokens, `share_plus`, and navigating to the existing `TournamentsScreen` pre-filtered by club.

**Tech Stack:** Laravel 12 / PHP 8.2 / sqlite tests / PHPUnit; Flutter (padel_app) Dart, share_plus, url_launcher, ARB l10n.

**Spec:** `docs/superpowers/specs/2026-05-23-club-card-redesign-design.md`

---

## Backend file map (C:\projects\padel)
- Modify: `app/Http/Controllers/Api/MobileClubController.php` (`show` payload)
- Test: `tests/Feature/MobileClubShowTest.php`

## Flutter file map (C:\projects\padel_app)
- Modify: `lib/models/club.dart` (cover, isCommunity, openTournamentsCount)
- Modify: `lib/screens/club_detail_screen.dart` (full redesign)

---

## Task 1: Backend — enrich `show` payload

**Files:**
- Modify: `app/Http/Controllers/Api/MobileClubController.php`
- Test: `tests/Feature/MobileClubShowTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MobileClubShowTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileClubShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_cover_community_and_open_count(): void
    {
        $club = Club::create([
            'name' => 'C', 'address' => 'A', 'city' => 'Алматы',
            'is_community' => true, 'cover' => '/covers/c.jpg',
        ]);

        // one OPEN future tournament (counts) + one completed (doesn't)
        Tournament::create([
            'club_id' => $club->id, 'name' => 'Open', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 8,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        Tournament::create([
            'club_id' => $club->id, 'name' => 'Done', 'type' => 'americano',
            'status' => 'completed', 'max_participants' => 8,
            'start_date' => now()->subDays(3), 'registration_deadline' => now()->subDays(4),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/clubs/{$club->id}")
            ->assertOk()
            ->assertJsonPath('club.is_community', true)
            ->assertJsonPath('club.open_tournaments_count', 1)
            ->assertJsonPath('club.cover', url('/covers/c.jpg'));
    }

    public function test_show_cover_null_when_absent(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/mobile/clubs/{$club->id}")
            ->assertOk()
            ->assertJsonPath('club.cover', null)
            ->assertJsonPath('club.open_tournaments_count', 0);
    }
}
```
If the public `show` route is NOT behind `auth:sanctum`, drop the `Sanctum::actingAs` lines. If `Tournament::create` needs more NOT-NULL columns, add them (the tournaments table requires `start_date`/`registration_deadline` — already set). If the club show is gated to non-hidden/active clubs, ensure the test club passes those (it's active by default).

- [ ] **Step 2: Run to confirm fail**

Run: `php artisan test --filter=MobileClubShowTest`
Expected: FAIL — keys `cover`/`is_community`/`open_tournaments_count` missing.

- [ ] **Step 3: Add the keys to `show()`**

In `app/Http/Controllers/Api/MobileClubController.php`, inside the `show()` club array (next to the existing `logo`/`telegram_url` keys), add:

```php
'cover' => $club->cover ? url($club->cover) : null,
'is_community' => (bool) $club->is_community,
'open_tournaments_count' => $club->tournaments()
    ->where('status', 'open')
    ->where('start_date', '>', now())
    ->count(),
```
Use the real local variable for the club inside `show()` (likely `$club`). Mirror the `url(...)` helper exactly as `logo` uses it.

- [ ] **Step 4: Run to confirm pass**

Run: `php artisan test --filter=MobileClubShowTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileClubController.php tests/Feature/MobileClubShowTest.php
git commit -m "feat(api): club show returns cover, is_community, open_tournaments_count"
```

---

## Task 2: Flutter — Club model fields

**Files:**
- Modify: `C:\projects\padel_app\lib\models\club.dart`

- [ ] **Step 1: Add fields**

In `lib/models/club.dart`:
- declarations (near `logo`/`isHidden`): `final String? cover;`, `final bool isCommunity;`, `final int openTournamentsCount;`
- constructor: `this.cover,`, `this.isCommunity = false,`, `this.openTournamentsCount = 0,`
- `fromJson`:
  ```dart
  cover: json['cover'] as String?,
  isCommunity: json['is_community'] as bool? ?? false,
  openTournamentsCount: json['open_tournaments_count'] as int? ?? 0,
  ```
- If `copyWith` exists and lists fields, add the three there too (mirror existing fields).

- [ ] **Step 2: Static-check**

Run: `cd C:\projects\padel_app && flutter analyze lib/models/club.dart`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
cd C:\projects\padel_app
git add lib/models/club.dart
git commit -m "feat(club): cover, isCommunity, openTournamentsCount in model"
```

---

## Task 3: Flutter — redesign club_detail_screen

**Files:**
- Modify: `C:\projects\padel_app\lib\screens\club_detail_screen.dart`

This is a focused UI rebuild of one screen. KEEP: the load logic (`ClubService.getClub`, `_club`, loading/error state), the launch helpers (`_openMaps`, `_callPhone`, `_openTelegram`, `_openInstagram`), `_buildInfoRow`, the description section, the hide-toggle. REPLACE the header/layout with the mockup structure.

- [ ] **Step 0: Read the current screen**
Read `lib/screens/club_detail_screen.dart` fully and note: the exact load/state code, helper signatures, `_buildInfoRow` signature, `_buildDescription`, the hide-toggle widget, the `_buildInitials` fallback, and the `AppTheme` tokens used. Read `lib/screens/tournaments_screen.dart` to learn how its filter (`TournamentsFilter` with `clubIds: Set<int>`) is set — does `TournamentsScreen` take a constructor param, or is the filter applied via a provider, or is it a tab inside a shell? Determine the least-invasive way to open it pre-filtered to one club id (e.g. add an optional `initialClubId`/`initialFilter` constructor param to `TournamentsScreen`, or push it and set the filter). Read `lib/theme/app_theme.dart` for the exact token names.

- [ ] **Step 1: Build the cover header**

Replace the current simple AppBar with a `Stack`-based cover header (~32% of screen height, e.g. `MediaQuery.of(context).size.height * 0.32`):
- Background: if `club.cover != null` → `Image.network(club.cover!, fit: BoxFit.cover, width: double.infinity, errorBuilder: (_, __, ___) => Container(color: AppTheme.card))`; else `Container(color: AppTheme.card)`.
- A bottom-up dark gradient overlay for legibility: `DecoratedBox(decoration: BoxDecoration(gradient: LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [Colors.transparent, Colors.black.withOpacity(0.7)])))`.
- Top-left circular **Back** button and top-right circular **Share** button (over the cover, inside `SafeArea`), each a `Material`/`InkWell` circle with `Colors.black.withOpacity(0.35)` bg and white icon (`Icons.arrow_back_ios_new` / `Icons.ios_share`).
- Bottom overlay (Positioned, left/right/bottom): Row[ circular logo (Image.network + `_buildInitials` fallback, ~64px, white border), SizedBox, Column[ badge + name ] ].
  - Badge: small pill, bg `AppTheme.accent` (or `accentSoft`), text white/accent, value `club.isCommunity ? 'КОМЬЮНИТИ' : 'КЛУБ'`.
  - Name: `club.name`, white, bold, ~22px.

- [ ] **Step 2: Social action row**

Below the cover, a `Row` of up to three equal cards (`Expanded`), each: `AppTheme.card` bg, rounded, column[ rounded square with green icon (`AppTheme.accentSoft` bg, `AppTheme.accent` icon), label ]. Only render the ones with data:
- Звонок — if `(club.phone ?? '').isNotEmpty` → `_callPhone(club.phone!)`, icon `Icons.call`.
- Telegram — if `(club.telegramUrl ?? '').isNotEmpty` → `_openTelegram(club.telegramUrl!)`, icon `Icons.send`.
- Instagram — if `(club.instagramUrl ?? '').isNotEmpty` → `_openInstagram(club.instagramUrl!)`, icon `Icons.camera_alt_outlined`.
Build a list of the available ones and lay them out with `Expanded` + gaps; if none available, omit the row.

- [ ] **Step 3: Open-tournaments card**

Only if `club.openTournamentsCount > 0`, a full-width green card (`AppTheme.accent` bg, rounded, padding) as an `InkWell`:
- Row[ calendar icon in a translucent white rounded square, Column[ 'Открытые турниры' bold white, optional small caption omitted per spec ], a count pill (white-ish bg, dark/green number = `club.openTournamentsCount`), chevron `Icons.chevron_right` white ].
- `onTap`: navigate to the club-filtered tournaments (Step 5).

- [ ] **Step 4: Info card + about + hide toggle**

- Address/city/phone via the existing `_buildInfoRow` (keep its current behavior: phone tap dials, address tap opens maps). Wrap in the existing card style.
- About ("О клубе") description via the existing `_buildDescription` (or its markup) — keep.
- Keep the existing hide-toggle widget at the bottom unchanged.
Wrap everything below the cover in a scroll view (`SingleChildScrollView` / `ListView`) so the page scrolls; the cover scrolls away with the content (simple approach — no SliverAppBar required).

- [ ] **Step 5: Navigation to club tournaments**

Implement the tap handler from Step 3 using the least-invasive approach found in Step 0. Preferred: add an optional `initialClubId` (or `initialFilter`) param to `TournamentsScreen` and apply it to its filter in `initState`, then:
```dart
Navigator.push(context, MaterialPageRoute(
  builder: (_) => TournamentsScreen(initialClubId: club.id),
));
```
If `TournamentsScreen` is a tab inside a shell and can't be pushed standalone cleanly, instead set the tournaments filter via the existing provider/state to `clubIds: {club.id}` and switch to that tab. Choose whichever matches the app's real navigation; keep it minimal and don't break the existing tournaments tab.

- [ ] **Step 6: Share**

Wire the Share button (`share_plus` is in the project; `import 'package:share_plus/share_plus.dart';`):
```dart
void _share() {
  final parts = <String>[club.name];
  final link = (club.telegramUrl?.isNotEmpty ?? false)
      ? club.telegramUrl!
      : ((club.instagramUrl?.isNotEmpty ?? false) ? club.instagramUrl! : null);
  if (link != null) parts.add(link);
  Share.share(parts.join('\n'));
}
```
Use the loaded `club` instance; guard if `_club == null`.

- [ ] **Step 7: Static-check**

Run: `cd C:\projects\padel_app && flutter analyze lib/screens/club_detail_screen.dart lib/screens/tournaments_screen.dart`
Expected: no errors (pre-existing info warnings OK). If you added a param to `TournamentsScreen`, ensure all existing call sites still compile (a default/optional param keeps them working).

- [ ] **Step 8: Manual verification**

Run the app (`flutter run`): open a club — cover shows (placeholder if none); back+share work; logo+badge(КОМЬЮНИТИ/КЛУБ)+name overlay correct; only available social buttons show and launch; tournaments card appears when count>0 and opens the tournaments list filtered to this club; address/city/phone + about render; hide-toggle still works.

- [ ] **Step 9: Commit**

```bash
cd C:\projects\padel_app
git add lib/screens/club_detail_screen.dart lib/screens/tournaments_screen.dart
git commit -m "feat(club): redesign club detail screen (cover, social, tournaments)"
```

---

## Self-Review

**Spec coverage:** backend payload cover/is_community/open_tournaments_count + tests (Task 1); Club model fields (Task 2); cover header + back/share + logo/badge/name overlay + social row (data-gated) + green tournaments card (count>0) + nav to club-filtered tournaments + info card + about + hide-toggle kept + share (Task 3). "Only count, no detail line" honored (Step 3 omits caption). Logo/cover in list, separate endpoint, in-app cover edit all correctly excluded.

**Placeholder scan:** No TBD/TODO. Steps include concrete widget code; the navigation step gives a concrete preferred approach + fallback, both actionable (not deferred). Step 0 directs reading the real screen/theme/nav before coding — appropriate for a redesign, not a placeholder.

**Type consistency:** payload keys `cover`/`is_community`/`open_tournaments_count` (Task 1) ↔ `Club` `cover`/`isCommunity`/`openTournamentsCount` parsing those keys (Task 2) ↔ screen uses `club.cover`/`club.isCommunity`/`club.openTournamentsCount` (Task 3). `TournamentsScreen(initialClubId:)` introduced in Task 3 Step 5 used in Step 5 nav. Launch helpers reused with existing names. share_plus `Share.share`. Consistent.

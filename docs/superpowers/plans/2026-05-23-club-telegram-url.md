# Club Telegram URL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a public `telegram_url` (t.me link) to a club — editable in the web form and in-app by the owner, and shown to players as a "Telegram channel" button.

**Architecture:** New nullable `telegram_url` column on `clubs`. Backend exposes it in the web edit form, the mobile owner-edit endpoint (`MobileAdminClubController`), and the public player payloads (`MobileClubController`). Flutter adds it to the owner edit screen and shows a launch button on the player club-detail screen via `url_launcher`.

**Tech Stack:** Laravel 12 / PHP 8.2 / MySQL+sqlite / PHPUnit; Flutter (padel_app) Dart, url_launcher, ARB l10n.

**Spec:** `docs/superpowers/specs/2026-05-23-club-telegram-url-design.md`

**Field:** `telegram_url`, validation `nullable|url|max:500`. Separate from super-admin `telegram_channel_id` / `telegram_bot_token`.

---

## Backend file map (C:\projects\padel)
- Create: `database/migrations/2026_05_23_000001_add_telegram_url_to_clubs_table.php`
- Modify: `app/Models/Club.php` (fillable)
- Modify: `resources/views/admin/clubs/edit.blade.php` (web field)
- Modify: `app/Http/Controllers/Admin/ClubController.php` (web validation)
- Modify: `app/Http/Controllers/Api/MobileAdminClubController.php` (payload + update validation)
- Modify: `app/Http/Controllers/Api/MobileClubController.php` (public index + show payloads)
- Modify: `tests/Feature/MobileClubEditTest.php`

## Flutter file map (C:\projects\padel_app)
- Modify: `lib/l10n/app_{ru,en,kk}.arb`
- Modify: `lib/models/admin_club_edit.dart`, `lib/screens/admin/admin_edit_club_screen.dart`
- Modify: `lib/models/club.dart`, `lib/screens/club_detail_screen.dart`

---

## Task 1: Backend — migration, model, web form

**Files:**
- Create: `database/migrations/2026_05_23_000001_add_telegram_url_to_clubs_table.php`
- Modify: `app/Models/Club.php`
- Modify: `resources/views/admin/clubs/edit.blade.php`
- Modify: `app/Http/Controllers/Admin/ClubController.php`

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('telegram_url')->nullable()->after('payment_url');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('telegram_url');
        });
    }
};
```
If `2026_05_23_000001` already exists, bump the suffix (e.g. `_000002`). If `payment_url` column doesn't exist to anchor `after()`, drop the `->after(...)`.

- [ ] **Step 2: Run the migration (and verify column)**

Run: `php artisan migrate`
Expected: migration runs; `clubs.telegram_url` exists. (Tests use `RefreshDatabase` so they pick it up automatically.)

- [ ] **Step 3: Add to Club fillable**

In `app/Models/Club.php`, add `'telegram_url',` to the `$fillable` array (next to `payment_url`).

- [ ] **Step 4: Add the web form field**

In `resources/views/admin/clubs/edit.blade.php`, add a field BEFORE the existing "Telegram — ID канала" block (around line 145), so the public link sits separate from the super-admin telegram settings:

```blade
<div class="mb-4">
    <label class="form-label">Телеграм-канал (ссылка)</label>
    <input type="text" name="telegram_url" class="form-control @error('telegram_url') is-invalid @enderror"
           value="{{ old('telegram_url', $club->telegram_url) }}" placeholder="https://t.me/yourchannel">
    @error('telegram_url')
        <div class="text-danger mt-2 small">{{ $message }}</div>
    @enderror
    <small class="text-muted">Публичная ссылка на телеграм-канал клуба (видна игрокам).</small>
</div>
```

- [ ] **Step 5: Add web validation**

In `app/Http/Controllers/Admin/ClubController.php::update`, in the `$request->validate([...])` array (near `'payment_url' => 'nullable|url|max:500',`), add:
```php
'telegram_url' => 'nullable|url|max:500',
```

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_23_000001_add_telegram_url_to_clubs_table.php app/Models/Club.php resources/views/admin/clubs/edit.blade.php app/Http/Controllers/Admin/ClubController.php
git commit -m "feat(club): add telegram_url column + web form field"
```

---

## Task 2: Backend — mobile owner-edit + public payloads + tests

**Files:**
- Modify: `app/Http/Controllers/Api/MobileAdminClubController.php`
- Modify: `app/Http/Controllers/Api/MobileClubController.php`
- Modify: `tests/Feature/MobileClubEditTest.php`

- [ ] **Step 1: Extend the owner-edit test**

Add to `tests/Feature/MobileClubEditTest.php` a test (the file already has a `club()` helper and Sanctum auth pattern):

```php
    public function test_owner_can_set_telegram_url_and_show_returns_it(): void
    {
        $club = $this->club();
        $admin = \App\Models\User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $this->putJson("/api/mobile/admin/clubs/{$club->id}", [
            'name' => 'X', 'address' => 'Y', 'telegram_url' => 'https://t.me/myclub',
        ])->assertOk()->assertJsonPath('club.telegram_url', 'https://t.me/myclub');

        $this->assertSame('https://t.me/myclub', $club->refresh()->telegram_url);

        $this->getJson("/api/mobile/admin/clubs/{$club->id}")
            ->assertOk()->assertJsonPath('club.telegram_url', 'https://t.me/myclub');

        // invalid url rejected
        $this->putJson("/api/mobile/admin/clubs/{$club->id}", [
            'name' => 'X', 'address' => 'Y', 'telegram_url' => 'not-a-url',
        ])->assertStatus(422);
    }
```

- [ ] **Step 2: Run to confirm fail**

Run: `php artisan test --filter=MobileClubEditTest::test_owner_can_set_telegram_url_and_show_returns_it`
Expected: FAIL — `club.telegram_url` null/missing (controller doesn't handle it yet).

- [ ] **Step 3: Update MobileAdminClubController**

In `app/Http/Controllers/Api/MobileAdminClubController.php`:
- In `payload()` add `'telegram_url' => $club->telegram_url,`.
- In `update()` validation array add `'telegram_url' => 'nullable|url|max:500',`.

- [ ] **Step 4: Update public player payloads**

In `app/Http/Controllers/Api/MobileClubController.php`, add `'telegram_url' => $club->telegram_url,` to BOTH the list mapping (`index`, ~lines 47-56) and the detail mapping (`show`, ~lines 91-106). Match the existing array style/keys.

- [ ] **Step 5: Run to confirm pass**

Run: `php artisan test --filter=MobileClubEditTest`
Expected: PASS (all tests, including the new telegram one).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/MobileAdminClubController.php app/Http/Controllers/Api/MobileClubController.php tests/Feature/MobileClubEditTest.php
git commit -m "feat(api): telegram_url in club edit + public club payloads"
```

---

## Task 3: Flutter — localization

**Files:**
- Modify: `C:\projects\padel_app\lib\l10n\app_ru.arb`, `app_en.arb`, `app_kk.arb`

- [ ] **Step 1: Add keys (valid JSON)**

`app_ru.arb`:
```json
"clubTelegram": "Телеграм-канал",
"openTelegramChannel": "Открыть телеграм-канал"
```
`app_en.arb`:
```json
"clubTelegram": "Telegram channel",
"openTelegramChannel": "Open Telegram channel"
```
`app_kk.arb`:
```json
"clubTelegram": "Телеграм-арна",
"openTelegramChannel": "Телеграм-арнаны ашу"
```
If a key already exists, skip it (report which).

- [ ] **Step 2: Regenerate**

Run: `cd C:\projects\padel_app && flutter gen-l10n`
Expected: regenerates `lib/l10n/app_localizations*.dart`, no errors; `clubTelegram` getter present.

- [ ] **Step 3: Commit**

```bash
cd C:\projects\padel_app
git add lib/l10n/app_ru.arb lib/l10n/app_en.arb lib/l10n/app_kk.arb lib/l10n/app_localizations*.dart
git commit -m "i18n: club telegram channel strings (ru/en/kk)"
```

---

## Task 4: Flutter — owner edit (model + screen)

**Files:**
- Modify: `C:\projects\padel_app\lib\models\admin_club_edit.dart`
- Modify: `C:\projects\padel_app\lib\screens\admin\admin_edit_club_screen.dart`

- [ ] **Step 1: Add `telegramUrl` to AdminClubEdit**

In `lib/models/admin_club_edit.dart`:
- field: `final String? telegramUrl;`
- constructor: `this.telegramUrl,`
- fromJson: `telegramUrl: json['telegram_url'] as String?,`

- [ ] **Step 2: Add the field to the edit screen**

In `lib/screens/admin/admin_edit_club_screen.dart`:
- Add a controller: `final _telegramUrl = TextEditingController();` and dispose it in `dispose()`.
- In `_load()`, after the other fields: `_telegramUrl.text = club.telegramUrl ?? '';`
- In `build()`, add a field row after the payment-url field (mirror the existing `_field(...)` rows used in this screen):
  ```dart
  _field(AppLocalizations.of(context)!.clubTelegram, _telegramUrl, keyboard: TextInputType.url),
  ```
  (Use the screen's actual field-builder helper name and signature — it was created in the previous feature; mirror how `_paymentUrl` is rendered.)
- In `_save()`, add to the body map:
  ```dart
  'telegram_url': _telegramUrl.text.trim().isEmpty ? null : _telegramUrl.text.trim(),
  ```

- [ ] **Step 3: Static-check**

Run: `cd C:\projects\padel_app && flutter analyze lib/models/admin_club_edit.dart lib/screens/admin/admin_edit_club_screen.dart`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
cd C:\projects\padel_app
git add lib/models/admin_club_edit.dart lib/screens/admin/admin_edit_club_screen.dart
git commit -m "feat(admin): edit club telegram channel link"
```

---

## Task 5: Flutter — player display (model + club detail button)

**Files:**
- Modify: `C:\projects\padel_app\lib\models\club.dart`
- Modify: `C:\projects\padel_app\lib\screens\club_detail_screen.dart`

- [ ] **Step 1: Add `telegramUrl` to the player Club model**

In `lib/models/club.dart`:
- field: `final String? telegramUrl;` (next to `phone`)
- constructor: `this.telegramUrl,`
- fromJson (~line 34): `telegramUrl: json['telegram_url'] as String?,`

- [ ] **Step 2: Add the launch helper + button**

In `lib/screens/club_detail_screen.dart`:
- Add a helper (near the existing maps/phone launch helpers):
  ```dart
  Future<void> _openTelegram(String url) async {
    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }
  ```
  (Confirm `url_launcher` is imported — it is used already in this file for maps/phone.)
- After the phone info row (~line 181), add a Telegram row shown only when the link is set. Mirror the existing `_buildInfoRow(...)` calls in this file (use its real signature — icon/label/value/onTap):
  ```dart
  if ((club.telegramUrl ?? '').isNotEmpty)
    _buildInfoRow(
      icon: Icons.send,
      label: AppLocalizations.of(context)!.clubTelegram,
      value: AppLocalizations.of(context)!.openTelegramChannel,
      onTap: () => _openTelegram(club.telegramUrl!),
    ),
  ```
  Adjust to the EXACT `_buildInfoRow` parameter names/positional usage in this file (read it first — it may take positional args or different names). The club variable accessor here is whatever the screen uses for the loaded club.

- [ ] **Step 3: Static-check**

Run: `cd C:\projects\padel_app && flutter analyze lib/models/club.dart lib/screens/club_detail_screen.dart`
Expected: no errors.

- [ ] **Step 4: Manual verification**

Run the app (`flutter run`): owner edits the club, sets a `https://t.me/...` link, saves; opens the club detail as a player → a "Telegram channel" row appears and opens the channel; with an empty link the row is absent.

- [ ] **Step 5: Commit**

```bash
cd C:\projects\padel_app
git add lib/models/club.dart lib/screens/club_detail_screen.dart
git commit -m "feat(club): show telegram channel button on club detail"
```

---

## Self-Review

**Spec coverage:** migration + fillable + web form/validation (Task 1); mobile owner-edit payload+validation + public player payloads + tests (Task 2); l10n (Task 3); Flutter owner edit model+screen (Task 4); Flutter player model + club-detail launch button (Task 5). Super-admin telegram fields untouched; logo out of scope (no logo code). All spec sections map to a task.

**Placeholder scan:** No TBD/TODO. Code shown in each step. "Mirror the real _field/_buildInfoRow signature" notes point at concrete existing helpers to match and say what to verify — not deferred work.

**Type consistency:** Column/key `telegram_url` everywhere (migration, fillable, web, mobile admin payload+validation, public payload, tests) ↔ Flutter `telegram_url`→`telegramUrl` in both `AdminClubEdit` (Task 4) and player `Club` (Task 5). Edit screen sends body key `telegram_url` (Task 4) matching backend validation (Task 2). Player button reads `club.telegramUrl` (Task 5) populated from public payload (Task 2). l10n keys `clubTelegram`/`openTelegramChannel` defined in Task 3, used in Tasks 4-5. Consistent.

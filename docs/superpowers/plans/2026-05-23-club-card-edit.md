# Club Card Edit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a club owner (`club_admin`) edit their club card from the mobile app (name, address, city, phone, email, description, payment_url) — no logo, no super-admin fields.

**Architecture:** Backend adds `MobileAdminClubController` (show/update, owner-only auth) + two routes. Flutter adds an `AdminClubEdit` model, `AdminService.getClub/updateClub`, an `AdminEditClubScreen` mirroring `edit_profile_screen.dart` (text-only), and an "Edit club card" button in `admin_club_block.dart` shown only to owners.

**Tech Stack:** Laravel 12 / PHP 8.2 / MySQL+sqlite / PHPUnit + Sanctum; Flutter (padel_app) Dart, ARB l10n.

**Spec:** `docs/superpowers/specs/2026-05-23-club-card-edit-design.md`

**Editable fields:** name, address, city, phone, email, description, payment_url. **Excluded (super-admin only, untouched):** is_active, telegram_channel_id, telegram_bot_token, features.

---

## Backend file map (C:\projects\padel)
- Create: `app/Http/Controllers/Api/MobileAdminClubController.php`
- Modify: `routes/api.php` (add GET+PUT next to other `/admin/clubs/{club}/...` routes, ~line 95)
- Test: `tests/Feature/MobileClubEditTest.php`

## Flutter file map (C:\projects\padel_app)
- Create: `lib/models/admin_club_edit.dart`
- Modify: `lib/services/admin_service.dart` (getClub/updateClub)
- Create: `lib/screens/admin/admin_edit_club_screen.dart`
- Modify: `lib/widgets/home/admin_club_block.dart` (button, owner-only)
- Modify: `lib/l10n/app_ru.arb`, `app_en.arb`, `app_kk.arb`

---

## Task 1: Backend — MobileAdminClubController + routes

**Files:**
- Create: `app/Http/Controllers/Api/MobileAdminClubController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/MobileClubEditTest.php`

Owner-only auth mirrors `MobileAdminUserController::canManageClub` (super_admin OR `adminClubs`, NOT moderators) and its `forbidden()` (403 json).

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/MobileClubEditTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileClubEditTest extends TestCase
{
    use RefreshDatabase;

    private function club(): Club
    {
        return Club::create([
            'name' => 'Old', 'address' => 'Old addr', 'city' => 'Алматы',
            'is_active' => true, 'features' => ['tournaments' => true],
            'telegram_channel_id' => 'chan-1', 'telegram_bot_token' => 'tok-1',
        ]);
    }

    public function test_owner_can_show_and_update_club(): void
    {
        $club = $this->club();
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);

        $this->getJson("/api/mobile/admin/clubs/{$club->id}")
            ->assertOk()->assertJsonPath('club.name', 'Old');

        $this->putJson("/api/mobile/admin/clubs/{$club->id}", [
            'name' => 'New name', 'address' => 'New addr', 'city' => 'Астана',
            'phone' => '+7700', 'email' => 'a@b.kz', 'description' => 'desc',
            'payment_url' => 'https://pay.kz/x',
        ])->assertOk()->assertJsonPath('club.name', 'New name');

        $club->refresh();
        $this->assertSame('New name', $club->name);
        $this->assertSame('Астана', $club->city);
        $this->assertSame('https://pay.kz/x', $club->payment_url);
    }

    public function test_update_does_not_touch_superadmin_fields(): void
    {
        $club = $this->club();
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);

        $this->putJson("/api/mobile/admin/clubs/{$club->id}", [
            'name' => 'X', 'address' => 'Y',
            'is_active' => false, 'telegram_bot_token' => 'HACKED',
            'features' => ['tournaments' => false],
        ])->assertOk();

        $club->refresh();
        $this->assertTrue((bool) $club->is_active, 'is_active must be unchanged');
        $this->assertSame('tok-1', $club->telegram_bot_token, 'bot token unchanged');
        $this->assertSame(['tournaments' => true], $club->features, 'features unchanged');
    }

    public function test_validation_errors(): void
    {
        $club = $this->club();
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);

        $this->putJson("/api/mobile/admin/clubs/{$club->id}", [
            'name' => 'X', 'address' => 'Y', 'city' => 'Париж',
        ])->assertStatus(422);

        $this->putJson("/api/mobile/admin/clubs/{$club->id}", [
            'name' => 'X', 'address' => 'Y', 'email' => 'not-email',
        ])->assertStatus(422);

        $this->putJson("/api/mobile/admin/clubs/{$club->id}", [
            'name' => 'X', 'address' => 'Y', 'payment_url' => 'not-url',
        ])->assertStatus(422);
    }

    public function test_moderator_forbidden(): void
    {
        $club = $this->club();
        $mod = User::factory()->create(['role' => 'club_moderator']);
        $mod->moderatorClubs()->attach($club->id);
        Sanctum::actingAs($mod);

        $this->getJson("/api/mobile/admin/clubs/{$club->id}")->assertStatus(403);
        $this->putJson("/api/mobile/admin/clubs/{$club->id}", ['name' => 'X', 'address' => 'Y'])
            ->assertStatus(403);
    }
}
```

Notes: verify `moderatorClubs()` exists on User (it does). If `Club::create` requires extra NOT-NULL columns, add them in the `club()` helper. If the test auth differs from how `TournamentRestartEndpointTest` authenticates, mirror that (it uses `Sanctum::actingAs`).

- [ ] **Step 2: Run to confirm fail**

Run: `php artisan test --filter=MobileClubEditTest`
Expected: FAIL — 404 (routes missing).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/MobileAdminClubController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileAdminClubController extends Controller
{
    /** Только владелец клуба (или супер-админ). Модератор — нет. */
    private function canEditClub($user, Club $club): bool
    {
        if (!$user) return false;
        if ($user->isSuperAdmin()) return true;
        return $user->adminClubs()->where('clubs.id', $club->id)->exists();
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Нет доступа к этому клубу'], 403);
    }

    private function payload(Club $club): array
    {
        return [
            'id' => $club->id,
            'name' => $club->name,
            'address' => $club->address,
            'city' => $club->city,
            'phone' => $club->phone,
            'email' => $club->email,
            'description' => $club->description,
            'payment_url' => $club->payment_url,
        ];
    }

    public function show(Request $request, Club $club): JsonResponse
    {
        if (!$this->canEditClub($request->user(), $club)) {
            return $this->forbidden();
        }
        return response()->json(['success' => true, 'club' => $this->payload($club)]);
    }

    public function update(Request $request, Club $club): JsonResponse
    {
        if (!$this->canEditClub($request->user(), $club)) {
            return $this->forbidden();
        }

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

        return response()->json(['success' => true, 'club' => $this->payload($club->fresh())]);
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/api.php`, right after the existing `/admin/clubs/{club}/users` routes (~line 94), inside the same `Route::prefix('mobile')->middleware('auth:sanctum')` group:

```php
Route::get('/admin/clubs/{club}', [\App\Http\Controllers\Api\MobileAdminClubController::class, 'show']);
Route::put('/admin/clubs/{club}', [\App\Http\Controllers\Api\MobileAdminClubController::class, 'update']);
```

(Place AFTER the `/admin/clubs/{club}/tournaments` and `/admin/clubs/{club}/users` lines so the more specific routes are registered; Laravel matches by path so order isn't strictly required, but keep them grouped.)

- [ ] **Step 5: Run to confirm pass**

Run: `php artisan test --filter=MobileClubEditTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/MobileAdminClubController.php routes/api.php tests/Feature/MobileClubEditTest.php
git commit -m "feat(api): mobile club card show/update (owner only)"
```

---

## Task 2: Flutter — AdminClubEdit model + AdminService methods

**Files:**
- Create: `C:\projects\padel_app\lib\models\admin_club_edit.dart`
- Modify: `C:\projects\padel_app\lib\services\admin_service.dart`

- [ ] **Step 1: Create the model**

Create `lib/models/admin_club_edit.dart`:

```dart
class AdminClubEdit {
  final int id;
  final String name;
  final String address;
  final String? city;
  final String? phone;
  final String? email;
  final String? description;
  final String? paymentUrl;

  AdminClubEdit({
    required this.id,
    required this.name,
    required this.address,
    this.city,
    this.phone,
    this.email,
    this.description,
    this.paymentUrl,
  });

  factory AdminClubEdit.fromJson(Map<String, dynamic> json) {
    return AdminClubEdit(
      id: json['id'] as int,
      name: (json['name'] as String?) ?? '',
      address: (json['address'] as String?) ?? '',
      city: json['city'] as String?,
      phone: json['phone'] as String?,
      email: json['email'] as String?,
      description: json['description'] as String?,
      paymentUrl: json['payment_url'] as String?,
    );
  }
}
```

- [ ] **Step 2: Add service methods**

In `lib/services/admin_service.dart`, add (use the exact `_api.get(path, token)` / `_api.put(path, body, token)` and `_storage.getToken()` patterns already used in this file; add the import for the model):

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

Add at top of the file: `import '../models/admin_club_edit.dart';` (match the existing model import style/path in this file).

- [ ] **Step 3: Static-check**

Run: `cd C:\projects\padel_app && flutter analyze lib/models/admin_club_edit.dart lib/services/admin_service.dart`
Expected: no errors for these files.

- [ ] **Step 4: Commit**

```bash
cd C:\projects\padel_app
git add lib/models/admin_club_edit.dart lib/services/admin_service.dart
git commit -m "feat(admin): AdminClubEdit model + getClub/updateClub"
```

---

## Task 3: Flutter — localization keys

**Files:**
- Modify: `C:\projects\padel_app\lib\l10n\app_ru.arb` (template), `app_en.arb`, `app_kk.arb`

- [ ] **Step 1: Add keys to each ARB (valid JSON)**

`app_ru.arb`:
```json
"editClubCard": "Редактировать карточку клуба",
"editClubCardSubtitle": "Название, контакты, описание",
"clubName": "Название клуба",
"clubAddress": "Адрес",
"clubCity": "Город",
"clubPhone": "Телефон",
"clubEmail": "Email",
"clubDescription": "Описание",
"clubPaymentUrl": "Ссылка для оплаты",
"clubCardSaved": "Карточка клуба сохранена"
```
`app_en.arb`:
```json
"editClubCard": "Edit club card",
"editClubCardSubtitle": "Name, contacts, description",
"clubName": "Club name",
"clubAddress": "Address",
"clubCity": "City",
"clubPhone": "Phone",
"clubEmail": "Email",
"clubDescription": "Description",
"clubPaymentUrl": "Payment link",
"clubCardSaved": "Club card saved"
```
`app_kk.arb`:
```json
"editClubCard": "Клуб картасын өңдеу",
"editClubCardSubtitle": "Атауы, байланыстар, сипаттама",
"clubName": "Клуб атауы",
"clubAddress": "Мекенжай",
"clubCity": "Қала",
"clubPhone": "Телефон",
"clubEmail": "Email",
"clubDescription": "Сипаттама",
"clubPaymentUrl": "Төлем сілтемесі",
"clubCardSaved": "Клуб картасы сақталды"
```
Reuse existing generic keys for "Save"/"Required" if they already exist (check ARB); only add the above club-specific keys. Plain strings — no `@`-metadata blocks needed (match the file's convention for placeholder-less keys).

- [ ] **Step 2: Regenerate**

Run: `cd C:\projects\padel_app && flutter gen-l10n`
Expected: regenerates `lib/l10n/app_localizations*.dart`, no errors. Confirm `editClubCard` getter exists in the generated file.

- [ ] **Step 3: Commit**

```bash
cd C:\projects\padel_app
git add lib/l10n/app_ru.arb lib/l10n/app_en.arb lib/l10n/app_kk.arb lib/l10n/app_localizations*.dart
git commit -m "i18n: club card edit strings (ru/en/kk)"
```
(Generated dart files are tracked in this repo — include them.)

---

## Task 4: Flutter — AdminEditClubScreen

**Files:**
- Create: `C:\projects\padel_app\lib\screens\admin\admin_edit_club_screen.dart`

Mirror `lib/screens/edit_profile_screen.dart` (header with back+save, scroll body, text rows, `_load`, `_save`, `showAppAlert` on error) but text-only. City uses a modal bottom-sheet picker over the 5 cities with `localizeCity` (same pattern as `_pickCity` in edit_profile_screen).

- [ ] **Step 1: Create the screen**

Create `lib/screens/admin/admin_edit_club_screen.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../services/admin_service.dart';
import '../../models/admin_club_edit.dart';
import '../../l10n/app_localizations.dart';
import '../../utils/app_alert.dart';
import '../../utils/city_l10n.dart';
import '../../widgets/app_back_button.dart';
import '../../theme/app_theme.dart';

class AdminEditClubScreen extends StatefulWidget {
  final int clubId;
  const AdminEditClubScreen({super.key, required this.clubId});

  @override
  State<AdminEditClubScreen> createState() => _AdminEditClubScreenState();
}

class _AdminEditClubScreenState extends State<AdminEditClubScreen> {
  static const _cities = ['Алматы', 'Астана', 'Шымкент', 'Караганда', 'Актобе'];

  final _name = TextEditingController();
  final _address = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _description = TextEditingController();
  final _paymentUrl = TextEditingController();
  String? _city;

  bool _loading = true;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    _address.dispose();
    _phone.dispose();
    _email.dispose();
    _description.dispose();
    _paymentUrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final club = await context.read<AdminService>().getClub(widget.clubId);
      if (!mounted) return;
      setState(() {
        _name.text = club.name;
        _address.text = club.address;
        _city = club.city;
        _phone.text = club.phone ?? '';
        _email.text = club.email ?? '';
        _description.text = club.description ?? '';
        _paymentUrl.text = club.paymentUrl ?? '';
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _loading = false);
      showAppAlert(context, e.toString(), title: 'Ошибка', isError: true);
    }
  }

  Future<void> _save() async {
    final l10n = AppLocalizations.of(context)!;
    if (_name.text.trim().isEmpty || _address.text.trim().isEmpty) {
      showAppAlert(context, '${l10n.clubName} / ${l10n.clubAddress}', title: 'Ошибка', isError: true);
      return;
    }
    setState(() => _saving = true);
    try {
      final body = <String, dynamic>{
        'name': _name.text.trim(),
        'address': _address.text.trim(),
        'city': _city,
        'phone': _phone.text.trim().isEmpty ? null : _phone.text.trim(),
        'email': _email.text.trim().isEmpty ? null : _email.text.trim(),
        'description': _description.text.trim().isEmpty ? null : _description.text.trim(),
        'payment_url': _paymentUrl.text.trim().isEmpty ? null : _paymentUrl.text.trim(),
      };
      await context.read<AdminService>().updateClub(widget.clubId, body);
      if (!mounted) return;
      showAppAlert(context, l10n.clubCardSaved);
      Navigator.pop(context, true);
    } catch (e) {
      if (!mounted) return;
      showAppAlert(context, e.toString(), title: 'Ошибка', isError: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _pickCity() async {
    await showModalBottomSheet(
      context: context,
      backgroundColor: AppTheme.card,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: _cities.map((c) => ListTile(
            title: Text(localizeCity(context, c),
                style: TextStyle(color: c == _city ? AppTheme.accent : AppTheme.textPrimary)),
            trailing: c == _city ? const Icon(Icons.check, color: AppTheme.accent) : null,
            onTap: () { setState(() => _city = c); Navigator.pop(ctx); },
          )).toList(),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    return Scaffold(
      backgroundColor: AppTheme.bg,
      body: SafeArea(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 14, 16, 10),
                    child: Row(
                      children: [
                        const AppBackButton(),
                        const SizedBox(width: 10),
                        Expanded(child: Text(l10n.editClubCard,
                            style: const TextStyle(color: AppTheme.textPrimary, fontSize: 18, fontWeight: FontWeight.w700))),
                        _saving
                            ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                            : TextButton(onPressed: _save, child: const Text('Сохранить')),
                      ],
                    ),
                  ),
                  Expanded(
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          _field(l10n.clubName, _name),
                          _field(l10n.clubAddress, _address),
                          _cityRow(l10n.clubCity),
                          _field(l10n.clubPhone, _phone, keyboard: TextInputType.phone),
                          _field(l10n.clubEmail, _email, keyboard: TextInputType.emailAddress),
                          _field(l10n.clubDescription, _description, maxLines: 4),
                          _field(l10n.clubPaymentUrl, _paymentUrl, keyboard: TextInputType.url),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
      ),
    );
  }

  Widget _field(String label, TextEditingController c, {TextInputType? keyboard, int maxLines = 1}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: const TextStyle(color: AppTheme.textSecondary, fontSize: 13)),
          const SizedBox(height: 6),
          TextField(
            controller: c,
            keyboardType: keyboard,
            maxLines: maxLines,
            style: const TextStyle(color: AppTheme.textPrimary),
            decoration: InputDecoration(
              filled: true,
              fillColor: AppTheme.card,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
              contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            ),
          ),
        ],
      ),
    );
  }

  Widget _cityRow(String label) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: const TextStyle(color: AppTheme.textSecondary, fontSize: 13)),
          const SizedBox(height: 6),
          InkWell(
            onTap: _pickCity,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
              decoration: BoxDecoration(color: AppTheme.card, borderRadius: BorderRadius.circular(12)),
              child: Row(
                children: [
                  Expanded(child: Text(
                    _city == null ? '—' : localizeCity(context, _city!),
                    style: const TextStyle(color: AppTheme.textPrimary),
                  )),
                  const Icon(Icons.arrow_drop_down, color: AppTheme.textSecondary),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
```

IMPORTANT before finalizing: open `lib/screens/edit_profile_screen.dart` and the project to confirm the EXACT names/paths used here — `AppTheme` color tokens (`bg`, `card`, `textPrimary`, `textSecondary`, `accent`), the `AppBackButton` import path, `showAppAlert` signature (`message`, `title:`, `isError:`), `localizeCity(context, city)` signature, the `AppLocalizations` import path, and that `AdminService` is provided via `context.read<AdminService>()` (it is used that way elsewhere). Adjust imports/names to the real ones. Use the "Сохранить"/"Ошибка" approach consistent with `edit_profile_screen.dart` (it uses hardcoded RU in places — acceptable to mirror, but prefer l10n keys where they already exist).

- [ ] **Step 2: Static-check**

Run: `cd C:\projects\padel_app && flutter analyze lib/screens/admin/admin_edit_club_screen.dart`
Expected: no errors (pre-existing info warnings elsewhere are fine).

- [ ] **Step 3: Commit**

```bash
cd C:\projects\padel_app
git add lib/screens/admin/admin_edit_club_screen.dart
git commit -m "feat(admin): club card edit screen"
```

---

## Task 5: Flutter — button in admin block (owner-only)

**Files:**
- Modify: `C:\projects\padel_app\lib\widgets\home\admin_club_block.dart`

The block already shows the "Создать турнир" CTA via `_AdminCta(icon, title, subtitle, gradientColors, onTap)`. It distinguishes owner vs moderator via `isModerator` (and gates owner-only items with `if (!isModerator ...)`). Add an "Edit club card" CTA below the create-tournament button, shown ONLY to owners (`!isModerator`).

- [ ] **Step 1: Add the button**

In `lib/widgets/home/admin_club_block.dart`, immediately AFTER the "Создать турнир" `_AdminCta` (and inside the same per-club column), add:

```dart
if (!isModerator) ...[
  const SizedBox(height: 10),
  _AdminCta(
    icon: Icons.edit_outlined,
    title: AppLocalizations.of(context)!.editClubCard,
    subtitle: AppLocalizations.of(context)!.editClubCardSubtitle,
    gradientColors: const [Color(0xFF3B82F6), Color(0xFF1D4ED8)],
    onTap: () {
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => AdminEditClubScreen(clubId: club.id),
        ),
      );
    },
  ),
],
```

Match the EXACT spacing widget the file already uses between CTAs (if it uses `const SizedBox(height: 10)` or a different value, mirror it). Confirm `_AdminCta`'s real parameter names (icon/title/subtitle/gradientColors/onTap) and that `club.id` is the right accessor (the file uses `club.id`/`club.name`). Add the import: `import '../../screens/admin/admin_edit_club_screen.dart';` and ensure `AppLocalizations` is imported (it likely already is in this file; if not, add `import '../../l10n/app_localizations.dart';`).

- [ ] **Step 2: Static-check**

Run: `cd C:\projects\padel_app && flutter analyze lib/widgets/home/admin_club_block.dart`
Expected: no errors.

- [ ] **Step 3: Manual verification**

Run the app (`flutter run`) as a club owner (club_admin):
- The "Редактировать карточку клуба" button appears below "Создать турнир".
- As a moderator, the button is NOT shown.
- Tapping opens the edit screen with current club data; changing fields + Save updates the card; reopening shows new values.
- City picker shows the 5 cities; invalid email/url → server returns 422 → error alert shown.

- [ ] **Step 4: Commit**

```bash
cd C:\projects\padel_app
git add lib/widgets/home/admin_club_block.dart
git commit -m "feat(admin): edit club card button (owner only)"
```

---

## Self-Review

**Spec coverage:** Backend controller show/update with owner-only auth + 403 for moderator + validation + super-admin fields untouched (Task 1, with explicit tests for each). Routes (Task 1). Flutter model+service (Task 2), l10n (Task 3), edit screen with city picker text-only (Task 4), owner-only button + navigation (Task 5). Logo excluded everywhere (no logo code). All spec sections covered.

**Placeholder scan:** No TBD/TODO. Full code in every code step. The "verify exact AppTheme/import names" notes point at concrete files to confirm against and say what to adjust — not deferred work.

**Type consistency:** Backend `payload()` keys (`name,address,city,phone,email,description,payment_url`) ↔ `AdminClubEdit.fromJson` (`payment_url`→`paymentUrl`) ↔ update `body` keys (snake_case) ↔ validation rules. Endpoint paths `/admin/clubs/{id}` (GET show / PUT update) match `AdminService.getClub`/`updateClub`. `AdminEditClubScreen(clubId:)` matches the button's `club.id`. l10n keys used in Task 4/5 (`editClubCard`, `editClubCardSubtitle`, `clubName`, `clubAddress`, `clubCity`, `clubPhone`, `clubEmail`, `clubDescription`, `clubPaymentUrl`, `clubCardSaved`) all defined in Task 3. Consistent.

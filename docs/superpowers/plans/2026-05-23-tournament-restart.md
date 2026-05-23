# Tournament Restart Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a club admin/moderator restart a running tournament from the mobile app — return it to `open`, wipe the generated bracket, keep participants — via a three-dots menu on the admin tournament screen.

**Architecture:** Backend adds `Tournament::firstRoundCompleted()`/`canRestart()`, a `TournamentResetService` that deletes the bracket per type (DB cascade handles children) and sets `status='open'`, a `restart` admin endpoint, and a `can_restart` flag in the admin payload. Flutter adds `AdminService.restartTournament`, a `canRestart` model field, and a `PopupMenuButton` (Start duplicate + Restart) with a confirm dialog.

**Tech Stack:** Laravel 12 / PHP 8.2 / MySQL (prod) + sqlite (tests) / PHPUnit; Flutter (padel_app) Dart, ARB l10n.

**Spec:** `docs/superpowers/specs/2026-05-23-tournament-restart-design.md`

**Note:** `can_start` already exists in the admin payload (`MobileAdminTournamentDetailController::formatDetail`) and in the Flutter model (`AdminTournamentDetail.canStart`). Only `can_restart` is new. The Start menu item reuses the existing `canStart`.

---

## Backend file map (C:\projects\padel)
- Modify: `app/Models/Tournament.php` — add `firstRoundCompleted()`, `canRestart()`.
- Create: `app/Services/TournamentResetService.php` — `reset(Tournament)`.
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php` — add `restart()` method; add `can_restart` to `formatDetail()`.
- Modify: `routes/api.php` — add restart route next to start (line ~99).
- Tests: `tests/Unit/TournamentRestartTest.php`, `tests/Feature/TournamentRestartEndpointTest.php`.

## Flutter file map (C:\projects\padel_app)
- Modify: `lib/services/admin_service.dart` — `restartTournament(int id)`.
- Modify: `lib/models/admin_tournament_detail.dart` — `canRestart` field.
- Modify: `lib/screens/admin/admin_tournament_detail_screen.dart` — PopupMenuButton + `_restart()`.
- Modify: `lib/l10n/app_ru.arb`, `app_en.arb`, `app_kk.arb` — new keys.

---

## Task 1: Tournament model — firstRoundCompleted() + canRestart()

**Files:**
- Modify: `app/Models/Tournament.php`
- Test: `tests/Unit/TournamentRestartTest.php`

Existing relations to reuse (verified): `groups()` (americano), `mexicanoRounds()`, `kingOfCourtRounds()`, `baliKocRounds()`, `americanoFlexRounds()`, `teamGroups()`, `playoffMatches()`. Round completion = `status === 'completed'` on the round; team uses a completed `tournament_group_matches` row (`group_id` → team group).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/TournamentRestartTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\AmericanoRound;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TournamentRestartTest extends TestCase
{
    use RefreshDatabase;

    private function americano(string $status): Tournament
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        return Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'americano',
            'status' => $status, 'max_participants' => 8,
        ]);
    }

    public function test_can_restart_true_when_in_progress_and_first_round_not_completed(): void
    {
        $t = $this->americano('in_progress');
        $g = TournamentGroup::create(['tournament_id' => $t->id, 'name' => 'A']);
        AmericanoRound::create(['tournament_group_id' => $g->id, 'round_number' => 1, 'status' => 'in_progress']);

        $this->assertFalse($t->firstRoundCompleted());
        $this->assertTrue($t->canRestart());
    }

    public function test_cannot_restart_after_first_round_completed(): void
    {
        $t = $this->americano('in_progress');
        $g = TournamentGroup::create(['tournament_id' => $t->id, 'name' => 'A']);
        AmericanoRound::create(['tournament_group_id' => $g->id, 'round_number' => 1, 'status' => 'completed']);

        $this->assertTrue($t->firstRoundCompleted());
        $this->assertFalse($t->canRestart());
    }

    public function test_cannot_restart_when_open_or_completed(): void
    {
        $this->assertFalse($this->americano('open')->canRestart());
        $this->assertFalse($this->americano('completed')->canRestart());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TournamentRestartTest`
Expected: FAIL — `Call to undefined method ...firstRoundCompleted()`.
(If `Tournament::create` needs more required columns — e.g. a non-null field — add them to the helper. Check the tournaments migration; `name`, `type`, `status`, `club_id` are required; add others the migration marks NOT NULL.)

- [ ] **Step 3: Implement the methods in `app/Models/Tournament.php`**

Add these methods to the class:

```php
public function firstRoundCompleted(): bool
{
    return match ($this->type) {
        'americano' => \App\Models\AmericanoRound::query()
            ->whereIn('tournament_group_id', $this->groups()->pluck('id'))
            ->where('round_number', 1)->where('status', 'completed')->exists(),
        'mexicano' => $this->mexicanoRounds()
            ->where('round_number', 1)->where('status', 'completed')->exists(),
        'king_of_court' => $this->kingOfCourtRounds()
            ->where('round_number', 1)->where('status', 'completed')->exists(),
        'bali_koc' => $this->baliKocRounds()
            ->where('round_number', 1)->where('status', 'completed')->exists(),
        'americano_flex' => $this->americanoFlexRounds()
            ->where('round_number', 1)->where('status', 'completed')->exists(),
        'team' => \App\Models\TournamentGroupMatch::query()
            ->whereIn('group_id', $this->teamGroups()->pluck('id'))
            ->where('status', 'completed')->exists(),
        default => $this->playoffMatches()->where('status', 'completed')->exists(),
    };
}

public function canRestart(): bool
{
    return $this->status === 'in_progress' && ! $this->firstRoundCompleted();
}
```

If any relation name differs from the above (verify against the model), use the actual name. If a rounds table has no `round_number` column, fall back to `->where('status','completed')->exists()` for that type (still correct: any completed round blocks restart).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TournamentRestartTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Tournament.php tests/Unit/TournamentRestartTest.php
git commit -m "feat(tournament): firstRoundCompleted + canRestart"
```

---

## Task 2: TournamentResetService

**Files:**
- Create: `app/Services/TournamentResetService.php`
- Test: append to `tests/Unit/TournamentRestartTest.php`

Deletes the bracket per type (children removed by DB cascade) and sets `status='open'`. Participants (`tournament_participants`) and teams (`tournament_teams`) are NOT touched.

- [ ] **Step 1: Write the failing test (append method)**

Add to `tests/Unit/TournamentRestartTest.php`:

```php
    public function test_reset_wipes_americano_bracket_and_reopens(): void
    {
        $t = $this->americano('in_progress');
        $g = \App\Models\TournamentGroup::create(['tournament_id' => $t->id, 'name' => 'A']);
        $r = \App\Models\AmericanoRound::create(['tournament_group_id' => $g->id, 'round_number' => 1, 'status' => 'in_progress']);

        app(\App\Services\TournamentResetService::class)->reset($t);

        $t->refresh();
        $this->assertEquals('open', $t->status);
        $this->assertSame(0, \App\Models\TournamentGroup::where('tournament_id', $t->id)->count());
        $this->assertSame(0, \App\Models\AmericanoRound::where('id', $r->id)->count());
    }
```

(If the americano cascade does not delete the round in sqlite, the assertion will catch it — then add explicit child deletion in the service for that type.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TournamentRestartTest::test_reset_wipes_americano_bracket_and_reopens`
Expected: FAIL — class `TournamentResetService` not found.

- [ ] **Step 3: Implement `app/Services/TournamentResetService.php`**

```php
<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\MexicanoPlayer;
use App\Models\MexicanoRound;
use App\Models\KingOfCourtPlayer;
use App\Models\AmericanoFlexPlayer;
use Illuminate\Support\Facades\DB;

class TournamentResetService
{
    /** Удаляет сгенерированную сетку и возвращает турнир в набор (open). Участники сохраняются. */
    public function reset(Tournament $tournament): void
    {
        DB::transaction(function () use ($tournament) {
            switch ($tournament->type) {
                case 'americano':
                    $tournament->groups()->delete();            // cascade: rounds, matches, group_players
                    $tournament->playoffMatches()->delete();
                    break;

                case 'mexicano':
                    MexicanoRound::where('tournament_id', $tournament->id)->delete(); // cascade: matches
                    MexicanoPlayer::where('tournament_id', $tournament->id)->delete();
                    DB::table('mexicano_pair_history')->where('tournament_id', $tournament->id)->delete();
                    break;

                case 'team':
                    $tournament->teamGroups()->delete();         // cascade: standings, group_matches
                    $tournament->playoffMatches()->delete();
                    break;

                case 'king_of_court':
                    $tournament->kingOfCourtRounds()->delete();  // cascade: matches
                    KingOfCourtPlayer::where('tournament_id', $tournament->id)->delete();
                    break;

                case 'bali_koc':
                    $tournament->baliKocRounds()->delete();      // cascade: matches
                    DB::table('bali_koc_pairs')->where('tournament_id', $tournament->id)->delete();
                    break;

                case 'americano_flex':
                    $tournament->americanoFlexRounds()->delete(); // cascade: matches, byes
                    DB::table('americano_flex_pair_history')->where('tournament_id', $tournament->id)->delete();
                    AmericanoFlexPlayer::where('tournament_id', $tournament->id)->delete();
                    break;

                default: // classic / legacy
                    $tournament->playoffMatches()->delete();
                    break;
            }

            $tournament->update(['status' => 'open']);
        });
    }
}
```

Verify model class names exist (`MexicanoPlayer`, `KingOfCourtPlayer`, `AmericanoFlexPlayer`). If a model is missing, use `DB::table('<table>')->where('tournament_id', $tournament->id)->delete()` instead (tables: `mexicano_players`, `kingofcourt_players`, `americano_flex_players`). If `groups()`/`teamGroups()`/`*Rounds()` relation names differ, use the real ones from the model.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TournamentRestartTest`
Expected: PASS (4 tests). If the cascade assertion fails on sqlite, add explicit child deletes for that type and re-run.

- [ ] **Step 5: Commit**

```bash
git add app/Services/TournamentResetService.php tests/Unit/TournamentRestartTest.php
git commit -m "feat(tournament): TournamentResetService wipes bracket by type"
```

---

## Task 3: Restart endpoint + can_restart payload flag

**Files:**
- Modify: `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/TournamentRestartEndpointTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TournamentRestartEndpointTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\AmericanoRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class TournamentRestartEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function setup_(string $roundStatus): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = Tournament::create(['club_id' => $club->id, 'name' => 'T', 'type' => 'americano', 'status' => 'in_progress', 'max_participants' => 8]);
        $g = TournamentGroup::create(['tournament_id' => $t->id, 'name' => 'A']);
        AmericanoRound::create(['tournament_group_id' => $g->id, 'round_number' => 1, 'status' => $roundStatus]);
        return [$admin, $t];
    }

    public function test_restart_reopens_when_allowed(): void
    {
        [$admin, $t] = $this->setup_('in_progress');
        Sanctum::actingAs($admin);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/restart")
            ->assertOk();
        $this->assertEquals('open', $t->refresh()->status);
    }

    public function test_restart_rejected_after_first_round(): void
    {
        [$admin, $t] = $this->setup_('completed');
        Sanctum::actingAs($admin);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/restart")
            ->assertStatus(422);
        $this->assertEquals('in_progress', $t->refresh()->status);
    }

    public function test_restart_forbidden_for_non_manager(): void
    {
        [, $t] = $this->setup_('in_progress');
        $other = User::factory()->create(['role' => 'player']);
        Sanctum::actingAs($other);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/restart")
            ->assertForbidden();
    }
}
```

(Verify the Sanctum guard/middleware matches how other mobile-admin tests authenticate. If the project uses a custom token guard instead of Sanctum, mirror that project's existing feature-test auth pattern. Check an existing test under `tests/Feature` that hits `/api/mobile/admin/...`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TournamentRestartEndpointTest`
Expected: FAIL — 404 (route missing).

- [ ] **Step 3: Add the controller method**

In `app/Http/Controllers/Api/MobileAdminTournamentDetailController.php`, add a `restart` method modeled on `start()` (reuse `canManageTournament` and `formatDetail`):

```php
public function restart(Request $request, Tournament $tournament)
{
    if (!$this->canManageTournament($request->user(), $tournament)) {
        return $this->forbidden();
    }

    if (!$tournament->canRestart()) {
        return response()->json([
            'success' => false,
            'message' => 'Перезапуск недоступен: турнир не запущен или первый раунд уже сыгран',
        ], 422);
    }

    app(\App\Services\TournamentResetService::class)->reset($tournament);

    return response()->json([
        'success' => true,
        'tournament' => $this->formatDetail($tournament->fresh()),
    ]);
}
```

Match the exact success shape that `start()` returns (it returns `['success'=>true,'tournament'=>$this->formatDetail(...)]` or similar — mirror it exactly so the Flutter `restartTournament` can read `response['tournament']`).

- [ ] **Step 4: Add `can_restart` to `formatDetail()`**

In `formatDetail()` (near the existing `'can_start' => ...` around line 283), add:

```php
'can_restart' => $hasFullAccess && $t->canRestart(),
```

Use whatever variable in `formatDetail` represents manage-permission (the report referenced `$hasFullAccess`; if the method computes permission differently, reuse that). If no permission var exists in `formatDetail`, use `$t->canRestart()` alone (the screen is admin-only).

- [ ] **Step 5: Add the route**

In `routes/api.php`, directly after the existing start route (~line 99):

```php
Route::post('/admin/tournaments/{tournament}/restart', [\App\Http\Controllers\Api\MobileAdminTournamentDetailController::class, 'restart']);
```

Place it inside the SAME route group (same prefix/middleware) as the start route. Match the `{tournament}` binding name used by `start`.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=TournamentRestartEndpointTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/MobileAdminTournamentDetailController.php routes/api.php tests/Feature/TournamentRestartEndpointTest.php
git commit -m "feat(api): admin tournament restart endpoint + can_restart flag"
```

---

## Task 4: Flutter — AdminService.restartTournament + model field

**Files:**
- Modify: `C:\projects\padel_app\lib\services\admin_service.dart`
- Modify: `C:\projects\padel_app\lib\models\admin_tournament_detail.dart`

- [ ] **Step 1: Add `canRestart` to the model**

In `lib/models/admin_tournament_detail.dart`: add a field next to `canStart`:
- Declaration (with the other `final bool` fields): `final bool canRestart;`
- Constructor param: `required this.canRestart,` (or `this.canRestart = false,` matching how `canStart` is declared).
- In `fromJson`, next to `canStart: json['can_start'] as bool? ?? false,` add:
  ```dart
  canRestart: json['can_restart'] as bool? ?? false,
  ```

- [ ] **Step 2: Add `restartTournament` to AdminService**

In `lib/services/admin_service.dart`, directly after `startTournament` (~line 136):

```dart
/// Перезапустить турнир (`in_progress` → `open`, сетка удаляется).
Future<AdminTournamentDetail> restartTournament(int id) async {
  final token = await _storage.getToken();
  final response = await _api.post(
    '/admin/tournaments/$id/restart',
    const {},
    token,
  );
  return AdminTournamentDetail.fromJson(
    response['tournament'] as Map<String, dynamic>,
  );
}
```

- [ ] **Step 3: Static-check**

Run: `cd C:\projects\padel_app && flutter analyze lib/services/admin_service.dart lib/models/admin_tournament_detail.dart`
Expected: No errors (warnings about unrelated files are fine).

- [ ] **Step 4: Commit**

```bash
cd C:\projects\padel_app
git add lib/services/admin_service.dart lib/models/admin_tournament_detail.dart
git commit -m "feat(admin): restartTournament service + canRestart model field"
```

---

## Task 5: Flutter — localization keys

**Files:**
- Modify: `C:\projects\padel_app\lib\l10n\app_ru.arb` (template), `app_en.arb`, `app_kk.arb`

- [ ] **Step 1: Add keys to each ARB**

`app_ru.arb` (template — add these keys; keep valid JSON, comma-separated):
```json
"restartTournament": "Перезапустить турнир",
"startTournamentMenu": "Запустить турнир",
"restartTournamentConfirmTitle": "Перезапустить турнир?",
"restartTournamentConfirmMessage": "Сетка и результаты будут удалены, участников можно будет изменить. Действие необратимо.",
"restartTournamentConfirmOk": "Перезапустить",
"restartTournamentSuccess": "Турнир перезапущен"
```

`app_en.arb`:
```json
"restartTournament": "Restart tournament",
"startTournamentMenu": "Start tournament",
"restartTournamentConfirmTitle": "Restart tournament?",
"restartTournamentConfirmMessage": "The bracket and results will be deleted; you'll be able to change participants. This action cannot be undone.",
"restartTournamentConfirmOk": "Restart",
"restartTournamentSuccess": "Tournament restarted"
```

`app_kk.arb`:
```json
"restartTournament": "Турнирді қайта бастау",
"startTournamentMenu": "Турнирді бастау",
"restartTournamentConfirmTitle": "Турнирді қайта бастау?",
"restartTournamentConfirmMessage": "Тор мен нәтижелер жойылады; қатысушыларды өзгерте аласыз. Бұл әрекетті қайтару мүмкін емес.",
"restartTournamentConfirmOk": "Қайта бастау",
"restartTournamentSuccess": "Турнир қайта басталды"
```

If `startTournamentMenu` duplicates an existing "start tournament" key, reuse the existing key instead of adding a new one (check the ARB first).

- [ ] **Step 2: Regenerate localizations**

Run: `cd C:\projects\padel_app && flutter gen-l10n`
Expected: `lib/l10n/app_localizations*.dart` regenerated, no errors. (If the project regenerates on `flutter pub get`, run that instead.)

- [ ] **Step 3: Commit**

```bash
cd C:\projects\padel_app
git add lib/l10n/app_ru.arb lib/l10n/app_en.arb lib/l10n/app_kk.arb
git commit -m "i18n: tournament restart strings (ru/en/kk)"
```

---

## Task 6: Flutter — three-dots menu + restart handler

**Files:**
- Modify: `C:\projects\padel_app\lib\screens\admin\admin_tournament_detail_screen.dart`

The header is a custom `Row` in `_buildHeader()` (~line 421-454), NOT a Material AppBar. The loaded detail is in field `_t`. There is a `_confirm({title, message, okText, destructive})` helper (~line 252) and a `_start()` method (~line 189) that calls `startTournament` then `setState`/reloads.

- [ ] **Step 1: Add the `_restart()` method**

Near `_start()` (~line 226), add (mirror `_start`'s loading/refresh pattern; reuse its exact reload calls — e.g. `_loadMatches()`, `_loadParticipants()` — match what `_start` does):

```dart
Future<void> _restart() async {
  final l10n = AppLocalizations.of(context)!;
  final ok = await _confirm(
    title: l10n.restartTournamentConfirmTitle,
    message: l10n.restartTournamentConfirmMessage,
    okText: l10n.restartTournamentConfirmOk,
    destructive: true,
  );
  if (!ok) return;

  try {
    final updated = await _admin.restartTournament(_t!.id);
    if (!mounted) return;
    setState(() => _t = updated);
    await _loadMatches();
    await _loadParticipants();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(l10n.restartTournamentSuccess)),
    );
  } catch (e) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(e.toString())),
    );
  }
}
```

Use the screen's actual AdminService field name (the report referenced calling `startTournament` — use the same instance, e.g. `_admin` or whatever it is) and the same reload methods `_start` uses. If `_start` reads `_t!.id` differently, match it.

- [ ] **Step 2: Add the PopupMenuButton to the header**

In `_buildHeader()` (~line 450, at the end of the header `Row`, on the right side), add:

```dart
PopupMenuButton<String>(
  icon: const Icon(Icons.more_vert, color: AppTheme.textPrimary),
  color: AppTheme.card,
  onSelected: (value) {
    if (value == 'start') _start();
    if (value == 'restart') _restart();
  },
  itemBuilder: (context) {
    final l10n = AppLocalizations.of(context)!;
    return [
      PopupMenuItem<String>(
        value: 'start',
        enabled: _t?.canStart ?? false,
        child: Text(l10n.startTournamentMenu),
      ),
      PopupMenuItem<String>(
        value: 'restart',
        enabled: _t?.canRestart ?? false,
        child: Text(l10n.restartTournament),
      ),
    ];
  },
),
```

Place it as the trailing widget of the header `Row` (after the title `Expanded`). If the `Row` lacks trailing space, wrap appropriately so it sits at the right edge, consistent with the existing layout. Use `AppTheme` colors already used in the file.

- [ ] **Step 3: Static-check**

Run: `cd C:\projects\padel_app && flutter analyze lib/screens/admin/admin_tournament_detail_screen.dart`
Expected: No errors.

- [ ] **Step 4: Manual verification**

Run the app (`flutter run`) as a club admin:
- Open a tournament that is `open` and fully filled → three-dots → «Запустить турнир» enabled, «Перезапустить» disabled.
- Start it → three-dots → «Перезапустить» now enabled (before any round completes).
- Tap «Перезапустить» → confirm dialog → confirm → status returns to `open`, participants become editable, success snackbar.
- Complete the first round → three-dots → «Перезапустить» disabled again.

- [ ] **Step 5: Commit**

```bash
cd C:\projects\padel_app
git add lib/screens/admin/admin_tournament_detail_screen.dart
git commit -m "feat(admin): three-dots menu with start/restart on tournament screen"
```

---

## Self-Review

**Spec coverage:** firstRoundCompleted/canRestart (Task 1), TournamentResetService delete-by-type + status→open + participants kept (Task 2), restart endpoint with 422/forbidden + can_restart payload (Task 3), Flutter service+model (Task 4), ARB ru/en/kk (Task 5), three-dots menu Start(dup)+Restart with confirm + refresh + disabled states (Task 6). `can_start` reused (already exists). Rating/court-bookings untouched (no code touches them). All spec sections map to a task.

**Placeholder scan:** No TBD/TODO. Each code step has full code. The "verify relation name / auth pattern / reload method" notes point at concrete existing code to mirror and give a fallback — not deferred work.

**Type consistency:** `firstRoundCompleted()`/`canRestart()` defined in Task 1, used in Task 3 controller. `TournamentResetService::reset(Tournament)` defined Task 2, called Task 3. Payload key `can_restart` (Task 3) ↔ model `canRestart` parsing `json['can_restart']` (Task 4) ↔ UI `_t?.canRestart` (Task 6). Endpoint path `/admin/tournaments/{id}/restart` (Task 3 route) ↔ Flutter `_api.post('/admin/tournaments/$id/restart')` (Task 4). Menu reuses existing `canStart`. Consistent.

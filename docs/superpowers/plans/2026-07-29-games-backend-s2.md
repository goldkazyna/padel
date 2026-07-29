# Games Module — Backend S2 (Роли и вступление) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Реализовать вступление в игру и управление составом: персональные приглашения, заявки (кандидаты) + одобрение, принятие/отклонение, выход и удаление; авто-переход статуса open↔full; уведомления.

**Architecture:** Расширяем существующий `Api\MobileGameController` (S0/S1 уже в main). Переиспользуем паттерн из `MobileChallengeController` (invite/join/accept/leave + `notifyPlayer`/`checkAndUpdateStatus`). Слот занят только при `accepted`. Позиция резервируется у `invited`/`accepted` (см. `Game::getAvailablePositions()`); кандидаты позицию не занимают (position=null) до одобрения.

**Tech Stack:** Laravel 12, Sanctum, PHPUnit sqlite :memory:, `FCMNotificationService`, `App\Models\Notification`.

## Global Constraints

- НЕ трогать `RatingCalculator`, `AmericanoRanking`, старый `challenge` домен.
- НЕ менять сигнатуры существующих `store/show/index/update/share*` — только добавлять. `formatGame` расширяем аддитивно (новые ключи), существующие ключи не убираем/не переименовываем.
- Формат ответа `{success, data}` (или `{success, message}` для чистых действий); доступ `403`, «не найдено» `404`, бизнес-нарушение `422`.
- Роуты — в `Route::prefix('mobile')`→`auth:sanctum` группе рядом с существующими `/games/*`.
- Слот занят только при `status=accepted`. Максимум `capacity` (=4) accepted.
- Организатор = `$game->isOrganizer($user->id)` (`creator_id`).
- Диапазон рейтинга (фильтр/out_of_range) — это слайс S3, здесь НЕ реализуем: `apply` пускает любого, решает организатор.
- Ветку создать от main (`feature/games-backend-s2`), НЕ работать на main.
- Уведомления игр: `category='game'`, `data=['game_id'=>id]`.

---

### Task 1: Хелперы состава + расширение formatGame + search-player

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (добавить `use`, приватные хелперы `notifyGame`, `syncFullness`, `nextFreePosition`; расширить `formatGame`; добавить `searchPlayer`)
- Modify: `routes/api.php` (роут `POST /games/search-player`)
- Test: `tests/Feature/Games/GameMembershipHelpersTest.php`

**Interfaces:**
- Consumes: `Game`, `GamePlayer`, `User`, `Notification`, `FCMNotificationService`.
- Produces:
  - `private function notifyGame(User $user, string $title, string $body, string $type, int $gameId): void` — пишет `Notification` (category='game') + FCM.
  - `private function syncFullness(Game $game): void` — если игра не начата (`status` в [open, full]): accepted>=capacity → `full`, иначе → `open`.
  - `private function nextFreePosition(Game $game): ?int` — первая свободная позиция из `getAvailablePositions()` или null.
  - `formatGame` дополнительно возвращает `is_participant` (bool), `my_status` (?string), `my_position` (?int) для текущего пользователя.
  - `searchPlayer(Request)` — POST, `{phone:min:3}` → `{success, data:[{id,first_name,last_name,full_name,phone,rating,level}]}` (пусто → 404).

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameMembershipHelpersTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameMembershipHelpersTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_player_finds_by_phone(): void
    {
        $u = User::factory()->create(['phone' => '77011234567']);
        Sanctum::actingAs(User::factory()->create());

        $res = $this->postJson('/api/mobile/games/search-player', ['phone' => '7011234'])->assertOk();
        $this->assertContains($u->id, collect($res->json('data'))->pluck('id')->all());
    }

    public function test_search_player_empty_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/mobile/games/search-player', ['phone' => 'zzzz'])->assertStatus(404);
    }

    public function test_format_game_exposes_my_membership(): void
    {
        $me = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $me->id]);
        GamePlayer::factory()->create([
            'game_id' => $game->id, 'user_id' => $me->id, 'position' => 2,
            'status' => GamePlayer::STATUS_ACCEPTED,
        ]);
        Sanctum::actingAs($me);

        $res = $this->getJson("/api/mobile/games/{$game->id}")->assertOk();
        $res->assertJsonPath('data.is_participant', true);
        $res->assertJsonPath('data.my_status', 'accepted');
        $res->assertJsonPath('data.my_position', 2);
    }
}
```

- [ ] **Step 2: Запустить — убедиться, что падает**

Run: `php artisan test tests/Feature/Games/GameMembershipHelpersTest.php`
Expected: FAIL — 404 на search-player (роут не зарегистрирован) и отсутствие ключей my_*.

- [ ] **Step 3: Реализовать**

В `MobileGameController` добавить в начало (к существующим `use`):
```php
use App\Models\Notification;
use App\Services\FCMNotificationService;
```
(остальные — `Game`, `GamePlayer`, `User` — уже импортированы в S1).

Добавить приватные хелперы (например, после `uniqueShareToken()`):
```php
    /** Уведомление участнику игры: запись в notifications + FCM. */
    private function notifyGame(User $user, string $title, string $body, string $type, int $gameId): void
    {
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'category' => 'game',
            'data' => ['game_id' => $gameId],
        ]);

        app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
            'type' => $type,
            'game_id' => (string) $gameId,
        ]);
    }

    /** Синхронизировать статус open/full по числу accepted (пока игра не начата). */
    private function syncFullness(Game $game): void
    {
        $game->refresh();
        if (!in_array($game->status, [Game::STATUS_OPEN, Game::STATUS_FULL], true)) {
            return;
        }
        $accepted = $game->acceptedCount();
        $target = $accepted >= (int) $game->capacity ? Game::STATUS_FULL : Game::STATUS_OPEN;
        if ($game->status !== $target) {
            $game->update(['status' => $target]);
        }
    }

    /** Первая свободная позиция (1..capacity), либо null. */
    private function nextFreePosition(Game $game): ?int
    {
        $free = $game->getAvailablePositions();
        return $free[0] ?? null;
    }
```

Расширить `formatGame`: перед `return [...]` вычислить моего игрока и добавить ключи. Найти в методе `$players = $game->players->map(...)` и после него добавить:
```php
        $mine = $user ? $game->players->firstWhere('user_id', $user->id) : null;
```
Затем в возвращаемый массив (в конец, перед закрывающей `];`) добавить три ключа:
```php
            'is_participant' => $mine !== null,
            'my_status' => $mine?->status,
            'my_position' => $mine?->position,
```

Добавить метод `searchPlayer`:
```php
    /** Поиск игрока по телефону (для приглашений). */
    public function searchPlayer(Request $request)
    {
        $request->validate(['phone' => 'required|string|min:3']);

        $users = User::where('phone', 'like', '%' . $request->phone . '%')->limit(10)->get();
        if ($users->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Пользователь не найден'], 404);
        }

        $data = $users->map(function ($u) {
            $name = $u->name ?? 'Без имени';
            $parts = explode(' ', $name, 2);
            return [
                'id' => $u->id,
                'first_name' => $parts[0] ?? '',
                'last_name' => $parts[1] ?? '',
                'full_name' => $name,
                'phone' => $u->phone,
                'rating' => $u->rating,
                'level' => (float) $u->level,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }
```

В `routes/api.php` (auth-группа, рядом с `/games/clubs`):
```php
        Route::post('/games/search-player', [MobileGameController::class, 'searchPlayer']);
```

- [ ] **Step 4: Запустить — тест зелёный**

Run: `php artisan test tests/Feature/Games/GameMembershipHelpersTest.php`
Expected: PASS (3 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameMembershipHelpersTest.php
git commit -m "feat(games): хелперы состава + my_status в formatGame + search-player (S2)"
```

---

### Task 2: Персональное приглашение (invite)

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (метод `invite`)
- Modify: `routes/api.php` (роут)
- Test: `tests/Feature/Games/GameInviteTest.php`

**Interfaces:**
- Consumes: хелперы Task 1, `Invitation`.
- Produces: `invite(Request, Game)` — только организатор (403). Body: `user_id` (required exists users), опц `position`. Игрок не должен быть уже в игре (иначе 422). Создаёт `GamePlayer(status=invited, source=invite, position=выбранная-или-nextFreePosition)`; создаёт `Invitation(user_id=приглашённый, inviter_id=организатор, invitable=Game, kind=game, status=pending, expires_at=game.starts_at)`; шлёт `notifyGame(приглашённый, ..., 'game_invite')`. Ответ `{success, data: formatGame}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameInviteTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_invites_creates_invited_player_and_invitation(): void
    {
        $organizer = User::factory()->create();
        $invitee = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/invite", ['user_id' => $invitee->id])
            ->assertOk()->assertJson(['success' => true]);

        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $invitee->id)->first();
        $this->assertSame(GamePlayer::STATUS_INVITED, $player->status);
        $this->assertNotNull($player->position);

        $inv = Invitation::where('user_id', $invitee->id)->first();
        $this->assertNotNull($inv);
        $this->assertSame('game', $inv->kind);
        $this->assertSame(Invitation::STATUS_PENDING, $inv->status);
        $this->assertSame($game->id, $inv->invitable_id);
    }

    public function test_non_organizer_cannot_invite(): void
    {
        $game = Game::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/mobile/games/{$game->id}/invite", ['user_id' => User::factory()->create()->id])
            ->assertStatus(403);
    }

    public function test_cannot_invite_existing_member(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/invite", ['user_id' => $organizer->id])
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Запустить — падает (404/405)**

Run: `php artisan test tests/Feature/Games/GameInviteTest.php`
Expected: FAIL — роут не зарегистрирован.

- [ ] **Step 3: Реализовать**

Добавить `use App\Models\Invitation;` к импортам. Метод:
```php
    /** Персональное приглашение игрока (только организатор). */
    public function invite(Request $request, Game $game)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор может приглашать'], 403);
        }
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'position' => 'nullable|integer|min:1',
        ]);

        if ($game->players()->where('user_id', $data['user_id'])->exists()) {
            return response()->json(['success' => false, 'message' => 'Игрок уже в игре'], 422);
        }

        $free = $game->getAvailablePositions();
        $position = (!empty($data['position']) && in_array($data['position'], $free, true))
            ? $data['position']
            : ($free[0] ?? null);

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $data['user_id'],
            'position' => $position,
            'status' => GamePlayer::STATUS_INVITED,
            'source' => GamePlayer::SOURCE_INVITE,
        ]);

        Invitation::create([
            'user_id' => $data['user_id'],
            'inviter_id' => $user->id,
            'invitable_type' => Game::class,
            'invitable_id' => $game->id,
            'kind' => Invitation::KIND_GAME,
            'status' => Invitation::STATUS_PENDING,
            'expires_at' => $game->starts_at,
        ]);

        $invitee = User::find($data['user_id']);
        $this->notifyGame($invitee, 'Приглашение в игру', "{$user->name} приглашает вас в игру", 'game_invite', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }
```

Роут (auth-группа):
```php
        Route::post('/games/{game}/invite', [MobileGameController::class, 'invite']);
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameInviteTest.php`
Expected: PASS (3 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameInviteTest.php
git commit -m "feat(games): персональное приглашение invite + invitation-запись (S2)"
```

---

### Task 3: Заявки — apply / approve / reject

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (методы `apply`, `approveApplication`, `rejectApplication`)
- Modify: `routes/api.php` (3 роута)
- Test: `tests/Feature/Games/GameApplicationTest.php`

**Interfaces:**
- Consumes: хелперы Task 1.
- Produces:
  - `apply(Request, Game)` — текущий пользователь подаёт заявку. Нельзя если уже в игре (422) или игра не в [open, full] (422 — на full заявки складируются, тоже разрешаем как candidate; но если `in_progress`+ — 422). Создаёт `GamePlayer(status=candidate, source=app_feed|app_link по body, position=null)`; уведомляет организатора. Ответ `{success, data: formatGame}`.
  - `approveApplication(Request, Game, GamePlayer $player)` — только организатор (403). `player` должен принадлежать игре и быть `candidate` (иначе 422). Если нет свободных позиций/уже full — 422. Ставит `accepted` + `position=nextFreePosition`; `syncFullness`; уведомляет кандидата. Ответ `{success, data: formatGame}`.
  - `rejectApplication(Request, Game, GamePlayer $player)` — только организатор. `candidate` → `declined`; уведомляет кандидата. Ответ `{success, data: formatGame}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameApplicationTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function openGame(User $organizer): Game
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        return $game;
    }

    public function test_user_applies_as_candidate(): void
    {
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $applicant = User::factory()->create();
        Sanctum::actingAs($applicant);

        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $applicant->id)->first();
        $this->assertSame(GamePlayer::STATUS_CANDIDATE, $player->status);
        $this->assertNull($player->position);
    }

    public function test_organizer_approves_candidate_to_accepted_with_position(): void
    {
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $applicant = User::factory()->create();
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $applicant->id, 'position' => null, 'status' => GamePlayer::STATUS_CANDIDATE]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/applications/{$player->id}/approve")->assertOk();
        $player->refresh();
        $this->assertSame(GamePlayer::STATUS_ACCEPTED, $player->status);
        $this->assertNotNull($player->position);
    }

    public function test_non_organizer_cannot_approve(): void
    {
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'position' => null, 'status' => GamePlayer::STATUS_CANDIDATE]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/applications/{$player->id}/approve")->assertStatus(403);
    }

    public function test_organizer_rejects_candidate(): void
    {
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'position' => null, 'status' => GamePlayer::STATUS_CANDIDATE]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/applications/{$player->id}/reject")->assertOk();
        $this->assertSame(GamePlayer::STATUS_DECLINED, $player->fresh()->status);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameApplicationTest.php`
Expected: FAIL — роуты не зарегистрированы.

- [ ] **Step 3: Реализовать**

Методы:
```php
    /** Подать заявку на игру (кандидат). */
    public function apply(Request $request, Game $game)
    {
        $user = $request->user();
        $data = $request->validate(['source' => 'nullable|in:app_feed,app_link']);

        if ($game->players()->where('user_id', $user->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Вы уже в этой игре'], 422);
        }
        if (!in_array($game->status, [Game::STATUS_OPEN, Game::STATUS_FULL], true)) {
            return response()->json(['success' => false, 'message' => 'Игра недоступна для заявок'], 422);
        }

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'position' => null,
            'status' => GamePlayer::STATUS_CANDIDATE,
            'source' => ($data['source'] ?? 'app_feed') === 'app_link' ? GamePlayer::SOURCE_APP_LINK : GamePlayer::SOURCE_APP_FEED,
        ]);

        $this->notifyGame($game->creator, 'Новая заявка', "{$user->name} хочет присоединиться к игре", 'game_application', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Одобрить заявку кандидата (организатор). */
    public function approveApplication(Request $request, Game $game, GamePlayer $player)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($player->game_id !== $game->id || $player->status !== GamePlayer::STATUS_CANDIDATE) {
            return response()->json(['success' => false, 'message' => 'Заявка не найдена'], 422);
        }
        $position = $this->nextFreePosition($game);
        if ($position === null) {
            return response()->json(['success' => false, 'message' => 'Мест больше нет'], 422);
        }

        $player->update(['status' => GamePlayer::STATUS_ACCEPTED, 'position' => $position, 'responded_at' => now()]);
        $this->syncFullness($game);
        $this->notifyGame($player->user, 'Заявка одобрена', 'Вас приняли в игру', 'game_application_approved', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Отклонить заявку кандидата (организатор). */
    public function rejectApplication(Request $request, Game $game, GamePlayer $player)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($player->game_id !== $game->id || $player->status !== GamePlayer::STATUS_CANDIDATE) {
            return response()->json(['success' => false, 'message' => 'Заявка не найдена'], 422);
        }
        $player->update(['status' => GamePlayer::STATUS_DECLINED, 'responded_at' => now()]);
        $this->notifyGame($player->user, 'Заявка отклонена', 'Организатор отклонил вашу заявку', 'game_application_rejected', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }
```

Роуты (auth-группа):
```php
        Route::post('/games/{game}/apply', [MobileGameController::class, 'apply']);
        Route::post('/games/{game}/applications/{player}/approve', [MobileGameController::class, 'approveApplication']);
        Route::post('/games/{game}/applications/{player}/reject', [MobileGameController::class, 'rejectApplication']);
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameApplicationTest.php`
Expected: PASS (4 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameApplicationTest.php
git commit -m "feat(games): заявки apply/approve/reject (S2)"
```

---

### Task 4: Принятие/отклонение приглашения (accept / decline)

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (методы `accept`, `decline`)
- Modify: `routes/api.php` (2 роута)
- Test: `tests/Feature/Games/GameAcceptDeclineTest.php`

**Interfaces:**
- Consumes: хелперы Task 1, `Invitation`.
- Produces:
  - `accept(Request, Game)` — у текущего пользователя есть `GamePlayer(status=invited)` в игре (иначе 404). Если нет свободного слота и позиция игрока была снята — сохраняем существующую позицию; статус → `accepted`; синхронизируем `Invitation`(pending→accepted) для этой пары user+game; `syncFullness`; уведомляем организатора. Ответ `{success, data: formatGame}`.
  - `decline(Request, Game)` — `invited` → `declined` (позиция освобождается: `position=null`); `Invitation`(pending→declined); уведомляем организатора. Ответ `{success, data: formatGame}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameAcceptDeclineTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameAcceptDeclineTest extends TestCase
{
    use RefreshDatabase;

    private function invited(User $organizer, User $invitee): Game
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $invitee->id, 'position' => 2, 'status' => GamePlayer::STATUS_INVITED]);
        Invitation::create([
            'user_id' => $invitee->id, 'inviter_id' => $organizer->id,
            'invitable_type' => Game::class, 'invitable_id' => $game->id,
            'kind' => 'game', 'status' => 'pending',
        ]);
        return $game;
    }

    public function test_accept_sets_accepted_and_syncs_invitation(): void
    {
        $organizer = User::factory()->create();
        $invitee = User::factory()->create();
        $game = $this->invited($organizer, $invitee);
        Sanctum::actingAs($invitee);

        $this->postJson("/api/mobile/games/{$game->id}/accept")->assertOk();
        $this->assertSame(GamePlayer::STATUS_ACCEPTED, GamePlayer::where('game_id', $game->id)->where('user_id', $invitee->id)->first()->status);
        $this->assertSame(Invitation::STATUS_ACCEPTED, Invitation::where('user_id', $invitee->id)->where('invitable_id', $game->id)->first()->status);
    }

    public function test_decline_sets_declined_and_frees_position(): void
    {
        $organizer = User::factory()->create();
        $invitee = User::factory()->create();
        $game = $this->invited($organizer, $invitee);
        Sanctum::actingAs($invitee);

        $this->postJson("/api/mobile/games/{$game->id}/decline")->assertOk();
        $p = GamePlayer::where('game_id', $game->id)->where('user_id', $invitee->id)->first();
        $this->assertSame(GamePlayer::STATUS_DECLINED, $p->status);
        $this->assertNull($p->position);
        $this->assertSame(Invitation::STATUS_DECLINED, Invitation::where('user_id', $invitee->id)->where('invitable_id', $game->id)->first()->status);
    }

    public function test_accept_without_invite_returns_404(): void
    {
        $game = Game::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/mobile/games/{$game->id}/accept")->assertStatus(404);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameAcceptDeclineTest.php`
Expected: FAIL — роуты не зарегистрированы.

- [ ] **Step 3: Реализовать**

```php
    /** Принять приглашение в игру. */
    public function accept(Request $request, Game $game)
    {
        $user = $request->user();
        $player = $game->players()->where('user_id', $user->id)->where('status', GamePlayer::STATUS_INVITED)->first();
        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Приглашение не найдено'], 404);
        }

        $player->update(['status' => GamePlayer::STATUS_ACCEPTED, 'responded_at' => now()]);

        Invitation::where('invitable_type', Game::class)
            ->where('invitable_id', $game->id)
            ->where('user_id', $user->id)
            ->where('status', Invitation::STATUS_PENDING)
            ->update(['status' => Invitation::STATUS_ACCEPTED]);

        $this->syncFullness($game);
        $this->notifyGame($game->creator, 'Приглашение принято', "{$user->name} принял приглашение", 'game_invite_accepted', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Отклонить приглашение в игру. */
    public function decline(Request $request, Game $game)
    {
        $user = $request->user();
        $player = $game->players()->where('user_id', $user->id)->where('status', GamePlayer::STATUS_INVITED)->first();
        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Приглашение не найдено'], 404);
        }

        $player->update(['status' => GamePlayer::STATUS_DECLINED, 'position' => null, 'responded_at' => now()]);

        Invitation::where('invitable_type', Game::class)
            ->where('invitable_id', $game->id)
            ->where('user_id', $user->id)
            ->where('status', Invitation::STATUS_PENDING)
            ->update(['status' => Invitation::STATUS_DECLINED]);

        $this->notifyGame($game->creator, 'Приглашение отклонено', "{$user->name} отклонил приглашение", 'game_invite_declined', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }
```

Роуты (auth-группа):
```php
        Route::post('/games/{game}/accept', [MobileGameController::class, 'accept']);
        Route::post('/games/{game}/decline', [MobileGameController::class, 'decline']);
```

- [ ] **Step 4: Тест зелёный**

Run: `php artisan test tests/Feature/Games/GameAcceptDeclineTest.php`
Expected: PASS (3 теста).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameAcceptDeclineTest.php
git commit -m "feat(games): принятие/отклонение приглашения + sync invitation (S2)"
```

---

### Task 5: Выход и удаление (leave / remove)

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (методы `leave`, `removePlayer`)
- Modify: `routes/api.php` (2 роута)
- Test: `tests/Feature/Games/GameLeaveRemoveTest.php`

**Interfaces:**
- Consumes: хелперы Task 1.
- Produces:
  - `leave(Request, Game)` — организатор не может (422 — используйте передачу прав/отмену, S9). Участник с `accepted`, игра ещё не `in_progress`/`finished` (иначе 422). Статус → `left`, `position=null`; `syncFullness` (full→open); уведомляем организатора. Ответ `{success, data: formatGame}`.
  - `removePlayer(Request, Game, GamePlayer $player)` — только организатор (403). Нельзя удалить себя (422). `player` принадлежит игре и `accepted` (иначе 422). Статус → `removed`, `position=null`; `syncFullness`; уведомляем удалённого. Ответ `{success, data: formatGame}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameLeaveRemoveTest.php`:
```php
<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameLeaveRemoveTest extends TestCase
{
    use RefreshDatabase;

    private function gameWith(User $organizer, User $member): Game
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $member->id, 'position' => 2, 'status' => GamePlayer::STATUS_ACCEPTED]);
        return $game;
    }

    public function test_member_leaves(): void
    {
        $organizer = User::factory()->create();
        $member = User::factory()->create();
        $game = $this->gameWith($organizer, $member);
        Sanctum::actingAs($member);

        $this->postJson("/api/mobile/games/{$game->id}/leave")->assertOk();
        $p = GamePlayer::where('game_id', $game->id)->where('user_id', $member->id)->first();
        $this->assertSame(GamePlayer::STATUS_LEFT, $p->status);
        $this->assertNull($p->position);
    }

    public function test_organizer_cannot_leave(): void
    {
        $organizer = User::factory()->create();
        $game = $this->gameWith($organizer, User::factory()->create());
        Sanctum::actingAs($organizer);
        $this->postJson("/api/mobile/games/{$game->id}/leave")->assertStatus(422);
    }

    public function test_organizer_removes_member(): void
    {
        $organizer = User::factory()->create();
        $member = User::factory()->create();
        $game = $this->gameWith($organizer, $member);
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $member->id)->first();
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/players/{$player->id}/remove")->assertOk();
        $this->assertSame(GamePlayer::STATUS_REMOVED, $player->fresh()->status);
    }

    public function test_non_organizer_cannot_remove(): void
    {
        $organizer = User::factory()->create();
        $member = User::factory()->create();
        $game = $this->gameWith($organizer, $member);
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $member->id)->first();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/players/{$player->id}/remove")->assertStatus(403);
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameLeaveRemoveTest.php`
Expected: FAIL — роуты не зарегистрированы.

- [ ] **Step 3: Реализовать**

```php
    /** Выйти из игры (участник, до старта). Организатор — нельзя. */
    public function leave(Request $request, Game $game)
    {
        $user = $request->user();
        if ($game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Организатор не может выйти — передайте организацию или отмените игру'], 422);
        }
        if (in_array($game->status, [Game::STATUS_IN_PROGRESS, Game::STATUS_FINISHED, Game::STATUS_DISPUTED], true)) {
            return response()->json(['success' => false, 'message' => 'Игра уже началась'], 422);
        }
        $player = $game->players()->where('user_id', $user->id)->where('status', GamePlayer::STATUS_ACCEPTED)->first();
        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Вы не в этой игре'], 404);
        }

        $player->update(['status' => GamePlayer::STATUS_LEFT, 'position' => null]);
        $this->syncFullness($game);
        $this->notifyGame($game->creator, 'Игрок вышел', "{$user->name} покинул игру", 'game_left', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }

    /** Удалить участника (организатор). */
    public function removePlayer(Request $request, Game $game, GamePlayer $player)
    {
        $user = $request->user();
        if (!$game->isOrganizer($user->id)) {
            return response()->json(['success' => false, 'message' => 'Только организатор'], 403);
        }
        if ($player->game_id !== $game->id || $player->status !== GamePlayer::STATUS_ACCEPTED) {
            return response()->json(['success' => false, 'message' => 'Участник не найден'], 422);
        }
        if ($player->user_id === $user->id) {
            return response()->json(['success' => false, 'message' => 'Нельзя удалить себя'], 422);
        }

        $removed = $player->user;
        $player->update(['status' => GamePlayer::STATUS_REMOVED, 'position' => null]);
        $this->syncFullness($game);
        $this->notifyGame($removed, 'Вас удалили из игры', 'Организатор удалил вас из состава', 'game_removed', $game->id);

        return response()->json([
            'success' => true,
            'data' => $this->formatGame($game->fresh(['creator', 'club', 'court', 'players.user']), $user),
        ]);
    }
```

Роуты (auth-группа):
```php
        Route::post('/games/{game}/leave', [MobileGameController::class, 'leave']);
        Route::post('/games/{game}/players/{player}/remove', [MobileGameController::class, 'removePlayer']);
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameLeaveRemoveTest.php` → PASS (4).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameLeaveRemoveTest.php
git commit -m "feat(games): выход leave + удаление removePlayer (S2)"
```

---

## Порядок выполнения
Task 1 → 2 → 3 → 4 → 5 (последовательно). Task 2–5 зависят от хелперов Task 1.

## Не входит (следующие слайсы)
S3 фильтр рейтинга/out_of_range; S4-6 движки счёта; S7 отмена; S8 утверждение/спор; S9 передача прав; S10 лента+фильтры; S11 инбокс (чтение invitations); S12 пуши/напоминания; S13 Flutter; S14 удаление challenge.

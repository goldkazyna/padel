# Games Module — Backend S11 (Инбокс приглашений: чтение invitations) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Дать пользователю читать свои приглашения в игры (инбокс) — список pending-приглашений с данными игры, чтобы принять/отклонить (accept/decline уже есть в S2).

**Architecture:** Расширяем `Api\MobileGameController` (S0–S9 в main). Читаем таблицу `invitations` (polymorphic invitable = Game), которая уже пишется/обновляется в `invite/accept/decline` (S2). Один эндпоинт `GET /games/invitations`.

**Tech Stack:** Laravel 12, Sanctum, PHPUnit sqlite :memory:.

## Design-решения (записаны намеренно)
- **Инбокс** = приглашения текущего пользователя (`invitations.user_id = me`) с `kind='game'`. По умолчанию только `status='pending'` и непросроченные (`expires_at` null или в будущем). Опциональный `?status=` для явного фильтра (pending/accepted/declined/…).
- Для каждого приглашения отдаём: `invitation_id, status, expires_at, inviter{id,name}`, и вложенный компактный объект игры `game` (через `formatGame`, чтобы фронт сразу показал детали и кнопки accept/decline, которые уже существуют).
- Приглашения на удалённые игры (invitable отсутствует) пропускаем.
- Сортировка по `created_at` DESC.

## Global Constraints
- НЕ трогать RatingCalculator/AmericanoRanking/AmericanoService/challenge. Никаких записей рейтинга.
- Только ЧТЕНИЕ. Приём/отклонение — существующие `POST /games/{game}/accept` и `/decline` (S2), их не дублируем.
- Ветка от main (`feature/games-backend-s11`), не работать на main, не пушить. Новых миграций нет.

---

### Task 1: GET /games/invitations — инбокс приглашений

**Files:**
- Modify: `app/Http/Controllers/Api/MobileGameController.php` (`invitations`)
- Modify: `routes/api.php` (роут GET `/games/invitations`)
- Test: `tests/Feature/Games/GameInvitationsInboxTest.php`

**Interfaces:**
- Produces:
  - `public function invitations(Request $request)` — приглашения текущего пользователя (`kind=game`), по умолчанию pending+непросроченные; опциональный `status`. Возвращает `{success, data:[{invitation_id, status, expires_at, inviter:{id,name}|null, game:{...formatGame}}]}`; приглашения на удалённые игры пропускаются.
  - Роут: `Route::get('/games/invitations', [MobileGameController::class, 'invitations']);` — ВАЖНО: объявить ДО `Route::get('/games/{game}', ...)`, иначе `invitations` уйдёт в `{game}`.

- [ ] **Step 1: Написать падающий тест**

`tests/Feature/Games/GameInvitationsInboxTest.php`:
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

class GameInvitationsInboxTest extends TestCase
{
    use RefreshDatabase;

    private function invite(User $invitee, User $inviter, string $status = 'pending', $expires = null): array
    {
        $game = Game::factory()->create(['creator_id' => $inviter->id, 'status' => 'open', 'format' => 'sets']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $invitee->id, 'position' => 2, 'status' => GamePlayer::STATUS_INVITED]);
        $inv = Invitation::create([
            'user_id' => $invitee->id,
            'inviter_id' => $inviter->id,
            'invitable_type' => Game::class,
            'invitable_id' => $game->id,
            'kind' => Invitation::KIND_GAME,
            'status' => $status,
            'expires_at' => $expires,
        ]);
        return [$game, $inv];
    }

    public function test_inbox_returns_pending_invitations(): void
    {
        $me = User::factory()->create();
        $inviter = User::factory()->create();
        Sanctum::actingAs($me);
        [$game, $inv] = $this->invite($me, $inviter, 'pending', now()->addDay());

        $res = $this->getJson('/api/mobile/games/invitations')->assertOk();
        $res->assertJsonPath('data.0.invitation_id', $inv->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.game.id', $game->id)
            ->assertJsonPath('data.0.inviter.id', $inviter->id);
    }

    public function test_inbox_excludes_declined_by_default(): void
    {
        $me = User::factory()->create();
        $inviter = User::factory()->create();
        Sanctum::actingAs($me);
        $this->invite($me, $inviter, 'declined');

        $this->getJson('/api/mobile/games/invitations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_inbox_excludes_other_users_invitations(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();
        $inviter = User::factory()->create();
        Sanctum::actingAs($me);
        $this->invite($someoneElse, $inviter, 'pending', now()->addDay());

        $this->getJson('/api/mobile/games/invitations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `php artisan test tests/Feature/Games/GameInvitationsInboxTest.php`
Expected: FAIL — метод/роут не существуют.

- [ ] **Step 3: Реализовать**

Добавить импорт `use App\Models\Invitation;` и метод в `MobileGameController` (например, после `index`/`myGames`):
```php
    /** Инбокс: приглашения текущего пользователя в игры. */
    public function invitations(Request $request)
    {
        $user = $request->user();
        $status = $request->query('status');

        $query = Invitation::where('user_id', $user->id)
            ->where('kind', Invitation::KIND_GAME)
            ->where('invitable_type', Game::class)
            ->with(['inviter:id,name', 'invitable.creator', 'invitable.club', 'invitable.court', 'invitable.players.user'])
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        } else {
            $query->where('status', Invitation::STATUS_PENDING)
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                });
        }

        $data = $query->get()
            ->filter(fn ($inv) => $inv->invitable !== null) // игра могла быть удалена
            ->map(fn ($inv) => [
                'invitation_id' => $inv->id,
                'status' => $inv->status,
                'expires_at' => $inv->expires_at?->toIso8601String(),
                'inviter' => $inv->inviter ? ['id' => $inv->inviter->id, 'name' => $inv->inviter->name] : null,
                'game' => $this->formatGame($inv->invitable, $user),
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $data]);
    }
```

В `routes/api.php`, в блоке игр, ДО `Route::get('/games/{game}', ...)`:
```php
        Route::get('/games/invitations', [MobileGameController::class, 'invitations']);
```

- [ ] **Step 4: Тест зелёный + вся сюита games**

Run: `php artisan test tests/Feature/Games/GameInvitationsInboxTest.php` → PASS (3).
Run: `php artisan test tests/Feature/Games` → всё зелёное.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MobileGameController.php routes/api.php tests/Feature/Games/GameInvitationsInboxTest.php
git commit -m "feat(games): инбокс приглашений в игры (S11)"
```

---

## Порядок выполнения
Одна задача.

## Не входит (следующие слайсы)
S12 пуши/напоминания; спор (dispute) — Фаза 2; S13 Flutter; S14 удаление challenge.

<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotate_changes_token_and_invalidates_old(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'share_token' => 'oldtoken']);
        Sanctum::actingAs($organizer);

        $res = $this->postJson("/api/mobile/games/{$game->id}/share/rotate")->assertOk();
        $new = $res->json('data.share_token');
        $this->assertNotSame('oldtoken', $new);
        $this->assertSame($new, $game->fresh()->share_token);

        // Старый токен больше не резолвится.
        $this->getJson('/api/mobile/games/by-share/oldtoken')->assertStatus(404);
    }

    public function test_revoke_disables_link(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'share_token' => 'tok']);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/share/revoke")->assertOk();
        $this->assertNotNull($game->fresh()->share_revoked_at);
        $this->getJson('/api/mobile/games/by-share/tok')->assertStatus(410);
    }

    public function test_non_organizer_cannot_rotate(): void
    {
        $game = Game::factory()->create(['share_token' => 'tok']);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/mobile/games/{$game->id}/share/rotate")->assertStatus(403);
    }

    public function test_resolve_returns_game_for_active_link(): void
    {
        $game = Game::factory()->create(['share_token' => 'livetok', 'share_revoked_at' => null]);
        // Публичный роут — без авторизации.
        $this->getJson('/api/mobile/games/by-share/livetok')
            ->assertOk()
            ->assertJsonPath('data.id', $game->id);
    }
}

<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameStartTest extends TestCase
{
    use RefreshDatabase;

    private function fullGame(User $organizer): Game
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'full']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        return $game;
    }

    public function test_organizer_starts_full_game(): void
    {
        $organizer = User::factory()->create();
        $game = $this->fullGame($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->assertSame('in_progress', $game->fresh()->status);
    }

    public function test_cannot_start_non_full_game(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertStatus(422);
    }

    public function test_non_organizer_cannot_start(): void
    {
        $organizer = User::factory()->create();
        $game = $this->fullGame($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertStatus(403);
    }

    public function test_cancel_start_returns_to_full(): void
    {
        $organizer = User::factory()->create();
        $game = $this->fullGame($organizer);
        $game->update(['status' => 'in_progress']);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start/cancel")->assertOk();
        $this->assertSame('full', $game->fresh()->status);
    }
}

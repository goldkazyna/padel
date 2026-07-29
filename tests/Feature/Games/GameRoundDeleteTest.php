<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameRoundDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_deletes_round(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        $round = GameRound::create(['game_id' => $game->id, 'round_no' => 1, 'pair_a' => [1, 2], 'pair_b' => [3, 4], 'is_played' => false]);
        Sanctum::actingAs($organizer);

        $this->deleteJson("/api/mobile/games/{$game->id}/rounds/{$round->id}")->assertOk();
        $this->assertNull(GameRound::find($round->id));
    }

    public function test_non_organizer_cannot_delete_round(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress']);
        $round = GameRound::create(['game_id' => $game->id, 'round_no' => 1, 'pair_a' => [1, 2], 'pair_b' => [3, 4], 'is_played' => false]);
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/mobile/games/{$game->id}/rounds/{$round->id}")->assertStatus(403);
        $this->assertNotNull(GameRound::find($round->id));
    }
}

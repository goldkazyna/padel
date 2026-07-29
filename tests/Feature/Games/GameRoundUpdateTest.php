<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameRoundUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function gameWithRound(User $organizer): array
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress']);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        $round = GameRound::create([
            'game_id' => $game->id, 'round_no' => 1,
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
            'score_a' => null, 'score_b' => null, 'is_played' => false,
        ]);
        return [$game, $round];
    }

    public function test_organizer_updates_score_sets_is_played(): void
    {
        $organizer = User::factory()->create();
        [$game, $round] = $this->gameWithRound($organizer);
        Sanctum::actingAs($organizer);

        $this->putJson("/api/mobile/games/{$game->id}/rounds/{$round->id}", ['score_a' => 6, 'score_b' => 3])->assertOk();
        $round->refresh();
        $this->assertSame(6, $round->score_a);
        $this->assertTrue((bool) $round->is_played);
    }

    public function test_non_organizer_cannot_update_round(): void
    {
        $organizer = User::factory()->create();
        [$game, $round] = $this->gameWithRound($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/mobile/games/{$game->id}/rounds/{$round->id}", ['score_a' => 6, 'score_b' => 3])->assertStatus(403);
    }
}

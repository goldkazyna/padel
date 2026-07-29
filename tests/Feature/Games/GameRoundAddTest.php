<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameRoundAddTest extends TestCase
{
    use RefreshDatabase;

    /** in_progress игра с 4 accepted; возвращает [game, [u1,u2,u3,u4]]. */
    private function startedGame(User $organizer): array
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress']);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        return [$game, $ids];
    }

    public function test_organizer_adds_round_with_score(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->startedGame($organizer);
        Sanctum::actingAs($organizer);

        $res = $this->postJson("/api/mobile/games/{$game->id}/rounds", [
            'pair_a' => [$ids[0], $ids[1]],
            'pair_b' => [$ids[2], $ids[3]],
            'score_a' => 6, 'score_b' => 4,
        ])->assertOk();

        $res->assertJsonPath('data.rounds.0.round_no', 1);
        $round = GameRound::where('game_id', $game->id)->first();
        $this->assertSame(6, $round->score_a);
        $this->assertTrue((bool) $round->is_played);
        $this->assertSame([$ids[0], $ids[1]], $round->pair_a);
    }

    public function test_round_pairs_must_be_accepted_players(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->startedGame($organizer);
        $outsider = User::factory()->create();
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/rounds", [
            'pair_a' => [$ids[0], $outsider->id],
            'pair_b' => [$ids[2], $ids[3]],
        ])->assertStatus(422);
    }

    public function test_non_organizer_cannot_add_round(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->startedGame($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/rounds", [
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
        ])->assertStatus(403);
    }

    public function test_cannot_add_round_when_not_in_progress(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->startedGame($organizer);
        $game->update(['status' => 'full']);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/rounds", [
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
        ])->assertStatus(422);
    }
}

<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameFinishTest extends TestCase
{
    use RefreshDatabase;

    /** in_progress игра с 4 accepted и одним сыгранным раундом. Возвращает [game, [u1..u4]]. */
    private function playedGame(User $organizer, string $type = 'rated'): array
    {
        $game = Game::factory()->create([
            'creator_id' => $organizer->id, 'status' => 'in_progress',
            'type' => $type, 'format' => 'sets',
        ]);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        GameRound::create([
            'game_id' => $game->id, 'round_no' => 1,
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
            'score_a' => 6, 'score_b' => 3, 'is_played' => true,
        ]);
        return [$game, $ids];
    }

    public function test_organizer_finishes_locks_and_autoconfirms(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->playedGame($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/finish")
            ->assertOk()
            ->assertJsonPath('data.score_locked', true)
            ->assertJsonPath('data.my_score_confirmed', true);

        $this->assertTrue((bool) $game->fresh()->score_locked);
        $this->assertSame('in_progress', $game->fresh()->status); // фаза подтверждения
        $org = GamePlayer::where('game_id', $game->id)->where('user_id', $organizer->id)->first();
        $this->assertTrue((bool) $org->score_confirmed);
    }

    public function test_finish_requires_played_round(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress', 'format' => 'sets']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertStatus(422);
    }

    public function test_non_organizer_cannot_finish(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->playedGame($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertStatus(403);
    }

    public function test_cannot_finish_twice(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->playedGame($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertStatus(422); // уже score_locked
    }
}

<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameConfirmScoreTest extends TestCase
{
    use RefreshDatabase;

    /** Игра в фазе подтверждения (score_locked, организатор уже подтвердил). [game, [u1..u4]]. */
    private function pendingGame(User $organizer, string $type = 'friendly'): array
    {
        $game = Game::factory()->create([
            'creator_id' => $organizer->id, 'status' => 'in_progress',
            'type' => $type, 'format' => 'sets', 'score_locked' => true,
        ]);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED, 'score_confirmed' => true]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED, 'score_confirmed' => false]);
        }
        GameRound::create([
            'game_id' => $game->id, 'round_no' => 1,
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
            'score_a' => 6, 'score_b' => 3, 'is_played' => true,
        ]);
        return [$game, $ids];
    }

    public function test_partial_confirmation_keeps_in_progress(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingGame($organizer);
        Sanctum::actingAs(User::find($ids[1]));

        $this->postJson("/api/mobile/games/{$game->id}/confirm-score")->assertOk();
        $this->assertSame('in_progress', $game->fresh()->status);
        $p = GamePlayer::where('game_id', $game->id)->where('user_id', $ids[1])->first();
        $this->assertTrue((bool) $p->score_confirmed);
    }

    public function test_all_confirmed_finishes_game(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingGame($organizer);

        // Подтверждают u2, u3, u4 (организатор уже подтвердён).
        foreach ([$ids[1], $ids[2], $ids[3]] as $uid) {
            Sanctum::actingAs(User::find($uid));
            $this->postJson("/api/mobile/games/{$game->id}/confirm-score")->assertOk();
        }

        $this->assertSame('finished', $game->fresh()->status);
    }

    public function test_non_participant_cannot_confirm(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->pendingGame($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/confirm-score")->assertStatus(403);
    }

    public function test_cannot_confirm_when_not_pending(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingGame($organizer);
        $game->update(['score_locked' => false]); // не фаза подтверждения
        Sanctum::actingAs(User::find($ids[1]));

        $this->postJson("/api/mobile/games/{$game->id}/confirm-score")->assertStatus(422);
    }
}

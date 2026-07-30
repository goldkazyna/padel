<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GameActionLog;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class GameActionLogTest extends TestCase
{
    use RefreshDatabase;

    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    private function fullGame(User $organizer): array
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'full', 'format' => 'sets']);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        return [$game, $ids];
    }

    public function test_start_logs_action(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->fullGame($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();

        $log = GameActionLog::where('game_id', $game->id)->where('action', GameActionLog::ACTION_START)->first();
        $this->assertNotNull($log);
        $this->assertSame($organizer->id, $log->user_id);
    }

    public function test_round_add_logs_action_with_round_no(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->fullGame($organizer);
        $game->update(['status' => 'in_progress']);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/rounds", [
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
            'score_a' => 6, 'score_b' => 3,
        ])->assertOk();

        $log = GameActionLog::where('game_id', $game->id)->where('action', GameActionLog::ACTION_ROUND_ADD)->first();
        $this->assertNotNull($log);
        $this->assertSame(1, $log->payload['round_no'] ?? null);
    }

    public function test_player_remove_logs_action(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        [$game, $ids] = $this->fullGame($organizer);
        Sanctum::actingAs($organizer);
        $target = GamePlayer::where('game_id', $game->id)->where('user_id', $ids[1])->first();

        $this->postJson("/api/mobile/games/{$game->id}/players/{$target->id}/remove")->assertOk();

        $log = GameActionLog::where('game_id', $game->id)->where('action', GameActionLog::ACTION_PLAYER_REMOVE)->first();
        $this->assertNotNull($log);
        $this->assertSame($ids[1], $log->payload['removed_user_id'] ?? null);
    }
}

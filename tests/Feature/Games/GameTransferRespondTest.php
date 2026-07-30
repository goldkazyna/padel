<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameTransfer;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class GameTransferRespondTest extends TestCase
{
    use RefreshDatabase;

    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    /** Игра с pending-передачей organizer→other. [game, organizerId, otherId]. */
    private function pendingTransfer(): array
    {
        $organizer = User::factory()->create();
        $other = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'full', 'format' => 'sets']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $other->id, 'position' => 2, 'status' => GamePlayer::STATUS_ACCEPTED]);
        GameTransfer::create(['game_id' => $game->id, 'from_user_id' => $organizer->id, 'to_user_id' => $other->id, 'status' => GameTransfer::STATUS_PENDING]);
        return [$game, $organizer->id, $other->id];
    }

    public function test_target_accepts_and_becomes_organizer(): void
    {
        $this->fakePush();
        [$game, $orgId, $otherId] = $this->pendingTransfer();
        Sanctum::actingAs(User::find($otherId));

        $this->postJson("/api/mobile/games/{$game->id}/transfer/accept")->assertOk();

        $this->assertSame($otherId, $game->fresh()->creator_id);
        $this->assertSame(GameTransfer::STATUS_ACCEPTED, GameTransfer::where('game_id', $game->id)->first()->status);
    }

    public function test_non_target_cannot_accept(): void
    {
        [$game, $orgId] = $this->pendingTransfer();
        Sanctum::actingAs(User::find($orgId)); // инициатор, не цель

        $this->postJson("/api/mobile/games/{$game->id}/transfer/accept")->assertStatus(403);
        $this->assertSame($orgId, $game->fresh()->creator_id);
    }

    public function test_target_declines(): void
    {
        $this->fakePush();
        [$game, $orgId, $otherId] = $this->pendingTransfer();
        Sanctum::actingAs(User::find($otherId));

        $this->postJson("/api/mobile/games/{$game->id}/transfer/decline")->assertOk();

        $this->assertSame($orgId, $game->fresh()->creator_id);
        $this->assertSame(GameTransfer::STATUS_DECLINED, GameTransfer::where('game_id', $game->id)->first()->status);
    }
}

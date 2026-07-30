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

class GameTransferInitiateTest extends TestCase
{
    use RefreshDatabase;

    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    /** [game, [organizer_id, other_id]] с двумя accepted. */
    private function game(User $organizer): array
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'full', 'format' => 'sets']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        $other = User::factory()->create();
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $other->id, 'position' => 2, 'status' => GamePlayer::STATUS_ACCEPTED]);
        return [$game, [$organizer->id, $other->id]];
    }

    public function test_organizer_initiates_transfer(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        [$game, $ids] = $this->game($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/transfer", ['to_user_id' => $ids[1]])->assertOk();

        $t = GameTransfer::where('game_id', $game->id)->first();
        $this->assertNotNull($t);
        $this->assertSame(GameTransfer::STATUS_PENDING, $t->status);
        $this->assertSame($ids[0], $t->from_user_id);
        $this->assertSame($ids[1], $t->to_user_id);
    }

    public function test_cannot_transfer_to_non_participant(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->game($organizer);
        $outsider = User::factory()->create();
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/transfer", ['to_user_id' => $outsider->id])->assertStatus(422);
    }

    public function test_non_organizer_cannot_initiate(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->game($organizer);
        Sanctum::actingAs(User::find($ids[1]));

        $this->postJson("/api/mobile/games/{$game->id}/transfer", ['to_user_id' => $ids[0]])->assertStatus(403);
    }

    public function test_organizer_cancels_pending_transfer(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        [$game, $ids] = $this->game($organizer);
        Sanctum::actingAs($organizer);
        $this->postJson("/api/mobile/games/{$game->id}/transfer", ['to_user_id' => $ids[1]])->assertOk();

        $this->postJson("/api/mobile/games/{$game->id}/transfer/cancel")->assertOk();
        $this->assertSame(GameTransfer::STATUS_CANCELLED, GameTransfer::where('game_id', $game->id)->first()->status);
    }
}

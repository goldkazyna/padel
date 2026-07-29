<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Invitation;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class GameInviteTest extends TestCase
{
    use RefreshDatabase;

    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    public function test_organizer_invites_creates_invited_player_and_invitation(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $invitee = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/invite", ['user_id' => $invitee->id])
            ->assertOk()->assertJson(['success' => true]);

        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $invitee->id)->first();
        $this->assertSame(GamePlayer::STATUS_INVITED, $player->status);
        $this->assertNotNull($player->position);

        $inv = Invitation::where('user_id', $invitee->id)->first();
        $this->assertNotNull($inv);
        $this->assertSame('game', $inv->kind);
        $this->assertSame(Invitation::STATUS_PENDING, $inv->status);
        $this->assertSame($game->id, $inv->invitable_id);
    }

    public function test_non_organizer_cannot_invite(): void
    {
        $game = Game::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/mobile/games/{$game->id}/invite", ['user_id' => User::factory()->create()->id])
            ->assertStatus(403);
    }

    public function test_cannot_invite_existing_member(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/invite", ['user_id' => $organizer->id])
            ->assertStatus(422);
    }

    public function test_can_reinvite_after_decline(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $invitee = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $invitee->id, 'position' => null, 'status' => GamePlayer::STATUS_DECLINED]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/invite", ['user_id' => $invitee->id])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, GamePlayer::where('game_id', $game->id)->where('user_id', $invitee->id)->count());
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $invitee->id)->first();
        $this->assertSame(GamePlayer::STATUS_INVITED, $player->status);

        $inv = Invitation::where('invitable_type', Game::class)
            ->where('invitable_id', $game->id)
            ->where('user_id', $invitee->id)
            ->first();
        $this->assertNotNull($inv);
        $this->assertSame(Invitation::STATUS_PENDING, $inv->status);
    }
}

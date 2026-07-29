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

class GameAcceptDeclineTest extends TestCase
{
    use RefreshDatabase;

    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    private function invited(User $organizer, User $invitee): Game
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $invitee->id, 'position' => 2, 'status' => GamePlayer::STATUS_INVITED]);
        Invitation::create([
            'user_id' => $invitee->id, 'inviter_id' => $organizer->id,
            'invitable_type' => Game::class, 'invitable_id' => $game->id,
            'kind' => 'game', 'status' => 'pending',
        ]);
        return $game;
    }

    public function test_accept_sets_accepted_and_syncs_invitation(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $invitee = User::factory()->create();
        $game = $this->invited($organizer, $invitee);
        Sanctum::actingAs($invitee);

        $this->postJson("/api/mobile/games/{$game->id}/accept")->assertOk();
        $this->assertSame(GamePlayer::STATUS_ACCEPTED, GamePlayer::where('game_id', $game->id)->where('user_id', $invitee->id)->first()->status);
        $this->assertSame(Invitation::STATUS_ACCEPTED, Invitation::where('user_id', $invitee->id)->where('invitable_id', $game->id)->first()->status);
    }

    public function test_decline_sets_declined_and_frees_position(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $invitee = User::factory()->create();
        $game = $this->invited($organizer, $invitee);
        Sanctum::actingAs($invitee);

        $this->postJson("/api/mobile/games/{$game->id}/decline")->assertOk();
        $p = GamePlayer::where('game_id', $game->id)->where('user_id', $invitee->id)->first();
        $this->assertSame(GamePlayer::STATUS_DECLINED, $p->status);
        $this->assertNull($p->position);
        $this->assertSame(Invitation::STATUS_DECLINED, Invitation::where('user_id', $invitee->id)->where('invitable_id', $game->id)->first()->status);
    }

    public function test_accept_without_invite_returns_404(): void
    {
        $game = Game::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/mobile/games/{$game->id}/accept")->assertStatus(404);
    }
}

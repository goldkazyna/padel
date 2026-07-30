<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameInvitationsInboxTest extends TestCase
{
    use RefreshDatabase;

    private function invite(User $invitee, User $inviter, string $status = 'pending', $expires = null): array
    {
        $game = Game::factory()->create(['creator_id' => $inviter->id, 'status' => 'open', 'format' => 'sets']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $invitee->id, 'position' => 2, 'status' => GamePlayer::STATUS_INVITED]);
        $inv = Invitation::create([
            'user_id' => $invitee->id,
            'inviter_id' => $inviter->id,
            'invitable_type' => Game::class,
            'invitable_id' => $game->id,
            'kind' => Invitation::KIND_GAME,
            'status' => $status,
            'expires_at' => $expires,
        ]);
        return [$game, $inv];
    }

    public function test_inbox_returns_pending_invitations(): void
    {
        $me = User::factory()->create();
        $inviter = User::factory()->create();
        Sanctum::actingAs($me);
        [$game, $inv] = $this->invite($me, $inviter, 'pending', now()->addDay());

        $res = $this->getJson('/api/mobile/games/invitations')->assertOk();
        $res->assertJsonPath('data.0.invitation_id', $inv->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.game.id', $game->id)
            ->assertJsonPath('data.0.inviter.id', $inviter->id);
    }

    public function test_inbox_excludes_declined_by_default(): void
    {
        $me = User::factory()->create();
        $inviter = User::factory()->create();
        Sanctum::actingAs($me);
        $this->invite($me, $inviter, 'declined');

        $this->getJson('/api/mobile/games/invitations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_inbox_excludes_other_users_invitations(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();
        $inviter = User::factory()->create();
        Sanctum::actingAs($me);
        $this->invite($someoneElse, $inviter, 'pending', now()->addDay());

        $this->getJson('/api/mobile/games/invitations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}

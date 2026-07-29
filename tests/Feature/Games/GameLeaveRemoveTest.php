<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class GameLeaveRemoveTest extends TestCase
{
    use RefreshDatabase;

    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    private function gameWith(User $organizer, User $member): Game
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $member->id, 'position' => 2, 'status' => GamePlayer::STATUS_ACCEPTED]);
        return $game;
    }

    public function test_member_leaves(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $member = User::factory()->create();
        $game = $this->gameWith($organizer, $member);
        Sanctum::actingAs($member);

        $this->postJson("/api/mobile/games/{$game->id}/leave")->assertOk();
        $p = GamePlayer::where('game_id', $game->id)->where('user_id', $member->id)->first();
        $this->assertSame(GamePlayer::STATUS_LEFT, $p->status);
        $this->assertNull($p->position);
    }

    public function test_organizer_cannot_leave(): void
    {
        $organizer = User::factory()->create();
        $game = $this->gameWith($organizer, User::factory()->create());
        Sanctum::actingAs($organizer);
        $this->postJson("/api/mobile/games/{$game->id}/leave")->assertStatus(422);
    }

    public function test_organizer_removes_member(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $member = User::factory()->create();
        $game = $this->gameWith($organizer, $member);
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $member->id)->first();
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/players/{$player->id}/remove")->assertOk();
        $this->assertSame(GamePlayer::STATUS_REMOVED, $player->fresh()->status);
    }

    public function test_non_organizer_cannot_remove(): void
    {
        $organizer = User::factory()->create();
        $member = User::factory()->create();
        $game = $this->gameWith($organizer, $member);
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $member->id)->first();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/players/{$player->id}/remove")->assertStatus(403);
    }
}

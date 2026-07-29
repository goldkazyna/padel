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

class GameApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    private function openGame(User $organizer): Game
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        return $game;
    }

    public function test_user_applies_as_candidate(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $applicant = User::factory()->create();
        Sanctum::actingAs($applicant);

        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $applicant->id)->first();
        $this->assertSame(GamePlayer::STATUS_CANDIDATE, $player->status);
        $this->assertNull($player->position);
    }

    public function test_organizer_approves_candidate_to_accepted_with_position(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $applicant = User::factory()->create();
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $applicant->id, 'position' => null, 'status' => GamePlayer::STATUS_CANDIDATE]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/applications/{$player->id}/approve")->assertOk();
        $player->refresh();
        $this->assertSame(GamePlayer::STATUS_ACCEPTED, $player->status);
        $this->assertNotNull($player->position);
    }

    public function test_non_organizer_cannot_approve(): void
    {
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'position' => null, 'status' => GamePlayer::STATUS_CANDIDATE]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/applications/{$player->id}/approve")->assertStatus(403);
    }

    public function test_organizer_rejects_candidate(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'position' => null, 'status' => GamePlayer::STATUS_CANDIDATE]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/applications/{$player->id}/reject")->assertOk();
        $this->assertSame(GamePlayer::STATUS_DECLINED, $player->fresh()->status);
    }
}

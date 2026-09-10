<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Ушедший из игры не остаётся её участником.
 *
 * Строка игрока со статусом left/removed никуда не девается, и по ней
 * приложение считало человека участником: свободные места он видел, а занять
 * не мог — ни плюса у слота, ни кнопки внизу.
 */
class GameRejoinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    private function game(User $creator): Game
    {
        $game = Game::factory()->create([
            'creator_id' => $creator->id,
            'club_id' => Club::factory()->create()->id,
            'capacity' => 4,
            'status' => 'open',
        ]);

        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $creator->id,
            'position' => 1,
            'status' => GamePlayer::STATUS_ACCEPTED,
        ]);

        return $game;
    }

    public function test_left_player_is_not_a_participant(): void
    {
        $creator = User::factory()->create();
        $guest = User::factory()->create();
        $game = $this->game($creator);

        Sanctum::actingAs($guest);
        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/leave")->assertOk();

        $payload = $this->getJson("/api/mobile/games/{$game->id}")->assertOk();

        $payload->assertJsonPath('data.is_participant', false);
        $payload->assertJsonPath('data.my_status', null);
    }

    public function test_left_player_can_join_again(): void
    {
        $creator = User::factory()->create();
        $guest = User::factory()->create();
        $game = $this->game($creator);

        Sanctum::actingAs($guest);
        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/leave")->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();

        $this->getJson("/api/mobile/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.is_participant', true)
            ->assertJsonPath('data.my_status', GamePlayer::STATUS_ACCEPTED);
    }

    public function test_removed_player_is_not_a_participant(): void
    {
        $creator = User::factory()->create();
        $guest = User::factory()->create();
        $game = $this->game($creator);

        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $guest->id,
            'position' => 2,
            'status' => GamePlayer::STATUS_ACCEPTED,
        ]);

        Sanctum::actingAs($creator);
        $this->postJson("/api/mobile/games/{$game->id}/players/{$player->id}/remove")->assertOk();

        Sanctum::actingAs($guest);
        $this->getJson("/api/mobile/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.is_participant', false);
    }
}

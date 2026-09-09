<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Invitation;
use App\Models\User;
use App\Services\FCMNotificationService;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Организатор сажает игрока в состав сразу.
 *
 * Приглашение ждёт ответа, а тут человек уже договорился — плюс у свободного
 * места должен записывать, а не спрашивать.
 */
class GameAddPlayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Пуши в тестах не шлём: файла ключей firebase локально нет.
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    private function game(User $creator, Club $club, array $override = []): Game
    {
        $game = Game::factory()->create(array_merge([
            'creator_id' => $creator->id,
            'club_id' => $club->id,
            'capacity' => 4,
            'status' => Game::STATUS_OPEN,
        ], $override));

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $creator->id,
            'position' => 1,
            'status' => GamePlayer::STATUS_ACCEPTED,
            'source' => GamePlayer::SOURCE_CREATOR,
        ]);

        return $game;
    }

    public function test_organizer_seats_player_right_away(): void
    {
        $club = Club::factory()->create();
        $creator = User::factory()->create();
        $guest = User::factory()->create();
        Sanctum::actingAs($creator);

        $game = $this->game($creator, $club);

        $this->postJson("/api/mobile/games/{$game->id}/players", ['user_id' => $guest->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $guest->id)->first();
        $this->assertSame(GamePlayer::STATUS_ACCEPTED, $player->status);
        $this->assertSame(2, $player->position);
        $this->assertNotNull($player->responded_at);
    }

    public function test_pending_invitation_becomes_a_seat(): void
    {
        $club = Club::factory()->create();
        $creator = User::factory()->create();
        $guest = User::factory()->create();
        Sanctum::actingAs($creator);

        $game = $this->game($creator, $club);
        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $guest->id,
            'position' => 3,
            'status' => GamePlayer::STATUS_INVITED,
            'source' => GamePlayer::SOURCE_INVITE,
        ]);
        Invitation::create([
            'user_id' => $guest->id,
            'inviter_id' => $creator->id,
            'invitable_type' => Game::class,
            'invitable_id' => $game->id,
            'kind' => Invitation::KIND_GAME,
            'status' => Invitation::STATUS_PENDING,
        ]);

        $this->postJson("/api/mobile/games/{$game->id}/players", ['user_id' => $guest->id])
            ->assertOk();

        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $guest->id)->first();
        $this->assertSame(GamePlayer::STATUS_ACCEPTED, $player->status);
        $this->assertSame(
            Invitation::STATUS_ACCEPTED,
            Invitation::where('invitable_id', $game->id)->where('user_id', $guest->id)->first()->status,
            'висящий вопрос закрывается'
        );
    }

    public function test_full_game_becomes_full_status(): void
    {
        $club = Club::factory()->create();
        $creator = User::factory()->create();
        Sanctum::actingAs($creator);

        $game = $this->game($creator, $club, ['capacity' => 2]);
        $guest = User::factory()->create();

        $this->postJson("/api/mobile/games/{$game->id}/players", ['user_id' => $guest->id])
            ->assertOk();

        $this->assertSame(Game::STATUS_FULL, $game->fresh()->status);
    }

    public function test_no_free_seats(): void
    {
        $club = Club::factory()->create();
        $creator = User::factory()->create();
        Sanctum::actingAs($creator);

        $game = $this->game($creator, $club, ['capacity' => 1]);

        $this->postJson("/api/mobile/games/{$game->id}/players", [
            'user_id' => User::factory()->create()->id,
        ])->assertStatus(422);
    }

    public function test_same_player_twice(): void
    {
        $club = Club::factory()->create();
        $creator = User::factory()->create();
        $guest = User::factory()->create();
        Sanctum::actingAs($creator);

        $game = $this->game($creator, $club);

        $this->postJson("/api/mobile/games/{$game->id}/players", ['user_id' => $guest->id])->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/players", ['user_id' => $guest->id])
            ->assertStatus(422);

        $this->assertSame(1, GamePlayer::where('game_id', $game->id)->where('user_id', $guest->id)->count());
    }

    public function test_only_organizer(): void
    {
        $club = Club::factory()->create();
        $creator = User::factory()->create();
        $game = $this->game($creator, $club);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/players", [
            'user_id' => User::factory()->create()->id,
        ])->assertStatus(403);
    }
}

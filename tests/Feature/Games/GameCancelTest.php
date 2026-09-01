<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GameActionLog;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Отмена игры организатором.
 *
 * Игра не удаляется — у неё есть участники и журнал. Она пропадает из лент
 * и из «моих игр», но остаётся в базе. Доигранную отменить нельзя: там уже
 * посчитан рейтинг.
 */
class GameCancelTest extends TestCase
{
    use RefreshDatabase;

    /** Пуши в тестах не шлём: Firebase тут не настроен. */
    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    private function game(array $extra = []): Game
    {
        return Game::factory()->create(array_merge([
            'status' => Game::STATUS_OPEN,
            'starts_at' => now()->addDay(),
        ], $extra));
    }

    public function test_organizer_cancels_game(): void
    {
        $organizer = User::factory()->create();
        $game = $this->game(['creator_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(Game::STATUS_CANCELLED, $game->fresh()->status);
        $this->assertDatabaseHas('game_action_logs', [
            'game_id' => $game->id,
            'action' => GameActionLog::ACTION_CANCEL,
        ]);
    }

    public function test_cancelled_game_leaves_feed_and_my_games(): void
    {
        $organizer = User::factory()->create();
        $game = $this->game([
            'creator_id' => $organizer->id,
            'visibility' => Game::VISIBILITY_PUBLIC,
        ]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/cancel")->assertOk();

        $feed = collect($this->getJson('/api/mobile/games')->json('data'))->pluck('id');
        $mine = collect($this->getJson('/api/mobile/games/my')->json('data'))->pluck('id');

        $this->assertNotContains($game->id, $feed, 'из ленты отменённая уходит');
        $this->assertNotContains($game->id, $mine, 'и из моих игр — чтобы не висела');

        // История доступна, если попросить её явно.
        $history = collect($this->getJson('/api/mobile/games/my?status=cancelled')->json('data'))->pluck('id');
        $this->assertContains($game->id, $history);
    }

    public function test_only_organizer_can_cancel(): void
    {
        $game = $this->game();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/cancel")->assertStatus(403);
        $this->assertSame(Game::STATUS_OPEN, $game->fresh()->status);
    }

    public function test_finished_game_cannot_be_cancelled(): void
    {
        $organizer = User::factory()->create();
        $game = $this->game([
            'creator_id' => $organizer->id,
            'status' => Game::STATUS_FINISHED,
        ]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/cancel")->assertStatus(422);
        $this->assertSame(Game::STATUS_FINISHED, $game->fresh()->status);
    }

    public function test_game_with_locked_score_cannot_be_cancelled(): void
    {
        $organizer = User::factory()->create();
        $game = $this->game([
            'creator_id' => $organizer->id,
            'status' => Game::STATUS_IN_PROGRESS,
            'score_locked' => true,
        ]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/cancel")->assertStatus(422);
    }

    public function test_second_cancel_is_rejected(): void
    {
        $organizer = User::factory()->create();
        $game = $this->game(['creator_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/cancel")->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/cancel")->assertStatus(422);
    }

    public function test_players_are_notified(): void
    {
        $organizer = User::factory()->create();
        $player = User::factory()->create();
        $game = $this->game(['creator_id' => $organizer->id]);
        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'position' => 2,
            'status' => GamePlayer::STATUS_ACCEPTED,
            'source' => GamePlayer::SOURCE_APP_FEED,
        ]);

        $this->fakePush();
        Sanctum::actingAs($organizer);
        $this->postJson("/api/mobile/games/{$game->id}/cancel")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $player->id,
            'category' => 'game',
            'type' => 'game_cancelled',
        ]);
    }
}

<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use App\Services\FCMNotificationService;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameFinishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Завершение шлёт пуши участникам — файла ключей локально нет.
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    /** in_progress игра с 4 accepted и одним сыгранным раундом. Возвращает [game, [u1..u4]]. */
    private function playedGame(User $organizer, string $type = 'rated'): array
    {
        $game = Game::factory()->create([
            'creator_id' => $organizer->id, 'status' => 'in_progress',
            'type' => $type, 'format' => 'sets',
        ]);
        // Рейтинг выше минимального: у новичка с 1000 проигрыш обрезается
        // нижней границей, и «просели» проверить нечем.
        $organizer->update(['rating' => 2000]);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create(['rating' => 2000]);
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        GameRound::create([
            'game_id' => $game->id, 'round_no' => 1,
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
            'score_a' => 6, 'score_b' => 3, 'is_played' => true,
        ]);
        return [$game, $ids];
    }

    public function test_organizer_finishes_the_game_at_once(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->playedGame($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/finish")
            ->assertOk()
            ->assertJsonPath('data.score_locked', true)
            ->assertJsonPath('data.status', 'finished');

        // Ждать, пока каждый нажмёт «подтвердить», больше не нужно.
        $this->assertSame('finished', $game->fresh()->status);
        $this->assertSame(
            4,
            GamePlayer::where('game_id', $game->id)->where('score_confirmed', true)->count()
        );

        // Рейтинг посчитан там же: победители выросли, проигравшие просели.
        foreach ($ids as $i => $id) {
            $player = GamePlayer::where('game_id', $game->id)->where('user_id', $id)->first();
            $this->assertNotNull($player->rating_change, 'изменение записано');
            if ($i < 2) {
                $this->assertGreaterThan(0, $player->rating_change);
            } else {
                $this->assertLessThan(0, $player->rating_change);
            }
        }
    }

    public function test_friendly_game_does_not_touch_rating(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->playedGame($organizer, 'friendly');
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertOk();

        $this->assertSame('finished', $game->fresh()->status);
        $this->assertNull(
            GamePlayer::where('game_id', $game->id)->first()->rating_change
        );
    }

    public function test_finish_requires_played_round(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress', 'format' => 'sets']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertStatus(422);
    }

    public function test_non_organizer_cannot_finish(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->playedGame($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertStatus(403);
    }

    public function test_cannot_finish_twice(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->playedGame($organizer);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/finish")->assertStatus(422); // игра уже завершена
    }
}

<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Notification;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GameRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Пуш не шлём реально.
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->app->instance(FCMNotificationService::class, $mock);
    }

    private function gameStartingIn(int $minutes): array
    {
        $game = Game::factory()->create([
            'status' => 'full', 'format' => 'sets',
            'starts_at' => now()->addMinutes($minutes),
            'ends_at' => now()->addMinutes($minutes + 90),
        ]);
        $u = User::factory()->create();
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        return [$game, $u];
    }

    public function test_sends_1h_reminder_and_sets_flag_once(): void
    {
        [$game, $u] = $this->gameStartingIn(50); // ≤1ч

        $this->artisan('games:send-reminders')->assertExitCode(0);

        $this->assertNotNull($game->fresh()->reminded_1h_at);
        $this->assertSame(1, Notification::where('user_id', $u->id)->where('type', 'game_reminder')->count());

        // Повторный запуск не шлёт второй раз.
        $this->artisan('games:send-reminders')->assertExitCode(0);
        $this->assertSame(1, Notification::where('user_id', $u->id)->where('type', 'game_reminder')->count());
    }

    public function test_does_not_remind_far_future_game(): void
    {
        [$game, $u] = $this->gameStartingIn(60 * 48); // через 2 суток

        $this->artisan('games:send-reminders')->assertExitCode(0);

        $this->assertNull($game->fresh()->reminded_1d_at);
        $this->assertSame(0, Notification::where('user_id', $u->id)->count());
    }

    public function test_does_not_remind_finished_game(): void
    {
        [$game, $u] = $this->gameStartingIn(50);
        $game->update(['status' => 'finished']);

        $this->artisan('games:send-reminders')->assertExitCode(0);

        $this->assertNull($game->fresh()->reminded_1h_at);
        $this->assertSame(0, Notification::where('user_id', $u->id)->count());
    }
}

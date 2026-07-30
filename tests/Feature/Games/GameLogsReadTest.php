<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GameActionLog;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameLogsReadTest extends TestCase
{
    use RefreshDatabase;

    private function gameWithLog(User $organizer): Game
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'in_progress', 'format' => 'sets']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        GameActionLog::create(['game_id' => $game->id, 'user_id' => $organizer->id, 'action' => GameActionLog::ACTION_START, 'payload' => null]);
        return $game;
    }

    public function test_organizer_reads_logs(): void
    {
        $organizer = User::factory()->create();
        $game = $this->gameWithLog($organizer);
        Sanctum::actingAs($organizer);

        $this->getJson("/api/mobile/games/{$game->id}/logs")
            ->assertOk()
            ->assertJsonPath('data.0.action', GameActionLog::ACTION_START)
            ->assertJsonPath('data.0.user_id', $organizer->id);
    }

    public function test_outsider_cannot_read_logs(): void
    {
        $organizer = User::factory()->create();
        $game = $this->gameWithLog($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/games/{$game->id}/logs")->assertStatus(403);
    }
}

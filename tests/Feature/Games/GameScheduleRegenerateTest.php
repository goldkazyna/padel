<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameScheduleRegenerateTest extends TestCase
{
    use RefreshDatabase;

    /** in_progress американо с 4 accepted и сгенерированным расписанием. */
    private function startedAmericano(User $organizer): array
    {
        $game = Game::factory()->create([
            'creator_id' => $organizer->id,
            'status' => 'full',
            'format' => 'americano',
            'format_meta' => ['sub' => 'by_points', 'target' => 24],
        ]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        Sanctum::actingAs($organizer);
        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();
        return [$game];
    }

    public function test_regenerate_replaces_rounds(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->startedAmericano($organizer);

        $before = GameRound::where('game_id', $game->id)->orderBy('round_no')->pluck('id')->all();
        $this->assertCount(3, $before);

        $this->postJson("/api/mobile/games/{$game->id}/schedule/regenerate")->assertOk();

        $after = GameRound::where('game_id', $game->id)->orderBy('round_no')->pluck('id')->all();
        $this->assertCount(3, $after);
        // Старые строки удалены, созданы новые.
        $this->assertEmpty(array_intersect($before, $after));
        $this->assertSame([1, 2, 3], GameRound::where('game_id', $game->id)->orderBy('round_no')->pluck('round_no')->all());
    }

    public function test_regenerate_blocked_after_score_entered(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->startedAmericano($organizer);

        $first = GameRound::where('game_id', $game->id)->orderBy('round_no')->first();
        $first->update(['score_a' => 24, 'score_b' => 18, 'is_played' => true]);

        $this->postJson("/api/mobile/games/{$game->id}/schedule/regenerate")->assertStatus(422);
        $this->assertSame(3, GameRound::where('game_id', $game->id)->count());
    }

    public function test_regenerate_non_organizer_forbidden(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->startedAmericano($organizer);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/schedule/regenerate")->assertStatus(403);
    }
}

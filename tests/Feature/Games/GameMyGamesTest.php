<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameMyGamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_games_where_organizer_or_participant(): void
    {
        $me = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($me);

        // Организатор.
        $mine = Game::factory()->create(['creator_id' => $me->id, 'status' => 'finished', 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour()]);
        // Участник.
        $joined = Game::factory()->create(['status' => 'in_progress', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        GamePlayer::factory()->create(['game_id' => $joined->id, 'user_id' => $me->id, 'position' => 2, 'status' => GamePlayer::STATUS_ACCEPTED]);
        // Чужая игра — не участвую.
        Game::factory()->create(['status' => 'open', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        // Игра, где я вышел (left) — не показывать.
        $leftGame = Game::factory()->create(['status' => 'open', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        GamePlayer::factory()->create(['game_id' => $leftGame->id, 'user_id' => $me->id, 'position' => 3, 'status' => GamePlayer::STATUS_LEFT]);

        $res = $this->getJson('/api/mobile/games/my')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertContains($joined->id, $ids);
        $this->assertSame(2, $res->json('meta.total'));
    }

    public function test_status_filter(): void
    {
        $me = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($me);
        Game::factory()->create(['creator_id' => $me->id, 'status' => 'finished', 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour()]);
        Game::factory()->create(['creator_id' => $me->id, 'status' => 'open', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);

        $res = $this->getJson('/api/mobile/games/my?status=finished')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('finished', $res->json('data.0.status'));
    }
}

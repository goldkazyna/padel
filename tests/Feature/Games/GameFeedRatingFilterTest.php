<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameFeedRatingFilterTest extends TestCase
{
    use RefreshDatabase;

    private function publicGame(array $override = []): Game
    {
        return Game::factory()->create(array_merge([
            'visibility' => 'public', 'status' => 'open', 'starts_at' => now()->addDay(),
        ], $override));
    }

    public function test_feed_hides_games_out_of_user_level_by_default(): void
    {
        $inRange = $this->publicGame(['rating_min' => 2.0, 'rating_max' => 4.0]);
        $tooHigh = $this->publicGame(['rating_min' => 4.5, 'rating_max' => 5.5]);
        $noLimit = $this->publicGame(['rating_min' => null, 'rating_max' => null]);

        Sanctum::actingAs(User::factory()->create(['level' => 3.0]));

        $ids = collect($this->getJson('/api/mobile/games')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($inRange->id, $ids);
        $this->assertContains($noLimit->id, $ids);
        $this->assertNotContains($tooHigh->id, $ids);
    }

    public function test_toggle_shows_out_of_level_games(): void
    {
        $tooHigh = $this->publicGame(['rating_min' => 4.5, 'rating_max' => 5.5]);
        Sanctum::actingAs(User::factory()->create(['level' => 3.0]));

        $ids = collect($this->getJson('/api/mobile/games?show_out_of_level=1')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($tooHigh->id, $ids);
    }
}

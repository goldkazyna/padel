<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Лента игр и уровень игрока.
 *
 * Раньше игра со своим диапазоном была видна слишком узкому кругу и так и
 * стояла пустой. Теперь уровень игру не прячет: её видно всем, но карточка
 * честно говорит, подходит ли уровень, а решает организатор — заявку он
 * одобряет вручную.
 */
class GameFeedRatingFilterTest extends TestCase
{
    use RefreshDatabase;

    private function publicGame(array $override = []): Game
    {
        return Game::factory()->create(array_merge([
            'visibility' => 'public', 'status' => 'open', 'starts_at' => now()->addDay(),
        ], $override));
    }

    public function test_feed_shows_games_of_any_level(): void
    {
        $inRange = $this->publicGame(['rating_min' => 2.0, 'rating_max' => 4.0]);
        $tooHigh = $this->publicGame(['rating_min' => 4.5, 'rating_max' => 5.5]);
        $tooLow = $this->publicGame(['rating_min' => 1.0, 'rating_max' => 1.5]);
        $noLimit = $this->publicGame(['rating_min' => null, 'rating_max' => null]);

        Sanctum::actingAs(User::factory()->create(['level' => 3.0]));

        $ids = collect($this->getJson('/api/mobile/games')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($inRange->id, $ids);
        $this->assertContains($noLimit->id, $ids);
        $this->assertContains($tooHigh->id, $ids, 'игру выше уровнем тоже видно');
        $this->assertContains($tooLow->id, $ids, 'и ниже уровнем');
    }

    public function test_card_says_whether_level_fits(): void
    {
        $this->publicGame(['rating_min' => 4.5, 'rating_max' => 5.5]);
        $this->publicGame(['rating_min' => 2.0, 'rating_max' => 4.0]);

        Sanctum::actingAs(User::factory()->create(['level' => 3.0]));

        $games = collect($this->getJson('/api/mobile/games')->assertOk()->json('data'))
            ->keyBy('rating_min');

        $this->assertFalse($games[4.5]['level_matches'], 'чужой уровень помечен');
        $this->assertTrue($games[2.0]['level_matches']);
    }

    public function test_only_my_level_filters_feed_on_demand(): void
    {
        $inRange = $this->publicGame(['rating_min' => 2.0, 'rating_max' => 4.0]);
        $tooHigh = $this->publicGame(['rating_min' => 4.5, 'rating_max' => 5.5]);

        Sanctum::actingAs(User::factory()->create(['level' => 3.0]));

        $ids = collect($this->getJson('/api/mobile/games?only_my_level=1')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($inRange->id, $ids);
        $this->assertNotContains($tooHigh->id, $ids);
    }
}

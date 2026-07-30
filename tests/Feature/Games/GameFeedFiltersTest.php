<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameFeedFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function openGame(array $override = []): Game
    {
        return Game::factory()->create(array_merge([
            'status' => 'open', 'visibility' => 'public',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
            'rating_min' => null, 'rating_max' => null,
        ], $override));
    }

    public function test_filter_by_format(): void
    {
        $user = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($user);
        $this->openGame(['format' => 'sets']);
        $this->openGame(['format' => 'americano', 'format_meta' => ['sub' => 'by_points', 'target' => 24]]);

        $res = $this->getJson('/api/mobile/games?format=americano')->assertOk();
        $res->assertJsonPath('meta.total', 1);
        $this->assertSame('americano', $res->json('data.0.format'));
    }

    public function test_filter_by_club(): void
    {
        $user = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($user);
        $club = Club::factory()->create();
        $this->openGame(['club_id' => $club->id]);
        $this->openGame(); // другой клуб

        $res = $this->getJson("/api/mobile/games?club_id={$club->id}")->assertOk();
        $res->assertJsonPath('meta.total', 1);
        $this->assertSame($club->id, $res->json('data.0.club.id'));
    }

    public function test_pagination_meta(): void
    {
        $user = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($user);
        for ($i = 0; $i < 3; $i++) {
            $this->openGame(['starts_at' => now()->addDays($i + 1), 'ends_at' => now()->addDays($i + 1)->addHour()]);
        }

        $res = $this->getJson('/api/mobile/games?per_page=2&page=1')->assertOk();
        $res->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);
        $this->assertCount(2, $res->json('data'));
    }
}

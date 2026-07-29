<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameShowIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_game(): void
    {
        $game = Game::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $game->id);
    }

    public function test_index_lists_only_public_future_open_games(): void
    {
        $public = Game::factory()->create([
            'visibility' => 'public', 'status' => 'open', 'starts_at' => now()->addDay(),
        ]);
        Game::factory()->create([
            'visibility' => 'private', 'status' => 'open', 'starts_at' => now()->addDay(),
        ]);
        Game::factory()->create([
            'visibility' => 'public', 'status' => 'finished', 'starts_at' => now()->addDay(),
        ]);
        Game::factory()->create([
            'visibility' => 'public', 'status' => 'open', 'starts_at' => now()->subDay(),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $res = $this->getJson('/api/mobile/games')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$public->id], $ids);
    }
}

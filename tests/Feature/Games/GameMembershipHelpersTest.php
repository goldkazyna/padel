<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameMembershipHelpersTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_player_finds_by_phone(): void
    {
        $u = User::factory()->create(['phone' => '77011234567']);
        Sanctum::actingAs(User::factory()->create());

        $res = $this->postJson('/api/mobile/games/search-player', ['phone' => '7011234'])->assertOk();
        $this->assertContains($u->id, collect($res->json('data'))->pluck('id')->all());
    }

    public function test_search_player_empty_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/mobile/games/search-player', ['phone' => 'zzzz'])->assertStatus(404);
    }

    public function test_format_game_exposes_my_membership(): void
    {
        $me = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $me->id]);
        GamePlayer::factory()->create([
            'game_id' => $game->id, 'user_id' => $me->id, 'position' => 2,
            'status' => GamePlayer::STATUS_ACCEPTED,
        ]);
        Sanctum::actingAs($me);

        $res = $this->getJson("/api/mobile/games/{$game->id}")->assertOk();
        $res->assertJsonPath('data.is_participant', true);
        $res->assertJsonPath('data.my_status', 'accepted');
        $res->assertJsonPath('data.my_position', 2);
    }
}

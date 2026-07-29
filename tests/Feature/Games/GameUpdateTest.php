<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function editPayload(Game $game, array $override = []): array
    {
        return array_merge([
            'club_id' => $game->club_id,
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(2)->addMinutes(90)->toIso8601String(),
            'type' => 'rated',
            'visibility' => 'public',
            'format' => 'sets',
        ], $override);
    }

    public function test_organizer_can_edit_and_toggle_privacy(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'visibility' => 'public']);
        Sanctum::actingAs($organizer);

        $res = $this->putJson("/api/mobile/games/{$game->id}", $this->editPayload($game, [
            'visibility' => 'private', 'price' => 5000,
        ]));

        $res->assertOk()->assertJsonPath('data.visibility', 'private');
        $this->assertSame(5000, $game->fresh()->price);
    }

    public function test_non_organizer_cannot_edit(): void
    {
        $game = Game::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/mobile/games/{$game->id}", $this->editPayload($game))
            ->assertStatus(403);
    }

    public function test_cannot_edit_locked_game(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'score_locked' => true]);
        Sanctum::actingAs($organizer);

        $this->putJson("/api/mobile/games/{$game->id}", $this->editPayload($game))
            ->assertStatus(403);
    }
}

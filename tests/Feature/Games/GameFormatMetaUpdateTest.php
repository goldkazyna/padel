<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameFormatMetaUpdateTest extends TestCase
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

    public function test_update_rejects_invalid_points_meta(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->putJson("/api/mobile/games/{$game->id}", $this->editPayload($game, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'first_to'], // без target
        ]))->assertStatus(422);
    }

    public function test_update_accepts_valid_points_meta(): void
    {
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id]);
        Sanctum::actingAs($organizer);

        $this->putJson("/api/mobile/games/{$game->id}", $this->editPayload($game, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'first_to', 'points_target' => 24],
        ]))->assertOk()->assertJsonPath('data.format', 'points');
    }
}

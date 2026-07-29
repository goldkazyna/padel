<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameFormatMetaTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Club $club, array $override = []): array
    {
        return array_merge([
            'club_id' => $club->id,
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addMinutes(90)->toIso8601String(),
            'type' => 'rated',
            'visibility' => 'public',
            'format' => 'sets',
        ], $override);
    }

    public function test_points_requires_valid_mode(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        // points без format_meta → 422
        $this->postJson('/api/mobile/games', $this->payload($club, ['format' => 'points']))
            ->assertStatus(422);

        // points с некорректным mode → 422
        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'weird', 'points_target' => 21],
        ]))->assertStatus(422);
    }

    public function test_points_first_to_requires_target(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'first_to'], // без target
        ]))->assertStatus(422);

        // корректный points → 201
        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'first_to', 'points_target' => 21],
        ]))->assertCreated();
    }

    public function test_americano_requires_sub_and_target(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'americano',
            'format_meta' => ['sub' => 'nope', 'target' => 7],
        ]))->assertStatus(422);

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'americano',
            'format_meta' => ['sub' => 'by_tiebreak', 'target' => 11],
        ]))->assertCreated();
    }

    public function test_sets_meta_optional(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        // sets без meta → 201
        $this->postJson('/api/mobile/games', $this->payload($club, ['format' => 'sets']))
            ->assertCreated();

        // sets с tiebreak bool → 201
        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'sets', 'format_meta' => ['tiebreak' => true],
        ]))->assertCreated();
    }
}

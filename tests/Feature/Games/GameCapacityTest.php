<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Сколько человек берём в игру: 4 по умолчанию, больше — только Американо. */
class GameCapacityTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Club $club, array $override = []): array
    {
        return array_merge([
            'club_id' => $club->id,
            'starts_at' => now('Asia/Almaty')->addDay()->toIso8601String(),
            'ends_at' => now('Asia/Almaty')->addDay()->addMinutes(90)->toIso8601String(),
            'type' => 'rated',
            'visibility' => 'public',
            'format' => 'sets',
        ], $override);
    }

    public function test_capacity_defaults_to_four(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $res = $this->postJson('/api/mobile/games', $this->payload($club));

        $res->assertCreated()->assertJsonPath('data.capacity', 4);
    }

    public function test_americano_accepts_more_than_four(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $res = $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'americano',
            'format_meta' => ['sub' => 'by_points', 'target' => 24],
            'capacity' => 8,
        ]));

        $res->assertCreated()->assertJsonPath('data.capacity', 8);
        $this->assertSame(8, (int) Game::first()->capacity);
    }

    public function test_sets_format_rejects_more_than_four(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'sets',
            'capacity' => 6,
        ]))->assertStatus(422);

        $this->assertNull(Game::first());
    }

    public function test_capacity_below_four_is_rejected(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'capacity' => 3,
        ]))->assertStatus(422);
    }

    public function test_update_changes_capacity(): void
    {
        $club = Club::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $game = Game::factory()->create([
            'creator_id' => $user->id,
            'club_id' => $club->id,
            'format' => 'americano',
            'format_meta' => ['sub' => 'by_points', 'target' => 24],
            'capacity' => 4,
        ]);

        $res = $this->putJson("/api/mobile/games/{$game->id}", $this->payload($club, [
            'format' => 'americano',
            'format_meta' => ['sub' => 'by_points', 'target' => 24],
            'capacity' => 8,
        ]));

        $res->assertOk()->assertJsonPath('data.capacity', 8);
    }

    public function test_update_cannot_drop_capacity_below_accepted_players(): void
    {
        $club = Club::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $game = Game::factory()->create([
            'creator_id' => $user->id,
            'club_id' => $club->id,
            'format' => 'americano',
            'format_meta' => ['sub' => 'by_points', 'target' => 24],
            'capacity' => 8,
        ]);
        foreach (User::factory()->count(6)->create() as $player) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'status' => GamePlayer::STATUS_ACCEPTED,
                'source' => GamePlayer::SOURCE_INVITE,
            ]);
        }

        $this->putJson("/api/mobile/games/{$game->id}", $this->payload($club, [
            'format' => 'americano',
            'format_meta' => ['sub' => 'by_points', 'target' => 24],
            'capacity' => 4,
        ]))->assertStatus(422);

        $this->assertSame(8, (int) $game->fresh()->capacity);
    }
}

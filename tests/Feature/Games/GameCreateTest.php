<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameCreateTest extends TestCase
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

    public function test_creates_game_with_creator_as_accepted_player(): void
    {
        $club = Club::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/mobile/games', $this->payload($club));

        $res->assertCreated()->assertJson(['success' => true]);
        $res->assertJsonPath('data.is_creator', true);
        $res->assertJsonPath('data.status', 'open');
        $this->assertNotEmpty($res->json('data.share_token'));

        $game = Game::first();
        $this->assertSame($user->id, $game->creator_id);
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $user->id)->first();
        $this->assertSame(GamePlayer::STATUS_ACCEPTED, $player->status);
        $this->assertSame(GamePlayer::SOURCE_CREATOR, $player->source);
    }

    public function test_validation_rejects_end_before_start(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'ends_at' => now()->addDay()->toIso8601String(),
            'starts_at' => now()->addDay()->addHour()->toIso8601String(),
        ]))->assertStatus(422);
    }

    public function test_validation_rejects_too_long_duration(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/mobile/games', $this->payload($club, [
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHours(7)->toIso8601String(),
        ]))->assertStatus(422);
    }

    public function test_points_format_meta_persisted(): void
    {
        $club = Club::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $res = $this->postJson('/api/mobile/games', $this->payload($club, [
            'format' => 'points',
            'format_meta' => ['points_mode' => 'first_to', 'points_target' => 21],
        ]));
        $res->assertCreated();
        $this->assertSame(21, Game::first()->format_meta['points_target']);
    }
}

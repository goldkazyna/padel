<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_belongs_to_creator_and_club(): void
    {
        $game = Game::factory()->create();
        $this->assertInstanceOf(User::class, $game->creator);
        $this->assertInstanceOf(Club::class, $game->club);
    }

    public function test_is_organizer_matches_creator(): void
    {
        $creator = User::factory()->create();
        $other = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $creator->id]);
        $this->assertTrue($game->isOrganizer($creator->id));
        $this->assertFalse($game->isOrganizer($other->id));
    }

    public function test_accepted_count_and_available_positions(): void
    {
        $game = Game::factory()->create();
        GamePlayer::factory()->create([
            'game_id' => $game->id, 'position' => 1,
            'status' => GamePlayer::STATUS_ACCEPTED,
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id, 'position' => 2,
            'status' => GamePlayer::STATUS_INVITED,
        ]);
        $this->assertSame(1, $game->fresh()->acceptedCount());
        // Позиция занята и accepted, и invited; свободны 3 и 4.
        $this->assertSame([3, 4], $game->fresh()->getAvailablePositions());
    }

    public function test_format_meta_is_cast_to_array(): void
    {
        $game = Game::factory()->create(['format' => 'points', 'format_meta' => ['points_mode' => 'first_to', 'points_target' => 21]]);
        $this->assertIsArray($game->fresh()->format_meta);
        $this->assertSame(21, $game->fresh()->format_meta['points_target']);
    }

    public function test_share_link_active_respects_revoke(): void
    {
        $game = Game::factory()->create(['share_token' => 'abc', 'share_revoked_at' => null]);
        $this->assertTrue($game->shareLinkActive());
        $game->update(['share_revoked_at' => now()]);
        $this->assertFalse($game->fresh()->shareLinkActive());
    }
}

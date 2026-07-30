<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GameReminderFlagsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_games_table_has_reminder_flag_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('games', 'reminded_1d_at'));
        $this->assertTrue(Schema::hasColumn('games', 'reminded_2h_at'));
        $this->assertTrue(Schema::hasColumn('games', 'reminded_1h_at'));
    }

    public function test_flags_are_fillable_and_cast_datetime(): void
    {
        $game = Game::factory()->create();
        $game->update(['reminded_1d_at' => now()]);
        $this->assertNotNull($game->fresh()->reminded_1d_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $game->fresh()->reminded_1d_at);
    }
}

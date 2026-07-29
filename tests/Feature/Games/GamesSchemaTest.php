<?php

namespace Tests\Feature\Games;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GamesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_games_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('games'));
        $this->assertTrue(Schema::hasColumns('games', [
            'id', 'creator_id', 'club_id', 'court_id', 'starts_at', 'ends_at',
            'type', 'visibility', 'format', 'format_meta', 'rating_min', 'rating_max',
            'capacity', 'price', 'description', 'status', 'score_locked',
            'share_token', 'share_expires_at', 'share_max_uses', 'share_uses',
            'share_revoked_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_supporting_tables_exist_with_key_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('game_players', [
            'game_id', 'user_id', 'position', 'status', 'source', 'out_of_range',
            'rating_before', 'rating_after', 'rating_change', 'score_confirmed', 'responded_at',
        ]));
        $this->assertTrue(Schema::hasColumns('game_rounds', [
            'game_id', 'round_no', 'pair_a', 'pair_b', 'score_a', 'score_b',
            'tiebreak_a', 'tiebreak_b', 'is_played',
        ]));
        $this->assertTrue(Schema::hasColumns('game_action_logs', ['game_id', 'user_id', 'action', 'payload']));
        $this->assertTrue(Schema::hasColumns('invitations', [
            'user_id', 'inviter_id', 'invitable_type', 'invitable_id', 'kind', 'status', 'expires_at',
        ]));
        $this->assertTrue(Schema::hasColumns('game_transfers', ['game_id', 'from_user_id', 'to_user_id', 'status']));
    }
}

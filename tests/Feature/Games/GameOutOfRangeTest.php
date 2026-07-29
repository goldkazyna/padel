<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class GameOutOfRangeTest extends TestCase
{
    use RefreshDatabase;

    private function fakePush(): void
    {
        $m = Mockery::mock(\App\Services\FCMNotificationService::class);
        $m->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(\App\Services\FCMNotificationService::class, $m);
    }

    public function test_apply_out_of_range_sets_flag(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open', 'rating_min' => 4.0, 'rating_max' => 5.0]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);

        $applicant = User::factory()->create(['level' => 2.5]); // вне диапазона
        Sanctum::actingAs($applicant);

        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();
        $this->assertTrue((bool) GamePlayer::where('game_id', $game->id)->where('user_id', $applicant->id)->first()->out_of_range);
    }

    public function test_apply_in_range_flag_false(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open', 'rating_min' => 2.0, 'rating_max' => 4.0]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);

        $applicant = User::factory()->create(['level' => 3.0]);
        Sanctum::actingAs($applicant);

        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();
        $this->assertFalse((bool) GamePlayer::where('game_id', $game->id)->where('user_id', $applicant->id)->first()->out_of_range);
    }

    public function test_invite_out_of_range_sets_flag(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'rating_min' => 4.0, 'rating_max' => 5.0]);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        $invitee = User::factory()->create(['level' => 2.0]); // вне диапазона
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/invite", ['user_id' => $invitee->id])->assertOk();
        $this->assertTrue((bool) GamePlayer::where('game_id', $game->id)->where('user_id', $invitee->id)->first()->out_of_range);
    }
}

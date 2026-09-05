<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Notification;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Вход в игру из ленты.
 *
 * Свободное место человек занимает сам — модерации нет. Кандидат остаётся
 * только как очередь на полную игру: пока вход был через одобрение, из
 * девятнадцати заявок не одобрили ни одной, люди висели месяцами.
 */
class GameApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    private function openGame(User $organizer): Game
    {
        $game = Game::factory()->create(['creator_id' => $organizer->id, 'status' => 'open']);
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        return $game;
    }

    /** Забить игру под завязку: организатор плюс ещё трое. */
    private function fillGame(Game $game): void
    {
        foreach ([2, 3, 4] as $position) {
            GamePlayer::factory()->create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'position' => $position,
                'status' => GamePlayer::STATUS_ACCEPTED,
            ]);
        }
        $game->update(['status' => Game::STATUS_FULL]);
    }

    public function test_free_slot_puts_player_straight_into_the_game(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $applicant = User::factory()->create(['name' => 'Асхат']);
        Sanctum::actingAs($applicant);

        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();

        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $applicant->id)->first();
        $this->assertSame(GamePlayer::STATUS_ACCEPTED, $player->status);
        $this->assertNotNull($player->position, 'место занято, а не «висит заявка»');
        $this->assertNotNull($player->responded_at);
    }

    public function test_organizer_is_notified_about_new_player(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $applicant = User::factory()->create(['name' => 'Асхат']);
        Sanctum::actingAs($applicant);

        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();

        $notification = Notification::where('user_id', $organizer->id)->latest('id')->first();
        $this->assertSame('game_joined', $notification->type);
        $this->assertStringContainsString('Асхат', $notification->body);
    }

    public function test_full_game_puts_player_in_queue(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $this->fillGame($game);

        $applicant = User::factory()->create();
        Sanctum::actingAs($applicant);

        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();

        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $applicant->id)->first();
        $this->assertSame(GamePlayer::STATUS_CANDIDATE, $player->status);
        $this->assertNull($player->position);
    }

    public function test_freed_slot_goes_to_the_first_in_queue(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $this->fillGame($game);

        $first = User::factory()->create();
        $second = User::factory()->create();
        foreach ([$first, $second] as $waiting) {
            Sanctum::actingAs($waiting);
            $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();
        }

        // Кто-то из состава ушёл — место достаётся тому, кто встал в очередь раньше.
        $leaving = $game->players()->where('status', GamePlayer::STATUS_ACCEPTED)
            ->where('user_id', '!=', $organizer->id)->first();
        Sanctum::actingAs($leaving->user);
        $this->postJson("/api/mobile/games/{$game->id}/leave")->assertOk();

        $this->assertSame(
            GamePlayer::STATUS_ACCEPTED,
            GamePlayer::where('game_id', $game->id)->where('user_id', $first->id)->first()->status
        );
        $this->assertSame(
            GamePlayer::STATUS_CANDIDATE,
            GamePlayer::where('game_id', $game->id)->where('user_id', $second->id)->first()->status,
            'второй ждёт дальше — освободилось одно место'
        );
    }

    public function test_organizer_takes_player_from_queue_manually(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $applicant = User::factory()->create();
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $applicant->id, 'position' => null, 'status' => GamePlayer::STATUS_CANDIDATE]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/applications/{$player->id}/approve")->assertOk();

        $player->refresh();
        $this->assertSame(GamePlayer::STATUS_ACCEPTED, $player->status);
        $this->assertNotNull($player->position);
    }

    public function test_non_organizer_cannot_approve(): void
    {
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'position' => null, 'status' => GamePlayer::STATUS_CANDIDATE]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/games/{$game->id}/applications/{$player->id}/approve")->assertStatus(403);
    }

    public function test_organizer_rejects_candidate(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => User::factory()->create()->id, 'position' => null, 'status' => GamePlayer::STATUS_CANDIDATE]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/applications/{$player->id}/reject")->assertOk();
        $this->assertSame(GamePlayer::STATUS_DECLINED, $player->fresh()->status);
    }

    public function test_can_rejoin_after_leave(): void
    {
        $this->fakePush();
        $organizer = User::factory()->create();
        $game = $this->openGame($organizer);
        $applicant = User::factory()->create();
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $applicant->id, 'position' => null, 'status' => GamePlayer::STATUS_LEFT]);
        Sanctum::actingAs($applicant);

        $this->postJson("/api/mobile/games/{$game->id}/apply")->assertOk();

        $this->assertSame(1, GamePlayer::where('game_id', $game->id)->where('user_id', $applicant->id)->count());
        $player = GamePlayer::where('game_id', $game->id)->where('user_id', $applicant->id)->first();
        $this->assertSame(GamePlayer::STATUS_ACCEPTED, $player->status);
    }
}

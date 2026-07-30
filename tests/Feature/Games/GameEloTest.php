<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\RatingHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameEloTest extends TestCase
{
    use RefreshDatabase;

    /** Фаза подтверждения (score_locked), организатор подтверждён; все игроки rating=1500. */
    private function pendingRated(User $organizer, int $scoreA, int $scoreB): array
    {
        $organizer->update(['rating' => 1500]);
        $game = Game::factory()->create([
            'creator_id' => $organizer->id, 'status' => 'in_progress',
            'type' => 'rated', 'format' => 'sets', 'score_locked' => true,
        ]);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED, 'score_confirmed' => true]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create(['rating' => 1500]);
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED, 'score_confirmed' => false]);
        }
        GameRound::create([
            'game_id' => $game->id, 'round_no' => 1,
            'pair_a' => [$ids[0], $ids[1]], 'pair_b' => [$ids[2], $ids[3]],
            'score_a' => $scoreA, 'score_b' => $scoreB, 'is_played' => true,
        ]);
        return [$game, $ids];
    }

    private function confirmAll(Game $game, array $ids): void
    {
        foreach ([$ids[1], $ids[2], $ids[3]] as $uid) {
            Sanctum::actingAs(User::find($uid));
            $this->postJson("/api/mobile/games/{$game->id}/confirm-score")->assertOk();
        }
    }

    public function test_rated_game_applies_elo_winners_up_losers_down(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingRated($organizer, 6, 2); // team A (u1,u2) выигрывает
        $this->confirmAll($game, $ids);

        $this->assertSame('finished', $game->fresh()->status);

        $u1 = User::find($ids[0]);
        $u3 = User::find($ids[2]);
        $this->assertGreaterThan(1500, $u1->rating); // победитель вырос
        $this->assertLessThan(1500, $u3->rating);    // проигравший упал

        // Записи game_players и rating_history для всех 4.
        $this->assertSame(4, RatingHistory::where('reason', 'game')->count());
        $gpWinner = GamePlayer::where('game_id', $game->id)->where('user_id', $ids[0])->first();
        $this->assertSame(1500, $gpWinner->rating_before);
        $this->assertGreaterThan(0, $gpWinner->rating_change);
        $this->assertSame($gpWinner->rating_after, $u1->rating);
    }

    public function test_friendly_game_does_not_touch_rating(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingRated($organizer, 6, 2);
        $game->update(['type' => 'friendly']);
        $this->confirmAll($game, $ids);

        $this->assertSame('finished', $game->fresh()->status);
        $this->assertSame(1500, User::find($ids[0])->rating);
        $this->assertSame(0, RatingHistory::where('reason', 'game')->count());
        $gp = GamePlayer::where('game_id', $game->id)->where('user_id', $ids[0])->first();
        $this->assertNull($gp->rating_change);
    }

    public function test_zero_zero_round_no_rating_change(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingRated($organizer, 0, 0); // 0:0 → трейт не меняет рейтинг
        $this->confirmAll($game, $ids);

        $this->assertSame('finished', $game->fresh()->status);
        $this->assertSame(1500, User::find($ids[0])->rating);
        $this->assertSame(1500, User::find($ids[2])->rating);
    }

    /**
     * Многораундовое накопление (running-average): u0 выигрывает все 3 раунда, партнёря
     * каждого по разу. Если бы применялся только один раунд, u0 лишь СРАВНялся бы с партнёром
     * того раунда — поэтому строгое «u0 выше каждого» ловит поломку накопления.
     */
    public function test_multi_round_accumulation_makes_sweeper_strictly_highest(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->pendingRated($organizer, 6, 0); // pendingRated создаёт R1 (u0,u1)>(u2,u3)
        // Добавляем R2 и R3: u0 снова в выигрывающей паре с другими партнёрами.
        GameRound::create([
            'game_id' => $game->id, 'round_no' => 2,
            'pair_a' => [$ids[0], $ids[2]], 'pair_b' => [$ids[1], $ids[3]],
            'score_a' => 6, 'score_b' => 0, 'is_played' => true,
        ]);
        GameRound::create([
            'game_id' => $game->id, 'round_no' => 3,
            'pair_a' => [$ids[0], $ids[3]], 'pair_b' => [$ids[1], $ids[2]],
            'score_a' => 6, 'score_b' => 0, 'is_played' => true,
        ]);

        $this->confirmAll($game, $ids);
        $this->assertSame('finished', $game->fresh()->status);

        $r0 = User::find($ids[0])->rating;
        $r1 = User::find($ids[1])->rating;
        $r2 = User::find($ids[2])->rating;
        $r3 = User::find($ids[3])->rating;

        // u0 выиграл все 3 раунда → строго выше каждого из остальных.
        $this->assertGreaterThan(1500, $r0);
        $this->assertGreaterThan($r1, $r0);
        $this->assertGreaterThan($r2, $r0);
        $this->assertGreaterThan($r3, $r0);

        // По одной записи истории на игрока; консистентность gp.rating_after == users.rating.
        $this->assertSame(4, RatingHistory::where('reason', 'game')->count());
        $gp0 = GamePlayer::where('game_id', $game->id)->where('user_id', $ids[0])->first();
        $this->assertSame($gp0->rating_after, $r0);
    }
}

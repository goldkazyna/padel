<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use App\Support\GameAmericanoRanking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameAmericanoRankingTest extends TestCase
{
    use RefreshDatabase;

    /** Американо-игра с 4 игроками u1..u4 и переданными раундами. */
    private function gameWithRounds(array $userIds, array $rounds): Game
    {
        $game = Game::factory()->create(['format' => 'americano', 'status' => 'in_progress']);
        foreach ($userIds as $i => $uid) {
            GamePlayer::factory()->create([
                'game_id' => $game->id, 'user_id' => $uid, 'position' => $i + 1,
                'status' => GamePlayer::STATUS_ACCEPTED,
            ]);
        }
        $no = 1;
        foreach ($rounds as $r) {
            GameRound::create([
                'game_id' => $game->id, 'round_no' => $no++,
                'pair_a' => $r['a'], 'pair_b' => $r['b'],
                'score_a' => $r['sa'], 'score_b' => $r['sb'], 'is_played' => true,
            ]);
        }
        return $game;
    }

    public function test_ranking_orders_by_points_then_wins_then_diff(): void
    {
        $u = User::factory()->count(4)->create()->pluck('id')->all(); // u[0..3]

        // Классические 3 раунда Американо. Считаем сумму личных очков.
        // R1: (u0,u1)=24 vs (u2,u3)=18  → u0,u1 +24; u2,u3 +18
        // R2: (u0,u2)=24 vs (u1,u3)=20  → u0,u2 +24; u1,u3 +20
        // R3: (u0,u3)=24 vs (u1,u2)=15  → u0,u3 +24; u1,u2 +15
        // Итог очки: u0=72, u1=59, u2=57, u3=62 → порядок u0,u3,u1,u2
        $game = $this->gameWithRounds($u, [
            ['a' => [$u[0], $u[1]], 'b' => [$u[2], $u[3]], 'sa' => 24, 'sb' => 18],
            ['a' => [$u[0], $u[2]], 'b' => [$u[1], $u[3]], 'sa' => 24, 'sb' => 20],
            ['a' => [$u[0], $u[3]], 'b' => [$u[1], $u[2]], 'sa' => 24, 'sb' => 15],
        ]);

        $this->assertSame([$u[0], $u[3], $u[1], $u[2]], GameAmericanoRanking::orderedIds($game));
        $this->assertSame(1, GameAmericanoRanking::place($game, $u[0]));
        $this->assertSame(2, GameAmericanoRanking::place($game, $u[3]));

        $table = GameAmericanoRanking::table($game);
        $this->assertSame($u[0], $table[0]['user_id']);
        $this->assertSame(72, $table[0]['points']);
        $this->assertSame(1, $table[0]['place']);
        $this->assertSame(3, $table[0]['wins']); // u0 выиграл все 3 раунда
    }

    public function test_place_null_for_non_participant(): void
    {
        $u = User::factory()->count(4)->create()->pluck('id')->all();
        $game = $this->gameWithRounds($u, []);
        $outsider = User::factory()->create();

        $this->assertNull(GameAmericanoRanking::place($game, $outsider->id));
        // Все 4 участника присутствуют в таблице даже без сыгранных раундов.
        $this->assertCount(4, GameAmericanoRanking::table($game));
    }

    public function test_show_includes_americano_ranking(): void
    {
        $u = User::factory()->count(4)->create()->pluck('id')->all();
        $game = $this->gameWithRounds($u, [
            ['a' => [$u[0], $u[1]], 'b' => [$u[2], $u[3]], 'sa' => 24, 'sb' => 10],
        ]);
        \Laravel\Sanctum\Sanctum::actingAs(User::find($u[0]));

        $this->getJson("/api/mobile/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.americano_ranking.0.place', 1);
    }
}

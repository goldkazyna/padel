<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameAmericanoScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** full-игра заданного формата с 4 accepted; возвращает [game, [u1..u4]]. */
    private function fullGame(User $organizer, string $format): array
    {
        $game = Game::factory()->create([
            'creator_id' => $organizer->id,
            'status' => 'full',
            'format' => $format,
            'format_meta' => $format === 'americano' ? ['sub' => 'by_points', 'target' => 24] : null,
        ]);
        $ids = [$organizer->id];
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $organizer->id, 'position' => 1, 'status' => GamePlayer::STATUS_ACCEPTED]);
        for ($i = 2; $i <= 4; $i++) {
            $u = User::factory()->create();
            $ids[] = $u->id;
            GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $u->id, 'position' => $i, 'status' => GamePlayer::STATUS_ACCEPTED]);
        }
        return [$game, $ids];
    }

    public function test_start_americano_generates_three_rounds(): void
    {
        $organizer = User::factory()->create();
        [$game, $ids] = $this->fullGame($organizer, 'americano');
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();

        $rounds = GameRound::where('game_id', $game->id)->orderBy('round_no')->get();
        $this->assertCount(3, $rounds);
        $this->assertSame([1, 2, 3], $rounds->pluck('round_no')->all());

        // Каждый раунд: 4 разных принятых игрока, счёт пуст (is_played=false).
        foreach ($rounds as $r) {
            $this->assertFalse((bool) $r->is_played);
            $this->assertNull($r->score_a);
            $four = array_merge($r->pair_a, $r->pair_b);
            $this->assertCount(4, array_unique($four));
            foreach ($four as $uid) {
                $this->assertContains($uid, $ids);
            }
        }

        // Каждая из 6 пар партнёров встречается ровно 1 раз (свойство Американо).
        $partnerKeys = [];
        foreach ($rounds as $r) {
            foreach ([$r->pair_a, $r->pair_b] as $pair) {
                sort($pair);
                $partnerKeys[] = implode('-', $pair);
            }
        }
        $this->assertCount(6, $partnerKeys);
        $this->assertCount(6, array_unique($partnerKeys));
    }

    public function test_start_non_americano_generates_no_rounds(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->fullGame($organizer, 'sets');
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();

        $this->assertSame(0, GameRound::where('game_id', $game->id)->count());
    }

    public function test_restart_does_not_duplicate_rounds(): void
    {
        $organizer = User::factory()->create();
        [$game] = $this->fullGame($organizer, 'americano');
        Sanctum::actingAs($organizer);

        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/start/cancel")->assertOk();
        $this->postJson("/api/mobile/games/{$game->id}/start")->assertOk();

        // Повторный старт не плодит раунды (у игры уже есть расписание).
        $this->assertSame(3, GameRound::where('game_id', $game->id)->count());
    }
}

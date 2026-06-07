<?php

namespace Tests\Unit\Services;

use App\Models\AmericanoMatch;
use App\Models\AmericanoRound;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\AmericanoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия на баг турнира 547: повторное сохранение уже завершённого матча
 * (двойной клик / повторный сабмит после обрыва связи) задваивало total_points,
 * потому что saveMatchResult слепо прибавлял очки без отката.
 */
class ScoreIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private AmericanoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AmericanoService();
    }

    /**
     * @return array{0: AmericanoMatch, 1: array<int,User>}
     */
    private function makeMatch(): array
    {
        $tournament = Tournament::factory()->create([
            'type' => 'americano',
            'status' => 'in_progress',
            'groups_count' => 1,
            'max_participants' => 4,
            'rounds_count' => 1,
        ]);

        $group = TournamentGroup::create([
            'tournament_id' => $tournament->id,
            'name' => 'Группа A',
        ]);

        $players = [];
        for ($i = 0; $i < 4; $i++) {
            $user = User::factory()->create(['rating' => 1500, 'name' => 'P' . ($i + 1)]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
            $group->players()->attach($user->id, [
                'total_points' => 0,
                'rating_before' => 1500,
                'rating_after' => null,
            ]);
            $players[] = $user;
        }

        $round = AmericanoRound::create([
            'tournament_group_id' => $group->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);

        $match = AmericanoMatch::create([
            'americano_round_id' => $round->id,
            'team1_player1_id' => $players[0]->id,
            'team1_player2_id' => $players[1]->id,
            'team2_player1_id' => $players[2]->id,
            'team2_player2_id' => $players[3]->id,
            'status' => 'pending',
        ]);

        return [$match, $players];
    }

    private function points(AmericanoMatch $match, User $user): int
    {
        return (int) $match->round->group->players()
            ->where('user_id', $user->id)
            ->first()->pivot->total_points;
    }

    public function test_resaving_same_match_does_not_double_points(): void
    {
        [$match, $players] = $this->makeMatch();

        // Первое сохранение
        $this->service->saveMatchResult($match->fresh(), 21, 15);

        $this->assertSame(21, $this->points($match, $players[0]), 'команда 1 получает 21');
        $this->assertSame(21, $this->points($match, $players[1]));
        $this->assertSame(15, $this->points($match, $players[2]), 'команда 2 получает 15');
        $this->assertSame(15, $this->points($match, $players[3]));

        // Повторное сохранение того же матча с тем же счётом — НЕ должно задваивать
        $this->service->saveMatchResult($match->fresh(), 21, 15);

        $this->assertSame(21, $this->points($match, $players[0]), 'очки не задвоились');
        $this->assertSame(21, $this->points($match, $players[1]));
        $this->assertSame(15, $this->points($match, $players[2]));
        $this->assertSame(15, $this->points($match, $players[3]));
    }

    public function test_resaving_with_corrected_score_replaces_not_accumulates(): void
    {
        [$match, $players] = $this->makeMatch();

        $this->service->saveMatchResult($match->fresh(), 21, 15);
        // Исправили счёт повторным сохранением
        $this->service->saveMatchResult($match->fresh(), 10, 9);

        $this->assertSame(10, $this->points($match, $players[0]), 'итог — только новый счёт');
        $this->assertSame(10, $this->points($match, $players[1]));
        $this->assertSame(9, $this->points($match, $players[2]));
        $this->assertSame(9, $this->points($match, $players[3]));
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\AmericanoMatch;
use App\Models\AmericanoRound;
use App\Models\MexicanoMatch;
use App\Models\MexicanoPlayer;
use App\Models\MexicanoRound;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\AmericanoService;
use App\Services\MexicanoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия на баг турнира 1051: если счёт матча РЕДАКТИРОВАЛИ (а не вводили
 * с нуля), раунд навсегда оставался in_progress — updateMatchResult, в отличие
 * от saveMatchResult, не пересчитывал статус раунда. Все матчи completed,
 * pending нет, а кнопка «Завершить турнир» не появлялась.
 */
class RoundCompletionOnEditTest extends TestCase
{
    use RefreshDatabase;

    /** Американо: турнир, группа, раунд и один матч на 4 игроков. */
    private function makeAmericanoMatch(): AmericanoMatch
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

        return AmericanoMatch::create([
            'americano_round_id' => $round->id,
            'team1_player1_id' => $players[0]->id,
            'team1_player2_id' => $players[1]->id,
            'team2_player1_id' => $players[2]->id,
            'team2_player2_id' => $players[3]->id,
            'status' => 'pending',
        ]);
    }

    /** Мексикано: турнир, раунд и один матч на 4 игроков. */
    private function makeMexicanoMatch(): MexicanoMatch
    {
        $tournament = Tournament::factory()->create([
            'type' => 'mexicano',
            'status' => 'in_progress',
            'max_participants' => 4,
            'rounds_count' => 1,
        ]);

        $players = [];
        for ($i = 0; $i < 4; $i++) {
            $user = User::factory()->create(['rating' => 1500, 'name' => 'P' . ($i + 1)]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
            MexicanoPlayer::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'total_points' => 0,
                'rating_before' => 1500,
            ]);
            $players[] = $user;
        }

        $round = MexicanoRound::create([
            'tournament_id' => $tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);

        return MexicanoMatch::create([
            'mexicano_round_id' => $round->id,
            'court_number' => 1,
            'team1_player1_id' => $players[0]->id,
            'team1_player2_id' => $players[1]->id,
            'team2_player1_id' => $players[2]->id,
            'team2_player2_id' => $players[3]->id,
            'status' => 'pending',
        ]);
    }

    public function test_americano_editing_last_match_of_round_completes_round(): void
    {
        // Сценарий турнира 1051: в раунде несколько матчей, и последний
        // недоигранный закрывают правкой счёта (вбивают 0:0), а не вводом с нуля.
        $first = $this->makeAmericanoMatch();
        $round = $first->round;
        $group = $round->group;
        $players = $group->players()->get();

        $second = AmericanoMatch::create([
            'americano_round_id' => $round->id,
            'team1_player1_id' => $players[0]->id,
            'team1_player2_id' => $players[2]->id,
            'team2_player1_id' => $players[1]->id,
            'team2_player2_id' => $players[3]->id,
            'status' => 'pending',
        ]);

        $service = new AmericanoService();

        // Первый матч сыгран — раунд ещё открыт, второй матч не доигран.
        $service->saveMatchResult($first->fresh(), 21, 15);
        $this->assertSame('in_progress', $round->fresh()->status);

        // Второй закрывают правкой счёта.
        $service->updateMatchResult($second->fresh(), 0, 0);

        $this->assertSame('completed', $second->fresh()->status);
        $this->assertSame(
            'completed',
            $round->fresh()->status,
            'раунд закрывается, когда последний матч завершили правкой счёта'
        );
    }

    public function test_americano_editing_pending_match_completes_round(): void
    {
        $match = $this->makeAmericanoMatch();
        $service = new AmericanoService();

        // Счёт единственного матча раунда выставлен через правку, минуя первичный ввод.
        $service->updateMatchResult($match->fresh(), 0, 0);

        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame(
            'completed',
            $match->fresh()->round->status,
            'раунд закрывается, когда в нём не осталось несыгранных матчей'
        );
    }

    public function test_mexicano_editing_score_completes_round(): void
    {
        $match = $this->makeMexicanoMatch();
        $service = new MexicanoService();

        $service->updateMatchResult($match->fresh(), 0, 0);

        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame(
            'completed',
            $match->fresh()->round->status,
            'раунд мексикано закрывается после правки счёта последнего матча'
        );
    }
}

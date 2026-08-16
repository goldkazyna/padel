<?php

namespace Tests\Feature;

use App\Models\AmericanoMatch;
use App\Models\AmericanoRound;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Общая таблица в живом экране турнира.
 *
 * При трёх группах плей-офф строится по ней, а не по местам в группах —
 * значит игрок должен видеть тот же ряд, по которому его посеют.
 */
class AmericanoOverallTableApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tournament, 1: array<int, User>} */
    private function tournament(int $groupsCount): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);
        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano',
            'status' => 'in_progress',
            'groups_count' => $groupsCount,
            'max_participants' => $groupsCount * 4,
            'rounds_count' => 1,
        ]);

        $players = [];
        for ($i = 0; $i < $groupsCount * 4; $i++) {
            $players[] = User::factory()->create(['name' => 'И' . ($i + 1), 'rating' => 1500]);
        }

        // Очки убывают по индексу: общий ряд известен заранее.
        for ($g = 0; $g < $groupsCount; $g++) {
            $group = TournamentGroup::create([
                'tournament_id' => $tournament->id,
                'name' => 'Группа ' . chr(65 + $g),
            ]);
            for ($p = 0; $p < 4; $p++) {
                $index = $g * 4 + $p;
                $group->players()->attach($players[$index]->id, [
                    'total_points' => 100 - $index,
                    'rating_before' => 1500,
                    'rating_after' => null,
                ]);
            }
            $round = AmericanoRound::create([
                'tournament_group_id' => $group->id,
                'round_number' => 1,
                'status' => 'completed',
            ]);
            AmericanoMatch::create([
                'americano_round_id' => $round->id,
                'court_number' => $g + 1,
                'team1_player1_id' => $players[$g * 4]->id,
                'team1_player2_id' => $players[$g * 4 + 1]->id,
                'team2_player1_id' => $players[$g * 4 + 2]->id,
                'team2_player2_id' => $players[$g * 4 + 3]->id,
                'team1_score' => 21,
                'team2_score' => 15,
                'status' => 'completed',
            ]);
        }

        return [$tournament->fresh(), $players];
    }

    public function test_three_groups_get_an_overall_table(): void
    {
        [$t, $players] = $this->tournament(3);
        Sanctum::actingAs($players[0]);

        $overall = $this->getJson("/api/mobile/tournaments/{$t->id}/live")
            ->assertOk()
            ->json('overall');

        $this->assertCount(12, $overall, 'в общем ряду все игроки всех групп');
        $this->assertSame(1, $overall[0]['position']);
        $this->assertSame($players[0]->id, $overall[0]['id'], 'первым идёт игрок с наибольшими очками');
        $this->assertSame('Группа A', $overall[0]['group_name'], 'видно, из какой он группы');
    }

    public function test_overall_marks_where_each_place_leads(): void
    {
        [$t, $players] = $this->tournament(3);
        Sanctum::actingAs($players[0]);

        $overall = collect($this->getJson("/api/mobile/tournaments/{$t->id}/live")->json('overall'))
            ->keyBy('position');

        $this->assertSame('semifinal', $overall[1]['playoff_slot'], 'топ-4 ждут в полуфинале');
        $this->assertSame('semifinal', $overall[4]['playoff_slot']);
        $this->assertSame('quarterfinal', $overall[5]['playoff_slot'], 'с пятого — четвертьфинал');
        $this->assertSame('quarterfinal', $overall[12]['playoff_slot']);
    }

    public function test_two_groups_have_no_overall_table(): void
    {
        [$t, $players] = $this->tournament(2);
        Sanctum::actingAs($players[0]);

        $this->assertSame(
            [],
            $this->getJson("/api/mobile/tournaments/{$t->id}/live")->assertOk()->json('overall'),
            'при двух группах плей-офф идёт по местам в группах, общий ряд не нужен'
        );
    }
}

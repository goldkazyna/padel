<?php

namespace Tests\Unit\Services;

use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\TournamentParticipant;
use App\Models\TournamentPlayoffMatch;
use App\Models\User;
use App\Services\AmericanoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmericanoPlayoffTest extends TestCase
{
    use RefreshDatabase;

    private AmericanoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AmericanoService();
    }

    private function makeFinishedSingleGroup(int $players, array $playoffAttrs): Tournament
    {
        $tournament = Tournament::factory()->create(array_merge([
            'type' => 'americano',
            'status' => 'in_progress',
            'groups_count' => 1,
            'max_participants' => $players,
            'rounds_count' => 1,
            'has_playoff' => true,
        ], $playoffAttrs));

        $group = TournamentGroup::create([
            'tournament_id' => $tournament->id,
            'name' => 'Группа A',
        ]);

        for ($i = 0; $i < $players; $i++) {
            $user = User::factory()->create(['rating' => 1500, 'name' => 'P' . ($i + 1)]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
            $group->players()->attach($user->id, [
                'total_points' => ($players - $i) * 10,
                'rating_before' => 1500,
                'rating_after' => null,
            ]);
        }

        return $tournament->fresh();
    }

    private function getSeed(Tournament $t, int $position): User
    {
        $group = $t->groups()->first();
        $ordered = $group->players()->orderByPivot('total_points', 'desc')->get();
        return $ordered[$position - 1];
    }

    public function test_single_group_semifinal_creates_two_semis_and_empty_final(): void
    {
        $t = $this->makeFinishedSingleGroup(8, [
            'playoff_type' => 'semifinal_final',
            'playoff_format' => 'mix',
        ]);

        $ok = $this->service->generatePlayoff($t);

        $this->assertTrue($ok);
        $semis = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Полуфинал')->where('bracket', 'upper')->get();
        $final = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Финал')->where('bracket', 'upper')->get();

        $this->assertCount(2, $semis, 'должно быть 2 полуфинала');
        $this->assertCount(1, $final, 'должен быть 1 финал');
        $this->assertNull($final->first()->team1_player1_id, 'финал пустой до полуфиналов');
    }

    public function test_single_group_semifinal_mix_seeding(): void
    {
        $t = $this->makeFinishedSingleGroup(8, [
            'playoff_type' => 'semifinal_final', 'playoff_format' => 'mix',
        ]);
        $this->service->generatePlayoff($t);

        $semi1 = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Полуфинал')->where('match_number', 1)->first();

        $this->assertEqualsCanonicalizing(
            [$this->getSeed($t, 1)->id, $this->getSeed($t, 8)->id],
            [$semi1->team1_player1_id, $semi1->team1_player2_id]
        );
        $this->assertEqualsCanonicalizing(
            [$this->getSeed($t, 4)->id, $this->getSeed($t, 5)->id],
            [$semi1->team2_player1_id, $semi1->team2_player2_id]
        );
    }

    public function test_single_group_final_lower_bracket_uses_places_5_to_8(): void
    {
        $t = $this->makeFinishedSingleGroup(8, [
            'playoff_type' => 'final_only', 'playoff_format' => 'cross',
            'has_lower_bracket' => true,
        ]);
        $this->service->generatePlayoff($t);

        $lowerFinal = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Финал')->where('bracket', 'lower')->first();
        $this->assertNotNull($lowerFinal, 'нижний финал создан');

        $lowerIds = [$lowerFinal->team1_player1_id, $lowerFinal->team1_player2_id,
                     $lowerFinal->team2_player1_id, $lowerFinal->team2_player2_id];
        $expected = [$this->getSeed($t,5)->id, $this->getSeed($t,6)->id,
                     $this->getSeed($t,7)->id, $this->getSeed($t,8)->id];
        $this->assertEqualsCanonicalizing($expected, $lowerIds);
    }

    public function test_lower_bracket_skipped_when_not_enough_players(): void
    {
        $t = $this->makeFinishedSingleGroup(6, [
            'playoff_type' => 'final_only', 'playoff_format' => 'cross',
            'has_lower_bracket' => true,
        ]);
        $this->service->generatePlayoff($t);

        $lower = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('bracket', 'lower')->count();
        $this->assertEquals(0, $lower, 'нижняя сетка не строится при нехватке игроков');
    }

    public function test_semifinal_updates_only_its_own_bracket_final(): void
    {
        $t = $this->makeFinishedSingleGroup(16, [
            'playoff_type' => 'semifinal_final', 'playoff_format' => 'mix',
            'has_lower_bracket' => true,
        ]);
        $this->service->generatePlayoff($t);

        $upperSemis = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Полуфинал')->where('bracket', 'upper')->get();
        foreach ($upperSemis as $s) {
            $s->update(['team1_score' => 6, 'team2_score' => 3, 'status' => 'completed']);
            $this->service->updateFinalAfterSemifinal($s->fresh());
        }

        $upperFinal = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Финал')->where('bracket', 'upper')->first();
        $lowerFinal = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Финал')->where('bracket', 'lower')->first();

        $this->assertNotNull($upperFinal->team1_player1_id, 'верхний финал заполнен');
        $this->assertNull($lowerFinal->team1_player1_id, 'нижний финал ещё пуст');
    }

    public function test_can_finish_requires_only_upper_final(): void
    {
        $t = $this->makeFinishedSingleGroup(8, [
            'playoff_type' => 'final_only', 'playoff_format' => 'cross',
            'has_lower_bracket' => true,
        ]);
        $this->service->generatePlayoff($t);

        $upperFinal = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Финал')->where('bracket', 'upper')->first();
        $upperFinal->update(['team1_score' => 6, 'team2_score' => 2, 'status' => 'completed']);

        $this->assertTrue($this->service->canFinishTournament($t->fresh()));
    }
}

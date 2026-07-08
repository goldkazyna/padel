<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\TeamTournamentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Новый формат плей-офф group+playoff (team): playoff_format = 'winners_final'.
 *
 * 2 группы × 2 advance: победители групп (A1/B1) сразу играют ФИНАЛ,
 * а вторые места групп (A2/B2) играют матч за 3-е место — без полуфиналов.
 */
class TeamWinnersFinalPlayoffTest extends TestCase
{
    use RefreshDatabase;

    private TeamTournamentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TeamTournamentService();
    }

    /**
     * Собирает турнир: 2 группы по 2 команды, playoff_format = winners_final.
     * Группа A: teamA1 обыгрывает teamA2 (winner = teamA1, runner-up = teamA2).
     * Группа B: teamB1 обыгрывает teamB2 (winner = teamB1, runner-up = teamB2).
     *
     * @return array{0: Tournament, 1: TournamentTeam, 2: TournamentTeam, 3: TournamentTeam, 4: TournamentTeam}
     *   [$tournament, $teamA1(winner), $teamA2(runner-up), $teamB1(winner), $teamB2(runner-up)]
     */
    private function makeCompletedGroupStage(): array
    {
        $tournament = Tournament::factory()->create([
            'type' => 'team',
            'status' => 'in_progress',
            'groups_count' => 2,
            'teams_advance' => 2,
            'max_participants' => 8,
            'playoff_format' => 'winners_final',
            'has_playoff' => true,
        ]);

        $teams = [];
        foreach (['A1', 'A2', 'B1', 'B2'] as $label) {
            $p1 = User::factory()->create(['rating' => 1500]);
            $p2 = User::factory()->create(['rating' => 1500]);
            $teams[$label] = TournamentTeam::create([
                'tournament_id' => $tournament->id,
                'player1_id' => $p1->id,
                'player2_id' => $p2->id,
                'status' => 'approved',
                'rating_avg' => 1500,
            ]);
        }

        $assignments = [
            $teams['A1']->id => 0,
            $teams['A2']->id => 0,
            $teams['B1']->id => 1,
            $teams['B2']->id => 1,
        ];

        [$ok, $msg] = $this->service->startTournamentWithAssignments($tournament, $assignments);
        $this->assertTrue($ok, $msg);

        $tournament->refresh();

        // Группа A: A1 обыгрывает A2 (единственный матч группы, т.к. 2 команды).
        $groupA = $tournament->teamGroups()->orderBy('name')->get()[0];
        $groupB = $tournament->teamGroups()->orderBy('name')->get()[1];

        // Кто именно team1/team2 в матче — не важно: победитель/проигравший
        // группы определяются по фактическим standings после матча
        // (см. getGroupWinnerAndRunnerUpIds), а не по меткам A1/A2/B1/B2.
        $matchA = $groupA->matches()->first();
        $this->service->saveGroupMatchResult($matchA, 21, 10);

        $matchB = $groupB->matches()->first();
        $this->service->saveGroupMatchResult($matchB, 21, 10);

        return [$tournament, $teams['A1'], $teams['A2'], $teams['B1'], $teams['B2']];
    }

    public function test_generate_playoff_creates_final_and_bronze_with_correct_teams(): void
    {
        [$tournament, $teamA1, $teamA2, $teamB1, $teamB2] = $this->makeCompletedGroupStage();

        $tournament->refresh();
        $ok = $this->service->generatePlayoff($tournament);
        $this->assertTrue($ok);

        $matches = $tournament->playoffMatches()->get();
        $this->assertCount(2, $matches, 'должно быть ровно 2 матча плей-офф: финал и матч за 3-е место');

        $noSemi = $matches->where('stage', 'semi')->count();
        $this->assertSame(0, $noSemi, 'в формате winners_final не должно быть полуфиналов');

        $final = $matches->firstWhere('is_bronze', false);
        $bronze = $matches->firstWhere('is_bronze', true);

        $this->assertNotNull($final);
        $this->assertNotNull($bronze);
        $this->assertSame('final', $final->stage);
        $this->assertSame('final', $bronze->stage);

        // Определяем реальных победителей групп (по standings, а не по фикс. предположению порядка team1/team2).
        $groupWinnerIds = $this->getGroupWinnerAndRunnerUpIds($tournament);

        $finalTeamIds = collect([$final->team1_id, $final->team2_id])->sort()->values()->all();
        $expectedWinners = collect($groupWinnerIds['winners'])->sort()->values()->all();
        $this->assertSame($expectedWinners, $finalTeamIds, 'в финале должны играть победители групп');

        $bronzeTeamIds = collect([$bronze->team1_id, $bronze->team2_id])->sort()->values()->all();
        $expectedRunnersUp = collect($groupWinnerIds['runners_up'])->sort()->values()->all();
        $this->assertSame($expectedRunnersUp, $bronzeTeamIds, 'в матче за 3-е место должны играть вторые места групп');

        $this->assertSame('in_progress', $final->status);
        $this->assertSame('in_progress', $bronze->status);
    }

    public function test_places_after_playing_final_and_bronze(): void
    {
        [$tournament] = $this->makeCompletedGroupStage();

        $tournament->refresh();
        $this->service->generatePlayoff($tournament);

        $matches = $tournament->playoffMatches()->get();
        $final = $matches->firstWhere('is_bronze', false);
        $bronze = $matches->firstWhere('is_bronze', true);

        // Финал: team1 побеждает.
        $this->service->savePlayoffMatchResult($final, 21, 15);
        // Бронза: team1 побеждает.
        $this->service->savePlayoffMatchResult($bronze, 21, 18);

        $final->refresh();
        $bronze->refresh();

        $finalWinnerId = $final->winner_id;
        $finalLoserId = $finalWinnerId === $final->team1_id ? $final->team2_id : $final->team1_id;
        $bronzeWinnerId = $bronze->winner_id;
        $bronzeLoserId = $bronzeWinnerId === $bronze->team1_id ? $bronze->team2_id : $bronze->team1_id;

        $place = $this->invokeGetUserPlace($tournament, $finalWinnerId);
        $this->assertSame(1, $place, 'победитель финала — 1 место');

        $place = $this->invokeGetUserPlace($tournament, $finalLoserId);
        $this->assertSame(2, $place, 'проигравший финала — 2 место');

        $place = $this->invokeGetUserPlace($tournament, $bronzeWinnerId);
        $this->assertSame(3, $place, 'победитель матча за 3-е место — 3 место');

        $place = $this->invokeGetUserPlace($tournament, $bronzeLoserId);
        $this->assertSame(4, $place, 'проигравший матча за 3-е место — 4 место');
    }

    /**
     * Победители/вторые места групп по фактическим standings (после завершения группового этапа).
     * @return array{winners: array<int>, runners_up: array<int>}
     */
    private function getGroupWinnerAndRunnerUpIds(Tournament $tournament): array
    {
        $winners = [];
        $runnersUp = [];
        foreach ($tournament->teamGroups as $group) {
            $sorted = $this->service->getSortedStandings($group);
            $winners[] = $sorted[0]['team_id'];
            $runnersUp[] = $sorted[1]['team_id'];
        }
        return ['winners' => $winners, 'runners_up' => $runnersUp];
    }

    /**
     * getUserPlace() в MobileTournamentController приватный — используем reflection,
     * чтобы не поднимать HTTP-стек. team_id -> берём id одного из игроков команды.
     */
    private function invokeGetUserPlace(Tournament $tournament, int $teamId): ?int
    {
        $team = TournamentTeam::find($teamId);
        $userId = (int) $team->player1_id;

        $controller = app(\App\Http\Controllers\Api\MobileTournamentController::class);
        $ref = new \ReflectionMethod($controller, 'getUserPlace');
        $ref->setAccessible(true);

        return $ref->invoke($controller, $tournament->fresh(), $userId);
    }
}

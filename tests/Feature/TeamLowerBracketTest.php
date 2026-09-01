<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\TeamTournamentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Нижняя (утешительная) сетка парного турнира.
 *
 * Раньше она строилась только у формата на 3 группы: галочку «нижняя сетка»
 * ставили при 2 группах, и она молча ничего не делала — те, кто не вышел из
 * группы, доигрывали ноль матчей.
 */
class TeamLowerBracketTest extends TestCase
{
    use RefreshDatabase;

    private TeamTournamentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TeamTournamentService();
    }

    /**
     * 2 группы по 4 пары, 2 выходят наверх. В каждой группе счёт подобран так,
     * что порядок мест совпадает с порядком создания команд.
     */
    private function makeTournament(array $extra = []): Tournament
    {
        $tournament = Tournament::factory()->create(array_merge([
            'type' => 'team',
            'status' => 'in_progress',
            'groups_count' => 2,
            'teams_advance' => 2,
            'max_participants' => 16,
            'has_playoff' => true,
            'has_lower_bracket' => true,
            'has_bronze_match' => true,
        ], $extra));

        $assignments = [];
        foreach (range(1, 8) as $i) {
            $team = TournamentTeam::create([
                'tournament_id' => $tournament->id,
                'player1_id' => User::factory()->create(['rating' => 1500])->id,
                'player2_id' => User::factory()->create(['rating' => 1500])->id,
                'status' => 'approved',
                'rating_avg' => 1500,
            ]);
            // 1-4 в группу A, 5-8 в группу B.
            $assignments[$team->id] = $i <= 4 ? 0 : 1;
        }

        [$ok, $msg] = $this->service->startTournamentWithAssignments($tournament, $assignments);
        $this->assertTrue($ok, $msg);

        // Доигрываем группы: в каждом матче побеждает команда, созданная раньше.
        foreach ($tournament->refresh()->teamGroups as $group) {
            foreach ($group->matches as $match) {
                $first = min($match->team1_id, $match->team2_id);
                $match->refresh();
                $this->service->saveGroupMatchResult(
                    $match,
                    $match->team1_id === $first ? 21 : 10,
                    $match->team1_id === $first ? 10 : 21
                );
            }
        }

        return $tournament->refresh();
    }

    /** Кто в какой сетке оказался: [нижние команды, верхние команды] */
    private function bracketTeams(Tournament $tournament, string $bracket): array
    {
        return $tournament->playoffMatches()
            ->where('bracket', $bracket)
            ->get()
            ->flatMap(fn ($m) => [$m->team1_id, $m->team2_id])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function test_нижняя_сетка_строится_при_двух_группах(): void
    {
        $tournament = $this->makeTournament();

        $this->assertTrue($this->service->generatePlayoff($tournament));
        $tournament->refresh();

        $lower = $tournament->playoffMatches()->where('bracket', 'lower')->get();

        $this->assertCount(2, $lower->where('stage', 'semi'), 'два полуфинала нижней сетки');
        $this->assertCount(1, $lower->where('stage', 'final')->where('is_bronze', false), 'финал за 5-е место');
        $this->assertCount(1, $lower->where('is_bronze', true), 'матч за 7-е место');

        // Номера нижней сетки не пересекаются с верхней: по ним ищется соперник.
        $this->assertTrue($lower->every(fn ($m) => $m->match_number > 100));
    }

    public function test_в_нижнюю_попадают_те_кто_не_вышел_из_группы(): void
    {
        $tournament = $this->makeTournament();
        $this->service->generatePlayoff($tournament);
        $tournament->refresh();

        $upper = $this->bracketTeams($tournament, 'upper');
        $lower = $this->bracketTeams($tournament, 'lower');

        $this->assertCount(4, $upper);
        $this->assertCount(4, $lower);
        $this->assertEmpty(array_intersect($upper, $lower), 'команда не может играть в обеих сетках');
    }

    public function test_победитель_нижнего_полуфинала_идёт_в_свой_финал(): void
    {
        $tournament = $this->makeTournament();
        $this->service->generatePlayoff($tournament);
        $tournament->refresh();

        $semis = $tournament->playoffMatches()
            ->where('bracket', 'lower')->where('stage', 'semi')->orderBy('match_number')->get();

        $this->service->savePlayoffMatchResult($semis[0], 21, 15);
        $winner = $semis[0]->refresh()->winner_id;

        $final = $tournament->playoffMatches()
            ->where('bracket', 'lower')->where('stage', 'final')->where('is_bronze', false)->first();

        $this->assertSame($winner, $final->team1_id, 'победитель нижнего ПФ встал в нижний финал');

        // Верхний финал победитель нижней сетки не трогает.
        $upperFinal = $tournament->playoffMatches()
            ->where('bracket', 'upper')->where('stage', 'final')->where('is_bronze', false)->first();
        $this->assertNotSame($winner, $upperFinal->team1_id);
    }

    public function test_без_галочки_нижней_сетки_нет(): void
    {
        $tournament = $this->makeTournament(['has_lower_bracket' => false]);
        $this->service->generatePlayoff($tournament);

        $this->assertSame(0, $tournament->refresh()->playoffMatches()->where('bracket', 'lower')->count());
    }

    public function test_нижнюю_сетку_можно_достроить_старому_турниру(): void
    {
        // Турниры, созданные до правки: плей-офф есть, нижней сетки нет.
        $tournament = $this->makeTournament(['has_lower_bracket' => false]);
        $this->service->generatePlayoff($tournament);
        $tournament->update(['has_lower_bracket' => true]);

        $this->assertTrue($this->service->createLowerBracket($tournament->refresh()));
        $this->assertCount(4, $this->bracketTeams($tournament->refresh(), 'lower'));

        // Второй раз — не дублируем.
        $this->assertFalse($this->service->createLowerBracket($tournament->refresh()));
    }
}

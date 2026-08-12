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

/**
 * Формат плей-оффа «Верх/низ»: сильные одной группы играют против слабых
 * другой. Полуфинал 1 — A1+B3 против A2+B4, полуфинал 2 — A3+B1 против A4+B2.
 */
class AmericanoPlayoffTopBottomTest extends TestCase
{
    use RefreshDatabase;

    private AmericanoService $service;

    /** @var array<string, array<int, User>> имя группы → игроки по местам (1-based смещён на 0) */
    private array $seeds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AmericanoService();
    }

    private function makeTwoGroups(array $playoffAttrs): Tournament
    {
        $tournament = Tournament::factory()->create(array_merge([
            'type' => 'americano',
            'status' => 'in_progress',
            'groups_count' => 2,
            'max_participants' => 8,
            'rounds_count' => 1,
            'has_playoff' => true,
        ], $playoffAttrs));

        foreach (['Группа A' => 'A', 'Группа B' => 'B'] as $groupName => $letter) {
            $group = TournamentGroup::create([
                'tournament_id' => $tournament->id,
                'name' => $groupName,
            ]);

            for ($place = 1; $place <= 4; $place++) {
                $user = User::factory()->create([
                    'rating' => 1500,
                    'name' => $letter . $place,
                ]);
                TournamentParticipant::create([
                    'tournament_id' => $tournament->id,
                    'user_id' => $user->id,
                    'status' => 'registered',
                ]);
                // Очки убывают с местом — по ним и строится рейтинг группы.
                $group->players()->attach($user->id, [
                    'total_points' => (5 - $place) * 10,
                    'rating_before' => 1500,
                    'rating_after' => null,
                ]);
                $this->seeds[$letter][$place] = $user;
            }
        }

        return $tournament->fresh();
    }

    /** id игрока по метке вида «A1», «B3». */
    private function id(string $label): int
    {
        return $this->seeds[$label[0]][(int) $label[1]]->id;
    }

    /** @return array<int, int> пара id обеих команд матча, каждая отсортирована */
    private function teams(TournamentPlayoffMatch $match): array
    {
        $team1 = [$match->team1_player1_id, $match->team1_player2_id];
        $team2 = [$match->team2_player1_id, $match->team2_player2_id];
        sort($team1);
        sort($team2);

        return [$team1, $team2];
    }

    /** @param array<int, string> $expected метки вида ['A1','B3'] */
    private function assertTeam(array $actual, array $expected, string $message): void
    {
        $want = array_map(fn ($label) => $this->id($label), $expected);
        sort($want);

        $this->assertSame($want, $actual, $message);
    }

    public function test_semifinals_pair_top_of_one_group_with_bottom_of_other(): void
    {
        $tournament = $this->makeTwoGroups([
            'playoff_type' => 'semifinal_final',
            'playoff_format' => 'top_bottom',
        ]);

        $this->assertTrue($this->service->generatePlayoff($tournament));

        $semis = $tournament->playoffMatches()
            ->where('stage', 'Полуфинал')
            ->orderBy('match_number')
            ->get();

        $this->assertCount(2, $semis);

        [$first1, $first2] = $this->teams($semis[0]);
        $this->assertTeam($first1, ['A1', 'B3'], 'полуфинал 1, команда 1');
        $this->assertTeam($first2, ['A2', 'B4'], 'полуфинал 1, команда 2');

        [$second1, $second2] = $this->teams($semis[1]);
        $this->assertTeam($second1, ['A3', 'B1'], 'полуфинал 2, команда 1');
        $this->assertTeam($second2, ['A4', 'B2'], 'полуфинал 2, команда 2');
    }

    public function test_bronze_match_is_created_when_enabled(): void
    {
        $tournament = $this->makeTwoGroups([
            'playoff_type' => 'semifinal_final',
            'playoff_format' => 'top_bottom',
            'has_bronze_match' => true,
        ]);

        $this->service->generatePlayoff($tournament);

        $bronze = $tournament->playoffMatches()->where('is_bronze', true)->get();

        $this->assertCount(1, $bronze, 'при включённом флаге должен создаться матч за 3-е место');
    }

    public function test_format_is_listed_for_the_form(): void
    {
        $this->assertArrayHasKey('top_bottom', Tournament::playoffFormats());
    }
}

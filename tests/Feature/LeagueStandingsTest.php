<?php

namespace Tests\Feature;

use App\Models\AmericanoFlexMatch;
use App\Models\AmericanoFlexPlayer;
use App\Models\AmericanoFlexRound;
use App\Models\Club;
use App\Models\League;
use App\Models\LeaguePlayer;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LeagueStandings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сводная таблица лиги: складываем очки за все этапы.
 *
 * Пропуск этапа стоит игроку очков — ходить на этапы часть соревнования.
 * Замена, сыгравшая один этап, попадает в таблицу со своими очками.
 */
class LeagueStandingsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private League $league;

    /** @var array<string, User> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $this->league = League::create([
            'club_id' => $this->club->id,
            'name' => 'Сентябрь Кап',
            'status' => 'in_progress',
            'stages_planned' => 8,
        ]);

        foreach (['A' => 2000, 'B' => 1900, 'C' => 1800, 'D' => 1700, 'E' => 1600] as $key => $rating) {
            $this->players[$key] = User::factory()->create([
                'role' => 'player', 'name' => "Игрок {$key}", 'rating' => $rating, 'level' => 3.0,
            ]);
            LeaguePlayer::create([
                'league_id' => $this->league->id,
                'user_id' => $this->players[$key]->id,
                'status' => 'registered',
                'joined_at' => now(),
            ]);
        }
    }

    /** Этап лиги — обычный Americano Flex. */
    private function stage(int $number, string $status = 'completed'): Tournament
    {
        return Tournament::create([
            'club_id' => $this->club->id,
            'league_id' => $this->league->id,
            'league_stage' => $number,
            'name' => "Этап {$number}",
            'type' => 'americano_flex',
            'status' => $status,
            'start_date' => '2026-09-0' . $number . ' 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);
    }

    /**
     * Матч этапа: пара ключей игроков против пары, со счётом.
     *
     * @param array{0: string, 1: string} $team1
     * @param array{0: string, 1: string} $team2
     */
    private function match(Tournament $stage, array $team1, array $team2, int $score1, int $score2): void
    {
        $round = AmericanoFlexRound::firstOrCreate([
            'tournament_id' => $stage->id,
            'round_number' => 1,
        ], ['status' => 'completed']);

        foreach (array_merge($team1, $team2) as $key) {
            AmericanoFlexPlayer::firstOrCreate([
                'tournament_id' => $stage->id,
                'user_id' => $this->players[$key]->id,
            ]);
        }

        AmericanoFlexMatch::create([
            'americano_flex_round_id' => $round->id,
            'court_number' => 1,
            'team1_player1_id' => $this->players[$team1[0]]->id,
            'team1_player2_id' => $this->players[$team1[1]]->id,
            'team2_player1_id' => $this->players[$team2[0]]->id,
            'team2_player2_id' => $this->players[$team2[1]]->id,
            'team1_score' => $score1,
            'team2_score' => $score2,
            'status' => 'completed',
        ]);
    }

    private function pointsOf(array $rows, string $key): int
    {
        $id = $this->players[$key]->id;
        foreach ($rows as $row) {
            if ($row['id'] === $id) return $row['points_for'];
        }

        return 0;
    }

    public function test_очки_складываются_по_всем_этапам(): void
    {
        $first = $this->stage(1);
        $this->match($first, ['A', 'B'], ['C', 'D'], 21, 12);

        $second = $this->stage(2);
        $this->match($second, ['A', 'C'], ['B', 'D'], 15, 18);

        $rows = LeagueStandings::build($this->league);

        $this->assertSame(36, $this->pointsOf($rows, 'A'), '21 на первом этапе + 15 на втором');
        $this->assertSame(39, $this->pointsOf($rows, 'B'), '21 + 18');
        $this->assertSame(27, $this->pointsOf($rows, 'C'), '12 + 15');
    }

    public function test_первым_идёт_набравший_больше_очков(): void
    {
        $stage = $this->stage(1);
        $this->match($stage, ['A', 'B'], ['C', 'D'], 21, 9);

        $rows = LeagueStandings::build($this->league);

        $this->assertSame('Игрок A', $rows[0]['name']);
        $this->assertSame(1, $rows[0]['position']);
        $this->assertSame(21, $rows[0]['points_for']);
    }

    public function test_пропуск_этапа_стоит_очков(): void
    {
        // A играет оба этапа, C только первый — при равной игре A впереди.
        $first = $this->stage(1);
        $this->match($first, ['A', 'C'], ['B', 'D'], 15, 15);

        $second = $this->stage(2);
        $this->match($second, ['A', 'B'], ['D', 'E'], 15, 15);

        $rows = LeagueStandings::build($this->league);

        $this->assertSame(30, $this->pointsOf($rows, 'A'));
        $this->assertSame(15, $this->pointsOf($rows, 'C'));
        $this->assertSame('Игрок A', $rows[0]['name'], 'кто ходил на этапы — тот выше');
    }

    public function test_незавершённый_этап_в_зачёт_не_идёт(): void
    {
        $done = $this->stage(1);
        $this->match($done, ['A', 'B'], ['C', 'D'], 20, 10);

        $running = $this->stage(2, 'in_progress');
        $this->match($running, ['A', 'B'], ['C', 'D'], 21, 0);

        $rows = LeagueStandings::build($this->league);

        $this->assertSame(20, $this->pointsOf($rows, 'A'), 'идущий этап не считаем — место скакало бы во время игры');
    }

    public function test_замена_попадает_в_таблицу_со_своими_очками(): void
    {
        // E в составе лиги есть, но выходит только на второй этап вместо C.
        $first = $this->stage(1);
        $this->match($first, ['A', 'C'], ['B', 'D'], 18, 12);

        $second = $this->stage(2);
        $this->match($second, ['A', 'E'], ['B', 'D'], 20, 10);

        $rows = LeagueStandings::build($this->league);
        $substitute = collect($rows)->firstWhere('id', $this->players['E']->id);

        $this->assertNotNull($substitute, 'сыграл — значит в таблице');
        $this->assertSame(20, $substitute['points_for']);
        $this->assertSame(1, $substitute['stages'], 'видно, что этап всего один');
    }

    public function test_при_равных_очках_решает_процент_побед(): void
    {
        // У C и D одинаковая сумма, но C выиграл свой матч, а D нет.
        $stage = $this->stage(1);
        $this->match($stage, ['C', 'A'], ['D', 'B'], 15, 15);
        $this->match($stage, ['C', 'B'], ['A', 'E'], 20, 10);
        $this->match($stage, ['D', 'E'], ['A', 'B'], 10, 20);

        $rows = LeagueStandings::build($this->league);
        $c = collect($rows)->firstWhere('id', $this->players['C']->id);
        $d = collect($rows)->firstWhere('id', $this->players['D']->id);

        $this->assertSame(35, $c['points_for']);
        $this->assertSame(25, $d['points_for']);
        $this->assertLessThan($d['position'], $c['position']);
    }

    public function test_матчи_0_0_не_считаются_сыгранными(): void
    {
        $stage = $this->stage(1);
        $this->match($stage, ['A', 'B'], ['C', 'D'], 0, 0);

        $rows = LeagueStandings::build($this->league);

        $this->assertSame([], $rows, '0:0 — отметка «не играли», а не результат');
    }

    public function test_лига_без_сыгранных_этапов_даёт_пустую_таблицу(): void
    {
        $this->stage(1, 'open');

        $this->assertSame([], LeagueStandings::build($this->league));
    }

    public function test_сводка_показывает_прогресс_лиги(): void
    {
        $this->stage(1);
        $this->stage(2);
        $this->stage(3, 'open');

        $summary = LeagueStandings::summary($this->league);

        $this->assertSame(8, $summary['stages_total'], 'запланировано восемь');
        $this->assertSame(2, $summary['stages_done']);
        $this->assertSame(5, $summary['players']);
        $this->assertSame(3, (int) $summary['next_stage']->league_stage, 'следующий — ближайший несыгранный');
    }
}

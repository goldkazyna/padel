<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\TeamTournamentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Групповой турнир с ручным сбором пар (pairing_mode=admin):
 * игроки регистрируются поодиночке, админ собирает пары, затем обычный старт.
 */
class TeamAdminPairingTest extends TestCase
{
    use RefreshDatabase;

    private TeamTournamentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TeamTournamentService();
    }

    /**
     * @return array{0: Tournament, 1: array<int,User>}
     */
    private function makeTournament(int $players = 8, int $groups = 2): array
    {
        $tournament = Tournament::factory()->create([
            'type' => 'team',
            'pairing_mode' => 'admin',
            'status' => 'open',
            'groups_count' => $groups,
            'teams_advance' => 2,
            'max_participants' => $players,
        ]);

        $users = [];
        for ($i = 0; $i < $players; $i++) {
            $user = User::factory()->create(['rating' => 1500 + $i * 25]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
            $users[] = $user;
        }

        return [$tournament, $users];
    }

    public function test_pairing_state_lists_unpaired_players(): void
    {
        [$tournament] = $this->makeTournament(8, 2);

        $state = $this->service->getPairingState($tournament);

        $this->assertSame(4, $state['max_pairs']);
        $this->assertSame(0, $state['pairs_count']);
        $this->assertCount(8, $state['unpaired']);
        $this->assertFalse($state['can_start']);
    }

    public function test_create_and_delete_pair(): void
    {
        [$tournament, $users] = $this->makeTournament(8, 2);

        [$ok, $msg] = $this->service->createPair($tournament, $users[0]->id, $users[1]->id);
        $this->assertTrue($ok, $msg);

        $this->assertSame(1, $tournament->teams()->count());
        $team = $tournament->teams()->first();
        // rating_avg = среднее двух рейтингов
        $expected = (int) round(($users[0]->rating + $users[1]->rating) / 2);
        $this->assertSame($expected, (int) $team->rating_avg);

        // тот же игрок повторно — нельзя
        [$ok2, ] = $this->service->createPair($tournament, $users[0]->id, $users[2]->id);
        $this->assertFalse($ok2, 'игрок уже в паре');

        // удаляем — снова свободен
        [$okDel, ] = $this->service->deletePair($tournament, $team);
        $this->assertTrue($okDel);
        $this->assertSame(0, $tournament->teams()->count());
    }

    public function test_auto_balance_pairs_by_rating(): void
    {
        [$tournament, $users] = $this->makeTournament(8, 2);

        [$ok, $msg] = $this->service->autoBalancePairs($tournament);
        $this->assertTrue($ok, $msg);

        // 8 игроков → 4 пары
        $this->assertSame(4, $tournament->teams()->count());

        // Баланс: сильнейший(1700) + слабейший(1500) и т.д. — средние пар близки.
        $avgs = $tournament->teams()->pluck('rating_avg')->map(fn($v) => (int) $v)->sort()->values();
        $this->assertLessThanOrEqual(40, $avgs->last() - $avgs->first(),
            'средние рейтинги пар должны быть близки при балансе');

        $state = $this->service->getPairingState($tournament);
        $this->assertTrue($state['can_start']);
        $this->assertCount(0, $state['unpaired']);
    }

    public function test_full_flow_pair_then_start_creates_groups(): void
    {
        [$tournament, $users] = $this->makeTournament(8, 2);

        $this->service->autoBalancePairs($tournament);

        $ok = $this->service->startTournament($tournament->fresh());
        $this->assertTrue($ok, 'старт после сбора пар');

        $this->assertSame('in_progress', $tournament->fresh()->status);
        $this->assertSame(2, $tournament->teamGroups()->count());
        // Матчи группового этапа созданы
        $matchCount = \App\Models\TournamentGroupMatch::whereHas('group',
            fn($q) => $q->where('tournament_id', $tournament->id))->count();
        $this->assertGreaterThan(0, $matchCount);
    }
}

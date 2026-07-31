<?php

namespace Tests\Unit\Services;

use App\Models\MexicanoMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\MexicanoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MexicanoFirstRoundSeedingTest extends TestCase
{
    use RefreshDatabase;

    private MexicanoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MexicanoService();
    }

    /** @return array<int,User> ключ = рейтинг */
    private function makeTournament(array $ratings): array
    {
        $tournament = Tournament::factory()->create([
            'type' => 'mexicano',
            'status' => 'open',
            'max_participants' => count($ratings),
            'rounds_count' => 1,
        ]);

        $byRating = [];
        foreach ($ratings as $i => $rating) {
            $user = User::factory()->create(['rating' => $rating, 'name' => 'P' . ($i + 1)]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
            $byRating[$rating] = $user;
        }

        return [$tournament->fresh(), $byRating];
    }

    public function test_first_round_seeded_by_rating_top_players_on_court_one(): void
    {
        // ранги 1..8 = 2000..1300
        $ratings = [2000, 1900, 1800, 1700, 1600, 1500, 1400, 1300];
        [$tournament, $u] = $this->makeTournament($ratings);

        $this->assertTrue($this->service->startTournament($tournament->fresh()));

        $round = $tournament->fresh()->mexicanoRounds()->where('round_number', 1)->firstOrFail();
        $matches = MexicanoMatch::where('mexicano_round_id', $round->id)
            ->orderBy('court_number')->get();

        $this->assertCount(2, $matches);

        // Корт 1 — четыре сильнейших (2000,1900,1800,1700); пары 1+4 vs 2+3.
        $c1 = $matches[0];
        $team1 = [$c1->team1_player1_id, $c1->team1_player2_id];
        $team2 = [$c1->team2_player1_id, $c1->team2_player2_id];
        $court1Ids = array_merge($team1, $team2);
        sort($court1Ids);
        $expectedCourt1 = collect([2000, 1900, 1800, 1700])->map(fn ($r) => $u[$r]->id)->sort()->values()->all();
        $this->assertSame($expectedCourt1, $court1Ids, 'Корт 1 — четыре сильнейших по рейтингу');

        // Пары: сильнейший(2000) + слабейший из четвёрки(1700) против 2-го(1900)+3-го(1800).
        $this->assertEqualsCanonicalizing([$u[2000]->id, $u[1700]->id], $team1);
        $this->assertEqualsCanonicalizing([$u[1900]->id, $u[1800]->id], $team2);

        // Корт 2 — 1600,1500,1400,1300.
        $court2Ids = [$matches[1]->team1_player1_id, $matches[1]->team1_player2_id, $matches[1]->team2_player1_id, $matches[1]->team2_player2_id];
        sort($court2Ids);
        $expectedCourt2 = collect([1600, 1500, 1400, 1300])->map(fn ($r) => $u[$r]->id)->sort()->values()->all();
        $this->assertSame($expectedCourt2, $court2Ids, 'Корт 2 — следующие по рейтингу');
    }

    public function test_first_round_is_deterministic_by_rating(): void
    {
        $ratings = [2000, 1900, 1800, 1700, 1600, 1500, 1400, 1300];

        [$t1, $u1] = $this->makeTournament($ratings);
        $this->service->startTournament($t1->fresh());

        // Корт 1, team1_player1 = сильнейший (2000) — детерминированно, без shuffle.
        $first = MexicanoMatch::whereIn('mexicano_round_id', $t1->fresh()->mexicanoRounds()->pluck('id'))
            ->orderBy('court_number')->first();
        $this->assertSame($u1[2000]->id, $first->team1_player1_id, 'Первый игрок корта 1 — сильнейший по рейтингу');
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\AmericanoFlexPlayer;
use App\Models\AmericanoFlexRound;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\AmericanoFlexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmericanoFlexServiceTest extends TestCase
{
    use RefreshDatabase;

    private AmericanoFlexService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AmericanoFlexService();
    }

    /** 10 игроков, 2 корта (courts_count=2). */
    private function makeTournament(int $playersCount = 10, int $courtsCount = 2): Tournament
    {
        $tournament = Tournament::factory()->create([
            'type' => 'americano_flex',
            'status' => 'open',
            'max_participants' => $playersCount,
            'courts_count' => $courtsCount,
        ]);

        for ($i = 1; $i <= $playersCount; $i++) {
            $user = User::factory()->create(['rating' => 1500]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
        }

        return $tournament;
    }

    public function test_start_tournament_creates_players(): void
    {
        $tournament = $this->makeTournament();
        $this->service->startTournament($tournament);

        $this->assertEquals('in_progress', $tournament->fresh()->status);
        $this->assertEquals(10, AmericanoFlexPlayer::where('tournament_id', $tournament->id)->count());
    }

    public function test_first_round_has_correct_matches_and_byes(): void
    {
        $tournament = $this->makeTournament(10, 2);
        $this->service->startTournament($tournament);

        $round = $tournament->americanoFlexRounds()->first();
        $this->assertNotNull($round);
        $this->assertEquals(1, $round->round_number);
        $this->assertEquals(2, $round->matches()->count(), 'на 2 кортах 2 матча');
        $this->assertEquals(2, $round->byes()->count(), '10 - 8 = 2 отдыхают');
    }

    public function test_bye_streak_increments_for_resting_players(): void
    {
        $tournament = $this->makeTournament(10, 2);
        $this->service->startTournament($tournament);

        $restingIds = $tournament->americanoFlexRounds()->first()->byes()->pluck('user_id');
        $resting = AmericanoFlexPlayer::where('tournament_id', $tournament->id)
            ->whereIn('user_id', $restingIds)
            ->get();
        foreach ($resting as $r) {
            $this->assertEquals(1, $r->bye_streak);
            $this->assertEquals(1, $r->bye_count);
        }
    }
}

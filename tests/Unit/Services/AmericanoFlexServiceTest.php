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
}

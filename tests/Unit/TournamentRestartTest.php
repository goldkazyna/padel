<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\AmericanoRound;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TournamentRestartTest extends TestCase
{
    use RefreshDatabase;

    private function americano(string $status): Tournament
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        return Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'americano',
            'status' => $status, 'max_participants' => 8,
            'start_date' => now()->addDay(),
            'registration_deadline' => now()->addHour(),
        ]);
    }

    public function test_can_restart_true_when_in_progress_and_first_round_not_completed(): void
    {
        $t = $this->americano('in_progress');
        $g = TournamentGroup::create(['tournament_id' => $t->id, 'name' => 'A']);
        AmericanoRound::create(['tournament_group_id' => $g->id, 'round_number' => 1, 'status' => 'in_progress']);

        $this->assertFalse($t->firstRoundCompleted());
        $this->assertTrue($t->canRestart());
    }

    public function test_cannot_restart_after_first_round_completed(): void
    {
        $t = $this->americano('in_progress');
        $g = TournamentGroup::create(['tournament_id' => $t->id, 'name' => 'A']);
        AmericanoRound::create(['tournament_group_id' => $g->id, 'round_number' => 1, 'status' => 'completed']);

        $this->assertTrue($t->firstRoundCompleted());
        $this->assertFalse($t->canRestart());
    }

    public function test_cannot_restart_when_open_or_completed(): void
    {
        $this->assertFalse($this->americano('open')->canRestart());
        $this->assertFalse($this->americano('completed')->canRestart());
    }
}

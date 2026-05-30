<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProcessModerationTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_expiry_and_promotion(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Team', 'type' => 'team',
            'status' => 'open', 'max_participants' => 4, 'waitlist_size' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 24,
        ]);
        $late = TournamentTeam::create([
            'tournament_id' => $t->id, 'player1_id' => User::factory()->create()->id,
            'player2_id' => User::factory()->create()->id, 'status' => 'pending',
            'moderation_deadline' => now()->subMinute(),
        ]);
        $waiting = TournamentTeam::create([
            'tournament_id' => $t->id, 'player1_id' => User::factory()->create()->id,
            'player2_id' => User::factory()->create()->id, 'status' => 'waiting',
        ]);
        // Делаем waiting-пару старейшей в очереди ожидания (FIFO по created_at).
        $waiting->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('tournaments:process-moderation')->assertExitCode(0);

        $this->assertSame('rejected', $late->fresh()->status);
        $this->assertSame('pending', $waiting->fresh()->status);
        $this->assertNotNull($waiting->fresh()->moderation_deadline);
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use App\Models\TournamentInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationInvitationAcceptTest extends TestCase
{
    use RefreshDatabase;

    public function test_accept_sets_moderation_deadline(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 24,
        ]);
        $player = User::factory()->create(['level' => 3]);
        $inv = TournamentInvitation::create([
            'tournament_id' => $t->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);
        Sanctum::actingAs($player);

        $this->postJson("/api/mobile/tournaments/invitations/{$inv->id}/accept")->assertOk();

        $row = $t->participants()->where('user_id', $player->id)->first();
        $this->assertNotNull($row->pivot->moderation_deadline);
    }
}

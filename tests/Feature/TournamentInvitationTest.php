<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use App\Models\Notification;
use App\Models\TournamentInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class TournamentInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function setup3(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $tournament = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        $player = User::factory()->create(['name' => 'Игрок']);
        return [$club, $admin, $tournament, $player];
    }

    public function test_admin_invites_player_creates_invitation_and_notification(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/invite", [
            'user_id' => $player->id,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('tournament_invitations', [
            'tournament_id' => $tournament->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $player->id, 'type' => 'tournament_invite',
        ]);
    }

    public function test_invite_blocked_for_team_tournament(): void
    {
        [$club, $admin, , $player] = $this->setup3();
        $team = Tournament::create([
            'club_id' => $club->id, 'name' => 'Team', 'type' => 'team',
            'status' => 'open', 'max_participants' => 8,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$team->id}/invite", [
            'user_id' => $player->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('tournament_invitations', 0);
    }

    public function test_invite_blocked_if_already_participant(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        $tournament->participants()->attach($player->id, ['status' => 'registered']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/invite", [
            'user_id' => $player->id,
        ])->assertStatus(422);
    }

    public function test_repeat_invite_does_not_duplicate(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        Sanctum::actingAs($admin);

        $url = "/api/mobile/admin/tournaments/{$tournament->id}/invite";
        $this->postJson($url, ['user_id' => $player->id])->assertOk();
        $this->postJson($url, ['user_id' => $player->id])->assertOk();

        $this->assertDatabaseCount('tournament_invitations', 1);
    }

    public function test_admin_lists_tournament_invitations(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        TournamentInvitation::create([
            'tournament_id' => $tournament->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/mobile/admin/tournaments/{$tournament->id}/invitations")
            ->assertOk()
            ->assertJsonCount(1, 'invitations')
            ->assertJsonPath('invitations.0.status', 'pending')
            ->assertJsonPath('invitations.0.player.id', $player->id);
    }

    public function test_admin_cancels_invitation(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        $inv = TournamentInvitation::create([
            'tournament_id' => $tournament->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/mobile/admin/tournaments/{$tournament->id}/invitations/{$inv->id}")
            ->assertOk();

        $this->assertDatabaseMissing('tournament_invitations', ['id' => $inv->id]);
    }

    public function test_invite_limited_to_ten_per_tournament(): void
    {
        [, $admin, $tournament, ] = $this->setup3();
        // Уже 10 приглашений
        for ($i = 0; $i < 10; $i++) {
            TournamentInvitation::create([
                'tournament_id' => $tournament->id,
                'user_id' => User::factory()->create()->id,
                'invited_by' => $admin->id, 'status' => 'pending',
            ]);
        }
        Sanctum::actingAs($admin);

        // 11-й — блок
        $eleventh = User::factory()->create();
        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/invite", [
            'user_id' => $eleventh->id,
        ])->assertStatus(422);
        $this->assertDatabaseCount('tournament_invitations', 10);

        // Повтор уже приглашённого — разрешён (не создаёт нового)
        $existing = TournamentInvitation::where('tournament_id', $tournament->id)->first();
        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/invite", [
            'user_id' => $existing->user_id,
        ])->assertOk();
        $this->assertDatabaseCount('tournament_invitations', 10);
    }

    public function test_player_lists_and_counts_pending(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        TournamentInvitation::create([
            'tournament_id' => $tournament->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);
        Sanctum::actingAs($player);

        $this->getJson('/api/mobile/tournaments/invitations')
            ->assertOk()
            ->assertJsonCount(1, 'invitations')
            ->assertJsonPath('invitations.0.tournament.name', 'Кубок')
            ->assertJsonPath('invitations.0.tournament.club.name', 'C')
            ->assertJsonPath('invitations.0.tournament.type_name', $tournament->type_name)
            ->assertJsonPath('invitations.0.invited_by_name', $admin->name);

        $this->getJson('/api/mobile/tournaments/invitations/count')
            ->assertOk()->assertJsonPath('count', 1);
    }

    public function test_accept_registers_player_pending(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        $inv = TournamentInvitation::create([
            'tournament_id' => $tournament->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);
        Sanctum::actingAs($player);

        $this->postJson("/api/mobile/tournaments/invitations/{$inv->id}/accept")
            ->assertOk()
            ->assertJsonPath('tournament_id', $tournament->id)
            ->assertJsonPath('waitlisted', false);

        $this->assertSame('accepted', $inv->fresh()->status);
        $this->assertDatabaseHas('tournament_participants', [
            'tournament_id' => $tournament->id, 'user_id' => $player->id, 'status' => 'pending',
        ]);
    }

    public function test_accept_when_full_without_waitlist_fails(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        // Забиваем 4 места
        for ($i = 0; $i < 4; $i++) {
            $tournament->participants()->attach(
                User::factory()->create()->id, ['status' => 'registered']
            );
        }
        $inv = TournamentInvitation::create([
            'tournament_id' => $tournament->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);
        Sanctum::actingAs($player);

        $this->postJson("/api/mobile/tournaments/invitations/{$inv->id}/accept")
            ->assertStatus(400);

        $this->assertSame('pending', $inv->fresh()->status);
    }

    public function test_decline_marks_declined(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        $inv = TournamentInvitation::create([
            'tournament_id' => $tournament->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);
        Sanctum::actingAs($player);

        $this->postJson("/api/mobile/tournaments/invitations/{$inv->id}/decline")->assertOk();
        $this->assertSame('declined', $inv->fresh()->status);

        $this->getJson('/api/mobile/tournaments/invitations')
            ->assertOk()->assertJsonCount(0, 'invitations');
    }

    public function test_cannot_accept_others_invitation(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        $inv = TournamentInvitation::create([
            'tournament_id' => $tournament->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/tournaments/invitations/{$inv->id}/accept")
            ->assertStatus(404);
    }
}

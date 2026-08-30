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

    public function test_прошедшие_турниры_не_висят_в_приглашениях(): void
    {
        [$club, $admin, $tournament, $player] = $this->setup3();

        // Приглашение на будущий турнир — живое.
        TournamentInvitation::create([
            'tournament_id' => $tournament->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);

        // Турнир уже сыгран — принимать нечего.
        $past = Tournament::create([
            'club_id' => $club->id, 'name' => 'Прошлый', 'type' => 'americano',
            'status' => 'completed', 'max_participants' => 4,
            'start_date' => now()->subWeek(),
        ]);
        TournamentInvitation::create([
            'tournament_id' => $past->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);

        // Дата прошла, а статус остался open — тоже мимо.
        $stale = Tournament::create([
            'club_id' => $club->id, 'name' => 'Вчерашний', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->subDay(),
        ]);
        TournamentInvitation::create([
            'tournament_id' => $stale->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);

        Sanctum::actingAs($player);

        $response = $this->getJson('/api/mobile/tournaments/invitations')->assertOk();
        $ids = collect($response->json('invitations'))->pluck('tournament.id')->all();

        $this->assertSame([$tournament->id], $ids);

        // Бейдж считает то же самое, иначе на иконке висит несуществующее.
        $this->getJson('/api/mobile/tournaments/invitations/count')
            ->assertOk()->assertJsonPath('count', 1);
    }

    public function test_приглашение_на_прошедший_турнир_не_принять(): void
    {
        [$club, $admin, , $player] = $this->setup3();
        $past = Tournament::create([
            'club_id' => $club->id, 'name' => 'Прошлый', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->subDay(),
        ]);
        $invitation = TournamentInvitation::create([
            'tournament_id' => $past->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);

        Sanctum::actingAs($player);

        $this->postJson("/api/mobile/tournaments/invitations/{$invitation->id}/accept")
            ->assertStatus(422);

        $this->assertSame(0, $past->participants()->count());
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

    /** Лимита на число приглашений нет — зовут столько, сколько нужно. */
    public function test_invites_are_not_capped(): void
    {
        [, $admin, $tournament, ] = $this->setup3();
        // Уже 10 приглашений — раньше на этом месте стоял потолок.
        for ($i = 0; $i < 10; $i++) {
            TournamentInvitation::create([
                'tournament_id' => $tournament->id,
                'user_id' => User::factory()->create()->id,
                'invited_by' => $admin->id, 'status' => 'pending',
            ]);
        }
        Sanctum::actingAs($admin);

        $eleventh = User::factory()->create();
        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/invite", [
            'user_id' => $eleventh->id,
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseCount('tournament_invitations', 11);

        // Повтор уже приглашённого по-прежнему обновляет существующее, а не плодит дубль.
        $existing = TournamentInvitation::where('tournament_id', $tournament->id)->first();
        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/invite", [
            'user_id' => $existing->user_id,
        ])->assertOk();
        $this->assertDatabaseCount('tournament_invitations', 11);
    }

    /** Веб-админка: та же свобода, что и в приложении. */
    public function test_web_invite_is_not_capped(): void
    {
        [, $admin, $tournament, ] = $this->setup3();
        for ($i = 0; $i < 10; $i++) {
            TournamentInvitation::create([
                'tournament_id' => $tournament->id,
                'user_id' => User::factory()->create()->id,
                'invited_by' => $admin->id, 'status' => 'pending',
            ]);
        }

        $eleventh = User::factory()->create();
        $this->actingAs($admin)
            ->post(route('club.tournaments.invite', $tournament), ['user_id' => $eleventh->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('tournament_invitations', 11);
    }

    // ===== Свой текст приглашения =====

    public function test_web_invite_uses_custom_text(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();

        $this->actingAs($admin)->post(route('club.tournaments.invite', $tournament), [
            'user_id' => $player->id,
            'invite_title' => 'Ждём тебя!',
            'invite_body' => 'Корт 3, сбор в 19:40, форма светлая',
        ])->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $player->id,
            'type' => 'tournament_invite',
            'title' => 'Ждём тебя!',
            'body' => 'Корт 3, сбор в 19:40, форма светлая',
        ]);
    }

    public function test_mobile_invite_uses_custom_text(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$tournament->id}/invite", [
            'user_id' => $player->id,
            'title' => 'Нужен четвёртый',
            'body' => 'Одно место осталось, стартуем в 20:00',
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $player->id,
            'title' => 'Нужен четвёртый',
            'body' => 'Одно место осталось, стартуем в 20:00',
        ]);
    }

    /** Пустой текст — уходит заготовка, а не пустое уведомление. */
    public function test_blank_text_falls_back_to_template(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();

        $this->actingAs($admin)->post(route('club.tournaments.invite', $tournament), [
            'user_id' => $player->id,
            'invite_title' => '   ',
            'invite_body' => '',
        ])->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $player->id,
            'title' => 'Приглашение на турнир',
            'body' => "Вас пригласили на турнир «{$tournament->name}»",
        ]);
    }

    public function test_too_long_text_is_rejected(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();

        $this->actingAs($admin)->post(route('club.tournaments.invite', $tournament), [
            'user_id' => $player->id,
            'invite_body' => str_repeat('я', 251),
        ])->assertSessionHasErrors('invite_body');

        $this->assertDatabaseMissing('tournament_invitations', [
            'tournament_id' => $tournament->id, 'user_id' => $player->id,
        ]);
    }

    /** Приложению нужна та же заготовка, что стоит в вебе. */
    public function test_invitations_endpoint_returns_defaults(): void
    {
        [, $admin, $tournament, ] = $this->setup3();
        Sanctum::actingAs($admin);

        $this->getJson("/api/mobile/admin/tournaments/{$tournament->id}/invitations")
            ->assertOk()
            ->assertJsonPath('invite_defaults.title', 'Приглашение на турнир')
            ->assertJsonPath('invite_defaults.body', "Вас пригласили на турнир «{$tournament->name}»");
    }

    /** Форма приглашения на странице турнира даёт править текст. */
    public function test_web_form_has_editable_text(): void
    {
        [, $admin, $tournament, ] = $this->setup3();

        $this->actingAs($admin)
            ->get(route('club.tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('name="invite_title"', false)
            ->assertSee('name="invite_body"', false)
            ->assertSee('Как увидит игрок');
    }

    /** Счётчик в блоке приглашений больше не показывает потолок. */
    public function test_web_page_shows_plain_invite_count(): void
    {
        [, $admin, $tournament, $player] = $this->setup3();
        TournamentInvitation::create([
            'tournament_id' => $tournament->id, 'user_id' => $player->id,
            'invited_by' => $admin->id, 'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('club.tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('Приглашения (1)')
            ->assertDontSee('Приглашения (1/10)');
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

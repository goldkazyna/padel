<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\JustPadelItPair;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\JustPadelItService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Кто собирает пары в парном «Just Padel It»: админ или сами игроки.
 *
 * «Админ собирает» — как работало раньше: запись поодиночке, пары перед стартом.
 * «Сами игроки» — запись парой, как в групповом: пара живёт в командах турнира,
 * а перед стартом переносится в пары формата.
 */
class JustPadelItPairingModeTest extends TestCase
{
    use RefreshDatabase;

    private function makeTournament(string $pairingMode = 'admin', bool $paired = true): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'JPI', 'type' => 'just_padel_it',
            'status' => 'open', 'start_date' => now()->addDay()->toDateString(),
            'courts_count' => 2, 'max_participants' => 8,
            'is_rated' => true, 'is_paired' => $paired,
            'pairing_mode' => $pairingMode,
            'min_level' => 0, 'max_level' => 10,
        ]);

        $players = [];
        for ($i = 1; $i <= 8; $i++) {
            $players[] = User::factory()->create([
                'name' => "P{$i}", 'rating' => 2000 - $i * 10, 'level' => 3,
            ]);
        }

        return [$t, $admin, $players];
    }

    // ===== Режим «админ собирает» =====

    public function test_admin_mode_keeps_solo_registration(): void
    {
        [$t] = $this->makeTournament('admin');

        $this->assertTrue($t->isAdminPairing());
        $this->assertTrue($t->usesSoloRegistration(), 'записываются поодиночке');
        $this->assertFalse($t->isSelfPairing());
    }

    public function test_admin_mode_rejects_pair_registration(): void
    {
        [$t, , $players] = $this->makeTournament('admin');

        Sanctum::actingAs($players[0]);
        $this->postJson("/api/mobile/tournaments/{$t->id}/register-team", [
            'partner_id' => $players[1]->id,
        ])->assertStatus(400)->assertJsonPath('success', false);

        $this->assertSame(0, TournamentTeam::count());
    }

    // ===== Режим «сами игроки» =====

    public function test_self_mode_switches_to_pair_registration(): void
    {
        [$t] = $this->makeTournament('self');

        $this->assertTrue($t->isSelfPairing());
        $this->assertFalse($t->usesSoloRegistration(), 'записываются парой');
        $this->assertFalse($t->isAdminPairing());
    }

    public function test_self_mode_accepts_pair_registration(): void
    {
        [$t, , $players] = $this->makeTournament('self');

        Sanctum::actingAs($players[0]);
        $this->postJson("/api/mobile/tournaments/{$t->id}/register-team", [
            'partner_id' => $players[1]->id,
        ])->assertOk()->assertJsonPath('success', true);

        $team = TournamentTeam::where('tournament_id', $t->id)->first();
        $this->assertNotNull($team, 'пара записалась как команда турнира');
        $this->assertSame($players[0]->id, $team->player1_id);
        $this->assertSame($players[1]->id, $team->player2_id);
    }

    public function test_self_mode_forbids_admin_pair_assembly(): void
    {
        [$t, , $players] = $this->makeTournament('self');
        foreach ($players as $p) {
            $t->participants()->attach($p->id, ['status' => 'registered']);
        }

        [$ok, $msg] = app(JustPadelItService::class)->createPairs($t, [
            [$players[0]->id, $players[1]->id],
            [$players[2]->id, $players[3]->id],
            [$players[4]->id, $players[5]->id],
            [$players[6]->id, $players[7]->id],
        ]);

        $this->assertFalse($ok, 'в этом режиме пары собирают игроки');
        $this->assertStringContainsString('сами игроки', $msg);
    }

    /** Одобренные пары — как после модерации. */
    private function approveTeams(Tournament $t, array $players): void
    {
        foreach ([[0, 1], [2, 3], [4, 5], [6, 7]] as [$a, $b]) {
            TournamentTeam::create([
                'tournament_id' => $t->id,
                'player1_id' => $players[$a]->id,
                'player2_id' => $players[$b]->id,
                'rating_avg' => (int) (($players[$a]->rating + $players[$b]->rating) / 2),
                'status' => 'approved',
            ]);
        }
    }

    public function test_start_moves_teams_into_format_pairs(): void
    {
        [$t, , $players] = $this->makeTournament('self');
        $this->approveTeams($t, $players);

        $this->assertTrue(app(JustPadelItService::class)->startTournament($t));

        $t = $t->fresh();
        $this->assertSame('in_progress', $t->status);
        $this->assertSame(4, JustPadelItPair::where('tournament_id', $t->id)->count(), 'пары перенесены');
        $this->assertSame(8, $t->participants()->wherePivot('status', 'registered')->count(),
            'оба игрока каждой пары стали участниками');
        $this->assertSame(2, $t->justPadelItRounds()->first()->matches()->count(), 'два корта — два матча');
    }

    public function test_start_ignores_teams_awaiting_moderation(): void
    {
        [$t, , $players] = $this->makeTournament('self');
        $this->approveTeams($t, $players);
        // Одну пару отправили на модерацию — стартовать с семерыми нельзя.
        TournamentTeam::where('tournament_id', $t->id)->latest('id')->first()->update(['status' => 'pending']);

        $this->assertFalse(app(JustPadelItService::class)->startTournament($t));
        $this->assertSame('open', $t->fresh()->status);
    }

    public function test_sync_is_idempotent(): void
    {
        [$t, , $players] = $this->makeTournament('self');
        $this->approveTeams($t, $players);

        $svc = app(JustPadelItService::class);
        $this->assertSame(4, $svc->syncPairsFromTeams($t));
        // Повторный вызов не должен плодить дубликаты пар и участников.
        $this->assertSame(0, $svc->syncPairsFromTeams($t->fresh()));
        $this->assertSame(4, JustPadelItPair::where('tournament_id', $t->id)->count());
        $this->assertSame(8, $t->fresh()->participants()->count());
    }

    // ===== Защита старых клиентов =====

    public function test_creating_paired_jpi_without_choice_keeps_admin_mode(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        // Форма/сборка без нового поля — режим не должен молча стать «сами игроки».
        $this->actingAs($admin)->post(route('club.tournaments.store'), [
            'club_id' => $club->id,
            'name' => 'JPI', 'type' => 'just_padel_it',
            'start_date' => now()->addDay()->toDateString(),
            'max_participants' => 8, 'courts_count' => 2,
            'min_level' => 1, 'max_level' => 5, 'status' => 'open',
            'is_paired' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $t = Tournament::where('type', 'just_padel_it')->firstOrFail();
        $this->assertSame('admin', $t->pairing_mode);
        $this->assertTrue($t->isAdminPairing());
    }

    /** Приложение создаёт парный JPI и сразу задаёт способ сбора пар. */
    public function test_app_can_choose_pairing_mode_on_create(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", [
            'club_id' => $club->id,
            'name' => 'JPI парный',
            'type' => 'just_padel_it',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1, 'max_level' => 5,
            'max_participants' => 8, 'courts_count' => 2,
            'is_paired' => true,
            'pairing_mode' => 'self',
            'status' => 'open',
        ])->assertOk();

        $t = Tournament::where('type', 'just_padel_it')->firstOrFail();
        $this->assertSame('self', $t->pairing_mode);
        $this->assertTrue($t->isSelfPairing());
    }

    /** И меняет его при редактировании, пока турнир не начался. */
    public function test_app_can_change_pairing_mode_on_edit(): void
    {
        [$t, $admin] = $this->makeTournament('admin');
        $t->update(['status' => 'open']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", [
            'name' => $t->name,
            'start_date' => now()->addDay()->toDateString(),
            'min_level' => 1, 'max_level' => 5,
            'max_participants' => 8,
            'pairing_mode' => 'self',
        ])->assertOk();

        $this->assertSame('self', $t->fresh()->pairing_mode);
    }

    public function test_non_paired_jpi_is_always_solo(): void
    {
        // Без фиксированных пар выбора нет — записываются поодиночке.
        [$t] = $this->makeTournament('self', paired: false);

        $this->assertTrue($t->usesSoloRegistration());
        $this->assertFalse($t->isSelfPairing());
        $this->assertFalse($t->isAdminPairing());
    }

    public function test_team_tournament_behaviour_is_unchanged(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $self = Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'team', 'status' => 'open',
            'start_date' => now()->addDay()->toDateString(), 'max_participants' => 8,
            'pairing_mode' => 'self',
        ]);
        $byAdmin = Tournament::create([
            'club_id' => $club->id, 'name' => 'T2', 'type' => 'team', 'status' => 'open',
            'start_date' => now()->addDay()->toDateString(), 'max_participants' => 8,
            'pairing_mode' => 'admin',
        ]);

        $this->assertFalse($self->usesSoloRegistration());
        $this->assertTrue($byAdmin->usesSoloRegistration());
        $this->assertTrue($byAdmin->isAdminPairing());
    }
}

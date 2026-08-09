<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Удаление турнира из мобильной админки.
 *
 * Удаление сносит каскадом матчи, группы, участников, чат и приглашения,
 * поэтому разрешено только там, где терять нечего: черновик и отменённый.
 * Завершённый не трогаем — по нему начислен рейтинг, и в истории игроков
 * остались бы точки без турнира.
 */
class MobileAdminDeleteTournamentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Tournament,1:User} */
    private function makeTournament(string $status): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano',
            'status' => $status,
            'max_participants' => 8,
            'start_date' => now()->addDay(),
        ]);

        return [$tournament, $admin];
    }

    public function test_cancelled_tournament_can_be_deleted(): void
    {
        [$tournament, $admin] = $this->makeTournament('cancelled');
        $player = User::factory()->create();
        TournamentParticipant::create([
            'tournament_id' => $tournament->id,
            'user_id' => $player->id,
            'status' => 'registered',
        ]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/mobile/admin/tournaments/{$tournament->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('tournaments', ['id' => $tournament->id]);
        // Участники уходят каскадом — записей о турнире не остаётся.
        $this->assertDatabaseMissing('tournament_participants', [
            'tournament_id' => $tournament->id,
        ]);
    }

    public function test_draft_tournament_can_be_deleted(): void
    {
        [$tournament, $admin] = $this->makeTournament('draft');

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/mobile/admin/tournaments/{$tournament->id}")->assertOk();

        $this->assertDatabaseMissing('tournaments', ['id' => $tournament->id]);
    }

    public function test_completed_tournament_cannot_be_deleted(): void
    {
        [$tournament, $admin] = $this->makeTournament('completed');

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/mobile/admin/tournaments/{$tournament->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('tournaments', ['id' => $tournament->id]);
    }

    public function test_open_tournament_cannot_be_deleted(): void
    {
        [$tournament, $admin] = $this->makeTournament('open');

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/mobile/admin/tournaments/{$tournament->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('tournaments', ['id' => $tournament->id]);
    }

    public function test_tournament_with_rating_history_is_protected(): void
    {
        // Страховка: статус отменённый, но рейтинг по турниру начислялся.
        // Значит данные в неожиданном состоянии — удалять нельзя.
        [$tournament, $admin] = $this->makeTournament('cancelled');
        $player = User::factory()->create();
        RatingHistory::create([
            'user_id' => $player->id,
            'tournament_id' => $tournament->id,
            'rating_before' => 1000,
            'rating_after' => 1020,
            'change' => 20,
            'reason' => 'Турнир',
        ]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/mobile/admin/tournaments/{$tournament->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('tournaments', ['id' => $tournament->id]);
    }

    public function test_foreign_admin_cannot_delete(): void
    {
        [$tournament, $admin] = $this->makeTournament('cancelled');
        $stranger = User::factory()->create(['role' => 'club_admin']);

        Sanctum::actingAs($stranger);
        $this->deleteJson("/api/mobile/admin/tournaments/{$tournament->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('tournaments', ['id' => $tournament->id]);
    }
}

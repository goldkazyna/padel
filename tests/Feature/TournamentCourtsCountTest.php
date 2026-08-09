<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TournamentCourtsCountTest extends TestCase
{
    use RefreshDatabase;

    /** Клуб и его администратор. */
    private function setupClub(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        return [$club, $admin];
    }

    /** Турнир с заданными кортами. */
    private function makeTournament(Club $club, ?int $count, ?array $courts): Tournament
    {
        return Tournament::create([
            'club_id' => $club->id,
            'name' => 'Турнир',
            'type' => 'king_of_court',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'max_participants' => 16,
            'courts_count' => $count,
            'courts' => $courts,
        ]);
    }

    public function test_shrinking_count_drops_extra_names(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeTournament($club, 3, ['Корт 1', 'Корт 2', 'Корт 3', 'Корт 4']);

        $t->syncCourtNames();

        $this->assertSame(['Корт 1', 'Корт 2', 'Корт 3'], $t->fresh()->courts);
    }

    public function test_growing_count_pads_with_empty(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeTournament($club, 5, ['Корт 1', 'Корт 2', 'Корт 3']);

        $t->syncCourtNames();

        $courts = $t->fresh()->courts;
        $this->assertCount(5, $courts);
        $this->assertSame('Корт 3', $courts[2]);
        $this->assertNull($courts[3], 'недостающие названия — пустые');
        $this->assertNull($courts[4]);
        // Подпись для добитых кортов генерируется по умолчанию.
        $this->assertSame('Корт 4', $t->fresh()->getCourtName(4));
    }

    public function test_null_count_leaves_names_untouched(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeTournament($club, null, ['Центральный', 'Дальний']);

        $t->syncCourtNames();

        $this->assertSame(['Центральный', 'Дальний'], $t->fresh()->courts);
    }

    public function test_all_empty_names_become_null(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeTournament($club, 3, [null, null, null, null]);

        $t->syncCourtNames();

        $this->assertNull($t->fresh()->courts);
    }

    public function test_no_names_at_all_stays_null(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeTournament($club, 3, null);

        $t->syncCourtNames();

        $this->assertNull($t->fresh()->courts);
    }

    public function test_mobile_update_fixes_broken_record(): void
    {
        [$club, $admin] = $this->setupClub();
        // Уже испорченная запись: счётчик 3, названий 4.
        $t = $this->makeTournament($club, 3, ['Корт 1', 'Корт 2', 'Корт 3', 'Корт 4']);

        Sanctum::actingAs($admin);
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", [
            'name' => 'Турнир',
            'start_date' => now()->addDay()->toDateString(),
            'min_level' => 1,
            'max_level' => 5,
            'max_participants' => 16,
            'courts_count' => 3,
        ])->assertOk();

        $this->assertCount(3, $t->fresh()->courts);
    }

    public function test_web_update_fixes_broken_record(): void
    {
        [$club, $admin] = $this->setupClub();
        $t = $this->makeTournament($club, 3, ['Корт 1', 'Корт 2', 'Корт 3', 'Корт 4']);

        $this->actingAs($admin)->put(route('club.tournaments.update', $t), [
            'name' => 'Турнир',
            'type' => 'king_of_court',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'min_level' => 1,
            'max_level' => 5,
            'max_participants' => 16,
            'courts_count' => 3,
        ])->assertRedirect();

        $this->assertCount(3, $t->fresh()->courts);
    }

    /**
     * Solo Just Padel It без courts_count стартует при любом кратном четырём
     * числе зарегистрированных: 12 игроков — это 3 корта, всё раскладывается.
     */
    public function test_jpi_solo_without_courts_count_is_ready_with_12_players(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeJpiSolo($club, null, 12);

        $this->assertTrue($t->jpiSeedingReady());
    }

    /**
     * А с сохранённым courts_count = 4 те же 12 игроков посев блокируют:
     * сетка строится ровно на кортов × 4 = 16 мест. Поэтому подставлять
     * число кортов «на всякий случай» нельзя — оно ломает старт турнира.
     */
    public function test_jpi_solo_with_courts_count_4_is_not_ready_with_12_players(): void
    {
        [$club] = $this->setupClub();
        $t = $this->makeJpiSolo($club, 4, 12);

        $this->assertFalse($t->jpiSeedingReady());
    }

    /** Solo Just Padel It с заданным числом кортов и зарегистрированными игроками. */
    private function makeJpiSolo(Club $club, ?int $courtsCount, int $registered): Tournament
    {
        $t = Tournament::create([
            'club_id' => $club->id,
            'name' => 'JPI',
            'type' => 'just_padel_it',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'max_participants' => 16,
            'courts_count' => $courtsCount,
            'is_paired' => false,
        ]);

        for ($i = 0; $i < $registered; $i++) {
            \App\Models\TournamentParticipant::create([
                'tournament_id' => $t->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'registered',
            ]);
        }

        return $t;
    }

    public function test_mobile_create_syncs_mismatched_courts(): void
    {
        [$club, $admin] = $this->setupClub();

        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", [
            'type' => 'king_of_court',
            'name' => 'Турнир',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'min_level' => 1,
            'max_level' => 5,
            'max_participants' => 16,
            // Расхождение как из реального приложения: названий больше, чем кортов.
            'courts_count' => 3,
            'courts' => ['Корт 1', 'Корт 2', 'Корт 3', 'Корт 4'],
        ])->assertOk();

        $tournament = Tournament::find($response->json('tournament_id'));
        $this->assertCount(3, $tournament->courts);
    }
}

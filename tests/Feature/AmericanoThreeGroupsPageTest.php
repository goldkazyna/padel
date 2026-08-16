<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\TournamentParticipant;
use App\Models\TournamentPlayoffMatch;
use App\Models\User;
use App\Services\AmericanoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Страница турнира Американо при трёх группах: общая таблица и сетка с четвертьфиналом.
 */
class AmericanoThreeGroupsPageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tournament, 1: User} турнир и админ клуба */
    private function scenario(int $groupsCount = 3, int $players = 24): array
    {
        $club = Club::create(['name' => 'Padel', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano',
            'status' => 'in_progress',
            'groups_count' => $groupsCount,
            'max_participants' => $players,
            'rounds_count' => 1,
            'has_playoff' => true,
            'playoff_type' => 'semifinal_final',
            'playoff_format' => Tournament::PLAYOFF_FORMAT_TABLE_QF,
        ]);

        $groups = [];
        for ($g = 0; $g < $groupsCount; $g++) {
            $groups[$g] = TournamentGroup::create([
                'tournament_id' => $tournament->id,
                'name' => 'Группа ' . chr(65 + $g),
            ]);
        }

        for ($i = 0; $i < $players; $i++) {
            $user = User::factory()->create(['rating' => 1500, 'name' => 'Игрок ' . ($i + 1)]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
            $groups[$i % $groupsCount]->players()->attach($user->id, [
                'total_points' => ($players - $i) * 10,
                'rating_before' => 1500,
                'rating_after' => null,
            ]);
        }

        return [$tournament->fresh(), $admin];
    }

    public function test_three_groups_show_overall_table(): void
    {
        [$t, $admin] = $this->scenario();

        $this->actingAs($admin)
            ->get(route('club.tournaments.show', $t))
            ->assertOk()
            ->assertSee('Общая таблица всех групп')
            ->assertSee('Места 1–4 ждут соперников в полуфинале, места 5–12 играют четвертьфинал.');
    }

    public function test_two_groups_have_no_overall_table(): void
    {
        [$t, $admin] = $this->scenario(2, 16);

        $this->actingAs($admin)
            ->get(route('club.tournaments.show', $t))
            ->assertOk()
            ->assertDontSee('Общая таблица всех групп');
    }

    public function test_quarterfinal_column_is_rendered_in_bracket(): void
    {
        [$t, $admin] = $this->scenario();
        app(AmericanoService::class)->generatePlayoff($t);

        $response = $this->actingAs($admin)->get(route('club.tournaments.show', $t))->assertOk();

        $response->assertSee('Четвертьфинал');
        // Полуфинал ждёт победителя — в слоте стоит подпись, а не «Ожидание…».
        $response->assertSee('Победитель ЧФ 2');
    }

    /**
     * Турнир до старта — редактируются только такие.
     *
     * @return array{0: Tournament, 1: User}
     */
    private function openTournament(int $groupsCount): array
    {
        $club = Club::create(['name' => 'Padel', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $t = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano',
            'status' => 'open',
            'groups_count' => $groupsCount,
            'max_participants' => 24,
            'has_playoff' => true,
            'playoff_type' => 'semifinal_final',
            'playoff_format' => $groupsCount >= 3 ? Tournament::PLAYOFF_FORMAT_TABLE_QF : 'mix',
        ]);

        return [$t, $admin];
    }

    public function test_edit_form_offers_table_format_for_three_groups(): void
    {
        [$t, $admin] = $this->openTournament(3);

        $this->actingAs($admin)
            ->get(route('club.tournaments.edit', $t))
            ->assertOk()
            ->assertSee('value="table_qf"', false)
            ->assertSee('Формат плей-офф');
    }

    public function test_edit_form_keeps_group_formats_for_two_groups(): void
    {
        [$t, $admin] = $this->openTournament(2);

        $this->actingAs($admin)
            ->get(route('club.tournaments.edit', $t))
            ->assertOk()
            ->assertSee('Группа vs Группа (A1+A2 vs B1+B2, A3+A4 vs B3+B4)');
    }

    public function test_edit_saves_table_format(): void
    {
        [$t, $admin] = $this->openTournament(2);

        $this->actingAs($admin)
            ->put(route('club.tournaments.update', $t), [
                'club_id' => $t->club_id,
                'name' => 'Американо 24',
                'type' => 'americano',
                'start_date' => now()->addDay()->toDateString(),
                'max_participants' => 24,
                'min_level' => 1,
                'max_level' => 5,
                'status' => 'open',
                'groups_count' => 3,
                'has_playoff' => 1,
                'playoff_type' => 'semifinal_final',
                'playoff_format' => 'table_qf',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $t = $t->fresh();
        $this->assertSame(3, (int) $t->groups_count, 'три группы сохранились');
        $this->assertSame('table_qf', $t->playoff_format);
    }

    public function test_mobile_admin_can_set_three_groups(): void
    {
        [$t, $admin] = $this->openTournament(2);

        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", [
            'name' => 'Американо 24',
            'start_date' => now()->addDay()->toDateString(),
            'min_level' => 1,
            'max_level' => 5,
            'max_participants' => 24,
            'groups_count' => 3,
            'playoff_format' => 'table_qf',
        ])->assertOk();

        $t = $t->fresh();
        $this->assertSame(3, (int) $t->groups_count, 'три группы не схлопнулись до одной');
        $this->assertSame('table_qf', $t->playoff_format);
    }

    public function test_score_saved_from_web_advances_quarterfinal_winner(): void
    {
        [$t, $admin] = $this->scenario();
        app(AmericanoService::class)->generatePlayoff($t);

        $quarter = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Четвертьфинал')->where('match_number', 1)->firstOrFail();

        $this->actingAs($admin)
            ->post(route('club.americano.savePlayoffScore', $quarter), [
                'team1_score' => 21,
                'team2_score' => 16,
            ])->assertRedirect();

        $semi = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Полуфинал')->where('match_number', 2)->firstOrFail();

        $this->assertSame($quarter->team1_player1_id, $semi->team2_player1_id);
        $this->assertSame($quarter->team1_player2_id, $semi->team2_player2_id);
    }
}

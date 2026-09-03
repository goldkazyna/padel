<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\AmericanoRound;
use App\Models\AmericanoMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Список игроков клуба: пол и сыгранные матчи.
 *
 * Матчи считаются во всех форматах и только по завершённым турнирам —
 * иначе счётчик надувается недоигранными.
 */
class ClubUsersFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin', 'city' => 'Алматы']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function player(string $name, ?string $gender): User
    {
        return User::factory()->create([
            'name' => $name,
            'gender' => $gender,
            'city' => 'Алматы',
            'role' => 'user',
        ]);
    }

    /** Завершённый матч Американо между двумя парами. */
    private function playedMatch(array $players): void
    {
        $tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'status' => 'completed',
            'type' => 'americano',
        ]);
        $group = TournamentGroup::create(['tournament_id' => $tournament->id, 'name' => 'A']);
        $round = AmericanoRound::create([
            'tournament_group_id' => $group->id,
            'round_number' => 1,
            'status' => 'completed',
        ]);

        AmericanoMatch::create([
            'americano_round_id' => $round->id,
            'court_number' => 1,
            'team1_player1_id' => $players[0]->id,
            'team1_player2_id' => $players[1]->id,
            'team2_player1_id' => $players[2]->id,
            'team2_player2_id' => $players[3]->id,
            'team1_score' => 21,
            'team2_score' => 15,
            'status' => 'completed',
        ]);
    }

    public function test_пол_виден_и_фильтруется(): void
    {
        $this->player('Айгуль Женская', 'female');
        $this->player('Ержан Мужской', 'male');
        $this->player('Без Пола', null);

        $this->actingAs($this->admin)->get(route('club.users.index'))
            ->assertOk()
            ->assertSee('Айгуль Женская')
            ->assertSee('Ержан Мужской');

        $this->actingAs($this->admin)->get(route('club.users.index', ['gender' => 'female']))
            ->assertOk()
            ->assertSee('Айгуль Женская')
            ->assertDontSee('Ержан Мужской');

        $this->actingAs($this->admin)->get(route('club.users.index', ['gender' => 'unknown']))
            ->assertOk()
            ->assertSee('Без Пола')
            ->assertDontSee('Айгуль Женская');
    }

    public function test_в_таблице_видно_число_матчей(): void
    {
        $players = [
            $this->player('Первый Игрок', 'male'),
            $this->player('Второй Игрок', 'male'),
            $this->player('Третий Игрок', 'female'),
            $this->player('Четвёртый Игрок', 'female'),
        ];
        $this->playedMatch($players);
        $this->playedMatch($players);

        $html = $this->actingAs($this->admin)->get(route('club.users.index'))
            ->assertOk()->getContent();

        // Каждый из четверых сыграл два матча.
        $this->assertSame(4, substr_count($html, '<span class="matches-count has">2</span>'));
    }

    public function test_фильтр_прячет_тех_кто_не_играл(): void
    {
        $players = [
            $this->player('Игравший Один', 'male'),
            $this->player('Игравший Два', 'male'),
            $this->player('Игравший Три', 'female'),
            $this->player('Игравший Четыре', 'female'),
        ];
        $this->playedMatch($players);
        $this->player('Новичок Без Игр', 'male');

        $this->actingAs($this->admin)->get(route('club.users.index', ['with_games' => 1]))
            ->assertOk()
            ->assertSee('Игравший Один')
            ->assertDontSee('Новичок Без Игр');
    }

    public function test_незавершённый_турнир_в_счётчик_не_идёт(): void
    {
        $players = [
            $this->player('Игрок А', 'male'),
            $this->player('Игрок Б', 'male'),
            $this->player('Игрок В', 'female'),
            $this->player('Игрок Г', 'female'),
        ];

        $tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'status' => 'in_progress',
            'type' => 'americano',
        ]);
        $group = TournamentGroup::create(['tournament_id' => $tournament->id, 'name' => 'A']);
        $round = AmericanoRound::create([
            'tournament_group_id' => $group->id, 'round_number' => 1, 'status' => 'completed',
        ]);
        AmericanoMatch::create([
            'americano_round_id' => $round->id,
            'court_number' => 1,
            'team1_player1_id' => $players[0]->id,
            'team1_player2_id' => $players[1]->id,
            'team2_player1_id' => $players[2]->id,
            'team2_player2_id' => $players[3]->id,
            'team1_score' => 21, 'team2_score' => 15, 'status' => 'completed',
        ]);

        $this->actingAs($this->admin)->get(route('club.users.index', ['with_games' => 1]))
            ->assertOk()
            ->assertDontSee('Игрок А');
    }
}

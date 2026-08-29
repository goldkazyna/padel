<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\League;
use App\Models\LeaguePlayer;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Раздел «Лиги» в CRM: создание, этапы, состав.
 *
 * Этап лиги — обычный турнир Americano Flex, поэтому проводится и судится
 * существующими экранами; здесь проверяем только саму лигу.
 */
class LeagueCrmTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function league(array $attrs = []): League
    {
        return League::create(array_merge([
            'club_id' => $this->club->id,
            'name' => 'Сентябрь Кап',
            'status' => 'open',
            'stages_planned' => 8,
            'max_players' => 12,
        ], $attrs));
    }

    public function test_лига_создаётся_из_формы(): void
    {
        $this->actingAs($this->admin)->post(route('club.leagues.store'), [
            'name' => 'Сентябрь Кап',
            'stages_planned' => 8,
            'max_players' => 12,
            'price' => 5000,
            'is_rated' => 1,
        ])->assertRedirect();

        $league = League::first();
        $this->assertSame('Сентябрь Кап', $league->name);
        $this->assertSame(8, $league->stages_planned);
        $this->assertSame('open', $league->status, 'сразу открыта на запись');
        $this->assertSame($this->club->id, $league->club_id);
    }

    public function test_этап_создаётся_турниром_americano_flex(): void
    {
        $league = $this->league();

        $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
            'start_date' => '2026-09-05 19:00',
            'max_participants' => 12,
        ])->assertRedirect();

        $stage = Tournament::first();
        $this->assertSame('americano_flex', $stage->type);
        $this->assertSame($league->id, $stage->league_id);
        $this->assertSame(1, (int) $stage->league_stage);
        $this->assertSame('Сентябрь Кап — этап 1', $stage->name);
        $this->assertSame('in_progress', $league->fresh()->status, 'первый этап запускает лигу');
    }

    public function test_состав_лиги_попадает_в_новый_этап(): void
    {
        $league = $this->league();
        $players = User::factory()->count(3)->create(['role' => 'player', 'level' => 3.0]);
        foreach ($players as $player) {
            LeaguePlayer::create([
                'league_id' => $league->id, 'user_id' => $player->id,
                'status' => 'registered', 'joined_at' => now(),
            ]);
        }

        $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
            'start_date' => '2026-09-05 19:00',
            'max_participants' => 12,
        ])->assertRedirect();

        $stage = Tournament::first();
        $this->assertSame(3, $stage->participants()->count(),
            'записывались в лигу, а не в каждый этап отдельно');
    }

    public function test_номера_этапов_идут_по_порядку(): void
    {
        $league = $this->league();

        foreach (['2026-09-05 19:00', '2026-09-12 19:00'] as $date) {
            $this->actingAs($this->admin)->post(route('club.leagues.stages.add', $league), [
                'start_date' => $date,
                'max_participants' => 12,
            ])->assertRedirect();
        }

        $this->assertSame([1, 2], Tournament::orderBy('id')->pluck('league_stage')->map(fn ($n) => (int) $n)->all());
    }

    public function test_игрок_добавляется_и_убирается_из_состава(): void
    {
        $league = $this->league();
        $player = User::factory()->create(['role' => 'player', 'level' => 3.0]);

        $this->actingAs($this->admin)
            ->post(route('club.leagues.players.add', $league), ['user_id' => $player->id])
            ->assertRedirect();

        $this->assertSame(1, $league->activePlayers()->count());

        $this->actingAs($this->admin)
            ->delete(route('club.leagues.players.remove', [$league, $player]))
            ->assertRedirect();

        $this->assertSame(0, $league->activePlayers()->count());
        $this->assertSame('left', LeaguePlayer::first()->status, 'запись остаётся, чтобы история не переписывалась');
    }

    public function test_поиск_игроков_умный_и_без_тех_кто_уже_в_лиге(): void
    {
        $league = $this->league();
        $inside = User::factory()->create(['role' => 'player', 'name' => 'Денис Дудников', 'level' => 3.0]);
        User::factory()->create(['role' => 'player', 'name' => 'Denis Dudnikov', 'level' => 3.0]);

        LeaguePlayer::create([
            'league_id' => $league->id, 'user_id' => $inside->id,
            'status' => 'registered', 'joined_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('club.leagues.players.search', $league) . '?q=Дудников')
            ->assertOk();

        $names = collect($response->json('players'))->pluck('name')->all();

        $this->assertSame(['Denis Dudnikov'], $names,
            'латиница находится по кириллице, а тот, кто уже в составе, не предлагается');
    }

    public function test_чужая_лига_недоступна(): void
    {
        $otherClub = Club::create(['name' => 'Другой', 'address' => 'Б', 'city' => 'Астана']);
        $league = $this->league(['club_id' => $otherClub->id]);

        $this->actingAs($this->admin)->get(route('club.leagues.show', $league))->assertForbidden();
    }

    public function test_страница_лиги_показывает_этапы_и_состав(): void
    {
        $league = $this->league();
        $player = User::factory()->create(['role' => 'player', 'name' => 'Ерлан Игрок', 'level' => 3.0]);
        LeaguePlayer::create([
            'league_id' => $league->id, 'user_id' => $player->id,
            'status' => 'registered', 'joined_at' => now(),
        ]);
        Tournament::create([
            'club_id' => $this->club->id, 'league_id' => $league->id, 'league_stage' => 1,
            'name' => 'Этап 1', 'type' => 'americano_flex', 'status' => 'open',
            'start_date' => '2026-09-05 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 12,
        ]);

        $this->actingAs($this->admin)->get(route('club.leagues.show', $league))
            ->assertOk()
            ->assertSee('Сентябрь Кап')
            ->assertSee('Этап 1')
            ->assertSee('Ерлан Игрок')
            ->assertSee('Таблица появится, когда завершится первый этап.');
    }
}

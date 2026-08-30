<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\TeamTournamentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Экран сбора пар в парном Americano Flex.
 *
 * Расклад собирается в браузере целиком и уходит одним запросом, поэтому
 * сервер проверяет его как единое целое: чужих игроков нет, никто не попал
 * в две пары, прежний расклад заменяется, а стартовавший турнир не трогаем.
 */
class FlexPairBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;
    private TeamTournamentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
        $this->service = new TeamTournamentService();
    }

    /**
     * @return array{0: Tournament, 1: \Illuminate\Support\Collection<int, User>}
     */
    private function tournament(int $players = 8): array
    {
        $tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'type' => 'americano_flex',
            'is_paired' => true,
            'status' => 'open',
            'courts_count' => 2,
            'max_participants' => $players,
        ]);

        $users = collect(range(1, $players))->map(function ($i) use ($tournament) {
            $user = User::factory()->create(['rating' => 1000 + $i * 100]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);

            return $user;
        });

        return [$tournament, $users];
    }

    private function save(Tournament $tournament, array $pairs)
    {
        return $this->actingAs($this->admin)
            ->postJson(route('club.tournaments.pairing.save', $tournament), ['pairs' => $pairs]);
    }

    public function test_расклад_сохраняется_одним_запросом(): void
    {
        [$tournament, $users] = $this->tournament(8);

        $this->save($tournament, [
            [$users[0]->id, $users[7]->id],
            [$users[1]->id, $users[6]->id],
            [$users[2]->id, $users[5]->id],
            [$users[3]->id, $users[4]->id],
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(4, $tournament->teams()->count());

        // Средний рейтинг считается сервером: в браузере он только показывается.
        $first = $tournament->teams()->where('player1_id', $users[0]->id)->first();
        $this->assertSame(
            (int) round(($users[0]->rating + $users[7]->rating) / 2),
            (int) $first->rating_avg
        );
    }

    public function test_новый_расклад_заменяет_прежний(): void
    {
        [$tournament, $users] = $this->tournament(8);

        $this->save($tournament, [
            [$users[0]->id, $users[1]->id],
            [$users[2]->id, $users[3]->id],
        ])->assertOk();

        // Разбитая в браузере пара не должна остаться в базе.
        $this->save($tournament, [
            [$users[0]->id, $users[2]->id],
        ])->assertOk();

        $this->assertSame(1, $tournament->teams()->count());
        $this->assertSame($users[2]->id, (int) $tournament->teams()->first()->player2_id);
    }

    public function test_игрок_не_может_попасть_в_две_пары(): void
    {
        [$tournament, $users] = $this->tournament(8);

        $this->save($tournament, [
            [$users[0]->id, $users[1]->id],
            [$users[0]->id, $users[2]->id],
        ])->assertStatus(422);

        $this->assertSame(0, $tournament->teams()->count(), 'ничего не записалось');
    }

    public function test_чужой_игрок_не_проходит(): void
    {
        [$tournament, $users] = $this->tournament(8);
        $stranger = User::factory()->create();

        $this->save($tournament, [[$users[0]->id, $stranger->id]])->assertStatus(422);

        $this->assertSame(0, $tournament->teams()->count());
    }

    public function test_пар_не_больше_чем_мест(): void
    {
        [$tournament, $users] = $this->tournament(8);

        $pairs = [];
        foreach ([[0, 1], [2, 3], [4, 5], [6, 7]] as [$a, $b]) {
            $pairs[] = [$users[$a]->id, $users[$b]->id];
        }
        // Пятая пара при четырёх местах — это уже не наш турнир.
        $extra = User::factory()->create();
        TournamentParticipant::create([
            'tournament_id' => $tournament->id,
            'user_id' => $extra->id,
            'status' => 'registered',
        ]);
        $another = User::factory()->create();
        TournamentParticipant::create([
            'tournament_id' => $tournament->id,
            'user_id' => $another->id,
            'status' => 'registered',
        ]);
        $pairs[] = [$extra->id, $another->id];

        $this->save($tournament, $pairs)->assertStatus(422);
    }

    public function test_неполный_состав_пары_не_собирает(): void
    {
        [$tournament, $users] = $this->tournament(8);
        // Один вышел — состав неполный, значит расклад преждевременный.
        TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('user_id', $users[7]->id)
            ->delete();

        $this->save($tournament, [[$users[0]->id, $users[1]->id]])->assertStatus(422);

        $this->assertSame(0, $tournament->teams()->count());
    }

    public function test_после_старта_пары_не_меняются(): void
    {
        [$tournament, $users] = $this->tournament(8);

        $this->save($tournament, [
            [$users[0]->id, $users[1]->id],
            [$users[2]->id, $users[3]->id],
            [$users[4]->id, $users[5]->id],
            [$users[6]->id, $users[7]->id],
        ])->assertOk();

        $this->service->startTournament($tournament->fresh());

        $this->save($tournament->fresh(), [[$users[0]->id, $users[2]->id]])
            ->assertStatus(422);

        $this->assertSame(4, $tournament->teams()->count(), 'расклад стартовавшего турнира цел');
    }

    public function test_пустой_список_очищает_пары(): void
    {
        [$tournament, $users] = $this->tournament(8);

        TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $users[0]->id,
            'player2_id' => $users[1]->id,
            'status' => 'approved',
            'rating_avg' => 1500,
        ]);

        $this->save($tournament, [])->assertOk();

        $this->assertSame(0, $tournament->teams()->count());
    }

    public function test_экран_сбора_пар_отдаёт_состав(): void
    {
        [$tournament, $users] = $this->tournament(8);

        $this->actingAs($this->admin)
            ->get(route('club.tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('Сбор пар')
            ->assertSee('Авто-пары')
            // Состав уезжает в data-атрибут: экран собирает пары без запросов.
            ->assertSee($users[0]->name, false);
    }
}

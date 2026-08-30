<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\League;
use App\Models\LeaguePlayer;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\LeagueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Состав этапа лиги: замены и досыпка.
 *
 * Состав копируется в этап один раз, при его создании. Дальше жизнь: кто-то
 * не смог прийти — его меняют на другого прямо в этапе; кого-то добавили в
 * лигу позже — его досыпают кнопкой. В сводную таблицу лиги подмена попадает
 * сама: она считается по сыгранным матчам, а не по составу лиги.
 */
class LeagueStageRosterTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;
    private League $league;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        $this->league = League::create([
            'club_id' => $this->club->id,
            'name' => 'Сентябрь Кап',
            'status' => 'in_progress',
            'stages_planned' => 8,
            'max_players' => 8,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function joinLeague(int $count): \Illuminate\Support\Collection
    {
        return collect(range(1, $count))->map(function ($i) {
            $user = User::factory()->create(['role' => 'player', 'rating' => 1000 + $i * 100]);
            LeaguePlayer::create([
                'league_id' => $this->league->id,
                'user_id' => $user->id,
                'status' => 'registered',
                'joined_at' => now(),
            ]);

            return $user;
        });
    }

    private function stage(int $maxParticipants = 8): Tournament
    {
        return app(LeagueService::class)->createStage($this->league, [
            'start_date' => '2026-09-05 19:00',
            'max_participants' => $maxParticipants,
        ], $this->admin->id);
    }

    public function test_досыпка_добавляет_тех_кого_записали_в_лигу_позже(): void
    {
        $this->joinLeague(4);
        $stage = $this->stage();
        $this->assertSame(4, $stage->participants()->count());

        // Пришёл ещё один игрок — этап уже создан, сам он туда не попадёт.
        $late = $this->joinLeague(1)->first();

        $this->actingAs($this->admin)
            ->post(route('club.tournaments.league.refill', $stage))
            ->assertRedirect();

        $this->assertTrue($stage->participants()->where('user_id', $late->id)->exists());
        $this->assertSame(5, $stage->participants()->count());
    }

    public function test_досыпка_не_трогает_ручные_замены(): void
    {
        $players = $this->joinLeague(4);
        $stage = $this->stage();

        // Один не смог прийти, вместо него — человек не из лиги.
        $substitute = User::factory()->create(['role' => 'player']);
        $stage->participants()->detach($players[0]->id);
        $stage->participants()->attach($substitute->id, ['status' => 'registered']);

        $added = app(LeagueService::class)->refillStage($this->league, $stage->fresh());

        $this->assertSame(1, $added, 'вернулся только пропущенный из лиги');
        $this->assertTrue($stage->participants()->where('user_id', $substitute->id)->exists(),
            'подмену досыпка не выкидывает');
    }

    public function test_досыпка_не_превышает_мест(): void
    {
        $this->joinLeague(6);
        $stage = $this->stage(4);

        $this->assertSame(4, $stage->participants()->count(), 'этап забрал ровно по местам');

        $added = app(LeagueService::class)->refillStage($this->league, $stage);

        $this->assertSame(0, $added);
        $this->assertSame(4, $stage->participants()->count());
    }

    public function test_обычный_турнир_досыпать_нечем(): void
    {
        $plain = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'type' => 'americano_flex',
            'status' => 'open',
            'max_participants' => 8,
        ]);

        $this->actingAs($this->admin)
            ->post(route('club.tournaments.league.refill', $plain))
            ->assertSessionHas('error');
    }

    public function test_замена_переписывает_игрока_в_паре(): void
    {
        $players = $this->joinLeague(4);
        $stage = $this->stage(4);
        $stage->update(['is_paired' => true]);

        $pair = TournamentTeam::create([
            'tournament_id' => $stage->id,
            'player1_id' => $players[0]->id,
            'player2_id' => $players[1]->id,
            'status' => 'approved',
            'rating_avg' => 1500,
        ]);

        $substitute = User::factory()->create(['role' => 'player', 'rating' => 3000]);

        $this->actingAs($this->admin)
            ->put(route('club.tournaments.participants.replace', [$stage, $players[0]->id]), [
                'new_user_id' => $substitute->id,
            ])
            ->assertRedirect();

        // Без этого ушедший остался бы в паре, а пришедший — вне пар,
        // и турнир не стартовал бы: роутер раскладывает по кортам пары.
        $pair->refresh();
        $this->assertSame($substitute->id, (int) $pair->player1_id);
        $this->assertSame(
            (int) round(($substitute->rating + $players[1]->rating) / 2),
            (int) $pair->rating_avg,
            'средний рейтинг пары пересчитан'
        );
    }

    public function test_замена_с_телефона_тоже_правит_пару(): void
    {
        $players = $this->joinLeague(4);
        $stage = $this->stage(4);
        $stage->update(['is_paired' => true]);

        $pair = TournamentTeam::create([
            'tournament_id' => $stage->id,
            'player1_id' => $players[2]->id,
            'player2_id' => $players[3]->id,
            'status' => 'approved',
            'rating_avg' => 1500,
        ]);

        $substitute = User::factory()->create(['role' => 'player', 'rating' => 2500]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/mobile/admin/tournaments/{$stage->id}/participants/{$players[3]->id}", [
                'new_user_id' => $substitute->id,
            ])
            ->assertOk();

        $this->assertSame($substitute->id, (int) $pair->fresh()->player2_id);
    }

    public function test_досыпка_с_телефона(): void
    {
        $this->joinLeague(4);
        $stage = $this->stage();
        $late = $this->joinLeague(1)->first();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/mobile/admin/tournaments/{$stage->id}/league/refill")
            ->assertOk();

        $this->assertSame(1, $response->json('added'));
        $this->assertTrue($stage->participants()->where('user_id', $late->id)->exists());
    }

    public function test_подмена_попадает_в_таблицу_лиги(): void
    {
        $players = $this->joinLeague(4);
        $stage = $this->stage(4);

        $substitute = User::factory()->create(['role' => 'player', 'name' => 'Подмена']);
        $stage->participants()->detach($players[0]->id);
        $stage->participants()->attach($substitute->id, ['status' => 'registered']);

        // Таблица лиги считается по сыгранным матчам, а не по составу лиги:
        // подмена попадает в неё со своими очками, а пропустивший — со своими.
        $round = \App\Models\AmericanoFlexRound::create([
            'tournament_id' => $stage->id, 'round_number' => 1, 'status' => 'completed',
        ]);
        \App\Models\AmericanoFlexMatch::create([
            'americano_flex_round_id' => $round->id,
            'court_number' => 1,
            'team1_player1_id' => $substitute->id,
            'team1_player2_id' => $players[1]->id,
            'team2_player1_id' => $players[2]->id,
            'team2_player2_id' => $players[3]->id,
            'team1_score' => 16,
            'team2_score' => 10,
            'status' => 'completed',
        ]);
        $stage->update(['status' => 'completed']);

        $rows = collect(\App\Support\LeagueStandings::build($this->league->fresh()));

        $this->assertTrue($rows->contains(fn ($r) => $r['id'] === $substitute->id));
        $this->assertSame(16, $rows->firstWhere('id', $substitute->id)['points_for']);
        $this->assertFalse(
            $rows->contains(fn ($r) => $r['id'] === $players[0]->id),
            'пропустивший этап очков за него не получает'
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\AmericanoFlexMatch;
use App\Models\AmericanoFlexPlayer;
use App\Models\AmericanoFlexRound;
use App\Models\Club;
use App\Models\League;
use App\Models\LeaguePlayer;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Лиги в приложении игрока: список, карточка с таблицей, запись,
 * «мои лиги» для профиля и плашка этапа внутри турнира.
 */
class MobileLeagueApiTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private League $league;
    private User $me;
    private User $rival;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $this->league = League::create([
            'club_id' => $this->club->id,
            'name' => 'Сентябрь Кап',
            'status' => 'open',
            'stages_planned' => 8,
            'max_players' => 12,
        ]);

        $this->me = User::factory()->create(['role' => 'player', 'name' => 'Я Игрок', 'level' => 3.0, 'rating' => 2000]);
        $this->rival = User::factory()->create(['role' => 'player', 'name' => 'Соперник', 'level' => 3.0, 'rating' => 1900]);
    }

    private function stage(int $number, string $status = 'completed'): Tournament
    {
        return Tournament::create([
            'club_id' => $this->club->id,
            'league_id' => $this->league->id,
            'league_stage' => $number,
            'name' => "Этап {$number}",
            'type' => 'americano_flex',
            'status' => $status,
            'start_date' => '2026-09-0' . $number . ' 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 12,
            'price' => 0,
        ]);
    }

    /** Матч этапа: я с партнёром против пары соперников. */
    private function playMatch(Tournament $stage, int $myScore, int $theirScore): void
    {
        $round = AmericanoFlexRound::firstOrCreate(
            ['tournament_id' => $stage->id, 'round_number' => 1],
            ['status' => 'completed']
        );

        $partner = User::factory()->create(['role' => 'player', 'level' => 3.0]);
        $other = User::factory()->create(['role' => 'player', 'level' => 3.0]);

        foreach ([$this->me, $partner, $this->rival, $other] as $user) {
            AmericanoFlexPlayer::firstOrCreate(['tournament_id' => $stage->id, 'user_id' => $user->id]);
        }

        AmericanoFlexMatch::create([
            'americano_flex_round_id' => $round->id,
            'court_number' => 1,
            'team1_player1_id' => $this->me->id,
            'team1_player2_id' => $partner->id,
            'team2_player1_id' => $this->rival->id,
            'team2_player2_id' => $other->id,
            'team1_score' => $myScore,
            'team2_score' => $theirScore,
            'status' => 'completed',
        ]);
    }

    public function test_список_лиг_показывает_прогресс_и_мою_запись(): void
    {
        $this->stage(1);
        $this->stage(2, 'open');
        LeaguePlayer::create([
            'league_id' => $this->league->id, 'user_id' => $this->me->id,
            'status' => 'registered', 'joined_at' => now(),
        ]);

        $response = $this->actingAs($this->me, 'sanctum')->getJson('/api/mobile/leagues')->assertOk();

        $league = $response->json('leagues.0');
        $this->assertSame('Сентябрь Кап', $league['name']);
        $this->assertSame(8, $league['stages_total']);
        $this->assertSame(1, $league['stages_done']);
        $this->assertTrue($league['is_registered']);
        $this->assertSame(2, $league['next_stage']['stage'], 'ближайший несыгранный этап');
    }

    public function test_карточка_лиги_отдаёт_таблицу_и_этапы(): void
    {
        $stage = $this->stage(1);
        $this->playMatch($stage, 21, 12);

        $response = $this->actingAs($this->me, 'sanctum')
            ->getJson("/api/mobile/leagues/{$this->league->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('league.stages'));
        $this->assertSame(1, $response->json('league.my_place'));

        $top = $response->json('league.standings.0');
        $this->assertSame('Я Игрок', $top['name']);
        $this->assertSame(21, $top['points_for']);
        $this->assertTrue($top['is_me'], 'свою строку приложение подсвечивает');
    }

    public function test_запись_и_отмена(): void
    {
        $this->actingAs($this->me, 'sanctum')
            ->postJson("/api/mobile/leagues/{$this->league->id}/register")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, $this->league->activePlayers()->count());

        $this->actingAs($this->me, 'sanctum')
            ->postJson("/api/mobile/leagues/{$this->league->id}/cancel")
            ->assertOk();

        $this->assertSame(0, $this->league->fresh()->activePlayers()->count());
    }

    public function test_в_закрытую_лигу_не_записаться(): void
    {
        $this->league->update(['status' => 'completed']);

        $this->actingAs($this->me, 'sanctum')
            ->postJson("/api/mobile/leagues/{$this->league->id}/register")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_нет_мест_в_лиге(): void
    {
        $this->league->update(['max_players' => 1]);
        LeaguePlayer::create([
            'league_id' => $this->league->id, 'user_id' => $this->rival->id,
            'status' => 'registered', 'joined_at' => now(),
        ]);

        $this->actingAs($this->me, 'sanctum')
            ->postJson("/api/mobile/leagues/{$this->league->id}/register")
            ->assertStatus(422);
    }

    public function test_этап_несёт_моё_место_и_очки(): void
    {
        // Медальку за этап показывает сама лига — в общей истории турниров
        // этапов больше нет.
        $stage = $this->stage(1);
        $this->playMatch($stage, 21, 12);
        $pending = $this->stage(2, 'open');

        $response = $this->actingAs($this->me, 'sanctum')
            ->getJson("/api/mobile/leagues/{$this->league->id}")
            ->assertOk();

        $first = $response->json('league.stages.0');
        $this->assertSame(1, $first['my_place']);
        $this->assertSame(21, $first['my_points']);

        $second = $response->json('league.stages.1');
        $this->assertNull($second['my_place'], 'у несыгранного этапа места нет');
        $this->assertNull($second['my_points']);
    }

    public function test_этапы_лиги_не_попадают_в_историю_турниров(): void
    {
        $stage = $this->stage(1);
        $stage->participants()->attach($this->me->id, ['status' => 'registered']);

        $plain = Tournament::create([
            'club_id' => $this->club->id,
            'name' => 'Обычный турнир',
            'type' => 'americano_flex',
            'status' => 'completed',
            'start_date' => '2026-09-10 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 12, 'price' => 0,
        ]);
        $plain->participants()->attach($this->me->id, ['status' => 'registered']);

        $response = $this->actingAs($this->me, 'sanctum')
            ->getJson('/api/mobile/tournaments/archive')
            ->assertOk();

        $ids = collect($response->json('tournaments'))->pluck('id')->all();
        $this->assertContains($plain->id, $ids);
        $this->assertNotContains($stage->id, $ids, 'этап смотрим в лиге, а не в истории');
    }

    public function test_мои_лиги_показывают_место_и_очки(): void
    {
        LeaguePlayer::create([
            'league_id' => $this->league->id, 'user_id' => $this->me->id,
            'status' => 'registered', 'joined_at' => now(),
        ]);
        $first = $this->stage(1);
        $this->playMatch($first, 20, 10);
        $second = $this->stage(2);
        $this->playMatch($second, 15, 12);

        $response = $this->actingAs($this->me, 'sanctum')->getJson('/api/mobile/leagues/my')->assertOk();

        $league = $response->json('leagues.0');
        $this->assertSame(1, $league['my_place']);
        $this->assertSame(35, $league['my_points'], 'очки за оба этапа');
        $this->assertSame(2, $league['my_stages']);
    }

    public function test_турнир_этапа_знает_свою_лигу(): void
    {
        $stage = $this->stage(3, 'open');

        $response = $this->actingAs($this->me, 'sanctum')
            ->getJson("/api/mobile/tournaments/{$stage->id}")
            ->assertOk();

        $league = $response->json('league') ?? $response->json('tournament.league');

        $this->assertNotNull($league, 'приложение показывает плашку «Этап 3 из 8»');
        $this->assertSame('Сентябрь Кап', $league['name']);
        $this->assertSame(3, $league['stage']);
        $this->assertSame(8, $league['stages_total']);
    }

    public function test_обычный_турнир_без_лиги(): void
    {
        $plain = Tournament::create([
            'club_id' => $this->club->id,
            'name' => 'Обычный турнир', 'type' => 'americano', 'status' => 'open',
            'start_date' => '2026-09-10 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8, 'price' => 0,
        ]);

        $response = $this->actingAs($this->me, 'sanctum')
            ->getJson("/api/mobile/tournaments/{$plain->id}")
            ->assertOk();

        $this->assertNull($response->json('league') ?? $response->json('tournament.league'));
    }
}

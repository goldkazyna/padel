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
 * Лиги в мобильной админке: создание, этапы, состав.
 * Правила общие с веб-CRM — обе стороны зовут LeagueService.
 */
class MobileAdminLeagueTest extends TestCase
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

    public function test_лига_создаётся_с_телефона(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/mobile/admin/leagues', [
            'name' => 'Сентябрь Кап',
            'stages_planned' => 8,
            'max_players' => 12,
            'price' => 5000,
        ])->assertOk();

        $this->assertSame('Сентябрь Кап', $response->json('league.name'));
        $this->assertSame('open', $response->json('league.status'));
        $this->assertSame($this->club->id, League::first()->club_id);
    }

    public function test_список_лиг_клуба(): void
    {
        $this->league();
        $this->league(['name' => 'Октябрь Кап']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/mobile/admin/leagues')->assertOk();

        $this->assertCount(2, $response->json('leagues'));
    }

    public function test_этап_создаётся_и_забирает_состав(): void
    {
        $league = $this->league();
        $players = User::factory()->count(3)->create(['role' => 'player', 'level' => 3.0]);
        foreach ($players as $player) {
            LeaguePlayer::create([
                'league_id' => $league->id, 'user_id' => $player->id,
                'status' => 'registered', 'joined_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/mobile/admin/leagues/{$league->id}/stages", [
                'start_date' => '2026-09-05 19:00',
                'max_participants' => 12,
            ])->assertOk();

        $stage = Tournament::find($response->json('tournament_id'));
        $this->assertSame('americano_flex', $stage->type);
        $this->assertSame(1, (int) $stage->league_stage);
        $this->assertSame(3, $stage->participants()->count(), 'состав лиги записан сразу');
        $this->assertSame('in_progress', $league->fresh()->status);
    }

    public function test_несыгранный_этап_удаляется_с_телефона(): void
    {
        $league = $this->league();

        $first = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/mobile/admin/leagues/{$league->id}/stages", [
                'start_date' => '2026-09-05 19:00', 'max_participants' => 12,
            ])->json('tournament_id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/mobile/admin/leagues/{$league->id}/stages", [
                'start_date' => '2026-09-12 19:00', 'max_participants' => 12,
            ]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/mobile/admin/leagues/{$league->id}/stages/{$first}")
            ->assertOk();

        $this->assertNull(Tournament::find($first));
        $this->assertSame(1, (int) Tournament::first()->league_stage, 'оставшийся стал первым');
    }

    public function test_завершённый_этап_с_телефона_не_удаляется(): void
    {
        $league = $this->league();

        $id = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/mobile/admin/leagues/{$league->id}/stages", [
                'start_date' => '2026-09-05 19:00', 'max_participants' => 12,
            ])->json('tournament_id');

        Tournament::find($id)->update(['status' => 'completed']);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/mobile/admin/leagues/{$league->id}/stages/{$id}")
            ->assertStatus(422);

        $this->assertNotNull(Tournament::find($id));
    }

    public function test_состав_ведётся_с_телефона(): void
    {
        $league = $this->league();
        $player = User::factory()->create(['role' => 'player', 'name' => 'Ерлан', 'level' => 3.0]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/mobile/admin/leagues/{$league->id}/players", ['user_id' => $player->id])
            ->assertOk();

        $this->assertSame(1, $league->activePlayers()->count());

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/mobile/admin/leagues/{$league->id}")->assertOk();
        $this->assertSame('Ерлан', $response->json('league.roster.0.name'));
        $this->assertSame(1, $response->json('league.players'), 'счётчик участников остаётся числом');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/mobile/admin/leagues/{$league->id}/players/{$player->id}")
            ->assertOk();

        $this->assertSame(0, $league->fresh()->activePlayers()->count());
        $this->assertSame('left', LeaguePlayer::first()->status, 'история лиги не переписывается');
    }

    public function test_поиск_игроков_умный(): void
    {
        $league = $this->league();
        User::factory()->create(['role' => 'player', 'name' => 'Denis Dudnikov', 'level' => 3.0]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/mobile/admin/leagues/{$league->id}/players/search?q=Дудников")
            ->assertOk();

        $this->assertSame('Denis Dudnikov', $response->json('players.0.name'));
        $this->assertArrayHasKey('avatar', $response->json('players.0'), 'аватар в поиске тоже нужен');
    }

    public function test_чужая_лига_недоступна(): void
    {
        $otherClub = Club::create(['name' => 'Другой', 'address' => 'Б', 'city' => 'Астана']);
        $league = $this->league(['club_id' => $otherClub->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/mobile/admin/leagues/{$league->id}")
            ->assertForbidden();
    }

    public function test_настройки_лиги_меняются(): void
    {
        $league = $this->league();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/mobile/admin/leagues/{$league->id}", [
                'name' => 'Сентябрь Кап 2026',
                'stages_planned' => 10,
                'status' => 'completed',
            ])->assertOk();

        $fresh = $league->fresh();
        $this->assertSame('Сентябрь Кап 2026', $fresh->name);
        $this->assertSame(10, $fresh->stages_planned);
        $this->assertSame('completed', $fresh->status);
    }
}

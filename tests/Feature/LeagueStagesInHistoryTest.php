<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\League;
use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Этапы лиги в истории турниров.
 *
 * Раньше архив их прятал: «этапы живут в своей лиге». Но человек их сыграл —
 * и в истории они должны быть, с местом, как у обычного турнира. Что это
 * этап, видно по полю league: приложение рисует метку «Лига · этап N».
 */
class LeagueStagesInHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $player;
    private Club $club;
    private League $league;

    protected function setUp(): void
    {
        parent::setUp();

        $this->player = User::factory()->create(['rating' => 1500]);
        $this->club = Club::create(['name' => 'Davay Padel', 'address' => 'А', 'city' => 'Алматы']);
        $this->league = League::create([
            'club_id' => $this->club->id,
            'name' => 'Осенняя лига',
            'status' => 'in_progress',
            'stages_planned' => 8,
        ]);
    }

    private function tournament(array $over = []): Tournament
    {
        $tournament = Tournament::factory()->create(array_merge([
            'club_id' => $this->club->id,
            'status' => 'completed',
            'type' => 'americano',
            'name' => 'Обычный американо',
            'start_date' => now()->subDays(3),
        ], $over));

        $tournament->participants()->attach($this->player->id, ['status' => 'registered']);

        return $tournament;
    }

    public function test_этап_лиги_есть_в_архиве_своего_профиля(): void
    {
        $plain = $this->tournament();
        $stage = $this->tournament([
            'name' => '2й этап ПАРНОЙ ЛИГИ',
            'league_id' => $this->league->id,
            'league_stage' => 2,
        ]);

        Sanctum::actingAs($this->player);

        $rows = $this->getJson('/api/mobile/tournaments/archive')->assertOk()->json('tournaments');
        $ids = array_column($rows, 'id');

        $this->assertContains($plain->id, $ids);
        $this->assertContains($stage->id, $ids, 'этап лиги в истории');

        $row = collect($rows)->firstWhere('id', $stage->id);
        $this->assertSame(2, $row['league']['stage']);
        $this->assertSame('Осенняя лига', $row['league']['name']);

        // У обычного турнира лиги нет — метку рисовать не по чему.
        $this->assertNull(collect($rows)->firstWhere('id', $plain->id)['league']);
    }

    public function test_этап_лиги_есть_в_истории_чужого_профиля(): void
    {
        $stage = $this->tournament([
            'name' => '3й этап ПАРНОЙ ЛИГИ',
            'league_id' => $this->league->id,
            'league_stage' => 3,
        ]);

        RatingHistory::create([
            'user_id' => $this->player->id,
            'tournament_id' => $stage->id,
            'rating_before' => 1480,
            'rating_after' => 1500,
            'change' => 20,
            'reason' => $stage->name,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $history = $this->getJson("/api/mobile/rating/player/{$this->player->id}")
            ->assertOk()
            ->json('history');

        $row = collect($history)->firstWhere('tournament_id', $stage->id);

        $this->assertNotNull($row, 'этап виден в чужом профиле');
        $this->assertSame(3, $row['league']['stage']);
        $this->assertSame(20, $row['change']);
    }

    public function test_у_обычного_турнира_в_чужом_профиле_лиги_нет(): void
    {
        $plain = $this->tournament();
        RatingHistory::create([
            'user_id' => $this->player->id,
            'tournament_id' => $plain->id,
            'rating_before' => 1480,
            'rating_after' => 1500,
            'change' => 20,
            'reason' => $plain->name,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $row = collect($this->getJson("/api/mobile/rating/player/{$this->player->id}")->json('history'))
            ->firstWhere('tournament_id', $plain->id);

        $this->assertNull($row['league']);
    }
}

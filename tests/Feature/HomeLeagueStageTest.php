<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\League;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Этап лиги в календаре главной.
 *
 * Этап — обычный турнир, и в общем списке он ничем не отличался от других:
 * человек записывался в лигу, не понимая этого. Теперь ответ несёт лигу, и
 * приложение рисует метку «Лига · этап 3».
 */
class HomeLeagueStageTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create([
            'name' => 'Davay Padel',
            'address' => 'А',
            'city' => 'Алматы',
            'is_test' => false,
        ]);
    }

    private function tournament(array $over = []): Tournament
    {
        return Tournament::factory()->create(array_merge([
            'club_id' => $this->club->id,
            'status' => 'open',
            'type' => 'americano',
            'start_date' => now()->addDays(2),
        ], $over));
    }

    public function test_этап_лиги_приходит_с_номером_и_названием(): void
    {
        $league = League::create([
            'club_id' => $this->club->id,
            'name' => 'Осенняя лига',
            'status' => 'in_progress',
            'stages_planned' => 8,
        ]);
        $stage = $this->tournament(['league_id' => $league->id, 'league_stage' => 3]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/mobile/home')->assertOk();
        $row = collect($response->json('upcoming_tournaments'))
            ->firstWhere('id', $stage->id);

        $this->assertNotNull($row, 'этап лиги виден в календаре');
        $this->assertSame(3, $row['league']['stage']);
        $this->assertSame(8, $row['league']['stages_total']);
        $this->assertSame('Осенняя лига', $row['league']['name']);
        $this->assertSame($league->id, $row['league']['id']);
    }

    public function test_у_обычного_турнира_лиги_нет(): void
    {
        $plain = $this->tournament();

        Sanctum::actingAs(User::factory()->create());

        $row = collect($this->getJson('/api/mobile/home')->json('upcoming_tournaments'))
            ->firstWhere('id', $plain->id);

        $this->assertNotNull($row);
        $this->assertNull($row['league']);
    }
}

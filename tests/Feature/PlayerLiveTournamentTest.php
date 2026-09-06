<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Идущий турнир в профиле игрока.
 *
 * В своём профиле активный турнир виден на главной, а в чужом узнать, что
 * человек прямо сейчас играет, было нечем — приходилось искать его в списке
 * турниров.
 */
class PlayerLiveTournamentTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $player;
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create();
        $this->player = User::factory()->create(['name' => 'Марина', 'rating' => 1315]);
        $this->club = Club::create(['name' => 'Davay Padel', 'address' => 'А', 'city' => 'Алматы']);
    }

    private function tournament(string $status): Tournament
    {
        $tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'status' => $status,
            'type' => 'americano',
            'name' => 'Вечерний американо',
        ]);
        $tournament->participants()->attach($this->player->id, ['status' => 'registered']);

        return $tournament;
    }

    public function test_идущий_турнир_приходит_в_профиль_игрока(): void
    {
        $tournament = $this->tournament('in_progress');

        Sanctum::actingAs($this->me);

        $this->getJson("/api/mobile/rating/player/{$this->player->id}")
            ->assertOk()
            ->assertJsonPath('live_tournament.id', $tournament->id)
            ->assertJsonPath('live_tournament.status', 'in_progress')
            ->assertJsonPath('live_tournament.club.name', 'Davay Padel');
    }

    public function test_если_турнир_ещё_не_начался_блока_нет(): void
    {
        $this->tournament('open');

        Sanctum::actingAs($this->me);

        $this->getJson("/api/mobile/rating/player/{$this->player->id}")
            ->assertOk()
            ->assertJsonPath('live_tournament', null);
    }

    public function test_чужой_идущий_турнир_в_профиль_не_попадает(): void
    {
        // Турнир идёт, но этот игрок в нём не участвует.
        $tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'status' => 'in_progress',
            'type' => 'americano',
        ]);
        $tournament->participants()->attach($this->me->id, ['status' => 'registered']);

        Sanctum::actingAs($this->me);

        $this->getJson("/api/mobile/rating/player/{$this->player->id}")
            ->assertOk()
            ->assertJsonPath('live_tournament', null);
    }
}

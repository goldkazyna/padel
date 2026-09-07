<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Пары видно до старта.
 *
 * В парном флексе (и в этапе лиги) люди записываются поодиночке, а пары
 * собирает организатор. Экран турнира отдавал только список участников —
 * человек не видел, с кем играет, хотя пара уже была собрана.
 */
class FlexPairsBeforeStartTest extends TestCase
{
    use RefreshDatabase;

    private Tournament $tournament;

    /** @var array<int, User> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $club = Club::create(['name' => 'Davay Padel', 'address' => 'А', 'city' => 'Алматы']);
        $this->tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano_flex',
            'status' => 'open',
            'is_paired' => true,
            'pairing_mode' => 'admin',
            'max_participants' => 8,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $u = User::factory()->create(['rating' => 2000 + $i * 10]);
            $this->tournament->participants()->attach($u->id, ['status' => 'registered']);
            $this->players[] = $u;
        }
    }

    private function detail(): array
    {
        Sanctum::actingAs($this->players[0]);

        return $this->getJson("/api/mobile/tournaments/{$this->tournament->id}")
            ->assertOk()
            ->json('tournament');
    }

    public function test_собранные_пары_приходят_в_детали_турнира(): void
    {
        TournamentTeam::create([
            'tournament_id' => $this->tournament->id,
            'player1_id' => $this->players[0]->id,
            'player2_id' => $this->players[1]->id,
            'status' => 'approved',
            'rating_avg' => 2005,
        ]);

        $data = $this->detail();

        $this->assertCount(1, $data['teams']);
        $this->assertSame($this->players[0]->id, $data['teams'][0]['player1']['id']);
        $this->assertSame($this->players[1]->id, $data['teams'][0]['player2']['id']);

        // Список участников остаётся полным: приложение само вычтет тех,
        // кто уже в паре, и покажет остальных как «без пары».
        $this->assertCount(5, $data['participants']);
    }

    public function test_пока_пар_нет_блок_пустой(): void
    {
        $data = $this->detail();

        $this->assertSame([], $data['teams']);
        $this->assertCount(5, $data['participants']);
    }

    public function test_когда_пары_собирают_сами_игроки_поведение_прежнее(): void
    {
        // Тут записываются сразу парой — участников как таковых нет.
        $this->tournament->update(['pairing_mode' => 'self', 'type' => 'team']);

        $data = $this->detail();

        $this->assertArrayHasKey('teams', $data);
        $this->assertArrayNotHasKey('participants', $data);
    }
}

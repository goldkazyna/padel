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
 * Игрок без пары виден в мобильной админке парного турнира.
 *
 * Экран состава показывает пары; человек, которому пары не нашлось, не попадал
 * в выдачу вообще — организатор не мог ни убрать его, ни поставить в пару.
 */
class MobileAdminUnpairedPlayerTest extends TestCase
{
    use RefreshDatabase;

    private Tournament $tournament;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $club = Club::create(['name' => 'JUST PADEL IT', 'address' => 'А']);

        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($club->id);

        $this->tournament = Tournament::create([
            'club_id' => $club->id, 'name' => 'COUPLES', 'type' => 'just_padel_it',
            'status' => 'open', 'start_date' => now()->addDay()->toDateString(),
            'courts_count' => 2, 'max_participants' => 12,
            'is_paired' => true, 'pairing_mode' => 'self',
            'min_level' => 0, 'max_level' => 10,
        ]);
    }

    private function participants(): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->admin);
        return $this->getJson("/api/mobile/admin/tournaments/{$this->tournament->id}/participants");
    }

    public function test_заявка_без_пары_приходит_отдельным_списком(): void
    {
        $a = User::factory()->create(['name' => 'Пара А1']);
        $b = User::factory()->create(['name' => 'Пара А2']);
        $alone = User::factory()->create(['name' => 'Aneli Schram']);

        TournamentTeam::create([
            'tournament_id' => $this->tournament->id,
            'player1_id' => $a->id, 'player2_id' => $b->id, 'status' => 'approved',
        ]);
        $this->tournament->participants()->attach($a->id, ['status' => 'registered']);
        $this->tournament->participants()->attach($b->id, ['status' => 'registered']);
        $this->tournament->participants()->attach($alone->id, ['status' => 'pending']);

        $this->participants()
            ->assertOk()
            ->assertJsonPath('type', 'team')
            ->assertJsonCount(1, 'unpaired')
            ->assertJsonPath('unpaired.0.name', 'Aneli Schram')
            ->assertJsonPath('unpaired.0.status', 'pending');
    }

    public function test_игрок_в_паре_в_список_без_пары_не_попадает(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        TournamentTeam::create([
            'tournament_id' => $this->tournament->id,
            'player1_id' => $a->id, 'player2_id' => $b->id, 'status' => 'approved',
        ]);
        $this->tournament->participants()->attach($a->id, ['status' => 'registered']);
        $this->tournament->participants()->attach($b->id, ['status' => 'registered']);

        $this->participants()->assertOk()->assertJsonCount(0, 'unpaired');
    }

    public function test_игрока_без_пары_можно_убрать(): void
    {
        $alone = User::factory()->create();
        $this->tournament->participants()->attach($alone->id, ['status' => 'pending']);

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/mobile/admin/tournaments/{$this->tournament->id}/participants/{$alone->id}")
            ->assertOk()->assertJsonPath('success', true);

        $this->participants()->assertJsonCount(0, 'unpaired');
    }
}

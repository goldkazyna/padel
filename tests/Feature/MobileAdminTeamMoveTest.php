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
 * Перевод пары между списками в мобильной админке.
 *
 * В одиночных турнирах админ давно двигает игроков между основным составом,
 * модерацией и листом ожидания. В парных он мог только одобрить или удалить —
 * вернуть пару в очередь было нечем.
 */
class MobileAdminTeamMoveTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function tournament(array $over = []): Tournament
    {
        return Tournament::factory()->create(array_merge([
            'club_id' => $this->club->id,
            'type' => 'just_padel_it',
            'is_paired' => true,
            'pairing_mode' => 'self',
            'status' => 'open',
            'max_participants' => 8,
        ], $over));
    }

    private function team(Tournament $t, string $status): TournamentTeam
    {
        return TournamentTeam::create([
            'tournament_id' => $t->id,
            'player1_id' => User::factory()->create()->id,
            'player2_id' => User::factory()->create()->id,
            'status' => $status,
            'rating_avg' => 1500,
        ]);
    }

    private function move(Tournament $t, TournamentTeam $team, string $to)
    {
        return $this->postJson(
            "/api/mobile/admin/tournaments/{$t->id}/teams/{$team->id}/move",
            ['to' => $to]
        );
    }

    public function test_пара_из_листа_ожидания_идёт_в_основной_состав(): void
    {
        $tournament = $this->tournament();
        $team = $this->team($tournament, 'waiting');
        Sanctum::actingAs($this->admin);

        $this->move($tournament, $team, 'approved')->assertOk();

        $this->assertSame('approved', $team->fresh()->status);
    }

    public function test_пару_можно_вернуть_в_лист_ожидания(): void
    {
        $tournament = $this->tournament();
        $team = $this->team($tournament, 'approved');
        Sanctum::actingAs($this->admin);

        $this->move($tournament, $team, 'waiting')->assertOk();

        $this->assertSame('waiting', $team->fresh()->status);
    }

    public function test_перевод_на_модерацию_ставит_таймер(): void
    {
        $tournament = $this->tournament(['moderation_hours' => 2]);
        $team = $this->team($tournament, 'waiting');
        Sanctum::actingAs($this->admin);

        $this->move($tournament, $team, 'pending')->assertOk();

        $fresh = $team->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNotNull($fresh->moderation_deadline, 'на модерации у пары есть срок');
    }

    public function test_в_полный_состав_пару_не_пускаем(): void
    {
        // 4 пары — это 8 человек, лимит турнира.
        $tournament = $this->tournament(['max_participants' => 8]);
        foreach (range(1, 4) as $i) {
            $this->team($tournament, 'approved');
        }
        $waiting = $this->team($tournament, 'waiting');

        Sanctum::actingAs($this->admin);

        $this->move($tournament, $waiting, 'approved')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('waiting', $waiting->fresh()->status);
    }

    public function test_повторный_перевод_в_тот_же_список_не_ошибка(): void
    {
        $tournament = $this->tournament();
        $team = $this->team($tournament, 'approved');
        Sanctum::actingAs($this->admin);

        $this->move($tournament, $team, 'approved')->assertOk();
        $this->assertSame('approved', $team->fresh()->status);
    }

    public function test_чужой_турнир_не_трогаем(): void
    {
        $tournament = $this->tournament();
        $team = $this->team($tournament, 'waiting');

        $stranger = User::factory()->create(['role' => 'club_admin']);
        $otherClub = Club::create(['name' => 'Другой', 'address' => 'Б']);
        $stranger->adminClubs()->attach($otherClub->id);
        Sanctum::actingAs($stranger);

        $this->move($tournament, $team, 'approved')->assertForbidden();
        $this->assertSame('waiting', $team->fresh()->status);
    }

    public function test_после_старта_составы_не_меняем(): void
    {
        $tournament = $this->tournament(['status' => 'in_progress']);
        $team = $this->team($tournament, 'waiting');
        Sanctum::actingAs($this->admin);

        $this->move($tournament, $team, 'approved')->assertStatus(422);
        $this->assertSame('waiting', $team->fresh()->status);
    }
}

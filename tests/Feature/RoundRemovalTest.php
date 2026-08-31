<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\JustPadelItMatch;
use App\Models\JustPadelItRound;
use App\Models\Tournament;
use App\Models\User;
use App\Services\RoundRemovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Удаление лишнего раунда.
 *
 * «Следующий раунд» жмут на один раз больше, чем нужно: турнир доигран, а в
 * таблице висит пустой раунд, и завершить турнир нельзя.
 */
class RoundRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;
    private Tournament $tournament;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        $this->tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'type' => 'just_padel_it',
            'status' => 'in_progress',
            'courts_count' => 2,
            'max_participants' => 8,
        ]);
    }

    private function round(int $number, string $status = 'completed', bool $withScore = true): JustPadelItRound
    {
        $round = JustPadelItRound::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => $number,
            'status' => $status,
        ]);

        $players = User::factory()->count(4)->create();
        JustPadelItMatch::create([
            'just_padel_it_round_id' => $round->id,
            'court_number' => 1,
            'team1_player1_id' => $players[0]->id,
            'team1_player2_id' => $players[1]->id,
            'team2_player1_id' => $players[2]->id,
            'team2_player2_id' => $players[3]->id,
            'team1_score' => $withScore ? 16 : null,
            'team2_score' => $withScore ? 10 : null,
            'status' => $withScore ? 'completed' : 'pending',
        ]);

        return $round;
    }

    public function test_пустой_последний_раунд_удаляется(): void
    {
        $first = $this->round(1);
        $extra = $this->round(2, 'in_progress', withScore: false);

        $this->actingAs($this->admin)
            ->delete(route('club.tournaments.rounds.remove', [$this->tournament, $extra->id]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull(JustPadelItRound::find($extra->id));
        $this->assertSame(0, JustPadelItMatch::where('just_padel_it_round_id', $extra->id)->count(),
            'матчи раунда уходят вместе с ним');
        $this->assertNotNull(JustPadelItRound::find($first->id), 'сыгранный раунд цел');
    }

    public function test_раунд_со_счётом_не_удаляется(): void
    {
        $this->round(1);
        $played = $this->round(2, 'completed');

        $this->actingAs($this->admin)
            ->delete(route('club.tournaments.rounds.remove', [$this->tournament, $played->id]))
            ->assertSessionHas('error');

        $this->assertNotNull(JustPadelItRound::find($played->id));
    }

    public function test_середину_вынуть_нельзя(): void
    {
        $first = $this->round(1);
        $this->round(2, 'in_progress', withScore: false);

        // Пустой, но не последний: удаление развалит нумерацию и ротацию.
        $this->actingAs($this->admin)
            ->delete(route('club.tournaments.rounds.remove', [$this->tournament, $first->id]))
            ->assertSessionHas('error');

        $this->assertNotNull(JustPadelItRound::find($first->id));
    }

    public function test_недоигранный_предыдущий_раунд_снова_текущий(): void
    {
        // Раунд был открыт, сверху нажали «следующий» — после удаления
        // лишнего он снова становится текущим, иначе играть некуда.
        $first = $this->round(1, 'in_progress', withScore: false);
        $extra = $this->round(2, 'in_progress', withScore: false);

        app(RoundRemovalService::class)->remove($this->tournament, $extra);

        $this->assertSame('in_progress', $first->fresh()->status);
    }

    public function test_доигранный_предыдущий_раунд_остаётся_завершённым(): void
    {
        // Турнир доигран, лишний раунд убрали — предыдущий не должен
        // «открываться» заново, иначе турнир снова нельзя завершить.
        $first = $this->round(1, 'completed');
        $extra = $this->round(2, 'in_progress', withScore: false);

        app(RoundRemovalService::class)->remove($this->tournament, $extra);

        $this->assertSame('completed', $first->fresh()->status);
    }

    public function test_в_завершённом_турнире_раунды_не_трогаем(): void
    {
        $this->round(1);
        $extra = $this->round(2, 'in_progress', withScore: false);
        $this->tournament->update(['status' => 'completed']);

        [$ok, $message] = app(RoundRemovalService::class)
            ->remove($this->tournament->fresh(), $extra);

        $this->assertFalse($ok);
        $this->assertStringContainsString('завершён', $message);
    }

    public function test_удаление_с_телефона(): void
    {
        $this->round(1);
        $extra = $this->round(2, 'in_progress', withScore: false);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/mobile/admin/tournaments/{$this->tournament->id}/rounds/{$extra->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull(JustPadelItRound::find($extra->id));
    }

    public function test_чужой_раунд_не_удалить(): void
    {
        $other = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'type' => 'just_padel_it',
            'status' => 'in_progress',
        ]);
        $foreign = JustPadelItRound::create([
            'tournament_id' => $other->id, 'round_number' => 1, 'status' => 'in_progress',
        ]);

        [$ok, $message] = app(RoundRemovalService::class)->remove($this->tournament, $foreign);

        $this->assertFalse($ok);
        $this->assertStringContainsString('не из этого турнира', $message);
    }
}

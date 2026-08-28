<?php

namespace Tests\Feature;

use App\Models\AmericanoMatch;
use App\Models\AmericanoRound;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\User;
use App\Support\CountedMatches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Что идёт в винрейт: только матчи завершённых турниров, ничьи — не поражение.
 *
 * До правки в статистику попадали матчи отменённых и ещё идущих турниров:
 * у игрока с небольшим налётом это меняло процент на десятки пунктов.
 */
class WinrateCountingTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $player;
    private User $partner;
    private User $rival1;
    private User $rival2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $this->player = User::factory()->create(['role' => 'player', 'name' => 'Игрок']);
        $this->partner = User::factory()->create(['role' => 'player']);
        $this->rival1 = User::factory()->create(['role' => 'player']);
        $this->rival2 = User::factory()->create(['role' => 'player']);
    }

    /** Турнир с одним матчем нашего игрока: счёт задаём явно. */
    private function match(string $tournamentStatus, int $myScore, int $theirScore): AmericanoMatch
    {
        $tournament = Tournament::create([
            'club_id' => $this->club->id, 'name' => 'Турнир', 'type' => 'americano',
            'status' => $tournamentStatus, 'start_date' => '2026-08-20 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);
        $group = TournamentGroup::create(['tournament_id' => $tournament->id, 'name' => 'A']);
        $round = AmericanoRound::create(['tournament_group_id' => $group->id, 'round_number' => 1]);

        return AmericanoMatch::create([
            'americano_round_id' => $round->id,
            'court_number' => 1,
            'team1_player1_id' => $this->player->id,
            'team1_player2_id' => $this->partner->id,
            'team2_player1_id' => $this->rival1->id,
            'team2_player2_id' => $this->rival2->id,
            'team1_score' => $myScore,
            'team2_score' => $theirScore,
            'status' => 'completed',
        ]);
    }

    public function test_матчи_отменённого_турнира_не_считаются(): void
    {
        $this->match('completed', 10, 5);   // победа
        $this->match('cancelled', 2, 9);    // поражение, которого быть не должно

        $stats = $this->player->fresh()->getAllMatchesStats();

        $this->assertSame(1, $stats['total'], 'в зачёте только завершённый турнир');
        $this->assertSame(1, $stats['won']);
        $this->assertSame(0, $stats['lost']);
        $this->assertSame(100.0, $this->player->fresh()->winRate());
    }

    public function test_матчи_идущего_турнира_не_считаются(): void
    {
        $this->match('completed', 10, 5);
        $this->match('in_progress', 1, 10);

        $stats = $this->player->fresh()->getAllMatchesStats();

        $this->assertSame(1, $stats['total'], 'турнир ещё идёт — рейтинг тоже ждёт его конца');
    }

    public function test_ничья_не_считается_поражением(): void
    {
        $this->match('completed', 10, 5);   // победа
        $this->match('completed', 7, 7);    // ничья

        $stats = $this->player->fresh()->getAllMatchesStats();

        $this->assertSame(2, $stats['total'], 'ничья — сыгранный матч');
        $this->assertSame(1, $stats['won']);
        $this->assertSame(1, $stats['draw']);
        $this->assertSame(100.0, $this->player->fresh()->winRate(),
            'одна победа и одна ничья — это 100%, а не 50%');
    }

    public function test_винрейт_без_решающих_матчей_равен_нулю(): void
    {
        $this->match('completed', 7, 7);

        $this->assertSame(0.0, $this->player->fresh()->winRate(), 'делить не на что');
    }

    public function test_формула_винрейта(): void
    {
        $this->assertSame(50, CountedMatches::winrate(5, 5));
        $this->assertSame(75, CountedMatches::winrate(3, 1));
        $this->assertSame(0, CountedMatches::winrate(0, 0), 'без матчей — ноль, а не деление на ноль');
        $this->assertSame(100, CountedMatches::winrate(4, 0));
    }

    public function test_история_матчей_тоже_без_отменённых(): void
    {
        $this->match('completed', 10, 5);
        $this->match('cancelled', 2, 9);

        $history = app(\App\Services\PlayerMatchHistory::class)->for($this->player->fresh());

        $this->assertCount(1, $history, 'в истории матчей отменённого турнира тоже быть не должно');
        $this->assertSame('win', $history[0]['result']);
    }

    public function test_профиль_отдаёт_тот_же_винрейт(): void
    {
        $this->match('completed', 10, 5);
        $this->match('completed', 7, 7);
        $this->match('completed', 3, 9);
        $this->match('cancelled', 1, 9);

        $response = $this->actingAs($this->player, 'sanctum')->getJson('/api/mobile/profile');

        $response->assertOk()
            ->assertJsonPath('statistics.matches_played', 3)
            ->assertJsonPath('statistics.wins', 1)
            ->assertJsonPath('statistics.losses', 1)
            ->assertJsonPath('statistics.draws', 1)
            ->assertJsonPath('statistics.winrate', 50);
    }
}

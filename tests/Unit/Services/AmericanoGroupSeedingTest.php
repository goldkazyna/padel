<?php

namespace Tests\Unit\Services;

use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\AmericanoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmericanoGroupSeedingTest extends TestCase
{
    use RefreshDatabase;

    private AmericanoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AmericanoService();
    }

    /** 8 игроков с убывающим рейтингом, 2 группы, без заранее созданных групп. */
    private function makeTournament(array $ratings): Tournament
    {
        $tournament = Tournament::factory()->create([
            'type' => 'americano',
            'status' => 'open',
            'groups_count' => 2,
            'max_participants' => count($ratings),
            'rounds_count' => 1,
        ]);

        foreach ($ratings as $i => $rating) {
            $user = User::factory()->create(['rating' => $rating, 'name' => 'P' . ($i + 1)]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
        }

        return $tournament->fresh();
    }

    public function test_two_groups_are_seeded_snake_not_stratified(): void
    {
        // Рейтинги: ранги 1..8 = 2000,1900,...,1300.
        $ratings = [2000, 1900, 1800, 1700, 1600, 1500, 1400, 1300];
        $tournament = $this->makeTournament($ratings);

        $this->assertTrue($this->service->startTournament($tournament->fresh()));

        $groups = $tournament->fresh()->groups()->with('players')->get();
        $this->assertCount(2, $groups);

        // Равные размеры.
        $this->assertSame(4, $groups[0]->players->count());
        $this->assertSame(4, $groups[1]->players->count());

        // Суммы рейтингов групп равны (баланс змейкой). Стратификация дала бы 7400 vs 5800.
        $sum = fn ($g) => $g->players->sum(fn ($p) => (int) $p->pivot->rating_before);
        $this->assertSame($sum($groups[0]), $sum($groups[1]),
            'Группы должны быть равны по сумме рейтингов (змейка)');

        // Два сильнейших — в РАЗНЫХ группах (ключевое отличие от стратификации).
        $groupOfRating = [];
        foreach ($groups as $gi => $g) {
            foreach ($g->players as $p) {
                $groupOfRating[(int) $p->pivot->rating_before] = $gi;
            }
        }
        $this->assertNotSame($groupOfRating[2000], $groupOfRating[1900],
            'Топ-1 и топ-2 по рейтингу должны быть в разных группах');
    }

    public function test_all_players_assigned_to_some_group(): void
    {
        $ratings = [2000, 1900, 1800, 1700, 1600, 1500, 1400, 1300];
        $tournament = $this->makeTournament($ratings);
        $this->service->startTournament($tournament->fresh());

        $assigned = $tournament->fresh()->groups()->with('players')->get()
            ->pluck('players')->flatten()->pluck('id')->unique();
        $this->assertCount(8, $assigned, 'Все 8 игроков должны быть распределены');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\KingOfCourtMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\KingOfCourtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Первый раунд «Короля корта» сеется по рейтингу:
 * корт 1 — топ-4 (или 2 сильнейшие пары), корт 2 — следующие, и т.д.
 */
class KingOfCourtSeedingTest extends TestCase
{
    use RefreshDatabase;

    private function tournament(bool $paired, int $max): Tournament
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        return Tournament::create([
            'club_id' => $club->id,
            'name' => 'KOC',
            'type' => 'king_of_court',
            'is_paired' => $paired,
            'start_date' => now()->addDay(),
            'min_level' => 1,
            'max_level' => 5.75,
            'max_participants' => $max,
            'status' => 'open',
            'is_rated' => true,
        ]);
    }

    /** @return array<int,int> отсортированные id 4 игроков матча */
    private function courtIds(KingOfCourtMatch $m): array
    {
        $ids = [
            $m->team1_player1_id, $m->team1_player2_id,
            $m->team2_player1_id, $m->team2_player2_id,
        ];
        sort($ids);
        return $ids;
    }

    public function test_solo_first_round_top4_on_court_one(): void
    {
        // Порядок регистрации намеренно перемешан — сортировка должна идти по рейтингу.
        $ratings = [1400, 2000, 1600, 1300, 1900, 1500, 1800, 1700];
        $t = $this->tournament(false, count($ratings));
        $u = [];
        foreach ($ratings as $r) {
            $user = User::factory()->create(['rating' => $r]);
            $t->participants()->attach($user->id, ['status' => 'registered']);
            $u[$r] = $user;
        }

        $this->assertTrue((new KingOfCourtService())->startTournament($t->fresh()));

        $round = $t->fresh()->kingOfCourtRounds()->where('round_number', 1)->firstOrFail();
        $matches = KingOfCourtMatch::where('kingofcourt_round_id', $round->id)
            ->orderBy('court_number')->get();
        $this->assertCount(2, $matches);

        $expectC1 = collect([2000, 1900, 1800, 1700])->map(fn ($r) => $u[$r]->id)->sort()->values()->all();
        $expectC2 = collect([1600, 1500, 1400, 1300])->map(fn ($r) => $u[$r]->id)->sort()->values()->all();

        $this->assertSame($expectC1, $this->courtIds($matches[0]), 'Корт 1 — топ-4 по рейтингу');
        $this->assertSame($expectC2, $this->courtIds($matches[1]), 'Корт 2 — следующие 4');

        // Детерминированно: первый игрок корта 1 — сильнейший.
        $this->assertSame($u[2000]->id, $matches[0]->team1_player1_id);
    }

    public function test_solo_initial_leaderboard_ordered_by_rating(): void
    {
        // Порядок регистрации перемешан; матчей нет → таблица должна идти по рейтингу.
        $ratings = [1400, 2000, 1600, 1300, 1900, 1500, 1800, 1700];
        $t = $this->tournament(false, count($ratings));
        foreach ($ratings as $r) {
            $u = User::factory()->create(['rating' => $r]);
            $t->participants()->attach($u->id, ['status' => 'registered']);
        }
        (new KingOfCourtService())->startTournament($t->fresh());

        $controller = new \App\Http\Controllers\Api\MobileAdminTournamentDetailController();
        $method = new \ReflectionMethod($controller, 'buildKingOfCourtLeaderboard');
        $method->setAccessible(true);
        $rows = $method->invoke($controller, $t->fresh());

        $ratingsInOrder = array_map(fn ($row) => $row['rating'], $rows);
        $this->assertSame(
            [2000, 1900, 1800, 1700, 1600, 1500, 1400, 1300],
            $ratingsInOrder,
            'Стартовая таблица КК должна идти по рейтингу DESC'
        );
        $this->assertSame(1, $rows[0]['position']);
    }

    public function test_paired_first_round_strongest_pairs_on_court_one(): void
    {
        $t = $this->tournament(true, 8);

        // 4 пары с известной суммой рейтингов: A=4000, B=3600, C=3200, D=2800.
        $mk = function (int $r) use ($t) {
            $u = User::factory()->create(['rating' => $r]);
            $t->participants()->attach($u->id, ['status' => 'registered']);
            return $u;
        };
        $A = [$mk(2000), $mk(2000)];
        $B = [$mk(1800), $mk(1800)];
        $C = [$mk(1600), $mk(1600)];
        $D = [$mk(1400), $mk(1400)];

        $svc = new KingOfCourtService();
        [$ok] = $svc->createPairs($t->fresh(), [
            [$A[0]->id, $A[1]->id],
            [$B[0]->id, $B[1]->id],
            [$C[0]->id, $C[1]->id],
            [$D[0]->id, $D[1]->id],
        ]);
        $this->assertTrue($ok);
        $this->assertTrue($svc->startTournament($t->fresh()));

        $round = $t->fresh()->kingOfCourtRounds()->where('round_number', 1)->firstOrFail();
        $matches = KingOfCourtMatch::where('kingofcourt_round_id', $round->id)
            ->orderBy('court_number')->get();
        $this->assertCount(2, $matches);

        // Корт 1 — две сильнейшие пары A(4000) и B(3600).
        $expect1 = collect([$A[0], $A[1], $B[0], $B[1]])->map(fn ($x) => $x->id)->sort()->values()->all();
        $this->assertSame($expect1, $this->courtIds($matches[0]), 'Корт 1 — две сильнейшие пары');
    }
}

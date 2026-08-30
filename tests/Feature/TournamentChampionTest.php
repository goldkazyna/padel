<?php

namespace Tests\Feature;

use App\Models\AmericanoFlexMatch;
use App\Models\AmericanoFlexPlayer;
use App\Models\AmericanoFlexRound;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\AmericanoMatch;
use App\Models\AmericanoRound;
use App\Models\User;
use App\Support\TournamentChampion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Победа в турнире считается тем же кодом, что и место в профиле.
 *
 * Раньше значки считали победы своим кодом: у Американо победитель не
 * определялся вовсе, и игрок с первым местом в карточке видел
 * «Выиграть турнир 0/1».
 */
class TournamentChampionTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
    }

    /** Американо с одной группой: четверо, два матча. */
    private function americano(array $users, array $scores): Tournament
    {
        $t = Tournament::create([
            'club_id' => $this->club->id, 'name' => 'Американо', 'type' => 'americano',
            'status' => 'completed', 'is_rated' => true, 'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 4,
        ]);

        $group = TournamentGroup::create(['tournament_id' => $t->id, 'name' => 'A', 'group_number' => 1]);
        foreach ($users as $u) {
            $group->players()->attach($u->id);
            $t->participants()->attach($u->id, ['status' => 'registered']);
        }

        $round = AmericanoRound::create([
            'tournament_group_id' => $group->id,
            'round_number' => 1, 'status' => 'completed',
        ]);

        foreach ($scores as [$a, $b, $c, $d, $s1, $s2]) {
            AmericanoMatch::create([
                'americano_round_id' => $round->id,
                'court_number' => 1,
                'team1_player1_id' => $users[$a]->id, 'team1_player2_id' => $users[$b]->id,
                'team2_player1_id' => $users[$c]->id, 'team2_player2_id' => $users[$d]->id,
                'team1_score' => $s1, 'team2_score' => $s2, 'status' => 'completed',
            ]);
        }

        return $t;
    }

    public function test_победа_в_американо_попадает_в_статистику(): void
    {
        $users = User::factory()->count(4)->create()->all();

        // Первый забивает больше всех: 21 + 21.
        $t = $this->americano($users, [
            [0, 1, 2, 3, 21, 10],
            [0, 2, 1, 3, 21, 12],
        ]);

        $this->assertTrue(TournamentChampion::is($t, $users[0]->id));
        $this->assertFalse(TournamentChampion::is($t, $users[3]->id));

        $stats = $users[0]->getTournamentStats();
        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['wins'], 'первое место в профиле = победа в значках');

        $this->assertSame(0, $users[3]->getTournamentStats()['wins']);
    }

    public function test_проигравший_американо_побед_не_получает(): void
    {
        $users = User::factory()->count(4)->create()->all();
        $t = $this->americano($users, [[0, 1, 2, 3, 21, 10]]);

        $this->assertFalse(TournamentChampion::is($t, $users[2]->id));
    }

    public function test_флекс_чемпион_по_среднему(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $t = Tournament::create([
            'club_id' => $this->club->id, 'name' => 'Flex', 'type' => 'americano_flex',
            'status' => 'completed', 'is_rated' => true, 'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);

        $round = AmericanoFlexRound::create([
            'tournament_id' => $t->id, 'round_number' => 1, 'status' => 'completed',
        ]);
        $partner = User::factory()->create();
        $rival = User::factory()->create();
        foreach ([$user, $other, $partner, $rival] as $u) {
            AmericanoFlexPlayer::create(['tournament_id' => $t->id, 'user_id' => $u->id]);
        }
        AmericanoFlexMatch::create([
            'americano_flex_round_id' => $round->id, 'court_number' => 1,
            'team1_player1_id' => $user->id, 'team1_player2_id' => $partner->id,
            'team2_player1_id' => $other->id, 'team2_player2_id' => $rival->id,
            'team1_score' => 16, 'team2_score' => 8, 'status' => 'completed',
        ]);

        $this->assertTrue(TournamentChampion::is($t, $user->id));
        $this->assertFalse(TournamentChampion::is($t, $other->id));
    }
}

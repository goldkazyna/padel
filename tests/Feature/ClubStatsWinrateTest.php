<?php

namespace Tests\Feature;

use App\Models\AmericanoFlexMatch;
use App\Models\AmericanoFlexRound;
use App\Models\Club;
use App\Models\JustPadelItMatch;
use App\Models\JustPadelItRound;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Винрейт в статистике клуба.
 *
 * Ничьи в Американо и Флексе — обычное дело: матч идёт до фиксированного
 * счёта и часто заканчивается поровну. Пока они выпадали из подсчёта,
 * экран показывал 88% там, где игрок выиграл 7 матчей из 15.
 */
class ClubStatsWinrateTest extends TestCase
{
    use RefreshDatabase;

    private function makeClubTournament(string $type): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $tournament = Tournament::create([
            'club_id' => $club->id, 'name' => 'Турнир', 'type' => $type,
            'status' => 'completed', 'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);

        return [$club, $tournament];
    }

    private function player(string $name): User
    {
        return User::factory()->create(['role' => 'player', 'name' => $name]);
    }

    private function stats(Club $club, User $user): ?array
    {
        $admin = User::factory()->create(['role' => 'player']);
        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/mobile/clubs/{$club->id}/stats?from="
                . now()->subMonth()->toDateString() . '&to=' . now()->addDay()->toDateString());

        $response->assertOk();

        foreach ($response->json('players') as $row) {
            if ((int) $row['user']['id'] === $user->id) {
                return $row;
            }
        }

        return null;
    }

    public function test_ничьи_входят_в_знаменатель_винрейта(): void
    {
        // Случай с боевого сервера: 15 матчей — 7 побед, 1 поражение, 7 ничьих.
        [$club, $tournament] = $this->makeClubTournament('americano_flex');
        $me = $this->player('Emma');
        $mate = $this->player('Партнёр');
        $rivals = [$this->player('Соперник 1'), $this->player('Соперник 2')];
        TournamentParticipant::create([
            'tournament_id' => $tournament->id, 'user_id' => $me->id, 'status' => 'registered',
        ]);

        $round = AmericanoFlexRound::create([
            'tournament_id' => $tournament->id, 'round_number' => 1, 'status' => 'completed',
        ]);

        $scores = array_merge(
            array_fill(0, 7, [21, 15]),   // победы
            [[15, 21]],                   // поражение
            array_fill(0, 7, [18, 18])    // ничьи
        );
        foreach ($scores as $i => [$a, $b]) {
            AmericanoFlexMatch::create([
                'americano_flex_round_id' => $round->id, 'court_number' => 1,
                'team1_player1_id' => $me->id, 'team1_player2_id' => $mate->id,
                'team2_player1_id' => $rivals[0]->id, 'team2_player2_id' => $rivals[1]->id,
                'team1_score' => $a, 'team2_score' => $b, 'status' => 'completed',
            ]);
        }

        $row = $this->stats($club, $me);

        $this->assertNotNull($row, 'игрок должен попасть в статистику клуба');
        $this->assertSame(7, $row['wins']);
        $this->assertSame(1, $row['losses']);
        $this->assertSame(7, $row['draws'], 'ничьи должны считаться отдельно');
        $this->assertSame(47, $row['winrate'], '7 побед из 15 матчей — это 47%, а не 88%');
    }

    public function test_без_ничьих_винрейт_как_прежде(): void
    {
        [$club, $tournament] = $this->makeClubTournament('americano_flex');
        $me = $this->player('Игрок');
        $mate = $this->player('Партнёр');
        $rivals = [$this->player('Соперник 1'), $this->player('Соперник 2')];

        $round = AmericanoFlexRound::create([
            'tournament_id' => $tournament->id, 'round_number' => 1, 'status' => 'completed',
        ]);
        foreach ([[21, 10], [21, 12], [10, 21], [11, 21]] as [$a, $b]) {
            AmericanoFlexMatch::create([
                'americano_flex_round_id' => $round->id, 'court_number' => 1,
                'team1_player1_id' => $me->id, 'team1_player2_id' => $mate->id,
                'team2_player1_id' => $rivals[0]->id, 'team2_player2_id' => $rivals[1]->id,
                'team1_score' => $a, 'team2_score' => $b, 'status' => 'completed',
            ]);
        }

        $row = $this->stats($club, $me);

        $this->assertSame(50, $row['winrate']);
        $this->assertSame(0, $row['draws']);
    }

    public function test_матчи_just_padel_it_попадают_в_статистику(): void
    {
        // Раньше матчей этого формата в подсчёте не было вовсе, и игроки
        // таких турниров висели с нулевым винрейтом.
        [$club, $tournament] = $this->makeClubTournament('just_padel_it');
        $me = $this->player('Игрок JPI');
        $mate = $this->player('Партнёр');
        $rivals = [$this->player('Соперник 1'), $this->player('Соперник 2')];

        $round = JustPadelItRound::create([
            'tournament_id' => $tournament->id, 'round_number' => 1, 'status' => 'completed',
        ]);
        foreach ([[21, 10], [21, 12], [10, 21]] as [$a, $b]) {
            JustPadelItMatch::create([
                'just_padel_it_round_id' => $round->id, 'court_number' => 1,
                'team1_player1_id' => $me->id, 'team1_player2_id' => $mate->id,
                'team2_player1_id' => $rivals[0]->id, 'team2_player2_id' => $rivals[1]->id,
                'team1_score' => $a, 'team2_score' => $b, 'status' => 'completed',
            ]);
        }

        $row = $this->stats($club, $me);

        $this->assertNotNull($row, 'игрок Just Padel It должен попадать в статистику клуба');
        $this->assertSame(2, $row['wins']);
        $this->assertSame(1, $row['losses']);
        $this->assertSame(67, $row['winrate']);
    }
}

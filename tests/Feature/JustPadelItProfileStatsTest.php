<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use App\Services\JustPadelItService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Турниры Just Padel It в статистике профиля.
 *
 * Раньше формат не был подключён к User::getTournamentStats(), и сыгранные
 * JPI не попадали ни в счётчик турниров, ни в победы, ни в разбивку по типам —
 * игрок с одними только JPI видел у себя ноль.
 */
class JustPadelItProfileStatsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tournament, 1: array<int, User>} */
    private function playedTournament(bool $paired = false, bool $byWins = false, bool $rated = true): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'JPI', 'type' => 'just_padel_it',
            'status' => 'open', 'start_date' => now()->subDay()->toDateString(),
            'courts_count' => 2, 'max_participants' => 8,
            'is_rated' => $rated, 'is_paired' => $paired,
            'jpi_rank_by_wins' => $byWins,
        ]);

        $users = [];
        for ($i = 1; $i <= 8; $i++) {
            $u = User::factory()->create(['name' => "P{$i}", 'rating' => 2000 - $i * 10]);
            $t->participants()->attach($u->id, ['status' => 'registered']);
            $users[] = $u;
        }

        $svc = app(JustPadelItService::class);

        if ($paired) {
            // Пары по порядку посева: 1-2, 3-4, 5-6, 7-8.
            // createPairs ждёт пары списком: [[id1, id2], ...]
            [$ok, $msg] = $svc->createPairs($t, [
                [$users[0]->id, $users[1]->id],
                [$users[2]->id, $users[3]->id],
                [$users[4]->id, $users[5]->id],
                [$users[6]->id, $users[7]->id],
            ]);
            $this->assertTrue($ok, 'пары не созданы: ' . $msg);
        }

        $svc->startTournament($t);
        foreach ($t->fresh()->justPadelItRounds()->first()->matches as $m) {
            $svc->saveMatchResult($m, 8, 4);
        }
        $svc->finishTournament($t->fresh());

        return [$t->fresh(), $users];
    }

    public function test_solo_tournament_counts_in_profile(): void
    {
        [, $users] = $this->playedTournament();

        $stats = $users[0]->fresh()->getTournamentStats();

        $this->assertSame(1, $stats['total'], 'турнир попал в счётчик');
        $this->assertSame(1, $stats['by_type']['just_padel_it'] ?? 0);
    }

    public function test_winner_gets_a_win(): void
    {
        [$t, $users] = $this->playedTournament();

        // Кто первый в таблице — тот и должен получить победу в профиле.
        $rows = $t->justPadelItPlayers->map(fn($jp) => [
            'user_id' => (int) $jp->user_id,
            'total_points' => (int) $jp->total_points,
            'wins' => (int) $jp->wins,
            'diff' => (int) $jp->points_for - (int) $jp->points_against,
        ])->all();
        $rows = \App\Services\JustPadelItScoring::sortStandings($rows, false);
        $championId = $rows[0]['user_id'];

        $champion = collect($users)->firstWhere('id', $championId);
        $this->assertSame(1, $champion->fresh()->getTournamentStats()['wins']);

        // А тот, кто внизу таблицы, победы не получает.
        $lastId = $rows[count($rows) - 1]['user_id'];
        $last = collect($users)->firstWhere('id', $lastId);
        $this->assertSame(0, $last->fresh()->getTournamentStats()['wins']);
    }

    public function test_paired_tournament_counts_for_both_players_of_the_top_pair(): void
    {
        [$t, $users] = $this->playedTournament(paired: true);

        $standings = app(JustPadelItService::class)->getPairStandings($t);
        $topPair = $standings[0]['pair'];

        $p1 = collect($users)->firstWhere('id', (int) $topPair->player1_id);
        $p2 = collect($users)->firstWhere('id', (int) $topPair->player2_id);

        // Пара выигрывает вдвоём — победа засчитывается обоим.
        foreach ([$p1, $p2] as $player) {
            $stats = $player->fresh()->getTournamentStats();
            $this->assertSame(1, $stats['total']);
            $this->assertSame(1, $stats['wins'], "победа не засчитана игроку {$player->name}");
            $this->assertSame(1, $stats['by_type']['just_padel_it'] ?? 0);
        }
    }

    public function test_rank_by_wins_changes_who_is_the_champion(): void
    {
        // Режим зачёта решает, кто первый — статистика профиля должна
        // спрашивать тот же порядок, а не свой собственный.
        [$t, $users] = $this->playedTournament(byWins: true);

        $rows = $t->justPadelItPlayers->map(fn($jp) => [
            'user_id' => (int) $jp->user_id,
            'total_points' => (int) $jp->total_points,
            'wins' => (int) $jp->wins,
            'diff' => (int) $jp->points_for - (int) $jp->points_against,
        ])->all();
        $byWins = \App\Services\JustPadelItScoring::sortStandings($rows, true);

        $champion = collect($users)->firstWhere('id', $byWins[0]['user_id']);
        $this->assertSame(1, $champion->fresh()->getTournamentStats()['wins']);
    }

    public function test_unrated_tournament_is_not_counted(): void
    {
        // Нерейтинговые не считаются ни у одного формата — JPI не исключение.
        [, $users] = $this->playedTournament(rated: false);

        $stats = $users[0]->fresh()->getTournamentStats();

        $this->assertSame(0, $stats['total']);
        $this->assertArrayNotHasKey('just_padel_it', $stats['by_type']);
    }

    public function test_unfinished_tournament_is_not_counted(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'JPI', 'type' => 'just_padel_it',
            'status' => 'open', 'start_date' => now()->toDateString(),
            'courts_count' => 2, 'max_participants' => 8, 'is_rated' => true,
        ]);
        $users = [];
        for ($i = 1; $i <= 8; $i++) {
            $u = User::factory()->create(['name' => "P{$i}", 'rating' => 2000 - $i * 10]);
            $t->participants()->attach($u->id, ['status' => 'registered']);
            $users[] = $u;
        }
        app(JustPadelItService::class)->startTournament($t);

        $this->assertSame(0, $users[0]->fresh()->getTournamentStats()['total']);
    }
}

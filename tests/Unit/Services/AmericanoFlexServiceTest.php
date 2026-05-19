<?php

namespace Tests\Unit\Services;

use App\Models\AmericanoFlexPairHistory;
use App\Models\AmericanoFlexPlayer;
use App\Models\AmericanoFlexRound;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\AmericanoFlexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmericanoFlexServiceTest extends TestCase
{
    use RefreshDatabase;

    private AmericanoFlexService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AmericanoFlexService();
    }

    /** 10 игроков, 2 корта (courts_count=2). */
    private function makeTournament(int $playersCount = 10, int $courtsCount = 2): Tournament
    {
        $tournament = Tournament::factory()->create([
            'type' => 'americano_flex',
            'status' => 'open',
            'max_participants' => $playersCount,
            'courts_count' => $courtsCount,
        ]);

        for ($i = 1; $i <= $playersCount; $i++) {
            $user = User::factory()->create(['rating' => 1500]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
        }

        return $tournament;
    }

    public function test_start_tournament_creates_players(): void
    {
        $tournament = $this->makeTournament();
        $this->service->startTournament($tournament);

        $this->assertEquals('in_progress', $tournament->fresh()->status);
        $this->assertEquals(10, AmericanoFlexPlayer::where('tournament_id', $tournament->id)->count());
    }

    public function test_first_round_has_correct_matches_and_byes(): void
    {
        $tournament = $this->makeTournament(10, 2);
        $this->service->startTournament($tournament);

        $round = $tournament->americanoFlexRounds()->first();
        $this->assertNotNull($round);
        $this->assertEquals(1, $round->round_number);
        $this->assertEquals(2, $round->matches()->count(), 'на 2 кортах 2 матча');
        $this->assertEquals(2, $round->byes()->count(), '10 - 8 = 2 отдыхают');
    }

    public function test_bye_streak_increments_for_resting_players(): void
    {
        $tournament = $this->makeTournament(10, 2);
        $this->service->startTournament($tournament);

        $restingIds = $tournament->americanoFlexRounds()->first()->byes()->pluck('user_id');
        $resting = AmericanoFlexPlayer::where('tournament_id', $tournament->id)
            ->whereIn('user_id', $restingIds)
            ->get();
        foreach ($resting as $r) {
            $this->assertEquals(1, $r->bye_streak);
            $this->assertEquals(1, $r->bye_count);
        }
    }

    public function test_save_match_result_updates_points_and_history(): void
    {
        $tournament = $this->makeTournament(10, 2);
        $this->service->startTournament($tournament);

        $match = $tournament->americanoFlexRounds()->first()->matches()->first();
        $this->service->saveMatchResult($match, 24, 18);

        $match->refresh();
        $this->assertEquals('completed', $match->status);
        $this->assertEquals(24, $match->team1_score);

        $team1Player = AmericanoFlexPlayer::where('tournament_id', $tournament->id)
            ->where('user_id', $match->team1_player1_id)->first();
        $this->assertEquals(24, $team1Player->total_points);
        $this->assertEquals(1, $team1Player->matches_played);

        $team2Player = AmericanoFlexPlayer::where('tournament_id', $tournament->id)
            ->where('user_id', $match->team2_player1_id)->first();
        $this->assertEquals(18, $team2Player->total_points);
    }

    public function test_complete_tournament_calculates_elo(): void
    {
        $tournament = $this->makeTournament(10, 2);
        $this->service->startTournament($tournament);

        // Закрываем все матчи первого раунда
        $matches = $tournament->americanoFlexRounds()->first()->matches;
        foreach ($matches as $m) {
            $this->service->saveMatchResult($m, 24, 18);
        }

        $this->service->completeTournament($tournament);

        $this->assertEquals('completed', $tournament->fresh()->status);
        $players = AmericanoFlexPlayer::where('tournament_id', $tournament->id)->get();
        foreach ($players as $p) {
            $this->assertNotNull($p->rating_after, "у игрока {$p->user_id} должен быть rating_after");
        }
    }

    public function test_pairs_do_not_repeat_in_round_2(): void
    {
        $tournament = $this->makeTournament(10, 2);
        $this->service->startTournament($tournament);

        // Сохраняем счёт всех матчей раунда 1
        $round1 = $tournament->americanoFlexRounds()->first();
        foreach ($round1->matches as $m) {
            $this->service->saveMatchResult($m, 24, 18);
        }

        // Собираем пары партнёров раунда 1 (нормализованные)
        $pairsR1 = [];
        foreach ($round1->matches as $m) {
            $m->refresh();
            $p = AmericanoFlexPairHistory::normalizeIds($m->team1_player1_id, $m->team1_player2_id);
            $pairsR1[] = "{$p[0]}-{$p[1]}";
            $p = AmericanoFlexPairHistory::normalizeIds($m->team2_player1_id, $m->team2_player2_id);
            $pairsR1[] = "{$p[0]}-{$p[1]}";
        }

        // Генерируем раунд 2
        $this->service->generateNextRound($tournament);
        $round2 = $tournament->americanoFlexRounds()->where('round_number', 2)->first();

        $pairsR2 = [];
        foreach ($round2->matches as $m) {
            $p = AmericanoFlexPairHistory::normalizeIds($m->team1_player1_id, $m->team1_player2_id);
            $pairsR2[] = "{$p[0]}-{$p[1]}";
            $p = AmericanoFlexPairHistory::normalizeIds($m->team2_player1_id, $m->team2_player2_id);
            $pairsR2[] = "{$p[0]}-{$p[1]}";
        }

        // Никакая пара R1 не повторяется в R2
        $intersect = array_intersect($pairsR1, $pairsR2);
        $this->assertEmpty($intersect, "Партнёрские пары не должны повторяться в раунде 2: " . implode(', ', $intersect));
    }
}

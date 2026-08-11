<?php

namespace Tests\Feature;

use App\Models\AmericanoFlexMatch;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Services\AmericanoFlexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Боевой расклад: 14 игроков на 3 корта. Каждый раунд 12 играют, 2 отдыхают.
 * Проверяем именно то, что видно за столом: у всех поровну матчей и отдыха,
 * партнёр не повторяется.
 */
class Flex14x3Test extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tournament, 1: AmericanoFlexService} */
    private function startTournament(): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $tournament = Tournament::create([
            'club_id' => $club->id, 'name' => 'Флекс', 'type' => 'americano_flex',
            'status' => 'open', 'start_date' => now(),
            'min_level' => 1, 'max_level' => 5,
            'max_participants' => 14, 'courts_count' => 3,
        ]);

        for ($i = 1; $i <= 14; $i++) {
            $user = User::factory()->create(['name' => sprintf('И%02d', $i), 'rating' => 1500 + $i * 10]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id, 'user_id' => $user->id, 'status' => 'registered',
            ]);
        }

        $service = app(AmericanoFlexService::class);
        $this->assertTrue($service->startTournament($tournament), 'старт не прошёл');

        return [$tournament, $service];
    }

    /** Доиграть текущий раунд и открыть следующий. */
    private function playRound(Tournament $tournament, AmericanoFlexService $service): void
    {
        $round = $service->getCurrentRound($tournament);
        foreach ($round->matches as $match) {
            $service->saveMatchResult($match, 21, 15);
        }
    }

    public function test_every_round_has_three_courts_and_two_resting(): void
    {
        [$tournament, $service] = $this->startTournament();

        for ($r = 1; $r <= 7; $r++) {
            $round = $service->getCurrentRound($tournament);

            $this->assertSame($r, $round->round_number);
            $this->assertCount(3, $round->matches, "раунд {$r}: должно быть 3 матча");
            $this->assertSame(2, $round->byes()->count(), "раунд {$r}: должно отдыхать двое");

            $this->playRound($tournament, $service);
            if ($r < 7) {
                $service->generateNextRound($tournament);
            }
        }
    }

    public function test_after_seven_rounds_everyone_played_six_and_rested_once(): void
    {
        [$tournament, $service] = $this->startTournament();

        for ($r = 1; $r <= 7; $r++) {
            $this->playRound($tournament, $service);
            if ($r < 7) {
                $service->generateNextRound($tournament);
            }
        }

        $players = $tournament->americanoFlexPlayers()->get();

        $this->assertCount(14, $players);
        foreach ($players as $player) {
            $this->assertSame(6, (int) $player->matches_played, "{$player->user_id}: матчей должно быть 6");
            $this->assertSame(1, (int) $player->bye_count, "{$player->user_id}: отдыхов должен быть 1");
        }
    }

    public function test_partners_never_repeat_over_seven_rounds(): void
    {
        [$tournament, $service] = $this->startTournament();

        for ($r = 1; $r <= 7; $r++) {
            $this->playRound($tournament, $service);
            if ($r < 7) {
                $service->generateNextRound($tournament);
            }
        }

        $seen = [];
        $roundIds = $tournament->americanoFlexRounds()->pluck('id');
        foreach (AmericanoFlexMatch::whereIn('americano_flex_round_id', $roundIds)->get() as $match) {
            foreach ([[$match->team1_player1_id, $match->team1_player2_id],
                      [$match->team2_player1_id, $match->team2_player2_id]] as $pair) {
                sort($pair);
                $key = implode('-', $pair);
                $this->assertArrayNotHasKey($key, $seen, "пара {$key} повторилась");
                $seen[$key] = true;
            }
        }

        $this->assertCount(42, $seen, '7 раундов × 3 корта × 2 пары = 42 разные пары');
    }

    public function test_fourteen_rounds_keep_rest_even(): void
    {
        // Второй круг: к 14-му раунду у всех ровно по два отдыха.
        [$tournament, $service] = $this->startTournament();

        for ($r = 1; $r <= 14; $r++) {
            $this->playRound($tournament, $service);
            if ($r < 14) {
                $service->generateNextRound($tournament);
            }
        }

        foreach ($tournament->americanoFlexPlayers()->get() as $player) {
            $this->assertSame(12, (int) $player->matches_played);
            $this->assertSame(2, (int) $player->bye_count);
        }
    }
}

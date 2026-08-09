<?php

namespace Tests\Unit\Services;

use App\Models\Club;
use App\Models\EscaleraMatch;
use App\Models\EscaleraPlayer;
use App\Models\EscaleraRound;
use App\Models\EscaleraRoundCourt;
use App\Models\Tournament;
use App\Models\User;
use App\Services\EscaleraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscaleraServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Турнир-эскалера на заданное число кортов (игроков = корты × 4). */
    private function makeTournament(int $courts = 3, string $standingsMode = 'points'): Tournament
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);

        return Tournament::create([
            'club_id' => $club->id,
            'name' => 'Эскалера',
            'type' => 'escalera',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'courts_count' => $courts,
            'max_participants' => $courts * 4,
            'escalera_standings_mode' => $standingsMode,
        ]);
    }

    public function test_tournament_type_and_relations(): void
    {
        $t = $this->makeTournament();

        $this->assertTrue($t->isEscalera());
        $this->assertSame('points', $t->escalera_standings_mode);

        $user = User::factory()->create(['rating' => 1500]);
        EscaleraPlayer::create([
            'tournament_id' => $t->id,
            'user_id' => $user->id,
            'start_court' => 1,
            'current_court' => 1,
        ]);

        $this->assertSame(1, $t->fresh()->escaleraPlayers->count());
        $this->assertSame(0, $t->fresh()->escaleraPlayers->first()->total_points);
    }

    public function test_round_court_holds_four_players_in_seating_order(): void
    {
        $t = $this->makeTournament();
        $round = EscaleraRound::create([
            'tournament_id' => $t->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);

        $players = User::factory()->count(4)->create();
        $court = EscaleraRoundCourt::create([
            'escalera_round_id' => $round->id,
            'court_number' => 1,
            'player1_id' => $players[0]->id,
            'player2_id' => $players[1]->id,
            'player3_id' => $players[2]->id,
            'player4_id' => $players[3]->id,
        ]);

        $this->assertSame($round->id, $court->fresh()->round->id);
        $this->assertSame($players[0]->id, $court->fresh()->player1_id);
        $this->assertSame(1, $t->fresh()->escaleraRounds->count());
    }

    /**
     * Корт с четырьмя игроками и тремя матчами.
     * $scores — три пары [очки команды 1, очки команды 2] по порядку матчей.
     *
     * @param  array<int, array{0:int,1:int}> $scores
     * @return array{0: EscaleraRoundCourt, 1: array<int, User>}
     */
    private function makeCourtWithScores(Tournament $t, array $scores, array $ratings = [1600, 1500, 1400, 1300]): array
    {
        $round = EscaleraRound::create([
            'tournament_id' => $t->id, 'round_number' => 1, 'status' => 'in_progress',
        ]);

        $players = [];
        foreach ($ratings as $rating) {
            $players[] = User::factory()->create(['rating' => $rating]);
        }

        $court = EscaleraRoundCourt::create([
            'escalera_round_id' => $round->id,
            'court_number' => 1,
            'player1_id' => $players[0]->id,
            'player2_id' => $players[1]->id,
            'player3_id' => $players[2]->id,
            'player4_id' => $players[3]->id,
        ]);

        // Пары по порядку матчей: 1+4 vs 2+3, 1+3 vs 2+4, 1+2 vs 3+4.
        $lineup = [
            [[0, 3], [1, 2]],
            [[0, 2], [1, 3]],
            [[0, 1], [2, 3]],
        ];

        foreach ($lineup as $i => [$teamA, $teamB]) {
            EscaleraMatch::create([
                'escalera_round_court_id' => $court->id,
                'match_number' => $i + 1,
                'team1_player1_id' => $players[$teamA[0]]->id,
                'team1_player2_id' => $players[$teamA[1]]->id,
                'team2_player1_id' => $players[$teamB[0]]->id,
                'team2_player2_id' => $players[$teamB[1]]->id,
                'team1_score' => $scores[$i][0],
                'team2_score' => $scores[$i][1],
                'status' => 'completed',
            ]);
        }

        return [$court->fresh(), $players];
    }

    public function test_position_formula(): void
    {
        $service = app(EscaleraService::class);

        // Первый на первом корте — первый в общем строю.
        $this->assertSame(1, $service->positionFor(1, 1));
        // Четвёртый на первом корте — четвёртый.
        $this->assertSame(4, $service->positionFor(1, 4));
        // Первый на втором корте — пятый.
        $this->assertSame(5, $service->positionFor(2, 1));
        // Третий на третьем корте — одиннадцатый.
        $this->assertSame(11, $service->positionFor(3, 3));
    }

    public function test_points_formula(): void
    {
        $service = app(EscaleraService::class);

        // При 12 игроках первая позиция стоит 12 баллов, последняя — 1.
        $this->assertSame(12, $service->pointsFor(1, 12));
        $this->assertSame(1, $service->pointsFor(12, 12));
        $this->assertSame(8, $service->pointsFor(5, 12));
        // При 16 игроках шкала другая.
        $this->assertSame(16, $service->pointsFor(1, 16));
    }

    public function test_rank_court_by_points(): void
    {
        $t = $this->makeTournament(standingsMode: 'points');
        // Матч 1: (P1+P4) 7:5 (P2+P3); матч 2: (P1+P3) 8:4 (P2+P4); матч 3: (P1+P2) 6:6 (P3+P4).
        // Сумма очков: P1 = 7+8+6 = 21; P2 = 5+4+6 = 15; P3 = 5+8+6 = 19; P4 = 7+4+6 = 17.
        // Порядок P1, P3, P4, P2 намеренно расходится с порядком рейтингов
        // (P1, P2, P3, P4) — так тест ловит подмену сортировки на сортировку
        // только по рейтингу.
        [$court, $players] = $this->makeCourtWithScores($t, [[7, 5], [8, 4], [6, 6]]);

        $order = app(EscaleraService::class)->rankCourt($court);

        $this->assertSame(
            [$players[0]->id, $players[2]->id, $players[3]->id, $players[1]->id],
            $order,
            'порядок по сумме очков: P1, P3, P4, P2'
        );
    }

    public function test_full_tie_resolved_by_rating(): void
    {
        $t = $this->makeTournament(standingsMode: 'points');
        // Все три матча вничью — суммы очков у всех равны.
        // Порядок должен определиться рейтингом: 1600, 1500, 1400, 1300.
        [$court, $players] = $this->makeCourtWithScores($t, [[6, 6], [6, 6], [6, 6]]);

        $order = app(EscaleraService::class)->rankCourt($court);

        $this->assertSame(
            [$players[0]->id, $players[1]->id, $players[2]->id, $players[3]->id],
            $order,
            'при полном равенстве выше игрок с большим рейтингом'
        );
    }

    public function test_match_lineup_pairs_everyone_once(): void
    {
        $seating = [10, 20, 30, 40]; // id игроков в порядке посадки

        $lineup = app(EscaleraService::class)->matchLineup($seating);

        $this->assertSame([[10, 40], [20, 30]], $lineup[0], 'матч 1: 1+4 против 2+3');
        $this->assertSame([[10, 30], [20, 40]], $lineup[1], 'матч 2: 1+3 против 2+4');
        $this->assertSame([[10, 20], [30, 40]], $lineup[2], 'матч 3: 1+2 против 3+4');
    }

    public function test_movements_middle_court(): void
    {
        // Три корта, на каждом четвёрка в порядке мест с первого по четвёртое.
        $rankings = [
            1 => [101, 102, 103, 104],
            2 => [201, 202, 203, 204],
            3 => [301, 302, 303, 304],
        ];

        $next = app(EscaleraService::class)->planMovements($rankings);

        // Порядок важен, не только состав: он напрямую идёт в matchLineup() и
        // определяет, кто с кем играет первый матч следующего раунда.
        // Ожидаемый порядок — по возрастанию общей позиции прошлого раунда.
        // Верхний корт: первые трое остаются, четвёртый вниз; снизу приходит первый со второго.
        $this->assertSame([101, 102, 103, 201], $next[1]);
        // Средний: пришёл 104 сверху и 301 снизу, остались 202 и 203.
        $this->assertSame([104, 202, 203, 301], $next[2]);
        // Нижний: пришёл 204 сверху, остались 302, 303, 304.
        $this->assertSame([204, 302, 303, 304], $next[3]);
    }

    public function test_movements_two_courts(): void
    {
        // Минимально допустимая конфигурация: только верхний и нижний корт.
        $rankings = [
            1 => [101, 102, 103, 104],
            2 => [201, 202, 203, 204],
        ];

        $next = app(EscaleraService::class)->planMovements($rankings);

        // Порядок — по возрастанию общей позиции прошлого раунда (см. пояснение выше).
        $this->assertSame([101, 102, 103, 201], $next[1]);
        $this->assertSame([104, 202, 203, 204], $next[2]);
    }

    public function test_every_court_keeps_four_players(): void
    {
        $rankings = [
            1 => [101, 102, 103, 104],
            2 => [201, 202, 203, 204],
            3 => [301, 302, 303, 304],
            4 => [401, 402, 403, 404],
        ];

        $next = app(EscaleraService::class)->planMovements($rankings);

        foreach ($next as $courtNumber => $players) {
            $this->assertCount(4, $players, "на корте {$courtNumber} должно быть четверо");
        }
        // Никто не потерялся и не задвоился.
        $all = array_merge(...array_values($next));
        $this->assertCount(16, array_unique($all));
    }
}

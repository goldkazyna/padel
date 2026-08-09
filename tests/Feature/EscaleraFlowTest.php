<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\EscaleraPlayer;
use App\Models\EscaleraRound;
use App\Models\EscaleraRoundResult;
use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\User;
use App\Services\EscaleraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Сквозной прогон турнира «Эскалера»: старт, раунды, закрытие, награды, рейтинг.
 *
 * Счета подобраны так, чтобы порядок мест на корте был однозначным и не
 * зависел от тай-брейка по рейтингу.
 */
class EscaleraFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * «Доминирующий» набор счетов: три матча 12:0, 11:1, 10:2.
     * Суммы очков: посадка1 = 33, посадка4 = 15, посадка3 = 13, посадка2 = 11.
     * Итоговый порядок мест — [посадка1, посадка4, посадка3, посадка2].
     */
    private const DOMINANT = [[12, 0], [11, 1], [10, 2]];

    private function service(): EscaleraService
    {
        return app(EscaleraService::class);
    }

    private function makeTournament(int $courts = 3, string $mode = 'points', int $matchPoints = 12): Tournament
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);

        return Tournament::create([
            'club_id' => $club->id,
            'name' => 'Эскалера',
            'type' => 'escalera',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'courts_count' => $courts,
            'max_participants' => $courts * 4,
            'escalera_match_points' => $matchPoints,
            'escalera_standings_mode' => $mode,
        ]);
    }

    /**
     * Зарегистрировать игроков: ключ — имя, значение — рейтинг.
     * Порядок в массиве намеренно перемешан, чтобы посев считался по рейтингу,
     * а не по порядку регистрации.
     *
     * @param  array<string, int> $ratings
     * @return array<string, User>
     */
    private function register(Tournament $tournament, array $ratings): array
    {
        $users = [];
        foreach ($ratings as $name => $rating) {
            $user = User::factory()->create(['name' => $name, 'rating' => $rating]);
            $tournament->participants()->attach($user->id, ['status' => 'registered']);
            $users[$name] = $user;
        }

        return $users;
    }

    /** Двенадцать игроков на три корта: A* — сильнейшие, C* — слабейшие. */
    private function registerTwelve(Tournament $tournament): array
    {
        return $this->register($tournament, [
            'C3' => 1100,
            'A2' => 1900,
            'B4' => 1300,
            'A4' => 1700,
            'C1' => 1200,
            'B1' => 1600,
            'A1' => 2000,
            'C4' => 1050,
            'B3' => 1400,
            'A3' => 1800,
            'C2' => 1150,
            'B2' => 1500,
        ]);
    }

    private function lastRound(Tournament $tournament): ?EscaleraRound
    {
        return $tournament->escaleraRounds()->reorder('round_number', 'desc')->first();
    }

    /**
     * Внести счета текущего раунда. $patterns — корт => три пары счетов;
     * для кортов без своего набора берётся DOMINANT.
     *
     * @param  array<int, array<int, array{0:int,1:int}>> $patterns
     */
    private function playRound(Tournament $tournament, array $patterns = []): void
    {
        $round = $this->lastRound($tournament);

        foreach ($round->courts()->with('matches')->get() as $court) {
            $pattern = $patterns[$court->court_number] ?? self::DOMINANT;
            foreach ($court->matches->values() as $i => $match) {
                $this->service()->saveMatchResult($match, $pattern[$i][0], $pattern[$i][1]);
            }
        }
    }

    /** Имена игроков корта в порядке посадки. */
    private function seatingNames(Tournament $tournament, int $courtNumber): array
    {
        $court = $this->lastRound($tournament)->courts()->where('court_number', $courtNumber)->first();
        $ids = $court->playerIds();
        $names = User::whereIn('id', $ids)->pluck('name', 'id');

        return array_map(fn ($id) => $names[$id], $ids);
    }

    // ===== Старт =====

    public function test_start_blocked_when_participants_do_not_match_courts(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        // Одиннадцать игроков при трёх кортах — не хватает одного.
        $ratings = [];
        for ($i = 1; $i <= 11; $i++) {
            $ratings['P' . $i] = 2000 - $i * 10;
        }
        $this->register($tournament, $ratings);

        $this->assertFalse($this->service()->startTournament($tournament));

        $this->assertSame(0, $tournament->fresh()->escaleraRounds()->count(), 'раундов быть не должно');
        $this->assertSame(0, $tournament->fresh()->escaleraPlayers()->count(), 'участников формата быть не должно');
        $this->assertSame('open', $tournament->fresh()->status, 'турнир не должен стартовать');
    }

    public function test_start_creates_first_round_seeded_by_rating(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);

        $this->assertTrue($this->service()->startTournament($tournament));

        $tournament->refresh();
        $this->assertSame('in_progress', $tournament->status);

        $round = $this->lastRound($tournament);
        $this->assertSame(1, $round->round_number);
        $this->assertSame(3, $round->courts()->count(), 'кортов ровно courts_count');

        foreach ($round->courts as $court) {
            $this->assertSame(3, $court->matches()->count(), 'на каждом корте три матча');
        }

        // Первый корт — четверо сильнейших по рейтингу, в порядке убывания.
        $this->assertSame(['A1', 'A2', 'A3', 'A4'], $this->seatingNames($tournament, 1));
        $this->assertSame(['B1', 'B2', 'B3', 'B4'], $this->seatingNames($tournament, 2));
        $this->assertSame(['C1', 'C2', 'C3', 'C4'], $this->seatingNames($tournament, 3));

        // Порядок матчей от посадки: 1+4 против 2+3, затем 1+3 против 2+4, затем 1+2 против 3+4.
        $court1 = $round->courts()->where('court_number', 1)->first();
        $matches = $court1->matches->values();
        $this->assertSame([$u['A1']->id, $u['A4']->id], [$matches[0]->team1_player1_id, $matches[0]->team1_player2_id]);
        $this->assertSame([$u['A2']->id, $u['A3']->id], [$matches[0]->team2_player1_id, $matches[0]->team2_player2_id]);
        $this->assertSame([$u['A1']->id, $u['A3']->id], [$matches[1]->team1_player1_id, $matches[1]->team1_player2_id]);
        $this->assertSame([$u['A1']->id, $u['A2']->id], [$matches[2]->team1_player1_id, $matches[2]->team1_player2_id]);

        // Участники формата: стартовый и текущий корт совпадают, рейтинг зафиксирован.
        $this->assertSame(12, $tournament->escaleraPlayers()->count());
        $a1 = EscaleraPlayer::where('tournament_id', $tournament->id)->where('user_id', $u['A1']->id)->first();
        $this->assertSame(1, (int) $a1->start_court);
        $this->assertSame(1, (int) $a1->current_court);
        $this->assertSame(2000, (int) $a1->rating_before);

        $c4 = EscaleraPlayer::where('tournament_id', $tournament->id)->where('user_id', $u['C4']->id)->first();
        $this->assertSame(3, (int) $c4->start_court, 'слабейший — на нижнем корте');
    }

    // ===== Ввод счёта =====

    public function test_match_score_sum_is_validated(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);

        $match = $this->lastRound($tournament)->courts()->first()->matches()->first();

        try {
            $this->service()->saveMatchResult($match, 12, 10);
            $this->fail('счёт с неверной суммой должен отклоняться');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('12', $e->getMessage());
        }

        $this->assertNull($match->fresh()->team1_score, 'неверный счёт не сохраняется');
        $this->assertSame('pending', $match->fresh()->status);

        // Сумма равна заданной — счёт сохраняется.
        $this->service()->saveMatchResult($match, 7, 5);
        $this->assertSame(7, (int) $match->fresh()->team1_score);
        $this->assertSame(5, (int) $match->fresh()->team2_score);
        $this->assertSame('completed', $match->fresh()->status);
    }

    public function test_can_close_round_only_when_all_scores_entered(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);

        $this->assertFalse($this->service()->canCloseRound($tournament), 'счетов нет — закрывать нечего');

        $round = $this->lastRound($tournament);
        $courts = $round->courts()->with('matches')->get();

        // Внесли все счета, кроме последнего матча последнего корта.
        $all = [];
        foreach ($courts as $court) {
            foreach ($court->matches->values() as $match) {
                $all[] = $match;
            }
        }
        $lastMatch = array_pop($all);
        foreach ($all as $match) {
            $this->service()->saveMatchResult($match, 7, 5);
        }

        $this->assertFalse($this->service()->canCloseRound($tournament), 'один матч без счёта — закрытие недоступно');

        $this->service()->saveMatchResult($lastMatch, 7, 5);
        $this->assertTrue($this->service()->canCloseRound($tournament));
    }

    // ===== Закрытие раунда =====

    public function test_preview_round_close_does_not_write_anything(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);
        $this->playRound($tournament);

        $preview = $this->service()->previewRoundClose($tournament);

        $this->assertCount(3, $preview);
        $first = $preview[0];
        $this->assertSame(1, $first['court_number']);
        $this->assertSame(
            [$u['A1']->id, $u['A4']->id, $u['A3']->id, $u['A2']->id],
            array_column($first['places'], 'user_id'),
            'порядок мест по сумме очков'
        );
        $this->assertSame('stay', $first['places'][0]['movement'], 'с верхнего корта подниматься некуда');
        $this->assertSame('down', $first['places'][3]['movement']);
        $this->assertSame('up', $preview[1]['places'][0]['movement']);
        $this->assertSame('stay', $preview[2]['places'][3]['movement'], 'с нижнего корта опускаться некуда');

        // Ничего не записано и никто не переехал.
        $this->assertSame(0, EscaleraRoundResult::count());
        $this->assertSame(0, EscaleraPlayer::where('tournament_id', $tournament->id)->sum('total_points'));
        $this->assertSame(1, (int) EscaleraPlayer::where('user_id', $u['A2']->id)->first()->current_court);
        $this->assertFalse($this->lastRound($tournament)->isCompleted());
    }

    public function test_close_round_writes_results_points_and_movements(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);
        $this->playRound($tournament);

        $this->assertTrue($this->service()->closeRound($tournament));

        $round = $this->lastRound($tournament);
        $this->assertTrue($round->fresh()->isCompleted());
        $this->assertSame(12, EscaleraRoundResult::where('escalera_round_id', $round->id)->count());

        // У каждого игрока записаны позиция в общем строю и баллы.
        $results = EscaleraRoundResult::where('escalera_round_id', $round->id)->get()->keyBy('user_id');
        foreach ($u as $user) {
            $this->assertNotNull($results[$user->id]->overall_position);
            $this->assertNotNull($results[$user->id]->points);
        }

        // Первый на первом корте — первая позиция и максимум баллов (игроков 12).
        $this->assertSame(1, (int) $results[$u['A1']->id]->overall_position);
        $this->assertSame(12, (int) $results[$u['A1']->id]->points);
        // Четвёртый на первом корте — четвёртая позиция.
        $this->assertSame(4, (int) $results[$u['A2']->id]->overall_position);
        $this->assertSame(9, (int) $results[$u['A2']->id]->points);
        // Первый на втором корте — пятая позиция, хотя на корте он выиграл всё.
        $this->assertSame(5, (int) $results[$u['B1']->id]->overall_position);
        $this->assertSame(8, (int) $results[$u['B1']->id]->points);
        // Последний на нижнем корте — двенадцатая позиция и один балл.
        $this->assertSame(12, (int) $results[$u['C2']->id]->overall_position);
        $this->assertSame(1, (int) $results[$u['C2']->id]->points);

        // Сумма баллов обновлена в escalera_players.
        $players = EscaleraPlayer::where('tournament_id', $tournament->id)->get()->keyBy('user_id');
        $this->assertSame(12, (int) $players[$u['A1']->id]->total_points);
        $this->assertSame(1, (int) $players[$u['C2']->id]->total_points);
        // Сумма очков за матчи и победы тоже накапливаются.
        $this->assertSame(33, (int) $players[$u['A1']->id]->total_raw_points);
        $this->assertSame(3, (int) $players[$u['A1']->id]->wins, 'первый по посадке выиграл все три матча');
        $this->assertSame(1, (int) $players[$u['A2']->id]->wins, 'остальные выиграли по одному — в паре с ним');

        // Перемещения: первый идёт вверх, четвёртый вниз, средние остаются.
        $this->assertSame(1, (int) $players[$u['A1']->id]->current_court, 'с верхнего корта не уходят');
        $this->assertSame(2, (int) $players[$u['A2']->id]->current_court, 'четвёртый на первом корте — вниз');
        $this->assertSame(1, (int) $players[$u['B1']->id]->current_court, 'первый на втором корте — вверх');
        $this->assertSame(3, (int) $players[$u['B2']->id]->current_court);
        $this->assertSame(2, (int) $players[$u['C1']->id]->current_court);
        $this->assertSame(3, (int) $players[$u['C2']->id]->current_court, 'с нижнего корта не опускаются');

        // Повторное закрытие уже закрытого раунда невозможно.
        $this->assertFalse($this->service()->canCloseRound($tournament));
        $this->assertFalse($this->service()->closeRound($tournament));
    }

    // ===== Следующий раунд =====

    public function test_generate_next_round_follows_movements_and_standings(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);
        $this->playRound($tournament);
        $this->service()->closeRound($tournament);

        $this->assertTrue($this->service()->generateNextRound($tournament));

        $round = $this->lastRound($tournament);
        $this->assertSame(2, $round->round_number);
        $this->assertSame(3, $round->courts()->count());

        // Составы соответствуют перемещениям, посадка — по текущему месту в таблице.
        $this->assertSame(['A1', 'A4', 'A3', 'B1'], $this->seatingNames($tournament, 1));
        $this->assertSame(['A2', 'B4', 'B3', 'C1'], $this->seatingNames($tournament, 2));
        $this->assertSame(['B2', 'C4', 'C3', 'C2'], $this->seatingNames($tournament, 3));

        // Матчи второго раунда строятся от новой посадки.
        $court1 = $round->courts()->where('court_number', 1)->first();
        $match1 = $court1->matches->values()[0];
        $this->assertSame([$u['A1']->id, $u['B1']->id], [$match1->team1_player1_id, $match1->team1_player2_id]);
        $this->assertSame('pending', $match1->status);

        // current_court игроков соответствует новым кортам.
        $players = EscaleraPlayer::where('tournament_id', $tournament->id)->get()->keyBy('user_id');
        $this->assertSame(1, (int) $players[$u['B1']->id]->current_court);
        $this->assertSame(2, (int) $players[$u['A2']->id]->current_court);

        // Пока счетов второго раунда нет — новый раунд не закрыть.
        $this->assertFalse($this->service()->canCloseRound($tournament));
    }

    // ===== Таблица =====

    public function test_standings_sorted_by_mode(): void
    {
        // Два корта, восемь игроков.
        $tournament = $this->makeTournament(courts: 2, mode: 'points');
        $u = $this->register($tournament, [
            'A1' => 2000, 'A2' => 1900, 'A3' => 1800, 'A4' => 1700,
            'B1' => 1600, 'B2' => 1500, 'B3' => 1400, 'B4' => 1300,
        ]);
        $this->service()->startTournament($tournament);

        // На верхнем корте счета ровные (сумма очков победителя 21),
        // на нижнем — разгромные (сумма очков победителя 33).
        $this->playRound($tournament, [
            1 => [[8, 4], [7, 5], [6, 6]],
            2 => self::DOMINANT,
        ]);
        $this->service()->closeRound($tournament);

        // Режим «по баллам»: наверху лидер верхнего корта.
        $byPoints = $this->service()->standings($tournament);
        $this->assertSame('A1', $byPoints[0]['user']->name);
        $this->assertSame(8, $byPoints[0]['points']);
        $this->assertSame(1, $byPoints[0]['position']);
        $this->assertNull($byPoints[0]['change'], 'в первом раунде изменение позиции пустое');
        // Победитель нижнего корта — только пятый, как бы он там ни выиграл.
        $b1Row = collect($byPoints)->firstWhere('user_id', $u['B1']->id);
        $this->assertSame(5, $b1Row['position']);

        // Режим «по сумме очков»: тот же турнир, но наверху лидер нижнего корта.
        $tournament->update(['escalera_standings_mode' => 'raw_points']);
        $byRaw = $this->service()->standings($tournament->fresh());
        $this->assertSame('B1', $byRaw[0]['user']->name);
        $this->assertSame(33, $byRaw[0]['raw_points']);
        $this->assertSame('A1', $byRaw[1]['user']->name);

        // Баллы за позиции считаются в обоих режимах — от них зависят перемещения.
        $this->assertSame(8, collect($byRaw)->firstWhere('user_id', $u['A1']->id)['points']);
    }

    public function test_standings_shows_position_change_between_rounds(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);
        $this->playRound($tournament);
        $this->service()->closeRound($tournament);
        $this->service()->generateNextRound($tournament);

        // Второй раунд: на первом корте выигрывает поднявшийся снизу B1.
        $this->playRound($tournament, [1 => [[11, 1], [2, 10], [5, 7]]]);
        $this->service()->closeRound($tournament);

        $standings = $this->service()->standings($tournament);
        $rows = collect($standings)->keyBy('user_id');

        // После первого раунда B1 был пятым, после второго поднялся на третье место.
        $this->assertSame(3, $rows[$u['B1']->id]['position']);
        $this->assertSame(2, $rows[$u['B1']->id]['change'], 'поднялся на две позиции');
        // A3 был третьим, стал четвёртым.
        $this->assertSame(4, $rows[$u['A3']->id]['position']);
        $this->assertSame(-1, $rows[$u['A3']->id]['change']);
        // Лидер остался лидером.
        $this->assertSame(1, $rows[$u['A1']->id]['position']);
        $this->assertSame(0, $rows[$u['A1']->id]['change']);

        // Третий раунд: посадка идёт по общей таблице, а не по порядку приезда
        // на корт. B1 выиграл второй раунд на первом корте, но в таблице он
        // третий — значит и садится третьим.
        $this->service()->generateNextRound($tournament);
        $this->assertSame(['A1', 'A4', 'B1', 'A2'], $this->seatingNames($tournament, 1));
        $this->assertSame(['A3', 'B3', 'C1', 'B2'], $this->seatingNames($tournament, 2));
    }

    // ===== Правка счёта задним числом =====

    public function test_editing_score_after_close_recalculates_points_but_keeps_courts(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);
        $this->playRound($tournament);
        $this->service()->closeRound($tournament);

        $round = $this->lastRound($tournament);
        $before = EscaleraPlayer::where('tournament_id', $tournament->id)->get()->keyBy('user_id');
        $this->assertSame(4, (int) $before[$u['C1']->id]->total_points);
        $this->assertSame(2, (int) $before[$u['C3']->id]->total_points);
        $this->assertSame(2, (int) $before[$u['C1']->id]->current_court, 'C1 поднялся при закрытии раунда');

        // Переигрываем первый матч нижнего корта: было 12:0, стало 0:12.
        // Новые суммы очков: C3 = 25, C2 = 23, C1 = 21, C4 = 3.
        $court3 = $round->courts()->where('court_number', 3)->first();
        $match = $court3->matches->values()[0];
        $this->service()->saveMatchResult($match, 0, 12);

        $after = EscaleraPlayer::where('tournament_id', $tournament->id)->get()->keyBy('user_id');
        $results = EscaleraRoundResult::where('escalera_round_id', $round->id)->get()->keyBy('user_id');

        // Баллы пересчитаны: теперь первый на нижнем корте — C3.
        $this->assertSame(1, (int) $results[$u['C3']->id]->place_on_court);
        $this->assertSame(9, (int) $results[$u['C3']->id]->overall_position);
        $this->assertSame(4, (int) $after[$u['C3']->id]->total_points);
        $this->assertSame(2, (int) $after[$u['C1']->id]->total_points);
        $this->assertSame(1, (int) $after[$u['C4']->id]->total_points);

        // А по кортам никто не переехал — перемещения уже произошли.
        $this->assertSame(2, (int) $after[$u['C1']->id]->current_court);
        $this->assertSame(3, (int) $after[$u['C3']->id]->current_court);
        foreach ($u as $user) {
            $this->assertSame(
                (int) $before[$user->id]->current_court,
                (int) $after[$user->id]->current_court,
                'правка счёта не двигает игроков между кортами'
            );
        }
    }

    // ===== Завершение и награды =====

    public function test_finish_tournament_gives_awards_and_applies_rating(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);

        // Раунд 1: на всех кортах выигрывает первый по посадке.
        $this->playRound($tournament);
        $this->service()->closeRound($tournament);
        $this->service()->generateNextRound($tournament);

        // Раунд 2: на первом корте выигрывает поднявшийся снизу B1,
        // A1 остаётся вторым и сохраняет первое место в таблице.
        $this->playRound($tournament, [1 => [[11, 1], [2, 10], [5, 7]]]);
        $this->service()->closeRound($tournament);

        $ratingsBefore = User::whereIn('id', collect($u)->pluck('id'))->pluck('rating', 'id');

        $this->assertTrue($this->service()->canFinishTournament($tournament));
        $this->assertTrue($this->service()->finishTournament($tournament));

        $tournament->refresh();
        $this->assertSame('completed', $tournament->status);

        // Рейтинг начислен: у всех есть rating_after и запись в истории.
        $players = EscaleraPlayer::where('tournament_id', $tournament->id)->get();
        foreach ($players as $player) {
            $this->assertNotNull($player->rating_after, 'у каждого игрока должен быть итоговый рейтинг');
            $this->assertSame(
                (int) $player->rating_after,
                (int) User::find($player->user_id)->rating,
                'рейтинг игрока обновлён'
            );
        }
        $this->assertSame(12, RatingHistory::where('tournament_id', $tournament->id)->count());

        // Хотя бы у кого-то рейтинг реально изменился.
        $changed = $players->filter(fn ($p) => (int) $p->rating_after !== (int) $ratingsBefore[$p->user_id]);
        $this->assertTrue($changed->count() > 0, 'рейтинг должен измениться хотя бы у части игроков');

        // Награды.
        $awards = $this->service()->awards($tournament);
        // Чемпион — наибольшая сумма баллов: A1 набрал 12 + 11 = 23.
        $this->assertSame('A1', $awards['champion']['user']->name);
        $this->assertSame(23, $awards['champion']['points']);
        // «Восхождение» — B1 поднялся со второго корта на первый; C1 поднялся
        // на столько же, но закончил ниже, поэтому уступает.
        $this->assertSame('B1', $awards['ascent']['user']->name);
        $this->assertSame(1, $awards['ascent']['climb']);
        $this->assertSame(2, $awards['ascent']['start_court']);
        $this->assertSame(1, $awards['ascent']['current_court']);
        // «Король корта» — победитель последнего раунда на первом корте.
        $this->assertSame('B1', $awards['king_of_court']['user']->name);
        $this->assertSame(2, $awards['king_of_court']['round_number']);

        // Повторное завершение ничего не начисляет второй раз.
        $ratingsAfter = User::whereIn('id', collect($u)->pluck('id'))->pluck('rating', 'id');
        $this->assertFalse($this->service()->canFinishTournament($tournament));
        $this->assertFalse($this->service()->finishTournament($tournament));
        $this->assertSame(12, RatingHistory::where('tournament_id', $tournament->id)->count());
        $this->assertEquals(
            $ratingsAfter->all(),
            User::whereIn('id', collect($u)->pluck('id'))->pluck('rating', 'id')->all(),
            'повторный вызов не должен двигать рейтинг'
        );
    }

    public function test_finish_blocked_while_round_is_open(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);
        $this->playRound($tournament);

        // Раунд ещё не закрыт — завершать нельзя.
        $this->assertFalse($this->service()->canFinishTournament($tournament));
        $this->assertFalse($this->service()->finishTournament($tournament));
        $this->assertSame('in_progress', $tournament->fresh()->status);
        $this->assertSame(0, RatingHistory::where('tournament_id', $tournament->id)->count());
    }
}

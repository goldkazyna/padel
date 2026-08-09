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
use App\Services\TournamentResetService;
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

    private function makeTournament(int $courts = 3, string $mode = 'points'): Tournament
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

    public function test_any_score_is_accepted(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);

        $match = $this->lastRound($tournament)->courts()->first()->matches()->first();

        // Формат матча организатор задаёт сам, сумма ничем не ограничена.
        $this->service()->saveMatchResult($match, 12, 10);
        $this->assertSame(12, (int) $match->fresh()->team1_score);
        $this->assertSame(10, (int) $match->fresh()->team2_score);
        $this->assertSame('completed', $match->fresh()->status);

        // Правка на совсем другой формат тоже проходит.
        $this->service()->saveMatchResult($match->fresh(), 3, 1);
        $this->assertSame(3, (int) $match->fresh()->team1_score);
        $this->assertSame(1, (int) $match->fresh()->team2_score);
    }

    public function test_negative_score_is_rejected(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);

        $match = $this->lastRound($tournament)->courts()->first()->matches()->first();

        $this->expectException(InvalidArgumentException::class);
        $this->service()->saveMatchResult($match, -1, 5);
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
        $this->assertSame(1, $awards['ascent']['final_court']);
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

    public function test_start_is_blocked_for_already_started_tournament(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);

        $this->assertTrue($this->service()->startTournament($tournament));

        // Повторный старт не должен ни пересобирать посадку, ни плодить раунды.
        $this->assertFalse($this->service()->startTournament($tournament));
        $this->assertSame(1, $tournament->fresh()->escaleraRounds()->count());
        $this->assertSame(12, $tournament->fresh()->escaleraPlayers()->count());
    }

    public function test_next_round_blocked_until_current_one_is_closed(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);

        // Первый раунд ещё не начат — следующего быть не может.
        $this->assertFalse($this->service()->generateNextRound($tournament));
        $this->assertSame(1, $tournament->fresh()->escaleraRounds()->count());

        // Все счета внесены, но раунд не закрыт — по-прежнему нельзя.
        $this->playRound($tournament);
        $this->assertFalse($this->service()->generateNextRound($tournament));
        $this->assertSame(1, $tournament->fresh()->escaleraRounds()->count());

        // И только после закрытия появляется второй раунд.
        $this->service()->closeRound($tournament);
        $this->assertTrue($this->service()->generateNextRound($tournament));
        $this->assertSame(2, $tournament->fresh()->escaleraRounds()->count());
    }

    public function test_standings_count_points_scored_and_conceded(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);

        // Корт 1 (A1..A4) играет DOMINANT: 12:0, 11:1, 10:2.
        // Пары по посадке: (1,4)-(2,3), затем (1,3)-(2,4), затем (1,2)-(3,4).
        $this->playRound($tournament);
        $this->service()->closeRound($tournament);

        $rows = collect($this->service()->standings($tournament))->keyBy('user_id');

        // A1 выиграл все три матча: забил 12+11+10, пропустил 0+1+2.
        $a1 = $rows[$u['A1']->id];
        $this->assertSame(33, $a1['raw_points'], 'забитые');
        $this->assertSame(3, $a1['points_against'], 'пропущенные');
        $this->assertSame(3, $a1['wins']);
        $this->assertSame(0, $a1['losses']);

        // A2 выиграл только третий матч: забил 0+1+10, пропустил 12+11+2.
        $a2 = $rows[$u['A2']->id];
        $this->assertSame(11, $a2['raw_points']);
        $this->assertSame(25, $a2['points_against']);
        $this->assertSame(1, $a2['wins']);
        $this->assertSame(2, $a2['losses']);

        // Забитые и пропущенные на корте сходятся: каждый мяч кому-то забит
        // и кем-то пропущен.
        $courtIds = collect(['A1', 'A2', 'A3', 'A4'])->map(fn ($n) => $u[$n]->id);
        $this->assertSame(
            $courtIds->sum(fn ($id) => $rows[$id]['raw_points']),
            $courtIds->sum(fn ($id) => $rows[$id]['points_against']),
            'сумма забитых на корте равна сумме пропущенных'
        );
    }

    public function test_draw_counts_as_neither_win_nor_loss(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);

        // Счёт свободный, поэтому ничья возможна: первый матч корта 1 — 5:5.
        $this->playRound($tournament, [
            1 => [[5, 5], [11, 1], [10, 2]],
        ]);

        $rows = collect($this->service()->standings($tournament))->keyBy('user_id');

        // A1: ничья + две победы. A4: ничья, победа в третьем нет — только поражения.
        $this->assertSame(2, $rows[$u['A1']->id]['wins']);
        $this->assertSame(0, $rows[$u['A1']->id]['losses'], 'ничья не поражение');
        $this->assertSame(0, $rows[$u['A4']->id]['wins'], 'ничья не победа');
        $this->assertSame(2, $rows[$u['A4']->id]['losses']);
    }

    public function test_standings_tie_break_by_wins(): void
    {
        // Режим «по сумме очков» — в нём легко получить равные числа у игроков
        // с разных кортов.
        $tournament = $this->makeTournament(courts: 2, mode: 'raw_points');
        $u = $this->register($tournament, [
            'A1' => 2000, 'A2' => 1900, 'A3' => 1800, 'A4' => 1700,
            'B1' => 1600, 'B2' => 1500, 'B3' => 1400, 'B4' => 1300,
        ]);
        $this->service()->startTournament($tournament);

        // A4 набирает 15 очков одной победой, B1 — те же 15 двумя победами.
        // Рейтинг у A4 выше, поэтому без тай-брейка по победам он оказался бы
        // впереди — а по правилам выше должен быть B1.
        $this->playRound($tournament, [
            1 => self::DOMINANT,
            2 => [[7, 5], [7, 5], [1, 11]],
        ]);
        $this->service()->closeRound($tournament);

        $rows = collect($this->service()->standings($tournament))->keyBy('user_id');

        $this->assertSame(15, $rows[$u['A4']->id]['raw_points']);
        $this->assertSame(15, $rows[$u['B1']->id]['raw_points']);
        $this->assertSame(1, $rows[$u['A4']->id]['wins']);
        $this->assertSame(2, $rows[$u['B1']->id]['wins']);
        $this->assertTrue(
            $rows[$u['B1']->id]['position'] < $rows[$u['A4']->id]['position'],
            'при равной сумме очков выше тот, кто выиграл больше коротких матчей'
        );
    }

    public function test_standings_tie_break_by_head_to_head(): void
    {
        // Конструкция: A2 и A3 играли вместе на первом корте в первом раунде,
        // где A3 набрал против A2 13 очков, а A2 против A3 — 11. Во втором
        // раунде они оказались на разных кортах и добрали ровно столько, чтобы
        // сравняться и по сумме очков (42), и по числу побед (4).
        // Рейтинг у A2 выше, поэтому без тай-брейка по личной встрече впереди
        // оказался бы он — а должен быть A3.
        $tournament = $this->makeTournament(courts: 2, mode: 'raw_points');
        $u = $this->register($tournament, [
            'A1' => 2000, 'A2' => 1900, 'A3' => 1800, 'A4' => 1700,
            'B1' => 1600, 'B2' => 1500, 'B3' => 1400, 'B4' => 1300,
        ]);
        $this->service()->startTournament($tournament);

        $this->playRound($tournament, [
            1 => self::DOMINANT,
            2 => [[7, 5], [7, 5], [7, 5]],
        ]);
        $this->service()->closeRound($tournament);
        $this->service()->generateNextRound($tournament);

        // Во втором раунде A3 сидит четвёртым на первом корте, A2 — четвёртым на втором.
        $this->assertSame(['A1', 'B1', 'A4', 'A3'], $this->seatingNames($tournament, 1));
        $this->assertSame(['B2', 'B3', 'B4', 'A2'], $this->seatingNames($tournament, 2));

        $this->playRound($tournament, [
            1 => [[11, 1], [2, 10], [4, 8]],
            2 => [[12, 0], [3, 9], [2, 10]],
        ]);
        $this->service()->closeRound($tournament);

        $rows = collect($this->service()->standings($tournament))->keyBy('user_id');

        $this->assertSame(42, $rows[$u['A2']->id]['raw_points']);
        $this->assertSame(42, $rows[$u['A3']->id]['raw_points']);
        $this->assertSame(4, $rows[$u['A2']->id]['wins']);
        $this->assertSame(4, $rows[$u['A3']->id]['wins']);
        $this->assertTrue(
            $rows[$u['A3']->id]['position'] < $rows[$u['A2']->id]['position'],
            'при равных очках и победах решает личная встреча, а не рейтинг'
        );
    }

    // ===== Награды =====

    public function test_no_ascent_award_when_nobody_climbed(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);
        $this->playRound($tournament);
        $this->service()->closeRound($tournament);

        // Сыгран один раунд: все играли на своих стартовых кортах, подняться
        // никто ещё не успел.
        $awards = $this->service()->awards($tournament);

        $this->assertNull($awards['ascent'], 'награждать за подъём некого');
        $this->assertSame('A1', $awards['champion']['user']->name);
        $this->assertSame('A1', $awards['king_of_court']['user']->name);
    }

    public function test_ascent_counts_court_where_player_actually_played(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);
        $this->playRound($tournament);
        $this->service()->closeRound($tournament);
        $this->service()->generateNextRound($tournament);

        // Второй (последний) раунд: на втором корте выигрывает поднявшийся снизу
        // C1 — закрытие раунда переставит его на первый корт, хотя играл он на
        // втором. B1 играл второй раунд на первом корте и остался там же.
        $this->playRound($tournament, [2 => [[11, 1], [2, 10], [5, 7]]]);
        $this->service()->closeRound($tournament);
        $this->service()->finishTournament($tournament);

        $c1 = EscaleraPlayer::where('tournament_id', $tournament->id)
            ->where('user_id', $u['C1']->id)->first();
        $this->assertSame(1, (int) $c1->current_court, 'в БД остался корт, куда игрок поехал бы дальше');

        $rows = collect($this->service()->standings($tournament))->keyBy('user_id');
        $this->assertSame(2, $rows[$u['C1']->id]['current_court'], 'в таблице завершённого турнира — сыгранный корт');
        $this->assertSame(2, $rows[$u['C1']->id]['final_court']);
        $this->assertSame(1, $rows[$u['B1']->id]['final_court']);

        // C1 поднялся с третьего корта на второй, B1 — со второго на первый.
        // Подъём одинаковый, поэтому награду забирает тот, кто закончил выше.
        $awards = $this->service()->awards($tournament);
        $this->assertSame('B1', $awards['ascent']['user']->name);
        $this->assertSame(1, $awards['ascent']['climb']);
        $this->assertSame(1, $awards['ascent']['final_court']);
    }

    public function test_score_cannot_be_edited_after_tournament_is_finished(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);
        $this->playRound($tournament);
        $this->service()->closeRound($tournament);
        $this->service()->finishTournament($tournament);

        $match = $this->lastRound($tournament)->courts()->where('court_number', 3)->first()
            ->matches->values()[0];
        $pointsBefore = (int) EscaleraPlayer::where('tournament_id', $tournament->id)
            ->where('user_id', $u['C1']->id)->first()->total_points;

        try {
            $this->service()->saveMatchResult($match, 0, 12);
            $this->fail('в завершённом турнире счёт менять нельзя');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('завершён', $e->getMessage());
        }

        // Ни счёт, ни таблица не изменились — иначе таблица разошлась бы с рейтингом.
        $this->assertSame(12, (int) $match->fresh()->team1_score);
        $this->assertSame(
            $pointsBefore,
            (int) EscaleraPlayer::where('tournament_id', $tournament->id)
                ->where('user_id', $u['C1']->id)->first()->total_points
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

    // ===== Веб-интерфейс =====

    /** Админ клуба, которому принадлежит турнир. */
    private function clubAdmin(Tournament $tournament): User
    {
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($tournament->club_id);

        return $admin;
    }

    public function test_club_admin_creates_escalera_tournament_with_format_fields(): void
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $this->actingAs($admin)
            ->post(route('club.tournaments.store'), [
                'club_id' => $club->id,
                'name' => 'Эскалера вечер',
                'start_date' => now()->addDay()->format('Y-m-d\TH:i'),
                'min_level' => '1.00',
                'max_level' => '5.75',
                // Намеренно неверное число: для эскалеры оно пересчитывается из кортов.
                'max_participants' => 16,
                'status' => 'open',
                'type' => 'escalera',
                'escalera_courts_count' => 5,
                'escalera_standings_mode' => 'raw_points',
            ])
            ->assertRedirect(route('club.tournaments.index'));

        $tournament = Tournament::where('name', 'Эскалера вечер')->firstOrFail();
        $this->assertSame('escalera', $tournament->type);
        $this->assertSame(5, (int) $tournament->courts_count);
        $this->assertSame(20, (int) $tournament->max_participants, 'участников ровно кортов × 4');
        $this->assertSame('raw_points', $tournament->escalera_standings_mode);
    }

    public function test_seeding_page_shows_players_by_courts(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $admin = $this->clubAdmin($tournament);

        $this->actingAs($admin)
            ->get(route('club.escalera.seeding', $tournament))
            ->assertOk()
            ->assertSee('Корт 1')
            ->assertSee('Корт 3')
            ->assertSee('A1')
            ->assertSee('C4')
            ->assertSee('Начать турнир');
    }

    public function test_seeding_page_blocks_start_when_participants_do_not_match_courts(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        // Одиннадцать игроков при трёх кортах — не хватает одного.
        $ratings = [];
        for ($i = 1; $i <= 11; $i++) {
            $ratings['P' . $i] = 2000 - $i * 10;
        }
        $this->register($tournament, $ratings);
        $admin = $this->clubAdmin($tournament);

        $this->actingAs($admin)
            ->get(route('club.escalera.seeding', $tournament))
            ->assertOk()
            ->assertSee('не хватает')
            ->assertDontSee('Начать турнир');
    }

    public function test_save_seeding_keeps_arrangement_without_starting(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $admin = $this->clubAdmin($tournament);

        $order = collect(['C4', 'C3', 'C2', 'C1', 'B4', 'B3', 'B2', 'B1', 'A4', 'A3', 'A2', 'A1'])
            ->map(fn ($name) => $u[$name]->id)
            ->all();

        $this->actingAs($admin)
            ->post(route('club.escalera.saveSeeding', $tournament), ['order' => $order])
            ->assertRedirect(route('club.escalera.seeding', $tournament))
            ->assertSessionHas('escalera_seeding.' . $tournament->id, $order);

        // Турнир не начат: расстановка просто запомнена.
        $this->assertSame('open', $tournament->fresh()->status);
        $this->assertSame(0, $tournament->fresh()->escaleraRounds()->count());

        $this->actingAs($admin)
            ->get(route('club.escalera.seeding', $tournament))
            ->assertOk()
            ->assertSee('Начать турнир');
    }

    public function test_start_route_creates_round_and_redirects(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $u = $this->registerTwelve($tournament);
        $admin = $this->clubAdmin($tournament);

        // Ручная расстановка: порядок обратный рейтингу.
        $order = collect(['C4', 'C3', 'C2', 'C1', 'B4', 'B3', 'B2', 'B1', 'A4', 'A3', 'A2', 'A1'])
            ->map(fn ($name) => $u[$name]->id)
            ->all();

        $this->actingAs($admin)
            ->post(route('club.escalera.start', $tournament), ['order' => $order])
            ->assertRedirect(route('club.tournaments.show', $tournament));

        $tournament->refresh();
        $this->assertSame('in_progress', $tournament->status);
        $this->assertSame(1, $tournament->escaleraRounds()->count());
        $this->assertSame(3, $this->lastRound($tournament)->courts()->count());

        // Ручная расстановка учтена, а не пересобрана по рейтингу.
        $this->assertSame(['C4', 'C3', 'C2', 'C1'], $this->seatingNames($tournament, 1));
        $this->assertSame(['A4', 'A3', 'A2', 'A1'], $this->seatingNames($tournament, 3));
    }

    public function test_save_score_route_rejects_negative_and_accepts_any_sum(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $admin = $this->clubAdmin($tournament);
        $this->service()->startTournament($tournament);

        $match = $this->lastRound($tournament)->courts()->first()->matches()->first();

        // Отрицательный счёт отклоняется валидацией формы.
        $this->actingAs($admin)
            ->post(route('club.escalera.saveScore', $match), ['team1_score' => -1, 'team2_score' => 5])
            ->assertSessionHasErrors();

        $this->assertNull($match->fresh()->team1_score);
        $this->assertSame('pending', $match->fresh()->status);

        // Любая сумма — счёт сохраняется, формат матча на совести организатора.
        $this->actingAs($admin)
            ->post(route('club.escalera.saveScore', $match), ['team1_score' => 12, 'team2_score' => 10])
            ->assertSessionHasNoErrors();

        $this->assertSame(12, (int) $match->fresh()->team1_score);
        $this->assertSame('completed', $match->fresh()->status);

        // Правка идёт через PUT — этого маршрута ждёт общая модалка
        // club.tournaments.partials._modal_edit_score.
        $this->actingAs($admin)
            ->put(route('club.escalera.updateScore', $match), ['team1_score' => 6, 'team2_score' => 4])
            ->assertSessionHasNoErrors();

        $this->assertSame(6, (int) $match->fresh()->team1_score);
        $this->assertSame(4, (int) $match->fresh()->team2_score);
    }

    public function test_close_round_route_blocked_until_all_scores_entered(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $admin = $this->clubAdmin($tournament);
        $this->service()->startTournament($tournament);

        $this->actingAs($admin)
            ->post(route('club.escalera.closeRound', $tournament))
            ->assertSessionHas('error');

        $this->assertFalse($this->lastRound($tournament)->fresh()->isCompleted());

        // Все счета внесены — раунд закрывается.
        $this->playRound($tournament);
        $this->actingAs($admin)
            ->post(route('club.escalera.closeRound', $tournament))
            ->assertSessionHas('success');

        $this->assertTrue($this->lastRound($tournament)->fresh()->isCompleted());

        // И следующий раунд создаётся своим маршрутом.
        $this->actingAs($admin)
            ->post(route('club.escalera.nextRound', $tournament))
            ->assertSessionHas('success');

        $this->assertSame(2, $tournament->fresh()->escaleraRounds()->count());
    }

    public function test_tournament_page_shows_courts_and_standings(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $admin = $this->clubAdmin($tournament);
        $this->service()->startTournament($tournament);

        // Раунд только создан: карточки кортов есть, закрывать нечего.
        $firstMatch = $this->lastRound($tournament)->courts()->first()->matches()->first();
        $this->actingAs($admin)
            ->get(route('club.tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('Корт 1')
            ->assertSee('Корт 3')
            ->assertSee('A1')
            ->assertSee('C4')
            // Счёт вводится в общей модалке (partials._modal_score).
            ->assertSee('escScoreModal' . $firstMatch->id)
            ->assertSee('Ввод счёта')
            ->assertDontSee('Закрыть раунд');

        // Все счета внесены: показано превью перемещений и кнопка закрытия.
        $this->playRound($tournament);
        $this->actingAs($admin)
            ->get(route('club.tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('Закрыть раунд')
            ->assertSee('вверх')
            ->assertSee('вниз');

        // Раунд закрыт: таблица заполнена, предлагается следующий раунд.
        $this->service()->closeRound($tournament);
        $this->actingAs($admin)
            ->get(route('club.tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('Таблица')
            ->assertSee('Следующий раунд')
            ->assertSee('A1');
    }

    public function test_finish_route_completes_tournament_and_page_shows_awards(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $admin = $this->clubAdmin($tournament);
        $this->service()->startTournament($tournament);

        $this->playRound($tournament);
        $this->service()->closeRound($tournament);
        $this->service()->generateNextRound($tournament);
        // Второй раунд: на первом корте выигрывает поднявшийся снизу B1.
        $this->playRound($tournament, [1 => [[11, 1], [2, 10], [5, 7]]]);
        $this->service()->closeRound($tournament);

        $this->actingAs($admin)
            ->post(route('club.escalera.finish', $tournament))
            ->assertRedirect(route('club.tournaments.show', $tournament));

        $this->assertSame('completed', $tournament->fresh()->status);

        $this->actingAs($admin)
            ->get(route('club.tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('Чемпион')
            ->assertSee('Восхождение')
            ->assertSee('Король корта');
    }

    // ===== Перезапуск турнира =====

    public function test_reset_clears_escalera_tables_and_allows_second_start(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);
        $this->playRound($tournament);
        $this->service()->closeRound($tournament);

        app(TournamentResetService::class)->reset($tournament);

        $tournament->refresh();
        $this->assertSame('open', $tournament->status);
        $this->assertSame(0, $tournament->escaleraRounds()->count(), 'раунды удалены');
        $this->assertSame(0, $tournament->escaleraPlayers()->count(), 'участники формата удалены');
        $this->assertDatabaseCount('escalera_round_courts', 0);
        $this->assertDatabaseCount('escalera_matches', 0);
        $this->assertDatabaseCount('escalera_round_results', 0);

        // Повторный старт не должен упираться в уникальность (турнир + номер раунда).
        $this->assertTrue($this->service()->startTournament($tournament), 'турнир стартует заново');
        $this->assertSame(1, $tournament->fresh()->escaleraRounds()->count());
        $this->assertSame('in_progress', $tournament->fresh()->status);
    }

    public function test_can_restart_closes_after_first_round_is_closed(): void
    {
        $tournament = $this->makeTournament(courts: 3);
        $this->registerTwelve($tournament);
        $this->service()->startTournament($tournament);

        $this->assertTrue($tournament->fresh()->canRestart(), 'первый раунд ещё идёт — перезапуск доступен');

        $this->playRound($tournament);
        $this->service()->closeRound($tournament);

        $this->assertFalse(
            $tournament->fresh()->canRestart(),
            'первый раунд закрыт — перезапуск больше не предлагаем'
        );
    }

    // ===== Редактирование параметров формата =====

    public function test_editing_courts_count_recalculates_participants(): void
    {
        $tournament = $this->makeTournament(courts: 4, mode: 'points');
        $admin = $this->clubAdmin($tournament);

        // Форма редактирования показывает параметры формата.
        $this->actingAs($admin)
            ->get(route('club.tournaments.edit', $tournament))
            ->assertOk()
            ->assertSee('Количество кортов')
            ->assertSee('Итоговая таблица');

        $this->actingAs($admin)
            ->put(route('club.tournaments.update', $tournament), [
                'name' => 'Эскалера',
                'start_date' => now()->addDay()->format('Y-m-d\TH:i'),
                'min_level' => '1.00',
                'max_level' => '5.75',
                // Из формы приходит старое значение — оно должно быть пересчитано.
                'max_participants' => 16,
                'status' => 'open',
                'escalera_courts_count' => 3,
                'escalera_standings_mode' => 'raw_points',
            ])
            ->assertRedirect();

        $tournament->refresh();
        $this->assertSame(3, (int) $tournament->courts_count);
        $this->assertSame(12, (int) $tournament->max_participants, 'участников ровно кортов × 4');
        $this->assertSame('raw_points', $tournament->escalera_standings_mode);
    }

    public function test_format_settings_are_locked_after_start(): void
    {
        $tournament = $this->makeTournament(courts: 3, mode: 'points');
        $this->registerTwelve($tournament);
        $admin = $this->clubAdmin($tournament);
        $this->service()->startTournament($tournament);

        // Поля формата на форме неактивны — менять после старта нечего.
        $this->actingAs($admin)
            ->get(route('club.tournaments.edit', $tournament))
            ->assertOk()
            ->assertSee('Турнир уже начат — количество кортов менять нельзя');

        $this->actingAs($admin)
            ->put(route('club.tournaments.update', $tournament), [
                'name' => 'Эскалера',
                // Дату не меняем: её правка рассылает участникам пуши, а нас
                // здесь интересуют только параметры формата.
                'start_date' => $tournament->start_date->format('Y-m-d\TH:i'),
                'min_level' => '1.00',
                'max_level' => '5.75',
                'max_participants' => 40,
                'status' => 'in_progress',
                'escalera_courts_count' => 10,
                'escalera_standings_mode' => 'raw_points',
            ])
            ->assertRedirect();

        $tournament->refresh();
        $this->assertSame(3, (int) $tournament->courts_count, 'корты после старта не меняются');
        $this->assertSame(12, (int) $tournament->max_participants, 'участники после старта не меняются');
        $this->assertSame('points', $tournament->escalera_standings_mode);
    }

    // ===== Устойчивость таблицы =====

    public function test_standings_tie_break_ignores_rating_earned_in_tournament(): void
    {
        // Все матчи вничью 6:6 — у всех восьмерых поровну очков, побед и личных
        // встреч, поэтому решает последний тай-брейк, рейтинг.
        $tournament = $this->makeTournament(courts: 2, mode: 'raw_points');
        $u = $this->register($tournament, [
            'A1' => 2000, 'A2' => 1900, 'A3' => 1800, 'A4' => 1700,
            'B1' => 1600, 'B2' => 1500, 'B3' => 1400, 'B4' => 1300,
        ]);
        $this->service()->startTournament($tournament);

        $draws = [[6, 6], [6, 6], [6, 6]];
        $this->playRound($tournament, [1 => $draws, 2 => $draws]);
        $this->service()->closeRound($tournament);

        $before = collect($this->service()->standings($tournament))->pluck('user_id')->all();
        $this->assertSame(
            collect(['A1', 'A2', 'A3', 'A4', 'B1', 'B2', 'B3', 'B4'])->map(fn ($n) => $u[$n]->id)->all(),
            $before,
            'при полном равенстве порядок задаёт рейтинг на старте'
        );

        // Имитируем начисление рейтинга: живой рейтинг переворачивается.
        $inverted = ['A1' => 1300, 'A2' => 1400, 'A3' => 1500, 'A4' => 1600,
            'B1' => 1700, 'B2' => 1800, 'B3' => 1900, 'B4' => 2000];
        foreach ($inverted as $name => $rating) {
            $u[$name]->update(['rating' => $rating]);
        }

        $after = collect($this->service()->standings($tournament))->pluck('user_id')->all();
        $this->assertSame($before, $after, 'таблица не переупорядочивается от начисленного рейтинга');
    }
}

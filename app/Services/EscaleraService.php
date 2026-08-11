<?php

namespace App\Services;

use App\Models\EscaleraMatch;
use App\Models\EscaleraPlayer;
use App\Models\EscaleraRound;
use App\Models\EscaleraRoundCourt;
use App\Models\EscaleraRoundResult;
use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Логика формата «Ladder».
 *
 * Корты выстроены сверху вниз, на каждом четыре игрока и три коротких матча
 * за раунд. В общую таблицу идут не очки, а позиция игрока в общем строю всех
 * участников — номер корта уже встроен в это число, поэтому единственный
 * способ улучшить результат — подняться на корт выше.
 */
class EscaleraService
{
    use \App\Traits\RatingCalculator;

    /** Позиция в общем строю: корты упорядочены по силе, на каждом четверо. */
    public function positionFor(int $courtNumber, int $place): int
    {
        return ($courtNumber - 1) * 4 + $place;
    }

    /** Баллы за позицию: первый в строю получает столько, сколько всего игроков. */
    public function pointsFor(int $position, int $totalPlayers): int
    {
        return $totalPlayers - $position + 1;
    }

    /**
     * Три матча из посадки: каждый играет в паре с каждым по разу.
     * Первым идёт самый ровный матч — сильнейший с четвёртым.
     *
     * @param  array<int, int> $seating четыре id в порядке посадки
     * @return array<int, array{0: array<int,int>, 1: array<int,int>}>
     */
    public function matchLineup(array $seating): array
    {
        if (count($seating) !== 4) {
            throw new InvalidArgumentException(
                'Посадка должна содержать ровно четыре id игроков, получено: ' . count($seating)
            );
        }

        [$p1, $p2, $p3, $p4] = array_values($seating);

        return [
            [[$p1, $p4], [$p2, $p3]],
            [[$p1, $p3], [$p2, $p4]],
            [[$p1, $p2], [$p3, $p4]],
        ];
    }

    /**
     * Ранжировать четвёрку на корте. Возвращает id игроков в порядке мест
     * с первого по четвёртое.
     *
     * Ранжирование всегда по сумме очков за три матча — второй режим (по
     * числу побед) убран: математически он тождественен первому, потому что
     * внутри четвёрки любые двое ровно в одном матче партнёры (одинаковый
     * счёт) и ровно в двух — соперники, а значит больше побед всегда
     * означает больше очков. Полное равенство решается рейтингом.
     *
     * @return array<int, int>
     */
    public function rankCourt(EscaleraRoundCourt $court): array
    {
        $ids = $court->playerIds();
        $stats = [];
        foreach ($ids as $id) {
            $stats[$id] = ['points' => 0, 'rating' => 0];
        }

        // Рейтинги нужны как последний разделитель при полном равенстве.
        $ratings = User::whereIn('id', $ids)->pluck('rating', 'id');
        foreach ($ids as $id) {
            $stats[$id]['rating'] = (int) ($ratings[$id] ?? 0);
        }

        foreach ($court->matches as $match) {
            $team1 = [$match->team1_player1_id, $match->team1_player2_id];
            $team2 = [$match->team2_player1_id, $match->team2_player2_id];
            $s1 = (int) $match->team1_score;
            $s2 = (int) $match->team2_score;

            foreach ($team1 as $id) {
                $stats[$id]['points'] += $s1;
            }
            foreach ($team2 as $id) {
                $stats[$id]['points'] += $s2;
            }
        }

        $order = $ids;
        usort($order, function ($a, $b) use ($stats) {
            if ($stats[$a]['points'] !== $stats[$b]['points']) {
                return $stats[$b]['points'] <=> $stats[$a]['points'];
            }

            // Полное равенство — выше игрок с большим рейтингом.
            return $stats[$b]['rating'] <=> $stats[$a]['rating'];
        });

        return $order;
    }

    /**
     * Куда поедут игроки после раунда.
     * Первый на корте вверх, четвёртый вниз, двое средних остаются.
     * На верхнем корте вниз уходит только четвёртый, на нижнем вверх — только первый.
     *
     * @param  array<int, array<int,int>> $courtRankings корт => четвёрка по местам
     * @return array<int, array<int,int>> корт => состав на следующий раунд
     */
    public function planMovements(array $courtRankings): array
    {
        if (empty($courtRankings)) {
            throw new InvalidArgumentException('Нет ни одного корта для расчёта перемещений');
        }

        ksort($courtRankings);
        $courts = array_keys($courtRankings);
        $top = min($courts);
        $bottom = max($courts);

        $next = [];
        foreach ($courts as $court) {
            $next[$court] = [];
        }

        foreach ($courtRankings as $court => $places) {
            [$first, $second, $third, $fourth] = $places;

            // Первый идёт наверх, но с верхнего корта уходить некуда.
            if ($court === $top) {
                $next[$court][] = $first;
            } else {
                $next[$court - 1][] = $first;
            }

            // Двое средних всегда остаются.
            $next[$court][] = $second;
            $next[$court][] = $third;

            // Четвёртый идёт вниз, но с нижнего корта опускаться некуда.
            if ($court === $bottom) {
                $next[$court][] = $fourth;
            } else {
                $next[$court + 1][] = $fourth;
            }
        }

        // Целостность: после всех перемещений на каждом корте ровно четверо.
        foreach ($next as $court => $players) {
            if (count($players) !== 4) {
                throw new RuntimeException(
                    "После перемещений на корте {$court} оказалось " . count($players) . " игроков вместо четырёх"
                );
            }
        }

        return $next;
    }

    // ===================== Проведение турнира =====================

    /**
     * Старт турнира: посев по рейтингу и первый раунд.
     *
     * Участников должно быть ровно `корты × 4` — иначе стартовать нельзя.
     * $order — ручная расстановка (id игроков сверху вниз), сделанная админом
     * на странице стартовой посадки; без неё сеем по рейтингу.
     */
    public function startTournament(Tournament $tournament, ?array $order = null): bool
    {
        if (!$tournament->isEscalera()) {
            return false;
        }
        if ($tournament->status !== 'open') {
            return false;
        }

        $courtsCount = (int) $tournament->courts_count;
        if ($courtsCount < 2) {
            return false;
        }

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->get();

        if ($participants->count() !== $courtsCount * 4) {
            return false;
        }

        $seeded = $this->seedParticipants($participants, $order);

        DB::transaction(function () use ($tournament, $seeded) {
            $seating = [];

            foreach ($seeded as $index => $user) {
                // Первые четверо на первый корт, следующие четверо на второй и так далее.
                $courtNumber = intdiv($index, 4) + 1;

                EscaleraPlayer::updateOrCreate(
                    ['tournament_id' => $tournament->id, 'user_id' => $user->id],
                    [
                        'total_points' => 0,
                        'total_raw_points' => 0,
                        'wins' => 0,
                        'start_court' => $courtNumber,
                        'current_court' => $courtNumber,
                        'rating_before' => (int) ($user->rating ?? 0),
                        'rating_after' => null,
                    ]
                );

                $seating[$courtNumber][] = $user->id;
            }

            $this->createRound($tournament, 1, $seating);
            $tournament->update(['status' => 'in_progress']);
        });

        return true;
    }

    /**
     * Сохранить счёт короткого матча.
     *
     * Формат матча организатор задаёт сам на площадке, поэтому счёт принимается
     * любой — проверяем только неотрицательность.
     *
     * Если раунд уже закрыт, правка счёта пересчитывает места, баллы и таблицу,
     * но игроков по кортам не двигает — перемещения уже произошли.
     *
     * В завершённом турнире правка запрещена: рейтинг уже начислен по старым
     * счетам, и таблица разошлась бы с ним.
     */
    public function saveMatchResult(EscaleraMatch $match, int $team1Score, int $team2Score): void
    {
        $court = $match->court;
        $round = $court->round;
        $tournament = $round->tournament;

        if ($tournament->status === 'completed') {
            throw new RuntimeException(
                'Турнир уже завершён: рейтинг начислен по этим счетам, менять их нельзя'
            );
        }

        if ($team1Score < 0 || $team2Score < 0) {
            throw new InvalidArgumentException('Очки не могут быть отрицательными');
        }

        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'status' => 'completed',
        ]);

        if ($round->isCompleted()) {
            DB::transaction(function () use ($round, $tournament) {
                $this->writeRoundResults($round, $tournament, $this->computeRankings($round));
                $this->refreshTotals($tournament);
            });
        }
    }

    /** Все ли счета текущего раунда внесены — от этого зависит закрытие раунда. */
    public function canCloseRound(Tournament $tournament): bool
    {
        $round = $this->currentRound($tournament);
        if (!$round) {
            return false;
        }

        $courts = $round->courts()->with('matches')->get();
        if ($courts->isEmpty()) {
            return false;
        }

        foreach ($courts as $court) {
            if ($court->matches->count() !== 3) {
                return false;
            }
            foreach ($court->matches as $match) {
                if (!$match->isCompleted()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Превью закрытия раунда: места на кортах, позиции, баллы и стрелки
     * перемещений. Ничего не записывает.
     *
     * @return array<int, array{court_number:int, places: array<int, array<string, mixed>>}>
     */
    public function previewRoundClose(Tournament $tournament): array
    {
        $round = $this->currentRound($tournament);
        if (!$round) {
            return [];
        }

        $totalPlayers = $tournament->escaleraPlayers()->count();
        $courts = $round->courts()->with('matches')->get();
        $courtNumbers = $courts->pluck('court_number')->map(fn ($n) => (int) $n)->all();
        $top = $courtNumbers ? min($courtNumbers) : 1;
        $bottom = $courtNumbers ? max($courtNumbers) : 1;

        $users = User::whereIn('id', $tournament->escaleraPlayers()->pluck('user_id'))->get()->keyBy('id');

        $preview = [];
        foreach ($courts as $court) {
            $courtNumber = (int) $court->court_number;
            $order = $this->rankCourt($court);
            $scored = $this->courtRawPoints($court);

            $places = [];
            foreach ($order as $i => $userId) {
                $place = $i + 1;
                $position = $this->positionFor($courtNumber, $place);

                // Первый едет вверх, четвёртый вниз; с крайних кортов ехать некуда.
                $movement = 'stay';
                if ($place === 1 && $courtNumber !== $top) {
                    $movement = 'up';
                } elseif ($place === 4 && $courtNumber !== $bottom) {
                    $movement = 'down';
                }

                $places[] = [
                    'user_id' => $userId,
                    'user' => $users[$userId] ?? null,
                    'place' => $place,
                    'court_points' => $scored[$userId] ?? 0,
                    'overall_position' => $position,
                    'points' => $this->pointsFor($position, $totalPlayers),
                    'movement' => $movement,
                ];
            }

            $preview[] = ['court_number' => $courtNumber, 'places' => $places];
        }

        return $preview;
    }

    /**
     * Закрыть раунд: записать места, позиции и баллы, обновить суммы игроков
     * и развезти всех по новым кортам.
     */
    public function closeRound(Tournament $tournament): bool
    {
        if (!$this->canCloseRound($tournament)) {
            return false;
        }

        $round = $this->currentRound($tournament);
        $rankings = $this->computeRankings($round);

        // Считаем перемещения до записи: если где-то окажется не четверо,
        // планировщик бросит исключение и раунд останется незакрытым.
        $movements = $this->planMovements($rankings);

        DB::transaction(function () use ($tournament, $round, $rankings, $movements) {
            $this->writeRoundResults($round, $tournament, $rankings);

            foreach ($movements as $courtNumber => $userIds) {
                EscaleraPlayer::where('tournament_id', $tournament->id)
                    ->whereIn('user_id', $userIds)
                    ->update(['current_court' => $courtNumber]);
            }

            $this->refreshTotals($tournament);
            $round->update(['status' => 'completed']);
        });

        return true;
    }

    /**
     * Создать следующий раунд из текущих кортов игроков.
     * Посадка внутри корта — по текущему месту в общей таблице: лидер первым.
     */
    public function generateNextRound(Tournament $tournament): bool
    {
        if (!$tournament->isEscalera() || $tournament->status !== 'in_progress') {
            return false;
        }

        $last = $tournament->escaleraRounds()->reorder('round_number', 'desc')->first();
        if (!$last || !$last->isCompleted()) {
            return false;
        }

        // standings отсортирована сверху вниз, поэтому на каждом корте первым
        // окажется тот, кто выше в общей таблице.
        $seating = [];
        foreach ($this->standings($tournament) as $row) {
            $seating[(int) $row['current_court']][] = $row['user_id'];
        }
        ksort($seating);

        $round = null;
        DB::transaction(function () use ($tournament, $last, $seating, &$round) {
            $round = $this->createRound($tournament, (int) $last->round_number + 1, $seating);
        });

        return $round !== null;
    }

    /**
     * Итоговая таблица.
     *
     * Сортировка зависит от режима `escalera_standings_mode`: по сумме баллов
     * за позиции либо по сумме очков за все короткие матчи. Тай-брейки по
     * порядку: основное число режима, выигранные короткие матчи, личная
     * встреча (сумма очков в очных матчах), рейтинг.
     *
     * @return array<int, array<string, mixed>>
     */
    public function standings(Tournament $tournament): array
    {
        $stats = $this->collectStats($tournament);
        if (empty($stats)) {
            return [];
        }

        $mode = $tournament->escalera_standings_mode === 'raw_points' ? 'raw_points' : 'points';
        $headToHead = $this->headToHead($tournament);
        $ordered = $this->sortPlayers(array_keys($stats), $stats, $mode, $headToHead);
        $playedCourts = $this->playedCourts($tournament);

        // Изменение позиции считаем относительно таблицы на конец прошлого
        // закрытого раунда. После первого раунда сравнивать не с чем.
        $previousPositions = [];
        $lastClosed = (int) $tournament->escaleraRounds()
            ->where('status', 'completed')
            ->max('round_number');

        if ($lastClosed > 1) {
            $previousStats = $this->collectStats($tournament, $lastClosed - 1);
            $previousOrder = $this->sortPlayers(
                array_keys($previousStats),
                $previousStats,
                $mode,
                $this->headToHead($tournament, $lastClosed - 1)
            );
            foreach ($previousOrder as $i => $userId) {
                $previousPositions[$userId] = $i + 1;
            }
        }

        $rows = [];
        foreach ($ordered as $i => $userId) {
            $position = $i + 1;
            $stat = $stats[$userId];
            $currentCourt = (int) $stat['player']->current_court;
            // Корт, где игрок реально доигрывал последний закрытый раунд.
            $finalCourt = $playedCourts[$userId] ?? $currentCourt;

            $rows[] = [
                'position' => $position,
                'user_id' => $userId,
                'user' => $stat['user'],
                'player' => $stat['player'],
                'points' => $stat['points'],
                'raw_points' => $stat['raw_points'],
                'points_against' => $stat['points_against'],
                'wins' => $stat['wins'],
                'losses' => $stat['losses'],
                'start_court' => (int) $stat['player']->start_court,
                // Пока турнир идёт, «текущий корт» — это корт следующего раунда.
                // После завершения следующего раунда не будет, поэтому
                // показываем корт, на котором игрок действительно доиграл.
                'current_court' => $tournament->status === 'completed' ? $finalCourt : $currentCourt,
                'final_court' => $finalCourt,
                'change' => isset($previousPositions[$userId])
                    ? $previousPositions[$userId] - $position
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Три награды турнира.
     *
     * Чемпион — лидер итоговой таблицы (в родном режиме это и есть наибольшая
     * сумма баллов). «Восхождение» — наибольшая разница между стартовым и
     * финальным кортом, при равенстве выше тот, чей финальный корт выше.
     * «Король корта» — победитель последнего закрытого раунда на первом корте.
     *
     * Финальный корт — тот, где игрок реально играл последний закрытый раунд,
     * а не `current_court`: закрытие раунда применяет перемещения и в последнем
     * раунде тоже, поэтому `current_court` в конце турнира — это корт, куда
     * игрок ПОЕХАЛ БЫ дальше. Иначе награду за подъём получал бы тот, кто на
     * верхнем корте не сыграл ни одного матча.
     *
     * @return array{champion: ?array, ascent: ?array, king_of_court: ?array}
     */
    public function awards(Tournament $tournament): array
    {
        $standings = $this->standings($tournament);
        if (empty($standings)) {
            return ['champion' => null, 'ascent' => null, 'king_of_court' => null];
        }

        $first = $standings[0];
        $champion = [
            'user' => $first['user'],
            'user_id' => $first['user_id'],
            'points' => $first['points'],
            'raw_points' => $first['raw_points'],
        ];

        // «Восхождение»: считаем по кортам, а не по баллам — награда именно за подъём.
        $ascent = null;
        foreach ($standings as $row) {
            $climb = $row['start_court'] - $row['final_court'];
            // Не поднялся — награждать не за что.
            if ($climb <= 0) {
                continue;
            }

            $better = $ascent === null
                || $climb > $ascent['climb']
                // При равном подъёме выше тот, чей финальный корт выше.
                || ($climb === $ascent['climb'] && $row['final_court'] < $ascent['final_court']);

            if ($better) {
                $ascent = [
                    'user' => $row['user'],
                    'user_id' => $row['user_id'],
                    'climb' => $climb,
                    'start_court' => $row['start_court'],
                    'final_court' => $row['final_court'],
                ];
            }
        }

        // «Король корта»: первое место на первом корте в последнем закрытом раунде.
        $kingOfCourt = null;
        $lastRound = $tournament->escaleraRounds()
            ->where('status', 'completed')
            ->reorder('round_number', 'desc')
            ->first();

        if ($lastRound) {
            $result = EscaleraRoundResult::where('escalera_round_id', $lastRound->id)
                ->where('court_number', 1)
                ->where('place_on_court', 1)
                ->first();

            if ($result) {
                $kingOfCourt = [
                    'user' => User::find($result->user_id),
                    'user_id' => $result->user_id,
                    'round_number' => (int) $lastRound->round_number,
                ];
            }
        }

        return [
            'champion' => $champion,
            'ascent' => $ascent,
            'king_of_court' => $kingOfCourt,
        ];
    }

    /** Можно ли завершить турнир: последний раунд закрыт, турнир идёт. */
    public function canFinishTournament(Tournament $tournament): bool
    {
        if (!$tournament->isEscalera()) {
            return false;
        }
        // Статус `in_progress` — единственная точка, где начисляется рейтинг.
        // Завершённый турнир сюда уже не пройдёт, поэтому повторный вызов
        // finishTournament ничего не начислит второй раз.
        if ($tournament->status !== 'in_progress') {
            return false;
        }

        $lastRound = $tournament->escaleraRounds()->reorder('round_number', 'desc')->first();

        return $lastRound && $lastRound->isCompleted();
    }

    /**
     * Завершить турнир: начислить рейтинг по каждому короткому матчу
     * (парная формула ELO, как в остальных форматах) и закрыть турнир.
     */
    public function finishTournament(Tournament $tournament): bool
    {
        if (!$this->canFinishTournament($tournament)) {
            return false;
        }

        // Нерейтинговый турнир — просто закрываем. Если флаг не подгружен
        // (свежесозданная модель без значения из БД), считаем турнир
        // рейтинговым — в базе по умолчанию именно так.
        $isRated = $tournament->is_rated === null ? true : (bool) $tournament->is_rated;
        if (!$isRated) {
            $tournament->update(['status' => 'completed']);
            return true;
        }

        $players = $tournament->escaleraPlayers()->with('user')->get();

        $ratingChanges = [];
        foreach ($players as $player) {
            $ratingChanges[$player->user_id] = [
                'rating_before' => (int) $player->rating_before,
                'current_rating' => (int) $player->rating_before,
            ];
        }

        // Идём по матчам в порядке раундов и кортов — рейтинг накапливается.
        foreach ($tournament->escaleraRounds()->with('courts.matches')->orderBy('round_number')->get() as $round) {
            foreach ($round->courts as $court) {
                foreach ($court->matches as $match) {
                    if (!$match->isCompleted()) {
                        continue;
                    }
                    $this->calculateEloForMatch($match, $ratingChanges);
                }
            }
        }

        DB::transaction(function () use ($tournament, $players, $ratingChanges) {
            foreach ($players as $player) {
                $delta = (int) $ratingChanges[$player->user_id]['current_rating'] - (int) $player->rating_before;
                $actualBefore = (int) ($player->user->rating ?? $player->rating_before);
                $actualAfter = max($this->minRating, $actualBefore + $delta);

                $player->update(['rating_after' => $actualAfter]);
                $player->user->update(['rating' => $actualAfter]);
                $this->updateLevel($player->user->fresh());

                RatingHistory::create([
                    'user_id' => $player->user_id,
                    'tournament_id' => $tournament->id,
                    'rating_before' => $actualBefore,
                    'rating_after' => $actualAfter,
                    'change' => $delta,
                    'reason' => $tournament->name,
                ]);
            }

            $tournament->update(['status' => 'completed']);
        });

        return true;
    }

    /** ELO за один короткий матч: 2 на 2, средние рейтинги команд → дельта. */
    public function calculateEloForMatch(EscaleraMatch $match, array &$ratingChanges): void
    {
        $p11 = $match->team1_player1_id;
        $p12 = $match->team1_player2_id;
        $p21 = $match->team2_player1_id;
        $p22 = $match->team2_player2_id;

        if (!isset($ratingChanges[$p11], $ratingChanges[$p12], $ratingChanges[$p21], $ratingChanges[$p22])) {
            return;
        }

        $team1Rating = ($ratingChanges[$p11]['current_rating'] + $ratingChanges[$p12]['current_rating']) / 2;
        $team2Rating = ($ratingChanges[$p21]['current_rating'] + $ratingChanges[$p22]['current_rating']) / 2;

        $result = $this->calculateRatingChange(
            $team1Rating,
            $team2Rating,
            (int) $match->team1_score,
            (int) $match->team2_score
        );

        $ratingChanges[$p11]['current_rating'] = $this->applyRatingChange($ratingChanges[$p11]['current_rating'], $result['change1']);
        $ratingChanges[$p12]['current_rating'] = $this->applyRatingChange($ratingChanges[$p12]['current_rating'], $result['change1']);
        $ratingChanges[$p21]['current_rating'] = $this->applyRatingChange($ratingChanges[$p21]['current_rating'], $result['change2']);
        $ratingChanges[$p22]['current_rating'] = $this->applyRatingChange($ratingChanges[$p22]['current_rating'], $result['change2']);
    }

    /**
     * Корт, на котором каждый игрок реально играл в последнем закрытом раунде.
     *
     * `closeRound` применяет перемещения и к последнему раунду тоже, поэтому
     * `escalera_players.current_court` после конца турнира — это корт, куда
     * игрок поехал бы в следующем раунде, а не тот, где он доиграл. Для наград
     * и для итоговой таблицы нужен именно сыгранный корт.
     *
     * @return array<int, int> id игрока => номер корта
     */
    public function playedCourts(Tournament $tournament): array
    {
        $lastRound = $tournament->escaleraRounds()
            ->where('status', 'completed')
            ->reorder('round_number', 'desc')
            ->first();

        if (!$lastRound) {
            return [];
        }

        return EscaleraRoundResult::where('escalera_round_id', $lastRound->id)
            ->pluck('court_number', 'user_id')
            ->map(fn ($number) => (int) $number)
            ->all();
    }

    /** Текущий раунд — последний незакрытый. */
    public function currentRound(Tournament $tournament): ?EscaleraRound
    {
        $last = $tournament->escaleraRounds()->reorder('round_number', 'desc')->first();

        return ($last && !$last->isCompleted()) ? $last : null;
    }

    // ===================== Внутреннее =====================

    /**
     * Порядок посева: по рейтингу сверху вниз, игроки без рейтинга — ниже всех.
     * $order — ручная расстановка админа (id сверху вниз); забытые дописываются
     * в конец по рейтингу.
     *
     * @param  Collection<int, User> $participants
     * @return array<int, User>
     */
    protected function seedParticipants(Collection $participants, ?array $order = null): array
    {
        $byRating = $participants->all();
        usort($byRating, function (User $a, User $b) {
            $ratingA = $a->rating === null ? PHP_INT_MIN : (int) $a->rating;
            $ratingB = $b->rating === null ? PHP_INT_MIN : (int) $b->rating;

            if ($ratingA !== $ratingB) {
                return $ratingB <=> $ratingA;
            }

            // Равный рейтинг — по id, чтобы расстановка была детерминированной.
            return $a->id <=> $b->id;
        });

        if (empty($order)) {
            return $byRating;
        }

        $byId = $participants->keyBy('id');
        $seeded = [];
        foreach ($order as $userId) {
            if ($byId->has($userId) && !isset($seeded[$userId])) {
                $seeded[$userId] = $byId->get($userId);
            }
        }
        foreach ($byRating as $user) {
            if (!isset($seeded[$user->id])) {
                $seeded[$user->id] = $user;
            }
        }

        return array_values($seeded);
    }

    /**
     * Создать раунд с кортами и тремя матчами на каждом.
     *
     * @param  array<int, array<int,int>> $seating корт => четыре id в порядке посадки
     */
    protected function createRound(Tournament $tournament, int $roundNumber, array $seating): EscaleraRound
    {
        ksort($seating);

        foreach ($seating as $courtNumber => $players) {
            if (count($players) !== 4) {
                throw new RuntimeException(
                    "На корте {$courtNumber} " . count($players) . " игроков вместо четырёх — раунд не создан"
                );
            }
        }

        $round = EscaleraRound::create([
            'tournament_id' => $tournament->id,
            'round_number' => $roundNumber,
            'status' => 'in_progress',
        ]);

        foreach ($seating as $courtNumber => $players) {
            $court = EscaleraRoundCourt::create([
                'escalera_round_id' => $round->id,
                'court_number' => $courtNumber,
                'player1_id' => $players[0],
                'player2_id' => $players[1],
                'player3_id' => $players[2],
                'player4_id' => $players[3],
            ]);

            foreach ($this->matchLineup($players) as $i => [$team1, $team2]) {
                EscaleraMatch::create([
                    'escalera_round_court_id' => $court->id,
                    'match_number' => $i + 1,
                    'team1_player1_id' => $team1[0],
                    'team1_player2_id' => $team1[1],
                    'team2_player1_id' => $team2[0],
                    'team2_player2_id' => $team2[1],
                    'team1_score' => null,
                    'team2_score' => null,
                    'status' => 'pending',
                ]);
            }
        }

        return $round;
    }

    /**
     * Места на каждом корте раунда.
     *
     * @return array<int, array<int,int>> корт => четвёрка id по местам
     */
    protected function computeRankings(EscaleraRound $round): array
    {
        $rankings = [];
        foreach ($round->courts()->with('matches')->get() as $court) {
            $rankings[(int) $court->court_number] = $this->rankCourt($court);
        }
        ksort($rankings);

        return $rankings;
    }

    /**
     * Записать результаты раунда: место на корте, позиция в общем строю и баллы.
     * Позиции и баллы считаются всегда, независимо от режима таблицы, —
     * от них зависят перемещения и награда «Восхождение».
     *
     * @param  array<int, array<int,int>> $rankings
     */
    protected function writeRoundResults(EscaleraRound $round, Tournament $tournament, array $rankings): void
    {
        $totalPlayers = $tournament->escaleraPlayers()->count();

        foreach ($rankings as $courtNumber => $order) {
            foreach ($order as $i => $userId) {
                $place = $i + 1;
                $position = $this->positionFor((int) $courtNumber, $place);

                EscaleraRoundResult::updateOrCreate(
                    ['escalera_round_id' => $round->id, 'user_id' => $userId],
                    [
                        'court_number' => $courtNumber,
                        'place_on_court' => $place,
                        'overall_position' => $position,
                        'points' => $this->pointsFor($position, $totalPlayers),
                    ]
                );
            }
        }
    }

    /**
     * Пересчитать суммы игроков по всем раундам заново.
     * Пересчёт целиком, а не приращением, — чтобы правка счёта задним числом
     * давала те же числа, что и обычное закрытие раунда.
     */
    protected function refreshTotals(Tournament $tournament): void
    {
        $stats = $this->collectStats($tournament);

        foreach ($tournament->escaleraPlayers()->get() as $player) {
            $stat = $stats[$player->user_id] ?? null;
            if (!$stat) {
                continue;
            }

            $player->update([
                'total_points' => $stat['points'],
                'total_raw_points' => $stat['raw_points'],
                'wins' => $stat['wins'],
            ]);
        }
    }

    /**
     * Сводка по каждому игроку: баллы за позиции, очки за матчи, победы.
     * $throughRound — считать только по раунды с этим номером включительно
     * (нужно для колонки «изменение позиции»).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function collectStats(Tournament $tournament, ?int $throughRound = null): array
    {
        $stats = [];
        foreach ($tournament->escaleraPlayers()->with('user')->get() as $player) {
            $stats[$player->user_id] = [
                'player' => $player,
                'user' => $player->user,
                'points' => 0,
                'raw_points' => 0,
                'points_against' => 0,
                'wins' => 0,
                'losses' => 0,
                // Последний тай-брейк таблицы — рейтинг НА СТАРТЕ турнира.
                // Живой рейтинг брать нельзя: завершение турнира его переписывает,
                // и таблица (а с ней и чемпион) переупорядочилась бы задним числом.
                'rating' => (int) ($player->rating_before ?? $player->user->rating ?? 0),
            ];
        }

        $rounds = $tournament->escaleraRounds()->with(['courts.matches', 'results'])->get();

        foreach ($rounds as $round) {
            if ($throughRound !== null && (int) $round->round_number > $throughRound) {
                continue;
            }

            // Баллы за позиции есть только у закрытых раундов.
            foreach ($round->results as $result) {
                if (isset($stats[$result->user_id])) {
                    $stats[$result->user_id]['points'] += (int) $result->points;
                }
            }

            foreach ($round->courts as $court) {
                foreach ($court->matches as $match) {
                    if (!$match->isCompleted()) {
                        continue;
                    }

                    $score1 = (int) $match->team1_score;
                    $score2 = (int) $match->team2_score;
                    $team1 = [$match->team1_player1_id, $match->team1_player2_id];
                    $team2 = [$match->team2_player1_id, $match->team2_player2_id];

                    // Счёт свободный, поэтому возможна ничья: она не победа и
                    // не поражение — на месте в таблице сказывается только очками.
                    foreach ($team1 as $id) {
                        if (!isset($stats[$id])) {
                            continue;
                        }
                        $stats[$id]['raw_points'] += $score1;
                        $stats[$id]['points_against'] += $score2;
                        if ($score1 > $score2) {
                            $stats[$id]['wins']++;
                        } elseif ($score2 > $score1) {
                            $stats[$id]['losses']++;
                        }
                    }
                    foreach ($team2 as $id) {
                        if (!isset($stats[$id])) {
                            continue;
                        }
                        $stats[$id]['raw_points'] += $score2;
                        $stats[$id]['points_against'] += $score1;
                        if ($score2 > $score1) {
                            $stats[$id]['wins']++;
                        } elseif ($score1 > $score2) {
                            $stats[$id]['losses']++;
                        }
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Личная встреча: сколько очков игрок набрал против каждого соперника.
     * Игроки могли ни разу не пересечься — тогда тай-брейк пропускается.
     *
     * @return array<int, array<int, int>>
     */
    protected function headToHead(Tournament $tournament, ?int $throughRound = null): array
    {
        $map = [];

        foreach ($tournament->escaleraRounds()->with('courts.matches')->get() as $round) {
            if ($throughRound !== null && (int) $round->round_number > $throughRound) {
                continue;
            }

            foreach ($round->courts as $court) {
                foreach ($court->matches as $match) {
                    if (!$match->isCompleted()) {
                        continue;
                    }

                    $score1 = (int) $match->team1_score;
                    $score2 = (int) $match->team2_score;
                    $team1 = [$match->team1_player1_id, $match->team1_player2_id];
                    $team2 = [$match->team2_player1_id, $match->team2_player2_id];

                    foreach ($team1 as $a) {
                        foreach ($team2 as $b) {
                            $map[$a][$b] = ($map[$a][$b] ?? 0) + $score1;
                            $map[$b][$a] = ($map[$b][$a] ?? 0) + $score2;
                        }
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Сортировка игроков для итоговой таблицы.
     *
     * @param  array<int, int> $ids
     * @param  array<int, array<string, mixed>> $stats
     * @param  array<int, array<int, int>> $headToHead
     * @return array<int, int>
     */
    protected function sortPlayers(array $ids, array $stats, string $mode, array $headToHead): array
    {
        $main = $mode === 'raw_points' ? 'raw_points' : 'points';

        usort($ids, function ($a, $b) use ($stats, $main, $headToHead) {
            if ($stats[$a][$main] !== $stats[$b][$main]) {
                return $stats[$b][$main] <=> $stats[$a][$main];
            }

            if ($stats[$a]['wins'] !== $stats[$b]['wins']) {
                return $stats[$b]['wins'] <=> $stats[$a]['wins'];
            }

            // Личная встреча — только если игроки вообще пересекались.
            $pointsA = $headToHead[$a][$b] ?? null;
            $pointsB = $headToHead[$b][$a] ?? null;
            if ($pointsA !== null && $pointsB !== null && $pointsA !== $pointsB) {
                return $pointsB <=> $pointsA;
            }

            if ($stats[$a]['rating'] !== $stats[$b]['rating']) {
                return $stats[$b]['rating'] <=> $stats[$a]['rating'];
            }

            // Последняя опора, чтобы порядок не плавал между вызовами.
            return $a <=> $b;
        });

        return $ids;
    }

    /**
     * Сумма очков каждого игрока корта за три матча — то, по чему строится
     * место на корте.
     *
     * @return array<int, int>
     */
    protected function courtRawPoints(EscaleraRoundCourt $court): array
    {
        $points = array_fill_keys($court->playerIds(), 0);

        foreach ($court->matches as $match) {
            $score1 = (int) $match->team1_score;
            $score2 = (int) $match->team2_score;

            foreach ([$match->team1_player1_id, $match->team1_player2_id] as $id) {
                $points[$id] = ($points[$id] ?? 0) + $score1;
            }
            foreach ([$match->team2_player1_id, $match->team2_player2_id] as $id) {
                $points[$id] = ($points[$id] ?? 0) + $score2;
            }
        }

        return $points;
    }
}

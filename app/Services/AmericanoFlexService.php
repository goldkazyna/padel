<?php

namespace App\Services;

use App\Models\AmericanoFlexBye;
use App\Models\AmericanoFlexMatch;
use App\Models\AmericanoFlexPairHistory;
use App\Models\AmericanoFlexPlayer;
use App\Models\AmericanoFlexRound;
use App\Models\Tournament;
use App\Support\AmericanoFlexRanking;
use App\Models\TournamentParticipant;
use App\Traits\RatingCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AmericanoFlexService
{
    use RatingCalculator;

    /** Кэш готовых таблиц расписаний (по ключу "N-C"). */
    private ?array $scheduleCache = null;

    /**
     * Запустить турнир: создать AmericanoFlexPlayer для каждого участника,
     * сгенерировать первый раунд.
     * Возвращает true при успехе, false если не хватает игроков (нужно min M*4).
     */
    public function startTournament(Tournament $tournament): bool
    {
        // Парный флекс: роутер — игроки из собранных пар (по 2 на пару).
        if ($tournament->isPairedFlex()) {
            return $this->startPairedTournament($tournament);
        }

        $participantsCount = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('status', 'registered')
            ->count();
        $required = max(4, (int) $tournament->courts_count * 4);
        if ($participantsCount < $required) {
            return false;
        }

        DB::transaction(function () use ($tournament) {
            $participants = TournamentParticipant::where('tournament_id', $tournament->id)
                ->where('status', 'registered')
                ->with('user')
                ->get();

            foreach ($participants as $p) {
                AmericanoFlexPlayer::firstOrCreate(
                    ['tournament_id' => $tournament->id, 'user_id' => $p->user_id],
                    [
                        'rating_before' => $p->user->rating,
                        'total_points' => 0,
                        'matches_played' => 0,
                        'bye_count' => 0,
                        'bye_streak' => 0,
                    ]
                );
            }

            $tournament->update(['status' => 'in_progress']);
            $this->generateNextRound($tournament);
        });

        return true;
    }

    /**
     * Старт парного флекса: роутер — игроки из собранных пар (tournament_teams,
     * approved). Нужно минимум courts*2 пар (по 2 пары на корт).
     */
    private function startPairedTournament(Tournament $tournament): bool
    {
        $teams = $this->pairedTeams($tournament);
        $requiredPairs = max(2, (int) $tournament->courts_count * 2);
        if ($teams->count() < $requiredPairs) {
            return false;
        }

        DB::transaction(function () use ($tournament, $teams) {
            foreach ($teams as $team) {
                foreach ([$team->player1, $team->player2] as $user) {
                    if (!$user) continue;
                    AmericanoFlexPlayer::firstOrCreate(
                        ['tournament_id' => $tournament->id, 'user_id' => $user->id],
                        [
                            'rating_before' => $user->rating,
                            'total_points' => 0,
                            'matches_played' => 0,
                            'bye_count' => 0,
                            'bye_streak' => 0,
                        ]
                    );
                }
            }

            $tournament->update(['status' => 'in_progress']);
            $this->generateNextRound($tournament);
        });

        return true;
    }

    /**
     * Собранные пары турнира (approved, оба игрока заданы), стабильный порядок по id.
     */
    private function pairedTeams(Tournament $tournament): Collection
    {
        return $tournament->teams()
            ->whereNotNull('player1_id')
            ->whereNotNull('player2_id')
            ->with(['player1', 'player2'])
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * Можно ли сгенерировать следующий раунд (текущий полностью завершён).
     */
    public function canGenerateNextRound(Tournament $tournament): bool
    {
        if ($tournament->status !== 'in_progress') return false;
        $current = $this->getCurrentRound($tournament);
        return $current && $this->isRoundCompleted($current);
    }

    /**
     * Можно ли завершить турнир (есть хоть один раунд и все его матчи сыграны).
     */
    public function canFinishTournament(Tournament $tournament): bool
    {
        if ($tournament->status !== 'in_progress') return false;
        $current = $this->getCurrentRound($tournament);
        if (!$current) return false;
        return $this->isRoundCompleted($current);
    }

    /**
     * Сгенерировать следующий раунд.
     */
    public function generateNextRound(Tournament $tournament): AmericanoFlexRound
    {
        return DB::transaction(function () use ($tournament) {
            $lastRound = $this->getCurrentRound($tournament);
            $nextNumber = $lastRound ? $lastRound->round_number + 1 : 1;

            if ($tournament->isPairedFlex()) {
                // Парный флекс: пары — атомы, ротируются соперники и отдых.
                $paired = $this->generatePairedRound($tournament, $nextNumber);
                $playingIds = $paired['playingIds'];
                $restingIds = $paired['restingIds'];
                $matches    = $paired['matches'];
            } else {
                // 1+2. Если для расклада (N игроков, courts) есть готовая идеальная
                // таблица и раунд в её пределах — берём её. Иначе — алгоритм-fallback.
                $table = $this->tableRound($tournament, $nextNumber);
                if ($table) {
                    $playingIds = $table['playingIds'];
                    $restingIds = $table['restingIds'];
                    $matches    = $table['matches'];
                } else {
                    $playing = $this->selectPlayersForRound($tournament);
                    $playingIds = array_map(fn($p) => $p->user_id, $playing);
                    $allPlayers = $tournament->americanoFlexPlayers()->get();
                    $restingIds = $allPlayers->whereNotIn('user_id', $playingIds)
                        ->pluck('user_id')->all();
                    $matches = $this->generatePairsForRound($tournament, $playing);
                }
            }

            // 3. Создаём раунд
            $round = AmericanoFlexRound::create([
                'tournament_id' => $tournament->id,
                'round_number' => $nextNumber,
                'status' => 'in_progress',
            ]);

            // 4. Создаём матчи
            foreach ($matches as $m) {
                AmericanoFlexMatch::create([
                    'americano_flex_round_id' => $round->id,
                    'court_number' => $m['court'],
                    'team1_player1_id' => $m['team1'][0],
                    'team1_player2_id' => $m['team1'][1],
                    'team2_player1_id' => $m['team2'][0],
                    'team2_player2_id' => $m['team2'][1],
                    'status' => 'pending',
                ]);
            }

            // 5. Записываем отдыхающих
            foreach ($restingIds as $uid) {
                AmericanoFlexBye::create([
                    'americano_flex_round_id' => $round->id,
                    'user_id' => $uid,
                ]);
            }

            // 6. Обновляем bye_streak
            AmericanoFlexPlayer::where('tournament_id', $tournament->id)
                ->whereIn('user_id', $playingIds)
                ->update(['bye_streak' => 0]);
            AmericanoFlexPlayer::where('tournament_id', $tournament->id)
                ->whereNotIn('user_id', $playingIds)
                ->increment('bye_streak');
            AmericanoFlexPlayer::where('tournament_id', $tournament->id)
                ->whereNotIn('user_id', $playingIds)
                ->increment('bye_count');

            return $round;
        });
    }

    /**
     * Выбрать M*4 игроков для нового раунда.
     * Приоритеты: bye_streak DESC → matches_played ASC → рандом.
     */
    private function selectPlayersForRound(Tournament $tournament): array
    {
        $needed = $tournament->courts_count * 4;
        $players = $tournament->americanoFlexPlayers()
            ->with('user')
            ->get()
            ->shuffle()  // рандом для tie-breaker
            ->sortBy([
                ['bye_streak', 'desc'],
                ['matches_played', 'asc'],
            ])
            ->values();

        if ($players->count() <= $needed) {
            return $players->all();  // все играют, никто не отдыхает
        }

        return $players->take($needed)->all();
    }

    /**
     * Загрузить готовые таблицы расписаний (один раз за запрос).
     */
    private function loadSchedules(): array
    {
        if ($this->scheduleCache !== null) {
            return $this->scheduleCache;
        }
        $path = database_path('data/americano_flex_schedules.json');
        if (!is_file($path)) {
            return $this->scheduleCache = [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return $this->scheduleCache = is_array($data) ? $data : [];
    }

    /**
     * Готовый раунд из таблицы для расклада (N игроков, courts), если он есть и
     * номер раунда в её пределах. Слоты таблицы (0..N-1) маппятся на реальных
     * игроков в стабильном порядке (по id записи). Возвращает массив:
     *   ['matches' => [...], 'restingIds' => [...], 'playingIds' => [...]]
     * или null — тогда вызывающий код откатывается на алгоритм.
     */
    private function tableRound(Tournament $tournament, int $roundNumber): ?array
    {
        $players = $tournament->americanoFlexPlayers()->orderBy('id')->get()->values();
        $n = $players->count();
        $c = (int) $tournament->courts_count;
        $key = "{$n}-{$c}";

        $all = $this->loadSchedules();
        if (!isset($all[$key]['schedule'])) {
            return null;
        }
        $schedule = $all[$key]['schedule'];
        if ($roundNumber < 1 || $roundNumber > count($schedule)) {
            return null; // за пределами таблицы — дальше работает алгоритм
        }

        $round = $schedule[$roundNumber - 1];
        $uid = fn(int $slot) => $players[$slot]->user_id;

        $matches = [];
        $courtNum = 1;
        $playingIds = [];
        foreach ($round['courts'] as $crt) {
            [$t1, $t2] = $crt;
            $team1 = [$uid($t1[0]), $uid($t1[1])];
            $team2 = [$uid($t2[0]), $uid($t2[1])];
            $matches[] = ['team1' => $team1, 'team2' => $team2, 'court' => $courtNum++];
            $playingIds = array_merge($playingIds, $team1, $team2);
        }
        $restingIds = array_map($uid, $round['byes'] ?? []);

        return ['matches' => $matches, 'restingIds' => $restingIds, 'playingIds' => $playingIds];
    }

    /**
     * Сформировать M матчей из массива M*4 AmericanoFlexPlayer.
     * Минимизирует повторы по системе штрафов из ТЗ:
     *   Пара (partners):    0=0, 1=100, 2+=100*N
     *   Соперник (opponents): 0=0, 1=1, 2=50, 3+=500
     * Возвращает массив матчей: [['team1' => [id1, id2], 'team2' => [id3, id4], 'court' => 1], ...]
     */
    private function generatePairsForRound(Tournament $tournament, array $players): array
    {
        $playerIds = array_map(fn($p) => $p->user_id, $players);
        $history = AmericanoFlexPairHistory::where('tournament_id', $tournament->id)
            ->whereIn('player1_id', $playerIds)
            ->whereIn('player2_id', $playerIds)
            ->get()
            ->keyBy(fn($h) => $h->player1_id . '-' . $h->player2_id);

        $row = function (int $a, int $b) use ($history) {
            [$lo, $hi] = AmericanoFlexPairHistory::normalizeIds($a, $b);
            return $history["{$lo}-{$hi}"] ?? null;
        };

        // Штраф за повторение пары (партнёры в одной команде).
        $partnerPenalty = function (int $a, int $b) use ($row) {
            $r = $row($a, $b);
            $n = $r ? (int) $r->times_as_partners : 0;
            return $n === 0 ? 0 : 100 * $n;
        };

        // Штраф за повторение соперников (игроки в разных командах).
        $opponentPenalty = function (int $a, int $b) use ($row) {
            $r = $row($a, $b);
            $n = $r ? (int) $r->times_as_opponents : 0;
            return match (true) {
                $n === 0 => 0,
                $n === 1 => 1,
                $n === 2 => 50,
                default  => 500,
            };
        };

        // Лучшая разбивка четвёрки на 2 команды (минимум штрафа) + сами команды.
        $groupBest = function (array $g) use ($partnerPenalty, $opponentPenalty) {
            [$A, $B, $C, $D] = $g;
            $variants = [
                [[$A, $B], [$C, $D]],
                [[$A, $C], [$B, $D]],
                [[$A, $D], [$B, $C]],
            ];
            $best = null;
            $bestCost = PHP_INT_MAX;
            foreach ($variants as $v) {
                $cost =
                    $partnerPenalty($v[0][0], $v[0][1]) +
                    $partnerPenalty($v[1][0], $v[1][1]) +
                    $opponentPenalty($v[0][0], $v[1][0]) +
                    $opponentPenalty($v[0][0], $v[1][1]) +
                    $opponentPenalty($v[0][1], $v[1][0]) +
                    $opponentPenalty($v[0][1], $v[1][1]);
                if ($cost < $bestCost) {
                    $bestCost = $cost;
                    $best = $v;
                }
            }
            return ['cost' => $bestCost, 'teams' => $best];
        };

        $courts = (int) floor(count($playerIds) / 4);

        // ≤3 кортов — совместная оптимизация всего раунда (избегаем ловушки
        // жадного «корт 1 портит корт 2»). Больше — жадность (перебор взрывается).
        $groups = $courts <= 3
            ? $this->bestRoundPartition($playerIds, $groupBest)['groups']
            : $this->greedyRoundPartition($playerIds, $groupBest);

        $matches = [];
        $courtNum = 1;
        foreach ($groups as $g) {
            $matches[] = [
                'team1' => $g['teams'][0],
                'team2' => $g['teams'][1],
                'court' => $courtNum++,
            ];
        }

        return $matches;
    }

    /**
     * Совместная оптимизация раунда: перебираем ВСЕ способы разбить игроков на
     * матчи (по 4), берём вариант с минимальным суммарным штрафом. Каждая
     * четвёрка независимо считает лучшую разбивку на команды (groupBest).
     * Рекурсия с фиксацией первого игрока — без дублей-перестановок.
     */
    private function bestRoundPartition(array $ids, callable $groupBest): array
    {
        if (count($ids) < 4) {
            return ['cost' => 0, 'groups' => []];
        }

        $first = $ids[0];
        $rest = array_values(array_slice($ids, 1));
        $n = count($rest);

        $bestCost = PHP_INT_MAX;
        $bestGroups = [];

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                for ($k = $j + 1; $k < $n; $k++) {
                    $gb = $groupBest([$first, $rest[$i], $rest[$j], $rest[$k]]);

                    $remaining = [];
                    for ($t = 0; $t < $n; $t++) {
                        if ($t !== $i && $t !== $j && $t !== $k) {
                            $remaining[] = $rest[$t];
                        }
                    }

                    $sub = $this->bestRoundPartition($remaining, $groupBest);
                    $total = $gb['cost'] + $sub['cost'];

                    if ($total < $bestCost) {
                        $bestCost = $total;
                        $bestGroups = array_merge([$gb], $sub['groups']);
                    }
                }
            }
        }

        return ['cost' => $bestCost, 'groups' => $bestGroups];
    }

    /**
     * Жадный фолбэк для 4+ кортов: на каждый корт берём лучшую (мин. штраф)
     * четвёрку, убираем её, повторяем.
     */
    private function greedyRoundPartition(array $ids, callable $groupBest): array
    {
        $remaining = $ids;
        $groups = [];

        while (count($remaining) >= 4) {
            $bestCost = PHP_INT_MAX;
            $bestGroup = null;
            $bestIdx = null;
            $n = count($remaining);

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    for ($k = $j + 1; $k < $n; $k++) {
                        for ($l = $k + 1; $l < $n; $l++) {
                            $gb = $groupBest([$remaining[$i], $remaining[$j], $remaining[$k], $remaining[$l]]);
                            if ($gb['cost'] < $bestCost) {
                                $bestCost = $gb['cost'];
                                $bestGroup = $gb;
                                $bestIdx = [$i, $j, $k, $l];
                            }
                        }
                    }
                }
            }

            if (!$bestGroup) break;
            $groups[] = $bestGroup;
            rsort($bestIdx);
            foreach ($bestIdx as $idx) {
                array_splice($remaining, $idx, 1);
            }
        }

        return $groups;
    }

    // ===================== ПАРНЫЙ ФЛЕКС =====================

    /** Кэш парных таблиц расписаний (по ключу "P-C"). */
    private ?array $pairedScheduleCache = null;

    /**
     * Сгенерировать раунд для парного флекса. Пары — атомарные команды:
     * 2C пар играют (C матчей), P−2C пар отдыхают.
     * Возвращает ['matches'=>[...], 'playingIds'=>[...], 'restingIds'=>[...]].
     */
    private function generatePairedRound(Tournament $tournament, int $nextNumber): array
    {
        $teams = $this->pairedTeams($tournament);

        // 1. Готовая таблица для расклада (P пар, C кортов), если есть.
        $table = $this->pairedTableRound($tournament, $teams, $nextNumber);
        if ($table) {
            return $table;
        }

        // 2. Алгоритм-фолбэк.
        return $this->pairedAlgoRound($tournament, $teams);
    }

    /**
     * Готовый раунд из парной таблицы. Слоты (0..P-1) → пары в порядке id.
     */
    private function pairedTableRound(Tournament $tournament, Collection $teams, int $roundNumber): ?array
    {
        $p = $teams->count();
        $c = (int) $tournament->courts_count;
        $key = "{$p}-{$c}";

        $all = $this->loadPairedSchedules();
        if (!isset($all[$key]['schedule'])) {
            return null;
        }
        $schedule = $all[$key]['schedule'];
        if ($roundNumber < 1 || $roundNumber > count($schedule)) {
            return null;
        }

        $round = $schedule[$roundNumber - 1];
        $pairUsers = fn(int $slot) => [$teams[$slot]->player1_id, $teams[$slot]->player2_id];

        $matches = [];
        $courtNum = 1;
        $playingIds = [];
        foreach ($round['courts'] as $crt) {
            [$slotA, $slotB] = $crt;
            $team1 = $pairUsers($slotA);
            $team2 = $pairUsers($slotB);
            $matches[] = ['team1' => $team1, 'team2' => $team2, 'court' => $courtNum++];
            $playingIds = array_merge($playingIds, $team1, $team2);
        }
        $restingIds = [];
        foreach (($round['byes'] ?? []) as $slot) {
            $restingIds = array_merge($restingIds, $pairUsers($slot));
        }

        return ['matches' => $matches, 'restingIds' => $restingIds, 'playingIds' => $playingIds];
    }

    /**
     * Алгоритм-фолбэк парного раунда: выбираем играющие пары (ровный отдых),
     * стыкуем их в матчи минимизируя повторы соперников-пар.
     */
    private function pairedAlgoRound(Tournament $tournament, Collection $teams): array
    {
        $p = $teams->count();
        $c = (int) $tournament->courts_count;

        // Сколько пар играет: 2 на корт, не больше P, чётное число.
        $playingCount = min($p, 2 * $c);
        if ($playingCount % 2 !== 0) $playingCount--;

        // Статистика пары = статистика любого её игрока (играют/отдыхают вместе).
        $players = $tournament->americanoFlexPlayers()->get()->keyBy('user_id');
        $stat = function ($team) use ($players) {
            $fp = $players[$team->player1_id] ?? null;
            return [
                'bye_streak' => $fp ? (int) $fp->bye_streak : 0,
                'matches_played' => $fp ? (int) $fp->matches_played : 0,
            ];
        };

        // Выбираем играющие пары: дольше отдыхавшие играют первыми, затем
        // меньше сыгравшие; рандомный tie-break.
        $ordered = $teams->shuffle()->sortBy([
            fn($t) => -$stat($t)['bye_streak'],
            fn($t) => $stat($t)['matches_played'],
        ])->values();

        $playing = $ordered->take($playingCount)->values();
        $resting = $ordered->slice($playingCount)->values();

        // Матрица «пара против пары» из уже созданных матчей.
        $opp = $this->pairedOpponentMatrix($tournament, $teams);

        // Минимизируем повторы соперников: оптимальное паросочетание играющих пар.
        $playSlots = $playing->map(fn($t) => $t->id)->all();
        $cost = fn($idA, $idB) => $opp[$idA][$idB] ?? 0;
        $pairsList = $this->minCostPairing($playSlots, $cost);

        $byId = $teams->keyBy('id');
        $matches = [];
        $courtNum = 1;
        $playingIds = [];
        foreach ($pairsList as [$idA, $idB]) {
            $tA = $byId[$idA];
            $tB = $byId[$idB];
            $team1 = [$tA->player1_id, $tA->player2_id];
            $team2 = [$tB->player1_id, $tB->player2_id];
            $matches[] = ['team1' => $team1, 'team2' => $team2, 'court' => $courtNum++];
            $playingIds = array_merge($playingIds, $team1, $team2);
        }

        $restingIds = [];
        foreach ($resting as $t) {
            $restingIds[] = $t->player1_id;
            $restingIds[] = $t->player2_id;
        }

        return ['matches' => $matches, 'restingIds' => $restingIds, 'playingIds' => $playingIds];
    }

    /**
     * Матрица повторов соперников между парами (по team id) из созданных матчей.
     */
    private function pairedOpponentMatrix(Tournament $tournament, Collection $teams): array
    {
        // Карта: отсортированный ключ "u1-u2" → team id.
        $keyToTeam = [];
        foreach ($teams as $t) {
            $ids = [$t->player1_id, $t->player2_id];
            sort($ids);
            $keyToTeam[$ids[0] . '-' . $ids[1]] = $t->id;
        }
        $teamOf = function (int $u1, int $u2) use ($keyToTeam) {
            $ids = [$u1, $u2];
            sort($ids);
            return $keyToTeam[$ids[0] . '-' . $ids[1]] ?? null;
        };

        $roundIds = $tournament->americanoFlexRounds()->pluck('id');
        $matches = AmericanoFlexMatch::whereIn('americano_flex_round_id', $roundIds)->get();

        $opp = [];
        foreach ($matches as $m) {
            $a = $teamOf($m->team1_player1_id, $m->team1_player2_id);
            $b = $teamOf($m->team2_player1_id, $m->team2_player2_id);
            if ($a === null || $b === null) continue;
            $opp[$a][$b] = ($opp[$a][$b] ?? 0) + 1;
            $opp[$b][$a] = ($opp[$b][$a] ?? 0) + 1;
        }
        return $opp;
    }

    /**
     * Оптимальное паросочетание списка элементов (минимум суммы cost). Перебор с
     * фиксацией первого — без дублей. Для ≤ ~10 элементов быстро.
     * Возвращает массив пар [[a,b], ...].
     */
    private function minCostPairing(array $items, callable $cost): array
    {
        if (count($items) < 2) return [];
        $first = $items[0];
        $rest = array_slice($items, 1);
        $best = null;
        $bestCost = PHP_INT_MAX;
        for ($i = 0; $i < count($rest); $i++) {
            $partner = $rest[$i];
            $remaining = array_values(array_merge(
                array_slice($rest, 0, $i),
                array_slice($rest, $i + 1)
            ));
            $sub = $this->minCostPairing($remaining, $cost);
            $total = $cost($first, $partner) + $this->pairingCost($sub, $cost);
            if ($total < $bestCost) {
                $bestCost = $total;
                $best = array_merge([[$first, $partner]], $sub);
            }
        }
        return $best ?? [];
    }

    private function pairingCost(array $pairs, callable $cost): int
    {
        $sum = 0;
        foreach ($pairs as [$a, $b]) {
            $sum += $cost($a, $b);
        }
        return $sum;
    }

    /**
     * Загрузить парные таблицы расписаний (один раз за запрос).
     */
    private function loadPairedSchedules(): array
    {
        if ($this->pairedScheduleCache !== null) {
            return $this->pairedScheduleCache;
        }
        $path = database_path('data/americano_flex_paired_schedules.json');
        if (!is_file($path)) {
            return $this->pairedScheduleCache = [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return $this->pairedScheduleCache = is_array($data) ? $data : [];
    }

    /**
     * Лидерборд по парам: коллекция массивов с агрегатами пары, сортировка по
     * среднему за матч DESC. Каждый элемент — одна пара (двое игроков).
     */
    public function getPairedLeaderboard(Tournament $tournament): array
    {
        $teams = $this->pairedTeams($tournament);
        $players = $tournament->americanoFlexPlayers()->get()->keyBy('user_id');
        // Агрегаты — из фактически сыгранных матчей (0:0 не считаем), а не из
        // хранимых полей: иначе несыгранные матчи искажают таблицу.
        // Партнёры всегда играют вместе, поэтому статистика пары = статистика
        // её первого игрока, и личные встречи тоже ищутся по нему.
        $stats = AmericanoFlexRanking::stats($tournament);

        $rows = [];
        foreach ($teams as $t) {
            $fp = $players[$t->player1_id] ?? null;
            $row = AmericanoFlexRanking::row((int) $t->player1_id, $stats, (int) ($t->rating_avg ?? 0));
            $rows[] = $row + [
                'team_id' => $t->id,
                'player1' => $t->player1,
                'player2' => $t->player2,
                'total_points' => $row['points_for'],
                'matches_played' => $row['matches'],
                'bye_count' => $fp ? (int) $fp->bye_count : 0,
                'bye_streak' => $fp ? (int) $fp->bye_streak : 0,
                'avg_points' => $row['avg'],
            ];
        }

        return AmericanoFlexRanking::sortRows($rows, AmericanoFlexRanking::headToHead($tournament));
    }

    /**
     * Сохранить счёт матча, обновить points/matches_played игроков и pair_history.
     */
    public function saveMatchResult(AmericanoFlexMatch $match, int $score1, int $score2): void
    {
        DB::transaction(function () use ($match, $score1, $score2) {
            $tournamentId = $match->round->tournament_id;
            $team1Ids = [$match->team1_player1_id, $match->team1_player2_id];
            $team2Ids = [$match->team2_player1_id, $match->team2_player2_id];

            // Идемпотентность: если матч уже был сыгран (повторный сабмит, двойной
            // клик, обрыв связи и повторная отправка) — откатываем старые очки,
            // иначе total_points задвоится. matches_played и pair_history НЕ трогаем:
            // состав игроков тот же, матч уже был учтён ранее.
            $wasCompleted = $match->status === 'completed';
            if ($wasCompleted) {
                AmericanoFlexPlayer::where('tournament_id', $tournamentId)
                    ->whereIn('user_id', $team1Ids)
                    ->decrement('total_points', (int) $match->team1_score);
                AmericanoFlexPlayer::where('tournament_id', $tournamentId)
                    ->whereIn('user_id', $team2Ids)
                    ->decrement('total_points', (int) $match->team2_score);
            }

            $match->update([
                'team1_score' => $score1,
                'team2_score' => $score2,
                'status' => 'completed',
            ]);

            // Очки игроков: команда получает свой счёт
            AmericanoFlexPlayer::where('tournament_id', $tournamentId)
                ->whereIn('user_id', $team1Ids)
                ->increment('total_points', $score1);
            AmericanoFlexPlayer::where('tournament_id', $tournamentId)
                ->whereIn('user_id', $team2Ids)
                ->increment('total_points', $score2);

            // matches_played и pair_history — только при ПЕРВОМ сохранении матча
            if (!$wasCompleted) {
                AmericanoFlexPlayer::where('tournament_id', $tournamentId)
                    ->whereIn('user_id', $team1Ids)
                    ->increment('matches_played');
                AmericanoFlexPlayer::where('tournament_id', $tournamentId)
                    ->whereIn('user_id', $team2Ids)
                    ->increment('matches_played');

                // pair_history: +1 partners для команд, +1 opponents для крестов
                $this->incrementPairHistory($tournamentId, $team1Ids[0], $team1Ids[1], 'partners');
                $this->incrementPairHistory($tournamentId, $team2Ids[0], $team2Ids[1], 'partners');
                foreach ($team1Ids as $a) {
                    foreach ($team2Ids as $b) {
                        $this->incrementPairHistory($tournamentId, $a, $b, 'opponents');
                    }
                }
            }

            // Если все матчи раунда завершены — пометить раунд completed
            if ($this->isRoundCompleted($match->round)) {
                $match->round->update(['status' => 'completed']);
            }
        });
    }

    private function incrementPairHistory(int $tournamentId, int $a, int $b, string $kind): void
    {
        [$lo, $hi] = AmericanoFlexPairHistory::normalizeIds($a, $b);
        $col = $kind === 'partners' ? 'times_as_partners' : 'times_as_opponents';
        $row = AmericanoFlexPairHistory::firstOrCreate(
            ['tournament_id' => $tournamentId, 'player1_id' => $lo, 'player2_id' => $hi],
            ['times_as_partners' => 0, 'times_as_opponents' => 0]
        );
        $row->increment($col);
    }

    /**
     * Текущий открытый раунд (последний по round_number).
     */
    public function getCurrentRound(Tournament $tournament): ?AmericanoFlexRound
    {
        return $tournament->americanoFlexRounds()
            ->orderByDesc('round_number')
            ->first();
    }

    /**
     * Все матчи раунда завершены?
     */
    public function isRoundCompleted(AmericanoFlexRound $round): bool
    {
        return $round->matches()->where('status', '!=', 'completed')->count() === 0;
    }

    /**
     * Завершить турнир: посчитать ELO для всех игроков, выставить статус.
     * Возвращает true при успехе.
     */
    public function completeTournament(Tournament $tournament): bool
    {
        // Не рейтинговый турнир — завершаем без начисления рейтинга.
        if (!$tournament->is_rated) {
            $tournament->update(['status' => 'completed']);
            return true;
        }

        DB::transaction(function () use ($tournament) {
            // Идём по всем матчам в явном порядке: раунды по round_number, внутри раунда — по id.
            $roundIds = $tournament->americanoFlexRounds()
                ->orderBy('round_number')
                ->pluck('id');

            $matches = AmericanoFlexMatch::whereIn('americano_flex_round_id', $roundIds)
                ->where('status', 'completed')
                ->orderBy('americano_flex_round_id')
                ->orderBy('id')
                ->get();

            // Стартовые рейтинги — из AmericanoFlexPlayer.rating_before
            $players = $tournament->americanoFlexPlayers()->with('user')->get()->keyBy('user_id');
            $currentRatings = [];
            foreach ($players as $p) {
                $currentRatings[$p->user_id] = $p->rating_before ?? 1500;
            }

            foreach ($matches as $match) {
                // Несыгранный матч 0:0 в рейтинге не участвует.
                if ((int) $match->team1_score === 0 && (int) $match->team2_score === 0) {
                    continue;
                }
                $r11 = $currentRatings[$match->team1_player1_id];
                $r12 = $currentRatings[$match->team1_player2_id];
                $r21 = $currentRatings[$match->team2_player1_id];
                $r22 = $currentRatings[$match->team2_player2_id];

                $t1 = ($r11 + $r12) / 2;
                $t2 = ($r21 + $r22) / 2;

                $result = $this->calculateRatingChange($t1, $t2, $match->team1_score, $match->team2_score);

                $currentRatings[$match->team1_player1_id] = $this->applyRatingChange($r11, $result['change1']);
                $currentRatings[$match->team1_player2_id] = $this->applyRatingChange($r12, $result['change1']);
                $currentRatings[$match->team2_player1_id] = $this->applyRatingChange($r21, $result['change2']);
                $currentRatings[$match->team2_player2_id] = $this->applyRatingChange($r22, $result['change2']);
            }

            // Сохраняем rating_after в players + обновляем users.rating
            // Аналогично MexicanoService::finishTournament: применяем дельту к актуальному
            // рейтингу пользователя и пишем запись в rating_history для аналитики.
            foreach ($players as $userId => $player) {
                $calcFinal = (int) ($currentRatings[$userId] ?? $player->rating_before ?? 1500);
                $delta = $calcFinal - (int) $player->rating_before;
                $actualBefore = (int) $player->user->rating;
                $actualAfter = max($this->minRating, $actualBefore + $delta);

                $player->update(['rating_after' => $actualAfter]);
                $player->user->update(['rating' => $actualAfter]);
                $this->updateLevel($player->user->fresh());

                \App\Models\RatingHistory::create([
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

    /**
     * Пересчитать дельты рейтинга флекса БЕЗ применения (для проверки/ремонта).
     * Матчи 0:0 не учитываются. Возвращает [user_id => new_delta].
     */
    public function recomputeRatingDeltas(Tournament $tournament): array
    {
        $roundIds = $tournament->americanoFlexRounds()
            ->orderBy('round_number')
            ->pluck('id');

        $matches = AmericanoFlexMatch::whereIn('americano_flex_round_id', $roundIds)
            ->where('status', 'completed')
            ->orderBy('americano_flex_round_id')
            ->orderBy('id')
            ->get();

        $players = $tournament->americanoFlexPlayers()->with('user')->get()->keyBy('user_id');
        $current = [];
        foreach ($players as $p) {
            $current[$p->user_id] = $p->rating_before ?? 1500;
        }

        foreach ($matches as $m) {
            if ((int) $m->team1_score === 0 && (int) $m->team2_score === 0) continue;
            $r11 = $current[$m->team1_player1_id];
            $r12 = $current[$m->team1_player2_id];
            $r21 = $current[$m->team2_player1_id];
            $r22 = $current[$m->team2_player2_id];
            $t1 = ($r11 + $r12) / 2;
            $t2 = ($r21 + $r22) / 2;
            $res = $this->calculateRatingChange($t1, $t2, $m->team1_score, $m->team2_score);
            $current[$m->team1_player1_id] = $this->applyRatingChange($r11, $res['change1']);
            $current[$m->team1_player2_id] = $this->applyRatingChange($r12, $res['change1']);
            $current[$m->team2_player1_id] = $this->applyRatingChange($r21, $res['change2']);
            $current[$m->team2_player2_id] = $this->applyRatingChange($r22, $res['change2']);
        }

        $deltas = [];
        foreach ($players as $uid => $p) {
            $before = (int) ($p->rating_before ?? 1500);
            $deltas[$uid] = (int) $current[$uid] - $before;
        }
        return $deltas;
    }

    /**
     * Лидерборд: коллекция AmericanoFlexPlayer в порядке итоговой таблицы
     * (среднее → % побед → личная встреча → рейтинг, см. AmericanoFlexRanking).
     *
     * Хранимые в модели total_points / matches_played для порядка не годятся:
     * счётчик матчей растёт и на 0:0, которым отмечают несыгранный матч.
     * Актуальные агрегаты каждой строки лежат в getLeaderboardStats().
     */
    public function getLeaderboard(Tournament $tournament): Collection
    {
        $players = $tournament->americanoFlexPlayers()->with('user')->get()->keyBy('user_id');

        $ordered = collect();
        foreach (AmericanoFlexRanking::soloRows($tournament) as $row) {
            $player = $players[$row['id']] ?? null;
            if ($player) $ordered->push($player);
        }

        return $ordered->values();
    }

    /**
     * Агрегаты для таблицы: user_id => строка AmericanoFlexRanking.
     * Веб показывает числа отсюда, а не из хранимых полей игрока.
     */
    public function getLeaderboardStats(Tournament $tournament): array
    {
        $byUser = [];
        foreach (AmericanoFlexRanking::soloRows($tournament) as $row) {
            $byUser[$row['id']] = $row;
        }

        return $byUser;
    }
}

<?php

namespace App\Services;

use App\Models\RoundRobinMatch;
use App\Models\RoundRobinPlayer;
use App\Models\RoundRobinRound;
use App\Models\Tournament;
use App\Models\User;

/**
 * Турнир «Round Robin» (индивидуальный).
 *
 * Как Американо (2×2, партнёры меняются каждый раунд), НО:
 *  - ранжирование по числу выигранных МАТЧЕЙ; tie-break: разница геймов → личные встречи;
 *  - раунды генерятся вручную (кнопка «Следующий раунд» / «Завершить»), как Король корта;
 *  - расписание полного круга считается на старте и сохраняется; после круга — цикл
 *    (для 8 игроков круг = 7 раундов, 8-й раунд = расписание 1-го).
 */
class RoundRobinService
{
    use \App\Traits\RatingCalculator;

    /** Старт: создать игроков, построить и сохранить расписание, сгенерить раунд 1. */
    public function startTournament(Tournament $tournament): bool
    {
        if (!$tournament->isRoundRobin()) return false;
        if ($tournament->status !== 'open') return false;

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->get();

        $count = $participants->count();
        if ($count < 4 || $count % 4 !== 0) {
            return false; // минимум 4 игрока, кратно 4
        }

        $shuffled = $participants->shuffle()->values();
        $ids = $shuffled->pluck('id')->all();

        // Создаём записи игроков с позицией в расписании.
        foreach ($shuffled as $pos => $u) {
            RoundRobinPlayer::firstOrCreate(
                ['tournament_id' => $tournament->id, 'user_id' => $u->id],
                ['position' => $pos, 'rating_before' => $u->rating]
            );
        }

        // Строим расписание круга (индексы) и переводим в id игроков.
        $indexSchedule = $this->buildIndexSchedule($count);
        $schedule = [];
        foreach ($indexSchedule as $round) {
            $roundMatches = [];
            foreach ($round as $match) {
                [$a, $b] = $match;
                $roundMatches[] = [[$ids[$a[0]], $ids[$a[1]]], [$ids[$b[0]], $ids[$b[1]]]];
            }
            $schedule[] = $roundMatches;
        }

        $tournament->update([
            'round_robin_schedule' => $schedule,
            'status' => 'in_progress',
        ]);

        $this->createRound($tournament, 1, $schedule[0]);

        return true;
    }

    /** Сохранить счёт матча (идемпотентно: откат старой статы перед записью). */
    public function saveMatchResult(RoundRobinMatch $match, int $team1Score, int $team2Score): void
    {
        if ($match->isCompleted()) {
            $this->rollbackMatchStats($match);
        }

        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'status' => 'completed',
        ]);
        $match->refresh();

        $this->applyMatchStats($match);

        $round = $match->round;
        if ($round->matches()->where('status', '!=', 'completed')->count() === 0
            && $round->status !== 'completed') {
            $round->update(['status' => 'completed']);
        }
    }

    public function canGenerateNextRound(Tournament $tournament): bool
    {
        if (!$tournament->isRoundRobin()) return false;
        if ($tournament->status !== 'in_progress') return false;
        $last = $tournament->roundRobinRounds()->reorder('round_number', 'desc')->first();
        return $last && $last->status === 'completed';
    }

    /** Следующий раунд по сохранённому расписанию (цикл после полного круга). */
    public function generateNextRound(Tournament $tournament): bool
    {
        if (!$this->canGenerateNextRound($tournament)) return false;

        $schedule = $tournament->round_robin_schedule ?? [];
        if (empty($schedule)) return false;

        $last = $tournament->roundRobinRounds()->reorder('round_number', 'desc')->first();
        $nextNumber = $last->round_number + 1;
        $scheduleIdx = ($nextNumber - 1) % count($schedule); // цикл

        $this->createRound($tournament, $nextNumber, $schedule[$scheduleIdx]);
        return true;
    }

    public function canFinishTournament(Tournament $tournament): bool
    {
        if (!$tournament->isRoundRobin()) return false;
        if ($tournament->status !== 'in_progress') return false;
        $last = $tournament->roundRobinRounds()->reorder('round_number', 'desc')->first();
        return $last && $last->status === 'completed';
    }

    /** Завершить: ELO по сыгранным матчам (если рейтинговый), запись истории. */
    public function finishTournament(Tournament $tournament): bool
    {
        if (!$this->canFinishTournament($tournament)) return false;

        if (!$tournament->is_rated) {
            $tournament->update(['status' => 'completed']);
            return true;
        }

        $players = $tournament->roundRobinPlayers()->with('user')->get();
        $ratingChanges = [];
        foreach ($players as $p) {
            $ratingChanges[$p->user_id] = [
                'rating_before' => (int) $p->rating_before,
                'current_rating' => (int) $p->rating_before,
            ];
        }

        foreach ($tournament->roundRobinRounds()->orderBy('round_number')->get() as $round) {
            foreach ($round->matches as $match) {
                if ($match->status !== 'completed') continue;
                $this->calculateEloForMatch($match, $ratingChanges);
            }
        }

        foreach ($players as $p) {
            $delta = (int) $ratingChanges[$p->user_id]['current_rating'] - (int) $p->rating_before;
            $actualBefore = (int) ($p->user->rating ?? $p->rating_before);
            $actualAfter = max($this->minRating, $actualBefore + $delta);

            $p->update(['rating_after' => $actualAfter]);
            $p->user->update(['rating' => $actualAfter]);
            $this->updateLevel($p->user->fresh());

            \App\Models\RatingHistory::create([
                'user_id' => $p->user_id,
                'tournament_id' => $tournament->id,
                'rating_before' => $actualBefore,
                'rating_after' => $actualAfter,
                'change' => $delta,
                'reason' => $tournament->name,
            ]);
        }

        $tournament->update(['status' => 'completed']);
        return true;
    }

    public function calculateEloForMatch(RoundRobinMatch $match, array &$ratingChanges): void
    {
        $p = [$match->team1_player1_id, $match->team1_player2_id, $match->team2_player1_id, $match->team2_player2_id];
        foreach ($p as $id) {
            if (!isset($ratingChanges[$id])) return;
        }
        $team1Rating = ($ratingChanges[$p[0]]['current_rating'] + $ratingChanges[$p[1]]['current_rating']) / 2;
        $team2Rating = ($ratingChanges[$p[2]]['current_rating'] + $ratingChanges[$p[3]]['current_rating']) / 2;

        $res = $this->calculateRatingChange($team1Rating, $team2Rating, $match->team1_score, $match->team2_score);

        $ratingChanges[$p[0]]['current_rating'] = $this->applyRatingChange($ratingChanges[$p[0]]['current_rating'], $res['change1']);
        $ratingChanges[$p[1]]['current_rating'] = $this->applyRatingChange($ratingChanges[$p[1]]['current_rating'], $res['change1']);
        $ratingChanges[$p[2]]['current_rating'] = $this->applyRatingChange($ratingChanges[$p[2]]['current_rating'], $res['change2']);
        $ratingChanges[$p[3]]['current_rating'] = $this->applyRatingChange($ratingChanges[$p[3]]['current_rating'], $res['change2']);
    }

    /**
     * Итоговая таблица: победы DESC → разница геймов DESC → личные встречи → рейтинг.
     * Возвращает массив строк ['user', 'wins', 'losses', 'points_for', 'points_against', 'diff'].
     */
    public function standings(Tournament $tournament): array
    {
        $players = $tournament->roundRobinPlayers()->with('user')->get();

        // Матрица личных встреч: h2h[a][b] = (победы a над b) − (победы b над a).
        $h2h = [];
        foreach ($tournament->roundRobinRounds as $round) {
            foreach ($round->matches as $m) {
                if (!$m->isCompleted() || $m->team1_score === $m->team2_score) continue;
                $winners = $m->team1_score > $m->team2_score
                    ? [$m->team1_player1_id, $m->team1_player2_id]
                    : [$m->team2_player1_id, $m->team2_player2_id];
                $losers = $m->team1_score > $m->team2_score
                    ? [$m->team2_player1_id, $m->team2_player2_id]
                    : [$m->team1_player1_id, $m->team1_player2_id];
                foreach ($winners as $w) {
                    foreach ($losers as $l) {
                        $h2h[$w][$l] = ($h2h[$w][$l] ?? 0) + 1;
                        $h2h[$l][$w] = ($h2h[$l][$w] ?? 0) - 1;
                    }
                }
            }
        }

        $rows = $players->map(fn($p) => [
            'user' => $p->user,
            'user_id' => $p->user_id,
            'wins' => (int) $p->wins,
            'losses' => (int) $p->losses,
            'points_for' => (int) $p->points_for,
            'points_against' => (int) $p->points_against,
            'diff' => (int) $p->points_for - (int) $p->points_against,
            'rating' => (int) ($p->user->rating ?? 0),
        ])->values()->all();

        usort($rows, function ($a, $b) use ($h2h) {
            if ($a['wins'] !== $b['wins']) return $b['wins'] <=> $a['wins'];
            if ($a['diff'] !== $b['diff']) return $b['diff'] <=> $a['diff'];
            $direct = $h2h[$a['user_id']][$b['user_id']] ?? 0;
            if ($direct !== 0) return $direct > 0 ? -1 : 1;
            return $b['rating'] <=> $a['rating'];
        });

        return $rows;
    }

    // ===== Internal =====

    /** Создать раунд + матчи из расписания одного раунда [[ [id,id],[id,id] ], ...]. */
    protected function createRound(Tournament $tournament, int $roundNumber, array $matches): RoundRobinRound
    {
        $round = RoundRobinRound::create([
            'tournament_id' => $tournament->id,
            'round_number' => $roundNumber,
            'status' => 'in_progress',
        ]);

        $shuffled = $matches;
        shuffle($shuffled); // случайные корты
        $court = 1;
        foreach ($shuffled as $m) {
            [$teamA, $teamB] = $m;
            RoundRobinMatch::create([
                'round_robin_round_id' => $round->id,
                'court_number' => $court++,
                'team1_player1_id' => $teamA[0],
                'team1_player2_id' => $teamA[1],
                'team2_player1_id' => $teamB[0],
                'team2_player2_id' => $teamB[1],
                'status' => 'pending',
            ]);
        }

        return $round;
    }

    protected function applyMatchStats(RoundRobinMatch $match): void
    {
        $this->adjustStats($match, +1);
    }

    protected function rollbackMatchStats(RoundRobinMatch $match): void
    {
        $this->adjustStats($match, -1);
    }

    /** Начислить/откатить статы матча. $sign = +1 (apply) или -1 (rollback). */
    private function adjustStats(RoundRobinMatch $match, int $sign): void
    {
        $tid = $match->round->tournament_id;
        $team1 = [$match->team1_player1_id, $match->team1_player2_id];
        $team2 = [$match->team2_player1_id, $match->team2_player2_id];
        $s1 = (int) $match->team1_score;
        $s2 = (int) $match->team2_score;

        $team1Won = $s1 > $s2;
        $team2Won = $s2 > $s1;

        foreach ($team1 as $id) {
            $this->bump($tid, $id, $sign, $s1, $s2, $team1Won);
        }
        foreach ($team2 as $id) {
            $this->bump($tid, $id, $sign, $s2, $s1, $team2Won);
        }
    }

    private function bump(int $tid, int $userId, int $sign, int $for, int $against, bool $won): void
    {
        $p = RoundRobinPlayer::where('tournament_id', $tid)->where('user_id', $userId)->first();
        if (!$p) return;
        $p->increment('points_for', $sign * $for);
        $p->increment('points_against', $sign * $against);
        if ($won) {
            $p->increment('wins', $sign);
        } else {
            $p->increment('losses', $sign);
        }
    }

    /**
     * Расписание круга в индексах игроков: массив раундов, каждый раунд —
     * массив матчей [[a1,a2],[b1,b2]]. Для 8/12 — проверенные оптимальные схемы.
     */
    private function buildIndexSchedule(int $n): array
    {
        $optimal = $this->getOptimalSchedule($n);
        if ($optimal) {
            ksort($optimal);
            return array_values($optimal);
        }

        // Универсальный круговой метод: фиксируем игрока 0, остальные вращаются.
        $players = range(0, $n - 1);
        $fixed = $players[0];
        $rotating = array_slice($players, 1);
        $rounds = [];

        for ($r = 0; $r < $n - 1; $r++) {
            $pairs = [[$fixed, $rotating[0]]];
            for ($i = 1; $i <= (($n - 2) / 2); $i++) {
                $pairs[] = [$rotating[$i], $rotating[$n - 1 - $i]];
            }
            // Группируем пары по две в матч (teamA vs teamB).
            $matches = [];
            for ($j = 0; $j + 1 < count($pairs); $j += 2) {
                $matches[] = [$pairs[$j], $pairs[$j + 1]];
            }
            $rounds[] = $matches;

            $last = array_pop($rotating);
            array_unshift($rotating, $last);
        }

        return $rounds;
    }

    /** Проверенные идеальные расписания для 8 и 12 игроков (1-keyed → массивы пар индексов). */
    private function getOptimalSchedule(int $numPlayers): ?array
    {
        $schedules = [
            8 => [
                1 => [[[0,1], [2,3]], [[4,5], [6,7]]],
                2 => [[[0,2], [4,6]], [[1,3], [5,7]]],
                3 => [[[0,3], [5,6]], [[1,2], [4,7]]],
                4 => [[[0,4], [1,5]], [[2,6], [3,7]]],
                5 => [[[0,5], [2,7]], [[1,4], [3,6]]],
                6 => [[[0,6], [1,7]], [[2,4], [3,5]]],
                7 => [[[0,7], [3,4]], [[1,6], [2,5]]],
            ],
            12 => [
                1  => [[[11,0], [8,9]],   [[1,7], [2,5]],   [[3,10], [4,6]]],
                2  => [[[11,1], [9,10]],  [[2,8], [3,6]],   [[4,0], [5,7]]],
                3  => [[[11,2], [10,0]],  [[3,9], [4,7]],   [[5,1], [6,8]]],
                4  => [[[11,3], [0,1]],   [[4,10], [5,8]],  [[6,2], [7,9]]],
                5  => [[[11,4], [1,2]],   [[5,0], [6,9]],   [[7,3], [8,10]]],
                6  => [[[11,5], [2,3]],   [[6,1], [7,10]],  [[8,4], [9,0]]],
                7  => [[[11,6], [3,4]],   [[7,2], [8,0]],   [[9,5], [10,1]]],
                8  => [[[11,7], [4,5]],   [[8,3], [9,1]],   [[10,6], [0,2]]],
                9  => [[[11,8], [5,6]],   [[9,4], [10,2]],  [[0,7], [1,3]]],
                10 => [[[11,9], [6,7]],   [[10,5], [0,3]],  [[1,8], [2,4]]],
                11 => [[[11,10], [7,8]],  [[0,6], [1,4]],   [[2,9], [3,5]]],
            ],
        ];

        return $schedules[$numPlayers] ?? null;
    }
}

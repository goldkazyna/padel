<?php

namespace App\Services;

use App\Models\KingOfCourtMatch;
use App\Models\KingOfCourtPlayer;
use App\Models\KingOfCourtRound;
use App\Models\Tournament;

/**
 * Турнир «Король корта».
 *
 * Гибкий состав: игроков всегда 4×N, кортов — N (минимум 8 игроков / 2 корта).
 * Каждый раунд — на каждом корте сетап 2v2, после каждого раунда:
 *   - Корт 1: победители ОСТАЮТСЯ, проигравшие → корт 2
 *   - Корты 2..N-1: победители → выше, проигравшие → ниже
 *   - Корт N: победители → выше, проигравшие ОСТАЮТСЯ
 * После ротации на каждом корте 4 игрока — пары перемешиваются (важно!).
 */
class KingOfCourtService
{
    /**
     * Запустить турнир: создаём KOC-игроков, генерим первый раунд (рандомно).
     */
    public function startTournament(Tournament $tournament): bool
    {
        if (!$tournament->isKingOfCourt()) return false;
        if ($tournament->status !== 'open') return false;

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->get();

        $count = $participants->count();
        if ($count < 8 || $count % 4 !== 0) {
            return false; // Минимум 8 игроков, кратно 4
        }

        // Записи KOC-игроков (стат = 0 в начале)
        foreach ($participants as $u) {
            KingOfCourtPlayer::firstOrCreate(
                ['tournament_id' => $tournament->id, 'user_id' => $u->id],
                ['rating_before' => $u->rating]
            );
        }

        // Первый раунд — рандомное распределение
        $shuffled = $participants->shuffle()->values();
        $courts = [];
        $courtsCount = (int) ($count / 4);
        for ($i = 0; $i < $courtsCount; $i++) {
            $courts[] = [
                $shuffled[$i * 4]->id,
                $shuffled[$i * 4 + 1]->id,
                $shuffled[$i * 4 + 2]->id,
                $shuffled[$i * 4 + 3]->id,
            ];
        }

        $this->createRoundFromCourts($tournament, 1, $courts);

        $tournament->update(['status' => 'in_progress']);
        return true;
    }

    /**
     * Сохранить результат матча.
     */
    public function saveMatchResult(KingOfCourtMatch $match, int $team1Score, int $team2Score): void
    {
        $wasCompleted = $match->isCompleted();

        // Откат старых стат, если был сохранён ранее
        if ($wasCompleted) {
            $this->rollbackMatchStats($match);
        }

        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'status' => 'completed',
        ]);
        $match->refresh();

        // Применяем новые стат
        $this->applyMatchStats($match);

        // Если все матчи раунда завершены → пометить раунд completed
        $round = $match->round;
        $pending = $round->matches()->where('status', '!=', 'completed')->count();
        if ($pending === 0 && $round->status !== 'completed') {
            $round->update(['status' => 'completed']);
        }
    }

    /**
     * Можно ли сгенерить следующий раунд.
     */
    public function canGenerateNextRound(Tournament $tournament): bool
    {
        if (!$tournament->isKingOfCourt()) return false;
        if ($tournament->status !== 'in_progress') return false;

        $lastRound = $tournament->kingOfCourtRounds()
            ->reorder('round_number', 'desc')
            ->first();

        return $lastRound && $lastRound->status === 'completed';
    }

    /**
     * Сгенерировать следующий раунд: применить ротацию + перемешать пары.
     */
    public function generateNextRound(Tournament $tournament): bool
    {
        if (!$this->canGenerateNextRound($tournament)) return false;

        $lastRound = $tournament->kingOfCourtRounds()
            ->reorder('round_number', 'desc')
            ->with('matches')
            ->first();

        $matches = $lastRound->matches->sortBy('court_number')->values();
        $courtsCount = $matches->count();

        // Победители + проигравшие каждого корта
        $courtResults = []; // index: court_number-1 → ['winners' => [a,b], 'losers' => [c,d]]
        foreach ($matches as $m) {
            $winners = $m->team1_score > $m->team2_score
                ? [$m->team1_player1_id, $m->team1_player2_id]
                : [$m->team2_player1_id, $m->team2_player2_id];
            $losers = $m->team1_score > $m->team2_score
                ? [$m->team2_player1_id, $m->team2_player2_id]
                : [$m->team1_player1_id, $m->team1_player2_id];

            $courtResults[] = ['winners' => $winners, 'losers' => $losers];
        }

        // Применяем ротацию — собираем игроков для каждого следующего корта.
        // По индексу корта (0-based):
        //   0       (top): прошлые W корта 0 + W корта 1
        //   middle (i):    прошлые L корта (i-1) + W корта (i+1)
        //   N-1   (bot):   прошлые L корта (N-2) + L корта (N-1)
        // Гарантируем, что новые пары МИКСУЮТСЯ: один игрок из pairA + один из pairB,
        // т.е. ни одна старая пара не повторяется.
        $newCourts = [];
        for ($i = 0; $i < $courtsCount; $i++) {
            $pairA = [];
            $pairB = [];

            if ($i === 0) {
                $pairA = $courtResults[0]['winners'];
                $pairB = $courtResults[1]['winners'] ?? [];
            } elseif ($i === $courtsCount - 1) {
                $pairA = $courtResults[$courtsCount - 2]['losers'] ?? [];
                $pairB = $courtResults[$courtsCount - 1]['losers'];
            } else {
                $pairA = $courtResults[$i - 1]['losers'];
                $pairB = $courtResults[$i + 1]['winners'];
            }

            if (count($pairA) !== 2 || count($pairB) !== 2) continue;

            // Случайный порядок внутри каждой пары + случайно меняем pairA и pairB местами.
            shuffle($pairA);
            shuffle($pairB);
            if (random_int(0, 1) === 1) {
                [$pairA, $pairB] = [$pairB, $pairA];
            }

            // createRoundFromCourts формирует пары как (idx 0 + idx 2) vs (idx 1 + idx 3).
            // Раскладка [pairA[0], pairA[1], pairB[0], pairB[1]] даёт:
            //   team1 = pairA[0] + pairB[0], team2 = pairA[1] + pairB[1] — всегда mixed.
            $newCourts[] = [$pairA[0], $pairA[1], $pairB[0], $pairB[1]];
        }

        if (empty($newCourts)) return false;

        $this->createRoundFromCourts($tournament, $lastRound->round_number + 1, $newCourts);

        return true;
    }

    /**
     * Можно ли завершить турнир (последний раунд должен быть полностью завершён).
     */
    public function canFinishTournament(Tournament $tournament): bool
    {
        if (!$tournament->isKingOfCourt()) return false;
        if ($tournament->status !== 'in_progress') return false;

        $lastRound = $tournament->kingOfCourtRounds()
            ->reorder('round_number', 'desc')
            ->first();

        return $lastRound && $lastRound->status === 'completed';
    }

    /**
     * Завершить турнир.
     */
    public function finishTournament(Tournament $tournament): bool
    {
        if (!$this->canFinishTournament($tournament)) return false;

        // Сохраняем итоговый рейтинг (rating_after) — пока просто = before, ELO позже
        foreach ($tournament->kingOfCourtPlayers as $kp) {
            if ($kp->rating_after === null) {
                $kp->update(['rating_after' => $kp->user->rating ?? $kp->rating_before]);
            }
        }

        $tournament->update(['status' => 'completed']);
        return true;
    }

    // ===== Internal =====

    /**
     * Создать раунд + матчи из массива кортов: [[p1,p2,p3,p4], [...], ...].
     * Пары формируются как (players[0]+players[2]) vs (players[1]+players[3]).
     * Перед вызовом массивы кортов уже должны быть перемешаны.
     */
    protected function createRoundFromCourts(Tournament $tournament, int $roundNumber, array $courts): KingOfCourtRound
    {
        $round = KingOfCourtRound::create([
            'tournament_id' => $tournament->id,
            'round_number' => $roundNumber,
            'status' => 'in_progress',
        ]);

        foreach ($courts as $courtIdx => $players) {
            // players уже перемешаны (для первого раунда — рандом, для последующих — shuffle в generateNextRound)
            // Pair: 0+2 vs 1+3 (типичная схема перемешивания пар)
            KingOfCourtMatch::create([
                'kingofcourt_round_id' => $round->id,
                'court_number' => $courtIdx + 1,
                'team1_player1_id' => $players[0],
                'team1_player2_id' => $players[2],
                'team2_player1_id' => $players[1],
                'team2_player2_id' => $players[3],
                'status' => 'pending',
            ]);
        }

        return $round;
    }

    /**
     * Применить стат матча к KingOfCourtPlayer (когда сохранён счёт).
     */
    protected function applyMatchStats(KingOfCourtMatch $match): void
    {
        $tournamentId = $match->round->tournament_id;
        $team1 = [$match->team1_player1_id, $match->team1_player2_id];
        $team2 = [$match->team2_player1_id, $match->team2_player2_id];

        foreach ($team1 as $pId) {
            $kp = KingOfCourtPlayer::where('tournament_id', $tournamentId)
                ->where('user_id', $pId)->first();
            if (!$kp) continue;
            $kp->increment('points_for', (int) $match->team1_score);
            $kp->increment('points_against', (int) $match->team2_score);
            if ($match->team1_score > $match->team2_score) {
                $kp->increment('wins');
                // Очки: 1 балл за победу + забитые мячи
                $kp->increment('total_points', (int) $match->team1_score);
            } else {
                $kp->increment('losses');
                $kp->increment('total_points', (int) $match->team1_score);
            }
        }

        foreach ($team2 as $pId) {
            $kp = KingOfCourtPlayer::where('tournament_id', $tournamentId)
                ->where('user_id', $pId)->first();
            if (!$kp) continue;
            $kp->increment('points_for', (int) $match->team2_score);
            $kp->increment('points_against', (int) $match->team1_score);
            if ($match->team2_score > $match->team1_score) {
                $kp->increment('wins');
                $kp->increment('total_points', (int) $match->team2_score);
            } else {
                $kp->increment('losses');
                $kp->increment('total_points', (int) $match->team2_score);
            }
        }
    }

    /**
     * Откатить стат — для случая редактирования счёта.
     */
    protected function rollbackMatchStats(KingOfCourtMatch $match): void
    {
        $tournamentId = $match->round->tournament_id;
        $team1 = [$match->team1_player1_id, $match->team1_player2_id];
        $team2 = [$match->team2_player1_id, $match->team2_player2_id];

        foreach ($team1 as $pId) {
            $kp = KingOfCourtPlayer::where('tournament_id', $tournamentId)
                ->where('user_id', $pId)->first();
            if (!$kp) continue;
            $kp->decrement('points_for', (int) $match->team1_score);
            $kp->decrement('points_against', (int) $match->team2_score);
            if ($match->team1_score > $match->team2_score) {
                $kp->decrement('wins');
                $kp->decrement('total_points', (int) $match->team1_score);
            } else {
                $kp->decrement('losses');
                $kp->decrement('total_points', (int) $match->team1_score);
            }
        }

        foreach ($team2 as $pId) {
            $kp = KingOfCourtPlayer::where('tournament_id', $tournamentId)
                ->where('user_id', $pId)->first();
            if (!$kp) continue;
            $kp->decrement('points_for', (int) $match->team2_score);
            $kp->decrement('points_against', (int) $match->team1_score);
            if ($match->team2_score > $match->team1_score) {
                $kp->decrement('wins');
                $kp->decrement('total_points', (int) $match->team2_score);
            } else {
                $kp->decrement('losses');
                $kp->decrement('total_points', (int) $match->team2_score);
            }
        }
    }
}

<?php

namespace App\Services;

use App\Models\BaliKocMatch;
use App\Models\BaliKocPair;
use App\Models\BaliKocRound;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;

/**
 * Турнир «Король Корта (Bali Format)».
 *
 * Отличия от обычного KOC:
 *  - Пары ФИКСИРОВАННЫЕ на весь турнир (создаются админом вручную после набора).
 *  - Очки за победу зависят от корта: на N кортах победитель корта K получает
 *    (N+2−K) очков. Раунд 1 — исключение: 1 за победу, 0 за поражение.
 *  - Турнирная таблица по парам с tiebreaker: очки → H2H → выигранные геймы.
 *
 * Ротация после раунда (как в обычном KOC, но пары не миксуются):
 *  - Корт 1 (top): W остаётся, L → корт 2
 *  - Корты 2..N−1:   W → выше, L → ниже
 *  - Корт N (bot): W → выше, L остаётся
 */
class BaliKocService
{
    use \App\Traits\RatingCalculator;

    /**
     * Сохранить пары, созданные админом.
     * $pairs — массив [[player1_id, player2_id], ...] длиной N*N_кортов / 2 пар.
     * Возвращает [success, message].
     */
    public function createPairs(Tournament $tournament, array $pairs): array
    {
        if (!$tournament->isBaliKoc()) {
            return [false, 'Турнир не Bali Format'];
        }
        if ($tournament->status !== 'open') {
            return [false, 'Турнир уже запущен или завершён'];
        }
        if ($tournament->baliKocPairs()->exists()) {
            return [false, 'Пары уже созданы'];
        }

        $participantIds = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->pluck('users.id')
            ->all();

        $totalParticipants = count($participantIds);
        if ($totalParticipants < 8 || $totalParticipants % 4 !== 0) {
            return [false, 'Должно быть минимум 8 игроков и кратно 4'];
        }

        $expectedPairs = (int) ($totalParticipants / 2);
        if (count($pairs) !== $expectedPairs) {
            return [false, "Должно быть {$expectedPairs} пар (а сейчас " . count($pairs) . ')'];
        }

        // Все player_id должны быть среди registered участников
        $seen = [];
        foreach ($pairs as $idx => $pair) {
            if (!isset($pair[0], $pair[1])) {
                return [false, 'Пара ' . ($idx + 1) . ': оба игрока обязательны'];
            }
            $p1 = (int) $pair[0];
            $p2 = (int) $pair[1];
            if ($p1 === $p2) {
                return [false, 'Пара ' . ($idx + 1) . ': игрок не может быть в паре с самим собой'];
            }
            if (!in_array($p1, $participantIds, true) || !in_array($p2, $participantIds, true)) {
                return [false, 'Пара ' . ($idx + 1) . ': игрок не зарегистрирован на турнир'];
            }
            if (isset($seen[$p1])) {
                return [false, "Игрок #{$p1} в нескольких парах"];
            }
            if (isset($seen[$p2])) {
                return [false, "Игрок #{$p2} в нескольких парах"];
            }
            $seen[$p1] = true;
            $seen[$p2] = true;
        }

        if (count($seen) !== $totalParticipants) {
            return [false, 'Не все участники попали в пары'];
        }

        DB::transaction(function () use ($tournament, $pairs) {
            foreach ($pairs as $pair) {
                $p1 = (int) $pair[0];
                $p2 = (int) $pair[1];
                $u1 = \App\Models\User::find($p1);
                $u2 = \App\Models\User::find($p2);
                BaliKocPair::create([
                    'tournament_id' => $tournament->id,
                    'player1_id' => $p1,
                    'player2_id' => $p2,
                    'player1_rating_before' => (int) ($u1->rating ?? $this->minRating),
                    'player2_rating_before' => (int) ($u2->rating ?? $this->minRating),
                ]);
            }
        });

        return [true, 'Пары созданы'];
    }

    /**
     * Проверка: пары созданы.
     */
    public function arePairsCreated(Tournament $tournament): bool
    {
        return $tournament->isBaliKoc() && $tournament->baliKocPairs()->exists();
    }

    /**
     * Запустить турнир: рандомно распределить пары по кортам, сгенерить раунд 1.
     */
    public function startTournament(Tournament $tournament): bool
    {
        if (!$tournament->isBaliKoc()) return false;
        if ($tournament->status !== 'open') return false;
        if (!$this->arePairsCreated($tournament)) return false;

        $pairs = $tournament->baliKocPairs()->get();
        $pairCount = $pairs->count();
        if ($pairCount < 4 || $pairCount % 2 !== 0) return false;

        $courtsCount = (int) ($pairCount / 2);
        $shuffled = $pairs->shuffle()->values();

        $courts = [];
        for ($i = 0; $i < $courtsCount; $i++) {
            $courts[] = [
                'pair1_id' => $shuffled[$i * 2]->id,
                'pair2_id' => $shuffled[$i * 2 + 1]->id,
            ];
        }

        $this->createRoundFromCourts($tournament, 1, $courts);
        $tournament->update(['status' => 'in_progress']);
        return true;
    }

    /**
     * Сохранить результат матча (счёт по геймам).
     * При повторном вызове — откатывает прошлые статы и применяет новые.
     */
    public function saveMatchResult(BaliKocMatch $match, int $pair1Games, int $pair2Games): void
    {
        $wasCompleted = $match->isCompleted();

        if ($wasCompleted) {
            $this->rollbackMatchStats($match);
        }

        $match->update([
            'pair1_games' => $pair1Games,
            'pair2_games' => $pair2Games,
            'status' => 'completed',
        ]);
        $match->refresh();

        $this->applyMatchStats($match);

        // Если все матчи раунда закрыты — помечаем раунд completed
        $round = $match->round;
        $pending = $round->matches()->where('status', '!=', 'completed')->count();
        if ($pending === 0 && $round->status !== 'completed') {
            $round->update(['status' => 'completed']);
        }
    }

    public function canGenerateNextRound(Tournament $tournament): bool
    {
        if (!$tournament->isBaliKoc()) return false;
        if ($tournament->status !== 'in_progress') return false;

        $lastRound = $tournament->baliKocRounds()
            ->reorder('round_number', 'desc')
            ->first();

        return $lastRound && $lastRound->status === 'completed';
    }

    /**
     * Сгенерировать следующий раунд: ротация без миксования пар.
     * Корт 1: W → остаётся, L → корт 2.
     * Корт N: W → корт N−1, L → остаётся.
     * Между: W → выше, L → ниже.
     */
    /**
     * Пересобрать последний раунд из актуальных результатов.
     *
     * Нужно, когда счёт предыдущего раунда исправили уже после генерации
     * следующего: состав считается от результатов, и старый раунд оказывается
     * построен по неверным данным. Введённый в нём счёт теряется — организатор
     * подтверждает это в диалоге.
     */
    public function rebuildLastRound(Tournament $tournament): bool
    {
        if (!$tournament->isBaliKoc() || $tournament->status !== 'in_progress') {
            return false;
        }

        $last = $tournament->baliKocRounds()
            ->reorder('round_number', 'desc')
            ->with('matches')
            ->first();

        if (!$last || (int) $last->round_number <= 1) {
            return false;
        }

        DB::transaction(function () use ($last) {
            foreach ($last->matches as $match) {
                if ($match->isCompleted()) {
                    $this->rollbackMatchStats($match);
                }
            }
            $last->matches()->delete();
            $last->delete();
        });

        return $this->generateNextRound($tournament->fresh());
    }

    public function generateNextRound(Tournament $tournament): bool
    {
        if (!$this->canGenerateNextRound($tournament)) return false;

        $lastRound = $tournament->baliKocRounds()
            ->reorder('round_number', 'desc')
            ->with('matches')
            ->first();

        $matches = $lastRound->matches->sortBy('court_number')->values();
        $courtsCount = $matches->count();

        // Победитель/проигравший на каждом корте
        $results = []; // index → ['winner' => pair_id, 'loser' => pair_id]
        foreach ($matches as $m) {
            if (!$m->isCompleted()) return false;
            $winner = $m->pair1_games > $m->pair2_games ? $m->pair1_id : $m->pair2_id;
            $loser  = $m->pair1_games > $m->pair2_games ? $m->pair2_id : $m->pair1_id;
            $results[] = ['winner' => $winner, 'loser' => $loser];
        }

        // Новое распределение:
        //   i=0: W корта 0 + W корта 1
        //   0<i<N-1: L корта i-1 + W корта i+1
        //   i=N-1: L корта N-2 + L корта N-1
        $newCourts = [];
        for ($i = 0; $i < $courtsCount; $i++) {
            if ($i === 0) {
                $p1 = $results[0]['winner'];
                $p2 = $results[1]['winner'] ?? null;
            } elseif ($i === $courtsCount - 1) {
                $p1 = $results[$courtsCount - 2]['loser'] ?? null;
                $p2 = $results[$courtsCount - 1]['loser'];
            } else {
                $p1 = $results[$i - 1]['loser'];
                $p2 = $results[$i + 1]['winner'];
            }
            if (!$p1 || !$p2) return false;
            $newCourts[] = ['pair1_id' => $p1, 'pair2_id' => $p2];
        }

        $this->createRoundFromCourts($tournament, $lastRound->round_number + 1, $newCourts);
        return true;
    }

    public function canFinishTournament(Tournament $tournament): bool
    {
        if (!$tournament->isBaliKoc()) return false;
        if ($tournament->status !== 'in_progress') return false;

        $lastRound = $tournament->baliKocRounds()
            ->reorder('round_number', 'desc')
            ->first();

        return $lastRound && $lastRound->status === 'completed';
    }

    /**
     * Завершить турнир: ELO ко всем матчам, апдейт рейтингов игроков,
     * запись в RatingHistory.
     */
    public function finishTournament(Tournament $tournament): bool
    {
        if (!$this->canFinishTournament($tournament)) return false;

        // Не рейтинговый турнир — завершаем без начисления рейтинга.
        if (!$tournament->is_rated) {
            $tournament->update(['status' => 'completed']);
            return true;
        }

        $pairs = $tournament->baliKocPairs()->with(['player1', 'player2'])->get();

        // Стартовые рейтинги (по нашему трекеру эволюции внутри турнира)
        $ratingChanges = [];
        foreach ($pairs as $pair) {
            $ratingChanges[$pair->player1_id] = [
                'rating_before' => (int) $pair->player1_rating_before,
                'current_rating' => (int) $pair->player1_rating_before,
            ];
            $ratingChanges[$pair->player2_id] = [
                'rating_before' => (int) $pair->player2_rating_before,
                'current_rating' => (int) $pair->player2_rating_before,
            ];
        }

        foreach ($tournament->baliKocRounds()->orderBy('round_number')->get() as $round) {
            foreach ($round->matches as $match) {
                if (!$match->isCompleted()) continue;
                $this->calculateEloForMatch($match, $pairs, $ratingChanges);
            }
        }

        foreach ($pairs as $pair) {
            foreach ([['player1_id', 'player1', 'player1_rating_before', 'player1_rating_after'],
                      ['player2_id', 'player2', 'player2_rating_before', 'player2_rating_after']] as [$idKey, $rel, $beforeCol, $afterCol]) {
                $uid = $pair->{$idKey};
                $user = $pair->{$rel};
                if (!$user) continue;

                $calcFinal = (int) $ratingChanges[$uid]['current_rating'];
                $delta = $calcFinal - (int) $pair->{$beforeCol};
                $actualBefore = (int) ($user->rating ?? $pair->{$beforeCol});
                $actualAfter = max($this->minRating, $actualBefore + $delta);

                $pair->update([$afterCol => $actualAfter]);
                $user->update(['rating' => $actualAfter]);
                $this->updateLevel($user->fresh());

                \App\Models\RatingHistory::create([
                    'user_id' => $uid,
                    'tournament_id' => $tournament->id,
                    'rating_before' => $actualBefore,
                    'rating_after' => $actualAfter,
                    'change' => $delta,
                    'reason' => $tournament->name,
                ]);
            }
        }

        $tournament->update(['status' => 'completed']);
        return true;
    }

    /**
     * Превью изменений рейтинга по матчам.
     */
    public function previewRatingChanges(Tournament $tournament): array
    {
        $pairs = $tournament->baliKocPairs()->with(['player1', 'player2'])->get();
        $ratingChanges = [];

        foreach ($pairs as $pair) {
            $ratingChanges[$pair->player1_id] = [
                'name' => $pair->player1->name ?? '?',
                'phone' => $pair->player1->phone ?? null,
                'rating_before' => (int) $pair->player1_rating_before,
                'current_rating' => (int) $pair->player1_rating_before,
                'matches' => [],
            ];
            $ratingChanges[$pair->player2_id] = [
                'name' => $pair->player2->name ?? '?',
                'phone' => $pair->player2->phone ?? null,
                'rating_before' => (int) $pair->player2_rating_before,
                'current_rating' => (int) $pair->player2_rating_before,
                'matches' => [],
            ];
        }

        $pairById = $pairs->keyBy('id');

        foreach ($tournament->baliKocRounds()->orderBy('round_number')->get() as $round) {
            foreach ($round->matches as $match) {
                if (!$match->isCompleted()) continue;

                $p1 = $pairById[$match->pair1_id] ?? null;
                $p2 = $pairById[$match->pair2_id] ?? null;
                if (!$p1 || !$p2) continue;

                $t1p1 = $p1->player1_id;
                $t1p2 = $p1->player2_id;
                $t2p1 = $p2->player1_id;
                $t2p2 = $p2->player2_id;

                $r1_1 = $ratingChanges[$t1p1]['current_rating'];
                $r1_2 = $ratingChanges[$t1p2]['current_rating'];
                $r2_1 = $ratingChanges[$t2p1]['current_rating'];
                $r2_2 = $ratingChanges[$t2p2]['current_rating'];

                $team1Rating = ($r1_1 + $r1_2) / 2;
                $team2Rating = ($r2_1 + $r2_2) / 2;

                $result = $this->calculateRatingChange(
                    $team1Rating, $team2Rating,
                    (int) $match->pair1_games, (int) $match->pair2_games
                );

                $expected1 = $this->expectedScore($team1Rating, $team2Rating);
                $kFactor = $this->getMatchKFactor($team1Rating, $team2Rating);
                $multiplier = $this->getScoreMultiplier((int) $match->pair1_games, (int) $match->pair2_games);

                // Формат строки должен соответствовать парсеру в preview-rating.blade.php:
                //   "Р<N>: <team1> vs <team2> | Счёт A:B | K=… | М=… | Шанс=…% | ±N"
                $info1 = sprintf(
                    "Р%d: %s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | Счёт %d:%d | K=%d | М=%.2f | Шанс=%.0f%% | %+d",
                    $round->round_number,
                    $ratingChanges[$t1p1]['name'], $r1_1,
                    $ratingChanges[$t1p2]['name'], $r1_2,
                    round($team1Rating),
                    $ratingChanges[$t2p1]['name'], $r2_1,
                    $ratingChanges[$t2p2]['name'], $r2_2,
                    round($team2Rating),
                    $match->pair1_games, $match->pair2_games,
                    $kFactor, $multiplier, $expected1 * 100,
                    $result['change1']
                );
                $info2 = sprintf(
                    "Р%d: %s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | Счёт %d:%d | K=%d | М=%.2f | Шанс=%.0f%% | %+d",
                    $round->round_number,
                    $ratingChanges[$t2p1]['name'], $r2_1,
                    $ratingChanges[$t2p2]['name'], $r2_2,
                    round($team2Rating),
                    $ratingChanges[$t1p1]['name'], $r1_1,
                    $ratingChanges[$t1p2]['name'], $r1_2,
                    round($team1Rating),
                    $match->pair2_games, $match->pair1_games,
                    $kFactor, $multiplier, (1 - $expected1) * 100,
                    $result['change2']
                );

                $ratingChanges[$t1p1]['matches'][] = $info1;
                $ratingChanges[$t1p2]['matches'][] = $info1;
                $ratingChanges[$t2p1]['matches'][] = $info2;
                $ratingChanges[$t2p2]['matches'][] = $info2;

                $ratingChanges[$t1p1]['current_rating'] = $this->applyRatingChange($r1_1, $result['change1']);
                $ratingChanges[$t1p2]['current_rating'] = $this->applyRatingChange($r1_2, $result['change1']);
                $ratingChanges[$t2p1]['current_rating'] = $this->applyRatingChange($r2_1, $result['change2']);
                $ratingChanges[$t2p2]['current_rating'] = $this->applyRatingChange($r2_2, $result['change2']);
            }
        }

        return $ratingChanges;
    }

    /**
     * Турнирная таблица: пары, отсортированные по
     *  1) очкам (desc)
     *  2) H2H (между равными): кто кого побил
     *  3) выигранным геймам (desc)
     *  4) разнице геймов (desc) — 6:0 выше 6:2
     *  5) id (стабильность)
     */
    public function getStandings(Tournament $tournament): array
    {
        $pairs = $tournament->baliKocPairs()->with(['player1', 'player2'])->get();
        if ($pairs->isEmpty()) return [];

        // Собираем H2H: pair_id => [other_pair_id => wins_count]
        $h2h = [];
        foreach ($pairs as $p) {
            $h2h[$p->id] = [];
        }
        foreach ($tournament->baliKocRounds as $round) {
            foreach ($round->matches as $m) {
                if (!$m->isCompleted()) continue;
                $winner = $m->pair1_games > $m->pair2_games ? $m->pair1_id : $m->pair2_id;
                $loser  = $m->pair1_games > $m->pair2_games ? $m->pair2_id : $m->pair1_id;
                $h2h[$winner][$loser] = ($h2h[$winner][$loser] ?? 0) + 1;
            }
        }

        $arr = $pairs->all();
        usort($arr, function ($a, $b) use ($h2h) {
            // 1) очки
            if ($a->points !== $b->points) {
                return $b->points <=> $a->points;
            }
            // 2) H2H между этими двумя
            $aWins = $h2h[$a->id][$b->id] ?? 0;
            $bWins = $h2h[$b->id][$a->id] ?? 0;
            if ($aWins !== $bWins) {
                return $bWins <=> $aWins;
            }
            // 3) выигранные геймы (games_for)
            if ($a->games_for !== $b->games_for) {
                return $b->games_for <=> $a->games_for;
            }
            // 4) разница геймов (6:0 выше 6:2)
            $aDiff = (int) $a->games_for - (int) $a->games_against;
            $bDiff = (int) $b->games_for - (int) $b->games_against;
            if ($aDiff !== $bDiff) {
                return $bDiff <=> $aDiff;
            }
            // 5) стабильность — id
            return $a->id <=> $b->id;
        });

        return $arr;
    }

    // ===== Internal =====

    /**
     * Создать раунд + матчи из массива кортов:
     *   [['pair1_id' => X, 'pair2_id' => Y], ...]
     */
    protected function createRoundFromCourts(Tournament $tournament, int $roundNumber, array $courts): BaliKocRound
    {
        $round = BaliKocRound::create([
            'tournament_id' => $tournament->id,
            'round_number' => $roundNumber,
            'status' => 'in_progress',
        ]);

        foreach ($courts as $idx => $court) {
            BaliKocMatch::create([
                'bali_koc_round_id' => $round->id,
                'court_number' => $idx + 1,
                'pair1_id' => $court['pair1_id'],
                'pair2_id' => $court['pair2_id'],
                'status' => 'pending',
            ]);
        }

        return $round;
    }

    /**
     * Турнирные очки победителю матча в зависимости от корта и номера раунда.
     */
    public function pointsForMatch(int $roundNumber, int $courtNumber, int $courtsCount): int
    {
        if ($roundNumber === 1) {
            return 1;
        }
        // Корт K из N: (N + 2 − K) очков
        $val = $courtsCount + 2 - $courtNumber;
        return max(1, $val);
    }

    /**
     * Применить статистику матча к парам (очки, wins/losses, геймы).
     */
    protected function applyMatchStats(BaliKocMatch $match): void
    {
        $round = $match->round;
        $tournament = $round->tournament;
        $courtsCount = $round->matches()->count();

        $pair1 = BaliKocPair::find($match->pair1_id);
        $pair2 = BaliKocPair::find($match->pair2_id);
        if (!$pair1 || !$pair2) return;

        $pair1->increment('games_for', (int) $match->pair1_games);
        $pair1->increment('games_against', (int) $match->pair2_games);
        $pair2->increment('games_for', (int) $match->pair2_games);
        $pair2->increment('games_against', (int) $match->pair1_games);

        if ($match->pair1_games > $match->pair2_games) {
            $pair1->increment('wins');
            $pair1->increment('points', $this->pointsForMatch((int) $round->round_number, (int) $match->court_number, (int) $courtsCount));
            $pair2->increment('losses');
        } else {
            $pair2->increment('wins');
            $pair2->increment('points', $this->pointsForMatch((int) $round->round_number, (int) $match->court_number, (int) $courtsCount));
            $pair1->increment('losses');
        }
    }

    /**
     * Откат статов матча (для редактирования счёта).
     */
    protected function rollbackMatchStats(BaliKocMatch $match): void
    {
        $round = $match->round;
        $courtsCount = $round->matches()->count();

        $pair1 = BaliKocPair::find($match->pair1_id);
        $pair2 = BaliKocPair::find($match->pair2_id);
        if (!$pair1 || !$pair2) return;

        $pair1->decrement('games_for', (int) $match->pair1_games);
        $pair1->decrement('games_against', (int) $match->pair2_games);
        $pair2->decrement('games_for', (int) $match->pair2_games);
        $pair2->decrement('games_against', (int) $match->pair1_games);

        if ($match->pair1_games > $match->pair2_games) {
            $pair1->decrement('wins');
            $pair1->decrement('points', $this->pointsForMatch((int) $round->round_number, (int) $match->court_number, (int) $courtsCount));
            $pair2->decrement('losses');
        } else {
            $pair2->decrement('wins');
            $pair2->decrement('points', $this->pointsForMatch((int) $round->round_number, (int) $match->court_number, (int) $courtsCount));
            $pair1->decrement('losses');
        }
    }

    /**
     * ELO для одного матча: 2v2 средними рейтингами команд.
     * Public — используется в mobile API для подсчёта дельты по раундам.
     */
    public function calculateEloForMatch(BaliKocMatch $match, $pairs, array &$ratingChanges): void
    {
        $p1 = $pairs->firstWhere('id', $match->pair1_id);
        $p2 = $pairs->firstWhere('id', $match->pair2_id);
        if (!$p1 || !$p2) return;

        $t1p1 = $p1->player1_id;
        $t1p2 = $p1->player2_id;
        $t2p1 = $p2->player1_id;
        $t2p2 = $p2->player2_id;

        if (!isset($ratingChanges[$t1p1], $ratingChanges[$t1p2], $ratingChanges[$t2p1], $ratingChanges[$t2p2])) {
            return;
        }

        $team1Avg = ($ratingChanges[$t1p1]['current_rating'] + $ratingChanges[$t1p2]['current_rating']) / 2;
        $team2Avg = ($ratingChanges[$t2p1]['current_rating'] + $ratingChanges[$t2p2]['current_rating']) / 2;

        $result = $this->calculateRatingChange(
            $team1Avg, $team2Avg,
            (int) $match->pair1_games, (int) $match->pair2_games
        );

        $ratingChanges[$t1p1]['current_rating'] = $this->applyRatingChange($ratingChanges[$t1p1]['current_rating'], $result['change1']);
        $ratingChanges[$t1p2]['current_rating'] = $this->applyRatingChange($ratingChanges[$t1p2]['current_rating'], $result['change1']);
        $ratingChanges[$t2p1]['current_rating'] = $this->applyRatingChange($ratingChanges[$t2p1]['current_rating'], $result['change2']);
        $ratingChanges[$t2p2]['current_rating'] = $this->applyRatingChange($ratingChanges[$t2p2]['current_rating'], $result['change2']);
    }
}

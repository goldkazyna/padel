<?php

namespace App\Services;

use App\Models\JustPadelItMatch;
use App\Models\JustPadelItPlayer;
use App\Models\JustPadelItRound;
use App\Models\Tournament;
use App\Services\JustPadelItScoring;

/**
 * Турнир «Just Padel It».
 *
 * Гибкий состав: игроков всегда 4×N, кортов — N (минимум 8 игроков / 2 корта).
 * Каждый раунд — на каждом корте сетап 2v2, после каждого раунда:
 *   - Корт 1: победители ОСТАЮТСЯ, проигравшие → корт 2
 *   - Корты 2..N-1: победители → выше, проигравшие → ниже
 *   - Корт N: победители → выше, проигравшие ОСТАЮТСЯ
 * После ротации на каждом корте 4 игрока — пары перемешиваются (важно!).
 */
class JustPadelItService
{
    use \App\Traits\RatingCalculator;

    /**
     * Превью изменений рейтинга по матчам JPI.
     * Структура такая же, как у MexicanoService::previewRatingChanges.
     */
    public function previewRatingChanges(Tournament $tournament): array
    {
        $players = $tournament->justPadelItPlayers()->with('user')->get();
        $ratingChanges = [];

        foreach ($players as $player) {
            $ratingBefore = (int) $player->rating_before;
            $ratingChanges[$player->user_id] = [
                'name' => $player->user->name,
                'phone' => $player->user->phone,
                'rating_before' => $ratingBefore,
                'current_rating' => $ratingBefore,
                'matches' => [],
            ];
        }

        foreach ($tournament->justPadelItRounds()->orderBy('round_number')->get() as $round) {
            foreach ($round->matches as $match) {
                if (!$match->isCompleted()) continue;

                $p1_1 = $match->team1_player1_id;
                $p1_2 = $match->team1_player2_id;
                $p2_1 = $match->team2_player1_id;
                $p2_2 = $match->team2_player2_id;

                if (!isset($ratingChanges[$p1_1], $ratingChanges[$p1_2], $ratingChanges[$p2_1], $ratingChanges[$p2_2])) {
                    continue;
                }

                $r1_1 = $ratingChanges[$p1_1]['current_rating'];
                $r1_2 = $ratingChanges[$p1_2]['current_rating'];
                $r2_1 = $ratingChanges[$p2_1]['current_rating'];
                $r2_2 = $ratingChanges[$p2_2]['current_rating'];

                $team1Rating = ($r1_1 + $r1_2) / 2;
                $team2Rating = ($r2_1 + $r2_2) / 2;

                $result = $this->calculateRatingChange(
                    $team1Rating,
                    $team2Rating,
                    $match->team1_score,
                    $match->team2_score
                );
                $change1 = $result['change1'];
                $change2 = $result['change2'];

                $expected1 = $this->expectedScore($team1Rating, $team2Rating);
                $kFactor = $this->getMatchKFactor($team1Rating, $team2Rating);
                $multiplier = $this->getScoreMultiplier($match->team1_score, $match->team2_score);

                $courtTotal = $round->matches->count();
                $courtIdx = (int) $match->court_number;
                $courtTag = $courtIdx === 1
                    ? "К{$courtIdx}↑"
                    : ($courtIdx === $courtTotal ? "К{$courtIdx}↓" : "К{$courtIdx}");

                $matchInfo1 = sprintf(
                    "Р%d %s: %s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | Счёт %d:%d | K=%d | М=%.2f | Шанс=%.0f%% | %+d",
                    $round->round_number,
                    $courtTag,
                    $ratingChanges[$p1_1]['name'], $r1_1,
                    $ratingChanges[$p1_2]['name'], $r1_2,
                    round($team1Rating),
                    $ratingChanges[$p2_1]['name'], $r2_1,
                    $ratingChanges[$p2_2]['name'], $r2_2,
                    round($team2Rating),
                    $match->team1_score, $match->team2_score,
                    $kFactor,
                    $multiplier,
                    $expected1 * 100,
                    $change1
                );

                $matchInfo2 = sprintf(
                    "Р%d %s: %s(%d) + %s(%d) = [%d] vs %s(%d) + %s(%d) = [%d] | Счёт %d:%d | K=%d | М=%.2f | Шанс=%.0f%% | %+d",
                    $round->round_number,
                    $courtTag,
                    $ratingChanges[$p2_1]['name'], $r2_1,
                    $ratingChanges[$p2_2]['name'], $r2_2,
                    round($team2Rating),
                    $ratingChanges[$p1_1]['name'], $r1_1,
                    $ratingChanges[$p1_2]['name'], $r1_2,
                    round($team1Rating),
                    $match->team2_score, $match->team1_score,
                    $kFactor,
                    $multiplier,
                    (1 - $expected1) * 100,
                    $change2
                );

                $ratingChanges[$p1_1]['matches'][] = $matchInfo1;
                $ratingChanges[$p1_2]['matches'][] = $matchInfo1;
                $ratingChanges[$p2_1]['matches'][] = $matchInfo2;
                $ratingChanges[$p2_2]['matches'][] = $matchInfo2;

                $ratingChanges[$p1_1]['current_rating'] = $this->applyRatingChange($r1_1, $change1);
                $ratingChanges[$p1_2]['current_rating'] = $this->applyRatingChange($r1_2, $change1);
                $ratingChanges[$p2_1]['current_rating'] = $this->applyRatingChange($r2_1, $change2);
                $ratingChanges[$p2_2]['current_rating'] = $this->applyRatingChange($r2_2, $change2);
            }
        }

        return $ratingChanges;
    }

    /**
     * Сохранить фиксированные пары (для is_paired JPI), созданные админом.
     * $pairs — массив [[player1_id, player2_id], ...]. Возвращает [success, message].
     */
    public function createPairs(Tournament $tournament, array $pairs): array
    {
        if (!$tournament->isPairedJustPadelIt()) {
            return [false, 'Турнир не «Just Padel It» с фиксированными парами'];
        }
        if ($tournament->isSelfPairing()) {
            return [false, 'В этом турнире пары собирают сами игроки при записи'];
        }
        if ($tournament->status !== 'open') {
            return [false, 'Турнир уже запущен или завершён'];
        }
        if ($tournament->justPadelItPairs()->exists()) {
            return [false, 'Пары уже созданы'];
        }

        $participantIds = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->pluck('users.id')
            ->all();

        $total = count($participantIds);
        if ($total < 8 || $total % 4 !== 0) {
            return [false, 'Должно быть минимум 8 игроков и кратно 4'];
        }

        $expectedPairs = (int) ($total / 2);
        if (count($pairs) !== $expectedPairs) {
            return [false, "Должно быть {$expectedPairs} пар (а сейчас " . count($pairs) . ')'];
        }

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
            if (isset($seen[$p1]) || isset($seen[$p2])) {
                return [false, 'Игрок не может быть в нескольких парах'];
            }
            $seen[$p1] = true;
            $seen[$p2] = true;
        }

        if (count($seen) !== $total) {
            return [false, 'Не все участники попали в пары'];
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($tournament, $pairs) {
            foreach ($pairs as $pair) {
                \App\Models\JustPadelItPair::create([
                    'tournament_id' => $tournament->id,
                    'player1_id' => (int) $pair[0],
                    'player2_id' => (int) $pair[1],
                ]);
            }
        });

        return [true, 'Пары созданы'];
    }

    /** Пары для is_paired JPI созданы. */
    public function arePairsCreated(Tournament $tournament): bool
    {
        return $tournament->isPairedJustPadelIt() && $tournament->justPadelItPairs()->exists();
    }

    /**
     * Перенести пары, собранные самими игроками, из команд турнира в пары формата.
     *
     * При самостоятельной записи пара регистрируется в tournament_teams — там уже
     * есть модерация, лист ожидания и вывод в приложение. Формату же нужны свои
     * пары и участники, поэтому переносим одобренные команды сюда.
     *
     * @return int сколько пар перенесено
     */
    public function syncPairsFromTeams(Tournament $tournament): int
    {
        if (!$tournament->isPairedJustPadelIt() || !$tournament->isSelfPairing()) {
            return 0;
        }

        $teams = $tournament->teams()
            ->where('status', 'approved')
            ->whereNotNull('player2_id')
            ->orderBy('id')
            ->get();

        $made = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($tournament, $teams, &$made) {
            foreach ($teams as $team) {
                // Оба игрока должны быть участниками: по ним формат считает состав.
                foreach ([$team->player1_id, $team->player2_id] as $userId) {
                    if (!$tournament->participants()->where('users.id', $userId)->exists()) {
                        $tournament->participants()->attach($userId, ['status' => 'registered']);
                    }
                }

                $exists = $tournament->justPadelItPairs()
                    ->where(function ($q) use ($team) {
                        $q->where(function ($q2) use ($team) {
                            $q2->where('player1_id', $team->player1_id)
                               ->where('player2_id', $team->player2_id);
                        })->orWhere(function ($q2) use ($team) {
                            $q2->where('player1_id', $team->player2_id)
                               ->where('player2_id', $team->player1_id);
                        });
                    })
                    ->exists();

                if ($exists) continue;

                \App\Models\JustPadelItPair::create([
                    'tournament_id' => $tournament->id,
                    'player1_id' => $team->player1_id,
                    'player2_id' => $team->player2_id,
                ]);
                $made++;
            }
        });

        return $made;
    }

    /**
     * Запустить турнир: создаём JPI-игроков, генерим первый раунд.
     * Соло-режим — посев по рейтингу (либо явный порядок из экрана посева);
     * парный (is_paired) — фикс-пары по 2 на корт, отсортированные по рейтингу.
     */
    public function startTournament(Tournament $tournament, ?array $order = null): bool
    {
        if (!$tournament->isJustPadelIt()) return false;
        if ($tournament->status !== 'open') return false;

        // Пары собрали сами игроки — они лежат в командах турнира, как в групповом.
        // Переносим их в пары формата и в участников, иначе стартовать нечем.
        if ($tournament->isSelfPairing()) {
            $this->syncPairsFromTeams($tournament);
        }

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->get();

        $count = $participants->count();
        if ($count < 8 || $count % 4 !== 0) {
            return false; // Минимум 8 игроков, кратно 4
        }

        $paired = $tournament->isPairedJustPadelIt();
        if ($paired && !$this->arePairsCreated($tournament)) {
            return false; // Парный JPI требует созданные пары
        }

        // Записи JPI-игроков (стат = 0 в начале)
        foreach ($participants as $u) {
            JustPadelItPlayer::firstOrCreate(
                ['tournament_id' => $tournament->id, 'user_id' => $u->id],
                ['rating_before' => $u->rating]
            );
        }

        if ($paired) {
            // Фиксированные пары: 2 пары на корт, сортировка по сумме рейтинга ↓.
            $pairs = $tournament->justPadelItPairs()->get();
            $pairs = $pairs->sortByDesc(function ($pair) {
                return (int) ($pair->player1->rating ?? 0) + (int) ($pair->player2->rating ?? 0);
            })->values();
            $courtsCount = (int) ($pairs->count() / 2);
            $courts = [];
            for ($i = 0; $i < $courtsCount; $i++) {
                $courts[] = [$pairs[$i * 2], $pairs[$i * 2 + 1]];
            }
            $this->createRoundFromPairs($tournament, 1, $courts);
        } else {
            // Соло: посев по рейтингу (сильные → корт 1) либо явный порядок посева.
            if ($order && count($order)) {
                $byId = $participants->keyBy('id');
                $ordered = collect($order)->map(fn ($id) => $byId->get($id))->filter()->values();
                // добавить забытых (если порядок неполный)
                foreach ($participants as $p) {
                    if (!$ordered->contains('id', $p->id)) $ordered->push($p);
                }
                $participants = $ordered->values();
            } else {
                $participants = $participants->sortByDesc('rating')->values();
            }

            $courts = [];
            $courtsCount = (int) ($count / 4);
            for ($i = 0; $i < $courtsCount; $i++) {
                $courts[] = [
                    $participants[$i * 4]->id,
                    $participants[$i * 4 + 1]->id,
                    $participants[$i * 4 + 2]->id,
                    $participants[$i * 4 + 3]->id,
                ];
            }
            $this->createRoundFromCourts($tournament, 1, $courts);
        }

        $tournament->update(['status' => 'in_progress']);
        return true;
    }

    /**
     * Сохранить результат матча.
     */
    public function saveMatchResult(JustPadelItMatch $match, int $team1Score, int $team2Score): void
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
        if (!$tournament->isJustPadelIt()) return false;
        if ($tournament->status !== 'in_progress') return false;

        $lastRound = $tournament->justPadelItRounds()
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

        if ($tournament->isPairedJustPadelIt()) {
            return $this->generateNextPairedRound($tournament);
        }

        $lastRound = $tournament->justPadelItRounds()
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
        if (!$tournament->isJustPadelIt()) return false;
        if ($tournament->status !== 'in_progress') return false;

        $lastRound = $tournament->justPadelItRounds()
            ->reorder('round_number', 'desc')
            ->first();

        return $lastRound && $lastRound->status === 'completed';
    }

    /**
     * Завершить турнир.
     * Применяем ELO ко всем матчам, сохраняем rating_after, обновляем
     * рейтинг пользователя и пишем запись в RatingHistory.
     */
    public function finishTournament(Tournament $tournament): bool
    {
        if (!$this->canFinishTournament($tournament)) return false;

        // Не рейтинговый турнир — завершаем без начисления рейтинга.
        if (!$tournament->is_rated) {
            $tournament->update(['status' => 'completed']);
            return true;
        }

        $players = $tournament->justPadelItPlayers()->with('user')->get();
        $ratingChanges = [];

        foreach ($players as $player) {
            $ratingChanges[$player->user_id] = [
                'rating_before' => (int) $player->rating_before,
                'current_rating' => (int) $player->rating_before,
            ];
        }

        foreach ($tournament->justPadelItRounds()->orderBy('round_number')->get() as $round) {
            foreach ($round->matches as $match) {
                if ($match->status !== 'completed') continue;
                $this->calculateEloForMatch($match, $ratingChanges);
            }
        }

        foreach ($players as $player) {
            $calcFinal = (int) $ratingChanges[$player->user_id]['current_rating'];
            $delta = $calcFinal - (int) $player->rating_before;
            $actualBefore = (int) ($player->user->rating ?? $player->rating_before);
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
        return true;
    }

    /**
     * ELO для одного матча JPI: 2v2, средние рейтинги команд → дельта.
     * Public — используется снаружи (например, MobileTournamentController::live)
     * чтобы прокручивать эволюцию рейтингов без записи в БД.
     */
    public function calculateEloForMatch(JustPadelItMatch $match, array &$ratingChanges): void
    {
        $p1_1 = $match->team1_player1_id;
        $p1_2 = $match->team1_player2_id;
        $p2_1 = $match->team2_player1_id;
        $p2_2 = $match->team2_player2_id;

        if (!isset($ratingChanges[$p1_1], $ratingChanges[$p1_2], $ratingChanges[$p2_1], $ratingChanges[$p2_2])) {
            return;
        }

        $team1Rating = ($ratingChanges[$p1_1]['current_rating'] + $ratingChanges[$p1_2]['current_rating']) / 2;
        $team2Rating = ($ratingChanges[$p2_1]['current_rating'] + $ratingChanges[$p2_2]['current_rating']) / 2;

        $result = $this->calculateRatingChange(
            $team1Rating,
            $team2Rating,
            $match->team1_score,
            $match->team2_score
        );

        $ratingChanges[$p1_1]['current_rating'] = $this->applyRatingChange($ratingChanges[$p1_1]['current_rating'], $result['change1']);
        $ratingChanges[$p1_2]['current_rating'] = $this->applyRatingChange($ratingChanges[$p1_2]['current_rating'], $result['change1']);
        $ratingChanges[$p2_1]['current_rating'] = $this->applyRatingChange($ratingChanges[$p2_1]['current_rating'], $result['change2']);
        $ratingChanges[$p2_2]['current_rating'] = $this->applyRatingChange($ratingChanges[$p2_2]['current_rating'], $result['change2']);
    }

    // ===== Internal =====

    /**
     * Создать раунд + матчи из массива кортов: [[p1,p2,p3,p4], [...], ...].
     * Пары формируются как (players[0]+players[2]) vs (players[1]+players[3]).
     * Перед вызовом массивы кортов уже должны быть перемешаны.
     */
    protected function createRoundFromCourts(Tournament $tournament, int $roundNumber, array $courts): JustPadelItRound
    {
        $round = JustPadelItRound::create([
            'tournament_id' => $tournament->id,
            'round_number' => $roundNumber,
            'status' => 'in_progress',
        ]);

        foreach ($courts as $courtIdx => $players) {
            // players уже перемешаны (для первого раунда — посев по рейтингу, для последующих — shuffle в generateNextRound)
            // Pair: 0+2 vs 1+3 (типичная схема перемешивания пар)
            JustPadelItMatch::create([
                'just_padel_it_round_id' => $round->id,
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
     * Ротация для парного JPI: пары целые, схема как в обычном KoC/Bali.
     *   корт 0 (top): W к0 + W к1
     *   корты i:      L к(i-1) + W к(i+1)
     *   корт N-1:     L к(N-2) + L к(N-1)
     */
    protected function generateNextPairedRound(Tournament $tournament): bool
    {
        $lastRound = $tournament->justPadelItRounds()
            ->reorder('round_number', 'desc')
            ->with('matches')
            ->first();

        $matches = $lastRound->matches->sortBy('court_number')->values();
        $courtsCount = $matches->count();

        // Карта: множество игроков пары → модель пары.
        $pairByKey = [];
        foreach ($tournament->justPadelItPairs()->get() as $p) {
            $pairByKey[$this->pairKey($p->player1_id, $p->player2_id)] = $p;
        }

        $results = []; // index → ['winner' => pair, 'loser' => pair]
        foreach ($matches as $m) {
            if (!$m->isCompleted()) return false;
            $t1win = $m->team1_score > $m->team2_score;
            $winKey = $t1win
                ? $this->pairKey($m->team1_player1_id, $m->team1_player2_id)
                : $this->pairKey($m->team2_player1_id, $m->team2_player2_id);
            $loseKey = $t1win
                ? $this->pairKey($m->team2_player1_id, $m->team2_player2_id)
                : $this->pairKey($m->team1_player1_id, $m->team1_player2_id);
            $results[] = [
                'winner' => $pairByKey[$winKey] ?? null,
                'loser' => $pairByKey[$loseKey] ?? null,
            ];
        }

        $newCourts = [];
        for ($i = 0; $i < $courtsCount; $i++) {
            if ($i === 0) {
                $a = $results[0]['winner'];
                $b = $results[1]['winner'] ?? null;
            } elseif ($i === $courtsCount - 1) {
                $a = $results[$courtsCount - 2]['loser'] ?? null;
                $b = $results[$courtsCount - 1]['loser'];
            } else {
                $a = $results[$i - 1]['loser'];
                $b = $results[$i + 1]['winner'];
            }
            if (!$a || !$b) return false;
            $newCourts[] = [$a, $b];
        }

        $this->createRoundFromPairs($tournament, $lastRound->round_number + 1, $newCourts);
        return true;
    }

    /** Ключ пары по множеству игроков (порядок не важен). */
    protected function pairKey(int $a, int $b): string
    {
        return $a < $b ? "{$a}-{$b}" : "{$b}-{$a}";
    }

    /**
     * Создать раунд + матчи из массива кортов парного JPI:
     *   [[pairA, pairB], ...] — team1 = pairA (оба игрока), team2 = pairB.
     */
    protected function createRoundFromPairs(Tournament $tournament, int $roundNumber, array $courts): JustPadelItRound
    {
        $round = JustPadelItRound::create([
            'tournament_id' => $tournament->id,
            'round_number' => $roundNumber,
            'status' => 'in_progress',
        ]);

        foreach ($courts as $idx => [$pairA, $pairB]) {
            JustPadelItMatch::create([
                'just_padel_it_round_id' => $round->id,
                'court_number' => $idx + 1,
                'team1_player1_id' => $pairA->player1_id,
                'team1_player2_id' => $pairA->player2_id,
                'team2_player1_id' => $pairB->player1_id,
                'team2_player2_id' => $pairB->player2_id,
                'status' => 'pending',
            ]);
        }

        return $round;
    }

    /**
     * Турнирная таблица по парам (для is_paired JPI).
     * Стат пары = стат одного игрока (у обоих она идентична, т.к. всегда играют
     * вместе). Сортировка: очки → победы (JustPadelItScoring::sortStandings).
     */
    public function getPairStandings(Tournament $tournament): array
    {
        $pairs = $tournament->justPadelItPairs()->with(['player1', 'player2'])->get();
        if ($pairs->isEmpty()) return [];

        $players = $tournament->justPadelItPlayers()->get()->keyBy('user_id');

        $rows = [];
        foreach ($pairs as $pair) {
            $jp = $players->get($pair->player1_id); // у обоих игроков стат одинаков
            $wins = (int) ($jp->wins ?? 0);
            $losses = (int) ($jp->losses ?? 0);
            $games = $wins + $losses;
            $pf = (int) ($jp->points_for ?? 0);
            $pa = (int) ($jp->points_against ?? 0);
            $rows[] = [
                'pair' => $pair,
                'total_points' => (int) ($jp->total_points ?? 0),
                'wins' => $wins,
                'losses' => $losses,
                'points_for' => $pf,
                'points_against' => $pa,
                'diff' => $pf - $pa,
                'win_rate' => $games > 0 ? (int) round($wins / $games * 100) : 0,
            ];
        }

        return JustPadelItScoring::sortStandings($rows, (bool) $tournament->jpi_rank_by_wins);
    }

    /**
     * Применить стат матча к JustPadelItPlayer (когда сохранён счёт).
     * Победителям, в дополнение к очкам за счёт, начисляется бонус за корт
     * (courtBonus: корт 1 → +3, корт 2 → +2, остальные → +1) — обоим игрокам
     * победившей команды.
     */
    protected function applyMatchStats(JustPadelItMatch $match): void
    {
        $tournamentId = $match->round->tournament_id;
        $team1 = [$match->team1_player1_id, $match->team1_player2_id];
        $team2 = [$match->team2_player1_id, $match->team2_player2_id];
        $bonus = JustPadelItScoring::courtBonus((int) $match->court_number);

        foreach ($team1 as $pId) {
            $jp = JustPadelItPlayer::where('tournament_id', $tournamentId)
                ->where('user_id', $pId)->first();
            if (!$jp) continue;
            $jp->increment('points_for', (int) $match->team1_score);
            $jp->increment('points_against', (int) $match->team2_score);
            if ($match->team1_score > $match->team2_score) {
                $jp->increment('wins');
                // Очки: забитые мячи + бонус за корт (только победителям)
                $jp->increment('total_points', (int) $match->team1_score);
                $jp->increment('total_points', $bonus);
            } else {
                $jp->increment('losses');
                $jp->increment('total_points', (int) $match->team1_score);
            }
        }

        foreach ($team2 as $pId) {
            $jp = JustPadelItPlayer::where('tournament_id', $tournamentId)
                ->where('user_id', $pId)->first();
            if (!$jp) continue;
            $jp->increment('points_for', (int) $match->team2_score);
            $jp->increment('points_against', (int) $match->team1_score);
            if ($match->team2_score > $match->team1_score) {
                $jp->increment('wins');
                $jp->increment('total_points', (int) $match->team2_score);
                $jp->increment('total_points', $bonus);
            } else {
                $jp->increment('losses');
                $jp->increment('total_points', (int) $match->team2_score);
            }
        }
    }

    /**
     * Откатить стат — для случая редактирования счёта.
     * Симметрично applyMatchStats: у победителей вычитается и счёт, и бонус за корт.
     */
    protected function rollbackMatchStats(JustPadelItMatch $match): void
    {
        $tournamentId = $match->round->tournament_id;
        $team1 = [$match->team1_player1_id, $match->team1_player2_id];
        $team2 = [$match->team2_player1_id, $match->team2_player2_id];
        $bonus = JustPadelItScoring::courtBonus((int) $match->court_number);

        foreach ($team1 as $pId) {
            $jp = JustPadelItPlayer::where('tournament_id', $tournamentId)
                ->where('user_id', $pId)->first();
            if (!$jp) continue;
            $jp->decrement('points_for', (int) $match->team1_score);
            $jp->decrement('points_against', (int) $match->team2_score);
            if ($match->team1_score > $match->team2_score) {
                $jp->decrement('wins');
                $jp->decrement('total_points', (int) $match->team1_score);
                $jp->decrement('total_points', $bonus);
            } else {
                $jp->decrement('losses');
                $jp->decrement('total_points', (int) $match->team1_score);
            }
        }

        foreach ($team2 as $pId) {
            $jp = JustPadelItPlayer::where('tournament_id', $tournamentId)
                ->where('user_id', $pId)->first();
            if (!$jp) continue;
            $jp->decrement('points_for', (int) $match->team2_score);
            $jp->decrement('points_against', (int) $match->team1_score);
            if ($match->team2_score > $match->team1_score) {
                $jp->decrement('wins');
                $jp->decrement('total_points', (int) $match->team2_score);
                $jp->decrement('total_points', $bonus);
            } else {
                $jp->decrement('losses');
                $jp->decrement('total_points', (int) $match->team2_score);
            }
        }
    }
}

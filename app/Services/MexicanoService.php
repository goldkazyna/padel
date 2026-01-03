<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\MexicanoPlayer;
use App\Models\MexicanoRound;
use App\Models\MexicanoMatch;
use App\Models\MexicanoPairHistory;
use App\Models\User;

class MexicanoService
{
    /**
     * Запустить турнир Мексикано
     */
    public function startTournament(Tournament $tournament): bool
    {
        $participants = $tournament->participants()->orderBy('rating', 'desc')->get();
        
        if ($participants->count() !== $tournament->max_participants) {
            return false;
        }

        if ($tournament->mexicanoPlayers()->count() > 0) {
            return false;
        }

        // Создаём записи игроков с сохранением рейтинга
        foreach ($participants as $player) {
            MexicanoPlayer::create([
                'tournament_id' => $tournament->id,
                'user_id' => $player->id,
                'total_points' => 0,
                'rating_before' => $player->rating,
            ]);
        }

        // Генерируем первый раунд по рейтингу
        $this->generateFirstRound($tournament, $participants->pluck('id')->toArray());

        $tournament->update(['status' => 'in_progress']);

        return true;
    }

    /**
     * Генерация первого раунда (по рейтингу)
     */
    protected function generateFirstRound(Tournament $tournament, array $playerIds): void
    {
        $round = MexicanoRound::create([
            'tournament_id' => $tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);

        // Игроки уже отсортированы по рейтингу
        // 1+2 vs 3+4, 5+6 vs 7+8, ...
        for ($i = 0; $i < count($playerIds); $i += 4) {
            if (isset($playerIds[$i + 3])) {
                MexicanoMatch::create([
                    'mexicano_round_id' => $round->id,
                    'team1_player1_id' => $playerIds[$i],
                    'team1_player2_id' => $playerIds[$i + 1],
                    'team2_player1_id' => $playerIds[$i + 2],
                    'team2_player2_id' => $playerIds[$i + 3],
                    'status' => 'pending',
                ]);

                // Записываем историю пар
                $this->recordPairHistory($tournament->id, $playerIds[$i], $playerIds[$i + 1], true);
                $this->recordPairHistory($tournament->id, $playerIds[$i + 2], $playerIds[$i + 3], true);
                $this->recordPairHistory($tournament->id, $playerIds[$i], $playerIds[$i + 2], false);
                $this->recordPairHistory($tournament->id, $playerIds[$i], $playerIds[$i + 3], false);
                $this->recordPairHistory($tournament->id, $playerIds[$i + 1], $playerIds[$i + 2], false);
                $this->recordPairHistory($tournament->id, $playerIds[$i + 1], $playerIds[$i + 3], false);
            }
        }
    }

    /**
     * Записать историю пар
     */
    protected function recordPairHistory(int $tournamentId, int $player1Id, int $player2Id, bool $asPartners): void
    {
        // Всегда храним меньший ID первым для консистентности
        $p1 = min($player1Id, $player2Id);
        $p2 = max($player1Id, $player2Id);

        $history = MexicanoPairHistory::firstOrCreate([
            'tournament_id' => $tournamentId,
            'player1_id' => $p1,
            'player2_id' => $p2,
        ], [
            'times_as_partners' => 0,
            'times_as_opponents' => 0,
        ]);

        if ($asPartners) {
            $history->increment('times_as_partners');
        } else {
            $history->increment('times_as_opponents');
        }
    }

    /**
     * Генерация следующего раунда (по очкам)
     */
    public function generateNextRound(Tournament $tournament): ?MexicanoRound
    {
        $currentRoundNumber = $tournament->mexicanoRounds()->reorder('round_number', 'desc')->value('round_number') ?? 0;
        
        if ($currentRoundNumber >= $tournament->rounds_count) {
            return null; // Все раунды сыграны
        }

        // Получаем игроков отсортированных по очкам
        $players = $tournament->mexicanoPlayers()
            ->orderBy('total_points', 'desc')
            ->with('user')
            ->get();

        $round = MexicanoRound::create([
            'tournament_id' => $tournament->id,
            'round_number' => $currentRoundNumber + 1,
            'status' => 'in_progress',
        ]);

        // Формируем пары с учётом истории
        $this->generatePairsForRound($tournament, $round, $players);

        return $round;
    }

    /**
     * Формирование пар для раунда
     */
    protected function generatePairsForRound(Tournament $tournament, MexicanoRound $round, $players): void
    {
        $playerIds = $players->pluck('user_id')->toArray();
        $used = [];
        $matches = [];

        while (count($used) < count($playerIds)) {
            // Находим первого неиспользованного игрока
            $p1 = null;
            foreach ($playerIds as $id) {
                if (!in_array($id, $used)) {
                    $p1 = $id;
                    $used[] = $id;
                    break;
                }
            }

            if (!$p1) break;

            // Ищем лучшего партнёра (с кем меньше всего играл в паре)
            $p2 = $this->findBestPartner($tournament->id, $p1, $playerIds, $used);
            if ($p2) $used[] = $p2;

            // Ищем первого соперника
            $p3 = null;
            foreach ($playerIds as $id) {
                if (!in_array($id, $used)) {
                    $p3 = $id;
                    $used[] = $id;
                    break;
                }
            }

            if (!$p3) break;

            // Ищем лучшего партнёра для p3
            $p4 = $this->findBestPartner($tournament->id, $p3, $playerIds, $used);
            if ($p4) $used[] = $p4;

            if ($p1 && $p2 && $p3 && $p4) {
                MexicanoMatch::create([
                    'mexicano_round_id' => $round->id,
                    'team1_player1_id' => $p1,
                    'team1_player2_id' => $p2,
                    'team2_player1_id' => $p3,
                    'team2_player2_id' => $p4,
                    'status' => 'pending',
                ]);

                // Записываем историю
                $this->recordPairHistory($tournament->id, $p1, $p2, true);
                $this->recordPairHistory($tournament->id, $p3, $p4, true);
                $this->recordPairHistory($tournament->id, $p1, $p3, false);
                $this->recordPairHistory($tournament->id, $p1, $p4, false);
                $this->recordPairHistory($tournament->id, $p2, $p3, false);
                $this->recordPairHistory($tournament->id, $p2, $p4, false);
            }
        }
    }

    /**
     * Найти лучшего партнёра (с кем меньше всего играл)
     */
    protected function findBestPartner(int $tournamentId, int $playerId, array $allPlayers, array $excluded): ?int
    {
        $bestPartner = null;
        $minTimes = PHP_INT_MAX;

        foreach ($allPlayers as $candidateId) {
            if ($candidateId === $playerId || in_array($candidateId, $excluded)) {
                continue;
            }

            $times = $this->getTimesAsPartners($tournamentId, $playerId, $candidateId);
            
            if ($times < $minTimes) {
                $minTimes = $times;
                $bestPartner = $candidateId;
            }
        }

        return $bestPartner;
    }

    /**
     * Получить количество раз в паре
     */
    protected function getTimesAsPartners(int $tournamentId, int $player1Id, int $player2Id): int
    {
        $p1 = min($player1Id, $player2Id);
        $p2 = max($player1Id, $player2Id);

        $history = MexicanoPairHistory::where('tournament_id', $tournamentId)
            ->where('player1_id', $p1)
            ->where('player2_id', $p2)
            ->first();

        return $history ? $history->times_as_partners : 0;
    }

    /**
     * Сохранить результат матча
     */
    public function saveMatchResult(MexicanoMatch $match, int $team1Score, int $team2Score): void
    {
        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'status' => 'completed',
        ]);

        $tournament = $match->round->tournament;

        // Обновляем очки игроков
        $this->addPlayerPoints($tournament->id, $match->team1_player1_id, $team1Score);
        $this->addPlayerPoints($tournament->id, $match->team1_player2_id, $team1Score);
        $this->addPlayerPoints($tournament->id, $match->team2_player1_id, $team2Score);
        $this->addPlayerPoints($tournament->id, $match->team2_player2_id, $team2Score);

        // Проверяем завершение раунда
        $this->checkRoundCompletion($match->round);
    }

    /**
     * Обновить результат матча
     */
    public function updateMatchResult(MexicanoMatch $match, int $team1Score, int $team2Score): void
    {
        $tournament = $match->round->tournament;

        // Откатываем старые очки
        if ($match->isCompleted()) {
            $this->addPlayerPoints($tournament->id, $match->team1_player1_id, -$match->team1_score);
            $this->addPlayerPoints($tournament->id, $match->team1_player2_id, -$match->team1_score);
            $this->addPlayerPoints($tournament->id, $match->team2_player1_id, -$match->team2_score);
            $this->addPlayerPoints($tournament->id, $match->team2_player2_id, -$match->team2_score);
        }

        // Сохраняем новые
        $match->update([
            'team1_score' => $team1Score,
            'team2_score' => $team2Score,
            'status' => 'completed',
        ]);

        $this->addPlayerPoints($tournament->id, $match->team1_player1_id, $team1Score);
        $this->addPlayerPoints($tournament->id, $match->team1_player2_id, $team1Score);
        $this->addPlayerPoints($tournament->id, $match->team2_player1_id, $team2Score);
        $this->addPlayerPoints($tournament->id, $match->team2_player2_id, $team2Score);
    }

    /**
     * Добавить очки игроку
     */
    protected function addPlayerPoints(int $tournamentId, int $userId, int $points): void
    {
        MexicanoPlayer::where('tournament_id', $tournamentId)
            ->where('user_id', $userId)
            ->increment('total_points', $points);
    }

    /**
     * Проверить завершение раунда
     */
	protected function checkRoundCompletion(MexicanoRound $round): void
	{
		$pendingCount = $round->matches()->where('status', 'pending')->count();
		
		if ($pendingCount === 0) {
			$round->update(['status' => 'completed']);
		}
	}
    /**
     * Проверить можно ли завершить турнир
     */
    public function canFinishTournament(Tournament $tournament): bool
    {
        $completedRounds = $tournament->mexicanoRounds()->where('status', 'completed')->count();
        return $completedRounds >= $tournament->rounds_count;
    }

    /**
     * Завершить турнир и начислить Эло
     */
    public function finishTournament(Tournament $tournament): bool
    {
        if (!$this->canFinishTournament($tournament)) {
            return false;
        }

        $players = $tournament->mexicanoPlayers()->with('user')->get();
        $ratingChanges = [];

        // Инициализируем рейтинги
        foreach ($players as $player) {
            $ratingChanges[$player->user_id] = [
                'rating_before' => (int) $player->rating_before,
                'current_rating' => (int) $player->rating_before,
            ];
        }

        // Проходим по всем матчам
        foreach ($tournament->mexicanoRounds()->orderBy('round_number')->get() as $round) {
            foreach ($round->matches as $match) {
                $this->calculateEloForMatch($match, $ratingChanges);
            }
        }

        // Сохраняем финальные рейтинги
        foreach ($players as $player) {
            $newRating = (int) $ratingChanges[$player->user_id]['current_rating'];
            
            $player->update(['rating_after' => $newRating]);
            $player->user->update(['rating' => $newRating]);
			// Записываем историю
			\App\Models\RatingHistory::create([
				'user_id' => $player->user_id,
				'tournament_id' => $tournament->id,
				'rating_before' => (int) $player->rating_before,
				'rating_after' => $newRating,
				'change' => $newRating - (int) $player->rating_before,
				'reason' => $tournament->name,
			]);
        }

        $tournament->update(['status' => 'completed']);

        return true;
    }

    /**
     * Рассчитать Эло для матча
     */
    protected function calculateEloForMatch(MexicanoMatch $match, array &$ratingChanges): void
    {
        $p1_1 = $match->team1_player1_id;
        $p1_2 = $match->team1_player2_id;
        $p2_1 = $match->team2_player1_id;
        $p2_2 = $match->team2_player2_id;

        $team1Rating = ($ratingChanges[$p1_1]['current_rating'] + $ratingChanges[$p1_2]['current_rating']) / 2;
        $team2Rating = ($ratingChanges[$p2_1]['current_rating'] + $ratingChanges[$p2_2]['current_rating']) / 2;

        $expected1 = $this->expectedScore($team1Rating, $team2Rating);
        $expected2 = $this->expectedScore($team2Rating, $team1Rating);

        if ($match->team1_score > $match->team2_score) {
            $actual1 = 1;
            $actual2 = 0;
        } elseif ($match->team2_score > $match->team1_score) {
            $actual1 = 0;
            $actual2 = 1;
        } else {
            $actual1 = 0.5;
            $actual2 = 0.5;
        }

        $kFactor = 24;
        $change1 = round($kFactor * ($actual1 - $expected1));
        $change2 = round($kFactor * ($actual2 - $expected2));

        $ratingChanges[$p1_1]['current_rating'] = max(100, $ratingChanges[$p1_1]['current_rating'] + $change1);
        $ratingChanges[$p1_2]['current_rating'] = max(100, $ratingChanges[$p1_2]['current_rating'] + $change1);
        $ratingChanges[$p2_1]['current_rating'] = max(100, $ratingChanges[$p2_1]['current_rating'] + $change2);
        $ratingChanges[$p2_2]['current_rating'] = max(100, $ratingChanges[$p2_2]['current_rating'] + $change2);
    }

    protected function expectedScore(float $ratingA, float $ratingB): float
    {
        return 1 / (1 + pow(10, ($ratingB - $ratingA) / 400));
    }

    protected function updateLevel($player): void
    {
        $rating = $player->rating;
        
        $level = match(true) {
            $rating < 800 => 1.0,
            $rating < 900 => 1.25,
            $rating < 1000 => 1.5,
            $rating < 1100 => 1.75,
            $rating < 1200 => 2.0,
            $rating < 1300 => 2.25,
            $rating < 1400 => 2.5,
            $rating < 1500 => 2.75,
            $rating < 1600 => 3.0,
            $rating < 1700 => 3.25,
            $rating < 1800 => 3.5,
            $rating < 1900 => 3.75,
            $rating < 2000 => 4.0,
            $rating < 2100 => 4.25,
            $rating < 2200 => 4.5,
            $rating < 2300 => 4.75,
            $rating < 2400 => 5.0,
            $rating < 2500 => 5.25,
            $rating < 2600 => 5.5,
            default => 5.75,
        };

        $player->update(['level' => $level]);
    }

    /**
     * Превью рейтинга
     */
    public function previewRatingChanges(Tournament $tournament): array
    {
        $players = $tournament->mexicanoPlayers()->with('user')->get();
        $ratingChanges = [];

        foreach ($players as $player) {
            $ratingBefore = (int) $player->rating_before;
            $ratingChanges[$player->user_id] = [
                'name' => $player->user->full_name,
                'rating_before' => $ratingBefore,
                'current_rating' => $ratingBefore,
                'matches' => [],
            ];
        }

        foreach ($tournament->mexicanoRounds()->orderBy('round_number')->get() as $round) {
            foreach ($round->matches as $match) {
                if (!$match->isCompleted()) continue;

                $p1_1 = $match->team1_player1_id;
                $p1_2 = $match->team1_player2_id;
                $p2_1 = $match->team2_player1_id;
                $p2_2 = $match->team2_player2_id;

                $team1Rating = ($ratingChanges[$p1_1]['current_rating'] + $ratingChanges[$p1_2]['current_rating']) / 2;
                $team2Rating = ($ratingChanges[$p2_1]['current_rating'] + $ratingChanges[$p2_2]['current_rating']) / 2;

                $expected1 = $this->expectedScore($team1Rating, $team2Rating);
                $expected2 = $this->expectedScore($team2Rating, $team1Rating);

                if ($match->team1_score > $match->team2_score) {
                    $actual1 = 1; $actual2 = 0;
                } elseif ($match->team2_score > $match->team1_score) {
                    $actual1 = 0; $actual2 = 1;
                } else {
                    $actual1 = 0.5; $actual2 = 0.5;
                }

                $kFactor = 24;
                $change1 = round($kFactor * ($actual1 - $expected1));
                $change2 = round($kFactor * ($actual2 - $expected2));

                $matchInfo = "Р{$round->round_number}: {$match->team1_score}:{$match->team2_score}";
                
                $ratingChanges[$p1_1]['matches'][] = "{$matchInfo} → {$change1}";
                $ratingChanges[$p1_2]['matches'][] = "{$matchInfo} → {$change1}";
                $ratingChanges[$p2_1]['matches'][] = "{$matchInfo} → {$change2}";
                $ratingChanges[$p2_2]['matches'][] = "{$matchInfo} → {$change2}";

                $ratingChanges[$p1_1]['current_rating'] = max(100, $ratingChanges[$p1_1]['current_rating'] + $change1);
                $ratingChanges[$p1_2]['current_rating'] = max(100, $ratingChanges[$p1_2]['current_rating'] + $change1);
                $ratingChanges[$p2_1]['current_rating'] = max(100, $ratingChanges[$p2_1]['current_rating'] + $change2);
                $ratingChanges[$p2_2]['current_rating'] = max(100, $ratingChanges[$p2_2]['current_rating'] + $change2);
            }
        }

        return $ratingChanges;
    }
	/**
	 * Можно ли сгенерировать следующий раунд
	 */

	public function canGenerateNextRound(Tournament $tournament): bool
	{
		$currentRound = $tournament->mexicanoRounds()->reorder('round_number', 'desc')->first();
		
		// Нет раундов — нельзя (турнир не начат)
		if (!$currentRound) {
			return false;
		}
		
		// Проверяем что ВСЕ матчи текущего раунда завершены
		$pendingMatches = $currentRound->matches()->where('status', 'pending')->count();
		if ($pendingMatches > 0) {
			return false;
		}
		
		// Все раунды сыграны — нельзя
		if ($currentRound->round_number >= $tournament->rounds_count) {
			return false;
		}
		
		return true;
	}
}
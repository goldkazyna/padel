<?php

namespace App\Traits;

/**
 * Единый trait для расчёта рейтинга в падел-турнирах (v4)
 */
trait RatingCalculator
{
    /**
     * Минимальный рейтинг (нельзя упасть ниже)
     */
    protected int $minRating = 1000;

    /**
     * Ожидаемый результат по новой матрице шансов
     */
    protected function expectedScore(float $ratingA, float $ratingB): float
    {
        $diff = abs($ratingA - $ratingB);
        
        $strongerWinChance = match(true) {
            $diff <= 100  => 0.50,
            $diff <= 250  => 0.60,
            $diff <= 500  => 0.70,
            $diff <= 750  => 0.80,
            $diff <= 1000 => 0.90,
            $diff <= 1250 => 0.95,
            $diff <= 1500 => 0.975,
            default       => 0.985,
        };
        
        return $ratingA >= $ratingB ? $strongerWinChance : (1 - $strongerWinChance);
    }

    /**
     * K-фактор по среднему рейтингу команды
     */
    protected function getKFactor(float $teamAvgRating): int
    {
        return match(true) {
            $teamAvgRating < 2000 => 48,
            $teamAvgRating < 2500 => 36,
            $teamAvgRating < 4000 => 24,
            default               => 36,
        };
    }

    /**
     * K-фактор для матча (максимальный из двух команд)
     */
    protected function getMatchKFactor(float $team1Avg, float $team2Avg): int
    {
        return max($this->getKFactor($team1Avg), $this->getKFactor($team2Avg));
    }

    /**
     * Множитель за разницу в счёте
     */
    protected function getScoreMultiplier(int $score1, int $score2): float
    {
        if ($score1 === $score2) {
            return 1.0;
        }
        
        $diff = abs($score1 - $score2);
        $sum = $score1 + $score2;
        
        return $sum > 0 ? 1 + ($diff / $sum) * 0.5 : 1.0;
    }

    /**
     * Рассчитать изменение рейтинга
     */
    protected function calculateRatingChange(
        float $team1Rating,
        float $team2Rating,
        int $score1,
        int $score2
    ): array {
        // Несыгранный матч 0:0 приравнивается к отменённому — рейтинг не меняется.
        // Действует для ВСЕХ форматов (все используют этот трейт).
        if ($score1 === 0 && $score2 === 0) {
            return ['change1' => 0, 'change2' => 0];
        }

        // Фактический результат
        if ($score1 > $score2) {
            $actual1 = 1.0;
            $actual2 = 0.0;
        } elseif ($score2 > $score1) {
            $actual1 = 0.0;
            $actual2 = 1.0;
        } else {
            $actual1 = 0.5;
            $actual2 = 0.5;
        }
        
        $expected1 = $this->expectedScore($team1Rating, $team2Rating);
        $expected2 = $this->expectedScore($team2Rating, $team1Rating);
        
        $kFactor = $this->getMatchKFactor($team1Rating, $team2Rating);
        $multiplier = $this->getScoreMultiplier($score1, $score2);
        
        $change1 = round($kFactor * $multiplier * ($actual1 - $expected1));
        $change2 = round($kFactor * $multiplier * ($actual2 - $expected2));
        
        // Бонус за победу минимум 1 очко
        if ($actual1 === 1.0 && $change1 < 1) $change1 = 1;
        if ($actual2 === 1.0 && $change2 < 1) $change2 = 1;
        
        return [
            'change1' => (int) $change1,
            'change2' => (int) $change2,
        ];
    }

    /**
     * Применить изменение с учётом минимума
     */
    protected function applyRatingChange(int $currentRating, int $change): int
    {
        return max($this->minRating, $currentRating + $change);
    }

    /**
     * Обновить уровень игрока (новая формула: rating / 1000)
     */
    protected function updateLevel($player): void
    {
        $rating = $player->rating;
        $level = floor($rating / 250) * 0.25;
        $level = max(1.0, min(5.75, $level));
        $player->update(['level' => $level]);
    }
}
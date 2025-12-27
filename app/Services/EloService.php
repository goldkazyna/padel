<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\User;

class EloService
{
    protected int $kFactor = 32;

    /**
     * Рассчитать и применить изменения рейтинга
     */
    public function calculateMatch(GameMatch $gameMatch, int $winnerId): array
    {
        $player1 = $gameMatch->player1;
        $player2 = $gameMatch->player2;

        $rating1 = $player1->rating;
        $rating2 = $player2->rating;

        // Ожидаемый результат
        $expected1 = $this->expectedScore($rating1, $rating2);
        $expected2 = $this->expectedScore($rating2, $rating1);

        // Фактический результат
        $score1 = $winnerId === $player1->id ? 1 : 0;
        $score2 = $winnerId === $player2->id ? 1 : 0;

        // Новые рейтинги
        $newRating1 = round($rating1 + $this->kFactor * ($score1 - $expected1));
        $newRating2 = round($rating2 + $this->kFactor * ($score2 - $expected2));

        // Не даём рейтингу упасть ниже 100
        $newRating1 = max(100, $newRating1);
        $newRating2 = max(100, $newRating2);

        // Изменение рейтинга
        $ratingChange = abs($newRating1 - $rating1);

        // Обновляем игроков
        $player1->update([
            'rating' => $newRating1,
            'last_played_at' => now(),
        ]);

        $player2->update([
            'rating' => $newRating2,
            'last_played_at' => now(),
        ]);

        // Обновляем уровни
        $this->updateLevel($player1);
        $this->updateLevel($player2);

        return [
            'player1_rating_before' => $rating1,
            'player2_rating_before' => $rating2,
            'player1_rating_after' => $newRating1,
            'player2_rating_after' => $newRating2,
            'rating_change' => $ratingChange,
        ];
    }

    /**
     * Ожидаемый результат по формуле Эло
     */
    protected function expectedScore(int $ratingA, int $ratingB): float
    {
        return 1 / (1 + pow(10, ($ratingB - $ratingA) / 400));
    }

    /**
     * Обновить уровень игрока на основе рейтинга
     */
    protected function updateLevel(User $user): void
    {
        $rating = $user->rating;
        
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

        $user->update(['level' => $level]);
    }

    /**
     * Пересчитать матч
     */
    public function recalculateMatch(GameMatch $gameMatch, int $newWinnerId): array
    {
        $gameMatch->player1->update(['rating' => $gameMatch->player1_rating_before]);
        $gameMatch->player2->update(['rating' => $gameMatch->player2_rating_before]);

        return $this->calculateMatch($gameMatch, $newWinnerId);
    }
}
<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\User;
use App\Traits\RatingCalculator;

class EloService
{
    use RatingCalculator;

    /**
     * Рассчитать и применить изменения рейтинга
     */
    public function calculateMatch(GameMatch $gameMatch, int $winnerId): array
    {
        $player1 = $gameMatch->player1;
        $player2 = $gameMatch->player2;

        $rating1 = $player1->rating;
        $rating2 = $player2->rating;

        // Ожидаемый результат (новая матрица)
        $expected1 = $this->expectedScore($rating1, $rating2);
        $expected2 = $this->expectedScore($rating2, $rating1);

        // Фактический результат
        $score1 = $winnerId === $player1->id ? 1 : 0;
        $score2 = $winnerId === $player2->id ? 1 : 0;

        // K-фактор (максимальный из двух игроков)
        $kFactor = $this->getMatchKFactor($rating1, $rating2);

        // Рассчитываем изменения (без множителя за счёт — матч 1v1)
        $change1 = round($kFactor * ($score1 - $expected1));
        $change2 = round($kFactor * ($score2 - $expected2));

        // Бонус за победу минимум 1 очко
        if ($score1 === 1 && $change1 < 1) $change1 = 1;
        if ($score2 === 1 && $change2 < 1) $change2 = 1;

        // Новые рейтинги (минимум 1000)
        $newRating1 = $this->applyRatingChange($rating1, $change1);
        $newRating2 = $this->applyRatingChange($rating2, $change2);

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

        // Обновляем уровни (новая формула)
        $this->updateLevel($player1->fresh());
        $this->updateLevel($player2->fresh());

        return [
            'player1_rating_before' => $rating1,
            'player2_rating_before' => $rating2,
            'player1_rating_after' => $newRating1,
            'player2_rating_after' => $newRating2,
            'rating_change' => $ratingChange,
        ];
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
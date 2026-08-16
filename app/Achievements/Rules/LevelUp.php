<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class LevelUp implements Achievement
{
    public function code(): string { return 'level_up'; }
    public function title(): string { return 'Новый уровень'; }
    public function description(): string { return 'Подняться на уровень выше'; }
    public function icon(): string { return 'star'; }
    public function group(): string { return 'rating'; }
    public function target(): int { return 1; }

    public function progress(PlayerHistory $history): int
    {
        $first = $history->ratingEntries->first();
        if (!$first) {
            return 0;
        }

        $startLevel = $this->levelOf((int) $first->rating_before);
        foreach ($history->ratingEntries as $entry) {
            if ($this->levelOf((int) $entry->rating_after) > $startLevel) {
                return 1;
            }
        }

        return 0;
    }

    /** Тот же расчёт, что в RatingCalculator::updateLevel(). */
    private function levelOf(int $rating): float
    {
        return max(1.0, min(5.75, floor($rating / 250) * 0.25));
    }
}

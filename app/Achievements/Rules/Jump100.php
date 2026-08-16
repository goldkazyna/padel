<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class Jump100 implements Achievement
{
    public function code(): string { return 'jump_100'; }
    public function title(): string { return 'Рывок'; }
    public function description(): string { return 'Набрать +100 рейтинга за один турнир'; }
    public function icon(): string { return 'trending_up'; }
    public function group(): string { return 'rating'; }
    public function tier(): string { return 'bronze'; }
    public function target(): int { return 1; }

    public function progress(PlayerHistory $history): int
    {
        foreach ($history->ratingEntries as $entry) {
            if ((int) $entry->change >= 100) {
                return 1;
            }
        }

        return 0;
    }
}

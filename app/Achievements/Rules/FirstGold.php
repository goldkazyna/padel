<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class FirstGold implements Achievement
{
    public function code(): string { return 'first_gold'; }
    public function title(): string { return 'Первое золото'; }
    public function description(): string { return 'Выиграть турнир'; }
    public function icon(): string { return 'military_tech'; }
    public function group(): string { return 'wins'; }
    public function tier(): string { return 'silver'; }
    public function target(): int { return 1; }

    public function progress(PlayerHistory $history): int
    {
        return min($this->target(), (int) ($history->tournamentStats['wins'] ?? 0));
    }
}

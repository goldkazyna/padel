<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class Gold3 implements Achievement
{
    public function code(): string { return 'gold_3'; }
    public function title(): string { return 'Трижды первый'; }
    public function description(): string { return 'Выиграть три турнира'; }
    public function icon(): string { return 'military_tech'; }
    public function group(): string { return 'wins'; }
    public function target(): int { return 3; }

    public function progress(PlayerHistory $history): int
    {
        return min($this->target(), (int) ($history->tournamentStats['wins'] ?? 0));
    }
}

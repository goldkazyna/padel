<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class Regular5 implements Achievement
{
    public function code(): string { return 'regular_5'; }
    public function title(): string { return 'Пятёрка'; }
    public function description(): string { return 'Сыграть 5 турниров'; }
    public function icon(): string { return 'calendar_month'; }
    public function group(): string { return 'first_steps'; }
    public function tier(): string { return 'bronze'; }
    public function target(): int { return 5; }

    public function progress(PlayerHistory $history): int
    {
        return min($this->target(), (int) ($history->tournamentStats['total'] ?? 0));
    }
}

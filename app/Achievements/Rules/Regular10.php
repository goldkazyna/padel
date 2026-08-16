<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class Regular10 implements Achievement
{
    public function code(): string { return 'regular_10'; }
    public function title(): string { return 'Постоянный'; }
    public function description(): string { return 'Сыграть 10 турниров'; }
    public function icon(): string { return 'calendar_month'; }
    public function group(): string { return 'first_steps'; }
    public function tier(): string { return 'silver'; }
    public function target(): int { return 10; }

    public function progress(PlayerHistory $history): int
    {
        return min($this->target(), (int) ($history->tournamentStats['total'] ?? 0));
    }
}

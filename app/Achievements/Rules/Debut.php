<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class Debut implements Achievement
{
    public function code(): string { return 'debut'; }
    public function title(): string { return 'Дебют'; }
    public function description(): string { return 'Сыграть первый турнир'; }
    public function icon(): string { return 'flag'; }
    public function group(): string { return 'first_steps'; }
    public function target(): int { return 1; }

    public function progress(PlayerHistory $history): int
    {
        return min($this->target(), (int) ($history->tournamentStats['total'] ?? 0));
    }
}

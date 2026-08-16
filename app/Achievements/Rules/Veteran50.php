<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class Veteran50 implements Achievement
{
    public function code(): string { return 'veteran_50'; }
    public function title(): string { return 'Ветеран'; }
    public function description(): string { return 'Сыграть 50 турниров'; }
    public function icon(): string { return 'workspace_premium'; }
    public function group(): string { return 'first_steps'; }
    public function target(): int { return 50; }

    public function progress(PlayerHistory $history): int
    {
        return min($this->target(), (int) ($history->tournamentStats['total'] ?? 0));
    }
}

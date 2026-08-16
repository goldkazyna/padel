<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class FirstWin implements Achievement
{
    public function code(): string { return 'first_win'; }
    public function title(): string { return 'Первая победа'; }
    public function description(): string { return 'Выиграть первый матч'; }
    public function icon(): string { return 'emoji_events'; }
    public function group(): string { return 'first_steps'; }
    public function tier(): string { return 'bronze'; }
    public function target(): int { return 1; }

    public function progress(PlayerHistory $history): int
    {
        foreach ($history->matches as $match) {
            if ($match['result'] === 'win') {
                return 1;
            }
        }

        return 0;
    }
}

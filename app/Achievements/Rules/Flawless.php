<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class Flawless implements Achievement
{
    public function code(): string { return 'flawless'; }
    public function title(): string { return 'Без потерь'; }
    public function description(): string { return 'Выиграть все свои матчи одного турнира'; }
    public function icon(): string { return 'shield'; }
    public function group(): string { return 'wins'; }
    public function target(): int { return 1; }

    public function progress(PlayerHistory $history): int
    {
        foreach ($history->matchesByTournament() as $matches) {
            if ($matches === []) {
                continue;
            }

            $allWon = true;
            foreach ($matches as $match) {
                if ($match['result'] !== 'win') {
                    $allWon = false;
                    break;
                }
            }

            if ($allWon) {
                return 1;
            }
        }

        return 0;
    }
}

<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class Streak5 implements Achievement
{
    public function code(): string { return 'streak_5'; }
    public function title(): string { return 'Серия'; }
    public function description(): string { return 'Пять побед подряд'; }
    public function icon(): string { return 'bolt'; }
    public function group(): string { return 'wins'; }
    public function target(): int { return 5; }

    public function progress(PlayerHistory $history): int
    {
        $best = 0;
        $current = 0;

        // Матчи в снимке отсортированы по дате, поэтому серия считается одним
        // проходом. Ничья прерывает серию так же, как поражение.
        foreach ($history->matches as $match) {
            if ($match['result'] === 'win') {
                $current++;
                $best = max($best, $current);
            } else {
                $current = 0;
            }
        }

        return min($this->target(), $best);
    }
}

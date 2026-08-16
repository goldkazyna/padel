<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class Clubs3 implements Achievement
{
    public function code(): string { return 'clubs_3'; }
    public function title(): string { return 'Тур по клубам'; }
    public function description(): string { return 'Сыграть турниры в трёх клубах'; }
    public function icon(): string { return 'location_city'; }
    public function group(): string { return 'variety'; }
    public function target(): int { return 3; }

    public function progress(PlayerHistory $history): int
    {
        $clubs = [];
        foreach ($history->matches as $match) {
            if (!empty($match['club_id'])) {
                $clubs[$match['club_id']] = true;
            }
        }

        return min($this->target(), count($clubs));
    }
}

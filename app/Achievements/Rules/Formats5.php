<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

/**
 * Форматы считаются по типу турнира, а не по полю format из матча:
 * там лежат стадии, и классический турнир превратился бы в два формата.
 */
class Formats5 implements Achievement
{
    public function code(): string { return 'formats_5'; }
    public function title(): string { return 'Многоборец'; }
    public function description(): string { return 'Сыграть пять разных форматов'; }
    public function icon(): string { return 'explore'; }
    public function group(): string { return 'variety'; }
    public function tier(): string { return 'bronze'; }
    public function target(): int { return 5; }

    public function progress(PlayerHistory $history): int
    {
        $types = [];
        foreach ($history->matches as $match) {
            if (!empty($match['tournament_type'])) {
                $types[$match['tournament_type']] = true;
            }
        }

        return min($this->target(), count($types));
    }
}

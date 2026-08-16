<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

/**
 * Форматы считаются по типу турнира, а не по полю format из матча:
 * там лежат стадии, и классический турнир превратился бы в два формата.
 */
class FormatsAll implements Achievement
{
    public function code(): string { return 'formats_all'; }
    public function title(): string { return 'Знаток форматов'; }
    public function description(): string { return 'Сыграть все десять форматов'; }
    public function icon(): string { return 'auto_awesome'; }
    public function group(): string { return 'variety'; }
    public function target(): int { return 10; }

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

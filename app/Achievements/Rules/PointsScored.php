<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

/**
 * Значки за забитые мячи.
 *
 * Пороги взяты из живого распределения (1359 игроков с сыгранными матчами):
 * 500 мячей есть у 29% игроков, 1700 — у 10%, 5000 — у 1%. Так бронза,
 * серебро и золото означают одно и то же во всех значках: насколько это редко.
 */
abstract class PointsScored implements Achievement
{
    public function icon(): string { return 'sports_tennis'; }
    public function group(): string { return 'scoring'; }

    public function description(): string
    {
        return "Забить {$this->target()} мячей";
    }

    public function progress(PlayerHistory $history): int
    {
        $total = 0;
        foreach ($history->matches as $match) {
            $total += (int) ($match['points_for'] ?? 0);
        }

        return min($this->target(), $total);
    }
}

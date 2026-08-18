<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class Jump100 implements Achievement
{
    public function code(): string { return 'jump_100'; }
    public function title(): string { return 'Рывок'; }
    public function description(): string { return 'Набрать +100 рейтинга за один турнир'; }
    public function icon(): string { return 'trending_up'; }
    public function group(): string { return 'rating'; }
    public function tier(): string { return 'bronze'; }
    public function target(): int { return 1; }

    public function progress(PlayerHistory $history): int
    {
        foreach ($history->ratingEntries as $entry) {
            // Только турниры: ручная правка рейтинга администратором лежит
            // в той же истории и легко перешагивает сотню — за неё значок
            // выдавать нельзя. Поединки и игры отсекаются заодно, значок
            // и обещает «за один турнир».
            if ($entry->tournament_id === null) {
                continue;
            }
            if ((int) $entry->change >= 100) {
                return 1;
            }
        }

        return 0;
    }
}

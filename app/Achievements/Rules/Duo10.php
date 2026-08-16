<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class Duo10 implements Achievement
{
    public function code(): string { return 'duo_10'; }
    public function title(): string { return 'Сыгранный дуэт'; }
    public function description(): string { return 'Сыграть 10 матчей с одним партнёром'; }
    public function icon(): string { return 'handshake'; }
    public function group(): string { return 'together'; }
    public function tier(): string { return 'silver'; }
    public function target(): int { return 10; }

    public function progress(PlayerHistory $history): int
    {
        $byPartner = [];
        foreach ($history->matches as $match) {
            $id = $match['partner']['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $byPartner[$id] = ($byPartner[$id] ?? 0) + 1;
        }

        return min($this->target(), $byPartner === [] ? 0 : max($byPartner));
    }
}

<?php

namespace App\Achievements\Rules;

class Points5000 extends PointsScored
{
    public function code(): string { return 'points_5000'; }
    public function title(): string { return 'Снайпер'; }
    public function tier(): string { return 'gold'; }
    public function target(): int { return 5000; }
}

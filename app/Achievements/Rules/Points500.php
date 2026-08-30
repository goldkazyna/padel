<?php

namespace App\Achievements\Rules;

class Points500 extends PointsScored
{
    public function code(): string { return 'points_500'; }
    public function title(): string { return 'Меткий'; }
    public function tier(): string { return 'bronze'; }
    public function target(): int { return 500; }
}

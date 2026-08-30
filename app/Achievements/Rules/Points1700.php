<?php

namespace App\Achievements\Rules;

class Points1700 extends PointsScored
{
    public function code(): string { return 'points_1700'; }
    public function title(): string { return 'Бомбардир'; }
    public function tier(): string { return 'silver'; }
    public function target(): int { return 1700; }
}

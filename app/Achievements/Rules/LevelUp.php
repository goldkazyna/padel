<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

class LevelUp implements Achievement
{
    public function code(): string { return 'level_up'; }
    public function title(): string { return 'Новый уровень'; }
    public function description(): string { return 'Подняться на уровень выше'; }
    public function icon(): string { return 'star'; }
    public function group(): string { return 'rating'; }
    public function tier(): string { return 'silver'; }
    public function target(): int { return 1; }

    public function progress(PlayerHistory $history): int
    {
        // Ручные правки рейтинга администратором и списания за простой в
        // счёт не идут: значок «поднял уровень» — за игру, а не за то, что
        // рейтинг подвинули со стороны.
        $skip = [
            \App\Models\RatingHistory::REASON_MANUAL,
            \App\Models\RatingHistory::REASON_DECAY,
        ];
        $play = $history->ratingEntries
            ->reject(fn ($entry) => in_array($entry->reason, $skip, true))
            ->values();

        $first = $play->first();
        if (!$first) {
            return 0;
        }

        // Считаем рейтинг заново, прибавляя только игровые изменения: иначе
        // ручная надбавка посреди истории всё равно протащит игрока наверх.
        $rating = (int) $first->rating_before;
        $startLevel = $this->levelOf($rating);

        foreach ($play as $entry) {
            $rating += (int) $entry->change;
            if ($this->levelOf($rating) > $startLevel) {
                return 1;
            }
        }

        return 0;
    }

    /** Тот же расчёт, что в RatingCalculator::updateLevel(). */
    private function levelOf(int $rating): float
    {
        return max(1.0, min(5.75, floor($rating / 250) * 0.25));
    }
}

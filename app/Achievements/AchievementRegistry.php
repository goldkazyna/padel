<?php

namespace App\Achievements;

use App\Achievements\Rules;

/**
 * Все значки в порядке показа: первые шаги, победы, рейтинг, кругозор, вместе.
 * Добавить значок — добавить класс и строку сюда.
 */
class AchievementRegistry
{
    /** @return array<int, Achievement> */
    public function all(): array
    {
        return [
            new Rules\FirstWin(),
            new Rules\Debut(),
            new Rules\Regular5(),
            new Rules\Regular10(),
            new Rules\Veteran50(),
            new Rules\FirstGold(),
            new Rules\Gold3(),
            new Rules\Streak5(),
            new Rules\Flawless(),
            new Rules\Jump100(),
            new Rules\LevelUp(),
            new Rules\Formats5(),
            new Rules\FormatsAll(),
            new Rules\Clubs3(),
            new Rules\Duo10(),
        ];
    }

    public function byCode(string $code): ?Achievement
    {
        foreach ($this->all() as $rule) {
            if ($rule->code() === $code) {
                return $rule;
            }
        }

        return null;
    }
}

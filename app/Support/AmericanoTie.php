<?php

namespace App\Support;

/**
 * Тай-брейк по личной встрече (head-to-head) для турниров «Американо».
 *
 * «Встреча» = матч, где два игрока были в РАЗНЫХ командах. Победа команды в
 * матче = личная победа каждого её игрока над каждым соперником из команды
 * напротив. $h2h[A][B] — нетто личных побед A над B (ничьи в матче не считаются).
 *
 * Применяется как ПОСЛЕДНИЙ критерий — когда очки, победы и разница мячей равны.
 * Если по личным встречам тоже равенство (например 1-1) — возвращает 0 (порядок
 * не меняется).
 */
class AmericanoTie
{
    /** Построить карту личных встреч из плоского списка матчей. */
    public static function fromMatches(iterable $matches): array
    {
        $h2h = [];

        foreach ($matches as $m) {
            if (($m->status ?? '') !== 'completed') continue;
            if ((int) $m->team1_score === (int) $m->team2_score) continue;

            $t1 = [$m->team1_player1_id, $m->team1_player2_id];
            $t2 = [$m->team2_player1_id, $m->team2_player2_id];

            $winners = $m->team1_score > $m->team2_score ? $t1 : $t2;
            $losers  = $m->team1_score > $m->team2_score ? $t2 : $t1;

            foreach ($winners as $w) {
                foreach ($losers as $l) {
                    if ($w === null || $l === null) continue;
                    $h2h[$w][$l] = ($h2h[$w][$l] ?? 0) + 1;
                    $h2h[$l][$w] = ($h2h[$l][$w] ?? 0) - 1;
                }
            }
        }

        return $h2h;
    }

    /** Построить карту из коллекции групп (каждая с rounds.matches). */
    public static function fromGroups(iterable $groups): array
    {
        $matches = [];
        foreach ($groups as $group) {
            foreach ($group->rounds as $round) {
                foreach ($round->matches as $match) {
                    $matches[] = $match;
                }
            }
        }

        return self::fromMatches($matches);
    }

    /**
     * Сравнение двух игроков по личной встрече.
     * -1 если A выше (больше личных побед), 1 если ниже, 0 при равенстве.
     */
    public static function compare(array $h2h, $aId, $bId): int
    {
        $net = $h2h[$aId][$bId] ?? 0;

        return $net > 0 ? -1 : ($net < 0 ? 1 : 0);
    }
}

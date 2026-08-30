<?php

namespace App\Support;

use App\Models\User;
use App\Services\PlayerMatchHistory;

/**
 * С кем игрок выходит на корт: партнёры и как с ними складывается.
 *
 * Считается по тем же матчам, что история и значки, — по id партнёра, а не
 * по имени: тёзки склеивались бы в одного человека, и нажать на такую строку
 * было нельзя.
 */
class PlayerPartners
{
    /** Меньше трёх матчей — это ещё не «лучший партнёр», а случайность. */
    public const MIN_GAMES = 3;

    /**
     * Партнёры игрока: сколько сыграно вместе и сколько выиграно.
     *
     * @return array<int, array<string, mixed>> отсортированы: сначала лучшие
     */
    public static function all(User $user): array
    {
        $byId = [];

        foreach (app(PlayerMatchHistory::class)->for($user) as $match) {
            $partner = $match['partner'] ?? null;
            $id = $partner['id'] ?? null;
            if (!$id) {
                continue;
            }

            $byId[$id] ??= [
                'user_id' => (int) $id,
                'name' => $partner['name'] ?? 'Игрок',
                'avatar' => $partner['avatar'] ?? null,
                'games' => 0,
                'wins' => 0,
                'losses' => 0,
                'draws' => 0,
            ];

            $byId[$id]['games']++;
            match ($match['result'] ?? '') {
                'win' => $byId[$id]['wins']++,
                'loss' => $byId[$id]['losses']++,
                default => $byId[$id]['draws']++,
            };
        }

        $rows = array_map(function (array $row) {
            // Ничьи в знаменатель не идут — как и везде в статистике игрока.
            $row['winrate'] = CountedMatches::winrate($row['wins'], $row['losses']);

            return $row;
        }, array_values($byId));

        usort($rows, function (array $a, array $b) {
            // Сначала те, с кем чаще выигрываешь; при равном проценте —
            // с кем сыграно больше: один общий матч не делает лучшим партнёром.
            $ready = fn (array $r) => $r['games'] >= self::MIN_GAMES ? 1 : 0;

            return [$ready($b), $b['winrate'], $b['games']]
                <=> [$ready($a), $a['winrate'], $a['games']];
        });

        return $rows;
    }

    /**
     * Лучший партнёр — верхняя строка. null, если играл только один или
     * всегда в одиночных форматах без постоянного партнёра.
     *
     * @return array<string, mixed>|null
     */
    public static function best(User $user): ?array
    {
        return self::all($user)[0] ?? null;
    }
}

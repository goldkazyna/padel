<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Какие матчи идут в статистику игрока.
 *
 * Раньше в подсчёт попадало всё, у чего статус матча «completed», — включая
 * матчи отменённых и ещё идущих турниров. Отменённый турнир как будто не
 * состоялся, но его результаты продолжали висеть в винрейте: у игроков с
 * небольшим налётом это меняло цифру на десятки процентов.
 *
 * Здесь же живёт формула винрейта: победы к сыгранным решающим матчам.
 * Ничьи в знаменатель не идут — иначе вечер с несколькими ничьими выглядит
 * как вечер с поражениями.
 */
class CountedMatches
{
    /** Матчи считаем только у завершённых турниров. */
    public const TOURNAMENT_STATUSES = ['completed'];

    /**
     * Путь до турнира от матча — у каждого формата свой.
     * Ключ совпадает с именем модели матча.
     */
    public const PATHS = [
        'AmericanoMatch' => 'round.group.tournament',
        'MexicanoMatch' => 'round.tournament',
        'KingOfCourtMatch' => 'round.tournament',
        'JustPadelItMatch' => 'round.tournament',
        'AmericanoFlexMatch' => 'round.tournament',
        'RoundRobinMatch' => 'round.tournament',
        'BaliKocMatch' => 'round.tournament',
        'EscaleraMatch' => 'court.round.tournament',
        'TournamentGroupMatch' => 'group.tournament',
    ];

    /**
     * Ограничить выборку матчами завершённых турниров.
     *
     * @param  string $relation путь до турнира, например 'round.tournament'
     */
    public static function onlyFinished(Builder $query, string $relation): Builder
    {
        return $query->whereHas(
            $relation,
            fn ($q) => $q->whereIn('status', self::TOURNAMENT_STATUSES)
        );
    }

    /** Путь до турнира по классу модели матча. */
    public static function pathFor(string $modelClass): string
    {
        $short = class_basename($modelClass);

        return self::PATHS[$short] ?? 'tournament';
    }

    /**
     * Винрейт в процентах: победы к решающим матчам.
     * Ничьи не считаются ни победой, ни поражением и в знаменатель не входят.
     */
    public static function winrate(int $won, int $lost): int
    {
        $decided = $won + $lost;

        return $decided > 0 ? (int) round($won / $decided * 100) : 0;
    }

    /** То же, но с десятыми — для веба, где место позволяет. */
    public static function winrateExact(int $won, int $lost): float
    {
        $decided = $won + $lost;

        return $decided > 0 ? round($won / $decided * 100, 1) : 0.0;
    }
}

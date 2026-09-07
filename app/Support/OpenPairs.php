<?php

namespace App\Support;

use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;

/**
 * Открытые пары в парном Americano Flex.
 *
 * Человек записывается один и сразу становится половиной пары — рядом с ним
 * пустое место, к которому подсаживается следующий. Пока свободных мест в
 * парах нет, а новую пару создавать уже некуда, запись уходит в лист
 * ожидания: оттуда организатор доставит игрока руками.
 *
 * Раньше все записывались в общий список, а пары клуб собирал вручную перед
 * стартом — люди до последнего не знали, с кем играют.
 */
class OpenPairs
{
    /** Сколько пар помещается в турнир. */
    public static function maxPairs(Tournament $tournament): int
    {
        return (int) floor(((int) $tournament->max_participants) / 2);
    }

    /** Пары, где есть свободное место. */
    public static function openTeams(Tournament $tournament)
    {
        return $tournament->teams()
            ->whereNull('player2_id')
            ->whereIn('status', ['approved', 'pending'])
            ->get();
    }

    /** Можно ли создать ещё одну пару. */
    public static function canCreatePair(Tournament $tournament): bool
    {
        $teams = $tournament->teams()
            ->whereIn('status', ['approved', 'pending'])
            ->count();

        return $teams < self::maxPairs($tournament);
    }

    /**
     * Пара игрока в этом турнире (в любом статусе) или null.
     */
    public static function teamOf(Tournament $tournament, int $userId): ?TournamentTeam
    {
        return $tournament->teams()
            ->where(fn ($q) => $q->where('player1_id', $userId)->orWhere('player2_id', $userId))
            ->first();
    }

    /**
     * Создать пару из одного игрока (второе место свободно).
     */
    public static function createOpen(Tournament $tournament, User $user, string $status): TournamentTeam
    {
        return TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $user->id,
            'player2_id' => null,
            'status' => $status,
            'rating_avg' => (int) $user->rating,
        ]);
    }

    /**
     * Посадить игрока на свободное место в паре.
     */
    public static function join(TournamentTeam $team, User $user): void
    {
        $team->update([
            'player2_id' => $user->id,
            'rating_avg' => (int) round(((int) $team->player1->rating + (int) $user->rating) / 2),
        ]);
    }

    /**
     * Убрать игрока из его пары.
     *
     * Ушёл первый — второй занимает его место и пара снова открыта. Ушёл
     * последний — пары нет вовсе.
     */
    public static function leave(Tournament $tournament, int $userId): void
    {
        $team = self::teamOf($tournament, $userId);
        if (!$team) {
            return;
        }

        $isFirst = (int) $team->player1_id === $userId;
        $partnerId = $isFirst ? $team->player2_id : $team->player1_id;

        if (!$partnerId) {
            $team->delete();

            return;
        }

        $partner = User::find($partnerId);
        $team->update([
            'player1_id' => $partnerId,
            'player2_id' => null,
            'rating_avg' => (int) ($partner?->rating ?? $team->rating_avg),
        ]);
    }
}

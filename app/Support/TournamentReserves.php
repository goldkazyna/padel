<?php

namespace App\Support;

use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;

/**
 * Забронированные места в турнире.
 *
 * Организатор придерживает N мест под знакомых, которых заменит позже: места
 * занимают служебные аккаунты «Резерв 1…10» (роль `reserve`). Число хранится
 * в `tournaments.reserve_count`, но само по себе оно ничего не значит — места
 * занимают именно посаженные участники.
 *
 * Общее место для всех путей: веб создаёт и правит турнир своей формой,
 * приложение — своими ручками, и раньше каждый сажал резерв по-своему.
 * Из-за этого в приложении резерв появлялся при создании, а при
 * редактировании число менялось молча: мест не прибавлялось.
 */
class TournamentReserves
{
    /**
     * Привести число забронированных мест к $target.
     *
     * Лишние снимаются, недостающие досаживаются. Больше, чем есть служебных
     * аккаунтов, посадить нельзя — столько мест и займётся.
     */
    public static function sync(Tournament $tournament, int $target): void
    {
        $target = max(0, $target);

        if ($tournament->isTeamBased()) {
            self::syncTeams($tournament, $target);
            return;
        }

        $current = $tournament->participants()
            ->where('users.role', 'reserve')
            ->orderBy('users.id')
            ->get();

        if ($current->count() > $target) {
            // Снимаем последних посаженных — раньше сел, дольше сидит.
            $tournament->participants()->detach(
                $current->slice($target)->pluck('id')->all()
            );
            return;
        }

        $needed = $target - $current->count();
        if ($needed <= 0) {
            return;
        }

        $free = User::where('role', 'reserve')
            ->whereNotIn('id', $current->pluck('id')->all())
            ->orderBy('id')
            ->take($needed)
            ->get();

        foreach ($free as $reserve) {
            $tournament->participants()->attach($reserve->id, ['status' => 'registered']);
        }
    }

    /**
     * То же для командных турниров: там место занимает пара резервистов,
     * поэтому целевое число — это число пар, а не игроков.
     */
    private static function syncTeams(Tournament $tournament, int $target): void
    {
        $reserveTeams = $tournament->teams()
            ->with(['player1', 'player2'])
            ->orderBy('id')
            ->get()
            ->filter(fn ($team) => $team->player1?->role === 'reserve'
                && $team->player2?->role === 'reserve')
            ->values();

        if ($reserveTeams->count() > $target) {
            $tournament->teams()
                ->whereIn('id', $reserveTeams->slice($target)->pluck('id')->all())
                ->delete();
            return;
        }

        $needed = $target - $reserveTeams->count();
        if ($needed <= 0) {
            return;
        }

        $busy = $reserveTeams
            ->flatMap(fn ($team) => [$team->player1_id, $team->player2_id])
            ->all();

        $free = User::where('role', 'reserve')
            ->whereNotIn('id', $busy)
            ->orderBy('id')
            ->take($needed * 2)
            ->get();

        for ($i = 0; $i + 1 < $free->count(); $i += 2) {
            TournamentTeam::create([
                'tournament_id' => $tournament->id,
                'player1_id' => $free[$i]->id,
                'player2_id' => $free[$i + 1]->id,
                'status' => 'approved',
            ]);
        }
    }
}

<?php

namespace App\Support;

use App\Models\Challenge;
use App\Models\Game;
use App\Models\Tournament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Когда игрок последний раз играл.
 *
 * Считаем по участию, а не по матчам: девять таблиц матчей на каждого из
 * трёх тысяч игроков — это ночь работы, а результат тот же. Играл — значит
 * был в составе завершённого турнира, доигранной игры или поединка.
 */
class PlayerActivity
{
    /**
     * Дата последней игры одного человека или null, если не играл ни разу.
     */
    public static function lastPlayedAt(int $userId): ?Carbon
    {
        $dates = array_filter([
            self::lastTournament($userId),
            self::lastTeamTournament($userId),
            self::lastGame($userId),
            self::lastChallenge($userId),
        ]);

        if (empty($dates)) {
            return null;
        }

        return collect($dates)->map(fn ($d) => Carbon::parse($d))->max();
    }

    /**
     * То же для всех сразу: [user_id => Carbon]. Четыре запроса на всю базу
     * вместо четырёх на каждого.
     *
     * @return array<int, Carbon>
     */
    public static function lastPlayedMap(): array
    {
        $out = [];

        $merge = function ($rows) use (&$out) {
            foreach ($rows as $row) {
                $userId = (int) $row->user_id;
                $date = Carbon::parse($row->played_at);
                if (!isset($out[$userId]) || $date->gt($out[$userId])) {
                    $out[$userId] = $date;
                }
            }
        };

        $merge(DB::table('tournament_participants as tp')
            ->join('tournaments as t', 't.id', '=', 'tp.tournament_id')
            ->where('t.status', 'completed')
            ->groupBy('tp.user_id')
            ->select('tp.user_id', DB::raw('MAX(t.start_date) as played_at'))
            ->get());

        foreach (['player1_id', 'player2_id'] as $column) {
            $merge(DB::table('tournament_teams as tt')
                ->join('tournaments as t', 't.id', '=', 'tt.tournament_id')
                ->where('t.status', 'completed')
                ->whereNotNull("tt.$column")
                ->groupBy("tt.$column")
                ->select("tt.$column as user_id", DB::raw('MAX(t.start_date) as played_at'))
                ->get());
        }

        $merge(DB::table('game_players as gp')
            ->join('games as g', 'g.id', '=', 'gp.game_id')
            ->where('g.status', Game::STATUS_FINISHED)
            ->groupBy('gp.user_id')
            ->select('gp.user_id', DB::raw('MAX(g.starts_at) as played_at'))
            ->get());

        $merge(DB::table('challenge_players as cp')
            ->join('challenges as c', 'c.id', '=', 'cp.challenge_id')
            ->where('c.status', 'completed')
            ->groupBy('cp.user_id')
            ->select('cp.user_id', DB::raw('MAX(c.scheduled_at) as played_at'))
            ->get());

        return $out;
    }

    private static function lastTournament(int $userId): ?string
    {
        return Tournament::where('status', 'completed')
            ->whereHas('participants', fn ($q) => $q->where('users.id', $userId))
            ->max('start_date');
    }

    private static function lastTeamTournament(int $userId): ?string
    {
        return Tournament::where('status', 'completed')
            ->whereHas('teams', fn ($q) => $q
                ->where('player1_id', $userId)
                ->orWhere('player2_id', $userId))
            ->max('start_date');
    }

    private static function lastGame(int $userId): ?string
    {
        return Game::where('status', Game::STATUS_FINISHED)
            ->whereHas('players', fn ($q) => $q->where('user_id', $userId))
            ->max('starts_at');
    }

    private static function lastChallenge(int $userId): ?string
    {
        return Challenge::where('status', 'completed')
            ->whereHas('players', fn ($q) => $q->where('user_id', $userId))
            ->max('scheduled_at');
    }
}

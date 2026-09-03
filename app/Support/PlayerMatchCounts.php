<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Сколько матчей сыграл каждый игрок — во всех форматах сразу.
 *
 * Матч считается, если он сам завершён и завершён турнир: недоигранные
 * турниры статистику бы раздували. Форматы хранят игроков по-разному —
 * кто-то четырьмя колонками игроков, кто-то парой, кто-то командой, —
 * поэтому источники перечислены здесь явно и в одном месте.
 *
 * Считаем разом по всем игрокам: страница списка показывает двадцать
 * человек, но фильтр «только игравшие» работает по всей базе.
 */
class PlayerMatchCounts
{
    /** Матчи, где игроки лежат четырьмя колонками. */
    private const PLAYER_TABLES = [
        // таблица => как добраться до турнира
        'americano_matches' => [
            ['americano_rounds', 'americano_matches.americano_round_id', 'americano_rounds.id'],
            ['tournament_groups', 'americano_rounds.tournament_group_id', 'tournament_groups.id'],
            ['tournaments', 'tournament_groups.tournament_id', 'tournaments.id'],
        ],
        'mexicano_matches' => [
            ['mexicano_rounds', 'mexicano_matches.mexicano_round_id', 'mexicano_rounds.id'],
            ['tournaments', 'mexicano_rounds.tournament_id', 'tournaments.id'],
        ],
        'americano_flex_matches' => [
            ['americano_flex_rounds', 'americano_flex_matches.americano_flex_round_id', 'americano_flex_rounds.id'],
            ['tournaments', 'americano_flex_rounds.tournament_id', 'tournaments.id'],
        ],
        'round_robin_matches' => [
            ['round_robin_rounds', 'round_robin_matches.round_robin_round_id', 'round_robin_rounds.id'],
            ['tournaments', 'round_robin_rounds.tournament_id', 'tournaments.id'],
        ],
        'kingofcourt_matches' => [
            ['kingofcourt_rounds', 'kingofcourt_matches.kingofcourt_round_id', 'kingofcourt_rounds.id'],
            ['tournaments', 'kingofcourt_rounds.tournament_id', 'tournaments.id'],
        ],
        'just_padel_it_matches' => [
            ['just_padel_it_rounds', 'just_padel_it_matches.just_padel_it_round_id', 'just_padel_it_rounds.id'],
            ['tournaments', 'just_padel_it_rounds.tournament_id', 'tournaments.id'],
        ],
        'escalera_matches' => [
            ['escalera_round_courts', 'escalera_matches.escalera_round_court_id', 'escalera_round_courts.id'],
            ['escalera_rounds', 'escalera_round_courts.escalera_round_id', 'escalera_rounds.id'],
            ['tournaments', 'escalera_rounds.tournament_id', 'tournaments.id'],
        ],
    ];

    /**
     * @return array<int, int> [user_id => сыграно матчей]
     */
    public static function all(): array
    {
        $counts = [];

        foreach (self::PLAYER_TABLES as $table => $joins) {
            $query = DB::table($table)->where($table . '.status', 'completed');
            foreach ($joins as [$join, $left, $right]) {
                $query->join($join, $left, '=', $right);
            }

            $rows = $query->where('tournaments.status', 'completed')->get([
                $table . '.team1_player1_id as a',
                $table . '.team1_player2_id as b',
                $table . '.team2_player1_id as c',
                $table . '.team2_player2_id as d',
            ]);

            self::add($counts, $rows);
        }

        self::addPlayoff($counts);
        self::addTeams($counts);
        self::addBali($counts);

        return $counts;
    }

    /**
     * То же, но с коротким кешем: на странице списка счётчик нужен и для
     * колонки, и для фильтра, а данные меняются только после турнира.
     *
     * @return array<int, int>
     */
    public static function cached(int $seconds = 300): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'player_match_counts', $seconds, fn () => self::all()
        );
    }

    /** Id тех, кто сыграл хотя бы один матч. */
    public static function playedUserIds(): array
    {
        return array_keys(array_filter(self::all(), fn ($n) => $n > 0));
    }

    /**
     * Плей-офф: в одном матче бывают и игроки (американо), и команды
     * (парный турнир) — считаем оба заполнения.
     */
    private static function addPlayoff(array &$counts): void
    {
        $rows = DB::table('tournament_playoff_matches')
            ->join('tournaments', 'tournament_playoff_matches.tournament_id', '=', 'tournaments.id')
            ->where('tournament_playoff_matches.status', 'completed')
            ->where('tournaments.status', 'completed')
            ->get([
                'team1_player1_id as a', 'team1_player2_id as b',
                'team2_player1_id as c', 'team2_player2_id as d',
                'team1_id', 'team2_id',
            ]);

        self::add($counts, $rows);

        $teamPlayers = self::teamPlayers();
        foreach ($rows as $row) {
            foreach ([$row->team1_id, $row->team2_id] as $teamId) {
                foreach ($teamPlayers[$teamId] ?? [] as $userId) {
                    $counts[$userId] = ($counts[$userId] ?? 0) + 1;
                }
            }
        }
    }

    /** Групповой этап парного турнира: матч между командами. */
    private static function addTeams(array &$counts): void
    {
        $rows = DB::table('tournament_group_matches')
            ->join('tournament_team_groups', 'tournament_group_matches.group_id', '=', 'tournament_team_groups.id')
            ->join('tournaments', 'tournament_team_groups.tournament_id', '=', 'tournaments.id')
            ->where('tournament_group_matches.status', 'completed')
            ->where('tournaments.status', 'completed')
            ->get(['team1_id', 'team2_id']);

        $teamPlayers = self::teamPlayers();
        foreach ($rows as $row) {
            foreach ([$row->team1_id, $row->team2_id] as $teamId) {
                foreach ($teamPlayers[$teamId] ?? [] as $userId) {
                    $counts[$userId] = ($counts[$userId] ?? 0) + 1;
                }
            }
        }
    }

    /** Bali KOC: играют пары, игроки лежат в паре. */
    private static function addBali(array &$counts): void
    {
        $pairPlayers = [];
        foreach (DB::table('bali_koc_pairs')->get(['id', 'player1_id', 'player2_id']) as $pair) {
            $pairPlayers[$pair->id] = array_filter([$pair->player1_id, $pair->player2_id]);
        }

        if ($pairPlayers === []) {
            return;
        }

        $rows = DB::table('bali_koc_matches')
            ->join('bali_koc_rounds', 'bali_koc_matches.bali_koc_round_id', '=', 'bali_koc_rounds.id')
            ->join('tournaments', 'bali_koc_rounds.tournament_id', '=', 'tournaments.id')
            ->where('bali_koc_matches.status', 'completed')
            ->where('tournaments.status', 'completed')
            ->get(['pair1_id', 'pair2_id']);

        foreach ($rows as $row) {
            foreach ([$row->pair1_id, $row->pair2_id] as $pairId) {
                foreach ($pairPlayers[$pairId] ?? [] as $userId) {
                    $counts[$userId] = ($counts[$userId] ?? 0) + 1;
                }
            }
        }
    }

    /** Состав команд турниров: [team_id => [user_id, user_id]]. */
    private static function teamPlayers(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        foreach (DB::table('tournament_teams')->get(['id', 'player1_id', 'player2_id']) as $team) {
            $cache[$team->id] = array_values(array_filter([$team->player1_id, $team->player2_id]));
        }

        return $cache;
    }

    /** Плюс матч каждому игроку из строки. */
    private static function add(array &$counts, $rows): void
    {
        foreach ($rows as $row) {
            foreach ([$row->a ?? null, $row->b ?? null, $row->c ?? null, $row->d ?? null] as $userId) {
                if ($userId) {
                    $counts[$userId] = ($counts[$userId] ?? 0) + 1;
                }
            }
        }
    }
}

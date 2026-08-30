<?php

namespace App\Support;

use App\Models\League;
use App\Models\Tournament;
use App\Models\User;

/**
 * Сводная таблица лиги: складываем этапы.
 *
 * Внутри этапа Flex ранжирует по среднему за матч — там у игроков разное
 * число матчей из-за отдыхов. В лиге смысл другой: ходить на этапы — часть
 * соревнования, поэтому первым критерием идёт сумма очков за все этапы,
 * а пропуск честно стоит игроку очков.
 *
 * Дальше сравниваем как в этапе: % побед → личная встреча → рейтинг → id.
 * Результаты нигде не хранятся: счёт правят задним числом, и сохранённая
 * таблица разошлась бы с турнирами.
 */
class LeagueStandings
{
    /**
     * @return array<int, array<string, mixed>> строки таблицы с position
     */
    public static function build(League $league): array
    {
        $stages = self::countedStages($league);
        if ($stages->isEmpty()) {
            return [];
        }

        $totals = [];
        $stagesPlayed = [];
        $bestPlace = [];
        $h2h = [];

        foreach ($stages as $stage) {
            foreach (AmericanoFlexRanking::stats($stage) as $userId => $s) {
                $totals[$userId] ??= AmericanoFlexRanking::emptyStats();
                foreach ($s as $key => $value) {
                    $totals[$userId][$key] += $value;
                }
                $stagesPlayed[$userId] = ($stagesPlayed[$userId] ?? 0) + 1;
            }

            // Место на этапе — чтобы показать «лучший результат за лигу».
            foreach (AmericanoFlexRanking::soloRows($stage) as $row) {
                $id = $row['id'];
                $bestPlace[$id] = isset($bestPlace[$id])
                    ? min($bestPlace[$id], $row['position'])
                    : $row['position'];
            }

            // Личные встречи складываем по всем этапам: два игрока за лигу
            // встречаются много раз, и решает сумма их очных матчей.
            foreach (AmericanoFlexRanking::headToHead($stage) as $a => $rivals) {
                foreach ($rivals as $b => $score) {
                    $h2h[$a][$b] = ($h2h[$a][$b] ?? 0) + $score;
                }
            }
        }

        $users = User::whereIn('id', array_keys($totals))
            ->get(['id', 'name', 'avatar', 'level', 'rating', 'level_verified'])
            ->keyBy('id');

        $rows = [];
        foreach ($totals as $userId => $s) {
            $user = $users[$userId] ?? null;

            $rows[] = [
                'id' => $userId,
                'user' => $user,
                'name' => $user->name ?? 'Игрок',
                'avatar' => $user->avatar ?? null,
                'level' => $user->level ?? null,
                'rating' => (int) ($user->rating ?? 0),
                // Галочка подтверждённого уровня — как в таблице этапа.
                'verified' => (bool) ($user->level_verified ?? false),
                'points_for' => $s['points_for'],
                'points_against' => $s['points_against'],
                'diff' => $s['points_for'] - $s['points_against'],
                'matches' => $s['matches'],
                'wins' => $s['wins'],
                'losses' => $s['losses'],
                'draws' => $s['draws'],
                'stages' => $stagesPlayed[$userId] ?? 0,
                'best_place' => $bestPlace[$userId] ?? null,
                'average' => $s['matches'] > 0
                    ? round($s['points_for'] / $s['matches'], 2)
                    : 0.0,
            ];
        }

        return self::sort($rows, $h2h);
    }

    /**
     * Этапы, идущие в зачёт: только завершённые.
     * Идущий этап в таблицу не идёт — иначе место скачет прямо во время игры.
     *
     * @return \Illuminate\Support\Collection<int, Tournament>
     */
    public static function countedStages(League $league)
    {
        return $league->stages()->where('status', 'completed')->get();
    }

    /**
     * Сумма очков → % побед → личная встреча → рейтинг → id.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<int, int>> $h2h
     */
    private static function sort(array $rows, array $h2h): array
    {
        usort($rows, function ($a, $b) use ($h2h) {
            if ($a['points_for'] !== $b['points_for']) {
                return $b['points_for'] <=> $a['points_for'];
            }

            // Процент побед — сравниваем дробями, без потери точности.
            $left = $a['matches'] > 0 ? $a['wins'] * $b['matches'] : 0;
            $right = $b['matches'] > 0 ? $b['wins'] * $a['matches'] : 0;
            if ($left !== $right) {
                return $right <=> $left;
            }

            $mine = $h2h[$a['id']][$b['id']] ?? null;
            $theirs = $h2h[$b['id']][$a['id']] ?? null;
            if ($mine !== null && $theirs !== null && $mine !== $theirs) {
                return $theirs <=> $mine;
            }

            if ($a['rating'] !== $b['rating']) {
                return $b['rating'] <=> $a['rating'];
            }

            return $a['id'] <=> $b['id'];
        });

        $position = 1;
        foreach ($rows as &$row) {
            $row['position'] = $position++;
        }

        return $rows;
    }

    /** Короткая сводка для карточки лиги. */
    public static function summary(League $league): array
    {
        $stages = $league->stages()->get();

        return [
            'stages_total' => max($league->stages_planned, $stages->count()),
            'stages_done' => $stages->where('status', 'completed')->count(),
            'players' => $league->activePlayers()->count(),
            'next_stage' => $stages->whereIn('status', ['open', 'draft', 'in_progress'])
                ->sortBy('league_stage')
                ->first(),
        ];
    }
}

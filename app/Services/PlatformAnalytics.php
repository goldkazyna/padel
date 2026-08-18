<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Цифры платформы за прошлое — для партнёров и разговоров о рынке.
 *
 * Считается по боевым таблицам, а не по журналам событий: журнал входов
 * ловит только веб-панель клуба, а событий экранов приложение не пишет
 * вовсе. Зато турниры, участия и брони есть с самого первого дня.
 */
class PlatformAnalytics
{
    /** Бронь занимает место и приносит деньги только в этом статусе. */
    private const BOOKING_ACTIVE = 'confirmed';

    /**
     * Помесячный ряд: новые игроки, активные, турниры, участия, брони, выручка.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthly(?int $clubId = null): array
    {
        $months = [];

        // Новые игроки — платформенная цифра, к клубу не привязана.
        if ($clubId === null) {
            foreach ($this->groupByMonth('users', 'created_at') as $month => $count) {
                $months[$month]['new_players'] = $count;
            }
        }

        foreach ($this->tournamentsByMonth($clubId) as $month => $count) {
            $months[$month]['tournaments'] = $count;
        }
        foreach ($this->participationsByMonth($clubId) as $month => $count) {
            $months[$month]['participations'] = $count;
        }
        foreach ($this->activePlayersByMonth($clubId) as $month => $count) {
            $months[$month]['active_players'] = $count;
        }
        foreach ($this->bookingsByMonth($clubId) as $month => $row) {
            $months[$month]['bookings'] = $row->count;
            $months[$month]['revenue'] = (int) $row->revenue;
        }

        ksort($months);

        $result = [];
        foreach ($months as $month => $row) {
            $result[] = array_merge([
                'month' => $month,
                'new_players' => 0,
                'tournaments' => 0,
                'participations' => 0,
                'active_players' => 0,
                'bookings' => 0,
                'revenue' => 0,
            ], $row);
        }

        return $result;
    }

    /**
     * Итоги за всё время и за последние 30 дней.
     *
     * @return array<string, int>
     */
    public function totals(?int $clubId = null): array
    {
        $since = Carbon::now()->subDays(30);

        return [
            'players' => $clubId === null ? DB::table('users')->count() : $this->clubPlayers($clubId),
            'tournaments' => $this->tournamentsQuery($clubId)->count(),
            'participations' => $this->participationsQuery($clubId)->count(),
            'bookings' => $this->bookingsQuery($clubId)->count(),
            'revenue' => (int) $this->bookingsQuery($clubId)->sum('court_bookings.price'),
            'active_30d' => $this->activePlayersSince($since, $clubId),
            'bookings_30d' => (int) $this->bookingsQuery($clubId)
                ->where('court_bookings.date', '>=', $since->toDateString())->count(),
        ];
    }

    /**
     * Возвращаемость: из тех, кто впервые сыграл в этом месяце, сколько
     * сыграли ещё хотя бы раз позже.
     *
     * Главная цифра для партнёров: разовый посетитель и постоянный игрок —
     * это два разных бизнеса.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retention(): array
    {
        $plays = $this->playsByUser();

        $cohorts = [];
        foreach ($plays as $dates) {
            sort($dates);
            $firstMonth = substr($dates[0], 0, 7);
            $cohorts[$firstMonth]['total'] = ($cohorts[$firstMonth]['total'] ?? 0) + 1;
            if (count($dates) > 1) {
                $cohorts[$firstMonth]['returned'] = ($cohorts[$firstMonth]['returned'] ?? 0) + 1;
            }
        }

        ksort($cohorts);

        $result = [];
        foreach ($cohorts as $month => $row) {
            $total = $row['total'];
            $returned = $row['returned'] ?? 0;
            $result[] = [
                'month' => $month,
                'first_time' => $total,
                'returned' => $returned,
                'share' => $total > 0 ? (int) round($returned * 100 / $total) : 0,
            ];
        }

        return $result;
    }

    /**
     * Разбивка по клубам.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byClub(): array
    {
        $clubs = DB::table('clubs')->select('id', 'name')->orderBy('name')->get();

        $rows = [];
        foreach ($clubs as $club) {
            $totals = $this->totals($club->id);
            if ($totals['tournaments'] === 0 && $totals['bookings'] === 0) {
                continue; // клубы без активности в отчёт не тащим
            }
            $rows[] = array_merge(['club' => $club->name], $totals);
        }

        usort($rows, fn ($a, $b) => $b['participations'] <=> $a['participations']);

        return $rows;
    }

    // --- внутренняя кухня ---

    /**
     * Сколько записей появилось по месяцам.
     *
     * Месяц режем через SUBSTR, а не DATE_FORMAT: последний есть только
     * в MySQL, а тесты идут на SQLite — иначе аналитику нечем проверить.
     *
     * @return array<string, int>
     */
    private function groupByMonth(string $table, string $column): array
    {
        return DB::table($table)
            ->selectRaw("SUBSTR({$column}, 1, 7) as m, COUNT(*) as c")
            ->groupBy('m')
            ->pluck('c', 'm')
            ->all();
    }

    private function tournamentsQuery(?int $clubId)
    {
        return DB::table('tournaments')
            ->where('status', 'completed')
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId));
    }

    /** @return array<string, int> */
    private function tournamentsByMonth(?int $clubId): array
    {
        return $this->tournamentsQuery($clubId)
            ->selectRaw("SUBSTR(start_date, 1, 7) as m, COUNT(*) as c")
            ->groupBy('m')
            ->pluck('c', 'm')
            ->all();
    }

    /**
     * Участия в завершённых турнирах.
     *
     * Пары считаем за двоих: в парных турнирах игроки лежат в командах,
     * а не в участниках, и без этого половина людей пропала бы из отчёта.
     */
    private function participationsQuery(?int $clubId)
    {
        return DB::table('tournament_participants')
            ->join('tournaments', 'tournaments.id', '=', 'tournament_participants.tournament_id')
            ->where('tournaments.status', 'completed')
            ->when($clubId, fn ($q) => $q->where('tournaments.club_id', $clubId));
    }

    /** @return array<string, int> */
    private function participationsByMonth(?int $clubId): array
    {
        $solo = $this->participationsQuery($clubId)
            ->selectRaw("SUBSTR(tournaments.start_date, 1, 7) as m, COUNT(*) as c")
            ->groupBy('m')
            ->pluck('c', 'm')
            ->all();

        $paired = DB::table('tournament_teams')
            ->join('tournaments', 'tournaments.id', '=', 'tournament_teams.tournament_id')
            ->where('tournaments.status', 'completed')
            ->whereIn('tournament_teams.status', ['approved', 'pending'])
            ->when($clubId, fn ($q) => $q->where('tournaments.club_id', $clubId))
            ->selectRaw("SUBSTR(tournaments.start_date, 1, 7) as m, COUNT(*) * 2 as c")
            ->groupBy('m')
            ->pluck('c', 'm')
            ->all();

        foreach ($paired as $month => $count) {
            $solo[$month] = ($solo[$month] ?? 0) + $count;
        }

        return $solo;
    }

    /**
     * Когда каждый игрок играл.
     *
     * @return array<int, array<int, string>> user_id => [даты турниров]
     */
    private function playsByUser(): array
    {
        $plays = [];

        $rows = DB::table('tournament_participants')
            ->join('tournaments', 'tournaments.id', '=', 'tournament_participants.tournament_id')
            ->where('tournaments.status', 'completed')
            ->select('tournament_participants.user_id', 'tournaments.start_date')
            ->get();

        foreach ($rows as $row) {
            $plays[$row->user_id][] = (string) $row->start_date;
        }

        // Пары лежат отдельно — берём обоих игроков команды.
        $teams = DB::table('tournament_teams')
            ->join('tournaments', 'tournaments.id', '=', 'tournament_teams.tournament_id')
            ->where('tournaments.status', 'completed')
            ->whereIn('tournament_teams.status', ['approved', 'pending'])
            ->select('tournament_teams.player1_id', 'tournament_teams.player2_id', 'tournaments.start_date')
            ->get();

        foreach ($teams as $row) {
            foreach ([$row->player1_id, $row->player2_id] as $id) {
                if ($id) {
                    $plays[$id][] = (string) $row->start_date;
                }
            }
        }

        return $plays;
    }

    /** @return array<string, int> */
    private function activePlayersByMonth(?int $clubId): array
    {
        $byMonth = [];
        foreach ($this->playsByUser() as $userId => $dates) {
            foreach (array_unique(array_map(fn ($d) => substr($d, 0, 7), $dates)) as $month) {
                $byMonth[$month][$userId] = true;
            }
        }

        return array_map('count', $byMonth);
    }

    private function activePlayersSince(Carbon $since, ?int $clubId): int
    {
        $ids = [];
        foreach ($this->playsByUser() as $userId => $dates) {
            foreach ($dates as $date) {
                if ($date >= $since->toDateTimeString()) {
                    $ids[$userId] = true;
                    break;
                }
            }
        }

        return count($ids);
    }

    private function clubPlayers(int $clubId): int
    {
        return DB::table('club_clients')->where('club_id', $clubId)->count();
    }

    private function bookingsQuery(?int $clubId)
    {
        return DB::table('court_bookings')
            ->join('courts', 'courts.id', '=', 'court_bookings.court_id')
            ->where('court_bookings.status', self::BOOKING_ACTIVE)
            ->when($clubId, fn ($q) => $q->where('courts.club_id', $clubId));
    }

    /** @return array<string, object> */
    private function bookingsByMonth(?int $clubId): array
    {
        return $this->bookingsQuery($clubId)
            ->selectRaw("SUBSTR(court_bookings.date, 1, 7) as m, COUNT(*) as count, SUM(court_bookings.price) as revenue")
            ->groupBy('m')
            ->get()
            ->keyBy('m')
            ->all();
    }
}

<?php

namespace App\Reports;

use App\Models\TournamentTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Выборка игроков для выгрузки: уровни и участие в турнирах.
 *
 * «Играл» — это завершённый турнир, а не запись на него: турнир могли
 * отменить или он ещё идёт. Участие считается по двум источникам сразу —
 * личная запись (tournament_participants) и командная (tournament_teams),
 * иначе игроки парных форматов выпадают из выборки.
 */
class UsersReportService
{
    /**
     * @param array{levels?: array<int>, played?: string} $filters
     */
    public function query(array $filters): Builder
    {
        $query = User::human()->orderBy('name');

        $levels = array_filter((array) ($filters['levels'] ?? []), fn ($l) => $l !== '' && $l !== null);
        if ($levels) {
            $query->where(function ($q) use ($levels) {
                foreach ($levels as $level) {
                    $min = (float) $level;
                    $q->orWhereBetween('level', [$min, $min + 0.75]);
                }
            });
        }

        $played = $filters['played'] ?? null;
        if ($played === 'yes') {
            $query->whereIn('id', $this->playedUserIds());
        } elseif ($played === 'no') {
            $query->whereNotIn('id', $this->playedUserIds());
        }

        return $query;
    }

    /** Лист для Excel по тем же фильтрам, что и выборка на экране. */
    public function sheet(array $filters): ReportSheet
    {
        $users = $this->query($filters)->get();
        $counts = $this->tournamentCounts($users->pluck('id')->all());

        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                $user->id,
                $user->name,
                (string) ($user->phone ?? ''),
                (string) ($user->city ?? ''),
                $user->level !== null ? (float) $user->level : '',
                (int) ($user->rating ?? 0),
                $user->level_verified ? 'да' : 'нет',
                $counts[$user->id] ?? 0,
                $user->created_at?->format('d.m.Y') ?? '',
            ];
        }

        return new ReportSheet(
            title: 'Игроки',
            headings: [
                'ID', 'Имя', 'Телефон', 'Город', 'Уровень',
                'Рейтинг', 'Уровень подтверждён', 'Турниров сыграно', 'Регистрация',
            ],
            rows: $rows,
            columnFormats: [5 => '#,##0', 7 => '#,##0'],
        );
    }

    /** Сколько подходит под фильтры — показываем рядом с кнопкой выгрузки. */
    public function count(array $filters): int
    {
        return $this->query($filters)->count();
    }

    /**
     * Id всех, кто сыграл хотя бы один завершённый турнир.
     *
     * Одним подзапросом на оба источника: список нужен и для «играл»,
     * и для «не играл».
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function playedUserIds()
    {
        $personal = DB::table('tournament_participants')
            ->join('tournaments', 'tournaments.id', '=', 'tournament_participants.tournament_id')
            ->where('tournaments.status', 'completed')
            ->pluck('tournament_participants.user_id');

        $teams = TournamentTeam::query()
            ->whereHas('tournament', fn ($q) => $q->where('status', 'completed'))
            ->get(['player1_id', 'player2_id'])
            ->flatMap(fn ($t) => [$t->player1_id, $t->player2_id])
            ->filter();

        return $personal->merge($teams)->unique()->values();
    }

    /**
     * Сколько завершённых турниров сыграл каждый из переданных игроков.
     *
     * @param  array<int> $userIds
     * @return array<int, int>
     */
    private function tournamentCounts(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $counts = [];

        $personal = DB::table('tournament_participants')
            ->join('tournaments', 'tournaments.id', '=', 'tournament_participants.tournament_id')
            ->where('tournaments.status', 'completed')
            ->whereIn('tournament_participants.user_id', $userIds)
            ->selectRaw('tournament_participants.user_id, COUNT(*) as total')
            ->groupBy('tournament_participants.user_id')
            ->pluck('total', 'user_id');

        foreach ($personal as $userId => $total) {
            $counts[(int) $userId] = (int) $total;
        }

        $teams = TournamentTeam::query()
            ->whereHas('tournament', fn ($q) => $q->where('status', 'completed'))
            ->where(fn ($q) => $q->whereIn('player1_id', $userIds)->orWhereIn('player2_id', $userIds))
            ->get(['player1_id', 'player2_id']);

        foreach ($teams as $team) {
            foreach ([$team->player1_id, $team->player2_id] as $id) {
                if ($id && in_array($id, $userIds, true)) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }
}

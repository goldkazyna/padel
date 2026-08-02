{{-- resources/views/club/tournaments/justpadelit/partials/_leaderboard.blade.php --}}
<div class="section-subheader">
    <i class="bi bi-trophy"></i> Таблица лидеров
</div>

@if(($pairStandings ?? null) !== null)
    {{-- Фикс-пары: таблица по парам --}}
    <div class="leaderboard-table-wrapper mb-4">
        <table class="leaderboard-table">
            <thead>
                <tr>
                    <th class="col-rank ttt">#</th>
                    <th class="col-player ttt">Пара</th>
                    <th class="col-stat ttt">В</th>
                    <th class="col-stat ttt">П</th>
                    <th class="col-stat ttt">З</th>
                    <th class="col-stat ttt">Пр</th>
                    <th class="col-stat ttt">%</th>
                    <th class="col-points ttt">Очки</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pairStandings as $row)
                    @php
                        $rank = $loop->iteration;
                        $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                        $pair = $row['pair'];
                    @endphp
                    <tr class="{{ $rankClass }}">
                        <td class="col-rank ttt"><span class="rank-badge {{ $rankClass }}">{{ $rank }}</span></td>
                        <td class="col-player ttt">
                            <div class="player-info">
                                <div class="player-avatar">{{ mb_strtoupper(mb_substr($pair->player1->name ?? '?', 0, 1)) }}{{ mb_strtoupper(mb_substr($pair->player2->name ?? '?', 0, 1)) }}</div>
                                <div class="player-details">
                                    <div class="player-name">{{ $pair->player1->name ?? '?' }} / {{ $pair->player2->name ?? '?' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="col-stat wins ttt">{{ $row['wins'] }}</td>
                        <td class="col-stat losses ttt">{{ $row['losses'] }}</td>
                        <td class="col-stat points-for ttt">{{ $row['points_for'] }}</td>
                        <td class="col-stat points-against ttt">{{ $row['points_against'] }}</td>
                        <td class="col-stat percentage ttt">{{ $row['win_rate'] }}%</td>
                        <td class="col-points ttt">{{ $row['total_points'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <style>.player-name { font-weight: 500; font-size: 22px; } .ttt { font-size: 24px; }</style>
@else

@php
    $rows = $tournament->justPadelItPlayers->map(fn ($p) => [
        'player' => $p->user, 'total_points' => $p->total_points, 'wins' => $p->wins,
        'losses' => $p->losses, 'points_for' => $p->points_for, 'points_against' => $p->points_against,
    ])->values()->all();
    $rows = \App\Services\JustPadelItScoring::sortStandings($rows, (bool) $tournament->jpi_rank_by_wins);
@endphp

<div class="leaderboard-table-wrapper mb-4">
    <table class="leaderboard-table">
        <thead>
            <tr>
                <th class="col-rank ttt">#</th>
                <th class="col-player ttt">Игрок</th>
                <th class="col-stat ttt">В</th>
                <th class="col-stat ttt">П</th>
                <th class="col-stat ttt">З</th>
                <th class="col-stat ttt">Пр</th>
                <th class="col-stat ttt">%</th>
                <th class="col-points ttt">Очки</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                @php
                    $player = $row['player'];
                    $rank = $loop->iteration;
                    $totalBalls = $row['points_for'] + $row['points_against'];
                    $percentage = $totalBalls > 0 ? round(($row['points_for'] / $totalBalls) * 100) : 0;
                    $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                @endphp
                <tr class="{{ $rankClass }}">
                    <td class="col-rank ttt">
                        <span class="rank-badge {{ $rankClass }}">{{ $rank }}</span>
                    </td>
                    <td class="col-player ttt">
                        <div class="player-info">
                            <div class="player-avatar">
                                {{ mb_strtoupper(mb_substr($player->name, 0, 1)) }}
                            </div>
                            <div class="player-details">
                                <div class="player-name">{{ $player->name }}</div>
                                <div class="player-rating">{{ $player->rating }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="col-stat wins ttt">{{ $row['wins'] }}</td>
                    <td class="col-stat losses ttt">{{ $row['losses'] }}</td>
                    <td class="col-stat points-for ttt">{{ $row['points_for'] }}</td>
                    <td class="col-stat points-against ttt">{{ $row['points_against'] }}</td>
                    <td class="col-stat percentage ttt">{{ $percentage }}%</td>
                    <td class="col-points ttt">{{ $row['total_points'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<style>
.player-name { font-weight: 500; font-size: 24px; }
.ttt { font-size: 24px; }
</style>
@endif

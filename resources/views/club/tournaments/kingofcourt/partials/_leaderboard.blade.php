{{-- resources/views/club/tournaments/kingofcourt/partials/_leaderboard.blade.php --}}
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
    // Единый порядок (очки → разница → % мячей → личная встреча → рейтинг → id).
    $playerStats = [];
    foreach (\App\Support\KingOfCourtRanking::standings($tournament) as $row) {
        $kp = $row['kp'];
        $playerStats[$row['id']] = [
            'player' => $row['user'],
            'wins' => $kp->wins,
            'losses' => $kp->losses,
            'points_for' => $kp->points_for,
            'points_against' => $kp->points_against,
            'total_points' => $kp->total_points,
        ];
    }
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
            @foreach($playerStats as $stats)
                @php
                    $player = $stats['player'];
                    $rank = $loop->iteration;
                    $totalBalls = $stats['points_for'] + $stats['points_against'];
                    $percentage = $totalBalls > 0 ? round(($stats['points_for'] / $totalBalls) * 100) : 0;
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
                    <td class="col-stat wins ttt">{{ $stats['wins'] }}</td>
                    <td class="col-stat losses ttt">{{ $stats['losses'] }}</td>
                    <td class="col-stat points-for ttt">{{ $stats['points_for'] }}</td>
                    <td class="col-stat points-against ttt">{{ $stats['points_against'] }}</td>
                    <td class="col-stat percentage ttt">{{ $percentage }}%</td>
                    <td class="col-points ttt">{{ $stats['total_points'] }}</td>
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

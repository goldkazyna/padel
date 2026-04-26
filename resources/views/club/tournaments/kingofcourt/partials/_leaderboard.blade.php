{{-- resources/views/club/tournaments/kingofcourt/partials/_leaderboard.blade.php --}}
<div class="section-subheader">
    <i class="bi bi-trophy"></i> Таблица лидеров
</div>

@php
    $playerStats = [];
    foreach ($tournament->kingOfCourtPlayers as $kp) {
        $playerStats[$kp->user_id] = [
            'player' => $kp->user,
            'wins' => $kp->wins,
            'losses' => $kp->losses,
            'points_for' => $kp->points_for,
            'points_against' => $kp->points_against,
            'total_points' => $kp->total_points,
        ];
    }

    uasort($playerStats, function($a, $b) {
        if ($a['total_points'] !== $b['total_points']) {
            return $b['total_points'] <=> $a['total_points'];
        }
        $diffA = $a['points_for'] - $a['points_against'];
        $diffB = $b['points_for'] - $b['points_against'];
        if ($diffA !== $diffB) {
            return $diffB <=> $diffA;
        }
        $totalA = $a['points_for'] + $a['points_against'];
        $totalB = $b['points_for'] + $b['points_against'];
        $pctA = $totalA > 0 ? $a['points_for'] / $totalA : 0;
        $pctB = $totalB > 0 ? $b['points_for'] / $totalB : 0;
        return $pctB <=> $pctA;
    });
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

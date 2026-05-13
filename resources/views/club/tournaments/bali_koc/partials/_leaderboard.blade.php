{{-- resources/views/club/tournaments/bali_koc/partials/_leaderboard.blade.php --}}
<div class="section-subheader">
    <i class="bi bi-trophy"></i> Таблица лидеров (пары)
</div>

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
            @foreach($standings as $idx => $pair)
                @php
                    $rank = $idx + 1;
                    $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                    $totalGames = $pair->games_for + $pair->games_against;
                    $percentage = $totalGames > 0 ? round(($pair->games_for / $totalGames) * 100) : 0;
                    $p1 = $pair->player1;
                    $p2 = $pair->player2;
                @endphp
                <tr class="{{ $rankClass }}">
                    <td class="col-rank ttt">
                        <span class="rank-badge {{ $rankClass }}">{{ $rank }}</span>
                    </td>
                    <td class="col-player ttt">
                        <div class="player-info">
                            <div class="player-avatar pair-avatar-stack">
                                <span class="pair-avatar-1">{{ mb_strtoupper(mb_substr($p1->name ?? '?', 0, 1)) }}</span>
                                <span class="pair-avatar-2">{{ mb_strtoupper(mb_substr($p2->name ?? '?', 0, 1)) }}</span>
                            </div>
                            <div class="player-details">
                                <div class="player-name">{{ $p1->name ?? '?' }} <span class="player-rating-inline">{{ $p1->rating ?? '' }}</span></div>
                                <div class="player-name">{{ $p2->name ?? '?' }} <span class="player-rating-inline">{{ $p2->rating ?? '' }}</span></div>
                            </div>
                        </div>
                    </td>
                    <td class="col-stat wins ttt">{{ $pair->wins }}</td>
                    <td class="col-stat losses ttt">{{ $pair->losses }}</td>
                    <td class="col-stat points-for ttt">{{ $pair->games_for }}</td>
                    <td class="col-stat points-against ttt">{{ $pair->games_against }}</td>
                    <td class="col-stat percentage ttt">{{ $percentage }}%</td>
                    <td class="col-points ttt">{{ $pair->points }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<style>
.player-name { font-weight: 500; font-size: 24px; }
.ttt { font-size: 24px; }
.player-rating-inline {
    font-size: 14px;
    color: var(--text-secondary);
    margin-left: 6px;
    font-weight: 400;
}
.pair-avatar-stack {
    display: inline-flex;
    align-items: center;
    position: relative;
}
.pair-avatar-stack .pair-avatar-1,
.pair-avatar-stack .pair-avatar-2 {
    width: 1.6em;
    height: 1.6em;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85em;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.12);
}
.pair-avatar-stack .pair-avatar-2 { margin-left: -0.6em; }
</style>

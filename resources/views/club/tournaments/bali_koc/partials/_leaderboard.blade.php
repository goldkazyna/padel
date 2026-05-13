{{-- resources/views/club/tournaments/bali_koc/partials/_leaderboard.blade.php --}}
<div class="section-subheader">
    <i class="bi bi-trophy"></i> Турнирная таблица (пары)
</div>

<div class="leaderboard-table-wrapper mb-4">
    <table class="leaderboard-table">
        <thead>
            <tr>
                <th class="col-rank ttt">#</th>
                <th class="col-player ttt">Пара</th>
                <th class="col-stat ttt">В</th>
                <th class="col-stat ttt">П</th>
                <th class="col-stat ttt">Геймы</th>
                <th class="col-stat ttt">±</th>
                <th class="col-points ttt">Очки</th>
            </tr>
        </thead>
        <tbody>
            @foreach($standings as $idx => $pair)
                @php
                    $rank = $idx + 1;
                    $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                    $gameDiff = (int) $pair->games_for - (int) $pair->games_against;
                @endphp
                <tr class="{{ $rankClass }}">
                    <td class="col-rank ttt">
                        <span class="rank-badge {{ $rankClass }}">{{ $rank }}</span>
                    </td>
                    <td class="col-player ttt">
                        <div class="pair-info">
                            <div class="pair-line">
                                <span class="pair-name">{{ $pair->player1->name ?? '?' }}</span>
                                <span class="pair-rating">{{ $pair->player1->rating ?? '' }}</span>
                            </div>
                            <div class="pair-line">
                                <span class="pair-name">{{ $pair->player2->name ?? '?' }}</span>
                                <span class="pair-rating">{{ $pair->player2->rating ?? '' }}</span>
                            </div>
                        </div>
                    </td>
                    <td class="col-stat wins ttt">{{ $pair->wins }}</td>
                    <td class="col-stat losses ttt">{{ $pair->losses }}</td>
                    <td class="col-stat ttt">{{ $pair->games_for }}:{{ $pair->games_against }}</td>
                    <td class="col-stat ttt">{{ $gameDiff >= 0 ? '+' . $gameDiff : $gameDiff }}</td>
                    <td class="col-points ttt">{{ $pair->points }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<style>
.pair-info { display: flex; flex-direction: column; gap: 4px; }
.pair-line { display: flex; gap: 10px; align-items: baseline; }
.pair-name { font-weight: 500; font-size: 20px; }
.pair-rating { font-size: 14px; color: var(--text-secondary); }
.ttt { font-size: 22px; }
</style>

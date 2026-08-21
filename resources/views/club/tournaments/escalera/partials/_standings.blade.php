{{-- resources/views/club/tournaments/escalera/partials/_standings.blade.php --}}
@php
    $isRawMode = $tournament->escalera_standings_mode === 'raw_points';
    $winBonus = (bool) $tournament->escalera_win_bonus;
@endphp

<div class="section-subheader">
    <i class="bi bi-trophy"></i> Таблица
</div>

@if(empty($standings))
    <div class="esc-note mb-4">Пока пусто: таблица заполнится после первого закрытого раунда.</div>
@else
    <div class="leaderboard-table-wrapper mb-4">
        <table class="leaderboard-table">
            <thead>
                <tr>
                    <th class="col-rank ttt">#</th>
                    <th class="col-player ttt">Игрок</th>
                    <th class="col-stat ttt" title="Побед">В</th>
                    <th class="col-stat ttt" title="Поражений">П</th>
                    <th class="col-stat ttt" title="Забито очков">З</th>
                    <th class="col-stat ttt" title="Пропущено очков">Пр</th>
                    <th class="col-stat ttt" title="Доля выигранных очков">%</th>
                    {{-- Последняя колонка — то, по чему считается место.
                         Как в приложении: «Очки» при зачёте по забитым,
                         «Баллы» при зачёте по позициям. --}}
                    <th class="col-points ttt esc-main-col"
                        title="{{ $isRawMode ? ($winBonus ? 'Забитые очки плюс бонус за результат (победа +2, ничья +1) — по сумме зачёт' : 'Сумма забитых очков — по ней зачёт') : 'Баллы за позиции в общем строю — по ним зачёт' }}">
                        {{ $isRawMode ? 'Очки' : 'Баллы' }}
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach($standings as $row)
                    @php
                        $rank = $row['position'];
                        $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                        // Доля выигранных очков — та же формула, что в «Короле корта».
                        $totalBalls = $row['raw_points'] + $row['points_against'];
                        $percentage = $totalBalls > 0 ? round(($row['raw_points'] / $totalBalls) * 100) : 0;
                    @endphp
                    <tr class="{{ $rankClass }}">
                        <td class="col-rank ttt"><span class="rank-badge {{ $rankClass }}">{{ $rank }}</span></td>
                        <td class="col-player ttt">
                            <div class="player-info">
                                <div class="player-avatar">
                                    {{ mb_strtoupper(mb_substr($row['user']->name ?? '?', 0, 1)) }}
                                </div>
                                <div class="player-details">
                                    <div class="player-name">{{ $row['user']->name ?? '—' }}</div>
                                    <div class="player-rating">{{ $row['user']->rating ?? '—' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="col-stat wins ttt">{{ $row['wins'] }}</td>
                        <td class="col-stat losses ttt">{{ $row['losses'] }}</td>
                        <td class="col-stat points-for ttt">{{ $row['raw_points'] }}</td>
                        <td class="col-stat points-against ttt">{{ $row['points_against'] }}</td>
                        <td class="col-stat percentage ttt">{{ $percentage }}%</td>
                        <td class="col-points ttt esc-main-col">{{ $isRawMode ? $row['score_points'] : $row['points'] }}@if($isRawMode && $winBonus && $row['bonus_points'] > 0)<span class="esc-bonus">+{{ $row['bonus_points'] }}</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="esc-note mb-4">
        <i class="bi bi-info-circle me-2"></i>
        Зачёт идёт {{ $isRawMode ? 'по сумме забитых очков за все короткие матчи' : 'по баллам за позиции в общем строю' }}.
        При равенстве выше тот, кто выиграл больше коротких матчей, затем — личная встреча, затем — рейтинг.
        <br>
        <strong>В</strong> — победы, <strong>П</strong> — поражения, <strong>З</strong> — забито очков,
        <strong>Пр</strong> — пропущено, <strong>%</strong> — доля выигранных очков,
        @if($isRawMode)
            <strong>Очки</strong> — сумма забитых за все матчи: по ней и считается место.
        @else
            <strong>Баллы</strong> — за позицию в общем строю: чем выше корт, тем больше.
        @endif
    </div>
@endif

<style>
/* Размеры как в таблице «Короля корта» (kingofcourt/partials/_leaderboard). */
.player-name { font-weight: 500; font-size: 24px; }
.ttt { font-size: 24px; }
/* Очки и бонус — одной строкой: при 24px «45 +6» иначе рвётся на две. */
.esc-main-col { color: var(--accent) !important; white-space: nowrap; }
.esc-bonus {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-secondary);
    margin-left: 5px;
    vertical-align: 2px;
}
</style>

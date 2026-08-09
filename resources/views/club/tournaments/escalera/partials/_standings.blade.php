{{-- resources/views/club/tournaments/escalera/partials/_standings.blade.php --}}
@php
    $isRawMode = $tournament->escalera_standings_mode === 'raw_points';
    $isCompleted = $tournament->status === 'completed';
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
                    <th class="col-points ttt {{ $isRawMode ? '' : 'esc-main-col' }}">
                        Баллы@if(!$isRawMode) <small>(зачёт)</small>@endif
                    </th>
                    <th class="col-stat ttt {{ $isRawMode ? 'esc-main-col' : '' }}">
                        Очки@if($isRawMode) <small>(зачёт)</small>@endif
                    </th>
                    <th class="col-stat ttt">В</th>
                    <th class="col-stat ttt">{{ $isCompleted ? 'Корт' : 'Корт (след.)' }}</th>
                    <th class="col-stat ttt">Δ</th>
                </tr>
            </thead>
            <tbody>
                @foreach($standings as $row)
                    @php
                        $rank = $row['position'];
                        $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                        // Во время турнира current_court — это корт следующего раунда,
                        // после завершения показываем корт, на котором игрок доиграл.
                        $court = $isCompleted ? $row['final_court'] : $row['current_court'];
                        $change = $row['change'];
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
                        <td class="col-points ttt {{ $isRawMode ? '' : 'esc-main-col' }}">{{ $row['points'] }}</td>
                        <td class="col-stat ttt {{ $isRawMode ? 'esc-main-col' : '' }}">{{ $row['raw_points'] }}</td>
                        <td class="col-stat ttt">{{ $row['wins'] }}</td>
                        <td class="col-stat ttt">{{ $court }}</td>
                        <td class="col-stat ttt">
                            @if($change === null)
                                <span class="esc-change-none">—</span>
                            @elseif($change > 0)
                                <span class="esc-change-up"><i class="bi bi-arrow-up"></i> {{ $change }}</span>
                            @elseif($change < 0)
                                <span class="esc-change-down"><i class="bi bi-arrow-down"></i> {{ abs($change) }}</span>
                            @else
                                <span class="esc-change-none">0</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="esc-note mb-4">
        <i class="bi bi-info-circle me-2"></i>
        Зачёт идёт {{ $isRawMode ? 'по сумме очков за все короткие матчи' : 'по баллам за позиции в общем строю' }}.
        При равенстве выше тот, кто выиграл больше коротких матчей, затем — личная встреча, затем — рейтинг.
        Колонка Δ — изменение места в таблице с прошлого раунда.
    </div>
@endif

<style>
/* Размеры как в таблице «Короля корта» (kingofcourt/partials/_leaderboard). */
.player-name { font-weight: 500; font-size: 24px; }
.ttt { font-size: 24px; }
.esc-main-col { color: var(--accent) !important; }
.esc-change-up { color: var(--accent); }
.esc-change-down { color: var(--esc-warn); }
.esc-change-none { color: var(--text-secondary); }
</style>

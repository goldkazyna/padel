{{-- resources/views/club/tournaments/escalera/partials/_awards.blade.php --}}
@php
    $isRawMode = $tournament->escalera_standings_mode === 'raw_points';
@endphp

<div class="section-subheader">
    <i class="bi bi-award"></i> Награды
</div>

<div class="esc-awards mb-4">
    @if($awards['champion'])
        <div class="esc-award">
            <div class="esc-award-icon"><i class="bi bi-trophy-fill"></i></div>
            <div class="esc-award-title">Чемпион</div>
            <div class="esc-award-name">{{ $awards['champion']['user']->name ?? '—' }}</div>
            <div class="esc-award-note">
                {{-- Показываем то число, по которому шёл зачёт, иначе цифра выглядит ошибкой. --}}
                @if($isRawMode)
                    {{ $awards['champion']['raw_points'] }} очков за матчи
                @else
                    {{ $awards['champion']['points'] }} баллов за позиции
                @endif
            </div>
        </div>
    @endif

    {{-- «Восхождение» может не быть вовсе: если никто не поднялся выше стартового корта. --}}
    @if($awards['ascent'])
        <div class="esc-award">
            <div class="esc-award-icon"><i class="bi bi-graph-up-arrow"></i></div>
            <div class="esc-award-title">Восхождение</div>
            <div class="esc-award-name">{{ $awards['ascent']['user']->name ?? '—' }}</div>
            <div class="esc-award-note">
                корт {{ $awards['ascent']['start_court'] }} → {{ $awards['ascent']['final_court'] }}
                (+{{ $awards['ascent']['climb'] }})
            </div>
        </div>
    @endif

    @if($awards['king_of_court'])
        <div class="esc-award">
            <div class="esc-award-icon"><i class="bi bi-crown"></i></div>
            <div class="esc-award-title">Король корта</div>
            <div class="esc-award-name">{{ $awards['king_of_court']['user']->name ?? '—' }}</div>
            <div class="esc-award-note">
                первый на корте 1 в раунде {{ $awards['king_of_court']['round_number'] }}
            </div>
        </div>
    @endif
</div>

<style>
.esc-awards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
}
.esc-award {
    background: var(--bg-card);
    border: 1px solid var(--accent);
    border-radius: 12px;
    padding: 18px;
    text-align: center;
}
.esc-award-icon { font-size: 2rem; color: var(--accent); margin-bottom: 6px; }
.esc-award-title { color: var(--text-secondary); text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; }
.esc-award-name { font-size: 1.3rem; font-weight: 600; color: var(--text-primary); margin: 4px 0; }
.esc-award-note { color: var(--text-secondary); font-size: 0.9rem; }
</style>

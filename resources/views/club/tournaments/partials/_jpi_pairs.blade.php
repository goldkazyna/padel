{{--
    Фиксированные пары в «Just Padel It».

    Единица записи здесь — пара, а не игрок: организатор заводит сразу обоих.
    Показывается только когда пары собирает организатор; если их собирают сами
    игроки при записи, блок не нужен.
--}}
@php
    $jpiPairs = $tournament->justPadelItPairs()->with(['player1:id,name', 'player2:id,name'])->get();
    $jpiPaired = $jpiPairs->count() * 2;
    $jpiTotal = $tournament->participants()->wherePivot('status', 'registered')->count();
@endphp

<div class="jpi-pairs-section mt-4">
    <div class="add-participant-header">
        <i class="bi bi-people"></i>
        <span>Пары</span>
        <span class="jpi-pairs-count">{{ $jpiPairs->count() }}</span>
        @if($jpiTotal > $jpiPaired)
            <span class="jpi-pairs-rest">без пары: {{ $jpiTotal - $jpiPaired }}</span>
        @endif
    </div>

    @if($jpiPairs->isNotEmpty())
        <div class="jpi-pairs-list">
            @foreach($jpiPairs as $i => $pair)
                <div class="jpi-pair-row">
                    <div class="jpi-pair-num">{{ $i + 1 }}</div>
                    <div class="jpi-pair-names">
                        {{ $pair->player1->name ?? '—' }} <span>/</span> {{ $pair->player2->name ?? '—' }}
                    </div>
                    @if($tournament->status === 'open')
                        <form action="{{ route('club.tournaments.jpiPairs.remove', [$tournament, $pair]) }}"
                              method="POST" class="d-inline"
                              onsubmit="return confirm('Разбить пару? Игроки останутся в списке участников.')">
                            @csrf
                            @method('DELETE')
                            <button class="btn-danger-custom btn-sm" title="Разбить пару">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if($tournament->status === 'open')
        <form action="{{ route('club.tournaments.jpiPairs.add', $tournament) }}" method="POST" class="jpi-pair-form">
            @csrf
            <div class="jpi-pair-fields">
                <div class="jpi-pair-field">
                    <label class="form-label">Игрок 1</label>
                    <div class="search-wrapper">
                        <input type="text" class="form-control player-search-input" data-target="jpiP1"
                               placeholder="Телефон или имя..." autocomplete="off">
                        <input type="hidden" name="player1_id" id="jpiP1PlayerId">
                        <div class="search-results" id="jpiP1Results"></div>
                    </div>
                    <div class="selected-player mt-2" id="jpiP1Selected" style="display: none;"></div>
                </div>
                <div class="jpi-pair-field">
                    <label class="form-label">Игрок 2</label>
                    <div class="search-wrapper">
                        <input type="text" class="form-control player-search-input" data-target="jpiP2"
                               placeholder="Телефон или имя..." autocomplete="off">
                        <input type="hidden" name="player2_id" id="jpiP2PlayerId">
                        <div class="search-results" id="jpiP2Results"></div>
                    </div>
                    <div class="selected-player mt-2" id="jpiP2Selected" style="display: none;"></div>
                </div>
            </div>
            <button type="submit" class="btn-primary-custom mt-3">
                <i class="bi bi-plus-lg me-1"></i> Добавить пару
            </button>
        </form>
    @endif
</div>

<style>
.jpi-pairs-section {
    background: rgba(255, 255, 255, .03);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px;
}
.jpi-pairs-count {
    background: rgba(34, 197, 94, .16);
    color: #22c55e;
    font-size: .75rem;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 20px;
}
.jpi-pairs-rest {
    background: rgba(234, 179, 8, .16);
    color: #eab308;
    font-size: .75rem;
    font-weight: 600;
    padding: 2px 10px;
    border-radius: 20px;
}
.jpi-pairs-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 14px 0 18px;
}
.jpi-pair-row {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 10px 14px;
}
.jpi-pair-num {
    width: 24px;
    height: 24px;
    flex: none;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: rgba(255, 255, 255, .06);
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 700;
}
.jpi-pair-names {
    flex: 1;
    min-width: 0;
    color: var(--text-primary);
    font-weight: 600;
    font-size: 14px;
}
.jpi-pair-names span {
    color: var(--text-muted);
    margin: 0 4px;
}
.jpi-pair-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
@media (max-width: 576px) {
    .jpi-pair-fields { grid-template-columns: 1fr; }
}
</style>

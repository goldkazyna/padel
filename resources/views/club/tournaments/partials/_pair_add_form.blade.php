{{--
    Форма «Добавить пару». Ожидает $action — куда отправлять.

    Используется в двух местах: когда пары собирает организатор (пары формата)
    и когда игроки записываются парой сами (команды турнира). Механика поиска
    игроков общая с формой добавления участника.
--}}
<form action="{{ $action }}" method="POST" class="pair-add-form">
    @csrf
    <div class="pair-add-fields">
        <div class="pair-add-field">
            <label class="form-label">Игрок 1</label>
            <div class="search-wrapper">
                <input type="text" class="form-control player-search-input" data-target="pairP1"
                       placeholder="Телефон или имя..." autocomplete="off">
                <input type="hidden" name="player1_id" id="pairP1PlayerId">
                <div class="search-results" id="pairP1Results"></div>
            </div>
            <div class="selected-player mt-2" id="pairP1Selected" style="display: none;"></div>
        </div>
        <div class="pair-add-field">
            <label class="form-label">Игрок 2</label>
            <div class="search-wrapper">
                <input type="text" class="form-control player-search-input" data-target="pairP2"
                       placeholder="Телефон или имя..." autocomplete="off">
                <input type="hidden" name="player2_id" id="pairP2PlayerId">
                <div class="search-results" id="pairP2Results"></div>
            </div>
            <div class="selected-player mt-2" id="pairP2Selected" style="display: none;"></div>
        </div>
    </div>
    <button type="submit" class="btn-primary-custom mt-3">
        <i class="bi bi-plus-lg me-1"></i> Добавить пару
    </button>
</form>

<style>
.pair-add-section {
    background: rgba(255, 255, 255, .03);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px;
}
.pair-add-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
@media (max-width: 576px) {
    .pair-add-fields { grid-template-columns: 1fr; }
}
.pair-count-badge {
    background: rgba(34, 197, 94, .16);
    color: #22c55e;
    font-size: .75rem;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 20px;
}
.pair-rest-badge {
    background: rgba(234, 179, 8, .16);
    color: #eab308;
    font-size: .75rem;
    font-weight: 600;
    padding: 2px 10px;
    border-radius: 20px;
}
.pair-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 14px 0 18px;
}
.pair-row {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 10px 14px;
}
.pair-row-num {
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
.pair-row-names {
    flex: 1;
    min-width: 0;
    color: var(--text-primary);
    font-weight: 600;
    font-size: 14px;
}
.pair-row-names span {
    color: var(--text-muted);
    margin: 0 4px;
}
</style>

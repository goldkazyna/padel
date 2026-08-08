{{-- Турнир в модалке редактирования брони. Подключается в дневном и
     недельном расписании. Разметка повторяет окно создания (_book_tournament),
     чтобы оба окна показывали одно и то же. Функции — в _tournament_js. --}}
<div id="editTournamentBlock" style="display:none;">
    <div class="modal-section-title">Турнир</div>
    <div class="form-group">
        <select name="tournament_id" id="editTournamentSelect" class="form-input" onchange="renderEditTournamentPrice()">
            <option value="">— выберите турнир —</option>
            @foreach(($bookingTournaments ?? []) as $t)
                <option value="{{ $t['id'] }}">{{ $t['name'] }}{{ $t['date'] ? ' — ' . $t['date'] : '' }}</option>
            @endforeach
        </select>
    </div>
    <div id="editTnPrice" style="display:none;margin:8px 0;color:#a1a1aa;font-size:13px;"></div>
    <div id="editTournamentInfoBlock" class="group-members-block" style="display:none;">
        <div class="gm-header">
            <span class="gm-title">Оплатившие участники</span>
            <span class="gm-count" id="editTnCount"></span>
        </div>
        <ul id="editTnList" class="gm-list"></ul>
        <div id="editTnEmpty" class="gm-empty" style="display:none;">Оплативших пока нет — цена появится после записи игроков</div>
        <a id="editTnLink" href="#" target="_blank" rel="noopener"
           style="display:none;align-items:center;justify-content:center;gap:8px;margin-top:10px;padding:10px 12px;border-radius:10px;background:rgba(167,139,250,0.10);border:1px solid rgba(167,139,250,0.35);color:#a78bfa;font-size:13px;font-weight:700;text-decoration:none;">
            <i class="bi bi-trophy"></i> Открыть турнир
        </a>
    </div>
</div>

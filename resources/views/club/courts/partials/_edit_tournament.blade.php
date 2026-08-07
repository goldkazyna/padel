{{-- Турнир в модалке редактирования брони. Подключается в дневном и
     недельном расписании. Функции определены в _tournament_js. --}}
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
    <div id="editTnPrice" style="margin:8px 0;color:#a1a1aa;font-size:13px;"></div>
</div>

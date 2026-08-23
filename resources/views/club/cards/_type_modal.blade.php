{{-- Модал создания/редактирования типа карты --}}
<div id="cardTypeModal" class="ct-modal" onclick="if(event.target===this)this.style.display='none'">
    <div class="ct-modal-card" onclick="event.stopPropagation()">
        <div class="ct-modal-head">
            <h5 id="ctModalTitle">Создать тип карты</h5>
            <button type="button" class="ct-modal-close" onclick="document.getElementById('cardTypeModal').style.display='none'">&#10005;</button>
        </div>
        <form id="cardTypeForm" method="POST" action="{{ route('club.cardTypes.store') }}">
            @csrf
            <input type="hidden" name="_method" id="ctMethod" value="POST">
            <div class="ct-modal-body">
                <div id="ctIssuedNote" class="ct-issued-note" style="display:none;"></div>
                <div class="ct-field">
                    <label>Название *</label>
                    <input type="text" name="name" id="ctName" required placeholder="Напр.: 10 посещений">
                </div>
                <div class="ct-field">
                    <label>Что даёт карта</label>
                    <textarea name="description" id="ctDesc" rows="4" maxlength="2000"
                              placeholder="Напр.: 10 часов корта в любое время, аренда ракетки бесплатно, можно приводить гостя"></textarea>
                    <div class="ct-hint">Виден владельцу карты в приложении. Каждая строка показывается отдельным пунктом.</div>
                </div>
                <div class="ct-field">
                    <label>Префикс номера карты *</label>
                    <input type="text" name="code_prefix" id="ctPrefix" required maxlength="12"
                           placeholder="Напр.: VIP" style="text-transform:uppercase;"
                           oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,''); ctPrefixPreview()">
                    <div class="ct-hint">Номера карт этого типа будут <b id="ctPrefixPreviewBox">VIP000001</b>, далее по порядку.</div>
                </div>
                <div class="ct-field">
                    <label>Вид карты *</label>
                    <select name="kind" id="ctKind" onchange="ctToggleKind()">
                        <option value="visits">Посещения корта</option>
                        <option value="trainer">Занятия с тренером</option>
                        <option value="discount_court">Скидка на корт</option>
                        <option value="discount_trainer">Скидка на тренера</option>
                    </select>
                </div>
                <div class="ct-field" id="ctNominalField">
                    <label>Номинал (число часов)</label>
                    <input type="number" name="nominal" id="ctNominal" min="1" max="10000" value="10">
                </div>
                <div class="ct-field" id="ctDiscountField" style="display:none;">
                    <label>Скидка, %</label>
                    <input type="number" name="discount_percent" id="ctDiscount" min="1" max="100" value="10">
                </div>
                <div class="ct-field">
                    <label>Стоимость карты, ₸</label>
                    <input type="number" name="price" id="ctPrice" min="0" max="100000000" placeholder="Напр.: 50000">
                </div>
                <div class="ct-field">
                    <label>Срок действия</label>
                    <select id="ctValidityMode" name="validity_mode" onchange="ctToggleValidity()">
                        <option value="forever">Бессрочно</option>
                        <option value="date">До определённой даты</option>
                        <option value="days">N дней с момента выдачи</option>
                    </select>
                </div>
                <div class="ct-field" id="ctDateField" style="display:none;">
                    <label>Действует до (дата)</label>
                    <input type="date" name="default_expires_at" id="ctDate" class="ct-date">
                </div>
                <div class="ct-field" id="ctDaysField" style="display:none;">
                    <label>Срок, дней с момента выдачи</label>
                    <input type="number" name="default_validity_days" id="ctValidity" min="1" max="3650" placeholder="Напр.: 30">
                </div>
            </div>
            <div class="ct-modal-foot">
                <button type="button" class="btn-cancel" onclick="document.getElementById('cardTypeModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<style>
.ct-modal { display:none; position:fixed; inset:0; z-index:2000; align-items:center; justify-content:center; background:rgba(0,0,0,.7); }
.ct-modal-card { background:#111113; border:1px solid #27272a; border-radius:16px; width:460px; max-width:94vw; }
.ct-modal-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #27272a; }
.ct-modal-head h5 { color:#fff; margin:0; font-size:17px; }
.ct-modal-close { background:none; border:none; color:#a1a1aa; font-size:18px; cursor:pointer; }
.ct-modal-body { padding:18px 20px; }
.ct-field { margin-bottom:14px; }
.ct-field label { display:block; color:#a1a1aa; font-size:12px; margin-bottom:6px; }
.ct-field input, .ct-field select, .ct-field textarea { width:100%; background:#18181b; border:1px solid #27272a; border-radius:10px; padding:10px 12px; color:#fff; }
.ct-field textarea { resize:vertical; min-height:76px; font-family:inherit; font-size:14px; line-height:1.5; }
.ct-field input.ct-date { color-scheme: dark; }
.ct-hint { color:#71717a; font-size:11px; margin-top:5px; }
.ct-hint b { color:#a78bfa; font-family:monospace; letter-spacing:1px; }
.ct-modal-foot { display:flex; gap:12px; padding:14px 20px; border-top:1px solid #27272a; }
.btn-cancel { flex:1; background:#27272a; color:#d4d4d8; border:none; border-radius:10px; padding:11px; cursor:pointer; }
.btn-save { flex:2; background:#22c55e; color:#fff; border:none; border-radius:10px; padding:11px; font-weight:700; cursor:pointer; }
.ct-issued-note { background:rgba(251,191,36,.12); border:1px solid rgba(251,191,36,.35); color:#fbbf24; border-radius:10px; padding:10px 12px; font-size:12px; margin-bottom:14px; line-height:1.4; }
.ct-field input:disabled, .ct-field select:disabled, .ct-field textarea:disabled { opacity:.5; cursor:not-allowed; }
</style>

<script>
function ctToggleKind() {
    const kind = document.getElementById('ctKind').value;
    const counter = (kind === 'visits' || kind === 'trainer');
    document.getElementById('ctNominalField').style.display = counter ? '' : 'none';
    document.getElementById('ctDiscountField').style.display = counter ? 'none' : '';
}
function ctPrefixPreview() {
    const p = (document.getElementById('ctPrefix').value || 'XXX');
    document.getElementById('ctPrefixPreviewBox').textContent = p + '000001';
}
function ctToggleValidity() {
    const mode = document.getElementById('ctValidityMode').value;
    document.getElementById('ctDateField').style.display = (mode === 'date') ? '' : 'none';
    document.getElementById('ctDaysField').style.display = (mode === 'days') ? '' : 'none';
}
function openCardTypeModal(t, readOnly) {
    const form = document.getElementById('cardTypeForm');
    if (t) {
        document.getElementById('ctModalTitle').textContent = readOnly ? 'Тип карты — просмотр' : 'Редактировать тип карты';
        form.action = '{{ url("club/card-types") }}/' + t.id;
        document.getElementById('ctMethod').value = 'PUT';
        document.getElementById('ctName').value = t.name || '';
        document.getElementById('ctDesc').value = t.description || '';
        document.getElementById('ctPrefix').value = t.code_prefix || '';
        document.getElementById('ctKind').value = t.kind;
        document.getElementById('ctNominal').value = t.nominal || 10;
        document.getElementById('ctDiscount').value = t.discount_percent || 10;
        document.getElementById('ctPrice').value = t.price || '';
        const dateVal = t.default_expires_at ? String(t.default_expires_at).substring(0, 10) : '';
        let mode = 'forever';
        if (dateVal) mode = 'date';
        else if (t.default_validity_days) mode = 'days';
        document.getElementById('ctValidityMode').value = mode;
        document.getElementById('ctDate').value = dateVal;
        document.getElementById('ctValidity').value = t.default_validity_days || '';
    } else {
        document.getElementById('ctModalTitle').textContent = 'Создать тип карты';
        form.action = '{{ route("club.cardTypes.store") }}';
        document.getElementById('ctMethod').value = 'POST';
        form.reset();
        document.getElementById('ctMethod').value = 'POST';
        document.getElementById('ctValidityMode').value = 'forever';
    }
    ctToggleKind();
    ctToggleValidity();
    ctPrefixPreview();

    const modal = document.getElementById('cardTypeModal');
    const saveBtn = modal.querySelector('.btn-save');
    const cancelBtn = modal.querySelector('.btn-cancel');
    const note = document.getElementById('ctIssuedNote');

    // По типу уже выпущены карты → менять можно только срок действия.
    const issued = (t && t.ui_count) ? Number(t.ui_count) : 0;
    const validityOnly = !readOnly && issued > 0;
    // Описание — просто текст для владельца карты, его правим и после выдачи.
    const validityIds = ['ctDesc', 'ctValidityMode', 'ctDate', 'ctValidity'];

    modal.querySelectorAll('input, select, textarea').forEach(function (el) {
        // Скрытые поля (_token CSRF, _method) не трогаем: disabled-инпут не
        // отправляется с формой — без _token сервер вернёт 419 Page Expired.
        if (el.type === 'hidden') return;
        if (readOnly) { el.disabled = true; return; }
        el.disabled = validityOnly ? !validityIds.includes(el.id) : false;
    });

    if (validityOnly) {
        document.getElementById('ctModalTitle').textContent = 'Редактировать описание и срок';
        note.textContent = 'По типу выпущено карт: ' + issued + '. Менять можно описание и срок действия. Описание сразу увидят все владельцы карт этого типа, а новый срок применится только к новым картам — у выпущенных он не изменится.';
        note.style.display = '';
    } else {
        note.style.display = 'none';
    }

    saveBtn.style.display = readOnly ? 'none' : '';
    cancelBtn.textContent = readOnly ? 'Закрыть' : 'Отмена';

    modal.style.display = 'flex';
}
</script>

{{--
    Перенос брони: дата → корт → свободное время.

    Занятые слоты не прячем, а показываем перечёркнутыми: менеджеру важно
    видеть, что время занято, а не гадать, почему его нет в списке.

    Подключается в оба вида расписания (по кортам и по дням) — правку делать
    здесь, а не копией.
--}}
<div id="transferModal" class="gcancel-modal" style="display:none;">
    <div class="gcancel-box" style="max-width:520px;">
        <h3 class="gcancel-title">Перенести бронь</h3>
        <p class="gcancel-sub" id="transferCurrent">—</p>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <div style="flex:1 1 180px;">
                <label class="gcancel-label" for="transferDate">Дата</label>
                <input type="date" id="transferDate" class="gcancel-textarea" style="resize:none;">
            </div>
            <div style="flex:1 1 180px;">
                <label class="gcancel-label" for="transferCourt">Корт</label>
                <select id="transferCourt" class="gcancel-textarea" style="resize:none;"></select>
            </div>
        </div>

        <div style="margin-top:14px;">
            <label class="gcancel-label" for="transferTime">Время</label>
            <select id="transferTime" class="gcancel-textarea" style="resize:none;"></select>
            <small class="form-hint" id="transferHint" style="display:block;margin-top:6px;color:#71717a;font-size:12px;"></small>
        </div>

        <div id="transferError" style="display:none;color:#ef4444;font-size:13px;margin-top:10px;font-weight:600;"></div>

        <div class="gcancel-actions">
            <button type="button" class="gcancel-btn-secondary" onclick="closeTransfer()">Отмена</button>
            <button type="button" class="gcancel-btn-primary" id="transferSubmit" onclick="submitTransfer()">Перенести</button>
        </div>
    </div>
</div>

<form id="transferForm" method="POST" style="display:none;">
    @csrf
    <input type="hidden" name="date" id="transferFormDate">
    <input type="hidden" name="court_id" id="transferFormCourt">
    <input type="hidden" name="start_time" id="transferFormTime">
</form>

<style>
    .gcancel-btn-primary { flex: 1; padding: 12px; border-radius: 10px; border: none; background: #22c55e; color: #08130c; font-size: 15px; font-weight: 700; cursor: pointer; }
    .gcancel-btn-primary:hover { background: #16a34a; }
    .gcancel-btn-primary:disabled { opacity: .5; cursor: default; }
    /* Кнопка переноса в карточке брони — второстепенная, рядом с отменой. */
    .btn-transfer { flex: 1; padding: 12px; border-radius: 10px; border: 1px solid #27272a; background: #16161a; color: #a1a1aa; font-size: 15px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
    .btn-transfer:hover { border-color: #22c55e; color: #22c55e; }
</style>

<script>
    // Бронь, которую переносим: id для адреса формы и длительность для подписи.
    let transferBooking = null;

    function openTransfer(booking) {
        transferBooking = booking;
        const modal = document.getElementById('transferModal');
        if (!modal || !booking) return;

        document.getElementById('transferCurrent').textContent =
            'Сейчас: ' + (booking.courtName || 'корт') + ', '
            + (booking.dateLabel || booking.date) + ' ' + booking.startTime + '–' + booking.endTime;

        document.getElementById('transferDate').value = booking.date;
        document.getElementById('transferError').style.display = 'none';
        document.getElementById('transferForm').action =
            '{{ url("club/courts/bookings") }}/' + booking.id + '/transfer';

        modal.style.display = 'flex';
        loadTransferSlots();
    }

    function closeTransfer() {
        const modal = document.getElementById('transferModal');
        if (modal) modal.style.display = 'none';
    }

    // Свободное время на выбранную дату: спрашиваем сервер, а не считаем в браузере —
    // расписание могло измениться, пока карточка была открыта.
    async function loadTransferSlots() {
        if (!transferBooking) return;

        const date = document.getElementById('transferDate').value;
        const courtSelect = document.getElementById('transferCourt');
        const timeSelect = document.getElementById('transferTime');
        const hint = document.getElementById('transferHint');

        timeSelect.innerHTML = '<option>загружаем…</option>';
        hint.textContent = '';

        try {
            const resp = await fetch(
                '{{ url("club/courts/bookings") }}/' + transferBooking.id + '/transfer-slots?date=' + date,
                { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
            );
            const data = await resp.json();
            window.__transferCourts = data.courts || [];

            const keep = courtSelect.value || String(transferBooking.courtId || '');
            courtSelect.innerHTML = '';
            window.__transferCourts.forEach(court => {
                const opt = document.createElement('option');
                opt.value = court.id;
                opt.textContent = court.name + ' · свободно ' + court.free_count;
                courtSelect.appendChild(opt);
            });
            if (window.__transferCourts.some(c => String(c.id) === keep)) courtSelect.value = keep;

            renderTransferTimes();
        } catch (e) {
            timeSelect.innerHTML = '';
            hint.textContent = 'Не удалось получить расписание — попробуйте ещё раз.';
        }
    }

    function renderTransferTimes() {
        const courtId = document.getElementById('transferCourt').value;
        const timeSelect = document.getElementById('transferTime');
        const hint = document.getElementById('transferHint');
        const court = (window.__transferCourts || []).find(c => String(c.id) === String(courtId));

        timeSelect.innerHTML = '';
        if (!court) return;

        court.slots.forEach(slot => {
            const opt = document.createElement('option');
            opt.value = slot.time;
            opt.textContent = slot.time + '–' + slot.end + (slot.free ? '' : ' — занято');
            opt.disabled = !slot.free;
            timeSelect.appendChild(opt);
        });

        const firstFree = court.slots.find(s => s.free);
        if (firstFree) {
            timeSelect.value = firstFree.time;
            hint.textContent = 'Длительность сохраняется. Занятое время выбрать нельзя.';
        } else {
            hint.textContent = 'На этом корте в выбранный день всё занято.';
        }

        const submit = document.getElementById('transferSubmit');
        if (submit) submit.disabled = !firstFree;
    }

    function submitTransfer() {
        const date = document.getElementById('transferDate').value;
        const courtId = document.getElementById('transferCourt').value;
        const time = document.getElementById('transferTime').value;
        const error = document.getElementById('transferError');

        if (!date || !courtId || !time) {
            error.textContent = 'Выберите дату, корт и время.';
            error.style.display = 'block';
            return;
        }

        document.getElementById('transferFormDate').value = date;
        document.getElementById('transferFormCourt').value = courtId;
        document.getElementById('transferFormTime').value = time;

        const submit = document.getElementById('transferSubmit');
        submit.disabled = true;
        submit.textContent = 'Переносим…';

        document.getElementById('transferForm').submit();
    }

    document.addEventListener('DOMContentLoaded', function () {
        const dateInput = document.getElementById('transferDate');
        const courtSelect = document.getElementById('transferCourt');
        if (dateInput) dateInput.addEventListener('change', loadTransferSlots);
        if (courtSelect) courtSelect.addEventListener('change', renderTransferTimes);

        const modal = document.getElementById('transferModal');
        if (modal) {
            // Клик по затемнению закрывает окно — как у остальных модалок страницы.
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeTransfer();
            });
        }
    });
</script>

{{-- Секция «Повторение» для модалки бронирования.
     Используется и в schedule.blade.php (день), и в schedule_week.blade.php (неделя).
     Зависит от глобальных JS-функций selectRepeat/selectRepeatUntil/toggleRepeatSection
     и input[name="date"] в текущей форме. --}}
<div class="repeat-section">
    <div class="repeat-toggle-row">
        <label class="repeat-checkbox">
            <input type="checkbox" id="bookRepeatToggle" onchange="toggleRepeatSection()">
            <span>Повторять</span>
        </label>
        <span class="repeat-preview" id="bookRepeatPreview"></span>
    </div>
    <div class="repeat-options" id="bookRepeatOptions" style="display:none;">
        <div class="repeat-row">
            <span class="repeat-label">Как часто</span>
            <div class="repeat-buttons">
                <button type="button" class="repeat-btn active" data-repeat="weekly" onclick="selectRepeat(this)">Раз в неделю</button>
                <button type="button" class="repeat-btn" data-repeat="biweekly" onclick="selectRepeat(this)">Раз в 2 недели</button>
                <button type="button" class="repeat-btn" data-repeat="daily" onclick="selectRepeat(this)">Каждый день</button>
                <button type="button" class="repeat-btn" data-repeat="every_2_days" onclick="selectRepeat(this)">Через день</button>
                <button type="button" class="repeat-btn" data-repeat="custom" onclick="selectRepeat(this)">
                    <i class="bi bi-calendar3"></i> Выбрать даты
                </button>
            </div>
        </div>
        <div class="repeat-row" id="bookRepeatUntilRow">
            <span class="repeat-label">До</span>
            <div class="repeat-buttons">
                <button type="button" class="repeat-until-btn" data-until="week" onclick="selectRepeatUntil(this)">Конец недели</button>
                <button type="button" class="repeat-until-btn" data-until="two_weeks" onclick="selectRepeatUntil(this)">2 недели</button>
                <button type="button" class="repeat-until-btn active" data-until="month" onclick="selectRepeatUntil(this)">Конец месяца</button>
            </div>
        </div>

        {{-- Календарь показывает занятость корта именно на то время, которое
             выбрано в брони: админ сразу видит, куда повтор поставить можно,
             а куда нет. --}}
        <div class="repeat-calendar" id="bookRepeatCalendar" style="display:none;">
            <div class="rc-head">
                <button type="button" class="rc-nav" onclick="repeatCalShift(-1)" title="Предыдущий месяц">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <span class="rc-title" id="bookRepeatCalTitle"></span>
                <button type="button" class="rc-nav" onclick="repeatCalShift(1)" title="Следующий месяц">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>
            <div class="rc-weekdays">
                <span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span>
            </div>
            <div class="rc-grid" id="bookRepeatCalGrid"></div>
            <div class="rc-legend">
                <span><i class="rc-dot rc-dot-free"></i>свободно</span>
                <span><i class="rc-dot rc-dot-busy"></i>занято в это время</span>
                <span><i class="rc-dot rc-dot-picked"></i>выбрано</span>
                <span class="rc-time" id="bookRepeatCalTime"></span>
            </div>
        </div>
    </div>
    <input type="hidden" name="repeat" id="bookRepeatInput" value="none">
    <input type="hidden" name="repeat_until" id="bookRepeatUntilInput" value="month">
    <div id="bookRepeatDatesInputs"></div>
</div>

<style>
.repeat-section {
    padding: 12px 18px;
    border-top: 1px solid #2a2a2a;
    margin-top: 8px;
}
.repeat-toggle-row {
    display: flex; justify-content: space-between; align-items: center;
}
.repeat-checkbox {
    display: flex; align-items: center; gap: 10px; cursor: pointer;
    color: #f3f3f5; font-size: 14px; font-weight: 600; user-select: none;
}
.repeat-checkbox input[type="checkbox"] { width: 16px; height: 16px; accent-color: #22c55e; cursor: pointer; }
.repeat-preview { color: #22c55e; font-weight: 700; font-size: 13px; }
.repeat-options { display: flex; flex-direction: column; gap: 12px; margin-top: 14px; }
.repeat-row { display: flex; flex-direction: column; gap: 6px; }
.repeat-label { color: #9ca3af; font-size: 11px; font-weight: 600; letter-spacing: 0.4px; text-transform: uppercase; }
.repeat-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
.repeat-btn, .repeat-until-btn {
    padding: 7px 12px; border-radius: 8px; background: transparent;
    border: 1px solid #3a3a3a; color: #d1d5db; font-size: 12.5px;
    font-weight: 600; cursor: pointer; transition: all .15s;
}
.repeat-btn:hover, .repeat-until-btn:hover { border-color: #22c55e; color: #f3f3f5; }
.repeat-btn.active, .repeat-until-btn.active {
    background: rgba(34,197,94,0.15); border-color: #22c55e; color: #22c55e;
}

/* Календарь выбора дат */
.repeat-calendar {
    margin-top: 4px; padding: 12px; border: 1px solid #2f2f2f;
    border-radius: 10px; background: #141414; max-width: 340px;
}
.rc-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.rc-title { color: #f3f3f5; font-size: 13px; font-weight: 700; text-transform: capitalize; }
.rc-nav {
    width: 26px; height: 26px; border-radius: 7px; background: #1e1e1e;
    border: 1px solid #333; color: #d1d5db; cursor: pointer; line-height: 1;
}
.rc-nav:hover { border-color: #22c55e; color: #22c55e; }
.rc-weekdays, .rc-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
.rc-weekdays span {
    text-align: center; color: #6b7280; font-size: 10px;
    font-weight: 700; text-transform: uppercase; padding-bottom: 4px;
}
.rc-day {
    height: 32px; border-radius: 7px; border: 1px solid transparent;
    background: #1c1c1c; color: #d1d5db; font-size: 12px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    position: relative; transition: all .12s;
}
.rc-day.rc-empty { background: transparent; cursor: default; }
.rc-day.rc-free { color: #86efac; }
.rc-day.rc-free:hover { border-color: #22c55e; }
/* Занято — кликнуть нельзя: бронь всё равно не создастся. */
.rc-day.rc-busy { color: #7f1d1d; background: rgba(239,68,68,0.10); cursor: not-allowed; }
.rc-day.rc-past { color: #4b5563; background: transparent; cursor: not-allowed; }
.rc-day.rc-picked { background: rgba(34,197,94,0.22); border-color: #22c55e; color: #22c55e; }
/* Дата самой брони — выбрана всегда, снять нельзя. */
.rc-day.rc-origin { background: rgba(34,197,94,0.35); border-color: #22c55e; color: #dcfce7; cursor: default; }
.rc-legend {
    display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;
    color: #9ca3af; font-size: 10.5px; align-items: center;
}
.rc-legend span { display: inline-flex; align-items: center; gap: 4px; }
.rc-dot { width: 8px; height: 8px; border-radius: 3px; display: inline-block; }
.rc-dot-free { background: #86efac; }
.rc-dot-busy { background: rgba(239,68,68,0.55); }
.rc-dot-picked { background: #22c55e; }
.rc-time { margin-left: auto; color: #22c55e; font-weight: 700; }
</style>

<script>
(function() {
    let _currentRepeat = 'weekly';
    let _currentRepeatUntil = 'month';

    window.toggleRepeatSection = function() {
        const on = document.getElementById('bookRepeatToggle').checked;
        document.getElementById('bookRepeatOptions').style.display = on ? 'flex' : 'none';
        document.getElementById('bookRepeatInput').value = on ? _currentRepeat : 'none';
        updateRepeatPreview();
    };

    window.selectRepeat = function(btn) {
        document.querySelectorAll('.repeat-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        _currentRepeat = btn.dataset.repeat;
        if (document.getElementById('bookRepeatToggle').checked) {
            document.getElementById('bookRepeatInput').value = _currentRepeat;
        }

        // «Выбрать даты» — вместо срока показываем календарь: срок там ни к чему,
        // даты админ отмечает руками.
        const custom = _currentRepeat === 'custom';
        document.getElementById('bookRepeatUntilRow').style.display = custom ? 'none' : 'flex';
        document.getElementById('bookRepeatCalendar').style.display = custom ? 'block' : 'none';
        if (custom) openRepeatCalendar();

        updateRepeatPreview();
    };

    window.selectRepeatUntil = function(btn) {
        document.querySelectorAll('.repeat-until-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        _currentRepeatUntil = btn.dataset.until;
        document.getElementById('bookRepeatUntilInput').value = _currentRepeatUntil;
        updateRepeatPreview();
    };

    function updateRepeatPreview() {
        const on = document.getElementById('bookRepeatToggle').checked;
        const out = document.getElementById('bookRepeatPreview');
        if (!on) { out.textContent = ''; return; }
        const startStr = bookingDate();
        if (!startStr) { out.textContent = ''; return; }

        // В режиме календаря считаем отмеченные даты: сама бронь плюс выбранные.
        const count = _currentRepeat === 'custom'
            ? _pickedDates.size + 1
            : computeRepeatDates(startStr, _currentRepeat, _currentRepeatUntil).length;
        const word = pluralize(count, ['бронирование', 'бронирования', 'бронирований']);
        out.textContent = `${count} ${word}`;
    }

    function bookingDate() {
        return document.querySelector('#bookForm input[name="date"]')?.value || '';
    }

    function computeRepeatDates(startStr, repeat, until) {
        const start = new Date(startStr + 'T00:00:00');
        if (isNaN(start)) return [];
        const end = computeEndDate(start, until);
        const stepMap = { daily: 1, every_2_days: 2, weekly: 7, biweekly: 14 };
        const step = stepMap[repeat] || 7;
        const dates = [];
        const cursor = new Date(start);
        let i = 0;
        while (cursor <= end && i < 60) {
            dates.push(new Date(cursor));
            cursor.setDate(cursor.getDate() + step);
            i++;
        }
        return dates;
    }

    function computeEndDate(start, until) {
        if (until === 'week') {
            // end of week (Sunday)
            const day = start.getDay() || 7; // 1..7 (Mon..Sun)
            const end = new Date(start);
            end.setDate(start.getDate() + (7 - day));
            return end;
        }
        if (until === 'two_weeks') {
            const end = new Date(start);
            end.setDate(start.getDate() + 13);
            return end;
        }
        // month
        return new Date(start.getFullYear(), start.getMonth() + 1, 0);
    }

    function pluralize(n, forms) {
        const mod10 = n % 10, mod100 = n % 100;
        if (mod10 === 1 && mod100 !== 11) return forms[0];
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return forms[1];
        return forms[2];
    }

    // ── Календарь выбора дат ──────────────────────────────────────────
    // Занятость приходит с сервера на то же время, что и сама бронь: считать
    // её в браузере нельзя — расписание знает про блокировки и часы работы.
    let _pickedDates = new Set();   // 'YYYY-MM-DD'
    let _calMonth = null;           // первое число показанного месяца
    let _dayStatus = {};            // 'YYYY-MM-DD' => free | busy | past
    let _loadToken = 0;

    function openRepeatCalendar() {
        const start = bookingDate();
        if (!start) return;
        const d = new Date(start + 'T00:00:00');
        _calMonth = new Date(d.getFullYear(), d.getMonth(), 1);
        loadMonthAvailability();
    }

    window.repeatCalShift = function(delta) {
        if (!_calMonth) return;
        _calMonth = new Date(_calMonth.getFullYear(), _calMonth.getMonth() + delta, 1);
        loadMonthAvailability();
    };

    function loadMonthAvailability() {
        const ctx = calendarContext();
        if (!ctx) return;

        const from = fmt(_calMonth);
        const to = fmt(new Date(_calMonth.getFullYear(), _calMonth.getMonth() + 1, 0));
        renderCalendar(true);

        // Ответы могут прийти не в том порядке, в каком листали месяцы.
        const token = ++_loadToken;
        const url = `${ctx.availability}?start_time=${encodeURIComponent(ctx.time)}`
            + `&slots=${ctx.slots}&from=${from}&to=${to}`;

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : Promise.reject(r.status))
            .then(data => {
                if (token !== _loadToken) return;
                _dayStatus = data.days || {};
                const timeOut = document.getElementById('bookRepeatCalTime');
                if (timeOut) timeOut.textContent = `${data.start_time}–${data.end_time}`;
                renderCalendar(false);
            })
            .catch(() => {
                if (token !== _loadToken) return;
                _dayStatus = {};
                renderCalendar(false);
            });
    }

    function calendarContext() {
        // courtRoutes и currentBook объявлены в самом расписании — партиал
        // подключается внутрь обоих видов и берёт их оттуда.
        if (typeof courtRoutes === 'undefined' || typeof currentBook === 'undefined') return null;
        const routes = courtRoutes[currentBook.courtId];
        if (!routes || !routes.availability) return null;

        return {
            availability: routes.availability,
            time: document.getElementById('bookStartTime')?.value || currentBook.time,
            slots: parseInt(document.getElementById('bookSlots')?.value, 10) || 1,
        };
    }

    function renderCalendar(loading) {
        const grid = document.getElementById('bookRepeatCalGrid');
        const title = document.getElementById('bookRepeatCalTitle');
        if (!grid || !_calMonth) return;

        const MONTHS = ['январь','февраль','март','апрель','май','июнь',
                        'июль','август','сентябрь','октябрь','ноябрь','декабрь'];
        title.textContent = `${MONTHS[_calMonth.getMonth()]} ${_calMonth.getFullYear()}`;

        const first = new Date(_calMonth.getFullYear(), _calMonth.getMonth(), 1);
        const daysInMonth = new Date(_calMonth.getFullYear(), _calMonth.getMonth() + 1, 0).getDate();
        const lead = (first.getDay() + 6) % 7; // неделя с понедельника
        const origin = bookingDate();
        const todayKey = fmt(new Date());

        let html = '';
        for (let i = 0; i < lead; i++) html += '<div class="rc-day rc-empty"></div>';

        for (let day = 1; day <= daysInMonth; day++) {
            const key = fmt(new Date(_calMonth.getFullYear(), _calMonth.getMonth(), day));
            // Прошедшие дни определяем сами: если ответа по дню нет, он не должен
            // выглядеть занятым — просто недоступен.
            const status = loading ? '' : (key < todayKey ? 'past' : (_dayStatus[key] || 'past'));
            let cls = 'rc-day';
            let attr = '';

            if (key === origin) {
                cls += ' rc-origin';
                attr = ' title="Дата самой брони"';
            } else if (loading) {
                cls += ' rc-past';
            } else if (status === 'past') {
                cls += ' rc-past';
                attr = ' title="Дата уже прошла"';
            } else if (status === 'busy') {
                cls += ' rc-busy';
                attr = ' title="В это время корт занят"';
            } else {
                cls += _pickedDates.has(key) ? ' rc-free rc-picked' : ' rc-free';
                attr = ` onclick="repeatCalToggle('${key}')" title="Свободно — нажмите, чтобы добавить"`;
            }

            html += `<div class="${cls}"${attr}>${day}</div>`;
        }

        grid.innerHTML = html;
    }

    window.repeatCalToggle = function(key) {
        if (_pickedDates.has(key)) _pickedDates.delete(key); else _pickedDates.add(key);
        syncPickedInputs();
        renderCalendar(false);
        updateRepeatPreview();
    };

    function syncPickedInputs() {
        const box = document.getElementById('bookRepeatDatesInputs');
        if (!box) return;
        box.innerHTML = '';
        _pickedDates.forEach(date => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'repeat_dates[]';
            input.value = date;
            box.appendChild(input);
        });
    }

    function fmt(d) {
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${d.getFullYear()}-${m}-${day}`;
    }

    // Длительность брони меняют уже после выбора дат — занятость надо перечитать.
    window.refreshRepeatCalendar = function() {
        if (_currentRepeat === 'custom' && document.getElementById('bookRepeatToggle')?.checked) {
            loadMonthAvailability();
        }
    };

    // Сброс при открытии модалки (вызывается извне после установки даты)
    window.resetRepeatSection = function() {
        const toggle = document.getElementById('bookRepeatToggle');
        if (!toggle) return;
        toggle.checked = false;
        document.getElementById('bookRepeatOptions').style.display = 'none';
        document.getElementById('bookRepeatInput').value = 'none';
        document.getElementById('bookRepeatUntilInput').value = 'month';
        document.getElementById('bookRepeatPreview').textContent = '';
        _currentRepeat = 'weekly';
        _currentRepeatUntil = 'month';
        document.querySelectorAll('.repeat-btn').forEach(b => b.classList.toggle('active', b.dataset.repeat === 'weekly'));
        document.querySelectorAll('.repeat-until-btn').forEach(b => b.classList.toggle('active', b.dataset.until === 'month'));

        // Календарь и отмеченные даты — тоже с чистого листа.
        _pickedDates = new Set();
        _dayStatus = {};
        _calMonth = null;
        syncPickedInputs();
        document.getElementById('bookRepeatUntilRow').style.display = 'flex';
        document.getElementById('bookRepeatCalendar').style.display = 'none';
    };
})();
</script>

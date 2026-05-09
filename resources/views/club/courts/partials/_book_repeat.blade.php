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
            </div>
        </div>
        <div class="repeat-row">
            <span class="repeat-label">До</span>
            <div class="repeat-buttons">
                <button type="button" class="repeat-until-btn" data-until="week" onclick="selectRepeatUntil(this)">Конец недели</button>
                <button type="button" class="repeat-until-btn" data-until="two_weeks" onclick="selectRepeatUntil(this)">2 недели</button>
                <button type="button" class="repeat-until-btn active" data-until="month" onclick="selectRepeatUntil(this)">Конец месяца</button>
            </div>
        </div>
    </div>
    <input type="hidden" name="repeat" id="bookRepeatInput" value="none">
    <input type="hidden" name="repeat_until" id="bookRepeatUntilInput" value="month">
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
        const dateInput = document.querySelector('#bookForm input[name="date"]');
        const startStr = dateInput?.value;
        if (!startStr) { out.textContent = ''; return; }
        const dates = computeRepeatDates(startStr, _currentRepeat, _currentRepeatUntil);
        const word = pluralize(dates.length, ['бронирование', 'бронирования', 'бронирований']);
        out.textContent = `${dates.length} ${word}`;
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
    };
})();
</script>

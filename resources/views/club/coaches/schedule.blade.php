@extends('layouts.app')
@section('title', 'Расписание — ' . $clubCoach->user->full_name)
@section('content')

@php
    $dateCarbon = \Carbon\Carbon::parse($date);
    $monthNames = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    $formattedDate = $dateCarbon->format('d') . ' ' . $monthNames[(int)$dateCarbon->format('m')] . ' ' . $dateCarbon->format('Y');
    $existingSchedules = $clubCoach->schedules->groupBy('day_of_week');
@endphp

<div class="coach-schedule-container">
    <!-- Header -->
    <div class="coach-schedule-header">
        <div class="header-left">
            <a href="{{ route('club.coaches.index') }}" class="back-link">&#8249;</a>
            <div>
                <h1 class="page-title">{{ $clubCoach->user->full_name }}</h1>
                <p class="page-subtitle">Расписание тренера</p>
            </div>
        </div>
        <button class="btn-settings" data-bs-toggle="modal" data-bs-target="#settingsModal">
            <i class="bi bi-gear"></i> Настройки графика
        </button>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    <!-- Week Navigation -->
    <div class="week-nav">
        <div class="week-nav-tools">
            <input type="text" id="datePicker" value="{{ $date }}" class="date-picker-input" readonly>
            @if($date !== now()->format('Y-m-d'))
                <a href="{{ route('club.coaches.schedule', ['user' => $clubCoach->user_id]) }}" class="today-btn">Сегодня</a>
            @endif
        </div>
        <div class="week-nav-days">
            <a href="{{ route('club.coaches.schedule', ['user' => $clubCoach->user_id, 'date' => $prevWeek]) }}" class="date-btn">&#8249;</a>
            <div class="week-days">
                @foreach($weekDays as $wd)
                    <a href="{{ route('club.coaches.schedule', ['user' => $clubCoach->user_id, 'date' => $wd['date']]) }}"
                       class="week-day-btn{{ $wd['isSelected'] ? ' active' : '' }}{{ $wd['isToday'] ? ' today' : '' }}">
                        <span class="week-day-name">{{ $wd['dayName'] }}</span>
                        <span class="week-day-num">{{ $wd['dayNum'] }} {{ $wd['month'] }}</span>
                    </a>
                @endforeach
            </div>
            <a href="{{ route('club.coaches.schedule', ['user' => $clubCoach->user_id, 'date' => $nextWeek]) }}" class="date-btn">&#8250;</a>
        </div>
    </div>

    <!-- Schedule Table -->
    @if(count($timeSlots) > 0)
    <div class="schedule-wrap">
        <table class="schedule-table">
            <thead>
                <tr>
                    <th class="time-col">Время</th>
                    <th>{{ $clubCoach->user->full_name }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($timeSlots as $time)
                    @php $slot = $schedule[$time] ?? ['status' => 'free']; @endphp
                    <tr>
                        <td class="time-cell">{{ $time }}</td>
                        <td>
                            @if($slot['status'] === 'booked')
                                @php
                                    $b = $slot['booking'];
                                    $sM = \Carbon\Carbon::parse($b->start_time)->hour * 60 + \Carbon\Carbon::parse($b->start_time)->minute;
                                    $eM = \Carbon\Carbon::parse($b->end_time)->hour * 60 + \Carbon\Carbon::parse($b->end_time)->minute;
                                    if ($eM <= $sM) $eM += 1440;
                                    $bkH = ($eM - $sM) / 60;
                                    if ($b->coach_price !== null) {
                                        // Зафиксированная сумма (замороженная при проведении / ручная).
                                        $coachPay = (float) $b->coach_price;
                                    } elseif ($b->booking_type === 'group' && $clubCoach->rate_group !== null) {
                                        // Группа ещё не проведена — прикидка по текущей групповой ставке.
                                        $coachPay = (float) $clubCoach->rate_group * $bkH;
                                    } else {
                                        $coachPay = $clubCoach->getRateForHours((int) $bkH);
                                    }
                                @endphp
                                <div class="slot slot-booked" onclick="viewBooking('{{ addslashes($slot['booking']->court->name ?? '') }}', '{{ \Carbon\Carbon::parse($slot['booking']->start_time)->format('H:i') }}', '{{ \Carbon\Carbon::parse($slot['booking']->end_time)->format('H:i') }}', '{{ addslashes($slot['booking']->client_name ?? '') }}')">
                                    <span class="slot-client">{{ $slot['booking']->client_name ?? 'Бронь' }}</span>
                                    <span class="slot-court">{{ $slot['booking']->court->name ?? '' }}</span>
                                    @if($coachPay > 0)<span class="slot-price">{{ number_format($coachPay, 0, '', ' ') }} &#8376;</span>@endif
                                </div>
                            @elseif($slot['status'] === 'blocked')
                                <div class="slot slot-blocked" onclick="openUnblockModal({{ $slot['block']->id }}, '{{ $time }}', '{{ addslashes($slot['block']->reason ?? '') }}')">
                                    <span class="slot-reason">{{ $slot['block']->reason ?? 'Занят' }}</span>
                                </div>
                            @else
                                <div class="slot slot-free" onclick="openBlockModal('{{ $time }}')">
                                    @if($clubCoach->hourly_rate)
                                        <span class="slot-price">{{ number_format($clubCoach->hourly_rate, 0, '', ' ') }} &#8376;</span>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="empty-state">
        <p>Нет рабочих часов на этот день</p>
        <button class="btn-settings" data-bs-toggle="modal" data-bs-target="#settingsModal">
            <i class="bi bi-gear"></i> Настроить график
        </button>
    </div>
    @endif

    <!-- Legend -->
    <div class="legend">
        <div class="legend-item"><span class="legend-dot free"></span>Свободен</div>
        <div class="legend-item"><span class="legend-dot booked"></span>На тренировке</div>
        <div class="legend-item"><span class="legend-dot blocked"></span>Занят</div>
    </div>
</div>

<!-- Block Modal -->
<div class="modal fade" id="blockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-dark">
            <div class="sch-modal-header">
                <h2>Заблокировать слот</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <form id="blockForm" action="{{ route('club.coaches.blockSlot', $clubCoach->user_id) }}" method="POST">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                <input type="hidden" name="start_time" id="blockStartTime">
                <input type="hidden" name="end_time" id="blockEndTime">
                <div class="sch-modal-body">
                    <div class="sch-modal-info">
                        <div class="sch-modal-info-row">
                            <span class="sch-modal-info-label">Время</span>
                            <span class="sch-modal-info-value" id="blockTimeDisplay"></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Причина (необязательно)</label>
                        <input type="text" name="reason" class="form-input" placeholder="Тренировка в другом клубе, личные дела...">
                    </div>
                </div>
                <div class="sch-modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn-danger-custom">Заблокировать</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Unblock Modal -->
<div class="modal fade" id="unblockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-dark">
            <div class="sch-modal-header">
                <h2>Разблокировать слот</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <div class="sch-modal-body">
                <div class="sch-modal-info">
                    <div class="sch-modal-info-row">
                        <span class="sch-modal-info-label">Время</span>
                        <span class="sch-modal-info-value" id="unblockTimeDisplay"></span>
                    </div>
                    <div class="sch-modal-info-row" id="unblockReasonRow" style="display:none;">
                        <span class="sch-modal-info-label">Причина</span>
                        <span class="sch-modal-info-value" id="unblockReasonDisplay"></span>
                    </div>
                </div>
            </div>
            <div class="sch-modal-footer">
                <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                <form id="unblockForm" method="POST">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-confirm">Разблокировать</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- View Booking Modal -->
<div class="modal fade" id="viewBookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-dark">
            <div class="sch-modal-header">
                <h2>Тренировка</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <div class="sch-modal-body">
                <div class="sch-modal-info">
                    <div class="sch-modal-info-row">
                        <span class="sch-modal-info-label">Корт</span>
                        <span class="sch-modal-info-value" id="vbCourtName"></span>
                    </div>
                    <div class="sch-modal-info-row">
                        <span class="sch-modal-info-label">Время</span>
                        <span class="sch-modal-info-value" id="vbTime"></span>
                    </div>
                    <div class="sch-modal-info-row">
                        <span class="sch-modal-info-label">Клиент</span>
                        <span class="sch-modal-info-value" id="vbClient"></span>
                    </div>
                </div>
            </div>
            <div class="sch-modal-footer">
                <button type="button" class="btn-cancel" data-bs-dismiss="modal" style="flex:1;">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<!-- Settings Modal (график) -->
<div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-dark">
            <div class="sch-modal-header">
                <h2>Настройки графика</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <form id="scheduleForm" action="{{ route('club.coaches.updateSchedule', $clubCoach->user_id) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="sch-modal-body">
                    <p class="settings-hint">Отметьте рабочие дни и укажите часы работы. Можно добавить несколько интервалов в день (например, утро и вечер с перерывом).</p>
                    @for($day = 1; $day <= 7; $day++)
                        @php $daySchedules = $existingSchedules->get($day); $hasDay = $daySchedules && $daySchedules->count() > 0; @endphp
                        <div class="day-row">
                            <label class="day-check">
                                <input type="checkbox" class="day-checkbox" data-day="{{ $day }}" {{ $hasDay ? 'checked' : '' }} onchange="toggleDay({{ $day }})">
                                <span>{{ $dayNames[$day] }}</span>
                            </label>
                            <div class="day-times" id="dayTimes{{ $day }}" style="{{ $hasDay ? '' : 'display:none' }}">
                                <div class="day-intervals" id="dayIntervals{{ $day }}">
                                    @if($hasDay)
                                        @foreach($daySchedules->sortBy('start_time') as $s)
                                            <div class="interval-row">
                                                <input type="time" class="form-input time-input interval-start" value="{{ \Carbon\Carbon::parse($s->start_time)->format('H:i') }}">
                                                <span class="time-dash">—</span>
                                                <input type="time" class="form-input time-input interval-end" value="{{ \Carbon\Carbon::parse($s->end_time)->format('H:i') }}">
                                                <button type="button" class="btn-interval-remove" onclick="removeInterval(this)" title="Удалить интервал">&#10005;</button>
                                            </div>
                                        @endforeach
                                    @else
                                        <div class="interval-row">
                                            <input type="time" class="form-input time-input interval-start" value="09:00">
                                            <span class="time-dash">—</span>
                                            <input type="time" class="form-input time-input interval-end" value="18:00">
                                            <button type="button" class="btn-interval-remove" onclick="removeInterval(this)" title="Удалить интервал">&#10005;</button>
                                        </div>
                                    @endif
                                </div>
                                <button type="button" class="btn-interval-add" onclick="addInterval({{ $day }})">+ Добавить интервал</button>
                            </div>
                        </div>
                    @endfor
                    <div id="scheduleInputs"></div>

                    <hr style="border-color: #27272a; margin: 20px 0;">
                    <h3 class="settings-section-title">Исключения</h3>
                    @foreach($clubCoach->overrides->sortBy('date') as $ov)
                        <div class="override-row">
                            <span class="override-date">{{ $ov->date->format('d.m.Y') }}</span>
                            <span class="override-badge {{ $ov->is_available ? 'badge-green' : 'badge-red' }}">
                                {{ $ov->is_available ? 'Доп. день' : 'Выходной' }}
                            </span>
                            @if($ov->start_time)
                                <span class="override-time">{{ \Carbon\Carbon::parse($ov->start_time)->format('H:i') }}–{{ \Carbon\Carbon::parse($ov->end_time)->format('H:i') }}</span>
                            @endif
                            @if($ov->reason)
                                <span class="override-reason">{{ $ov->reason }}</span>
                            @endif
                            <button type="button" class="btn-delete-small" style="margin-left:auto;"
                                    onclick="deleteOverride('{{ route('club.coaches.deleteOverride', $ov->id) }}')">&#10005;</button>
                        </div>
                    @endforeach

                    <div class="override-add">
                        <h4 class="override-add-title">Добавить исключение</h4>
                    </div>
                </div>
                <div class="sch-modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn-confirm">Сохранить график</button>
                </div>
            </form>

            <form action="{{ route('club.coaches.addOverride', $clubCoach->user_id) }}" method="POST" class="override-form-bottom">
                @csrf
                <div class="override-form-row">
                    <input type="date" name="date" class="form-input" required style="flex:1;">
                    <select name="is_available" class="form-input" style="flex:1;">
                        <option value="0">Выходной</option>
                        <option value="1">Доп. день</option>
                    </select>
                    <input type="time" name="start_time" class="form-input" placeholder="С" style="flex:0.7;">
                    <input type="time" name="end_time" class="form-input" placeholder="До" style="flex:0.7;">
                    <input type="text" name="reason" class="form-input" placeholder="Причина" style="flex:1.5;">
                    <button type="submit" class="btn-confirm" style="white-space:nowrap;">Добавить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">

<script>
    function openBlockModal(time) {
        const [h, m] = time.split(':').map(Number);
        const endH = h + 1;
        const endTime = String(endH).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        document.getElementById('blockStartTime').value = time;
        document.getElementById('blockEndTime').value = endTime;
        document.getElementById('blockTimeDisplay').textContent = time + ' — ' + endTime;
        new bootstrap.Modal(document.getElementById('blockModal')).show();
    }

    function openUnblockModal(blockId, time, reason) {
        document.getElementById('unblockTimeDisplay').textContent = time;
        document.getElementById('unblockForm').action = '{{ url("club/coaches/block") }}/' + blockId;
        const reasonRow = document.getElementById('unblockReasonRow');
        if (reason) {
            document.getElementById('unblockReasonDisplay').textContent = reason;
            reasonRow.style.display = '';
        } else {
            reasonRow.style.display = 'none';
        }
        new bootstrap.Modal(document.getElementById('unblockModal')).show();
    }

    function viewBooking(courtName, startTime, endTime, clientName) {
        document.getElementById('vbCourtName').textContent = courtName;
        document.getElementById('vbTime').textContent = startTime + ' — ' + endTime;
        document.getElementById('vbClient').textContent = clientName || '—';
        new bootstrap.Modal(document.getElementById('viewBookingModal')).show();
    }

    function toggleDay(day) {
        const checked = document.querySelector('[data-day="' + day + '"]').checked;
        const wrap = document.getElementById('dayTimes' + day);
        wrap.style.display = checked ? 'flex' : 'none';
        // Если день включили, а интервалов нет — добавим один по умолчанию
        if (checked) {
            const intervals = document.getElementById('dayIntervals' + day);
            if (intervals.querySelectorAll('.interval-row').length === 0) {
                addInterval(day, '09:00', '18:00');
            }
        }
    }

    function buildIntervalRow(start, end) {
        const row = document.createElement('div');
        row.className = 'interval-row';
        row.innerHTML =
            '<input type="time" class="form-input time-input interval-start" value="' + start + '">' +
            '<span class="time-dash">—</span>' +
            '<input type="time" class="form-input time-input interval-end" value="' + end + '">' +
            '<button type="button" class="btn-interval-remove" onclick="removeInterval(this)" title="Удалить интервал">&#10005;</button>';
        return row;
    }

    function addInterval(day, start, end) {
        const intervals = document.getElementById('dayIntervals' + day);
        intervals.appendChild(buildIntervalRow(start || '09:00', end || '18:00'));
    }

    function removeInterval(btn) {
        const row = btn.closest('.interval-row');
        const intervals = row.parentElement;
        row.remove();
        // Если у дня не осталось ни одного интервала — снимаем чекбокс «рабочий день»
        if (intervals.querySelectorAll('.interval-row').length === 0) {
            const wrap = intervals.closest('.day-times');
            const cb = wrap.parentElement.querySelector('.day-checkbox');
            if (cb) {
                cb.checked = false;
                wrap.style.display = 'none';
            }
        }
    }

    // Удаление исключения — отдельной формой (НЕ вложенной в scheduleForm,
    // иначе вложенные <form> ломают сохранение графика).
    function deleteOverride(url) {
        if (!confirm('Удалить?')) return;
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = url;
        f.style.display = 'none';
        f.innerHTML = '<input type="hidden" name="_token" value="{{ csrf_token() }}">' +
                      '<input type="hidden" name="_method" value="DELETE">';
        document.body.appendChild(f);
        f.submit();
    }

    document.getElementById('scheduleForm').addEventListener('submit', function() {
        const container = document.getElementById('scheduleInputs');
        container.innerHTML = '';
        let idx = 0;
        for (let day = 1; day <= 7; day++) {
            const cb = document.querySelector('[data-day="' + day + '"]');
            if (cb && cb.checked) {
                const rows = document.getElementById('dayIntervals' + day).querySelectorAll('.interval-row');
                rows.forEach(function(row) {
                    const start = row.querySelector('.interval-start').value;
                    const end = row.querySelector('.interval-end').value;
                    if (!start || !end) return;
                    container.innerHTML += '<input type="hidden" name="schedules[' + idx + '][day_of_week]" value="' + day + '">';
                    container.innerHTML += '<input type="hidden" name="schedules[' + idx + '][start_time]" value="' + start + '">';
                    container.innerHTML += '<input type="hidden" name="schedules[' + idx + '][end_time]" value="' + end + '">';
                    idx++;
                });
            }
        }
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ru.js"></script>
<script>
    flatpickr('#datePicker', {
        locale: 'ru',
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'j F Y',
        defaultDate: '{{ $date }}',
        onChange: function(selectedDates, dateStr) {
            window.location.href = '{{ route("club.coaches.schedule", $clubCoach->user_id) }}?date=' + dateStr;
        }
    });
</script>

<style>
    .coach-schedule-container { width: 100%; padding: 32px 24px; }

    .coach-schedule-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
    .header-left { display: flex; align-items: center; gap: 14px; }
    .back-link { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; text-decoration: none; font-size: 20px; transition: all 0.2s; }
    .back-link:hover { border-color: #22c55e; color: #22c55e; }
    .page-title { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; margin: 0; }
    .page-subtitle { font-size: 13px; color: #71717a; margin: 2px 0 0; }

    .btn-settings { display: flex; align-items: center; gap: 6px; background: #16161a; border: 1px solid #27272a; padding: 10px 18px; border-radius: 10px; color: #a1a1aa; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; }
    .btn-settings:hover { border-color: #3b82f6; color: #3b82f6; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    /* Week Nav (same as courts) */
    .week-nav { margin-bottom: 24px; }
    .week-nav-tools { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
    .date-picker-input { background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 8px 14px; color: #f4f4f5; font-size: 14px; font-weight: 700; cursor: pointer; }
    .today-btn { padding: 7px 16px; background: #16161a; border: 1px solid #27272a; border-radius: 8px; color: #a1a1aa; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.2s; text-decoration: none; }
    .today-btn:hover { border-color: #22c55e; color: #22c55e; }
    .week-nav-days { display: flex; align-items: center; gap: 8px; }
    .date-btn { width: 36px; height: 36px; min-width: 36px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; cursor: pointer; transition: all 0.2s; font-size: 18px; text-decoration: none; }
    .date-btn:hover { border-color: #22c55e; color: #22c55e; }
    .week-days { display: flex; flex: 1; gap: 6px; }
    .week-day-btn { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 10px 4px 8px; background: #111113; border: 1px solid #27272a; border-radius: 14px; text-decoration: none; transition: all 0.2s; cursor: pointer; }
    .week-day-btn:hover { border-color: #3f3f46; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
    .week-day-btn.active { background: linear-gradient(135deg, #22c55e, #16a34a); border-color: transparent; box-shadow: 0 4px 20px rgba(34,197,94,0.3); }
    .week-day-btn.today:not(.active) { border-color: #22c55e; }
    .week-day-btn .week-day-name { font-size: 10px; font-weight: 700; color: #52525b; text-transform: uppercase; }
    .week-day-btn .week-day-num { font-size: 15px; font-weight: 800; color: #d4d4d8; line-height: 1.2; }
    .week-day-btn.active .week-day-name, .week-day-btn.active .week-day-num { color: #fff; }
    .week-day-btn.today:not(.active) .week-day-num { color: #22c55e; }

    /* Schedule Table */
    .schedule-wrap { background: #111113; border: 1px solid #27272a; border-radius: 16px; overflow: hidden; }
    .schedule-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .schedule-table th { padding: 14px 12px; text-align: center; font-size: 13px; font-weight: 800; color: #71717a; background: #16161a; border-bottom: 1px solid #27272a; text-transform: uppercase; letter-spacing: 0.5px; }
    .schedule-table th.time-col { width: 80px; text-align: left; padding-left: 20px; }
    .schedule-table td { padding: 4px; border-bottom: 1px solid #1c1c21; height: 56px; }
    .schedule-table td.time-cell { padding-left: 20px; font-size: 14px; font-weight: 700; color: #a1a1aa; vertical-align: middle; border-right: 1px solid #27272a; }

    .slot { width: 100%; height: 100%; min-height: 52px; border-radius: 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; cursor: pointer; transition: all 0.15s; border: 1px solid transparent; padding: 4px; font-size: 12px; font-weight: 600; }
    .slot-client { font-size: 12px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
    .slot-court { font-size: 10px; opacity: 0.7; }
    .slot-price { font-size: 13px; font-weight: 700; }
    .slot-reason { font-size: 11px; font-weight: 600; }

    .slot-free { background: rgba(34,197,94,0.08); color: #22c55e; border-color: rgba(34,197,94,0.15); }
    .slot-free:hover { background: rgba(34,197,94,0.2); border-color: #22c55e; box-shadow: inset 0 0 0 2px #22c55e; }
    .slot-booked { background: rgba(59,130,246,0.15); color: #3b82f6; border-color: rgba(59,130,246,0.25); }
    .slot-booked:hover { background: rgba(59,130,246,0.25); border-color: #3b82f6; }
    .slot-blocked { background: rgba(251,146,60,0.15); color: #fb923c; border-color: rgba(251,146,60,0.25); }
    .slot-blocked:hover { background: rgba(251,146,60,0.25); border-color: #fb923c; }

    .empty-state { text-align: center; padding: 60px 20px; color: #71717a; }
    .empty-state p { font-size: 16px; margin-bottom: 16px; }

    .legend { display: flex; gap: 24px; margin-top: 16px; flex-wrap: wrap; }
    .legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #a1a1aa; }
    .legend-dot { width: 14px; height: 14px; border-radius: 4px; }
    .legend-dot.free { background: rgba(34,197,94,0.3); border: 1px solid #22c55e; }
    .legend-dot.booked { background: rgba(59,130,246,0.3); border: 1px solid #3b82f6; }
    .legend-dot.blocked { background: rgba(251,146,60,0.3); border: 1px solid #fb923c; }

    /* Modals */
    .modal-dark { background: #111113; border: 1px solid #27272a; border-radius: 16px; }
    .sch-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid #27272a; }
    .sch-modal-header h2 { font-size: 18px; font-weight: 700; margin: 0; }
    .sch-modal-close { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 8px; cursor: pointer; color: #a1a1aa; font-size: 18px; }
    .sch-modal-close:hover { border-color: #ef4444; color: #ef4444; }
    .sch-modal-body { padding: 24px; }
    .sch-modal-info { display: flex; flex-direction: column; gap: 12px; margin-bottom: 16px; }
    .sch-modal-info-row { display: flex; justify-content: space-between; align-items: center; }
    .sch-modal-info-label { font-size: 13px; color: #71717a; font-weight: 600; }
    .sch-modal-info-value { font-size: 14px; font-weight: 700; }
    .sch-modal-footer { display: flex; gap: 12px; padding: 20px 24px; border-top: 1px solid #27272a; }

    .form-group { margin-bottom: 16px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 10px 14px; font-size: 14px; color: #f4f4f5; font-weight: 500; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #22c55e; }

    .btn-cancel { flex: 1; padding: 12px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-confirm { flex: 2; padding: 12px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-confirm:hover { background: #16a34a; }
    .btn-danger-custom { flex: 2; padding: 12px; background: #fb923c; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }

    /* Settings Modal */
    .settings-hint { color: #71717a; font-size: 13px; margin-bottom: 16px; }
    .settings-section-title { font-size: 15px; font-weight: 700; margin-bottom: 12px; }
    .day-row { display: flex; align-items: center; gap: 16px; padding: 10px 0; border-bottom: 1px solid #1c1c21; }
    .day-check { display: flex; align-items: center; gap: 10px; min-width: 150px; font-weight: 600; color: #a1a1aa; cursor: pointer; font-size: 14px; }
    .day-check input[type="checkbox"] { width: 18px; height: 18px; accent-color: #22c55e; }
    .day-times { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
    .day-intervals { display: flex; flex-direction: column; gap: 8px; }
    .interval-row { display: flex; align-items: center; gap: 8px; }
    .time-input { width: 110px !important; }
    .time-dash { color: #52525b; }
    .btn-interval-remove { width: 30px; height: 30px; min-width: 30px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 8px; color: #71717a; cursor: pointer; font-size: 13px; line-height: 1; transition: all 0.2s; }
    .btn-interval-remove:hover { border-color: #ef4444; color: #ef4444; }
    .btn-interval-add { align-self: flex-start; background: rgba(34,197,94,0.08); border: 1px dashed rgba(34,197,94,0.35); border-radius: 8px; color: #22c55e; cursor: pointer; padding: 6px 12px; font-size: 12px; font-weight: 700; transition: all 0.2s; }
    .btn-interval-add:hover { background: rgba(34,197,94,0.18); border-color: #22c55e; }

    .override-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #1c1c21; font-size: 13px; }
    .override-date { font-weight: 700; color: #d4d4d8; min-width: 80px; }
    .override-badge { padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; }
    .badge-green { background: rgba(34,197,94,0.2); color: #22c55e; }
    .badge-red { background: rgba(239,68,68,0.2); color: #ef4444; }
    .override-time { color: #a1a1aa; }
    .override-reason { color: #71717a; font-style: italic; }
    .btn-delete-small { background: none; border: 1px solid #27272a; border-radius: 6px; color: #71717a; cursor: pointer; padding: 4px 8px; font-size: 14px; }
    .btn-delete-small:hover { border-color: #ef4444; color: #ef4444; }

    .override-form-bottom { padding: 0 24px 24px; }
    .override-form-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .override-form-row .form-input { padding: 8px 10px; font-size: 13px; }

    /* Flatpickr overrides */
    .flatpickr-calendar { background: #111113 !important; border: 1px solid #27272a !important; border-radius: 14px !important; box-shadow: 0 8px 32px rgba(0,0,0,0.5) !important; }
    .flatpickr-months, .flatpickr-months .flatpickr-month { background: #111113 !important; }
    .flatpickr-current-month .flatpickr-monthDropdown-months { background: #16161a !important; color: #f4f4f5 !important; }
    .flatpickr-current-month input.cur-year { color: #f4f4f5 !important; }
    span.flatpickr-weekday { color: #a1a1aa !important; background: #111113 !important; font-weight: 700 !important; }
    .flatpickr-innerContainer, .flatpickr-rContainer, .dayContainer, .flatpickr-weekdays { background: #111113 !important; }
    .flatpickr-day { color: #a1a1aa !important; border-radius: 8px !important; border: none !important; }
    .flatpickr-day:hover { background: #27272a !important; color: #f4f4f5 !important; }
    .flatpickr-day.today { border: 2px solid #22c55e !important; color: #22c55e !important; }
    .flatpickr-day.selected { background: #22c55e !important; color: #0a0a0b !important; }
    .flatpickr-day.prevMonthDay, .flatpickr-day.nextMonthDay { color: #3f3f46 !important; }
    .flatpickr-months .flatpickr-prev-month, .flatpickr-months .flatpickr-next-month { color: #a1a1aa !important; fill: #a1a1aa !important; }

    @media (max-width: 768px) {
        .week-nav-days { overflow-x: auto; }
        .week-day-btn { min-width: 52px; }
        .day-row { flex-direction: column; align-items: flex-start; gap: 8px; }
        .override-form-row { flex-direction: column; }
    }
</style>
@endsection

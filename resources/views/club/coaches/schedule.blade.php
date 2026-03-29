@extends('layouts.app')
@section('title', 'Расписание тренера')
@section('content')

@php
    $existingSchedules = $clubCoach->schedules->keyBy('day_of_week');
@endphp

<div class="schedule-container">
    <div class="schedule-header">
        <a href="{{ route('club.coaches.index') }}" class="back-link">&#8592;</a>
        <h1 class="schedule-title">Расписание — {{ $clubCoach->user->full_name }}</h1>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    {{-- Секция 1: Недельный шаблон --}}
    <div class="section-card">
        <h2 class="section-title">Недельное расписание</h2>

        <form id="scheduleForm" action="{{ route('club.coaches.updateSchedule', $clubCoach->user_id) }}" method="POST">
            @csrf
            @method('PUT')

            @for($day = 1; $day <= 7; $day++)
                @php $s = $existingSchedules->get($day); @endphp
                <div class="day-row" id="dayRow{{ $day }}">
                    <label class="day-check">
                        <input type="checkbox" class="day-checkbox" data-day="{{ $day }}"
                               {{ $s ? 'checked' : '' }}
                               onchange="toggleDay({{ $day }})">
                        <span>{{ $dayNames[$day] }}</span>
                    </label>
                    <div class="day-times" id="dayTimes{{ $day }}" style="{{ $s ? '' : 'display:none' }}">
                        <input type="time" class="form-input time-input" id="startTime{{ $day }}" value="{{ $s ? \Carbon\Carbon::parse($s->start_time)->format('H:i') : '09:00' }}">
                        <span class="time-dash">—</span>
                        <input type="time" class="form-input time-input" id="endTime{{ $day }}" value="{{ $s ? \Carbon\Carbon::parse($s->end_time)->format('H:i') : '18:00' }}">
                    </div>
                </div>
            @endfor

            <div id="scheduleInputs"></div>

            <div class="form-actions">
                <button type="submit" class="btn-save">Сохранить расписание</button>
            </div>
        </form>
    </div>

    {{-- Секция 2: Исключения --}}
    <div class="section-card">
        <h2 class="section-title">Исключения (выходные, доп. дни)</h2>

        @if($clubCoach->overrides->count())
            <div class="overrides-list">
                @foreach($clubCoach->overrides->sortBy('date') as $override)
                    <div class="override-item">
                        <div class="override-info">
                            <span class="override-date">{{ \Carbon\Carbon::parse($override->date)->format('d.m.Y') }}</span>
                            @if($override->is_available)
                                <span class="override-badge badge-available">Доп. день</span>
                            @else
                                <span class="override-badge badge-dayoff">Выходной</span>
                            @endif
                            @if($override->start_time && $override->end_time)
                                <span class="override-time">{{ \Carbon\Carbon::parse($override->start_time)->format('H:i') }} — {{ \Carbon\Carbon::parse($override->end_time)->format('H:i') }}</span>
                            @endif
                            @if($override->reason)
                                <span class="override-reason">{{ $override->reason }}</span>
                            @endif
                        </div>
                        <form action="{{ route('club.coaches.deleteOverride', $override) }}" method="POST" style="display:inline;" onsubmit="return confirm('Удалить исключение?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="action-btn delete" title="Удалить">&#10005;</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @else
            <p class="empty-text">Исключений пока нет</p>
        @endif

        <div class="override-form-wrapper">
            <h3 class="subsection-title">Добавить исключение</h3>
            <form action="{{ route('club.coaches.addOverride', $clubCoach->user_id) }}" method="POST" class="override-form">
                @csrf

                <div class="form-group">
                    <label class="form-label">Дата</label>
                    <input type="date" name="date" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Тип</label>
                    <input type="hidden" name="is_available" id="overrideIsAvailable" value="0">
                    <div class="override-type-toggle">
                        <button type="button" class="override-type-btn active" onclick="setOverrideType(0)">Выходной</button>
                        <button type="button" class="override-type-btn" onclick="setOverrideType(1)">Доп. день</button>
                    </div>
                </div>

                <div class="form-group form-group-row">
                    <div>
                        <label class="form-label">Время начала</label>
                        <input type="time" name="start_time" class="form-input time-input">
                    </div>
                    <div>
                        <label class="form-label">Время конца</label>
                        <input type="time" name="end_time" class="form-input time-input">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Причина</label>
                    <input type="text" name="reason" class="form-input" placeholder="Необязательно">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-save">Добавить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .schedule-container { max-width: 800px; margin: 0 auto; padding: 32px 24px; }
    .schedule-header { display: flex; align-items: center; gap: 16px; margin-bottom: 28px; }
    .schedule-title { font-size: 22px; font-weight: 800; color: #f4f4f5; margin: 0; }

    .back-link { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; text-decoration: none; font-size: 20px; transition: all 0.2s; }
    .back-link:hover { border-color: #22c55e; color: #22c55e; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 24px; }
    .flash-success { background: rgba(34,197,94,0.12); color: #22c55e; border: 1px solid rgba(34,197,94,0.25); }
    .flash-error { background: rgba(239,68,68,0.12); color: #ef4444; border: 1px solid rgba(239,68,68,0.25); }

    .section-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; padding: 24px; margin-bottom: 24px; }
    .section-title { font-size: 18px; font-weight: 800; color: #f4f4f5; margin: 0 0 20px 0; }
    .subsection-title { font-size: 15px; font-weight: 700; color: #d4d4d8; margin: 0 0 16px 0; }

    .day-row { display: flex; align-items: center; gap: 16px; padding: 12px 0; border-bottom: 1px solid #1c1c21; }
    .day-row:last-of-type { border-bottom: none; }
    .day-check { display: flex; align-items: center; gap: 10px; min-width: 160px; font-weight: 600; color: #a1a1aa; cursor: pointer; margin: 0; }
    .day-check input[type="checkbox"] { width: 18px; height: 18px; accent-color: #22c55e; cursor: pointer; }
    .day-times { display: flex; align-items: center; gap: 8px; }
    .time-input { width: 120px !important; }
    .time-dash { color: #52525b; font-weight: 600; }

    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-input::placeholder { color: #52525b; }

    .form-actions { margin-top: 20px; }
    .btn-save { width: 100%; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; transition: all 0.2s; }
    .btn-save:hover { background: #16a34a; }

    /* Исключения */
    .overrides-list { margin-bottom: 24px; }
    .override-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #16161a; border: 1px solid #1c1c21; border-radius: 10px; margin-bottom: 8px; }
    .override-info { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .override-date { font-weight: 700; color: #f4f4f5; font-size: 14px; }
    .override-badge { padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; }
    .badge-dayoff { background: rgba(239,68,68,0.15); color: #ef4444; }
    .badge-available { background: rgba(34,197,94,0.15); color: #22c55e; }
    .override-time { color: #a1a1aa; font-size: 13px; font-weight: 500; }
    .override-reason { color: #71717a; font-size: 13px; font-style: italic; }
    .empty-text { color: #52525b; font-size: 14px; margin-bottom: 20px; }

    .action-btn { width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; border-radius: 8px; border: 1px solid #27272a; background: #16161a; color: #a1a1aa; cursor: pointer; font-size: 14px; transition: all 0.2s; }
    .action-btn.delete:hover { border-color: #ef4444; color: #ef4444; background: rgba(239,68,68,0.1); }

    .override-form-wrapper { border-top: 1px solid #1c1c21; padding-top: 20px; }
    .override-form { display: flex; flex-direction: column; gap: 16px; }
    .form-group { display: flex; flex-direction: column; gap: 6px; }
    .form-group-row { flex-direction: row; gap: 12px; }
    .form-group-row > div { flex: 1; display: flex; flex-direction: column; gap: 6px; }
    .form-label { font-size: 13px; font-weight: 600; color: #a1a1aa; }

    .override-type-toggle { display: flex; gap: 8px; }
    .override-type-btn { padding: 10px 20px; border: 1px solid #27272a; border-radius: 10px; background: #16161a; color: #a1a1aa; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; font-family: inherit; }
    .override-type-btn.active { border-color: #22c55e; color: #22c55e; background: rgba(34,197,94,0.1); }
    .override-type-btn:hover { border-color: #3f3f46; }

    @media (max-width: 600px) {
        .schedule-container { padding: 20px 12px; }
        .day-row { flex-direction: column; align-items: flex-start; gap: 8px; }
        .day-check { min-width: auto; }
        .time-input { width: 100px !important; }
        .override-info { gap: 8px; }
    }
</style>

<script>
    function toggleDay(day) {
        const checked = document.querySelector(`[data-day="${day}"]`).checked;
        document.getElementById('dayTimes' + day).style.display = checked ? 'flex' : 'none';
    }

    document.getElementById('scheduleForm').addEventListener('submit', function(e) {
        const container = document.getElementById('scheduleInputs');
        container.innerHTML = '';
        let idx = 0;
        for (let day = 1; day <= 7; day++) {
            const cb = document.querySelector(`[data-day="${day}"]`);
            if (cb.checked) {
                const start = document.getElementById('startTime' + day).value;
                const end = document.getElementById('endTime' + day).value;
                container.innerHTML += `<input type="hidden" name="schedules[${idx}][day_of_week]" value="${day}">`;
                container.innerHTML += `<input type="hidden" name="schedules[${idx}][start_time]" value="${start}">`;
                container.innerHTML += `<input type="hidden" name="schedules[${idx}][end_time]" value="${end}">`;
                idx++;
            }
        }
    });

    function setOverrideType(isAvailable) {
        document.getElementById('overrideIsAvailable').value = isAvailable;
        document.querySelectorAll('.override-type-btn').forEach(b => b.classList.remove('active'));
        event.target.classList.add('active');
    }
</script>

@endsection

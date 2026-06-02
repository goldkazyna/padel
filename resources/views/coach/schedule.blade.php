@extends('layouts.app')
@section('title', 'Расписание')
@section('content')

<div class="coach-schedule-container">
    <!-- Header -->
    <div class="coach-schedule-header">
        <div class="header-left">
            <div>
                <h1 class="page-title">{{ $cc?->user?->full_name ?? 'Моё расписание' }}</h1>
                <p class="page-subtitle">{{ $cc?->club?->name ? $cc->club->name . ' · ' : '' }}Расписание тренера</p>
            </div>
        </div>
        <button type="button" class="btn-settings" data-bs-toggle="modal" data-bs-target="#credModal">
            <i class="bi bi-key"></i> Изменить пароль
        </button>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="flash-message flash-error">
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    @if(!$cc)
        <div class="empty-state">
            <p>Вы пока не привязаны к клубу как тренер. Обратитесь к администратору клуба.</p>
        </div>
    @else
        <!-- Week Navigation -->
        <div class="week-nav">
            <div class="week-nav-tools">
                <input type="text" id="datePicker" value="{{ $date }}" class="date-picker-input" readonly>
                @php $hoursLabel = rtrim(rtrim(number_format($busyHours, 1, '.', ''), '0'), '.'); @endphp
                @if($busyHours > 0)
                    <span class="hours-badge has-hours">{{ $hoursLabel }} ч занятий</span>
                @else
                    <span class="hours-badge">Нет занятий</span>
                @endif
                @if($date !== now()->format('Y-m-d'))
                    <a href="{{ route('coach.schedule') }}" class="today-btn">Сегодня</a>
                @endif
            </div>
            <div class="week-nav-days">
                <a href="{{ route('coach.schedule', ['date' => $prevWeek]) }}" class="date-btn">&#8249;</a>
                <div class="week-days">
                    @foreach($weekDays as $wd)
                        @php $wdHours = rtrim(rtrim(number_format($wd['hours'], 1, '.', ''), '0'), '.'); @endphp
                        <a href="{{ route('coach.schedule', ['date' => $wd['date']]) }}"
                           class="week-day-btn{{ $wd['isSelected'] ? ' active' : '' }}{{ $wd['isToday'] ? ' today' : '' }}">
                            <span class="week-day-name">{{ $wd['dayName'] }}</span>
                            <span class="week-day-num">{{ $wd['dayNum'] }} {{ $wd['month'] }}</span>
                            @if($wd['hours'] > 0)
                                <span class="week-day-hours">{{ $wdHours }} ч</span>
                            @else
                                <span class="week-day-hours empty">—</span>
                            @endif
                        </a>
                    @endforeach
                </div>
                <a href="{{ route('coach.schedule', ['date' => $nextWeek]) }}" class="date-btn">&#8250;</a>
            </div>
        </div>

        <!-- Schedule Table (только занятые слоты) -->
        @php $busySlots = collect($timeSlots)->filter(fn($t) => ($schedule[$t]['status'] ?? 'free') !== 'free'); @endphp
        @if($busySlots->count() > 0)
        <div class="schedule-wrap">
            <table class="schedule-table">
                <thead>
                    <tr>
                        <th class="time-col">Время</th>
                        <th>{{ $cc->user->full_name }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($busySlots as $time)
                        @php $slot = $schedule[$time]; @endphp
                        <tr>
                            <td class="time-cell">{{ $time }}</td>
                            <td>
                                @if($slot['status'] === 'booked')
                                    <div class="slot slot-booked">
                                        <span class="slot-client">{{ $slot['booking']->client_name ?? 'Тренировка' }}</span>
                                        <span class="slot-court">{{ $slot['booking']->court->name ?? '' }}</span>
                                    </div>
                                @else
                                    <div class="slot slot-blocked">
                                        <span class="slot-reason">{{ $slot['block']->reason ?? 'Занят' }}</span>
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
            <p>В этот день занятий нет</p>
        </div>
        @endif

        <!-- Legend -->
        <div class="legend">
            <div class="legend-item"><span class="legend-dot booked"></span>На тренировке</div>
            <div class="legend-item"><span class="legend-dot blocked"></span>Занят</div>
        </div>

        <p class="readonly-hint">Расписание настраивает администратор клуба.</p>
    @endif
</div>

<!-- Модалка: смена пароля -->
<div class="modal fade" id="credModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="modal-header" style="border-bottom: 1px solid #27272a; padding: 20px 24px;">
                <h5 class="modal-title" style="font-weight: 700;">Изменить пароль</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('coach.password') }}" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-body" style="padding: 24px;">
                    <div class="form-group">
                        <label class="form-label">Текущий пароль</label>
                        <input type="password" name="current_password" class="form-input" required autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Новый пароль</label>
                        <input type="password" name="password" class="form-input" required autocomplete="new-password">
                        <small class="form-hint">Минимум 6 символов</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Повторите новый пароль</label>
                        <input type="password" name="password_confirmation" class="form-input" required autocomplete="new-password">
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #27272a; padding: 20px 24px; display:flex; gap:12px;">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn-save">Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</div>

@if($cc)
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
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
            window.location.href = '{{ route("coach.schedule") }}?date=' + dateStr;
        }
    });

    // На мобильных прокручиваем ленту дней так, чтобы выбранный день был по центру
    document.addEventListener('DOMContentLoaded', function () {
        var strip = document.querySelector('.week-days');
        var active = document.querySelector('.week-day-btn.active');
        if (strip && active) {
            strip.scrollLeft = active.offsetLeft - (strip.clientWidth - active.clientWidth) / 2;
        }
    });
</script>
@endif

<style>
    .coach-schedule-container { width: 100%; padding: 32px 24px; }

    .coach-schedule-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
    .header-left { display: flex; align-items: center; gap: 14px; }
    .page-title { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; margin: 0; }
    .page-subtitle { font-size: 13px; color: #71717a; margin: 2px 0 0; }

    .btn-settings { display: flex; align-items: center; gap: 6px; background: #16161a; border: 1px solid #27272a; padding: 10px 18px; border-radius: 10px; color: #a1a1aa; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; }
    .btn-settings:hover { border-color: #f59e0b; color: #f59e0b; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .week-nav { margin-bottom: 24px; }
    .week-nav-tools { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
    .date-picker-input { background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 8px 14px; color: #f4f4f5; font-size: 14px; font-weight: 700; cursor: pointer; }
    .hours-badge { padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 800; background: #16161a; border: 1px solid #27272a; color: #71717a; }
    .hours-badge.has-hours { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.3); color: #3b82f6; }
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
    .week-day-hours { font-size: 11px; font-weight: 800; color: #3b82f6; margin-top: 2px; }
    .week-day-hours.empty { color: #3f3f46; }
    .week-day-btn.active .week-day-hours { color: #fff; }

    .schedule-wrap { background: #111113; border: 1px solid #27272a; border-radius: 16px; overflow: hidden; }
    .schedule-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .schedule-table th { padding: 14px 12px; text-align: center; font-size: 13px; font-weight: 800; color: #71717a; background: #16161a; border-bottom: 1px solid #27272a; text-transform: uppercase; letter-spacing: 0.5px; }
    .schedule-table th.time-col { width: 80px; text-align: left; padding-left: 20px; }
    .schedule-table td { padding: 4px; border-bottom: 1px solid #1c1c21; height: 56px; }
    .schedule-table td.time-cell { padding-left: 20px; font-size: 14px; font-weight: 700; color: #a1a1aa; vertical-align: middle; border-right: 1px solid #27272a; }

    .slot { width: 100%; height: 100%; min-height: 52px; border-radius: 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; transition: all 0.15s; border: 1px solid transparent; padding: 4px; font-size: 12px; font-weight: 600; }
    .slot-client { font-size: 12px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
    .slot-court { font-size: 10px; opacity: 0.7; }
    .slot-price { font-size: 13px; font-weight: 700; }
    .slot-reason { font-size: 11px; font-weight: 600; }
    .slot-free { background: rgba(34,197,94,0.08); color: #22c55e; border-color: rgba(34,197,94,0.15); }
    .slot-booked { background: rgba(59,130,246,0.15); color: #3b82f6; border-color: rgba(59,130,246,0.25); }
    .slot-blocked { background: rgba(251,146,60,0.15); color: #fb923c; border-color: rgba(251,146,60,0.25); }

    .empty-state { text-align: center; padding: 60px 20px; color: #71717a; }
    .empty-state p { font-size: 16px; margin-bottom: 16px; }

    .legend { display: flex; gap: 24px; margin-top: 16px; flex-wrap: wrap; }
    .legend-item { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #a1a1aa; }
    .legend-dot { width: 14px; height: 14px; border-radius: 4px; }
    .legend-dot.free { background: rgba(34,197,94,0.3); border: 1px solid #22c55e; }
    .legend-dot.booked { background: rgba(59,130,246,0.3); border: 1px solid #3b82f6; }
    .legend-dot.blocked { background: rgba(251,146,60,0.3); border: 1px solid #fb923c; }

    .readonly-hint { margin-top: 16px; color: #52525b; font-size: 12px; }

    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-hint { color: #52525b; font-size: 11px; display: block; margin-top: 6px; }
    .btn-cancel { flex: 1; padding: 14px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }

    /* ===== Мобильная адаптация ===== */
    @media (max-width: 640px) {
        .coach-schedule-container { padding: 6px 0 20px; }

        /* Шапка: заголовок уводим вправо от кнопки-гамбургера, кнопка пароля — на всю ширину */
        .coach-schedule-header { flex-direction: column; align-items: stretch; gap: 12px; margin-bottom: 18px; }
        .header-left { padding-left: 56px; min-height: 44px; }
        .page-title { font-size: 18px; }
        .page-subtitle { font-size: 12px; }
        .btn-settings { width: 100%; justify-content: center; padding: 12px; }

        /* Дата + бейдж часов */
        .week-nav-tools { flex-wrap: wrap; gap: 8px; }
        .date-picker-input { flex: 1; min-width: 140px; }

        /* Дни недели — горизонтальная лента (свайп), дни нормального размера */
        .week-nav-days { gap: 6px; }
        .date-btn { width: 32px; min-width: 32px; height: 56px; border-radius: 10px; font-size: 16px; }
        .week-days {
            flex: 1;
            gap: 7px;
            overflow-x: auto;
            scroll-snap-type: x proximity;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            padding-bottom: 2px;
        }
        .week-days::-webkit-scrollbar { display: none; }
        .week-day-btn { flex: 0 0 auto; min-width: 60px; scroll-snap-align: center; padding: 9px 6px 8px; border-radius: 12px; gap: 2px; }
        .week-day-btn .week-day-name { font-size: 10px; }
        .week-day-btn .week-day-num { font-size: 14px; }
        .week-day-hours { font-size: 10px; margin-top: 2px; }

        /* Таблица занятий */
        .schedule-table th { padding: 10px 6px; font-size: 11px; }
        .schedule-table th.time-col { width: 56px; padding-left: 12px; }
        .schedule-table td { height: auto; padding: 3px; }
        .schedule-table td.time-cell { padding-left: 12px; font-size: 13px; }
        .slot { min-height: 46px; }
        .slot-client { font-size: 12px; white-space: normal; }

        .legend { gap: 16px; }
    }
</style>
@endsection

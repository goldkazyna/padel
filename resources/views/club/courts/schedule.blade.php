@extends('layouts.app')
@section('title', 'Расписание кортов')

@section('content')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
<style>
    .flatpickr-calendar {
        background: #111113 !important;
        border: 1px solid #27272a !important;
        border-radius: 14px !important;
        box-shadow: 0 8px 32px rgba(0,0,0,0.5) !important;
        font-family: inherit !important;
    }
    .flatpickr-months {
        background: #111113 !important;
        border-radius: 14px 14px 0 0 !important;
    }
    .flatpickr-months .flatpickr-month {
        background: #111113 !important;
        color: #f4f4f5 !important;
    }
    .flatpickr-current-month .flatpickr-monthDropdown-months {
        background: #16161a !important;
        color: #f4f4f5 !important;
        border: 1px solid #27272a !important;
        border-radius: 6px !important;
    }
    .flatpickr-current-month input.cur-year {
        color: #f4f4f5 !important;
    }
    .flatpickr-months .flatpickr-prev-month,
    .flatpickr-months .flatpickr-next-month {
        color: #a1a1aa !important;
        fill: #a1a1aa !important;
    }
    .flatpickr-months .flatpickr-prev-month:hover,
    .flatpickr-months .flatpickr-next-month:hover {
        color: #22c55e !important;
        fill: #22c55e !important;
    }
    .flatpickr-weekdays {
        background: #111113 !important;
    }
    span.flatpickr-weekday {
        color: #52525b !important;
        font-weight: 700 !important;
        font-size: 12px !important;
    }
    .flatpickr-innerContainer {
        background: #111113 !important;
        border-bottom: none !important;
    }
    .flatpickr-rContainer {
        background: #111113 !important;
    }
    .dayContainer {
        background: #111113 !important;
    }
    .flatpickr-day {
        color: #a1a1aa !important;
        border-radius: 8px !important;
        font-weight: 600 !important;
        border: none !important;
    }
    .flatpickr-day:hover {
        background: #27272a !important;
        color: #f4f4f5 !important;
    }
    .flatpickr-day.today {
        border: 2px solid #22c55e !important;
        color: #22c55e !important;
    }
    .flatpickr-day.today:hover {
        background: rgba(34,197,94,0.15) !important;
        color: #22c55e !important;
    }
    .flatpickr-day.selected {
        background: #22c55e !important;
        color: #0a0a0b !important;
        border: none !important;
    }
    .flatpickr-day.selected:hover {
        background: #16a34a !important;
    }
    .flatpickr-day.prevMonthDay,
    .flatpickr-day.nextMonthDay {
        color: #3f3f46 !important;
    }
    .flatpickr-day.flatpickr-disabled {
        color: #27272a !important;
    }
</style>

@php
    $monthNames = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    $dayNames = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];

    $dateCarbon = \Carbon\Carbon::parse($date);
    $prevDate = $dateCarbon->copy()->subDay()->format('Y-m-d');
    $nextDate = $dateCarbon->copy()->addDay()->format('Y-m-d');
    $today = now()->format('Y-m-d');
    $dayOfWeek = $dayNames[(int)$dateCarbon->format('w')];
    $dayNum = $dateCarbon->day;
    $monthName = $monthNames[(int)$dateCarbon->format('n')];
    $year = $dateCarbon->year;
    $formattedDate = "$dayNum $monthName $year";

    // Pre-calculate which cells to skip (multi-hour bookings)
    $skipCells = [];
    $rowspans = [];

    foreach ($courts as $court) {
        $courtSchedule = $schedules[$court->id] ?? [];
        $times = array_keys($courtSchedule);

        for ($i = 0; $i < count($times); $i++) {
            $slot = $courtSchedule[$times[$i]];
            if ($slot['status'] === 'booked' && $slot['booking']) {
                $booking = $slot['booking'];
                $bookingStartTime = \Carbon\Carbon::parse($booking->start_time)->format('H:i');

                if ($times[$i] === $bookingStartTime) {
                    $bookingEndTime = \Carbon\Carbon::parse($booking->end_time)->format('H:i');
                    $span = 0;
                    for ($j = $i; $j < count($times) && $times[$j] < $bookingEndTime; $j++) {
                        $span++;
                        if ($j > $i) {
                            $skipCells[$court->id . '-' . $times[$j]] = true;
                        }
                    }
                    if ($span > 1) {
                        $rowspans[$court->id . '-' . $times[$i]] = $span;
                    }
                }
            }
        }
    }

    // Pre-calculate max consecutive free slots for each court+time
    $maxFreeSlots = [];
    foreach ($courts as $court) {
        $courtSchedule = $schedules[$court->id] ?? [];
        $times = array_keys($courtSchedule);
        for ($i = 0; $i < count($times); $i++) {
            if ($courtSchedule[$times[$i]]['status'] === 'free') {
                $count = 0;
                for ($j = $i; $j < count($times) && $courtSchedule[$times[$j]]['status'] === 'free'; $j++) {
                    $count++;
                }
                $maxFreeSlots[$court->id . '-' . $times[$i]] = $count;
            }
        }
    }

    // Build prices array for free slots (for duration total calculation in JS)
    $freePrices = [];
    foreach ($courts as $court) {
        $courtSchedule = $schedules[$court->id] ?? [];
        $times = array_keys($courtSchedule);
        foreach ($times as $time) {
            if ($courtSchedule[$time]['status'] === 'free') {
                $freePrices[$court->id . '-' . $time] = $courtSchedule[$time]['price'];
            }
        }
    }
@endphp

<div class="schedule-container">
    <!-- Header -->
    <div class="schedule-header">
        <div class="header-left">
            <h1>Расписание кортов</h1>
        </div>
        <a href="{{ route('club.courts.index') }}" class="settings-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
            Настройки кортов
        </a>
    </div>

    <!-- Flash Messages -->
    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    <!-- Week Navigation -->
    <div class="week-nav">
        <div class="week-nav-tools">
            <div class="date-picker-wrap">
                <i class="bi bi-calendar3" style="color: #a1a1aa; font-size: 14px;"></i>
                <input type="text" id="datePicker" value="{{ $date }}" class="date-picker-input" readonly placeholder="Выберите дату">
            </div>
            @if($date !== now()->format('Y-m-d'))
                <a href="{{ route('club.courts.schedule') }}" class="today-btn">Сегодня</a>
            @endif
        </div>
        <div class="week-nav-days">
            <a href="{{ route('club.courts.schedule', ['date' => $prevWeek]) }}" class="date-btn">&#8249;</a>
            <div class="week-days">
                @foreach($weekDays as $wd)
                    <a href="{{ route('club.courts.schedule', ['date' => $wd['date']]) }}"
                       class="week-day-btn{{ $wd['isSelected'] ? ' active' : '' }}{{ $wd['isToday'] ? ' today' : '' }}">
                        <span class="week-day-name">{{ $wd['dayName'] }}</span>
                        <span class="week-day-num">{{ $wd['dayNum'] }} {{ $wd['month'] }}</span>
                        @if($wd['occupancy'] > 0)
                            <span class="week-day-occ" style="color: {{ $wd['occupancy'] >= 80 ? '#ef4444' : ($wd['occupancy'] >= 40 ? '#fb923c' : '#22c55e') }}">{{ $wd['occupancy'] }}%</span>
                        @endif
                        <div class="week-day-bar"><div class="week-day-bar-fill" style="width:{{ $wd['occupancy'] }}%;background:{{ $wd['occupancy'] >= 80 ? '#ef4444' : ($wd['occupancy'] >= 40 ? '#fb923c' : '#22c55e') }}"></div></div>
                    </a>
                @endforeach
            </div>
            <a href="{{ route('club.courts.schedule', ['date' => $nextWeek]) }}" class="date-btn">&#8250;</a>
        </div>
    </div>

    <!-- Schedule Grid -->
    <div class="schedule-wrap">
        <table class="schedule-table">
            <thead>
                <tr>
                    <th class="time-col">Время</th>
                    @foreach($courts as $court)
                        <th>{{ $court->name }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($timeSlots as $idx => $time)
                    @if($idx > 0)
                        @php
                            $prevMinutes = intval(substr($timeSlots[$idx - 1], 0, 2)) * 60 + intval(substr($timeSlots[$idx - 1], 3, 2));
                            $currMinutes = intval(substr($time, 0, 2)) * 60 + intval(substr($time, 3, 2));
                            $gap = $currMinutes - $prevMinutes;
                        @endphp
                        @if($gap > 60)
                            <tr class="schedule-divider">
                                <td colspan="{{ $courts->count() + 1 }}">
                                    <div class="divider-line"><span>Начало дня</span></div>
                                </td>
                            </tr>
                        @endif
                    @endif
                    <tr>
                        <td class="time-cell">{{ $time }}</td>
                        @foreach($courts as $court)
                            @php
                                $cellKey = $court->id . '-' . $time;
                                $courtSchedule = $schedules[$court->id] ?? [];
                                $slot = $courtSchedule[$time] ?? null;
                            @endphp

                            @if(isset($skipCells[$cellKey]))
                                {{-- Cell is part of a multi-hour booking, skip --}}
                                @continue
                            @endif

                            @if(!$slot)
                                <td><div class="slot slot-empty">&mdash;</div></td>
                            @elseif($slot['status'] === 'free')
                                <td>
                                    <div class="slot slot-free"
                                         onclick="openBookModal('{{ $court->id }}', '{{ addslashes($court->name) }}', '{{ $time }}', {{ $slot['price'] }}, {{ $maxFreeSlots[$cellKey] ?? 1 }})">
                                        <span class="slot-price">{{ number_format($slot['price'], 0, '', ' ') }} &#8376;</span>
                                    </div>
                                </td>
                            @elseif($slot['status'] === 'booked' && $slot['booking'])
                                @php
                                    $booking = $slot['booking'];
                                    $span = $rowspans[$cellKey] ?? 1;
                                    $bStart = \Carbon\Carbon::parse($booking->start_time)->format('H:i');
                                    $bEnd = \Carbon\Carbon::parse($booking->end_time)->format('H:i');
                                    $paidClass = $booking->is_paid ? '' : ' unpaid';
                                    $slotClass = ($span > 1 ? 'slot-booked-multi' : 'slot-booked') . $paidClass;
                                @endphp
                                <td @if($span > 1) rowspan="{{ $span }}" style="padding: 4px;" @endif>
                                    <div class="slot {{ $slotClass }}"
                                         onclick="openViewModal({ id: {{ $booking->id }}, courtName: '{{ addslashes($court->name) }}', startTime: '{{ $bStart }}', endTime: '{{ $bEnd }}', clientName: '{{ addslashes($booking->client_name ?? '') }}', clientPhone: '{{ addslashes($booking->client_phone ?? '') }}', price: {{ $booking->price ?? 0 }}, paymentMethod: '{{ $booking->payment_method ?? '' }}', isPaid: {{ $booking->is_paid ? 'true' : 'false' }}, comment: '{{ addslashes($booking->comment ?? '') }}' })">
                                        <span class="client-name">{{ $booking->client_name ?? 'Бронь' }}@if($booking->client_phone) — {{ $booking->client_phone }}@endif</span>
                                        @if($span > 1)
                                            <span class="slot-time">{{ $bStart }} &mdash; {{ $bEnd }}</span>
                                        @endif
                                        @if($booking->comment)
                                            <span class="slot-comment">{{ $booking->comment }}</span>
                                        @endif
                                        <span class="slot-price">{{ number_format($booking->price ?? 0, 0, '', ' ') }} &#8376;</span>
                                    </div>
                                </td>
                            @elseif($slot['status'] === 'blocked')
                                <td>
                                    <div class="slot slot-blocked"
                                         onclick="openUnblockModal({{ $slot['block']->id ?? 0 }}, '{{ addslashes($court->name) }}', '{{ $time }}')">
                                        <span class="slot-label">Заблокирован</span>
                                    </div>
                                </td>
                            @else
                                <td><div class="slot slot-empty">&mdash;</div></td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- Legend -->
    <div class="legend">
        <div class="legend-item"><span class="legend-dot free"></span>Свободен</div>
        <div class="legend-item"><span class="legend-dot booked"></span>Оплачено</div>
        <div class="legend-item"><span class="legend-dot unpaid"></span>Не оплачено</div>
        <div class="legend-item"><span class="legend-dot blocked"></span>Заблокирован</div>
    </div>
</div>

<!-- Book Modal (Bootstrap 5) -->
<div class="modal fade" id="bookModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="sch-modal-header">
                <h2>Бронирование</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <form id="bookForm" method="POST">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                <input type="hidden" name="start_time" id="bookStartTime">
                <input type="hidden" name="slots" id="bookSlots" value="1">
                <div class="sch-modal-body">
                    <div class="sch-modal-info">
                        <div class="sch-modal-info-row">
                            <span class="sch-modal-info-label">Корт</span>
                            <span class="sch-modal-info-value" id="bookCourtName"></span>
                        </div>
                        <div class="sch-modal-info-row">
                            <span class="sch-modal-info-label">Дата</span>
                            <span class="sch-modal-info-value">{{ $formattedDate }}</span>
                        </div>
                        <div class="sch-modal-info-row">
                            <span class="sch-modal-info-label">Начало</span>
                            <span class="sch-modal-info-value" id="bookTime"></span>
                        </div>
                        <div class="sch-modal-info-row">
                            <span class="sch-modal-info-label">Цена/час</span>
                            <span class="sch-modal-info-value" id="bookPrice"></span>
                        </div>
                    </div>

                    <hr class="sch-modal-divider">

                    <div class="form-group">
                        <label class="form-label">Длительность</label>
                        <div class="duration-selector" id="durationSelector"></div>
                    </div>

                    <div class="total-price">
                        <span class="total-price-label">Итого</span>
                        <span class="total-price-value" id="bookTotalPrice"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Имя клиента *</label>
                        <input type="text" name="client_name" class="form-input" placeholder="Введите имя" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Телефон (необязательно)</label>
                        <input type="text" name="client_phone" class="form-input" placeholder="+7 (___) ___-__-__">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Способ оплаты</label>
                        <div class="payment-methods" id="paymentMethods">
                            <button type="button" class="pay-btn" data-value="cash" onclick="selectPayment(this)">Наличные</button>
                            <button type="button" class="pay-btn" data-value="card" onclick="selectPayment(this)">Карта</button>
                            <button type="button" class="pay-btn" data-value="kaspi" onclick="selectPayment(this)">Kaspi</button>
                            <button type="button" class="pay-btn" data-value="certificate" onclick="selectPayment(this)">Сертификат</button>
                            <button type="button" class="pay-btn" data-value="club_card" onclick="selectPayment(this)">Клубная карта</button>
                            <button type="button" class="pay-btn" data-value="deposit" onclick="selectPayment(this)">Депозит</button>
                            <button type="button" class="pay-btn" data-value="cashback" onclick="selectPayment(this)">Кешбэк</button>
                        </div>
                        <input type="hidden" name="payment_method" id="paymentMethodInput">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Статус оплаты</label>
                        <input type="hidden" name="is_paid" id="isPaidInput" value="0">
                        <div class="paid-toggle">
                            <button type="button" class="paid-btn active" data-value="0" onclick="setPaid(this)">Не оплачено</button>
                            <button type="button" class="paid-btn" data-value="1" onclick="setPaid(this)">Оплачено</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Комментарий</label>
                        <textarea name="comment" class="form-input" rows="2" placeholder="Заметка к бронированию"></textarea>
                    </div>

                    <!-- Block option -->
                    <hr class="sch-modal-divider">
                    <div style="text-align: center;">
                        <button type="button" class="btn-block-slot" onclick="blockSlot()">Заблокировать слот</button>
                    </div>
                </div>
                <div class="sch-modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn-confirm">Забронировать</button>
                </div>
            </form>

            <!-- Hidden block form -->
            <form id="blockForm" method="POST" style="display:none;">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                <input type="hidden" name="start_time" id="blockStartTime">
                <input type="hidden" name="end_time" id="blockEndTime">
            </form>
        </div>
    </div>
</div>

<!-- Edit Booking Modal (Bootstrap 5) -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="sch-modal-header">
                <h2>Редактирование брони</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <form id="editBookingForm" method="POST">
                @csrf
                @method('PUT')
                <div class="sch-modal-body">
                    <div class="sch-modal-info">
                        <div class="sch-modal-info-row">
                            <span class="sch-modal-info-label">Корт</span>
                            <span class="sch-modal-info-value" id="viewCourtName"></span>
                        </div>
                        <div class="sch-modal-info-row">
                            <span class="sch-modal-info-label">Время</span>
                            <span class="sch-modal-info-value" id="viewTime"></span>
                        </div>
                        <div class="sch-modal-info-row">
                            <span class="sch-modal-info-label">Цена</span>
                            <span class="sch-modal-info-value" style="color: #22c55e; font-size: 18px;" id="viewPrice"></span>
                        </div>
                    </div>

                    <hr class="sch-modal-divider">

                    <div class="form-group">
                        <label class="form-label">Имя клиента *</label>
                        <input type="text" name="client_name" id="editClientName" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Телефон</label>
                        <input type="text" name="client_phone" id="editClientPhone" class="form-input" placeholder="+7 (___) ___-__-__">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Способ оплаты</label>
                        <div class="payment-methods" id="editPaymentMethods">
                            <button type="button" class="pay-btn" data-value="cash" onclick="selectEditPayment(this)">Наличные</button>
                            <button type="button" class="pay-btn" data-value="card" onclick="selectEditPayment(this)">Карта</button>
                            <button type="button" class="pay-btn" data-value="kaspi" onclick="selectEditPayment(this)">Kaspi</button>
                            <button type="button" class="pay-btn" data-value="certificate" onclick="selectEditPayment(this)">Сертификат</button>
                            <button type="button" class="pay-btn" data-value="club_card" onclick="selectEditPayment(this)">Клубная карта</button>
                            <button type="button" class="pay-btn" data-value="deposit" onclick="selectEditPayment(this)">Депозит</button>
                            <button type="button" class="pay-btn" data-value="cashback" onclick="selectEditPayment(this)">Кешбэк</button>
                        </div>
                        <input type="hidden" name="payment_method" id="editPaymentMethodInput">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Статус оплаты</label>
                        <input type="hidden" name="is_paid" id="editIsPaidInput" value="0">
                        <div class="paid-toggle">
                            <button type="button" class="paid-btn" data-value="0" onclick="setEditPaid(this)">Не оплачено</button>
                            <button type="button" class="paid-btn" data-value="1" onclick="setEditPaid(this)">Оплачено</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Комментарий</label>
                        <textarea name="comment" id="editComment" class="form-input" rows="2" placeholder="Заметка к бронированию"></textarea>
                    </div>
                </div>
                <div class="sch-modal-footer" style="flex-direction: column; gap: 8px;">
                    <div style="display: flex; gap: 12px; width: 100%;">
                        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Закрыть</button>
                        <button type="submit" class="btn-confirm">Сохранить</button>
                    </div>
                    <button type="button" class="btn-danger" style="width: 100%;" onclick="cancelBooking()">Отменить бронь</button>
                </div>
            </form>
            <form id="cancelBookingForm" method="POST" style="display:none;">
                @csrf
            </form>
        </div>
    </div>
</div>

<!-- Unblock Confirmation Modal (Bootstrap 5) -->
<div class="modal fade" id="unblockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="sch-modal-header">
                <h2>Разблокировать слот</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <div class="sch-modal-body">
                <div class="sch-modal-info">
                    <div class="sch-modal-info-row">
                        <span class="sch-modal-info-label">Корт</span>
                        <span class="sch-modal-info-value" id="unblockCourtName"></span>
                    </div>
                    <div class="sch-modal-info-row">
                        <span class="sch-modal-info-label">Время</span>
                        <span class="sch-modal-info-value" id="unblockTime"></span>
                    </div>
                </div>
                <p style="color: #a1a1aa; font-size: 14px; margin-top: 16px;">Вы уверены, что хотите разблокировать этот слот?</p>
            </div>
            <div class="sch-modal-footer">
                <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                <form id="unblockForm" method="POST" style="flex: 2;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-confirm" style="width: 100%;">Разблокировать</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    const courtRoutes = {
        @foreach($courts as $court)
            '{{ $court->id }}': {
                book: '{{ route('club.courts.book', $court) }}',
                block: '{{ route('club.courts.blockSlot', $court) }}'
            },
        @endforeach
    };

    const freePrices = @json($freePrices);
    const orderedTimes = @json($timeSlots->values()->toArray());

    // Current booking state
    let currentBook = {
        courtId: '',
        courtName: '',
        time: '',
        price: 0,
        maxSlots: 1,
        duration: 1
    };

    function formatPrice(val) {
        return Number(val).toLocaleString('ru-RU');
    }

    function hourLabel(n) {
        if (n === 1) return 'час';
        if (n >= 2 && n <= 4) return 'часа';
        return 'часов';
    }

    function calcTotalPrice() {
        let total = 0;
        const startIdx = orderedTimes.indexOf(currentBook.time);
        if (startIdx === -1) return currentBook.price * currentBook.duration;
        for (let i = 0; i < currentBook.duration; i++) {
            const t = orderedTimes[startIdx + i];
            const key = currentBook.courtId + '-' + t;
            total += freePrices[key] || currentBook.price;
        }
        return total;
    }

    function calcBlockEndTime() {
        const startIdx = orderedTimes.indexOf(currentBook.time);
        if (startIdx >= 0 && startIdx + 1 < orderedTimes.length) {
            return orderedTimes[startIdx + 1];
        }
        const [h, m] = currentBook.time.split(':').map(Number);
        const nh = h + 1;
        return String(nh).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }

    function updateBookTotalPrice() {
        document.getElementById('bookTotalPrice').innerHTML = formatPrice(calcTotalPrice()) + ' &#8376;';
        document.getElementById('bookSlots').value = currentBook.duration;
    }

    function setDuration(n) {
        currentBook.duration = n;
        // Update active state on buttons
        document.querySelectorAll('#durationSelector .duration-btn').forEach(function(btn) {
            const val = parseInt(btn.getAttribute('data-duration'));
            if (val === n) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        updateBookTotalPrice();
    }

    function renderDurationButtons(maxSlots) {
        const container = document.getElementById('durationSelector');
        container.innerHTML = '';
        for (let i = 1; i <= maxSlots; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'duration-btn' + (i === 1 ? ' active' : '');
            btn.setAttribute('data-duration', i);
            btn.innerHTML = i + '<small> ' + hourLabel(i) + '</small>';
            btn.onclick = function() { setDuration(i); };
            container.appendChild(btn);
        }
    }

    function openBookModal(courtId, courtName, time, price, maxSlots) {
        currentBook = {
            courtId: courtId,
            courtName: courtName,
            time: time,
            price: price,
            maxSlots: Math.min(maxSlots, 6),
            duration: 1
        };

        document.getElementById('bookForm').action = courtRoutes[courtId].book;
        document.getElementById('blockForm').action = courtRoutes[courtId].block;
        document.getElementById('bookStartTime').value = time;
        document.getElementById('bookSlots').value = 1;
        document.getElementById('bookCourtName').textContent = courtName;
        document.getElementById('bookTime').textContent = time;
        document.getElementById('bookPrice').innerHTML = formatPrice(price) + ' &#8376;';
        document.getElementById('blockStartTime').value = time;
        document.getElementById('blockEndTime').value = calcBlockEndTime();

        // Reset form inputs
        const form = document.getElementById('bookForm');
        const clientName = form.querySelector('input[name="client_name"]');
        const clientPhone = form.querySelector('input[name="client_phone"]');
        if (clientName) clientName.value = '';
        if (clientPhone) clientPhone.value = '';
        document.getElementById('paymentMethodInput').value = '';
        document.getElementById('isPaidInput').value = '0';
        document.querySelectorAll('#paymentMethods .pay-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.paid-toggle .paid-btn').forEach(b => b.classList.remove('active'));
        document.querySelector('.paid-btn[data-value="0"]').classList.add('active');

        renderDurationButtons(currentBook.maxSlots);
        updateBookTotalPrice();

        new bootstrap.Modal(document.getElementById('bookModal')).show();
    }

    function openViewModal(data) {
        document.getElementById('viewCourtName').textContent = data.courtName;
        document.getElementById('viewTime').textContent = data.startTime + ' — ' + data.endTime;
        document.getElementById('viewPrice').innerHTML = formatPrice(data.price) + ' &#8376;';

        document.getElementById('editClientName').value = data.clientName || '';
        document.getElementById('editClientPhone').value = data.clientPhone || '';

        // Payment method
        document.getElementById('editPaymentMethodInput').value = data.paymentMethod || '';
        document.querySelectorAll('#editPaymentMethods .pay-btn').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-value') === data.paymentMethod);
        });

        // Paid status
        const paidVal = data.isPaid ? '1' : '0';
        document.getElementById('editIsPaidInput').value = paidVal;
        document.querySelectorAll('#viewModal .paid-toggle .paid-btn').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-value') === paidVal);
        });

        document.getElementById('editComment').value = data.comment || '';
        document.getElementById('editBookingForm').action = '{{ url("club/courts/bookings") }}/' + data.id;
        document.getElementById('cancelBookingForm').action = '{{ url("club/courts/bookings") }}/' + data.id + '/cancel';

        new bootstrap.Modal(document.getElementById('viewModal')).show();
    }

    function cancelBooking() {
        if (confirm('Вы уверены, что хотите отменить бронь?')) {
            document.getElementById('cancelBookingForm').submit();
        }
    }

    function selectEditPayment(btn) {
        document.querySelectorAll('#editPaymentMethods .pay-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('editPaymentMethodInput').value = btn.getAttribute('data-value');
    }

    function setEditPaid(btn) {
        document.querySelectorAll('#viewModal .paid-toggle .paid-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('editIsPaidInput').value = btn.getAttribute('data-value');
    }

    function openUnblockModal(blockId, courtName, time) {
        document.getElementById('unblockCourtName').textContent = courtName;
        document.getElementById('unblockTime').textContent = time;
        document.getElementById('unblockForm').action = '{{ url("club/courts/blocks") }}/' + blockId;

        new bootstrap.Modal(document.getElementById('unblockModal')).show();
    }

    function blockSlot() {
        document.getElementById('blockStartTime').value = currentBook.time;
        document.getElementById('blockEndTime').value = calcBlockEndTime();
        document.getElementById('blockForm').submit();
    }

    function selectPayment(btn) {
        document.querySelectorAll('#paymentMethods .pay-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('paymentMethodInput').value = btn.getAttribute('data-value');
    }

    function setPaid(btn) {
        document.querySelectorAll('.paid-toggle .paid-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('isPaidInput').value = btn.getAttribute('data-value');
    }
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
            window.location.href = '{{ route('club.courts.schedule') }}?date=' + dateStr;
        }
    });
</script>

<style>
    :root {
        --sch-bg: #0a0a0b;
        --sch-card: #111113;
        --sch-card-alt: #16161a;
        --sch-accent: #22c55e;
        --sch-accent-dark: #16a34a;
        --sch-blue: #3b82f6;
        --sch-text: #f4f4f5;
        --sch-text-dim: #a1a1aa;
        --sch-text-muted: #71717a;
        --sch-border: #27272a;
        --sch-border-light: #1c1c21;
        --sch-red: #ef4444;
    }

    .schedule-container {
        width: 100%;
        padding: 32px 24px;
    }

    /* Header */
    .schedule-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 28px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .header-left h1 {
        font-size: 24px;
        font-weight: 800;
        letter-spacing: -0.5px;
    }

    .settings-link {
        display: flex;
        align-items: center;
        gap: 6px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        padding: 10px 18px;
        border-radius: 10px;
        color: var(--sch-text-dim);
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.2s;
    }

    .settings-link:hover {
        border-color: var(--sch-blue);
        color: var(--sch-blue);
    }

    /* Flash Messages */
    .flash-message {
        padding: 14px 20px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 24px;
    }

    .flash-success {
        background: rgba(34, 197, 94, 0.15);
        color: var(--sch-accent);
        border: 1px solid rgba(34, 197, 94, 0.3);
    }

    .flash-error {
        background: rgba(239, 68, 68, 0.15);
        color: var(--sch-red);
        border: 1px solid rgba(239, 68, 68, 0.3);
    }

    /* Week Navigation */
    .week-nav {
        margin-bottom: 24px;
    }

    .week-nav-tools {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
    }

    .date-picker-wrap {
        display: flex;
        align-items: center;
        gap: 8px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        padding: 8px 14px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .date-picker-wrap:hover {
        border-color: var(--sch-accent);
    }

    .date-picker-input,
    .date-picker-input + .flatpickr-input {
        background: transparent !important;
        border: none !important;
        color: var(--sch-text) !important;
        font-size: 14px !important;
        font-weight: 700 !important;
        cursor: pointer !important;
        padding: 0 !important;
        outline: none !important;
        width: auto !important;
    }

    .flatpickr-input.flatpickr-mobile {
        background: var(--sch-card-alt) !important;
        border: 1px solid var(--sch-border) !important;
        border-radius: 10px !important;
        padding: 8px 14px !important;
        color: var(--sch-text) !important;
        font-size: 14px !important;
    }

    .today-btn {
        padding: 7px 16px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 8px;
        color: var(--sch-text-dim);
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
    }

    .today-btn:hover {
        border-color: var(--sch-accent);
        color: var(--sch-accent);
    }

    .week-nav-days {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .date-btn {
        width: 36px;
        height: 36px;
        min-width: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        color: var(--sch-text-dim);
        cursor: pointer;
        transition: all 0.2s;
        font-size: 18px;
        text-decoration: none;
    }

    .date-btn:hover {
        border-color: var(--sch-accent);
        color: var(--sch-accent);
    }

    .week-days {
        display: flex;
        flex: 1;
        gap: 6px;
    }

    .week-day-btn {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 3px;
        padding: 10px 4px 8px;
        background: var(--sch-card);
        border: 1px solid var(--sch-border);
        border-radius: 14px;
        text-decoration: none;
        transition: all 0.2s;
        position: relative;
        cursor: pointer;
    }

    .week-day-btn:hover {
        border-color: #3f3f46;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    }

    .week-day-btn.active {
        background: linear-gradient(135deg, #22c55e, #16a34a);
        border-color: transparent;
        box-shadow: 0 4px 20px rgba(34,197,94,0.3);
    }

    .week-day-btn.today:not(.active) {
        border-color: var(--sch-accent);
    }

    .week-day-btn .week-day-name {
        font-size: 10px;
        font-weight: 700;
        color: #52525b;
        text-transform: uppercase;
    }

    .week-day-btn .week-day-num {
        font-size: 15px;
        font-weight: 800;
        color: #d4d4d8;
        line-height: 1.2;
    }

    .week-day-btn .week-day-occ {
        font-size: 8px;
        font-weight: 700;
        position: absolute;
        top: 4px;
        right: 6px;
    }

    .week-day-btn .week-day-bar {
        width: 80%;
        height: 3px;
        border-radius: 2px;
        background: var(--sch-border);
        overflow: hidden;
        margin-top: 2px;
    }

    .week-day-btn .week-day-bar-fill {
        height: 100%;
        border-radius: 2px;
    }

    .week-day-btn.active .week-day-name,
    .week-day-btn.active .week-day-num {
        color: #fff;
    }

    .week-day-btn.active .week-day-occ {
        color: #fff !important;
        opacity: 0.7;
    }

    .week-day-btn.active .week-day-bar {
        background: rgba(255,255,255,0.2);
    }

    .week-day-btn.active .week-day-bar-fill {
        background: #fff !important;
    }

    .week-day-btn.today:not(.active) .week-day-num {
        color: var(--sch-accent);
    }

    @media (max-width: 768px) {
        .week-nav-days { overflow-x: auto; }
        .week-day-btn { min-width: 52px; padding: 8px 2px 6px; }
        .week-day-btn .week-day-num { font-size: 13px; }
    }

    /* Schedule Grid */
    .schedule-wrap {
        background: var(--sch-card);
        border: 1px solid var(--sch-border);
        border-radius: 16px;
        overflow: hidden;
    }

    .schedule-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    .schedule-table th {
        padding: 16px 12px;
        text-align: center;
        font-size: 14px;
        font-weight: 800;
        color: var(--sch-text-dim);
        background: var(--sch-card-alt);
        border-bottom: 1px solid var(--sch-border);
    }

    .schedule-table th.time-col {
        width: 80px;
        text-align: left;
        padding-left: 20px;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--sch-text-muted);
    }

    .schedule-table td {
        padding: 4px;
        border-bottom: 1px solid var(--sch-border-light);
        vertical-align: top;
        height: 60px;
        overflow: hidden;
    }

    .schedule-divider td {
        padding: 0 !important;
        height: auto !important;
        border: none !important;
    }
    .divider-line {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 6px 20px;
    }
    .divider-line::before,
    .divider-line::after {
        content: '';
        flex: 1;
        height: 1px;
        background: #3f3f46;
    }
    .divider-line span {
        font-size: 11px;
        font-weight: 700;
        color: #71717a;
        text-transform: uppercase;
        letter-spacing: 1px;
        white-space: nowrap;
    }

    .schedule-table td.time-cell {
        padding-left: 20px;
        font-size: 14px;
        font-weight: 700;
        color: var(--sch-text-dim);
        vertical-align: middle;
        font-variant-numeric: tabular-nums;
        border-right: 1px solid var(--sch-border);
    }

    .schedule-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Slot cells */
    .slot {
        width: 100%;
        height: 100%;
        min-height: 52px;
        border-radius: 8px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
        border: 1px solid transparent;
        padding: 4px;
    }

    .slot-free {
        background: rgba(34, 197, 94, 0.08);
        color: var(--sch-accent);
        border-color: rgba(34, 197, 94, 0.15);
    }

    .slot-free:hover {
        background: rgba(34, 197, 94, 0.2);
        border-color: var(--sch-accent);
        box-shadow: inset 0 0 0 2px var(--sch-accent);
    }

    .slot-free .slot-price {
        font-size: 13px;
        font-weight: 700;
    }

    .slot-booked {
        background: rgba(59, 130, 246, 0.15);
        color: var(--sch-blue);
        border-color: rgba(59, 130, 246, 0.25);
    }

    .slot-booked:hover {
        background: rgba(59, 130, 246, 0.25);
        border-color: var(--sch-blue);
    }

    .slot-booked.unpaid {
        background: rgba(251, 146, 60, 0.15);
        color: #fb923c;
        border-color: rgba(251, 146, 60, 0.25);
    }

    .slot-booked.unpaid:hover {
        background: rgba(251, 146, 60, 0.25);
        border-color: #fb923c;
    }

    .slot-booked .client-name {
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }

    .slot-booked .slot-comment {
        font-size: 10px;
        opacity: 0.6;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }

    .slot-booked .slot-price {
        font-size: 10px;
        opacity: 0.7;
    }

    .slot-booked-multi {
        background: rgba(59, 130, 246, 0.15);
        color: var(--sch-blue);
        border-color: rgba(59, 130, 246, 0.25);
        min-height: 112px;
        border-radius: 8px;
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
        padding: 4px;
    }

    .slot-booked-multi:hover {
        background: rgba(59, 130, 246, 0.25);
        border-color: var(--sch-blue);
    }

    .slot-booked-multi.unpaid {
        background: rgba(251, 146, 60, 0.15);
        color: #fb923c;
        border-color: rgba(251, 146, 60, 0.25);
    }

    .slot-booked-multi.unpaid:hover {
        background: rgba(251, 146, 60, 0.25);
        border-color: #fb923c;
    }

    .slot-booked-multi .client-name {
        font-size: 13px;
        font-weight: 700;
    }

    .slot-booked-multi .slot-time {
        font-size: 11px;
        opacity: 0.7;
    }

    .slot-booked-multi .slot-comment {
        font-size: 11px;
        opacity: 0.6;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }

    .slot-booked-multi .slot-price {
        font-size: 11px;
        opacity: 0.7;
    }

    .slot-blocked {
        background: rgba(113, 113, 122, 0.15);
        color: var(--sch-text-muted);
        border-color: rgba(113, 113, 122, 0.2);
    }

    .slot-blocked:hover {
        background: rgba(113, 113, 122, 0.25);
        border-color: var(--sch-text-muted);
    }

    .slot-blocked .slot-label {
        font-size: 11px;
    }

    .slot-empty {
        color: var(--sch-text-muted);
        opacity: 0.3;
        font-size: 16px;
        cursor: default;
    }

    /* Legend */
    .legend {
        display: flex;
        gap: 24px;
        margin-top: 20px;
        flex-wrap: wrap;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 600;
        color: var(--sch-text-dim);
    }

    .legend-dot {
        width: 14px;
        height: 14px;
        border-radius: 4px;
    }

    .legend-dot.free {
        background: rgba(34, 197, 94, 0.3);
        border: 1px solid var(--sch-accent);
    }

    .legend-dot.booked {
        background: rgba(59, 130, 246, 0.3);
        border: 1px solid var(--sch-blue);
    }

    .legend-dot.unpaid {
        background: rgba(251, 146, 60, 0.3);
        border: 1px solid #fb923c;
    }

    .legend-dot.blocked {
        background: rgba(113, 113, 122, 0.3);
        border: 1px solid var(--sch-text-muted);
    }

    /* Bootstrap Modal Overrides for dark theme */
    #bookModal .modal-content,
    #viewModal .modal-content,
    #unblockModal .modal-content {
        overflow: hidden;
    }

    .sch-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px 24px;
        border-bottom: 1px solid var(--sch-border);
    }

    .sch-modal-header h2 {
        font-size: 18px;
        font-weight: 700;
        color: var(--sch-text);
        margin: 0;
    }

    .sch-modal-close {
        width: 36px; height: 36px;
        display: flex; align-items: center; justify-content: center;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 8px;
        cursor: pointer;
        color: var(--sch-text-dim);
        font-size: 18px;
        transition: all 0.2s;
    }

    .sch-modal-close:hover { border-color: var(--sch-red); color: var(--sch-red); }

    .sch-modal-body { padding: 24px; }

    .sch-modal-info {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-bottom: 20px;
    }

    .sch-modal-info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .sch-modal-info-label {
        font-size: 13px;
        color: var(--sch-text-muted);
        font-weight: 600;
    }

    .sch-modal-info-value {
        font-size: 14px;
        font-weight: 700;
        color: var(--sch-text);
    }

    .sch-modal-divider {
        border: none;
        border-top: 1px solid var(--sch-border);
        margin: 16px 0;
    }

    .form-group {
        margin-bottom: 16px;
    }

    .form-label {
        display: block;
        font-size: 12px;
        font-weight: 700;
        color: var(--sch-text-dim);
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-input {
        width: 100%;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        padding: 12px 16px;
        font-size: 15px;
        color: var(--sch-text);
        font-weight: 500;
    }

    .form-input:focus {
        outline: none;
        border-color: var(--sch-accent);
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
    }

    .form-input::placeholder { color: #52525b; }

    .duration-selector {
        display: flex;
        gap: 6px;
    }

    .duration-btn {
        flex: 1;
        height: 44px;
        padding: 0;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        color: var(--sch-text-dim);
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        text-align: center;
        transition: all 0.2s;
    }

    .duration-btn small {
        font-size: 10px;
        font-weight: 600;
        opacity: 0.7;
    }

    .duration-btn.active {
        background: var(--sch-accent);
        color: var(--sch-bg);
        border-color: var(--sch-accent);
    }

    .duration-btn.active small { opacity: 0.8; }

    .duration-btn:hover:not(.active) {
        border-color: var(--sch-accent);
        color: var(--sch-accent);
    }

    .payment-methods {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 6px;
    }

    .payment-methods .pay-btn:last-child:nth-child(4n - 2) {
        grid-column: span 1;
    }

    .pay-btn {
        padding: 8px 4px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 8px;
        color: var(--sch-text-dim);
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        text-align: center;
    }

    .pay-btn.active {
        background: var(--sch-accent);
        color: var(--sch-bg);
        border-color: var(--sch-accent);
    }

    .pay-btn:hover:not(.active) {
        border-color: var(--sch-accent);
        color: var(--sch-accent);
    }

    .paid-toggle {
        display: flex;
        gap: 8px;
    }

    .paid-btn {
        flex: 1;
        padding: 10px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        color: var(--sch-text-dim);
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        text-align: center;
        transition: all 0.2s;
    }

    .paid-btn.active[data-value="0"] {
        background: rgba(251, 146, 60, 0.2);
        color: #fb923c;
        border-color: #fb923c;
    }

    .paid-btn.active[data-value="1"] {
        background: var(--sch-accent);
        color: var(--sch-bg);
        border-color: var(--sch-accent);
    }

    .paid-btn:hover:not(.active) {
        border-color: #3f3f46;
        color: var(--sch-text);
    }

    .total-price {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        background: rgba(34, 197, 94, 0.1);
        border: 1px solid rgba(34, 197, 94, 0.2);
        border-radius: 10px;
        margin-bottom: 20px;
    }

    .total-price-label {
        font-size: 14px;
        font-weight: 600;
        color: var(--sch-text-dim);
    }

    .total-price-value {
        font-size: 20px;
        font-weight: 800;
        color: var(--sch-accent);
    }

    .sch-modal-footer {
        display: flex;
        gap: 12px;
        padding: 20px 24px;
        border-top: 1px solid var(--sch-border);
    }

    .btn-cancel {
        flex: 1;
        padding: 14px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        color: var(--sch-text-dim);
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-cancel:hover { border-color: #3f3f46; color: var(--sch-text); }

    .btn-confirm {
        flex: 2;
        padding: 14px;
        background: var(--sch-accent);
        border: none;
        border-radius: 10px;
        color: var(--sch-bg);
        font-size: 14px;
        font-weight: 800;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-confirm:hover { background: var(--sch-accent-dark); }

    .btn-danger {
        padding: 14px;
        background: var(--sch-red);
        border: none;
        border-radius: 10px;
        color: #fff;
        font-size: 14px;
        font-weight: 800;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-danger:hover { background: #dc2626; }

    .btn-block-slot {
        padding: 10px 20px;
        background: transparent;
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        color: var(--sch-text-muted);
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-block-slot:hover {
        border-color: var(--sch-text-muted);
        color: var(--sch-text-dim);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .schedule-container { padding: 16px 12px; }
        .schedule-header { flex-direction: column; align-items: flex-start; }
        .schedule-wrap { overflow-x: auto; }
        .schedule-table { min-width: 600px; }
        .date-nav-wrap { flex-wrap: wrap; }
        .date-label { min-width: auto; font-size: 15px; }
    }
</style>
@endsection

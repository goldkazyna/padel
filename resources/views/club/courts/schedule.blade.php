@extends('layouts.app')
@section('title', 'Расписание кортов')

@section('content')
@php
    // Способ оплаты → [подпись, иконка, цвет] для цветной полосы на слоте
    $paymentMeta = [
        'cash'        => ['Наличные',      'bi-cash-stack',          '#22c55e'],
        'card'        => ['Карта',         'bi-credit-card-2-front', '#3b82f6'],
        'kaspi'       => ['Kaspi',         'bi-qr-code',             '#f14635'],
        'certificate' => ['Сертификат',    'bi-award',               '#a855f7'],
        'club_card'   => ['Клубная карта', 'bi-person-vcard',        '#06b6d4'],
        'deposit'     => ['Депозит',       'bi-wallet2',             '#eab308'],
        'cashback'    => ['Кешбэк',        'bi-arrow-repeat',        '#ec4899'],
        'cashless'    => ['Безналичный',   'bi-bank',                '#14b8a6'],
        'free'        => ['Бесплатно',     'bi-gift',                '#94a3b8'],
    ];
@endphp
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
        color: #a1a1aa !important;
        font-weight: 700 !important;
        font-size: 12px !important;
        background: #111113 !important;
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
                    $endMinutes = intval(substr($bookingEndTime, 0, 2)) * 60 + intval(substr($bookingEndTime, 3, 2));
                    if ($endMinutes === 0) $endMinutes = 1440;
                    $span = 0;
                    for ($j = $i; $j < count($times); $j++) {
                        $slotMinutes = intval(substr($times[$j], 0, 2)) * 60 + intval(substr($times[$j], 3, 2));
                        if ($slotMinutes >= $endMinutes) break;
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

    // Карта свободных слотов по датам — для расчёта максимума и цены при
    // редактировании длительности существующей брони.
    $freeSlotsByDate = [$date => []];
    foreach ($courts as $court) {
        foreach ($schedules[$court->id] ?? [] as $time => $slot) {
            if (($slot['status'] ?? '') === 'free') {
                $freeSlotsByDate[$date][$court->id . '-' . $time] = $slot['price'];
            }
        }
    }
@endphp

<div class="schedule-container">
    <!-- Header -->
    <div class="schedule-header">
        <div class="header-left">
            <h1>Расписание кортов — {{ $club->name ?? '' }}</h1>
        </div>
        @if($unprocessedBookings->count() > 0)
        <button type="button" class="unprocessed-panel-btn" onclick="toggleUnprocessedPanel()">
            <i class="bi bi-exclamation-circle"></i>
            Необработанные
            <span class="unprocessed-panel-badge">{{ $unprocessedBookings->count() }}</span>
        </button>
        @endif
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
                       class="week-day-btn{{ $wd['isSelected'] ? ' active' : '' }}{{ $wd['isToday'] ? ' today' : '' }}" data-date="{{ $wd['date'] }}">
                        <span class="week-day-name">{{ $wd['dayName'] }}</span>
                        <span class="week-day-num">{{ $wd['dayNum'] }} {{ $wd['month'] }}</span>
                        @if($wd['occupancy'] > 0)
                            <span class="week-day-occ" style="color: {{ $wd['occupancy'] >= 80 ? '#ef4444' : ($wd['occupancy'] >= 40 ? '#fb923c' : '#22c55e') }}">{{ $wd['occupancy'] }}%</span>
                        @endif
                        <div class="week-day-bar"><div class="week-day-bar-fill" style="width:{{ $wd['occupancy'] }}%;background:{{ $wd['occupancy'] >= 80 ? '#ef4444' : ($wd['occupancy'] >= 40 ? '#fb923c' : '#22c55e') }}"></div></div>
                        <span class="week-day-unprocessed" data-date-badge="{{ $wd['date'] }}" style="display:none;"></span>
                    </a>
                @endforeach
            </div>
            <a href="{{ route('club.courts.schedule', ['date' => $nextWeek]) }}" class="date-btn">&#8250;</a>
        </div>
    </div>

    <!-- View tabs: По кортам (этот) / По дням (недельный) -->
    <div style="display:flex; justify-content:flex-end; margin: 12px 0 8px;">
        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
            <span style="font-size:11px; color: var(--sch-text-muted, #71717a); text-transform:uppercase; letter-spacing:0.4px;">Отображение</span>
            <div style="display:flex; gap:2px; background: var(--sch-card, #111113); border:1px solid var(--sch-border, #27272a); border-radius:10px; padding:3px;">
                <a href="{{ route('club.courts.schedule', ['date' => $date]) }}"
                   style="padding:7px 14px; border-radius:8px; font-size:13px; font-weight:500; text-decoration:none; background: rgba(34,197,94,0.14); color: var(--sch-accent, #22c55e);">По кортам</a>
                <a href="{{ route('club.courts.scheduleWeek', ['date' => $date]) }}"
                   style="padding:7px 14px; border-radius:8px; font-size:13px; font-weight:500; text-decoration:none; color: var(--sch-text-dim, #a1a1aa);">По дням</a>
            </div>
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
                                         id="slot-free-{{ $court->id }}-{{ $time }}"
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
                                    $statusClass = !$booking->is_processed ? ' unprocessed' : ($booking->is_paid ? '' : ' unpaid');
                                    $slotClass = ($span > 1 ? 'slot-booked-multi' : 'slot-booked') . $statusClass;
                                    if ($booking->booking_type) $slotClass .= ' bt-slot-' . $booking->booking_type;
                                    $pm = $paymentMeta[$booking->payment_method] ?? null;
                                    // У групповой брони нет способа оплаты — показываем тип
                                    if (!$pm && $booking->booking_type === 'group') {
                                        $pm = ['Групповая', 'bi-people-fill', '#fbbf24'];
                                    }
                                    if ($pm) $slotClass .= ' has-pm';
                                    $coachRate = null;
                                    $coachPhoto = null;
                                    if ($booking->coach_id) {
                                        $cc = $clubCoaches->firstWhere('user_id', $booking->coach_id);
                                        $coachRate = $cc ? $cc->hourly_rate : null;
                                        $coachPhoto = $cc ? $cc->photo : null;
                                    }
                                @endphp
                                <td @if($span > 1) rowspan="{{ $span }}" style="padding: 4px;" @endif>
                                    @php
                                        $coachTotal = 0;
                                        if ($booking->coach_id) {
                                            $ccObj = $clubCoaches->firstWhere('user_id', $booking->coach_id);
                                            $sMin = \Carbon\Carbon::parse($booking->start_time)->hour * 60 + \Carbon\Carbon::parse($booking->start_time)->minute;
                                            $eMin = \Carbon\Carbon::parse($booking->end_time)->hour * 60 + \Carbon\Carbon::parse($booking->end_time)->minute;
                                            if ($eMin <= $sMin) $eMin += 1440;
                                            $bkHours = ($eMin - $sMin) / 60;
                                            if ($booking->coach_price !== null) {
                                                // Зафиксированная сумма: ручная (индивид.) либо замороженная
                                                // при проведении группового занятия.
                                                $coachTotal = (float) $booking->coach_price;
                                            } elseif ($booking->booking_type === 'group' && $ccObj && $ccObj->rate_group !== null) {
                                                // Группа ещё не проведена — прикидка по текущей групповой ставке (₸/час × часы).
                                                $coachTotal = (float) $ccObj->rate_group * $bkHours;
                                            } elseif ($ccObj) {
                                                $coachTotal = $ccObj->getRateForHours((int) $bkHours);
                                            }
                                        }

                                        // Мультитренер (спарринг): дополнительные тренеры сверх основного.
                                        $extraCoaches = [];
                                        if ($booking->booking_type !== 'group' && $booking->coaches->count() > 1) {
                                            foreach ($booking->coaches as $pc) {
                                                if ((int) $pc->coach_id === (int) $booking->coach_id) continue;
                                                $pcObj = $clubCoaches->firstWhere('user_id', $pc->coach_id);
                                                $pcTotal = $pc->coach_price !== null
                                                    ? (float) $pc->coach_price
                                                    : ($pcObj ? $pcObj->getRateForHours((int) $bkHours) : 0);
                                                $extraCoaches[] = [
                                                    'user' => $pc->coach,
                                                    'photo' => $pcObj ? $pcObj->photo : null,
                                                    'total' => $pcTotal,
                                                    'paid' => $pc->coach_paid,
                                                ];
                                            }
                                        }
                                    @endphp
                                    <div class="slot {{ $slotClass }}{{ ($booking->club_card_id || $booking->source === 'app' || $booking->is_paid) ? ' has-icons' : '' }}"
                                         id="slot-booking-{{ $booking->id }}"
                                         onclick="openViewModal({{ json_encode([
                                            'id' => $booking->id,
                                            'courtId' => $court->id,
                                            'date' => $date,
                                            'courtName' => $court->name,
                                            'startTime' => $bStart,
                                            'endTime' => $bEnd,
                                            'clientName' => $booking->client_name ?? '',
                                            'clientPhone' => $booking->client_phone ?? '',
                                            'price' => (float) ($booking->price ?? 0),
                                            'paymentMethod' => $booking->payment_method ?? '',
                                            'isPaid' => (bool) $booking->is_paid,
                                            'isProcessed' => (bool) $booking->is_processed,
                                            'comment' => $booking->comment ?? '',
                                            'bookingType' => $booking->booking_type ?? '',
                                            'groupId' => $bookingGroupIds[$booking->id] ?? null,
                                            'coachId' => $booking->coach_id,
                                            'coachPaid' => $booking->coach_paid === null ? null : (bool) $booking->coach_paid,
                                            'coachPrice' => $booking->coach_price !== null ? (float) $booking->coach_price : null,
                                            'coaches' => $booking->coaches->map(fn($pc) => ['coachId' => (int) $pc->coach_id, 'price' => $pc->coach_price !== null ? (float) $pc->coach_price : null, 'paid' => (bool) $pc->coach_paid])->values(),
                                            'discount' => (float) ($booking->discount ?? 0),
                                            'clubCardId' => $booking->club_card_id,
                                            'cardCharged' => (bool) $booking->card_charged_at,
                                            'hasCertificate' => (bool) $booking->certificate_id,
                                            'certificateId' => $booking->certificate_id,
                                            'slotDuration' => $court->slot_duration ?? 60,
                                        ]) }})">
                                        @if($pm)
                                        <div class="slot-pm-strip" style="--pm: {{ $pm[2] }}" title="Оплата: {{ $pm[0] }}">
                                            <i class="bi {{ $pm[1] }}"></i><span>{{ $pm[0] }}</span>
                                        </div>
                                        @endif
                                        @if($booking->club_card_id || $booking->source === 'app' || $booking->is_paid)
                                        <div class="slot-icons">
                                            @if($booking->source === 'app')<i class="bi bi-phone-fill slot-ic ic-app" title="Заявка из приложения"></i>@endif
                                            @if($booking->is_paid)<i class="bi bi-patch-check-fill slot-ic ic-paid" title="Оплачено"></i>@endif
                                            @if($booking->club_card_id && !$pm)<i class="bi bi-credit-card-2-front slot-ic ic-card" title="Оплачено клубной картой"></i>@endif
                                        </div>
                                        @endif
                                        <div class="slot-row">
                                            <div class="slot-left">
                                                <span class="slot-name">{{ $booking->client_name ?? 'Бронь' }}</span>
                                                @if($booking->client_phone)<span class="slot-phone">@phoneFmt($booking->client_phone)</span>@endif
                                            </div>
                                            <div class="slot-right">
                                                <span class="slot-price-court">{{ number_format($booking->price ?? 0, 0, '', ' ') }} &#8376;</span>
                                            </div>
                                        </div>
                                        @if($booking->coach || $booking->comment || $coachTotal > 0 || $booking->needs_coach)
                                        <div class="slot-row slot-row-sub">
                                            <div class="slot-left">
                                                @if($booking->coach)<span class="slot-coach"><span class="slot-coach-avatar">@if($coachPhoto)<img src="{{ $coachPhoto }}" alt="">@else{{ mb_strtoupper(mb_substr($booking->coach->first_name ?? '?', 0, 1)) }}@endif</span>{{ $booking->coach->first_name }}</span>@endif
                                                @if($booking->needs_coach && !$booking->coach)<span class="slot-needs-coach" title="Клиент запросил тренера">🎾 Нужен тренер</span>@endif
                                                @if($booking->comment)<span class="slot-comment-text">{{ $booking->comment }}</span>@endif
                                            </div>
                                            @if($coachTotal > 0)
                                            <div class="slot-right">
                                                @if($booking->coach_paid === null)
                                                    <span class="slot-price-coach">+ {{ number_format($coachTotal, 0, '', ' ') }} &#8376;</span>
                                                @else
                                                    <span class="slot-coach-cap {{ $booking->coach_paid ? 'paid' : 'unpaid' }}" title="{{ $booking->coach_paid ? 'Тренер оплачен' : 'Тренер не оплачен' }}"><i class="bi {{ $booking->coach_paid ? 'bi-check-circle-fill' : 'bi-hourglass-split' }}"></i>+ {{ number_format($coachTotal, 0, '', ' ') }} &#8376;</span>
                                                @endif
                                            </div>
                                            @endif
                                        </div>
                                        @endif
                                        @foreach($extraCoaches as $ec)
                                        <div class="slot-row slot-row-sub">
                                            <div class="slot-left">
                                                <span class="slot-coach"><span class="slot-coach-avatar">@if($ec['photo'])<img src="{{ $ec['photo'] }}" alt="">@else{{ mb_strtoupper(mb_substr(optional($ec['user'])->first_name ?? '?', 0, 1)) }}@endif</span>{{ optional($ec['user'])->first_name }}</span>
                                            </div>
                                            @if($ec['total'] > 0)
                                            <div class="slot-right">
                                                @if($ec['paid'] === null)
                                                    <span class="slot-price-coach">+ {{ number_format($ec['total'], 0, '', ' ') }} &#8376;</span>
                                                @else
                                                    <span class="slot-coach-cap {{ $ec['paid'] ? 'paid' : 'unpaid' }}" title="{{ $ec['paid'] ? 'Тренер оплачен' : 'Тренер не оплачен' }}"><i class="bi {{ $ec['paid'] ? 'bi-check-circle-fill' : 'bi-hourglass-split' }}"></i>+ {{ number_format($ec['total'], 0, '', ' ') }} &#8376;</span>
                                                @endif
                                            </div>
                                            @endif
                                        </div>
                                        @endforeach
                                    </div>
                                </td>
                            @elseif($slot['status'] === 'blocked')
                                <td>
                                    <div class="slot slot-blocked"
                                         id="slot-block-{{ $slot['block']->id ?? 0 }}"
                                         onclick="openUnblockModal({{ $slot['block']->id ?? 0 }}, '{{ addslashes($court->name) }}', '{{ $time }}', '{{ addslashes($slot['block']->comment ?? '') }}')">
                                        <span class="slot-label">{{ $slot['block']->comment ?? 'Заблокирован' }}</span>
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
        <div class="legend-item"><span class="legend-dot unprocessed"></span>Не обработан</div>
        <div class="legend-item"><span class="legend-dot blocked"></span>Заблокирован</div>
    </div>

    <!-- Payment methods legend -->
    <div class="legend legend-pm">
        <span class="legend-pm-title">Способ оплаты:</span>
        @foreach($paymentMeta as $pmItem)
            <div class="legend-item"><span class="legend-dot" style="background: {{ $pmItem[2] }}"></span>{{ $pmItem[0] }}</div>
        @endforeach
    </div>
</div>

<!-- Unprocessed Panel -->
<div class="unprocessed-overlay" id="unprocessedOverlay" onclick="toggleUnprocessedPanel()"></div>
<div class="unprocessed-panel" id="unprocessedPanel">
    <div class="unprocessed-panel-header">
        <h2><i class="bi bi-exclamation-circle"></i> Необработанные заявки <span class="unprocessed-panel-count">{{ $unprocessedBookings->count() }}</span></h2>
        <button class="sch-modal-close" onclick="toggleUnprocessedPanel()">&#10005;</button>
    </div>
    <div class="unprocessed-panel-body">
        @forelse($unprocessedBookings as $ub)
            <div class="unprocessed-card" id="unprocessedCard{{ $ub->id }}">
                <div class="unprocessed-card-top">
                    <div class="unprocessed-card-client">
                        <span class="unprocessed-card-name">{{ $ub->client_name }}</span>
                        @if($ub->client_phone)
                            <span class="unprocessed-card-phone">@phoneFmt($ub->client_phone)</span>
                        @endif
                    </div>
                    <span class="unprocessed-card-price">{{ number_format($ub->price, 0, '', ' ') }} ₸</span>
                </div>
                <div class="unprocessed-card-details">
                    <span><i class="bi bi-grid-3x3"></i> {{ $ub->court->name }}</span>
                    <span><i class="bi bi-calendar3"></i> {{ \Carbon\Carbon::parse($ub->date)->locale('ru')->isoFormat('D MMM') }}</span>
                    <span><i class="bi bi-clock"></i> {{ substr($ub->start_time, 0, 5) }}–{{ substr($ub->end_time, 0, 5) }}</span>
                </div>
                @if($ub->needs_coach)
                    <div class="unprocessed-card-needs-coach" style="background:rgba(168,156,245,0.15);color:#a89cf5;padding:6px 10px;border-radius:8px;font-size:12px;font-weight:600;margin-top:6px;display:inline-flex;align-items:center;gap:6px;">
                        <i class="bi bi-person-raised-hand"></i> Клиент просит тренера
                    </div>
                @endif
                @if($ub->comment)
                    <div class="unprocessed-card-comment">{{ $ub->comment }}</div>
                @endif
                <div class="unprocessed-card-actions">
                    <button type="button" class="unprocessed-btn-view"
                            onclick="toggleUnprocessedPanel(); openViewModal({{ json_encode([
                                'id' => $ub->id,
                                'courtId' => $ub->court->id,
                                'date' => $ub->date instanceof \Carbon\Carbon ? $ub->date->format('Y-m-d') : (string) $ub->date,
                                'courtName' => $ub->court->name,
                                'startTime' => substr($ub->start_time, 0, 5),
                                'endTime' => substr($ub->end_time, 0, 5),
                                'price' => $ub->price,
                                'discount' => $ub->discount ?? 0,
                                'clientName' => $ub->client_name,
                                'clientPhone' => $ub->client_phone,
                                'paymentMethod' => $ub->payment_method,
                                'isPaid' => $ub->is_paid,
                                'isProcessed' => $ub->is_processed,
                                'comment' => $ub->comment,
                                'bookingType' => $ub->booking_type,
                                'coachId' => $ub->coach_id,
                                'coachPaid' => $ub->coach_paid,
                                'coaches' => $ub->coaches->map(fn($pc) => ['coachId' => (int) $pc->coach_id, 'price' => $pc->coach_price !== null ? (float) $pc->coach_price : null, 'paid' => (bool) $pc->coach_paid])->values(),
                                'clubCardId' => $ub->club_card_id,
                                'cardCharged' => (bool) $ub->card_charged_at,
                                'slotDuration' => $ub->court->slot_duration ?? 60,
                            ]) }})">
                        <i class="bi bi-eye"></i> Просмотреть
                    </button>
                </div>
            </div>
        @empty
            <div class="unprocessed-empty">
                <i class="bi bi-check-circle"></i>
                <p>Все заявки обработаны</p>
            </div>
        @endforelse
    </div>
</div>

<!-- Book Modal (Bootstrap 5) -->
<div class="modal fade" id="bookModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-wide">
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

                <div class="modal-two-col">
                    <!-- Left column: info, duration, price -->
                    <div class="modal-col-left">
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

                        <div class="modal-section-title">Длительность</div>
                        <div class="duration-selector" id="durationSelector"></div>

                        <div class="modal-section-title js-hide-for-group">Цена и скидка</div>
                        <div class="price-edit-row js-hide-for-group">
                            <div class="price-edit-group">
                                <label class="form-label">Цена корта</label>
                                <input type="number" name="custom_price" id="bookCustomPrice" class="form-input price-input" min="0" step="100" onchange="updateFinalPrice()" oninput="updateFinalPrice()">
                            </div>
                            <div class="price-edit-group">
                                <label class="form-label">Скидка</label>
                                <input type="number" name="discount" id="bookDiscount" class="form-input price-input" min="0" step="100" value="0" onchange="updateFinalPrice()" oninput="updateFinalPrice()">
                            </div>
                        </div>
                        <div class="total-price js-hide-for-group">
                            <div class="total-row">
                                <span class="total-sub-label">Цена за корт</span>
                                <span class="total-sub-value" id="bookCourtPrice"></span>
                            </div>
                            <div class="total-row" id="bookCoachTotalRow" style="display:none;">
                                <span class="total-sub-label">Цена за тренера</span>
                                <span class="total-sub-value" id="bookCoachTotal"></span>
                            </div>
                            <div class="total-row total-row-final">
                                <span class="total-price-label">Итого</span>
                                <span class="total-price-value" id="bookTotalPrice"></span>
                            </div>
                        </div>

                        <div class="modal-section-title js-hide-for-group">Тренер</div>
                        <div class="coach-buttons js-hide-for-group" id="bookCoachButtons">
                            @foreach($clubCoaches as $cc)
                                <button type="button" class="coach-btn" data-coach-id="{{ $cc->user_id }}" data-rate="{{ $cc->hourly_rate ?? 0 }}" onclick="selectBookCoach(this)">
                                    <span class="coach-btn-avatar">@if($cc->photo)<img src="{{ $cc->photo }}" alt="">@else{{ mb_strtoupper(mb_substr($cc->user->first_name ?? $cc->user->name ?? '?', 0, 1)) }}@endif</span>
                                    <span class="coach-btn-name">{{ $cc->user->full_name }}</span>
                                    @if($cc->hourly_rate)<span class="coach-rate">{{ number_format($cc->hourly_rate, 0, '', ' ') }} ₸</span>@endif
                                </button>
                            @endforeach
                        </div>
                        <input type="hidden" name="coach_id" id="bookCoachId" value="">
                        <div id="bookSelectedCoaches" class="sel-coaches js-hide-for-group"></div>

                        <div class="form-group" id="bookCoachPriceGroup" style="display:none; margin-top:14px;">
                            <label class="form-label">Цена тренера, ₸</label>
                            <input type="number" name="coach_price" id="bookCoachPrice" class="form-input" min="0" step="100" placeholder="0">
                            <small class="form-hint" style="color:#71717a;">По умолчанию — ставка тренера, можно изменить</small>
                        </div>

                        <div class="form-group js-hide-for-group" id="bookCoachPaidGroup" style="display:none; margin-top:14px;">
                            <label class="form-label">Оплата тренера</label>
                            <input type="hidden" name="coach_paid" id="bookCoachPaidInput" value="">
                            <div class="paid-toggle">
                                <button type="button" class="paid-btn" data-value="0" onclick="setBookCoachPaid(this)">Не оплачен</button>
                                <button type="button" class="paid-btn" data-value="1" onclick="setBookCoachPaid(this)">Оплачен</button>
                            </div>
                        </div>
                    </div>

                    <!-- Right column: client, payment, coach -->
                    <div class="modal-col-right">
                        <div class="form-group autocomplete-wrap js-hide-for-group">
                            <label class="form-label">Напишите имя и фамилию клиента *</label>
                            <input type="text" name="client_name" id="bookClientName" class="form-input" placeholder="Например: Денис Дудников" required autocomplete="off">
                            <div class="autocomplete-list" id="bookNameList"></div>
                            <small class="form-hint" id="bookClientNameHint" style="display:none;">Имя из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».</small>
                        </div>
                        <div class="form-group autocomplete-wrap js-hide-for-group">
                            <label class="form-label">Телефон *</label>
                            <input type="text" name="client_phone" id="bookClientPhone" class="form-input" placeholder="+7 (___) ___-__-__" required autocomplete="off">
                            <div class="autocomplete-list" id="bookPhoneList"></div>
                        </div>

                        <div class="form-group js-hide-for-group">
                            <label class="form-label">Заметка о клиенте</label>
                            <textarea name="client_note" id="bookClientNote" class="form-input" rows="2" placeholder="Например: ВИП, играет с тренером, оплачивает картой"></textarea>
                            <small class="form-hint" id="bookClientNoteHint" style="display:none;">Заметка из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».</small>
                        </div>

                        <div class="form-group" id="bookCardWrap" style="display:none;">
                            <label class="form-label">Клубная карта клиента</label>
                            <input type="hidden" name="club_card_id" id="bookCardInput" value="">
                            <div class="client-card-buttons" id="bookCardButtons"></div>
                            <small class="form-hint" id="bookCardHint" style="display:none;"></small>
                        </div>

                        <div class="form-group" id="bookCertWrap" style="display:none;">
                            <label class="form-label">Сертификаты клиента</label>
                            <input type="hidden" name="certificate_id" id="bookCertInput" value="">
                            <div class="client-card-buttons" id="bookCertButtons"></div>
                            <small class="form-hint" id="bookCertHint" style="display:none;"></small>
                        </div>

                        <div class="modal-section-title">Тип брони</div>
                        <div class="booking-type-buttons" id="bookingTypeButtons">
                            <button type="button" class="bt-btn bt-soft" data-value="soft" onclick="selectBookingType(this)"><i class="bi bi-clock-history"></i><span>Мягкая бронь</span></button>
                            <button type="button" class="bt-btn bt-group" data-value="group" onclick="selectBookingType(this)"><i class="bi bi-people"></i><span>Групповые</span></button>
                            <button type="button" class="bt-btn bt-individual" data-value="individual" onclick="selectBookingType(this)"><i class="bi bi-person"></i><span>Индивидуальные</span></button>
                            <button type="button" class="bt-btn bt-tournament" data-value="tournament" onclick="selectBookingType(this)"><i class="bi bi-trophy"></i><span>Турнир</span></button>
                        </div>
                        <input type="hidden" name="booking_type" id="bookingTypeInput">

                        @if(isset($activeGroups) && $activeGroups->count())
                            <div id="bookGroupSelectWrap" style="display:none;">
                                <div class="modal-section-title">Группа (создаст занятие в журнале)</div>
                                <div class="form-group">
                                    <select name="group_id" id="bookGroupSelect" class="form-input" onchange="renderGroupMembers(this.value)">
                                        <option value="">— без привязки к группе —</option>
                                        @foreach($activeGroups as $g)
                                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div id="gmPrice" style="display:none;margin:8px 0;color:#a1a1aa;font-size:13px;"></div>
                                <div id="groupMembersBlock" class="group-members-block" style="display:none;">
                                    <div class="gm-header">
                                        <span class="gm-title">Участники</span>
                                        <span class="gm-count" id="gmCount"></span>
                                    </div>
                                    <ul id="gmList" class="gm-list"></ul>
                                    <div id="gmEmpty" class="gm-empty" style="display:none;">В группе пока нет участников</div>
                                    <a id="gmScheduleLink" href="#" target="_blank" rel="noopener"
                                       style="display:none;align-items:center;justify-content:center;gap:8px;margin-top:10px;padding:10px 12px;border-radius:10px;background:rgba(34,197,94,0.10);border:1px solid rgba(34,197,94,0.35);color:#34d17f;font-size:13px;font-weight:700;text-decoration:none;">
                                        <i class="bi bi-calendar3"></i> Всё расписание группы
                                    </a>
                                </div>
                                <div id="groupCoachBlock" class="form-group" style="display:none;">
                                    <label for="bookGroupCoachSelect" class="form-label">Тренер</label>
                                    <select id="bookGroupCoachSelect" class="form-input" onchange="document.getElementById('bookCoachId').value = this.value;">
                                        <option value="">— без тренера —</option>
                                        @foreach($clubCoaches as $cc)
                                            <option value="{{ $cc->user_id }}">{{ $cc->user->full_name }}</option>
                                        @endforeach
                                    </select>
                                    <small class="form-hint" id="bookGroupCoachHint" style="display:none;color:#a1a1aa;font-size:12px;margin-top:6px;">По умолчанию — тренер группы</small>
                                </div>
                            </div>
                            @php
                                $gmFreezeDate = \Illuminate\Support\Carbon::parse($date);
                                $groupMembersData = $activeGroups->mapWithKeys(function ($g) use ($gmFreezeDate) {
                                    return [$g->id => [
                                        'coach_id' => $g->coach_id,
                                        'price' => (float) $g->price_per_session,
                                        'members' => $g->members->map(function ($m) use ($gmFreezeDate) {
                                            $bought = (int) $m->enrollments->sum('sessions');
                                            $used = (int) $m->attendance->where('charged', true)->count();
                                            $freeze = $m->freezes->first(fn($f) => $f->freeze_from->lte($gmFreezeDate) && $f->freeze_until->gte($gmFreezeDate));
                                            return [
                                                'name' => optional($m->client)->name ?? '—',
                                                'remaining' => $bought - $used,
                                                'frozen' => $freeze !== null,
                                                'frozen_until' => $freeze ? $freeze->freeze_until->format('d.m.y') : null,
                                                'not_started' => $m->starts_at && $m->starts_at->gt($gmFreezeDate),
                                                'starts_at' => $m->starts_at ? $m->starts_at->format('d.m.y') : null,
                                                'note' => $m->note,
                                            ];
                                        })->values(),
                                    ]];
                                })->toArray();
                            @endphp
                            <script>window.__groupMembers = @json($groupMembersData);</script>
                        @endif
                        <script>window.__coachNames = @json($clubCoaches->mapWithKeys(fn($cc) => [$cc->user_id => ($cc->user->full_name ?? '')])->toArray());</script>

                        <div class="modal-section-title js-hide-for-group">Способ оплаты</div>
                        <div class="payment-methods js-hide-for-group" id="paymentMethods">
                            <button type="button" class="pay-btn" data-value="cash" onclick="selectPayment(this)"><i class="bi bi-cash-stack"></i><span>Наличные</span></button>
                            <button type="button" class="pay-btn" data-value="card" onclick="selectPayment(this)"><i class="bi bi-credit-card-2-front"></i><span>Карта</span></button>
                            <button type="button" class="pay-btn" data-value="kaspi" onclick="selectPayment(this)"><i class="bi bi-qr-code"></i><span>Kaspi</span></button>
                            <button type="button" class="pay-btn" data-value="certificate" onclick="selectPayment(this)"><i class="bi bi-award"></i><span>Сертификат</span></button>
                            <button type="button" class="pay-btn" data-value="club_card" onclick="selectPayment(this)"><i class="bi bi-person-vcard"></i><span>Клубная карта</span></button>
                            <button type="button" class="pay-btn" data-value="deposit" onclick="selectPayment(this)"><i class="bi bi-wallet2"></i><span>Депозит</span></button>
                            <button type="button" class="pay-btn" data-value="cashback" onclick="selectPayment(this)"><i class="bi bi-arrow-repeat"></i><span>Кешбэк</span></button>
                            <button type="button" class="pay-btn" data-value="cashless" onclick="selectPayment(this)"><i class="bi bi-bank"></i><span>Безналичный</span></button>
                            <button type="button" class="pay-btn" data-value="free" onclick="selectPayment(this)"><i class="bi bi-gift"></i><span>Бесплатно</span></button>
                        </div>
                        <input type="hidden" name="payment_method" id="paymentMethodInput">

                        <div class="form-group js-hide-for-group" style="margin-top: 14px;">
                            <label class="form-label">Статус оплаты *</label>
                            <input type="hidden" name="is_paid" id="isPaidInput" value="">
                            <div class="paid-toggle">
                                <button type="button" class="paid-btn" data-value="0" onclick="setPaid(this)">Не оплачено</button>
                                <button type="button" class="paid-btn" data-value="1" onclick="setPaid(this)">Оплачено</button>
                            </div>
                        </div>

                        <div id="groupBookingHint" class="form-group js-show-for-group" style="display:none;">
                            <div style="padding:12px 14px;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.25);border-radius:10px;color:#a1a1aa;font-size:13px;line-height:1.5;">
                                Для групповой брони данные о клиенте и оплате не требуются — занятие добавится в «Журнал занятий», оплата идёт через пакеты участников группы.
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Комментарий</label>
                            <textarea name="comment" class="form-input" rows="2" placeholder="Заметка к бронированию"></textarea>
                        </div>

                        <div style="text-align: center; margin-top: 16px;">
                            <button type="button" class="btn-block-slot" onclick="blockSlot()">Заблокировать слот</button>
                        </div>
                    </div>
                </div>

                @include('club.courts.partials._book_repeat')

                <div class="book-form-error" id="bookFormError" role="alert" aria-live="polite">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span class="book-form-error-text"></span>
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
                <input type="hidden" name="comment" id="newBlockComment">
            </form>
        </div>
    </div>
</div>

<!-- Edit Booking Modal (Bootstrap 5) -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-wide">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="sch-modal-header">
                <h2>Редактирование брони</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <form id="editBookingForm" method="POST" novalidate>
                @csrf
                @method('PUT')

                <div class="modal-two-col">
                    <!-- Left column: info, price -->
                    <div class="modal-col-left">
                        <div class="sch-modal-info">
                            <div class="sch-modal-info-row">
                                <span class="sch-modal-info-label">Корт</span>
                                <span class="sch-modal-info-value" id="viewCourtName"></span>
                            </div>
                            <div class="sch-modal-info-row">
                                <span class="sch-modal-info-label">Дата</span>
                                <span class="sch-modal-info-value" id="viewDate"></span>
                            </div>
                            <div class="sch-modal-info-row">
                                <span class="sch-modal-info-label">Начало</span>
                                <span class="sch-modal-info-value" id="viewTime"></span>
                            </div>
                            <div class="sch-modal-info-row">
                                <span class="sch-modal-info-label">Цена/час</span>
                                <span class="sch-modal-info-value" id="viewPrice"></span>
                            </div>
                        </div>

                        <div class="modal-section-title">Длительность</div>
                        <input type="hidden" name="slots" id="editSlots" value="">
                        <div class="duration-selector" id="editDurationSelector"></div>

                        <div class="modal-section-title js-edit-hide-for-group">Цена и скидка</div>
                        <div class="price-edit-row js-edit-hide-for-group">
                            <div class="price-edit-group">
                                <label class="form-label">Цена</label>
                                <input type="number" name="custom_price" id="editCustomPrice" class="form-input price-input" min="0" step="100" onchange="updateEditFinalPrice()" oninput="updateEditFinalPrice()">
                            </div>
                            <div class="price-edit-group">
                                <label class="form-label">Скидка</label>
                                <input type="number" name="discount" id="editDiscount" class="form-input price-input" min="0" step="100" value="0" onchange="updateEditFinalPrice()" oninput="updateEditFinalPrice()">
                            </div>
                        </div>
                        <div class="total-price js-edit-hide-for-group">
                            <div class="total-row">
                                <span class="total-sub-label">Цена за корт</span>
                                <span class="total-sub-value" id="editCourtPrice"></span>
                            </div>
                            <div class="total-row" id="editCoachTotalRow" style="display:none;">
                                <span class="total-sub-label">Цена за тренера</span>
                                <span class="total-sub-value" id="editCoachTotal"></span>
                            </div>
                            <div class="total-row total-row-final">
                                <span class="total-price-label">Итого</span>
                                <span class="total-price-value" id="editTotalPrice"></span>
                            </div>
                        </div>

                        <div class="form-group" id="editProcessedGroup" style="display:none;">
                            <label class="form-label">Статус обработки</label>
                            <input type="hidden" name="is_processed" id="editIsProcessedInput" value="1">
                            <div class="paid-toggle">
                                <button type="button" class="processed-btn" data-value="0" onclick="setEditProcessed(this)">Не обработан</button>
                                <button type="button" class="processed-btn" data-value="1" onclick="setEditProcessed(this)">Обработан</button>
                            </div>
                        </div>

                        <div class="modal-section-title js-edit-hide-for-group">Тренер</div>
                        <div class="coach-buttons js-edit-hide-for-group" id="editCoachButtons">
                            @foreach($clubCoaches as $cc)
                                <button type="button" class="coach-btn" data-coach-id="{{ $cc->user_id }}" data-rate="{{ $cc->hourly_rate ?? 0 }}" onclick="selectEditCoach(this)">
                                    <span class="coach-btn-avatar">@if($cc->photo)<img src="{{ $cc->photo }}" alt="">@else{{ mb_strtoupper(mb_substr($cc->user->first_name ?? $cc->user->name ?? '?', 0, 1)) }}@endif</span>
                                    <span class="coach-btn-name">{{ $cc->user->full_name }}</span>
                                    @if($cc->hourly_rate)<span class="coach-rate">{{ number_format($cc->hourly_rate, 0, '', ' ') }} ₸</span>@endif
                                </button>
                            @endforeach
                        </div>
                        <input type="hidden" name="coach_id" id="editCoachId" value="">
                        <div id="editSelectedCoaches" class="sel-coaches js-edit-hide-for-group"></div>

                        <div class="form-group" id="editCoachPriceGroup" style="display:none; margin-top:14px;">
                            <label class="form-label">Цена тренера, ₸</label>
                            <input type="number" name="coach_price" id="editCoachPrice" class="form-input" min="0" step="100" placeholder="0">
                            <small class="form-hint" style="color:#71717a;">По умолчанию — ставка тренера, можно изменить</small>
                        </div>

                        <div class="form-group" id="editCoachPaidGroup" style="display:none; margin-top:14px;">
                            <label class="form-label">Оплата тренера</label>
                            <input type="hidden" name="coach_paid" id="editCoachPaidInput" value="">
                            <div class="paid-toggle">
                                <button type="button" class="paid-btn" data-value="0" onclick="setEditCoachPaid(this)">Не оплачен</button>
                                <button type="button" class="paid-btn" data-value="1" onclick="setEditCoachPaid(this)">Оплачен</button>
                            </div>
                        </div>
                    </div>

                    <!-- Right column: client, payment, coach -->
                    <div class="modal-col-right">
                        <div class="form-group autocomplete-wrap">
                            <label class="form-label" id="editClientLabel">Напишите имя и фамилию клиента *</label>
                            <input type="text" name="client_name" id="editClientName" class="form-input" placeholder="Например: Денис Дудников" autocomplete="off" required>
                            <div class="autocomplete-list" id="editNameList"></div>
                            <small class="form-hint" id="editClientNameHint" style="display:none;">Имя из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».</small>
                        </div>
                        <!-- Группа: участники + тренер (порядок как в окне создания) -->
                        <div id="editGroupBlock" style="display:none;">
                            <div id="editGmPrice" style="display:none;margin:4px 0 12px;color:#a1a1aa;font-size:13px;"></div>
                            <div class="group-members-block">
                                <div class="gm-header">
                                    <span class="gm-title">Участники</span>
                                    <span class="gm-count" id="editGmCount"></span>
                                </div>
                                <ul id="editGmList" class="gm-list"></ul>
                                <div id="editGmEmpty" class="gm-empty" style="display:none;">В группе пока нет участников</div>
                                <a id="editGmScheduleLink" href="#" target="_blank" rel="noopener"
                                   style="display:none;align-items:center;justify-content:center;gap:8px;margin-top:10px;padding:10px 12px;border-radius:10px;background:rgba(34,197,94,0.10);border:1px solid rgba(34,197,94,0.35);color:#34d17f;font-size:13px;font-weight:700;text-decoration:none;">
                                    <i class="bi bi-calendar3"></i> Всё расписание группы
                                </a>
                            </div>
                            <div class="modal-section-title" style="margin-top:14px;">Тренер</div>
                            <div class="form-group">
                                <select id="editGroupCoachSelect" class="form-input" onchange="document.getElementById('editCoachId').value = this.value;">
                                    <option value="">— без тренера —</option>
                                    @foreach($clubCoaches as $cc)
                                        <option value="{{ $cc->user_id }}">{{ $cc->user->full_name }}</option>
                                    @endforeach
                                </select>
                                <small class="form-hint" style="color:#a1a1aa;font-size:12px;margin-top:6px;">Можно заменить, если занятие проведёт другой тренер. По умолчанию — тренер группы.</small>
                            </div>
                            <div style="margin-top:14px;padding:12px 14px;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.25);border-radius:10px;color:#a1a1aa;font-size:13px;line-height:1.5;">
                                Для групповой брони данные о клиенте и оплате не требуются — занятие добавится в «Журнал занятий», оплата идёт через пакеты участников группы.
                            </div>
                        </div>

                        <div class="form-group autocomplete-wrap js-edit-hide-for-group">
                            <label class="form-label">Телефон *</label>
                            <input type="text" name="client_phone" id="editClientPhone" class="form-input" placeholder="+7 (___) ___-__-__" autocomplete="off" required>
                            <div class="autocomplete-list" id="editPhoneList"></div>
                        </div>

                        <div class="form-group js-edit-hide-for-group">
                            <label class="form-label">Заметка о клиенте</label>
                            <textarea name="client_note" id="editClientNote" class="form-input" rows="2" placeholder="Например: ВИП, играет с тренером, оплачивает картой"></textarea>
                            <small class="form-hint" id="editClientNoteHint" style="display:none;">Заметка из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».</small>
                        </div>

                        <div class="form-group" id="editCardWrap" style="display:none;">
                            <label class="form-label">Клубная карта клиента</label>
                            <input type="hidden" name="club_card_id" id="editCardInput" value="">
                            <div class="client-card-buttons" id="editCardButtons"></div>
                            <small class="form-hint" id="editCardHint" style="display:none;"></small>
                        </div>

                        <div class="form-group" id="editCertWrap" style="display:none;">
                            <label class="form-label">Сертификаты клиента</label>
                            <input type="hidden" name="certificate_id" id="editCertInput" value="">
                            <div class="client-card-buttons" id="editCertButtons"></div>
                            <small class="form-hint" id="editCertHint" style="display:none;"></small>
                        </div>

                        <div class="modal-section-title">Тип брони</div>
                        <div class="booking-type-buttons" id="editBookingTypeButtons">
                            <button type="button" class="bt-btn bt-soft" data-value="soft" onclick="selectEditBookingType(this)"><i class="bi bi-clock-history"></i><span>Мягкая бронь</span></button>
                            <button type="button" class="bt-btn bt-group" data-value="group" onclick="selectEditBookingType(this)"><i class="bi bi-people"></i><span>Групповые</span></button>
                            <button type="button" class="bt-btn bt-individual" data-value="individual" onclick="selectEditBookingType(this)"><i class="bi bi-person"></i><span>Индивидуальные</span></button>
                            <button type="button" class="bt-btn bt-tournament" data-value="tournament" onclick="selectEditBookingType(this)"><i class="bi bi-trophy"></i><span>Турнир</span></button>
                        </div>
                        <input type="hidden" name="booking_type" id="editBookingTypeInput">

                        <div class="modal-section-title js-edit-hide-for-group">Способ оплаты *</div>
                        <div class="payment-methods js-edit-hide-for-group" id="editPaymentMethods">
                            <button type="button" class="pay-btn" data-value="cash" onclick="selectEditPayment(this)"><i class="bi bi-cash-stack"></i><span>Наличные</span></button>
                            <button type="button" class="pay-btn" data-value="card" onclick="selectEditPayment(this)"><i class="bi bi-credit-card-2-front"></i><span>Карта</span></button>
                            <button type="button" class="pay-btn" data-value="kaspi" onclick="selectEditPayment(this)"><i class="bi bi-qr-code"></i><span>Kaspi</span></button>
                            <button type="button" class="pay-btn" data-value="certificate" onclick="selectEditPayment(this)"><i class="bi bi-award"></i><span>Сертификат</span></button>
                            <button type="button" class="pay-btn" data-value="club_card" onclick="selectEditPayment(this)"><i class="bi bi-person-vcard"></i><span>Клубная карта</span></button>
                            <button type="button" class="pay-btn" data-value="deposit" onclick="selectEditPayment(this)"><i class="bi bi-wallet2"></i><span>Депозит</span></button>
                            <button type="button" class="pay-btn" data-value="cashback" onclick="selectEditPayment(this)"><i class="bi bi-arrow-repeat"></i><span>Кешбэк</span></button>
                            <button type="button" class="pay-btn" data-value="cashless" onclick="selectEditPayment(this)"><i class="bi bi-bank"></i><span>Безналичный</span></button>
                            <button type="button" class="pay-btn" data-value="free" onclick="selectEditPayment(this)"><i class="bi bi-gift"></i><span>Бесплатно</span></button>
                            <button type="button" class="pay-btn" data-value="plexy" onclick="selectEditPayment(this)"><i class="bi bi-phone"></i><span>Онлайн</span></button>
                        </div>
                        <input type="hidden" name="payment_method" id="editPaymentMethodInput">

                        <div class="form-group js-edit-hide-for-group" style="margin-top: 14px;">
                            <label class="form-label">Статус оплаты *</label>
                            <input type="hidden" name="is_paid" id="editIsPaidInput" value="">
                            <div class="paid-toggle">
                                <button type="button" class="paid-btn" data-value="0" onclick="setEditPaid(this)">Не оплачено</button>
                                <button type="button" class="paid-btn" data-value="1" onclick="setEditPaid(this)">Оплачено</button>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 14px;">
                            <label class="form-label">Комментарий</label>
                            <textarea name="comment" id="editComment" class="form-input" rows="2" placeholder="Заметка к бронированию"></textarea>
                        </div>

                    </div>
                </div>

                <div class="book-form-error" id="editFormError" role="alert" aria-live="polite">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span class="book-form-error-text"></span>
                </div>

                <div class="sch-modal-footer" style="flex-direction: column; gap: 8px;">
                    <div style="display: flex; gap: 12px; width: 100%;">
                        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Закрыть</button>
                        <button type="submit" class="btn-confirm">Сохранить</button>
                    </div>
                    <button type="button" class="btn-danger" style="width: 100%;" id="cancelBookingBtn" onclick="cancelBooking()">Отменить бронь</button>
                </div>
            </form>
            <form id="cancelBookingForm" method="POST" style="display:none;">
                @csrf
                <input type="hidden" name="reason" id="cancelBookingReason" value="">
            </form>
        </div>
    </div>
</div>

<!-- Причина отмены групповой брони -->
<div id="cancelBookingReasonModal" class="gcancel-modal" style="display:none;">
    <div class="gcancel-box">
        <h3 class="gcancel-title">Отменить бронь занятия?</h3>
        <p class="gcancel-sub">Корт освободится, занятие отменится. Причину видно в журнале группы — по ней потом понятно, за что отменили.</p>
        <label class="gcancel-label">Причина отмены</label>
        <textarea id="cancelBookingReasonText" class="gcancel-textarea" rows="3" maxlength="255" placeholder="Например: заболел тренер, нет игроков, перенос…"></textarea>
        <div id="cancelReasonError" style="display:none;color:#ef4444;font-size:13px;margin-top:6px;font-weight:600;">Укажите причину отмены — минимум 5 символов.</div>
        <div class="gcancel-actions">
            <button type="button" class="gcancel-btn-secondary" onclick="document.getElementById('cancelBookingReasonModal').style.display='none'">Назад</button>
            <button type="button" class="gcancel-btn-danger" onclick="submitCancelWithReason()">Отменить бронь</button>
        </div>
    </div>
</div>
<style>
.gcancel-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 3000; align-items: center; justify-content: center; padding: 20px; }
.gcancel-box { background: #131619; border: 1px solid #27272a; border-radius: 16px; padding: 24px; width: 100%; max-width: 440px; box-shadow: 0 20px 60px rgba(0,0,0,0.5); }
.gcancel-title { font-size: 20px; font-weight: 800; color: #f4f4f5; margin: 0 0 6px; }
.gcancel-sub { font-size: 14px; color: #a1a1aa; line-height: 1.5; margin: 0 0 16px; }
.gcancel-label { display: block; font-size: 13px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
.gcancel-textarea { width: 100%; background: #0c0e0f; border: 1px solid #27272a; border-radius: 10px; padding: 12px 14px; color: #e4e4e7; font-size: 15px; font-family: inherit; resize: vertical; box-sizing: border-box; }
.gcancel-textarea:focus { outline: none; border-color: #ef4444; }
.gcancel-actions { display: flex; gap: 10px; margin-top: 18px; }
.gcancel-btn-secondary { flex: 1; padding: 12px; border-radius: 10px; border: 1px solid #27272a; background: #16161a; color: #a1a1aa; font-size: 15px; font-weight: 700; cursor: pointer; }
.gcancel-btn-secondary:hover { border-color: #3f3f46; color: #e4e4e7; }
.gcancel-btn-danger { flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
.gcancel-btn-danger:hover { background: #dc2626; }
</style>

<!-- Unblock Confirmation Modal (Bootstrap 5) -->
<div class="modal fade" id="unblockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="sch-modal-header">
                <h2>Заблокированный слот</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <form id="updateBlockForm" method="POST">
                @csrf
                @method('PUT')
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
                    <div class="form-group" style="margin-top: 16px;">
                        <label class="form-label">Комментарий</label>
                        <textarea name="comment" id="blockComment" class="form-input" rows="2" placeholder="Заметка к блокировке"></textarea>
                    </div>
                </div>
                <div class="sch-modal-footer" style="flex-direction: column; gap: 8px;">
                    <div style="display: flex; gap: 12px; width: 100%;">
                        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Закрыть</button>
                        <button type="submit" class="btn-confirm">Сохранить</button>
                    </div>
                    <button type="button" class="btn-danger" style="width: 100%;" onclick="unblockSlot()">Разблокировать</button>
                </div>
            </form>
            <form id="unblockForm" method="POST" style="display:none;">
                @csrf
                @method('DELETE')
            </form>
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
    const freeSlotsByDate = @json($freeSlotsByDate);
    const pageDate = @json($date);

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
        const slots = parseInt(document.getElementById('bookSlots').value) || 1;
        const startIdx = orderedTimes.indexOf(currentBook.time);
        if (startIdx >= 0 && startIdx + slots < orderedTimes.length) {
            return orderedTimes[startIdx + slots];
        }
        const [h, m] = currentBook.time.split(':').map(Number);
        // % 24: последний слот 23:00 + 1ч → «00:00» (не «24:00», которое
        // отклоняет валидатор H:i на бэке и блокировка не создаётся).
        const nh = (h + slots) % 24;
        return String(nh).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }

    function updateBookTotalPrice() {
        const autoPrice = calcTotalPrice();
        document.getElementById('bookCustomPrice').value = autoPrice;
        document.getElementById('bookDiscount').value = 0;
        updateFinalPrice();
        document.getElementById('bookSlots').value = currentBook.duration;
        // Длительность сбросила цену/скидку — переприменим выбранную карту/сертификат.
        if (selectedCard.book) applyCardPricing('book');
        if (selectedCert.book) applyCertPricing('book');
        // Пересчёт цены тренера под новую длительность.
        recalcBookCoachPrice();
    }

    // Пересчитать цену тренера = ставка × длительность (если тренер выбран).
    function recalcBookCoachPrice() {
        const coachId = document.getElementById('bookCoachId').value;
        const priceInput = document.getElementById('bookCoachPrice');
        if (!coachId || !priceInput) return;
        const btn = document.querySelector('#bookCoachButtons .coach-btn.active');
        const rate = btn ? (parseFloat(btn.getAttribute('data-rate')) || 0) : 0;
        const dur = (typeof currentBook === 'object' && currentBook.duration) ? currentBook.duration : 1;
        if (rate > 0) priceInput.value = Math.round(rate * dur);
    }

    // Сумма цен всех выбранных тренеров (мультитренер).
    function sumBookCoachPrices() {
        let sum = 0;
        document.querySelectorAll('#bookSelectedCoaches .sel-coach-price').forEach(inp => {
            sum += parseInt(inp.value) || 0;
        });
        return sum;
    }
    function updateFinalPrice() {
        const price = parseInt(document.getElementById('bookCustomPrice').value) || 0;
        const discount = parseInt(document.getElementById('bookDiscount').value) || 0;
        const courtPrice = Math.max(0, price - discount);
        const coachTotal = sumBookCoachPrices();
        document.getElementById('bookCourtPrice').innerHTML = formatPrice(courtPrice) + ' &#8376;';
        const coachRow = document.getElementById('bookCoachTotalRow');
        if (coachTotal > 0) {
            document.getElementById('bookCoachTotal').innerHTML = formatPrice(coachTotal) + ' &#8376;';
            coachRow.style.display = '';
        } else {
            coachRow.style.display = 'none';
        }
        document.getElementById('bookTotalPrice').innerHTML = formatPrice(courtPrice + coachTotal) + ' &#8376;';
    }
    // Правка цены тренера в строке → пересчёт итога.
    document.getElementById('bookSelectedCoaches')?.addEventListener('input', updateFinalPrice);

    // ===== Итог в окне редактирования (корт + тренеры) =====
    function sumEditCoachPrices() {
        let sum = 0;
        document.querySelectorAll('#editSelectedCoaches .sel-coach-price').forEach(inp => {
            sum += parseInt(inp.value) || 0;
        });
        return sum;
    }
    function updateEditFinalPrice() {
        const priceEl = document.getElementById('editCustomPrice');
        if (!priceEl) return;
        const price = parseInt(priceEl.value) || 0;
        const discount = parseInt(document.getElementById('editDiscount').value) || 0;
        const courtPrice = Math.max(0, price - discount);
        const coachTotal = sumEditCoachPrices();
        document.getElementById('editCourtPrice').innerHTML = formatPrice(courtPrice) + ' &#8376;';
        const coachRow = document.getElementById('editCoachTotalRow');
        if (coachTotal > 0) {
            document.getElementById('editCoachTotal').innerHTML = formatPrice(coachTotal) + ' &#8376;';
            coachRow.style.display = '';
        } else {
            coachRow.style.display = 'none';
        }
        document.getElementById('editTotalPrice').innerHTML = formatPrice(courtPrice + coachTotal) + ' &#8376;';
    }
    document.getElementById('editSelectedCoaches')?.addEventListener('input', updateEditFinalPrice);

    // «2026-07-29» → «29 июля 2026».
    function formatDateRu(iso) {
        if (!iso) return '';
        const m = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                   'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        const p = String(iso).split('-');
        if (p.length !== 3) return iso;
        return parseInt(p[2], 10) + ' ' + (m[parseInt(p[1], 10)] || '') + ' ' + p[0];
    }

    // ====== Клубные карты в окне брони (кнопки) ======
    const cardsForClientUrl = @json(route('club.cards.forClient'));
    const cardCache = { book: [], edit: [] };
    const selectedCard = { book: null, edit: null };
    let cardLoadTimer = null;

    // Локальный escape (escHtml из автокомплита заперт в своём IIFE и здесь недоступен).
    function cardEsc(s) {
        const d = document.createElement('div');
        d.textContent = (s == null ? '' : String(s));
        return d.innerHTML;
    }

    // id редактируемой брони — чтобы исключить её из резерва часов карты
    // (иначе своя же бронь показала бы «нет свободных часов»).
    let editingBookingId = null;

    function loadClientCards(prefix, phone, preselectId, preselectCertId) {
        loadClientCertificates(prefix, phone, preselectCertId);
        const wrap = document.getElementById(prefix + 'CardWrap');
        const box = document.getElementById(prefix + 'CardButtons');
        const input = document.getElementById(prefix + 'CardInput');
        const hint = document.getElementById(prefix + 'CardHint');
        if (!wrap || !box) return;
        const reset = () => {
            wrap.style.display = 'none'; box.innerHTML = '';
            if (input) input.value = '';
            if (hint) hint.style.display = 'none';
            selectedCard[prefix] = null; cardCache[prefix] = [];
        };
        const digits = (phone || '').replace(/\D/g, '');
        if (digits.length < 5) { reset(); return; }
        let url = cardsForClientUrl + '?phone=' + encodeURIComponent(digits);
        if (preselectId) url += '&include_card_id=' + encodeURIComponent(preselectId);
        if (prefix === 'edit' && editingBookingId) url += '&exclude_booking_id=' + encodeURIComponent(editingBookingId);
        fetch(url)
            .then(r => r.json())
            .then(d => {
                // Неактивную (списанную/просроченную) карту показываем только если
                // это карта текущей брони — свободный выбор списанных карт запрещён.
                const cards = ((d && d.cards) ? d.cards : []).filter(c =>
                    !c.inactive || String(c.id) === String(preselectId));
                cardCache[prefix] = cards;
                selectedCard[prefix] = null;
                if (input) input.value = '';
                if (!cards.length) { reset(); return; }
                box.innerHTML = cards.map(c => {
                    const avail = (c.available != null ? c.available : c.balance);
                    const noHours = !!c.is_counter && avail <= 0; // все часы зарезервированы
                    const isPre = String(c.id) === String(preselectId);
                    const blocked = (c.inactive || noHours) && !isPre;
                    const cap = (c.capacity != null ? c.capacity : c.nominal);
                    const sub = c.is_counter ? ('осталось ' + avail + '/' + cap + ' ч')
                                             : ('скидка −' + c.discount_percent + '%');
                    let note = '';
                    if (c.inactive) note = ' · списано, не активна';
                    else if (noHours) note = ' · нет свободных часов';
                    const cls = 'client-card-btn' + (blocked ? ' inactive' : '');
                    const clickAttr = blocked ? '' : ' onclick="onCardButton(\'' + prefix + '\',' + c.id + ')"';
                    return '<button type="button" class="' + cls + '" data-id="' + c.id + '"' + clickAttr + '>' +
                        '<span class="ccb-name">' + cardEsc(c.type_name || 'Карта') + '</span>' +
                        '<span class="ccb-code">' + cardEsc(c.code) + '</span>' +
                        '<span class="ccb-sub">' + sub + note + '</span></button>';
                }).join('');
                wrap.style.display = '';
                if (preselectId) onCardButton(prefix, preselectId, true);
            })
            .catch(() => reset());
    }

    function onCardButton(prefix, cardId, keepIfSelected) {
        const card = (cardCache[prefix] || []).find(c => String(c.id) === String(cardId));
        if (!card) return;
        const box = document.getElementById(prefix + 'CardButtons');
        const input = document.getElementById(prefix + 'CardInput');
        const hint = document.getElementById(prefix + 'CardHint');
        const already = selectedCard[prefix] && String(selectedCard[prefix].id) === String(cardId);

        if (already && !keepIfSelected) {
            // Повторный клик — снять выбор.
            selectedCard[prefix] = null;
            if (input) input.value = '';
            if (box) box.querySelectorAll('.client-card-btn').forEach(b => b.classList.remove('active'));
            if (hint) hint.style.display = 'none';
            applyCardPricing(prefix);
            return;
        }

        selectedCard[prefix] = card;
        if (input) input.value = card.id;
        if (box) box.querySelectorAll('.client-card-btn').forEach(b =>
            b.classList.toggle('active', String(b.dataset.id) === String(cardId)));

        setPaymentClubCard(prefix);   // способ оплаты → «Клубная карта»
        applyCardPricing(prefix);     // цена/скидка из карты

        if (hint) {
            hint.textContent = card.is_counter
                ? ('Спишутся часы по длительности брони после её завершения (остаток: ' + (card.available != null ? card.available : card.balance) + ' ч).')
                : ('Скидка −' + card.discount_percent + '% применена к цене.');
            hint.style.display = '';
        }
    }

    // Снять выбор карты (напр. при переключении способа оплаты на другой).
    function deselectCard(prefix) {
        if (!selectedCard[prefix]) return;
        selectedCard[prefix] = null;
        const input = document.getElementById(prefix + 'CardInput');
        const box = document.getElementById(prefix + 'CardButtons');
        const hint = document.getElementById(prefix + 'CardHint');
        if (input) input.value = '';
        if (box) box.querySelectorAll('.client-card-btn').forEach(b => b.classList.remove('active'));
        if (hint) hint.style.display = 'none';
        applyCardPricing(prefix); // вернёт обычную цену/скидку
    }

    function setPaymentClubCard(prefix) {
        const sel = (prefix === 'book') ? '#paymentMethods' : '#editPaymentMethods';
        const inputId = (prefix === 'book') ? 'paymentMethodInput' : 'editPaymentMethodInput';
        document.querySelectorAll(sel + ' .pay-btn').forEach(b =>
            b.classList.toggle('active', b.getAttribute('data-value') === 'club_card'));
        const inp = document.getElementById(inputId);
        if (inp) inp.value = 'club_card';
    }

    function applyCardPricing(prefix) {
        const card = selectedCard[prefix];
        const priceEl = document.getElementById(prefix === 'book' ? 'bookCustomPrice' : 'editCustomPrice');
        const discEl = document.getElementById(prefix === 'book' ? 'bookDiscount' : 'editDiscount');
        if (!priceEl || !discEl) return;

        if (!card) {
            // Карта снята — вернуть обычную цену корта и нулевую скидку.
            discEl.value = 0;
            if (prefix === 'book') {
                if (typeof calcTotalPrice === 'function') priceEl.value = calcTotalPrice();
                updateFinalPrice();
            }
            return;
        }
        if (card.is_counter) {
            // Цена за час = стоимость карты ÷ число занятий, ×длительность.
            const nominal = parseInt(card.nominal) || 0;
            const cardPrice = parseInt(card.price) || 0;
            if (nominal > 0 && cardPrice > 0) {
                const perHour = Math.round(cardPrice / nominal);
                const slots = (prefix === 'book' && typeof currentBook === 'object') ? (currentBook.duration || 1) : 1;
                priceEl.value = perHour * slots;
            }
            discEl.value = 0;
        } else if (card.is_discount) {
            const price = parseInt(priceEl.value) || 0;
            discEl.value = Math.round(price * (card.discount_percent || 0) / 100);
        }
        if (prefix === 'book') updateFinalPrice();
    }

    // ==== Сертификаты клиента (по аналогии с картами) ====
    const certsForClientUrl = @json(route('club.certificates.forClient'));
    let certCache = { book: [], edit: [] };
    let selectedCert = { book: null, edit: null };

    function loadClientCertificates(prefix, phone, preselectCertId) {
        const wrap = document.getElementById(prefix + 'CertWrap');
        const box = document.getElementById(prefix + 'CertButtons');
        const input = document.getElementById(prefix + 'CertInput');
        const hint = document.getElementById(prefix + 'CertHint');
        if (!wrap || !box) return;
        const reset = () => {
            wrap.style.display = 'none'; box.innerHTML = '';
            if (input) input.value = '';
            if (hint) hint.style.display = 'none';
            selectedCert[prefix] = null; certCache[prefix] = [];
        };
        const digits = (phone || '').replace(/\D/g, '');
        if (digits.length < 5) { reset(); return; }
        // include — id сертификата редактируемой брони (он погашен, но должен быть в списке).
        let url = certsForClientUrl + '?phone=' + encodeURIComponent(digits);
        if (preselectCertId) url += '&include=' + encodeURIComponent(preselectCertId);
        fetch(url)
            .then(r => r.json())
            .then(d => {
                const certs = (d && d.certificates) ? d.certificates : [];
                certCache[prefix] = certs;
                selectedCert[prefix] = null;
                if (input) input.value = '';
                if (!certs.length) { reset(); return; }
                box.innerHTML = certs.map(c =>
                    '<button type="button" class="client-card-btn" data-id="' + c.id + '" onclick="onCertButton(\'' + prefix + '\',' + c.id + ')">' +
                    '<span class="ccb-name">' + cardEsc(c.label) + '</span>' +
                    '<span class="ccb-code">' + cardEsc(c.number) + '</span>' +
                    '<span class="ccb-sub">' + (c.is_free ? 'бесплатно' : 'на сумму') + '</span></button>'
                ).join('');
                wrap.style.display = '';
                // Преселект сертификата редактируемой брони (без пересчёта — скидка
                // уже загружена из брони).
                if (preselectCertId) {
                    const cert = certs.find(c => String(c.id) === String(preselectCertId));
                    if (cert) {
                        selectedCert[prefix] = cert;
                        if (input) input.value = cert.id;
                        box.querySelectorAll('.client-card-btn').forEach(b =>
                            b.classList.toggle('active', String(b.dataset.id) === String(cert.id)));
                        setPaymentCertificate(prefix);
                    }
                }
            })
            .catch(() => reset());
    }

    function onCertButton(prefix, certId) {
        const cert = (certCache[prefix] || []).find(c => String(c.id) === String(certId));
        if (!cert) return;
        const box = document.getElementById(prefix + 'CertButtons');
        const input = document.getElementById(prefix + 'CertInput');
        const already = selectedCert[prefix] && String(selectedCert[prefix].id) === String(certId);
        if (already) { deselectCert(prefix); return; }

        deselectCard(prefix); // сертификат исключает карту
        selectedCert[prefix] = cert;
        if (input) input.value = cert.id;
        if (box) box.querySelectorAll('.client-card-btn').forEach(b =>
            b.classList.toggle('active', String(b.dataset.id) === String(certId)));
        setPaymentCertificate(prefix);
        applyCertPricing(prefix);
    }

    function deselectCert(prefix) {
        if (!selectedCert[prefix]) return;
        selectedCert[prefix] = null;
        const input = document.getElementById(prefix + 'CertInput');
        const box = document.getElementById(prefix + 'CertButtons');
        const hint = document.getElementById(prefix + 'CertHint');
        if (input) input.value = '';
        if (box) box.querySelectorAll('.client-card-btn').forEach(b => b.classList.remove('active'));
        if (hint) hint.style.display = 'none';
        const discEl = document.getElementById(prefix === 'book' ? 'bookDiscount' : 'editDiscount');
        if (discEl) discEl.value = 0;
        if (prefix === 'book' && typeof updateFinalPrice === 'function') updateFinalPrice();
        if (prefix === 'edit' && typeof updateEditFinalPrice === 'function') updateEditFinalPrice();
    }

    function setPaymentCertificate(prefix) {
        const sel = (prefix === 'book') ? '#paymentMethods' : '#editPaymentMethods';
        const inputId = (prefix === 'book') ? 'paymentMethodInput' : 'editPaymentMethodInput';
        document.querySelectorAll(sel + ' .pay-btn').forEach(b =>
            b.classList.toggle('active', b.getAttribute('data-value') === 'certificate'));
        const inp = document.getElementById(inputId);
        if (inp) inp.value = 'certificate';
    }

    // Часы брони (для сертификата на часы).
    function certBookingHours(prefix) {
        if (prefix === 'book') {
            return (typeof currentBook === 'object' && currentBook.duration) ? currentBook.duration : 1;
        }
        return parseInt(document.getElementById('editSlots')?.value) || 1;
    }

    function applyCertPricing(prefix) {
        const cert = selectedCert[prefix];
        const priceEl = document.getElementById(prefix === 'book' ? 'bookCustomPrice' : 'editCustomPrice');
        const discEl = document.getElementById(prefix === 'book' ? 'bookDiscount' : 'editDiscount');
        const hint = document.getElementById(prefix + 'CertHint');
        if (!cert || !priceEl || !discEl) return;
        const price = parseInt(priceEl.value) || 0;
        if (cert.value_type === 'hours') {
            // Сертификат на часы: покрываем только часы сертификата, остальное платно
            // (по средней цене за час брони).
            const bookingHours = certBookingHours(prefix);
            const certHours = parseInt(cert.hours) || 0;
            const covered = Math.min(certHours, bookingHours);
            const perHour = bookingHours > 0 ? price / bookingHours : 0;
            const disc = Math.min(price, Math.round(perHour * covered));
            discEl.value = disc;
            if (hint) {
                hint.textContent = covered >= bookingHours
                    ? 'Бесплатно по сертификату (' + certHours + ' ч) — итог 0 ₸.'
                    : 'Сертификат покрывает ' + covered + ' ч из ' + bookingHours + ' — остальное платно.';
                hint.style.display = '';
            }
        } else {
            discEl.value = Math.min(cert.amount || 0, price); // номинал, но не больше цены
            if (hint) { hint.textContent = 'Скидка по сертификату: ' + (cert.amount || 0) + ' ₸.'; hint.style.display = ''; }
        }
        if (prefix === 'book' && typeof updateFinalPrice === 'function') updateFinalPrice();
        if (prefix === 'edit' && typeof updateEditFinalPrice === 'function') updateEditFinalPrice();
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
            maxSlots: Math.min(maxSlots, 12),
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
        if (clientName) {
            clientName.value = '';
            clientName.removeAttribute('readonly');
            clientName.classList.remove('is-locked');
            clientName.removeAttribute('title');
        }
        const clientNameHint = document.getElementById('bookClientNameHint');
        if (clientNameHint) clientNameHint.style.display = 'none';
        if (clientPhone) clientPhone.value = '';
        const clientNote = document.getElementById('bookClientNote');
        const clientNoteHint = document.getElementById('bookClientNoteHint');
        if (clientNote) {
            clientNote.value = '';
            clientNote.removeAttribute('readonly');
            clientNote.classList.remove('is-locked');
            clientNote.removeAttribute('title');
        }
        if (clientNoteHint) clientNoteHint.style.display = 'none';
        editingBookingId = null; // новая бронь — резерв считаем полностью
        loadClientCards('book', '', null); // сброс карт клиента
        document.getElementById('paymentMethodInput').value = '';
        document.getElementById('isPaidInput').value = '';
        document.querySelectorAll('#paymentMethods .pay-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.paid-toggle .paid-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('bookingTypeInput').value = '';
        document.querySelectorAll('#bookingTypeButtons .bt-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('bookCoachId').value = '';
        document.getElementById('bookCoachPaidGroup').style.display = 'none';
        document.getElementById('bookCoachPaidInput').value = '';
        document.querySelectorAll('#bookCoachPaidGroup .paid-btn').forEach(b => b.classList.remove('active'));
        const bcpg = document.getElementById('bookCoachPriceGroup');
        if (bcpg) bcpg.style.display = 'none';
        const bcp = document.getElementById('bookCoachPrice');
        if (bcp) bcp.value = '';
        updateCoachButtons();

        if (typeof window.resetRepeatSection === 'function') window.resetRepeatSection();
        if (typeof clearBookFormError === 'function') clearBookFormError();
        // Сброс кнопки брони (вдруг осталась в состоянии загрузки).
        const bookSubmitBtn = document.querySelector('#bookForm button[type="submit"]');
        if (bookSubmitBtn) { bookSubmitBtn.disabled = false; bookSubmitBtn.innerHTML = 'Забронировать'; }
        document.getElementById('bookForm').dataset.submitting = '';

        renderDurationButtons(currentBook.maxSlots);
        updateBookTotalPrice();

        new bootstrap.Modal(document.getElementById('bookModal')).show();
    }

    function openViewModal(data) {
        document.getElementById('viewCourtName').textContent = data.courtName;
        document.getElementById('viewDate').textContent = formatDateRu(data.date);
        document.getElementById('viewTime').textContent = data.startTime;
        // Цена/час = цена брони ÷ длительность в часах (как в окне создания).
        let _durMin = parseTimeToMinutes(data.endTime) - parseTimeToMinutes(data.startTime);
        if (_durMin <= 0) _durMin += 1440;
        const _durH = Math.max(1, Math.round(_durMin / 60));
        // Полная цена = сохранённая (после скидки) + скидка. Цена/час считаем из неё.
        const _preDiscount = (Number(data.price) || 0) + (Number(data.discount) || 0);
        document.getElementById('viewPrice').innerHTML = formatPrice(Math.round(_preDiscount / _durH)) + ' &#8376;';

        // Сначала разлочиваем поля (могли остаться от предыдущего открытия)
        unlockClientFields('edit');
        clearEditFormError();

        document.getElementById('editClientName').value = data.clientName || '';
        document.getElementById('editClientPhone').value = data.clientPhone ? '+' + data.clientPhone.replace(/\D/g, '') : '';
        document.getElementById('editClientNote').value = '';

        // Подгружаем карточку клиента по нормализованному телефону и, если
        // нашлось точное совпадение, лочим имя+заметку и подставляем заметку.
        const phoneNorm = (data.clientPhone || '').replace(/\D/g, '');
        if (phoneNorm) {
            fetch('{{ route("club.clients.search") }}?q=' + encodeURIComponent(phoneNorm) + '&field=phone')
                .then(r => r.json())
                .then(clients => {
                    const exact = (clients || []).find(c => (c.phone || '').replace(/\D/g, '') === phoneNorm);
                    if (exact) {
                        lockClientFields('edit', exact.note || '');
                    }
                })
                .catch(() => {});
        }
        // Клубные карты + сертификаты клиента (с предвыбором текущих для брони).
        editingBookingId = data.id || null;
        loadClientCards('edit', phoneNorm, data.clubCardId || null, data.certificateId || null);

        // Payment method
        document.getElementById('editPaymentMethodInput').value = data.paymentMethod || '';
        document.querySelectorAll('#editPaymentMethods .pay-btn').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-value') === data.paymentMethod);
        });

        // Тип брони
        const btVal = data.bookingType || '';
        document.getElementById('editBookingTypeInput').value = btVal;
        document.querySelectorAll('#editBookingTypeButtons .bt-btn').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-value') === btVal);
        });

        // Paid status — берём строго из брони (true/false), не из дефолта
        const paidVal = data.isPaid ? '1' : '0';
        document.getElementById('editIsPaidInput').value = paidVal;
        document.querySelectorAll('#viewModal .paid-toggle .paid-btn').forEach(b => {
            const isToggle = b.closest('#editProcessedGroup') === null && b.closest('#editCoachPaidGroup') === null;
            if (isToggle) {
                b.classList.toggle('active', b.getAttribute('data-value') === paidVal);
            }
        });

        // В поле ЦЕНА — полная цена (до скидки), скидка отдельно; иначе двойное вычитание.
        document.getElementById('editCustomPrice').value = Math.round(_preDiscount) || 0;
        document.getElementById('editDiscount').value = Math.round(data.discount) || 0;
        document.getElementById('editComment').value = data.comment || '';

        // Статус обработки — показывать только для необработанных
        const processedGroup = document.getElementById('editProcessedGroup');
        const processedVal = data.isProcessed ? '1' : '0';
        document.getElementById('editIsProcessedInput').value = processedVal;
        if (!data.isProcessed) {
            processedGroup.style.display = '';
        } else {
            processedGroup.style.display = 'none';
        }
        document.querySelectorAll('.processed-btn').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-value') === processedVal);
        });
        // Список тренеров брони: из пивота (мультитренер) либо синтез из одиночного
        // coachId (старые брони без пивота).
        let coachList = Array.isArray(data.coaches) ? data.coaches.slice() : [];
        if (coachList.length === 0 && data.coachId) {
            coachList = [{ coachId: data.coachId, price: data.coachPrice, paid: data.coachPaid }];
        }
        // Групповая бронь — тренер задаётся отдельным селектом (renderEditGroup), не мультитренером.
        if (data.bookingType === 'group') coachList = [];
        const selectedIds = coachList.map(c => String(c.coachId));
        document.getElementById('editCoachId').value = selectedIds.length ? selectedIds[0] : '';

        // Часы брони (для расчёта цены по ставке, если у тренера не задана цена).
        const _sMin = parseTimeToMinutes(data.startTime);
        let _eMin = parseTimeToMinutes(data.endTime);
        if (_eMin <= _sMin) _eMin += 1440;
        const _hours = Math.max(1, Math.round((_eMin - _sMin) / 60));

        // Доступность + подсветка выбранных тренеров.
        document.querySelectorAll('#editCoachButtons .coach-btn').forEach(b => {
            const coachId = b.getAttribute('data-coach-id');
            const isSelected = selectedIds.includes(String(coachId));
            const available = isSelected || (coachAvailability[coachId] && coachAvailability[coachId][data.startTime]);
            b.classList.remove('active', 'unavailable');
            if (!available) b.classList.add('unavailable');
            if (isSelected) b.classList.add('active');
        });

        // Строки выбранных тренеров с ценой/оплатой.
        const editSelContainer = document.getElementById('editSelectedCoaches');
        editSelContainer.innerHTML = '';
        coachList.forEach(c => {
            const btn = document.querySelector('#editCoachButtons .coach-btn[data-coach-id="' + c.coachId + '"]');
            const name = btn ? (btn.querySelector('.coach-btn-name')?.textContent || '') : ('#' + c.coachId);
            let price = (c.price !== null && c.price !== undefined && c.price !== '') ? Math.round(c.price) : '';
            if (price === '') {
                const rate = btn ? (parseFloat(btn.getAttribute('data-rate')) || 0) : 0;
                price = rate > 0 ? Math.round(rate * _hours) : '';
            }
            editSelContainer.insertAdjacentHTML('beforeend', editCoachRowHtml(c.coachId, name, price, c.paid === true));
        });
        updateEditFinalPrice();

        // Старые одиночные поля не используются в мультитренере — прячем.
        const editCoachPaidGroup = document.getElementById('editCoachPaidGroup');
        document.getElementById('editCoachPaidInput').value = '';
        editCoachPaidGroup.style.display = 'none';
        const editCoachPriceGroup = document.getElementById('editCoachPriceGroup');
        const editCoachPrice = document.getElementById('editCoachPrice');
        if (editCoachPriceGroup && editCoachPrice) {
            editCoachPriceGroup.style.display = 'none';
            editCoachPrice.value = '';
        }

        document.getElementById('editBookingForm').action = '{{ url("club/courts/bookings") }}/' + data.id;
        document.getElementById('cancelBookingForm').action = '{{ url("club/courts/bookings") }}/' + data.id + '/cancel';
        window._viewHasCert = !!data.hasCertificate;

        // Если часы по клубной карте уже списаны — отмена брони недоступна.
        const cancelBookingBtn = document.getElementById('cancelBookingBtn');
        if (cancelBookingBtn) {
            cancelBookingBtn.style.display = data.cardCharged ? 'none' : '';
            // Сброс кнопки (вдруг осталась в состоянии загрузки).
            cancelBookingBtn.disabled = false;
            cancelBookingBtn.dataset.submitting = '';
            cancelBookingBtn.innerHTML = 'Отменить бронь';
        }

        // Длительность — кнопки 1..6, текущая = (end-start)/slotDuration
        renderEditDurationButtons(data);

        // Групповая бронь — показываем тренера и участников, прячем поля
        // клиента/оплаты (как при создании).
        renderEditGroup(data);
        applyEditGroupVisibility((data.bookingType || '') === 'group');

        new bootstrap.Modal(document.getElementById('viewModal')).show();
    }

    // Контекст текущей редактируемой брони — для калькулятора цены
    let currentEdit = null;

    function renderEditDurationButtons(data) {
        const slotDur = data.slotDuration || 60;
        const startMin = parseTimeToMinutes(data.startTime);
        let endMin = parseTimeToMinutes(data.endTime);
        if (endMin <= startMin) endMin += 24 * 60;
        const currentSlots = Math.max(1, Math.round((endMin - startMin) / slotDur));
        const date = data.date || pageDate;
        const courtId = data.courtId;
        const maxSlots = computeMaxEditSlots(courtId, date, data.startTime, currentSlots);

        // Цена за слот брони — из полной цены (до скидки), для корректного пересчёта длительности.
        const _pre = (Number(data.price) || 0) + (Number(data.discount) || 0);
        const pricePerSlot = currentSlots > 0 ? (_pre / currentSlots) : 0;
        currentEdit = {
            id: data.id,
            courtId: courtId,
            date: date,
            startTime: data.startTime,
            slotDuration: slotDur,
            originalSlots: currentSlots,
            originalPricePerSlot: pricePerSlot,
        };

        document.getElementById('editSlots').value = currentSlots;
        const container = document.getElementById('editDurationSelector');
        if (!container) return;
        container.innerHTML = '';
        for (let i = 1; i <= maxSlots; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'duration-btn' + (i === currentSlots ? ' active' : '');
            btn.dataset.slots = i;
            btn.textContent = formatDuration(i * slotDur);
            btn.onclick = function() {
                document.querySelectorAll('#editDurationSelector .duration-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById('editSlots').value = i;
                applyEditPriceForSlots(i);
                if (selectedCert.edit) applyCertPricing('edit');
            };
            container.appendChild(btn);
        }
    }

    function computeMaxEditSlots(courtId, date, startTime, currentSlots) {
        const startIdx = orderedTimes.indexOf(startTime);
        if (startIdx === -1) return currentSlots;
        const dateMap = (freeSlotsByDate || {})[date] || {};
        let max = currentSlots;
        let i = startIdx + currentSlots;
        while (i < orderedTimes.length && max < 12) {
            const key = courtId + '-' + orderedTimes[i];
            if (dateMap[key] !== undefined) {
                max++;
                i++;
            } else {
                break;
            }
        }
        return max;
    }

    function calcEditTotalPrice(slots) {
        if (!currentEdit) return 0;
        const startIdx = orderedTimes.indexOf(currentEdit.startTime);
        if (startIdx === -1) return currentEdit.originalPricePerSlot * slots;
        const dateMap = (freeSlotsByDate || {})[currentEdit.date] || {};
        let total = 0;
        for (let i = 0; i < slots; i++) {
            const t = orderedTimes[startIdx + i];
            if (!t) break;
            const key = currentEdit.courtId + '-' + t;
            if (dateMap[key] !== undefined) {
                total += Number(dateMap[key]);
            } else {
                // Слот относится к самой брони — берём её per-slot цену
                total += currentEdit.originalPricePerSlot;
            }
        }
        return Math.round(total);
    }

    function applyEditPriceForSlots(slots) {
        const total = calcEditTotalPrice(slots);
        const priceInput = document.getElementById('editCustomPrice');
        const discountInput = document.getElementById('editDiscount');
        if (priceInput) priceInput.value = total;
        if (discountInput) discountInput.value = 0;
        // Пересчёт цены тренера под новую длительность.
        const coachId = document.getElementById('editCoachId').value;
        const coachPrice = document.getElementById('editCoachPrice');
        if (coachId && coachPrice) {
            const btn = document.querySelector('#editCoachButtons .coach-btn.active');
            const rate = btn ? (parseFloat(btn.getAttribute('data-rate')) || 0) : 0;
            if (rate > 0) coachPrice.value = Math.round(rate * slots);
        }
        // Мультитренер: пересчитать цену в каждой строке по ставке тренера.
        document.querySelectorAll('#editSelectedCoaches .sel-coach-row').forEach(row => {
            const cid = row.getAttribute('data-cid');
            const btn = document.querySelector('#editCoachButtons .coach-btn[data-coach-id="' + cid + '"]');
            const rate = btn ? (parseFloat(btn.getAttribute('data-rate')) || 0) : 0;
            const priceInp = row.querySelector('.sel-coach-price');
            if (rate > 0 && priceInp) priceInp.value = Math.round(rate * slots);
        });
        updateEditFinalPrice();
    }

    function parseTimeToMinutes(t) {
        if (!t) return 0;
        const [h, m] = t.split(':').map(Number);
        return h * 60 + (m || 0);
    }

    function formatDuration(min) {
        const h = Math.floor(min / 60);
        const m = min % 60;
        if (m === 0) return h + 'ч';
        if (h === 0) return m + 'м';
        return h + 'ч ' + m + 'м';
    }

    function cancelBooking() {
        // Причину спрашиваем ВСЕГДА, для любой брони.
        const isGroup = document.getElementById('editBookingTypeInput').value === 'group';
        const hasCert = !!window._viewHasCert;
        const ta = document.getElementById('cancelBookingReasonText');
        if (ta) ta.value = '';
        const rm = document.getElementById('cancelBookingReasonModal');
        const rmTitle = rm.querySelector('.gcancel-title');
        const rmSub = rm.querySelector('.gcancel-sub');
        if (isGroup) {
            if (rmTitle) rmTitle.textContent = 'Отменить бронь занятия?';
            if (rmSub) rmSub.textContent = 'Корт освободится, занятие отменится. Причину видно в журнале группы — по ней потом понятно, за что отменили.';
        } else if (hasCert) {
            if (rmTitle) rmTitle.textContent = 'Отменить бронь?';
            if (rmSub) rmSub.textContent = 'Корт освободится, сертификат вернётся в активные. Причина сохранится к брони.';
        } else {
            if (rmTitle) rmTitle.textContent = 'Отменить бронь?';
            if (rmSub) rmSub.textContent = 'Корт освободится. Укажите причину — она сохранится к брони.';
        }
        // Переносим окно ВНУТРЬ bootstrap-модалки, иначе её focus-trap не даёт
        // печатать в поле (крадёт фокус обратно).
        const modalEl = document.getElementById('viewModal');
        if (modalEl && rm && rm.parentElement !== modalEl) modalEl.appendChild(rm);
        rm.style.display = 'flex';
        setTimeout(function () { if (ta) ta.focus(); }, 50);
    }
    function submitCancelWithReason() {
        const ta = document.getElementById('cancelBookingReasonText');
        const val = (ta ? ta.value : '').trim();
        const err = document.getElementById('cancelReasonError');
        if (val.length < 5) {
            if (err) err.style.display = 'block';
            if (ta) { ta.style.borderColor = '#ef4444'; ta.focus(); }
            return;
        }
        if (err) err.style.display = 'none';
        const reasonInput = document.getElementById('cancelBookingReason');
        if (reasonInput) reasonInput.value = val;
        document.getElementById('cancelBookingReasonModal').style.display = 'none';
        submitCancelBooking();
    }
    function submitCancelBooking() {
        const btn = document.getElementById('cancelBookingBtn');
        if (btn) {
            if (btn.dataset.submitting === '1') return; // уже отменяется
            btn.dataset.submitting = '1';
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Отмена…';
            setTimeout(function () { btn.disabled = true; }, 0);
        }
        document.getElementById('cancelBookingForm').submit();
    }

    // ============================================================
    // Лок имя+заметка клиента (общая логика для book и edit модалок)
    // ============================================================
    function lockClientFields(prefix, noteValue) {
        const nameInput = document.getElementById(prefix + 'ClientName');
        if (nameInput) {
            nameInput.setAttribute('readonly', 'readonly');
            nameInput.classList.add('is-locked');
            nameInput.title = 'Имя берётся из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».';
        }
        const nameHint = document.getElementById(prefix + 'ClientNameHint');
        if (nameHint) nameHint.style.display = 'block';
        const noteInput = document.getElementById(prefix + 'ClientNote');
        if (noteInput) {
            noteInput.value = noteValue || '';
            noteInput.setAttribute('readonly', 'readonly');
            noteInput.classList.add('is-locked');
            noteInput.title = 'Заметка берётся из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».';
        }
        const noteHint = document.getElementById(prefix + 'ClientNoteHint');
        if (noteHint) noteHint.style.display = 'block';
    }

    function unlockClientFields(prefix) {
        const nameInput = document.getElementById(prefix + 'ClientName');
        if (nameInput && nameInput.hasAttribute('readonly')) {
            nameInput.removeAttribute('readonly');
            nameInput.classList.remove('is-locked');
            nameInput.removeAttribute('title');
        }
        const nameHint = document.getElementById(prefix + 'ClientNameHint');
        if (nameHint) nameHint.style.display = 'none';
        const noteInput = document.getElementById(prefix + 'ClientNote');
        if (noteInput) {
            noteInput.removeAttribute('readonly');
            noteInput.classList.remove('is-locked');
            noteInput.removeAttribute('title');
        }
        const noteHint = document.getElementById(prefix + 'ClientNoteHint');
        if (noteHint) noteHint.style.display = 'none';
    }

    // ============================================================
    // Валидация edit-формы (та же логика, что book): inline-ошибка
    // ============================================================
    function showEditFormError(message, targetEl) {
        const errorBox = document.getElementById('editFormError');
        if (!errorBox) return;
        errorBox.querySelector('.book-form-error-text').textContent = message;
        errorBox.classList.add('is-visible');
        document.querySelectorAll('#editBookingForm .has-error').forEach(el => el.classList.remove('has-error'));
        if (targetEl) {
            targetEl.classList.add('has-error');
            try { targetEl.scrollIntoView({behavior: 'smooth', block: 'center'}); } catch (_) {}
            if (targetEl.tagName === 'INPUT') {
                setTimeout(() => targetEl.focus({preventScroll: true}), 200);
            }
        }
    }
    function clearEditFormError() {
        const errorBox = document.getElementById('editFormError');
        if (errorBox) errorBox.classList.remove('is-visible');
        document.querySelectorAll('#editBookingForm .has-error').forEach(el => el.classList.remove('has-error'));
    }

    document.getElementById('editBookingForm').addEventListener('submit', function(e) {
        const form = e.target;
        const bookingType = document.getElementById('editBookingTypeInput').value;
        const isGroup = bookingType === 'group';
        // Групповая бронь: гарантируем отправку выбранного тренера в coach_id
        // (на случай, если onchange селекта не сработал).
        if (isGroup) {
            const gsel = document.getElementById('editGroupCoachSelect');
            if (gsel) document.getElementById('editCoachId').value = gsel.value;
        }
        const nameInput = form.querySelector('input[name="client_name"]');
        const phoneInput = form.querySelector('input[name="client_phone"]');
        const paymentInput = form.querySelector('input[name="payment_method"]');
        const paidInput = form.querySelector('input[name="is_paid"]');
        const paymentGroup = document.getElementById('editPaymentMethods');
        const paidGroup = document.getElementById('editIsPaidInput').parentElement.querySelector('.paid-toggle');

        // Для групповой брони поля клиента/оплаты скрыты и не нужны — пропускаем
        // эти проверки (как в форме создания).
        if (!isGroup) {
            // Для существующих клиентов имя может быть однословным — не блокируем
            // (карточка-источник истины уже валидирует это на бэке).
            const nameIsLocked = nameInput && nameInput.hasAttribute('readonly');
            if (!nameIsLocked) {
                const words = (nameInput.value || '').trim().split(/\s+/).filter(Boolean);
                if (words.length < 2) {
                    e.preventDefault();
                    showEditFormError('Укажите имя и фамилию клиента (например: «Денис Дудников»)', nameInput);
                    return;
                }
            }
            if (!(phoneInput.value || '').trim()) {
                e.preventDefault();
                showEditFormError('Укажите номер телефона клиента', phoneInput);
                return;
            }
            if (!paymentInput.value) {
                e.preventDefault();
                showEditFormError('Выберите способ оплаты', paymentGroup);
                return;
            }
            if (paymentInput.value === 'club_card' && !(document.getElementById('editCardInput').value || '').trim()) {
                e.preventDefault();
                showEditFormError('Выберите клубную карту', document.getElementById('editCardButtons'));
                return;
            }
            if (paidInput.value === '') {
                e.preventDefault();
                showEditFormError('Выберите статус оплаты: «Оплачено» или «Не оплачено»', paidGroup);
                return;
            }
        }
        // Если выбран тренер по старому одиночному полю (виден блок оплаты) —
        // статус его оплаты обязателен. В мультитренере оплата задаётся чекбоксом
        // в каждой строке (пустой не бывает), поэтому проверка не нужна.
        const coachId = document.getElementById('editCoachId').value;
        const coachPaid = document.getElementById('editCoachPaidInput').value;
        const legacyPaidGroup = document.getElementById('editCoachPaidGroup');
        const legacyPaidVisible = legacyPaidGroup && legacyPaidGroup.style.display !== 'none';
        if (coachId && legacyPaidVisible && coachPaid === '') {
            e.preventDefault();
            showEditFormError('Выберите статус оплаты тренера: «Оплачен» или «Не оплачен»', document.querySelector('#editCoachPaidGroup .paid-toggle'));
            return;
        }
        clearEditFormError();
    });
    document.getElementById('editBookingForm').addEventListener('input', clearEditFormError);
    document.getElementById('editBookingForm').addEventListener('click', function(e) {
        if (e.target.closest('.pay-btn, .paid-btn')) clearEditFormError();
    });

    // Ручная правка телефона в edit-форме — разлочить имя+заметку
    const editPhoneEl = document.getElementById('editClientPhone');
    if (editPhoneEl) {
        editPhoneEl.addEventListener('input', function() {
            if (document.getElementById('editClientName').hasAttribute('readonly')) {
                unlockClientFields('edit');
                document.getElementById('editClientNote').value = '';
            }
        });
    }

    function selectEditPayment(btn) {
        document.querySelectorAll('#editPaymentMethods .pay-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const val = btn.getAttribute('data-value');
        document.getElementById('editPaymentMethodInput').value = val;
        if (val !== 'club_card') deselectCard('edit');
        if (val !== 'certificate') deselectCert('edit');
        // «Бесплатно» — цена и скидка 0, статус → Оплачено
        if (val === 'free') {
            const p = document.getElementById('editCustomPrice'); if (p) p.value = 0;
            const d = document.getElementById('editDiscount'); if (d) d.value = 0;
            document.getElementById('editIsPaidInput').value = '1';
            document.querySelectorAll('#viewModal .paid-toggle .paid-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-value') === '1'));
        }
    }

    function setEditPaid(btn) {
        document.querySelectorAll('#viewModal .paid-toggle .paid-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('editIsPaidInput').value = btn.getAttribute('data-value');
    }

    function openUnblockModal(blockId, courtName, time, comment) {
        document.getElementById('unblockCourtName').textContent = courtName;
        document.getElementById('unblockTime').textContent = time;
        document.getElementById('blockComment').value = comment || '';
        document.getElementById('updateBlockForm').action = '{{ url("club/courts/blocks") }}/' + blockId;
        document.getElementById('unblockForm').action = '{{ url("club/courts/blocks") }}/' + blockId;
        new bootstrap.Modal(document.getElementById('unblockModal')).show();
    }

    function unblockSlot() {
        if (confirm('Вы уверены, что хотите разблокировать этот слот?')) {
            document.getElementById('unblockForm').submit();
        }
    }

    function blockSlot() {
        document.getElementById('blockStartTime').value = currentBook.time;
        document.getElementById('blockEndTime').value = calcBlockEndTime();
        var commentField = document.querySelector('#bookForm textarea[name="comment"]');
        document.getElementById('newBlockComment').value = commentField ? commentField.value : '';
        document.getElementById('blockForm').submit();
    }

    function selectPayment(btn) {
        document.querySelectorAll('#paymentMethods .pay-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const val = btn.getAttribute('data-value');
        document.getElementById('paymentMethodInput').value = val;
        // Выбран не «Клубная карта» — снимаем карту и возвращаем обычную цену.
        if (val !== 'club_card') deselectCard('book');
        if (val !== 'certificate') deselectCert('book');
        // «Бесплатно» — цена и скидка 0, статус → Оплачено
        if (val === 'free') {
            const p = document.getElementById('bookCustomPrice'); if (p) p.value = 0;
            const d = document.getElementById('bookDiscount'); if (d) d.value = 0;
            updateFinalPrice();
            document.getElementById('isPaidInput').value = '1';
            document.querySelectorAll('.paid-toggle .paid-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-value') === '1'));
        }
    }

    function setPaid(btn) {
        document.querySelectorAll('.paid-toggle .paid-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('isPaidInput').value = btn.getAttribute('data-value');
    }

    // Тип брони (опционально, повторный клик снимает выбор)
    function selectBookingType(btn) {
        const input = document.getElementById('bookingTypeInput');
        const wasActive = btn.classList.contains('active');
        document.querySelectorAll('#bookingTypeButtons .bt-btn').forEach(b => b.classList.remove('active'));
        if (wasActive) { input.value = ''; }
        else { btn.classList.add('active'); input.value = btn.getAttribute('data-value'); }

        const isGroup = input.value === 'group';

        // Селект группы — только при типе «Групповые»
        const groupWrap = document.getElementById('bookGroupSelectWrap');
        if (groupWrap) {
            groupWrap.style.display = isGroup ? 'block' : 'none';
            if (!isGroup) {
                const sel = document.getElementById('bookGroupSelect');
                if (sel) sel.value = '';
                renderGroupMembers('');
            }
        }

        // При типе group прячем клиента/оплату/скидку, снимаем required.
        document.querySelectorAll('.js-hide-for-group').forEach(el => el.style.display = isGroup ? 'none' : '');
        document.querySelectorAll('.js-show-for-group').forEach(el => el.style.display = isGroup ? 'block' : 'none');
        ['bookClientName', 'bookClientPhone'].forEach(id => {
            const e = document.getElementById(id);
            if (e) { e.required = !isGroup; if (isGroup) e.value = ''; }
        });
        // Блок карты — показываем только если есть карты и не групповая бронь.
        const cardWrap = document.getElementById('bookCardWrap');
        if (cardWrap) cardWrap.style.display = (!isGroup && (cardCache.book || []).length) ? '' : 'none';
    }
    function renderGroupMembers(groupId) {
        const block = document.getElementById('groupMembersBlock');
        const list = document.getElementById('gmList');
        const empty = document.getElementById('gmEmpty');
        const count = document.getElementById('gmCount');
        const coachBlock = document.getElementById('groupCoachBlock');
        const coachSelect = document.getElementById('bookGroupCoachSelect');
        const coachHint = document.getElementById('bookGroupCoachHint');
        const coachIdInput = document.getElementById('bookCoachId');
        const priceEl = document.getElementById('gmPrice');
        const schedLink = document.getElementById('gmScheduleLink');
        if (!block || !list) return;
        if (!groupId) {
            block.style.display = 'none';
            list.innerHTML = '';
            if (coachBlock) coachBlock.style.display = 'none';
            if (coachSelect) coachSelect.value = '';
            if (coachIdInput) coachIdInput.value = '';
            if (priceEl) priceEl.style.display = 'none';
            if (schedLink) schedLink.style.display = 'none';
            return;
        }
        if (schedLink) {
            schedLink.href = '{{ url('club/groups') }}/' + groupId + '/schedule';
            schedLink.style.display = 'flex';
        }
        const data = (window.__groupMembers && window.__groupMembers[groupId]) || { coach_id: null, members: [] };
        if (priceEl) {
            const p = Number(data.price || 0);
            const cnt = (data.members || []).length;
            const fmt = n => new Intl.NumberFormat('ru-RU').format(n);
            priceEl.style.display = 'block';
            priceEl.innerHTML = p > 0
                ? 'Цена занятия: <b style="color:#22c55e;">' + fmt(p * cnt) + ' ₸</b> <span style="color:#71717a;">(' + fmt(p) + ' ₸ × ' + cnt + ')</span>'
                : '<span style="color:#71717a;">Цена занятия не задана в группе</span>';
        }
        const members = data.members || [];
        block.style.display = 'block';
        list.innerHTML = '';
        count.textContent = members.length ? members.length : '';
        if (members.length === 0) {
            empty.style.display = 'block';
        } else {
            empty.style.display = 'none';
            members.forEach(m => {
                const li = document.createElement('li');
                li.className = 'gm-item';
                const remaining = m.remaining;
                const lowClass = remaining <= 0 ? 'gm-rem-zero' : (remaining <= 2 ? 'gm-rem-low' : 'gm-rem-ok');
                const word = remaining === 1 ? 'занятие' : (remaining >= 2 && remaining <= 4 ? 'занятия' : 'занятий');
                const frozen = m.frozen
                    ? '<span class="gm-frozen">❄ заморожен' + (m.frozen_until ? ' до ' + m.frozen_until : '') + '</span>'
                    : '';
                const notStarted = m.not_started
                    ? '<span class="gm-frozen" style="background:rgba(106,164,245,.14);color:#6aa4f5;">начнёт с ' + m.starts_at + '</span>'
                    : '';
                const note = m.note
                    ? '<span class="gm-mnote" style="flex-basis:100%;color:#71717a;font-size:11.5px;"><i class="bi bi-chat-square-text" style="font-size:10px;"></i> ' + escapeHtml(m.note) + '</span>'
                    : '';
                li.innerHTML = '<span class="gm-name">' + escapeHtml(m.name) + '</span>' + frozen + notStarted +
                    '<span class="gm-rem ' + lowClass + '">' + remaining + ' ' + word + '</span>' + note;
                if (m.frozen) li.classList.add('gm-item-frozen');
                list.appendChild(li);
            });
        }
        if (coachBlock && coachSelect && coachIdInput) {
            coachBlock.style.display = 'block';
            const defaultCoach = data.coach_id ? String(data.coach_id) : '';
            coachSelect.value = defaultCoach;
            coachIdInput.value = defaultCoach;
            // Занятые тренеры (ведут другое занятие / не работают) — серым и недоступны.
            const bookTime = (typeof currentBook === 'object' && currentBook && currentBook.time) ? currentBook.time : null;
            markGroupCoachAvailability(coachSelect, bookTime, defaultCoach);
            if (coachHint) coachHint.style.display = defaultCoach ? 'block' : 'none';
        }
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function selectEditBookingType(btn) {
        const input = document.getElementById('editBookingTypeInput');
        const wasActive = btn.classList.contains('active');
        document.querySelectorAll('#editBookingTypeButtons .bt-btn').forEach(b => b.classList.remove('active'));
        if (wasActive) { input.value = ''; }
        else { btn.classList.add('active'); input.value = btn.getAttribute('data-value'); }
        applyEditGroupVisibility(input.value === 'group');
    }

    // Для групповой брони в окне редактирования прячем поля клиента/оплаты/
    // тренера — как в окне создания (групповое занятие платится пакетами).
    function applyEditGroupVisibility(isGroup) {
        document.querySelectorAll('.js-edit-hide-for-group').forEach(function (el) {
            el.style.display = isGroup ? 'none' : '';
        });
        const phone = document.getElementById('editClientPhone');
        if (phone) { phone.required = !isGroup; }
        const name = document.getElementById('editClientName');
        if (name) { name.readOnly = isGroup; }
        const label = document.getElementById('editClientLabel');
        if (label) {
            label.textContent = isGroup ? 'Группа' : 'Напишите имя и фамилию клиента *';
        }
        const groupBlock = document.getElementById('editGroupBlock');
        if (groupBlock) groupBlock.style.display = isGroup ? 'block' : 'none';
        // Оплата тренера для группы не нужна
        if (isGroup) {
            const cp = document.getElementById('editCoachPaidGroup');
            if (cp) cp.style.display = 'none';
        }
    }

    // Заполнить тренера и список участников группы в окне редактирования.
    // Пометить занятых тренеров в <select> группы: недоступных в это время
    // делаем disabled и дописываем «— занят» (кроме уже назначенного на бронь).
    function markGroupCoachAvailability(selectEl, time, currentCoachId) {
        if (!selectEl) return;
        const avail = (typeof coachAvailability === 'object' && coachAvailability) ? coachAvailability : {};
        Array.from(selectEl.options).forEach(function (opt) {
            // Сброс прошлой пометки.
            opt.textContent = opt.textContent.replace(/\s+—\s+занят$/, '');
            opt.disabled = false;
            if (!opt.value) return; // «— без тренера —»
            const isCurrent = String(opt.value) === String(currentCoachId || '');
            const free = isCurrent || (avail[opt.value] && avail[opt.value][time]);
            if (!free) {
                opt.disabled = true;
                opt.textContent = opt.textContent + ' — занят';
            }
        });
    }

    function renderEditGroup(data) {
        const coachSelect = document.getElementById('editGroupCoachSelect');
        if (coachSelect) {
            coachSelect.value = data.coachId ? String(data.coachId) : '';
            // Занятые тренеры (ведут другое занятие / не работают) — серым и недоступны.
            markGroupCoachAvailability(coachSelect, data.startTime, data.coachId);
            // Держим скрытое поле coach_id в соответствии с выбором (на случай рассинхрона).
            const editCoachIdInput = document.getElementById('editCoachId');
            if (editCoachIdInput) editCoachIdInput.value = coachSelect.value;
        }
        const list = document.getElementById('editGmList');
        const empty = document.getElementById('editGmEmpty');
        const count = document.getElementById('editGmCount');
        if (!list) return;
        list.innerHTML = '';
        const g = (window.__groupMembers && data.groupId && window.__groupMembers[data.groupId]) || null;
        const members = g ? (g.members || []) : [];
        const schedLink = document.getElementById('editGmScheduleLink');
        if (schedLink) {
            if (data.groupId) {
                schedLink.href = '{{ url('club/groups') }}/' + data.groupId + '/schedule';
                schedLink.style.display = 'flex';
            } else {
                schedLink.style.display = 'none';
            }
        }
        const priceEl = document.getElementById('editGmPrice');
        if (priceEl) {
            const p = Number((g && g.price) || 0);
            const cnt = members.length;
            const fmt = n => new Intl.NumberFormat('ru-RU').format(n);
            priceEl.style.display = 'block';
            priceEl.innerHTML = p > 0
                ? 'Цена занятия: <b style="color:#22c55e;">' + fmt(p * cnt) + ' ₸</b> <span style="color:#71717a;">(' + fmt(p) + ' ₸ × ' + cnt + ')</span>'
                : '<span style="color:#71717a;">Цена занятия не задана в группе</span>';
        }
        if (count) count.textContent = members.length ? members.length : '';
        if (!members.length) {
            if (empty) empty.style.display = 'block';
        } else {
            if (empty) empty.style.display = 'none';
            members.forEach(function (m) {
                const li = document.createElement('li');
                li.className = 'gm-item';
                const remaining = m.remaining;
                const lowClass = remaining <= 0 ? 'gm-rem-zero' : (remaining <= 2 ? 'gm-rem-low' : 'gm-rem-ok');
                const word = remaining === 1 ? 'занятие' : (remaining >= 2 && remaining <= 4 ? 'занятия' : 'занятий');
                const frozen = m.frozen
                    ? '<span class="gm-frozen">❄ заморожен' + (m.frozen_until ? ' до ' + m.frozen_until : '') + '</span>'
                    : '';
                const notStarted = m.not_started
                    ? '<span class="gm-frozen" style="background:rgba(106,164,245,.14);color:#6aa4f5;">начнёт с ' + m.starts_at + '</span>'
                    : '';
                const note = m.note
                    ? '<span class="gm-mnote" style="flex-basis:100%;color:#71717a;font-size:11.5px;"><i class="bi bi-chat-square-text" style="font-size:10px;"></i> ' + escapeHtml(m.note) + '</span>'
                    : '';
                li.innerHTML = '<span class="gm-name">' + escapeHtml(m.name) + '</span>' + frozen + notStarted +
                    '<span class="gm-rem ' + lowClass + '">' + remaining + ' ' + word + '</span>' + note;
                if (m.frozen) li.classList.add('gm-item-frozen');
                list.appendChild(li);
            });
        }
    }

    // Валидация формы бронирования: имя+фамилия, способ оплаты, статус оплаты
    function showBookFormError(message, targetEl) {
        const errorBox = document.getElementById('bookFormError');
        if (!errorBox) return;
        errorBox.querySelector('.book-form-error-text').textContent = message;
        errorBox.classList.add('is-visible');

        document.querySelectorAll('#bookForm .has-error').forEach(el => el.classList.remove('has-error'));
        if (targetEl) {
            targetEl.classList.add('has-error');
            try { targetEl.scrollIntoView({behavior: 'smooth', block: 'center'}); } catch (_) {}
            if (targetEl.tagName === 'INPUT') {
                setTimeout(() => targetEl.focus({preventScroll: true}), 200);
            }
        }
    }

    function clearBookFormError() {
        const errorBox = document.getElementById('bookFormError');
        if (errorBox) errorBox.classList.remove('is-visible');
        document.querySelectorAll('#bookForm .has-error').forEach(el => el.classList.remove('has-error'));
    }

    document.getElementById('bookForm').addEventListener('submit', function(e) {
        const form = e.target;
        // Уже отправляется — блокируем повторные клики (обработка занимает пару секунд).
        if (form.dataset.submitting === '1') { e.preventDefault(); return; }
        const bookingType = document.getElementById('bookingTypeInput').value;
        const isGroup = bookingType === 'group';
        const nameInput = form.querySelector('input[name="client_name"]');
        const phoneInput = form.querySelector('input[name="client_phone"]');
        const paymentInput = form.querySelector('input[name="payment_method"]');
        const paidInput = form.querySelector('input[name="is_paid"]');
        const paymentGroup = document.getElementById('paymentMethods');
        const paidGroup = document.getElementById('isPaidInput').parentElement.querySelector('.paid-toggle');

        // Для групповой брони поля клиента/оплаты не нужны — пропускаем эти проверки.
        if (!isGroup) {
            const words = (nameInput.value || '').trim().split(/\s+/).filter(Boolean);
            if (words.length < 2) {
                e.preventDefault();
                showBookFormError('Укажите имя и фамилию клиента (например: «Денис Дудников»)', nameInput);
                return;
            }
            if (!(phoneInput.value || '').trim()) {
                e.preventDefault();
                showBookFormError('Укажите номер телефона клиента', phoneInput);
                return;
            }
            if (!paymentInput.value) {
                e.preventDefault();
                showBookFormError('Выберите способ оплаты', paymentGroup);
                return;
            }
            if (paymentInput.value === 'club_card' && !(document.getElementById('bookCardInput').value || '').trim()) {
                e.preventDefault();
                showBookFormError('Выберите клубную карту', document.getElementById('bookCardButtons'));
                return;
            }
            if (paidInput.value === '') {
                e.preventDefault();
                showBookFormError('Выберите статус оплаты: «Оплачено» или «Не оплачено»', paidGroup);
                return;
            }
        }
        // Статус оплаты тренера обязателен только для разовых броней — для group оплата идёт через пакеты.
        // Проверяем только старое одиночное поле (видимо); в мультитренере оплата — чекбокс в строке.
        if (!isGroup) {
            const coachId = document.getElementById('bookCoachId').value;
            const coachPaid = document.getElementById('bookCoachPaidInput').value;
            const legacyBookPaid = document.getElementById('bookCoachPaidGroup');
            const legacyBookPaidVisible = legacyBookPaid && legacyBookPaid.style.display !== 'none';
            if (coachId && legacyBookPaidVisible && coachPaid === '') {
                e.preventDefault();
                showBookFormError('Выберите статус оплаты тренера: «Оплачен» или «Не оплачен»', document.querySelector('#bookCoachPaidGroup .paid-toggle'));
                return;
            }
        }
        clearBookFormError();
        // Валидация пройдена — форма отправляется (перезагрузка страницы).
        // Прелоадер + блокировка кнопки, чтобы не тыкали по 20 раз.
        form.dataset.submitting = '1';
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Бронирование…';
            setTimeout(function () { submitBtn.disabled = true; }, 0);
        }
    });

    // Любое изменение в форме / клик по тогглам — гасим ошибку
    document.getElementById('bookForm').addEventListener('input', clearBookFormError);
    document.getElementById('bookForm').addEventListener('click', function(e) {
        if (e.target.closest('.pay-btn, .paid-btn')) clearBookFormError();
    });

    // Тренеры — доступность по слотам
    const coachAvailability = @json($coachAvailability);

    function updateCoachButtons() {
        const time = currentBook.time;
        const container = document.getElementById('bookCoachButtons');
        if (!container) return;
        container.querySelectorAll('.coach-btn').forEach(btn => {
            const coachId = btn.getAttribute('data-coach-id');
            const available = coachAvailability[coachId] && coachAvailability[coachId][time];
            btn.classList.remove('active', 'unavailable');
            if (!available) {
                btn.classList.add('unavailable');
            }
        });
        document.getElementById('bookCoachId').value = '';
        const sc = document.getElementById('bookSelectedCoaches');
        if (sc) sc.innerHTML = '';
    }

    // Аватар тренера берём из его кнопки выбора (там уже фото или инициал).
    function coachAvatarHtml(coachId) {
        const el = document.querySelector('.coach-btn[data-coach-id="' + coachId + '"] .coach-btn-avatar');
        return '<span class="sel-coach-avatar">' + (el ? el.innerHTML : '') + '</span>';
    }
    // Мультивыбор тренеров (спарринг): одна карточка-рамка на тренера —
    // аватар + имя + цена + переключатель «Не оплачен / Оплачен» под ним.
    function coachRowHtml(coachId, name, price, paid) {
        const priceVal = (price === '' || price === null || price === undefined) ? '' : price;
        return '<div class="sel-coach-row" data-cid="' + coachId + '">' +
            '<div class="sel-coach-head">' +
                coachAvatarHtml(coachId) +
                '<span class="sel-coach-name">' + escapeHtml(name) + '</span>' +
                '<input type="number" class="sel-coach-price" name="coaches[' + coachId + '][price]" min="0" step="100" placeholder="цена ₸" value="' + priceVal + '">' +
            '</div>' +
            '<input type="hidden" name="coaches[' + coachId + '][coach_id]" value="' + coachId + '">' +
            '<input type="checkbox" class="sel-coach-paid-cb" name="coaches[' + coachId + '][paid]" value="1"' + (paid ? ' checked' : '') + ' hidden>' +
            '<div class="sel-coach-paidtoggle">' +
                '<button type="button" class="scp-btn scp-unpaid' + (paid ? '' : ' active') + '" onclick="setCoachRowPaid(this,0)">Не оплачен</button>' +
                '<button type="button" class="scp-btn scp-paid' + (paid ? ' active' : '') + '" onclick="setCoachRowPaid(this,1)">Оплачен</button>' +
            '</div>' +
        '</div>';
    }
    function bookCoachRowHtml(coachId, name, price) {
        return coachRowHtml(coachId, name, price, false);
    }
    // Переключение статуса оплаты в строке тренера (двигает скрытый чекбокс).
    function setCoachRowPaid(btn, val) {
        const row = btn.closest('.sel-coach-row');
        if (!row) return;
        const cb = row.querySelector('.sel-coach-paid-cb');
        if (cb) cb.checked = (val === 1);
        row.querySelectorAll('.scp-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }
    function selectBookCoach(btn) {
        if (btn.classList.contains('unavailable')) return;
        const coachId = btn.getAttribute('data-coach-id');
        const container = document.getElementById('bookSelectedCoaches');
        const existing = container.querySelector('.sel-coach-row[data-cid="' + coachId + '"]');
        if (existing) {
            existing.remove();
            btn.classList.remove('active');
        } else {
            btn.classList.add('active');
            const nameEl = btn.querySelector('.coach-btn-name');
            const name = nameEl ? nameEl.textContent : '';
            const rate = parseFloat(btn.getAttribute('data-rate')) || 0;
            const dur = (typeof currentBook === 'object' && currentBook.duration) ? currentBook.duration : 1;
            const price = rate > 0 ? Math.round(rate * dur) : '';
            container.insertAdjacentHTML('beforeend', bookCoachRowHtml(coachId, name, price));
        }
        // Основной тренер (совместимость) = первый выбранный.
        const first = container.querySelector('.sel-coach-row');
        document.getElementById('bookCoachId').value = first ? first.getAttribute('data-cid') : '';
        updateFinalPrice();
    }
    function setBookCoachPaid(btn) {
        document.querySelectorAll('#bookCoachPaidGroup .paid-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('bookCoachPaidInput').value = btn.getAttribute('data-value');
    }

    function setEditProcessed(btn) {
        document.querySelectorAll('.processed-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('editIsProcessedInput').value = btn.getAttribute('data-value');
    }

    // Мультивыбор тренеров в окне редактирования брони (спарринг).
    function editCoachRowHtml(coachId, name, price, paid) {
        return coachRowHtml(coachId, name, price, !!paid);
    }
    function selectEditCoach(btn) {
        if (btn.classList.contains('unavailable')) return;
        const coachId = btn.getAttribute('data-coach-id');
        const container = document.getElementById('editSelectedCoaches');
        const existing = container.querySelector('.sel-coach-row[data-cid="' + coachId + '"]');
        if (existing) {
            existing.remove();
            btn.classList.remove('active');
        } else {
            btn.classList.add('active');
            const nameEl = btn.querySelector('.coach-btn-name');
            const name = nameEl ? nameEl.textContent : '';
            const rate = parseFloat(btn.getAttribute('data-rate')) || 0;
            const slots = parseInt(document.getElementById('editSlots')?.value) || 1;
            const price = rate > 0 ? Math.round(rate * slots) : '';
            container.insertAdjacentHTML('beforeend', editCoachRowHtml(coachId, name, price, false));
        }
        const first = container.querySelector('.sel-coach-row');
        document.getElementById('editCoachId').value = first ? first.getAttribute('data-cid') : '';
        updateEditFinalPrice();
    }
    function setEditCoachPaid(btn) {
        document.querySelectorAll('#editCoachPaidGroup .paid-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('editCoachPaidInput').value = btn.getAttribute('data-value');
    }

    // Авто-открытие модалки по URL-параметрам (приходит из недельного вида)
    function autoOpenFromUrl() {
        const params = new URLSearchParams(window.location.search);
        const open = params.get('open');
        if (!open) return;

        const cleanUrl = () => {
            const u = new URL(window.location);
            ['open', 'courtId', 'courtName', 'time', 'price', 'maxSlots', 'bookingId', 'blockId'].forEach(p => u.searchParams.delete(p));
            window.history.replaceState({}, '', u.toString());
        };

        if (open === 'book') {
            const courtId = params.get('courtId');
            const courtName = params.get('courtName') || '';
            const time = params.get('time');
            const price = parseInt(params.get('price')) || 0;
            const maxSlots = parseInt(params.get('maxSlots')) || 1;
            if (courtId && time) {
                openBookModal(courtId, courtName, time, price, maxSlots);
            }
        } else if (open === 'view') {
            const bookingId = params.get('bookingId');
            const el = bookingId && document.getElementById('slot-booking-' + bookingId);
            if (el) el.click();
        } else if (open === 'unblock') {
            const blockId = params.get('blockId');
            const el = blockId && document.getElementById('slot-block-' + blockId);
            if (el) el.click();
        }

        cleanUrl();
    }
    document.addEventListener('DOMContentLoaded', autoOpenFromUrl);
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

    // Polling необработанных — бейджи на днях
    function updateDayBadges() {
        fetch('{{ route("club.unprocessedCount") }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data) return;
                const byDate = data.by_date || {};
                document.querySelectorAll('[data-date-badge]').forEach(badge => {
                    const date = badge.getAttribute('data-date-badge');
                    const count = byDate[date] || 0;
                    const btn = badge.closest('.week-day-btn');
                    if (count > 0) {
                        const word = count === 1 ? 'новая' : 'новых';
                        badge.textContent = count + ' ' + word;
                        badge.style.display = 'block';
                        if (btn) btn.classList.add('has-unprocessed');
                    } else {
                        badge.style.display = 'none';
                        if (btn) btn.classList.remove('has-unprocessed');
                    }
                });
            })
            .catch(() => {});
    }
    updateDayBadges();
    setInterval(updateDayBadges, 30000);
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

    .week-day-unprocessed {
        position: absolute;
        bottom: -10px;
        left: 50%;
        transform: translateX(-50%);
        padding: 1px 6px;
        background: #ef4444;
        color: #fff;
        font-size: 8px;
        font-weight: 700;
        border-radius: 4px;
        white-space: nowrap;
        z-index: 2;
        box-shadow: 0 2px 8px rgba(239,68,68,0.4);
    }

    .week-day-btn.has-unprocessed {
        box-shadow: 0 4px 16px rgba(239,68,68,0.4), inset 0 -2px 0 #ef4444;
    }

    .week-day-btn.active.has-unprocessed {
        box-shadow: 0 4px 20px rgba(34,197,94,0.3), inset 0 -2px 0 #ef4444;
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
        background: rgba(59, 130, 246, 0.1);
        color: var(--sch-blue);
        border-color: rgba(59, 130, 246, 0.18);
        flex-direction: column;
        padding: 4px 12px;
        gap: 1px;
        justify-content: center;
    }

    .slot-booked:hover {
        background: rgba(59, 130, 246, 0.18);
        border-color: var(--sch-blue);
    }

    .slot-booked.unpaid {
        background: rgba(251, 146, 60, 0.1);
        color: #fb923c;
        border-color: rgba(251, 146, 60, 0.18);
    }

    .slot-booked.unpaid:hover {
        background: rgba(251, 146, 60, 0.18);
        border-color: #fb923c;
    }

    .slot-booked.unpaid .slot-name { color: #fbbf24; }

    /* Иконки-маркеры брони — аккуратный ряд в правом верхнем углу слота */
    .slot { position: relative; }
    .slot-icons { position: absolute; top: 5px; right: 7px; display: flex; align-items: center; gap: 5px; pointer-events: none; z-index: 2; }
    .slot-ic { font-size: 12px; line-height: 1; }
    .ic-app  { color: #a1a1aa; }   /* из приложения — монохромный телефон */
    .ic-paid { color: #22c55e; }   /* оплачено — зелёный бейдж */
    .ic-card { color: #a1a1aa; }   /* клубная карта — серый (как было) */
    /* Если есть иконки — цену чуть ниже, чтобы не налезала на них */
    .slot.has-icons .slot-price-court { display: inline-block; margin-top: 12px; }

    /* Полоса способа оплаты сверху слота (вариант 5) */
    .slot-pm-strip {
        position: absolute; top: 0; left: 0; right: 0; z-index: 1; pointer-events: none;
        display: flex; align-items: center; gap: 4px;
        height: 16px; padding: 0 7px 0 8px;
        font-size: 9px; font-weight: 800; letter-spacing: 0.3px; text-transform: uppercase;
        color: var(--pm);
        background: color-mix(in srgb, var(--pm) 22%, #16161a);
        border-radius: 7px 7px 0 0;
        white-space: nowrap; overflow: hidden;
    }
    .slot-pm-strip i { font-size: 10px; flex-shrink: 0; }
    .slot-pm-strip span { overflow: hidden; text-overflow: ellipsis; }
    /* Контент уезжает под полосу */
    .slot.has-pm { justify-content: flex-start; padding-top: 20px; }
    .slot.has-pm .slot-icons { top: 1px; }
    .slot.has-pm .slot-price-court { margin-top: 0; }
    /* Если есть и иконки, и полоса — оставляем место справа под иконки */
    .slot.has-pm.has-icons .slot-pm-strip { padding-right: 44px; }
    .legend-pm { margin-top: 10px; }
    .legend-pm-title { font-size: 12px; font-weight: 700; color: var(--sch-text-dim, #a1a1aa); margin-right: 4px; align-self: center; }

    .slot-booked.unprocessed {
        background: rgba(239, 68, 68, 0.12);
        color: #ef4444;
        border-color: rgba(239, 68, 68, 0.25);
    }

    .slot-booked.unprocessed:hover {
        background: rgba(239, 68, 68, 0.2);
        border-color: #ef4444;
    }

    .slot-booked.unprocessed .slot-name { color: #fca5a5; }

    .slot-row { display: flex; align-items: center; justify-content: space-between; width: 100%; gap: 8px; }
    .slot-row-sub { opacity: 0.8; }
    .slot-left { display: flex; align-items: center; gap: 6px; min-width: 0; }
    .slot-right { flex-shrink: 0; }

    .slot-name { font-size: 13px; font-weight: 700; color: #e4e4e7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .slot-phone { font-size: 11px; color: #71717a; white-space: nowrap; }
    .slot-comment-text { font-size: 10px; color: #71717a; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px; }
    .slot-price-court { font-size: 13px; font-weight: 700; color: #22c55e; }
    .slot-price-coach { font-size: 12px; font-weight: 700; color: #a78bfa; }
    /* Статус оплаты тренера — сумма в цветной капсуле */
    .slot-coach-cap { font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 20px; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px; }
    .slot-coach-cap i { font-size: 10px; }
    .slot-coach-cap.paid { color: #22c55e; background: rgba(34,197,94,.14); border: 1px solid rgba(34,197,94,.3); }
    .slot-coach-cap.unpaid { color: #f59e0b; background: rgba(245,158,11,.14); border: 1px solid rgba(245,158,11,.3); }
    .slot-needs-coach { font-size: 10px; font-weight: 600; color: #a89cf5; background: rgba(168,156,245,0.15); padding: 2px 6px; border-radius: 4px; white-space: nowrap; }

    .slot-booked-multi {
        background: rgba(59, 130, 246, 0.1);
        color: var(--sch-blue);
        border-color: rgba(59, 130, 246, 0.18);
        min-height: 112px;
        border-radius: 8px;
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 1px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
        padding: 4px 12px;
    }

    .slot-booked-multi:hover {
        background: rgba(59, 130, 246, 0.18);
        border-color: var(--sch-blue);
    }

    .slot-booked-multi.unpaid {
        background: rgba(251, 146, 60, 0.1);
        color: #fb923c;
        border-color: rgba(251, 146, 60, 0.18);
    }

    .slot-booked-multi.unpaid:hover {
        background: rgba(251, 146, 60, 0.18);
        border-color: #fb923c;
    }

    .slot-booked-multi.unpaid .slot-name { color: #fbbf24; }

    .slot-booked-multi.unprocessed {
        background: rgba(239, 68, 68, 0.12);
        color: #ef4444;
        border-color: rgba(239, 68, 68, 0.25);
    }

    .slot-booked-multi.unprocessed:hover {
        background: rgba(239, 68, 68, 0.2);
        border-color: #ef4444;
    }

    .slot-booked-multi.unprocessed .slot-name { color: #fca5a5; }

    .slot-coach {
        font-size: 11px;
        font-weight: 600;
        color: #a78bfa;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .slot-coach-avatar {
        position: relative;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: linear-gradient(135deg, #a78bfa, #7c3aed);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 9px;
        font-weight: 800;
        color: #fff;
        flex-shrink: 0;
    }
    .slot-coach-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
    .slot-coach-paid {
        position: absolute;
        bottom: -1px;
        right: -1px;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        border: 1.5px solid var(--sch-card, #16161a);
    }
    .slot-coach-paid.paid { background: #22c55e; }
    .slot-coach-paid.unpaid { background: #f59e0b; }

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

    .legend-dot.unprocessed {
        background: rgba(239, 68, 68, 0.3);
        border: 1px solid #ef4444;
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
    .modal-wide {
        max-width: 1000px;
    }

    #bookModal .modal-content,
    #viewModal .modal-content,
    #unblockModal .modal-content {
        overflow: hidden;
    }

    /* ===== Бронирование/просмотр — выезжающая справа панель (drawer) ===== */
    #bookModal .modal-dialog,
    #viewModal .modal-dialog {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        margin: 0;
        width: 94vw;
        max-width: 1600px;
        height: 100vh;
        height: 100dvh;
        align-items: stretch;
    }
    #bookModal .modal-content,
    #viewModal .modal-content {
        height: 100%;
        width: 100%;
        border-radius: 0 !important;
        border-top: none;
        border-right: none;
        border-bottom: none;
        overflow-y: auto;
        overflow-x: hidden;
    }
    /* Шапка прилипает к верху при скролле */
    #bookModal .sch-modal-header,
    #viewModal .sch-modal-header {
        position: sticky;
        top: 0;
        z-index: 5;
        background: #111113;
    }
    /* Выезд справа (переопределяет вертикальный слайд Bootstrap) */
    #bookModal.fade .modal-dialog,
    #viewModal.fade .modal-dialog {
        transform: translateX(100%);
        transition: transform 0.3s ease-out;
    }
    #bookModal.show .modal-dialog,
    #viewModal.show .modal-dialog {
        transform: none;
    }
    /* На телефоне — почти на всю ширину */
    @media (max-width: 575.98px) {
        #bookModal .modal-dialog,
        #viewModal .modal-dialog {
            width: 100vw;
            max-width: 100vw;
        }
    }

    .modal-two-col {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .modal-col-left {
        padding: 20px 24px;
        border-right: 1px solid var(--sch-border);
    }

    .modal-col-right {
        padding: 20px 24px;
    }

    .modal-section-title {
        font-size: 11px;
        font-weight: 700;
        color: var(--sch-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 10px;
        margin-top: 16px;
    }

    .autocomplete-wrap {
        position: relative;
    }

    .autocomplete-list {
        display: none;
        position: absolute;
        left: 0;
        right: 0;
        top: 100%;
        z-index: 50;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        margin-top: 4px;
        max-height: 200px;
        overflow-y: auto;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
    }

    .autocomplete-list.show {
        display: block;
    }

    .autocomplete-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 14px;
        cursor: pointer;
        transition: background 0.1s;
        border-bottom: 1px solid var(--sch-border);
    }

    .autocomplete-item:last-child {
        border-bottom: none;
    }

    .autocomplete-item:hover {
        background: rgba(34, 197, 94, 0.1);
    }

    .autocomplete-item-name {
        font-size: 14px;
        font-weight: 600;
        color: var(--sch-text);
    }

    .autocomplete-item-phone {
        font-size: 13px;
        color: var(--sch-text-muted);
    }

    /* Unprocessed Panel Button */
    .unprocessed-panel-btn {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(239, 68, 68, 0.3);
        border-radius: 10px;
        color: #fca5a5;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .unprocessed-panel-btn:hover {
        background: rgba(239, 68, 68, 0.2);
        border-color: var(--sch-red);
        color: var(--sch-red);
    }

    .unprocessed-panel-btn i { font-size: 16px; }

    .unprocessed-panel-badge {
        background: var(--sch-red);
        color: #fff;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 800;
    }

    /* Slide-in Panel */
    .unprocessed-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1050;
    }

    .unprocessed-overlay.show { display: block; }

    .unprocessed-panel {
        position: fixed;
        top: 0;
        right: -460px;
        width: 440px;
        height: 100vh;
        background: var(--sch-bg);
        border-left: 1px solid var(--sch-border);
        z-index: 1051;
        display: flex;
        flex-direction: column;
        transition: right 0.3s ease;
        box-shadow: -8px 0 24px rgba(0, 0, 0, 0.3);
    }

    .unprocessed-panel.show { right: 0; }

    .unprocessed-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px 24px;
        border-bottom: 1px solid var(--sch-border);
        flex-shrink: 0;
    }

    .unprocessed-panel-header h2 {
        font-size: 18px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }

    .unprocessed-panel-header h2 i { color: var(--sch-red); }

    .unprocessed-panel-count {
        background: var(--sch-red);
        color: #fff;
        padding: 2px 10px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 800;
    }

    .unprocessed-panel-body {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .unprocessed-card {
        background: var(--sch-card);
        border: 1px solid rgba(239, 68, 68, 0.25);
        border-radius: 12px;
        padding: 16px;
    }

    .unprocessed-card-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 10px;
    }

    .unprocessed-card-client {
        display: flex;
        flex-direction: column;
    }

    .unprocessed-card-name {
        font-size: 16px;
        font-weight: 700;
        color: var(--sch-text);
    }

    .unprocessed-card-phone {
        font-size: 13px;
        color: var(--sch-text-muted);
    }

    .unprocessed-card-price {
        font-size: 16px;
        font-weight: 800;
        color: var(--sch-accent);
    }

    .unprocessed-card-details {
        display: flex;
        gap: 14px;
        font-size: 13px;
        color: var(--sch-text-dim);
        margin-bottom: 10px;
    }

    .unprocessed-card-details i {
        font-size: 12px;
        color: var(--sch-text-muted);
        margin-right: 4px;
    }

    .unprocessed-card-comment {
        font-size: 13px;
        color: var(--sch-text-muted);
        padding: 8px 10px;
        background: rgba(113, 113, 122, 0.1);
        border-radius: 6px;
        margin-bottom: 10px;
    }

    .unprocessed-card-actions {
        display: flex;
        gap: 8px;
    }

    .unprocessed-btn-view {
        width: 100%;
        padding: 10px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 8px;
        color: var(--sch-text-dim);
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: all 0.2s;
    }

    .unprocessed-btn-view:hover {
        border-color: var(--sch-accent);
        color: var(--sch-accent);
    }

    .unprocessed-empty {
        text-align: center;
        padding: 60px 20px;
        color: var(--sch-text-muted);
    }

    .unprocessed-empty i {
        font-size: 40px;
        color: var(--sch-accent);
        display: block;
        margin-bottom: 12px;
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

    .group-members-block {
        margin-bottom: 16px;
        padding: 12px 14px;
        background: rgba(255,255,255,0.02);
        border: 1px solid #27272a;
        border-radius: 10px;
    }
    .gm-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 8px;
    }
    .gm-title {
        font-size: 11px;
        font-weight: 700;
        color: var(--sch-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .gm-count {
        font-size: 12px;
        color: var(--sch-text-dim);
        background: rgba(255,255,255,0.05);
        padding: 2px 8px;
        border-radius: 999px;
    }
    .gm-list {
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .gm-item {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 6px 12px;
        padding: 8px 0;
        border-top: 1px solid rgba(255,255,255,0.05);
    }
    .gm-item:first-child {
        border-top: none;
    }
    .gm-name {
        font-size: 13px;
        color: var(--sch-text);
        font-weight: 500;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .gm-rem {
        font-size: 12px;
        font-weight: 600;
        padding: 3px 10px;
        border-radius: 999px;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .gm-rem-ok {
        background: rgba(34,197,94,0.12);
        color: #4ade80;
    }
    .gm-rem-low {
        background: rgba(234,179,8,0.12);
        color: #facc15;
    }
    .gm-rem-zero {
        background: rgba(239,68,68,0.12);
        color: #f87171;
    }
    .gm-frozen {
        margin-right: auto;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        font-weight: 700;
        color: #38bdf8;
        background: rgba(56,189,248,0.12);
        border: 1px solid rgba(56,189,248,0.3);
        border-radius: 999px;
        padding: 2px 9px;
        white-space: nowrap;
    }
    .gm-item-frozen .gm-name { color: #94a3b8; }
    .gm-empty {
        font-size: 13px;
        color: var(--sch-text-dim);
        text-align: center;
        padding: 4px 0;
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
        display: grid;
        grid-template-columns: repeat(6, 1fr);
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
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    /* Тип брони */
    .booking-type-buttons { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 4px; }
    .bt-btn {
        flex: 1 1 calc(50% - 6px);
        min-width: 120px;
        padding: 8px 10px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 8px;
        color: var(--sch-text-dim);
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        text-align: center;
        transition: all 0.2s;
    }
    .bt-btn:hover:not(.active) { border-color: var(--sch-text-dim); color: var(--sch-text); }
    .bt-soft.active { background: rgba(245,158,11,0.18); border-color: #f59e0b; color: #f59e0b; }
    .bt-group.active { background: rgba(59,130,246,0.18); border-color: #3b82f6; color: #3b82f6; }
    .bt-individual.active { background: rgba(229,231,235,0.18); border-color: #e5e7eb; color: #e5e7eb; }
    .bt-tournament.active { background: rgba(167,139,250,0.18); border-color: #a78bfa; color: #a78bfa; }

    /* Цветной фон слота по типу брони */
    .bt-slot-soft { background: rgba(245,158,11,0.30) !important; }
    .bt-slot-group { background: rgba(59,130,246,0.30) !important; }
    .bt-slot-individual { background: rgba(229,231,235,0.22) !important; }
    .bt-slot-tournament { background: rgba(167,139,250,0.30) !important; }

    .pay-btn {
        flex: 1 1 calc(25% - 6px);
        min-width: 90px;
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

    .client-card-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .client-card-btn {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
        padding: 9px 14px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.15s;
        min-width: 150px;
    }
    .client-card-btn .ccb-name { color: var(--sch-text); font-weight: 700; font-size: 13px; }
    .client-card-btn .ccb-code { color: var(--sch-text-dim); font-size: 11px; font-family: monospace; letter-spacing: 1px; }
    .client-card-btn .ccb-sub { color: var(--sch-accent); font-size: 12px; font-weight: 600; }
    .client-card-btn:hover:not(.active) { border-color: var(--sch-accent); }
    .client-card-btn.active {
        border-color: var(--sch-accent);
        background: rgba(34,197,94,.14);
        box-shadow: 0 0 0 1px var(--sch-accent) inset;
    }
    .client-card-btn.inactive {
        cursor: not-allowed;
        opacity: .55;
        filter: grayscale(.4);
    }
    .client-card-btn.inactive .ccb-sub { color: var(--sch-text-dim); }

    .coach-buttons {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6px;
    }
    @media (max-width: 600px) {
        .coach-buttons { grid-template-columns: 1fr; }
    }

    .coach-btn {
        padding: 8px 14px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 8px;
        color: var(--sch-text-dim);
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 6px;
        text-align: left;
        justify-content: flex-start;
    }
    .coach-btn-name {
        flex: 1;
        min-width: 0;
        text-align: left;
        line-height: 1.25;
    }

    .coach-btn .coach-rate {
        color: var(--sch-accent);
        font-weight: 700;
        font-size: 12px;
        flex-shrink: 0;
        white-space: nowrap;
    }

    .coach-btn-avatar {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        overflow: hidden;
        background: linear-gradient(135deg, #a78bfa, #7c3aed);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 800;
        color: #fff;
        flex-shrink: 0;
    }
    .coach-btn-avatar img { width: 100%; height: 100%; object-fit: cover; }

    .coach-btn.active {
        background: #a78bfa;
        color: #0a0a0b;
        border-color: #a78bfa;
    }

    .coach-btn.active .coach-rate {
        color: #0a0a0b;
    }

    .coach-btn.unavailable {
        opacity: 0.3;
        cursor: not-allowed;
        text-decoration: line-through;
    }

    .coach-btn:hover:not(.active):not(.unavailable) {
        border-color: #a78bfa;
        color: #a78bfa;
    }

    /* Выбранные тренеры (мультитренер / спарринг) */
    .sel-coaches {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-top: 12px;
    }
    .sel-coaches:empty { margin-top: 0; }
    .sel-coach-row {
        display: flex;
        flex-direction: column;
        gap: 9px;
        padding: 11px 12px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
    }
    .sel-coach-head {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .sel-coach-avatar {
        width: 26px;
        height: 26px;
        border-radius: 50%;
        overflow: hidden;
        background: linear-gradient(135deg, #a78bfa, #7c3aed);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 800;
        color: #fff;
        flex-shrink: 0;
    }
    .sel-coach-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .sel-coach-name {
        flex: 1;
        min-width: 90px;
        font-size: 13.5px;
        font-weight: 700;
        color: var(--sch-text);
    }
    .sel-coach-price {
        width: 120px;
        padding: 7px 10px;
        background: var(--sch-card);
        border: 1px solid var(--sch-border);
        border-radius: 7px;
        color: var(--sch-text);
        text-align: right;
        font-weight: 700;
        font-size: 15px;
    }
    /* Убираем стрелки-спиннеры у поля цены тренера */
    .sel-coach-price::-webkit-outer-spin-button,
    .sel-coach-price::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .sel-coach-price { -moz-appearance: textfield; appearance: textfield; }
    /* Построчный статус оплаты тренера — как основной переключатель оплаты */
    .sel-coach-paidtoggle {
        display: flex;
        gap: 6px;
    }
    .scp-btn {
        flex: 1;
        padding: 8px 10px;
        background: var(--sch-card);
        border: 1px solid var(--sch-border);
        border-radius: 8px;
        color: var(--sch-text-dim);
        font-size: 12.5px;
        font-weight: 700;
        cursor: pointer;
        text-align: center;
        transition: all 0.2s;
    }
    .scp-btn.scp-unpaid.active {
        background: rgba(251, 146, 60, 0.2);
        color: #fb923c;
        border-color: #fb923c;
    }
    .scp-btn.scp-paid.active {
        background: var(--sch-accent);
        color: var(--sch-bg);
        border-color: var(--sch-accent);
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

    .processed-btn {
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

    .processed-btn.active[data-value="0"] {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
        border-color: #ef4444;
    }

    .processed-btn.active[data-value="1"] {
        background: var(--sch-accent);
        color: var(--sch-bg);
        border-color: var(--sch-accent);
    }

    .processed-btn:hover:not(.active) {
        border-color: #3f3f46;
        color: var(--sch-text);
    }

    .paid-btn:hover:not(.active) {
        border-color: #3f3f46;
        color: var(--sch-text);
    }

    .price-edit-row {
        display: flex;
        gap: 10px;
        margin-bottom: 10px;
    }

    .price-edit-group {
        flex: 1;
    }

    .price-input {
        text-align: right;
        font-weight: 700;
        font-size: 15px !important;
    }

    .total-price {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 14px 20px;
        background: rgba(34, 197, 94, 0.1);
        border: 1px solid rgba(34, 197, 94, 0.2);
        border-radius: 10px;
        margin-bottom: 20px;
    }
    .total-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .total-sub-label {
        font-size: 13px;
        color: var(--sch-text-dim);
    }
    .total-sub-value {
        font-size: 14px;
        font-weight: 700;
        color: var(--sch-text);
    }
    .total-row-final {
        padding-top: 8px;
        margin-top: 2px;
        border-top: 1px solid rgba(34, 197, 94, 0.25);
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

    /* Inline error в форме бронирования */
    .book-form-error {
        display: none;
        align-items: flex-start;
        gap: 10px;
        margin: 0 24px 4px;
        padding: 12px 14px;
        background: rgba(239, 68, 68, 0.08);
        border: 1px solid rgba(239, 68, 68, 0.35);
        border-radius: 10px;
        color: #fca5a5;
        font-size: 13.5px;
        font-weight: 600;
        line-height: 1.4;
    }
    .book-form-error.is-visible {
        display: flex;
        animation: bookFormErrorIn 0.22s ease;
    }
    .book-form-error i {
        font-size: 18px;
        color: #ef4444;
        flex-shrink: 0;
        margin-top: 1px;
    }
    .book-form-error-text { display: block; }
    @keyframes bookFormErrorIn {
        from { opacity: 0; transform: translateY(-4px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    /* Подсветка проблемных полей и групп */
    .form-input.has-error {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.18) !important;
    }
    /* Подсказка под полем формы */
    .form-hint {
        display: block;
        margin-top: 6px;
        font-size: 11.5px;
        color: rgba(34, 197, 94, 0.85);
        font-weight: 600;
        line-height: 1.4;
    }
    /* Залоченное имя клиента (выбран из автокомплита) */
    .form-input.is-locked {
        background: rgba(34, 197, 94, 0.06) !important;
        border-color: rgba(34, 197, 94, 0.45) !important;
        color: #d4d4d8 !important;
        cursor: not-allowed;
    }
    input.form-input.is-locked {
        padding-right: 38px;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' fill='%2322c55e' viewBox='0 0 16 16' width='16' height='16'><path d='M8 1a3 3 0 0 0-3 3v3H4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2h-1V4a3 3 0 0 0-3-3zm0 1a2 2 0 0 1 2 2v3H6V4a2 2 0 0 1 2-2z'/></svg>");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }
    textarea.form-input.is-locked {
        padding-right: 32px;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' fill='%2322c55e' viewBox='0 0 16 16' width='14' height='14'><path d='M8 1a3 3 0 0 0-3 3v3H4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2h-1V4a3 3 0 0 0-3-3zm0 1a2 2 0 0 1 2 2v3H6V4a2 2 0 0 1 2-2z'/></svg>");
        background-repeat: no-repeat;
        background-position: top 10px right 10px;
    }
    .payment-methods.has-error,
    .paid-toggle.has-error,
    .client-card-buttons.has-error {
        position: relative;
        animation: shake 0.4s ease;
    }
    .payment-methods.has-error::before,
    .paid-toggle.has-error::before,
    .client-card-buttons.has-error::before {
        content: '';
        position: absolute;
        inset: -6px;
        border-radius: 12px;
        box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.55);
        pointer-events: none;
    }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25%      { transform: translateX(-4px); }
        75%      { transform: translateX(4px); }
    }
</style>
<script>
function toggleUnprocessedPanel() {
    document.getElementById('unprocessedPanel').classList.toggle('show');
    document.getElementById('unprocessedOverlay').classList.toggle('show');
}

function processBooking(id, url, data) {
    const formData = new FormData();
    formData.append('_token', '{{ csrf_token() }}');
    formData.append('_method', 'PUT');
    Object.keys(data).forEach(k => { if (data[k] !== null && data[k] !== undefined) formData.append(k, data[k]); });

    fetch(url, { method: 'POST', body: formData })
        .then(r => {
            if (r.ok || r.redirected) {
                const card = document.getElementById('unprocessedCard' + id);
                if (card) card.style.display = 'none';
                const remaining = document.querySelectorAll('.unprocessed-card:not([style*="display: none"])').length;
                const countEl = document.querySelector('.unprocessed-panel-count');
                const badgeEl = document.querySelector('.unprocessed-panel-badge');
                if (countEl) countEl.textContent = remaining;
                if (badgeEl) badgeEl.textContent = remaining;
                if (remaining === 0) {
                    document.querySelector('.unprocessed-panel-body').innerHTML = '<div class="unprocessed-empty"><i class="bi bi-check-circle"></i><p>Все заявки обработаны</p></div>';
                }
            } else {
                r.text().then(text => {
                    console.error('processBooking error', r.status, text);
                    alert('Ошибка ' + r.status + ': ' + (text.substring(0, 300)));
                });
            }
        })
        .catch(e => { console.error('processBooking network', e); alert('Ошибка сети: ' + e.message); });
}

function cancelUnprocessed(id, url, name) {
    if (!confirm('Отменить бронь ' + name + '?')) return;
    const formData = new FormData();
    formData.append('_token', '{{ csrf_token() }}');

    fetch(url, { method: 'POST', body: formData })
        .then(r => {
            if (r.ok || r.redirected) {
                const card = document.getElementById('unprocessedCard' + id);
                if (card) card.style.display = 'none';
                const remaining = document.querySelectorAll('.unprocessed-card:not([style*="display: none"])').length;
                const countEl = document.querySelector('.unprocessed-panel-count');
                const badgeEl = document.querySelector('.unprocessed-panel-badge');
                if (countEl) countEl.textContent = remaining;
                if (badgeEl) badgeEl.textContent = remaining;
                if (remaining === 0) {
                    document.querySelector('.unprocessed-panel-body').innerHTML = '<div class="unprocessed-empty"><i class="bi bi-check-circle"></i><p>Все заявки обработаны</p></div>';
                }
            } else {
                r.text().then(text => {
                    console.error('cancelBooking error', r.status, text);
                    alert('Ошибка ' + r.status + ': ' + (text.substring(0, 300)));
                });
            }
        })
        .catch(e => { console.error('cancelBooking network', e); alert('Ошибка сети: ' + e.message); });
}

(function() {
    const searchUrl = @json(route('club.clients.search'));
    let debounceTimer = null;

    function setupAutocomplete(inputId, listId, field, pairedInputId) {
        const input = document.getElementById(inputId);
        const list = document.getElementById(listId);
        if (!input || !list) return;

        input.addEventListener('input', function() {
            const q = this.value.trim();
            clearTimeout(debounceTimer);
            if (q.length < 1) { list.classList.remove('show'); return; }

            debounceTimer = setTimeout(() => {
                fetch(searchUrl + '?q=' + encodeURIComponent(q) + '&field=' + field)
                    .then(r => r.json())
                    .then(clients => {
                        if (!clients.length) { list.classList.remove('show'); return; }
                        list.innerHTML = clients.map(c =>
                            '<div class="autocomplete-item" data-name="' + (c.name || '').replace(/"/g, '&quot;') + '" data-phone="' + formatPhone(c.phone).replace(/"/g, '&quot;') + '" data-note="' + (c.note || '').replace(/"/g, '&quot;') + '">' +
                            '<span class="autocomplete-item-name">' + escHtml(c.name) + '</span>' +
                            '<span class="autocomplete-item-phone">' + escHtml(formatPhone(c.phone)) + '</span>' +
                            '</div>'
                        ).join('');
                        list.classList.add('show');

                        list.querySelectorAll('.autocomplete-item').forEach(item => {
                            item.addEventListener('click', function() {
                                input.value = this.dataset[field];
                                const paired = document.getElementById(pairedInputId);
                                if (paired) {
                                    const pairedField = field === 'name' ? 'phone' : 'name';
                                    paired.value = this.dataset[pairedField];
                                }
                                // Выбран существующий клиент — карточка источник истины,
                                // имя+заметка readonly. Применяем к book или edit форме.
                                const isBook = (inputId === 'bookClientName' || inputId === 'bookClientPhone');
                                const isEdit = (inputId === 'editClientName' || inputId === 'editClientPhone');
                                if (isBook || isEdit) {
                                    lockClientFields(isBook ? 'book' : 'edit', this.dataset.note || '');
                                    const ph = this.dataset.phone || '';
                                    loadClientCards(isBook ? 'book' : 'edit', ph, null);
                                }
                                list.classList.remove('show');
                            });
                        });
                    });
            }, 150);
        });

        input.addEventListener('blur', function() {
            setTimeout(() => list.classList.remove('show'), 200);
        });
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function formatPhone(p) {
        if (!p) return '';
        return '+' + p.replace(/\D/g, '');
    }

    // Book modal
    setupAutocomplete('bookClientName', 'bookNameList', 'name', 'bookClientPhone');
    setupAutocomplete('bookClientPhone', 'bookPhoneList', 'phone', 'bookClientName');

    // Ручная правка телефона — клиент уже не «выбран из базы», разлочим имя
    // и сбросим подгруженную заметку (это новый клиент / новые данные).
    const bookPhoneEl = document.getElementById('bookClientPhone');
    if (bookPhoneEl) {
        bookPhoneEl.addEventListener('input', function() {
            const nameInput = document.getElementById('bookClientName');
            if (nameInput && nameInput.hasAttribute('readonly')) {
                nameInput.removeAttribute('readonly');
                nameInput.classList.remove('is-locked');
                nameInput.removeAttribute('title');
            }
            const nameHint = document.getElementById('bookClientNameHint');
            if (nameHint) nameHint.style.display = 'none';
            const noteInput = document.getElementById('bookClientNote');
            const noteHint = document.getElementById('bookClientNoteHint');
            if (noteInput) {
                noteInput.value = '';
                noteInput.removeAttribute('readonly');
                noteInput.classList.remove('is-locked');
                noteInput.removeAttribute('title');
            }
            if (noteHint) noteHint.style.display = 'none';
            // Подгрузка карт клиента по введённому телефону (с дебаунсом).
            clearTimeout(cardLoadTimer);
            cardLoadTimer = setTimeout(() => loadClientCards('book', this.value, null), 400);
        });
    }

    // Edit modal
    setupAutocomplete('editClientName', 'editNameList', 'name', 'editClientPhone');
    setupAutocomplete('editClientPhone', 'editPhoneList', 'phone', 'editClientName');

    const editPhoneEl = document.getElementById('editClientPhone');
    if (editPhoneEl) {
        editPhoneEl.addEventListener('input', function() {
            clearTimeout(cardLoadTimer);
            cardLoadTimer = setTimeout(() => loadClientCards('edit', this.value, null), 400);
        });
    }
})();
</script>
@include('club.courts._booking_tiles_css')
@endsection

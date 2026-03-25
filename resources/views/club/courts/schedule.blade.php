@extends('layouts.app')
@section('title', 'Расписание кортов')
@section('content')

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

<div class="schedule-container" x-data="scheduleApp()">
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

    <!-- Date Navigation -->
    <div class="date-nav-wrap">
        <a href="{{ route('club.courts.schedule', ['date' => $prevDate]) }}" class="date-btn">&#8249;</a>
        <div class="date-label"><span class="day-name">{{ $dayOfWeek }}</span>, {{ $formattedDate }}</div>
        <a href="{{ route('club.courts.schedule', ['date' => $nextDate]) }}" class="date-btn">&#8250;</a>
        @if($date !== $today)
            <a href="{{ route('club.courts.schedule') }}" class="today-btn">Сегодня</a>
        @endif
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
                @foreach($timeSlots as $time)
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
                                         @click="openBookModal('{{ $court->id }}', '{{ $court->name }}', '{{ $time }}', {{ $slot['price'] }}, {{ $maxFreeSlots[$cellKey] ?? 1 }})">
                                        <span class="slot-price">{{ number_format($slot['price'], 0, '', ' ') }} &#8376;</span>
                                    </div>
                                </td>
                            @elseif($slot['status'] === 'booked' && $slot['booking'])
                                @php
                                    $booking = $slot['booking'];
                                    $span = $rowspans[$cellKey] ?? 1;
                                    $bStart = \Carbon\Carbon::parse($booking->start_time)->format('H:i');
                                    $bEnd = \Carbon\Carbon::parse($booking->end_time)->format('H:i');
                                @endphp
                                <td @if($span > 1) rowspan="{{ $span }}" style="padding: 4px;" @endif>
                                    <div class="slot {{ $span > 1 ? 'slot-booked-multi' : 'slot-booked' }}"
                                         @click="openViewModal({
                                            id: {{ $booking->id }},
                                            courtName: '{{ addslashes($court->name) }}',
                                            startTime: '{{ $bStart }}',
                                            endTime: '{{ $bEnd }}',
                                            clientName: '{{ addslashes($booking->client_name ?? '') }}',
                                            clientPhone: '{{ addslashes($booking->client_phone ?? '') }}',
                                            price: {{ $booking->price ?? 0 }}
                                         })">
                                        <span class="client-name">{{ $booking->client_name ?? 'Бронь' }}</span>
                                        @if($span > 1)
                                            <span class="slot-time">{{ $bStart }} &mdash; {{ $bEnd }}</span>
                                        @endif
                                        <span class="slot-price">{{ number_format($booking->price ?? 0, 0, '', ' ') }} &#8376;</span>
                                    </div>
                                </td>
                            @elseif($slot['status'] === 'blocked')
                                <td>
                                    <div class="slot slot-blocked"
                                         @click="openUnblockModal({{ $slot['block']->id ?? 0 }}, '{{ addslashes($court->name) }}', '{{ $time }}')">
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
        <div class="legend-item"><span class="legend-dot booked"></span>Забронирован</div>
        <div class="legend-item"><span class="legend-dot blocked"></span>Заблокирован</div>
    </div>

    <!-- Booking Modal -->
    <div class="modal-overlay" x-show="bookModal" x-cloak
         x-transition:enter="modal-enter" x-transition:enter-start="modal-enter-start" x-transition:enter-end="modal-enter-end"
         x-transition:leave="modal-leave" x-transition:leave-start="modal-leave-start" x-transition:leave-end="modal-leave-end"
         @click.self="bookModal = false" @keydown.escape.window="bookModal = false">
        <div class="modal">
            <div class="modal-header">
                <h2>Бронирование</h2>
                <button class="modal-close" @click="bookModal = false">&#10005;</button>
            </div>
            <form :action="bookFormAction" method="POST">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                <input type="hidden" name="start_time" :value="book.time">
                <input type="hidden" name="slots" :value="book.duration">
                <div class="modal-body">
                    <div class="modal-info">
                        <div class="modal-info-row">
                            <span class="modal-info-label">Корт</span>
                            <span class="modal-info-value" x-text="book.courtName"></span>
                        </div>
                        <div class="modal-info-row">
                            <span class="modal-info-label">Дата</span>
                            <span class="modal-info-value">{{ $formattedDate }}</span>
                        </div>
                        <div class="modal-info-row">
                            <span class="modal-info-label">Начало</span>
                            <span class="modal-info-value" x-text="book.time"></span>
                        </div>
                        <div class="modal-info-row">
                            <span class="modal-info-label">Цена/час</span>
                            <span class="modal-info-value" x-text="formatPrice(book.price) + ' &#8376;'"></span>
                        </div>
                    </div>

                    <hr class="modal-divider">

                    <div class="form-group">
                        <label class="form-label">Длительность</label>
                        <div class="duration-selector">
                            <template x-for="i in book.maxSlots" :key="i">
                                <button type="button" class="duration-btn"
                                        :class="{ 'active': book.duration === i }"
                                        @click="book.duration = i"
                                        x-text="i + ' ' + hourLabel(i)"></button>
                            </template>
                        </div>
                    </div>

                    <div class="total-price">
                        <span class="total-price-label">Итого</span>
                        <span class="total-price-value" x-text="formatPrice(totalPrice()) + ' &#8376;'"></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Имя клиента *</label>
                        <input type="text" name="client_name" class="form-input" placeholder="Введите имя" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Телефон (необязательно)</label>
                        <input type="text" name="client_phone" class="form-input" placeholder="+7 (___) ___-__-__">
                    </div>

                    <!-- Block option -->
                    <hr class="modal-divider">
                    <div style="text-align: center;">
                        <button type="button" class="btn-block-slot" @click="blockSlot()">Заблокировать слот</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" @click="bookModal = false">Отмена</button>
                    <button type="submit" class="btn-confirm">Забронировать</button>
                </div>
            </form>

            <!-- Hidden block form -->
            <form :action="blockFormAction" method="POST" x-ref="blockForm" style="display:none;">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                <input type="hidden" name="start_time" :value="book.time">
                <input type="hidden" name="end_time" :value="blockEndTime()">
            </form>
        </div>
    </div>

    <!-- View Booking Modal -->
    <div class="modal-overlay" x-show="viewModal" x-cloak
         x-transition:enter="modal-enter" x-transition:enter-start="modal-enter-start" x-transition:enter-end="modal-enter-end"
         x-transition:leave="modal-leave" x-transition:leave-start="modal-leave-start" x-transition:leave-end="modal-leave-end"
         @click.self="viewModal = false" @keydown.escape.window="viewModal = false">
        <div class="modal">
            <div class="modal-header">
                <h2>Бронирование</h2>
                <button class="modal-close" @click="viewModal = false">&#10005;</button>
            </div>
            <div class="modal-body">
                <div class="modal-info">
                    <div class="modal-info-row">
                        <span class="modal-info-label">Корт</span>
                        <span class="modal-info-value" x-text="view.courtName"></span>
                    </div>
                    <div class="modal-info-row">
                        <span class="modal-info-label">Дата</span>
                        <span class="modal-info-value">{{ $formattedDate }}</span>
                    </div>
                    <div class="modal-info-row">
                        <span class="modal-info-label">Время</span>
                        <span class="modal-info-value" x-text="view.startTime + ' — ' + view.endTime"></span>
                    </div>
                    <hr class="modal-divider" style="margin: 0;">
                    <div class="modal-info-row">
                        <span class="modal-info-label">Клиент</span>
                        <span class="modal-info-value" x-text="view.clientName || '—'"></span>
                    </div>
                    <div class="modal-info-row" x-show="view.clientPhone">
                        <span class="modal-info-label">Телефон</span>
                        <span class="modal-info-value" x-text="view.clientPhone"></span>
                    </div>
                    <hr class="modal-divider" style="margin: 0;">
                    <div class="modal-info-row">
                        <span class="modal-info-label">Цена</span>
                        <span class="modal-info-value" style="color: #22c55e; font-size: 18px;" x-text="formatPrice(view.price) + ' &#8376;'"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" @click="viewModal = false">Закрыть</button>
                <form :action="cancelBookingAction" method="POST" style="flex: 2;">
                    @csrf
                    <button type="submit" class="btn-danger" style="width: 100%;">Отменить бронь</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Unblock Confirmation Modal -->
    <div class="modal-overlay" x-show="unblockModal" x-cloak
         x-transition:enter="modal-enter" x-transition:enter-start="modal-enter-start" x-transition:enter-end="modal-enter-end"
         x-transition:leave="modal-leave" x-transition:leave-start="modal-leave-start" x-transition:leave-end="modal-leave-end"
         @click.self="unblockModal = false" @keydown.escape.window="unblockModal = false">
        <div class="modal">
            <div class="modal-header">
                <h2>Разблокировать слот</h2>
                <button class="modal-close" @click="unblockModal = false">&#10005;</button>
            </div>
            <div class="modal-body">
                <div class="modal-info">
                    <div class="modal-info-row">
                        <span class="modal-info-label">Корт</span>
                        <span class="modal-info-value" x-text="unblock.courtName"></span>
                    </div>
                    <div class="modal-info-row">
                        <span class="modal-info-label">Время</span>
                        <span class="modal-info-value" x-text="unblock.time"></span>
                    </div>
                </div>
                <p style="color: #a1a1aa; font-size: 14px; margin-top: 16px;">Вы уверены, что хотите разблокировать этот слот?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" @click="unblockModal = false">Отмена</button>
                <form :action="unblockAction" method="POST" style="flex: 2;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-confirm" style="width: 100%;">Разблокировать</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function scheduleApp() {
        const courtRoutes = {
            @foreach($courts as $court)
                '{{ $court->id }}': {
                    book: '{{ route('club.courts.book', $court) }}',
                    block: '{{ route('club.courts.blockSlot', $court) }}'
                },
            @endforeach
        };

        // Prices array for calculating totals across multiple slots
        const freePrices = @json($freePrices);

        // Time slots in order for price lookup
        const orderedTimes = @json($timeSlots->values()->toArray());

        return {
            bookModal: false,
            viewModal: false,
            unblockModal: false,

            book: {
                courtId: '',
                courtName: '',
                time: '',
                price: 0,
                maxSlots: 1,
                duration: 1
            },

            view: {
                id: 0,
                courtName: '',
                startTime: '',
                endTime: '',
                clientName: '',
                clientPhone: '',
                price: 0
            },

            unblock: {
                blockId: 0,
                courtName: '',
                time: ''
            },

            get bookFormAction() {
                return courtRoutes[this.book.courtId]?.book || '';
            },

            get blockFormAction() {
                return courtRoutes[this.book.courtId]?.block || '';
            },

            get cancelBookingAction() {
                return '{{ url("club/courts/bookings") }}/' + this.view.id + '/cancel';
            },

            get unblockAction() {
                return '{{ url("club/courts/blocks") }}/' + this.unblock.blockId;
            },

            openBookModal(courtId, courtName, time, price, maxSlots) {
                this.book = {
                    courtId: courtId,
                    courtName: courtName,
                    time: time,
                    price: price,
                    maxSlots: Math.min(maxSlots, 6),
                    duration: 1
                };
                this.bookModal = true;
            },

            openViewModal(data) {
                this.view = data;
                this.viewModal = true;
            },

            openUnblockModal(blockId, courtName, time) {
                this.unblock = { blockId, courtName, time };
                this.unblockModal = true;
            },

            totalPrice() {
                let total = 0;
                const startIdx = orderedTimes.indexOf(this.book.time);
                if (startIdx === -1) return this.book.price * this.book.duration;
                for (let i = 0; i < this.book.duration; i++) {
                    const t = orderedTimes[startIdx + i];
                    const key = this.book.courtId + '-' + t;
                    total += freePrices[key] || this.book.price;
                }
                return total;
            },

            blockEndTime() {
                const startIdx = orderedTimes.indexOf(this.book.time);
                if (startIdx >= 0 && startIdx + 1 < orderedTimes.length) {
                    return orderedTimes[startIdx + 1];
                }
                // Fallback: add 1 hour
                const [h, m] = this.book.time.split(':').map(Number);
                const nh = h + 1;
                return String(nh).padStart(2, '0') + ':' + String(m).padStart(2, '0');
            },

            blockSlot() {
                this.$refs.blockForm.submit();
            },

            formatPrice(val) {
                return Number(val).toLocaleString('ru-RU');
            },

            hourLabel(n) {
                if (n === 1) return 'час';
                if (n >= 2 && n <= 4) return 'часа';
                return 'часов';
            }
        };
    }
</script>

<style>
    [x-cloak] { display: none !important; }

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
        max-width: 1200px;
        margin: 0 auto;
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

    /* Date Navigation */
    .date-nav-wrap {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin-bottom: 28px;
    }

    .date-btn {
        width: 38px;
        height: 38px;
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

    .date-label {
        font-size: 17px;
        font-weight: 700;
        min-width: 220px;
        text-align: center;
    }

    .date-label .day-name {
        color: var(--sch-accent);
    }

    .today-btn {
        padding: 8px 18px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        color: var(--sch-text-dim);
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
    }

    .today-btn:hover {
        border-color: var(--sch-accent);
        color: var(--sch-accent);
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
        transform: scale(1.03);
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

    .slot-booked .client-name {
        font-size: 12px;
        font-weight: 700;
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

    .slot-booked-multi .client-name {
        font-size: 13px;
        font-weight: 700;
    }

    .slot-booked-multi .slot-time {
        font-size: 11px;
        opacity: 0.7;
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

    .legend-dot.blocked {
        background: rgba(113, 113, 122, 0.3);
        border: 1px solid var(--sch-text-muted);
    }

    /* Modal */
    .modal-overlay {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.7);
        backdrop-filter: blur(4px);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }

    .modal {
        background: var(--sch-card);
        border: 1px solid var(--sch-border);
        border-radius: 16px;
        width: 100%;
        max-width: 420px;
        margin: 16px;
    }

    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px 24px;
        border-bottom: 1px solid var(--sch-border);
    }

    .modal-header h2 {
        font-size: 18px;
        font-weight: 700;
    }

    .modal-close {
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

    .modal-close:hover { border-color: var(--sch-red); color: var(--sch-red); }

    .modal-body { padding: 24px; }

    .modal-info {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-bottom: 20px;
    }

    .modal-info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-info-label {
        font-size: 13px;
        color: var(--sch-text-muted);
        font-weight: 600;
    }

    .modal-info-value {
        font-size: 14px;
        font-weight: 700;
        color: var(--sch-text);
    }

    .modal-divider {
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
        gap: 8px;
        flex-wrap: wrap;
    }

    .duration-btn {
        flex: 1;
        min-width: 60px;
        padding: 12px;
        background: var(--sch-card-alt);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        color: var(--sch-text-dim);
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        text-align: center;
        transition: all 0.2s;
    }

    .duration-btn.active {
        background: var(--sch-accent);
        color: var(--sch-bg);
        border-color: var(--sch-accent);
    }

    .duration-btn:hover:not(.active) {
        border-color: var(--sch-accent);
        color: var(--sch-accent);
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

    .modal-footer {
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

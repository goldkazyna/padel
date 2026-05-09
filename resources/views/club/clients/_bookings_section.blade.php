{{-- Сводка бронирований клиента — встраивается в client_detail. --}}
@php
    $monthsRu = ['', 'январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'];
    $dowsRu = ['', 'Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

    $periodOptions = [
        'current_month' => 'Этот месяц',
        'previous_month' => 'Прошлый месяц',
        'last_3_months' => '3 месяца',
        'all' => 'Всё',
    ];

    // Группировка по дате
    $byDate = [];
    foreach ($clientBookings as $b) {
        $d = $b->date instanceof \Carbon\Carbon ? $b->date->format('Y-m-d') : (string) $b->date;
        $byDate[$d] ??= [];
        $byDate[$d][] = $b;
    }
    krsort($byDate);
@endphp

<div class="client-detail-section client-bookings-section">
    <div class="client-detail-label client-bookings-header">
        <span>Бронирования</span>
        <button type="button" class="btn-copy-bookings" onclick="copyBookingsSummary()" title="Скопировать">
            <i class="bi bi-clipboard"></i>
            Скопировать
        </button>
    </div>

    {{-- Period filter chips --}}
    <div class="client-bookings-periods">
        @foreach($periodOptions as $val => $label)
            <a href="{{ route('club.clients.index', array_merge(request()->query(), ['selected' => $selectedClient->id, 'booking_period' => $val])) }}"
               class="period-chip {{ $bookingPeriod === $val ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    {{-- Summary --}}
    <div class="client-bookings-summary">
        <div class="summary-cell">
            <div class="summary-num">{{ $bookingStats['count'] }}</div>
            <div class="summary-lbl">броней</div>
        </div>
        <div class="summary-cell">
            <div class="summary-num">{{ rtrim(rtrim(number_format($bookingStats['hours'], 1, '.', ''), '0'), '.') }}</div>
            <div class="summary-lbl">часов</div>
        </div>
        <div class="summary-cell">
            <div class="summary-num">{{ number_format($bookingStats['amount'], 0, '', ' ') }} ₸</div>
            <div class="summary-lbl">сумма</div>
        </div>
    </div>

    {{-- List grouped by date --}}
    @if(empty($byDate))
        <div class="client-bookings-empty">Нет бронирований за выбранный период</div>
    @else
    <div class="client-bookings-list" id="clientBookingsList"
         data-client-name="{{ $selectedClient->name }}"
         data-period-label="{{ $periodOptions[$bookingPeriod] ?? '' }}">
        @foreach($byDate as $dateStr => $items)
            @php
                $d = \Carbon\Carbon::parse($dateStr);
                $dowName = $dowsRu[$d->dayOfWeekIso] ?? '';
                $monthName = $monthsRu[$d->month] ?? '';
            @endphp
            <div class="bookings-day-block">
                <div class="bookings-day-header">
                    {{ $d->day }} {{ $monthName }} <span class="bookings-day-dow">· {{ $dowName }}</span>
                </div>
                <div class="bookings-day-rows">
                    @foreach($items as $b)
                        <div class="bookings-row" data-date="{{ $dateStr }}" data-time="{{ substr($b->start_time, 0, 5) }}–{{ substr($b->end_time, 0, 5) }}" data-court="{{ $b->court->name ?? '—' }}" data-price="{{ number_format($b->price, 0, '', ' ') }}" data-paid="{{ $b->is_paid ? 'оплачено' : 'не оплачено' }}">
                            <div class="bookings-row-left">
                                <div class="bookings-row-time">{{ substr($b->start_time, 0, 5) }}–{{ substr($b->end_time, 0, 5) }}</div>
                                <div class="bookings-row-court">{{ $b->court->name ?? '—' }}</div>
                            </div>
                            <div class="bookings-row-right">
                                <div class="bookings-row-price">{{ number_format($b->price, 0, '', ' ') }} ₸</div>
                                <div class="bookings-row-paid {{ $b->is_paid ? 'paid' : 'unpaid' }}">
                                    {{ $b->is_paid ? 'оплачено' : 'не оплачено' }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    @endif
</div>

<style>
.client-bookings-section { margin-top: 12px; }
.client-bookings-header { display: flex; justify-content: space-between; align-items: center; }
.btn-copy-bookings {
    background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.32);
    color: #22c55e; font-size: 12px; font-weight: 600;
    padding: 5px 10px; border-radius: 6px; cursor: pointer;
    display: flex; align-items: center; gap: 5px; transition: all .15s;
}
.btn-copy-bookings:hover { background: rgba(34,197,94,0.2); }
.client-bookings-periods { display: flex; gap: 6px; flex-wrap: wrap; margin: 8px 0 12px; }
.period-chip {
    padding: 5px 11px; border-radius: 6px; background: transparent;
    border: 1px solid #2a2a2a; color: #9ca3af; font-size: 12px; font-weight: 600;
    text-decoration: none; transition: all .15s;
}
.period-chip:hover { color: #f3f3f5; border-color: #3a3a3a; }
.period-chip.active { background: rgba(34,197,94,0.15); border-color: #22c55e; color: #22c55e; }
.client-bookings-summary {
    display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;
    background: rgba(255,255,255,0.02); border: 1px solid #1f1f1f;
    border-radius: 8px; padding: 10px; margin-bottom: 14px;
}
.summary-cell { text-align: center; }
.summary-num { color: #f3f3f5; font-size: 18px; font-weight: 800; line-height: 1.1; }
.summary-lbl { color: #6b7280; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; margin-top: 2px; }
.client-bookings-empty {
    padding: 18px; text-align: center; color: #6b7280; font-size: 13px;
    border: 1px dashed #2a2a2a; border-radius: 8px;
}
.client-bookings-list { display: flex; flex-direction: column; gap: 14px; }
.bookings-day-block {}
.bookings-day-header {
    color: #9ca3af; font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
}
.bookings-day-dow { color: #6b7280; font-weight: 500; text-transform: none; }
.bookings-day-rows { display: flex; flex-direction: column; gap: 5px; }
.bookings-row {
    display: flex; justify-content: space-between; align-items: center;
    background: #18181b; border: 1px solid #1f1f1f; border-radius: 8px;
    padding: 9px 12px;
}
.bookings-row-left { display: flex; flex-direction: column; gap: 1px; }
.bookings-row-time { color: #f3f3f5; font-size: 13px; font-weight: 700; }
.bookings-row-court { color: #9ca3af; font-size: 11px; }
.bookings-row-right { display: flex; flex-direction: column; align-items: flex-end; gap: 1px; }
.bookings-row-price { color: #f3f3f5; font-size: 13px; font-weight: 700; }
.bookings-row-paid { font-size: 10px; font-weight: 700; }
.bookings-row-paid.paid { color: #22c55e; }
.bookings-row-paid.unpaid { color: #f59e0b; }
</style>

<script>
function copyBookingsSummary() {
    const list = document.getElementById('clientBookingsList');
    if (!list) return;
    const clientName = list.dataset.clientName || '';
    const periodLabel = list.dataset.periodLabel || '';

    let text = `Бронирования — ${clientName}\n`;
    if (periodLabel) text += `${periodLabel}\n`;
    text += '\n';

    const dayBlocks = list.querySelectorAll('.bookings-day-block');
    dayBlocks.forEach(block => {
        const header = block.querySelector('.bookings-day-header');
        if (header) text += header.innerText.replace(/\s+/g, ' ').trim() + '\n';
        const rows = block.querySelectorAll('.bookings-row');
        rows.forEach(r => {
            const time = r.dataset.time || '';
            const court = r.dataset.court || '';
            const price = r.dataset.price || '';
            const paid = r.dataset.paid || '';
            text += `  ${time} · ${court} · ${price} ₸ · ${paid}\n`;
        });
        text += '\n';
    });

    // Итоги
    const summary = document.querySelectorAll('.client-bookings-summary .summary-cell');
    if (summary.length === 3) {
        text += `Итого: ${summary[0].querySelector('.summary-num').innerText} броней, ${summary[1].querySelector('.summary-num').innerText} ч, ${summary[2].querySelector('.summary-num').innerText}\n`;
    }

    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            const btn = document.querySelector('.btn-copy-bookings');
            if (btn) {
                const orig = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check2"></i> Скопировано';
                setTimeout(() => btn.innerHTML = orig, 1500);
            }
        });
    } else {
        // Fallback
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        alert('Скопировано');
    }
}
</script>

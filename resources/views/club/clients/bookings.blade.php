@extends('layouts.app')

@section('title', 'Брони — ' . $client->name)

@section('content')
@php
    $monthsRu = ['', 'января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    $dowsRu = ['', 'Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

    $periodOptions = [
        'future'         => 'Будущие',
        'all'            => 'Весь период',
        'current_month'  => 'Этот месяц',
        'previous_month' => 'Прошлый месяц',
        'last_3_months'  => '3 месяца',
        'custom'         => 'Календарь',
    ];

    $byDate = [];
    foreach ($bookings as $b) {
        $d = $b->date instanceof \Carbon\Carbon ? $b->date->format('Y-m-d') : (string) $b->date;
        $byDate[$d] ??= [];
        $byDate[$d][] = $b;
    }
    krsort($byDate);

    $title = $periodOptions[$period] ?? '';
    if ($period === 'custom' && ($from || $to)) {
        $title .= ': ' . ($from ? $from->format('d.m.Y') : '...') . ' – ' . ($to ? $to->format('d.m.Y') : '...');
    }

    // Заранее форматируем строки для JS (чтобы не вкладывать в @json лишнего)
    $hoursStr = rtrim(rtrim(number_format($stats['hours'], 1, '.', ''), '0'), '.');
    $amountStr = number_format($stats['amount'], 0, '', ' ');
@endphp

<div class="cb-page">
    <div class="cb-header">
        <a href="{{ route('club.clients.index', ['selected' => $client->id]) }}" class="cb-back">
            <i class="bi bi-arrow-left"></i>
            <span>К клиенту</span>
        </a>
        <div class="cb-title-block">
            <div class="cb-title">Брони — {{ $client->name }}</div>
            @if($client->phone)
                <div class="cb-subtitle">+{{ $client->phone }}</div>
            @endif
        </div>
        <button type="button" class="cb-copy" onclick="copyBookingsList()">
            <i class="bi bi-clipboard"></i>
            Скопировать
        </button>
    </div>

    {{-- Period chips --}}
    <div class="cb-periods">
        @foreach($periodOptions as $val => $label)
            <a href="{{ $val === 'custom'
                    ? '#'
                    : route('club.clients.bookings', ['client' => $client, 'period' => $val]) }}"
               @if($val === 'custom') onclick="toggleCustomRange(); return false;" @endif
               class="cb-chip {{ $period === $val ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    {{-- Custom date range --}}
    <form method="GET" action="{{ route('club.clients.bookings', $client) }}" class="cb-custom" id="cbCustom" style="{{ $period === 'custom' ? '' : 'display:none;' }}">
        <input type="hidden" name="period" value="custom">
        <label>С<input type="date" name="from" value="{{ $from?->format('Y-m-d') }}"></label>
        <label>По<input type="date" name="to" value="{{ $to?->format('Y-m-d') }}"></label>
        <button type="submit" class="cb-custom-apply">Применить</button>
    </form>

    {{-- Stats --}}
    <div class="cb-stats">
        <div class="cb-stat">
            <div class="cb-stat-num">{{ $stats['count'] }}</div>
            <div class="cb-stat-lbl">броней</div>
        </div>
        <div class="cb-stat">
            <div class="cb-stat-num">{{ rtrim(rtrim(number_format($stats['hours'], 1, '.', ''), '0'), '.') }}</div>
            <div class="cb-stat-lbl">часов</div>
        </div>
        <div class="cb-stat">
            <div class="cb-stat-num">{{ number_format($stats['amount'], 0, '', ' ') }} ₸</div>
            <div class="cb-stat-lbl">сумма</div>
        </div>
        <div class="cb-stat">
            <div class="cb-stat-num"><span style="color: #22c55e;">{{ $stats['paid'] }}</span> / <span style="color: #f59e0b;">{{ $stats['unpaid'] }}</span></div>
            <div class="cb-stat-lbl">оплачено / нет</div>
        </div>
    </div>

    @if(empty($byDate))
        <div class="cb-empty">
            <i class="bi bi-calendar-x"></i>
            <div>Нет бронирований за выбранный период</div>
        </div>
    @else
    <div class="cb-list" id="cbList"
         data-client-name="{{ $client->name }}"
         data-period-label="{{ $title }}">
        @foreach($byDate as $dateStr => $items)
            @php
                $d = \Carbon\Carbon::parse($dateStr);
                $dowName = $dowsRu[$d->dayOfWeekIso] ?? '';
                $monthName = $monthsRu[$d->month] ?? '';
            @endphp
            <div class="cb-day">
                <div class="cb-day-header">
                    <span class="cb-day-num">{{ $d->day }} {{ $monthName }}</span>
                    <span class="cb-day-dow">{{ $dowName }}</span>
                </div>
                <div class="cb-day-rows">
                    @foreach($items as $b)
                        @php
                            $bStart = substr($b->start_time, 0, 5);
                            $bEnd   = substr($b->end_time, 0, 5);
                            $courtName = $b->court?->name ?? '—';
                        @endphp
                        <div class="cb-row"
                             data-date="{{ $dateStr }}"
                             data-time="{{ $bStart }}–{{ $bEnd }}"
                             data-court="{{ $courtName }}"
                             data-price="{{ number_format($b->price, 0, '', ' ') }}"
                             data-paid="{{ $b->is_paid ? 'оплачено' : 'не оплачено' }}">
                            <div class="cb-row-time">{{ $bStart }}–{{ $bEnd }}</div>
                            <div class="cb-row-court">{{ $courtName }}</div>
                            <div class="cb-row-price">{{ number_format($b->price, 0, '', ' ') }} ₸</div>
                            <div class="cb-row-paid {{ $b->is_paid ? 'paid' : 'unpaid' }}">
                                {{ $b->is_paid ? 'оплачено' : 'не оплачено' }}
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
.cb-page { max-width: 960px; margin: 0 auto; padding: 24px 20px; color: #f3f3f5; }
.cb-header { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; flex-wrap: wrap; }
.cb-back {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 12px; border-radius: 8px;
    background: #1a1a1f; border: 1px solid #2a2a2a;
    color: #9ca3af; text-decoration: none; font-size: 13px; font-weight: 600;
    transition: all .15s;
}
.cb-back:hover { color: #f3f3f5; border-color: #3a3a3a; }
.cb-title-block { flex: 1; min-width: 0; }
.cb-title { font-size: 20px; font-weight: 800; letter-spacing: -0.3px; }
.cb-subtitle { color: #9ca3af; font-size: 13px; margin-top: 2px; }
.cb-copy {
    background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.4);
    color: #22c55e; padding: 8px 14px; border-radius: 8px;
    font-size: 13px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; gap: 6px; transition: all .15s;
}
.cb-copy:hover { background: rgba(34,197,94,0.2); }

.cb-periods { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
.cb-chip {
    padding: 7px 14px; border-radius: 8px;
    background: #1a1a1f; border: 1px solid #2a2a2a;
    color: #9ca3af; font-size: 13px; font-weight: 600;
    text-decoration: none; transition: all .15s;
}
.cb-chip:hover { color: #f3f3f5; border-color: #3a3a3a; }
.cb-chip.active { background: rgba(34,197,94,0.15); border-color: #22c55e; color: #22c55e; }

.cb-custom {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
    background: #15151a; border: 1px solid #2a2a2a;
    padding: 12px 14px; border-radius: 10px; margin-bottom: 14px;
}
.cb-custom label {
    display: flex; flex-direction: column; gap: 4px;
    color: #9ca3af; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
}
.cb-custom input[type="date"] {
    background: #0f0f12; border: 1px solid #2a2a2a; color: #f3f3f5;
    padding: 8px 10px; border-radius: 8px; font-size: 13px;
    color-scheme: dark;
}
.cb-custom-apply {
    background: #22c55e; color: #06281a;
    border: none; padding: 9px 16px; border-radius: 8px;
    font-size: 13px; font-weight: 800; cursor: pointer;
    margin-left: auto; align-self: flex-end;
}

.cb-stats {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
    margin-bottom: 18px;
}
.cb-stat {
    background: #15151a; border: 1px solid #1f1f1f;
    border-radius: 10px; padding: 14px; text-align: center;
}
.cb-stat-num { font-size: 22px; font-weight: 800; line-height: 1.1; color: #f3f3f5; letter-spacing: -0.3px; }
.cb-stat-lbl { color: #6b7280; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; margin-top: 4px; }

.cb-empty {
    padding: 60px 20px; text-align: center; color: #6b7280;
    background: #15151a; border: 1px dashed #2a2a2a; border-radius: 12px;
}
.cb-empty i { font-size: 36px; margin-bottom: 10px; display: block; opacity: 0.6; }

.cb-list { display: flex; flex-direction: column; gap: 18px; }
.cb-day {}
.cb-day-header {
    display: flex; align-items: baseline; gap: 8px;
    color: #9ca3af; font-size: 13px; font-weight: 700;
    margin-bottom: 8px;
}
.cb-day-num { color: #f3f3f5; font-size: 14px; font-weight: 800; letter-spacing: -0.2px; }
.cb-day-dow { color: #6b7280; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
.cb-day-rows { display: flex; flex-direction: column; gap: 6px; }
.cb-row {
    display: grid; grid-template-columns: 110px 1fr 120px 110px;
    align-items: center; gap: 14px;
    background: #18181b; border: 1px solid #1f1f1f; border-radius: 10px;
    padding: 11px 14px;
}
.cb-row-time { color: #f3f3f5; font-size: 14px; font-weight: 700; }
.cb-row-court { color: #d1d5db; font-size: 13px; }
.cb-row-price { color: #f3f3f5; font-size: 14px; font-weight: 700; text-align: right; }
.cb-row-paid {
    font-size: 11px; font-weight: 700;
    text-align: right;
    text-transform: lowercase;
}
.cb-row-paid.paid { color: #22c55e; }
.cb-row-paid.unpaid { color: #f59e0b; }

@media (max-width: 720px) {
    .cb-stats { grid-template-columns: repeat(2, 1fr); }
    .cb-row { grid-template-columns: 1fr 1fr; }
    .cb-row-court { grid-column: 1 / -1; order: 3; color: #9ca3af; font-size: 12px; }
    .cb-row-paid { grid-column: 1 / -1; order: 4; text-align: left; }
}
</style>

<script>
function toggleCustomRange() {
    const el = document.getElementById('cbCustom');
    if (!el) return;
    el.style.display = el.style.display === 'none' ? '' : 'none';
}

function copyBookingsList() {
    const list = document.getElementById('cbList');
    const clientName = list?.dataset?.clientName || @json($client->name);
    const periodLabel = list?.dataset?.periodLabel || '';

    let text = `Бронирования — ${clientName}\n`;
    if (periodLabel) text += `Период: ${periodLabel}\n`;
    text += '\n';

    if (list) {
        const days = list.querySelectorAll('.cb-day');
        days.forEach(day => {
            const num = day.querySelector('.cb-day-num')?.innerText || '';
            const dow = day.querySelector('.cb-day-dow')?.innerText || '';
            text += `${num} (${dow})\n`;
            day.querySelectorAll('.cb-row').forEach(r => {
                const t = r.dataset.time || '';
                const c = r.dataset.court || '';
                const p = r.dataset.price || '';
                const paid = r.dataset.paid || '';
                text += `  ${t} · ${c} · ${p} ₸ · ${paid}\n`;
            });
            text += '\n';
        });
    } else {
        text += 'Нет бронирований за выбранный период\n';
    }

    const __sCount = @json($stats['count']);
    const __sHours = @json($hoursStr);
    const __sAmount = @json($amountStr);
    text += 'Итого: ' + __sCount + ' броней, ' + __sHours + ' ч, ' + __sAmount + ' ₸\n';

    const onCopied = () => {
        const btn = document.querySelector('.cb-copy');
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2"></i> Скопировано';
            setTimeout(() => btn.innerHTML = orig, 1500);
        }
    };

    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(onCopied);
    } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        onCopied();
    }
}
</script>
@endsection

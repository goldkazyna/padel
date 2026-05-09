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
        <button type="button" class="cb-copy" onclick="printBookings()">
            <i class="bi bi-file-earmark-pdf"></i>
            Скачать PDF
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

function printBookings() {
    const list = document.getElementById('cbList');
    const clientName = @json($client->name);
    const clientPhone = @json($client->phone);
    const periodLabel = @json($title);
    const sCount = @json($stats['count']);
    const sHours = @json($hoursStr);
    const sAmount = @json($amountStr);
    const sPaid = @json($stats['paid']);
    const sUnpaid = @json($stats['unpaid']);

    let listHtml = '';
    if (list) {
        const days = list.querySelectorAll('.cb-day');
        days.forEach(day => {
            const num = day.querySelector('.cb-day-num')?.innerText || '';
            const dow = day.querySelector('.cb-day-dow')?.innerText || '';
            let rowsHtml = '';
            day.querySelectorAll('.cb-row').forEach(r => {
                const t = r.dataset.time || '';
                const c = r.dataset.court || '';
                const p = r.dataset.price || '';
                const paid = r.dataset.paid || '';
                const isPaid = paid === 'оплачено';
                rowsHtml += `<tr>
                    <td class="pd-time">${t}</td>
                    <td class="pd-court">${c}</td>
                    <td class="pd-price">${p} ₸</td>
                    <td class="pd-paid ${isPaid ? 'paid' : 'unpaid'}">${paid}</td>
                </tr>`;
            });
            listHtml += `<div class="pd-day">
                <div class="pd-day-h"><span class="pd-day-num">${num}</span><span class="pd-day-dow">${dow}</span></div>
                <table class="pd-rows"><tbody>${rowsHtml}</tbody></table>
            </div>`;
        });
    } else {
        listHtml = '<div class="pd-empty">Нет бронирований за выбранный период</div>';
    }

    const docCss = `
.print-doc { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #000; padding: 24px 28px; }
.print-doc h1 { font-size: 22px; font-weight: 800; letter-spacing: -0.4px; margin: 0 0 4px; }
.print-doc .pd-sub { color: #555; font-size: 13px; margin-bottom: 4px; }
.print-doc .pd-period { color: #666; font-size: 12px; margin-bottom: 18px; }
.print-doc .pd-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 22px; }
.print-doc .pd-stat { border: 1px solid #d4d4d8; border-radius: 8px; padding: 10px; text-align: center; }
.print-doc .pd-stat-num { font-size: 18px; font-weight: 800; color: #000; line-height: 1.1; }
.print-doc .pd-stat-lbl { font-size: 10px; color: #666; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
.print-doc .pd-day { margin-bottom: 14px; page-break-inside: avoid; }
.print-doc .pd-day-h { display: flex; align-items: baseline; gap: 8px; padding: 4px 0 6px; border-bottom: 1px solid #e5e5e5; margin-bottom: 6px; }
.print-doc .pd-day-num { font-size: 14px; font-weight: 800; color: #000; letter-spacing: -0.2px; }
.print-doc .pd-day-dow { color: #888; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
.print-doc table.pd-rows { width: 100%; border-collapse: collapse; }
.print-doc table.pd-rows td { padding: 6px 8px; font-size: 12.5px; vertical-align: middle; border-bottom: 1px solid #f0f0f0; }
.print-doc .pd-time { font-weight: 700; width: 110px; }
.print-doc .pd-court { color: #555; }
.print-doc .pd-price { text-align: right; font-weight: 700; width: 100px; }
.print-doc .pd-paid { text-align: right; width: 110px; font-size: 11px; font-weight: 700; }
.print-doc .pd-paid.paid { color: #176c2f; }
.print-doc .pd-paid.unpaid { color: #a05a00; }
.print-doc .pd-empty { padding: 40px; text-align: center; color: #888; border: 1px dashed #ccc; border-radius: 8px; }
.print-doc .pd-footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e5e5e5; color: #777; font-size: 11px; }
`;

    const html = `<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Брони — ${clientName}</title>
<style>
@page { margin: 14mm; }
body { margin: 0; }
${docCss}
</style>
</head>
<body>
<div class="print-doc">
    <h1>Брони — ${clientName}</h1>
    ${clientPhone ? `<div class="pd-sub">+${clientPhone}</div>` : ''}
    ${periodLabel ? `<div class="pd-period">Период: ${periodLabel}</div>` : ''}
    <div class="pd-stats">
        <div class="pd-stat"><div class="pd-stat-num">${sCount}</div><div class="pd-stat-lbl">броней</div></div>
        <div class="pd-stat"><div class="pd-stat-num">${sHours}</div><div class="pd-stat-lbl">часов</div></div>
        <div class="pd-stat"><div class="pd-stat-num">${sAmount} ₸</div><div class="pd-stat-lbl">сумма</div></div>
        <div class="pd-stat"><div class="pd-stat-num"><span style="color:#176c2f">${sPaid}</span> / <span style="color:#a05a00">${sUnpaid}</span></div><div class="pd-stat-lbl">оплачено / нет</div></div>
    </div>
    ${listHtml}
    <div class="pd-footer">Сгенерировано ${new Date().toLocaleDateString('ru-RU')} в ${new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</div>
</div>
</body>
</html>`;

    const w = window.open('', '_blank');
    if (!w) { alert('Разрешите всплывающие окна для печати PDF'); return; }
    w.document.open();
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(() => { w.print(); }, 300);
}
</script>
@endsection

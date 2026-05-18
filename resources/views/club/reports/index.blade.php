@extends('layouts.app')

@section('title', 'Отчёты клуба')

@php
    $formatMoney = fn($v) => number_format((float) $v, 0, '.', ' ') . ' ₸';
    $delta = function ($now, $prev) {
        if ($prev <= 0) return null;
        return round((($now - $prev) / $prev) * 100, 1);
    };
    $deltaRevenue = $delta($data['revenue'], $prev['revenue']);
    $deltaCount = $delta($data['count'], $prev['count']);
    $deltaOcc = $delta($data['occupancy'], $prev['occupancy']);
    $deltaAvg = $delta($data['avg_check'], $prev['avg_check']);

    $exportParams = [];
    if ($preset) $exportParams['preset'] = $preset;
    $exportParams['from'] = $from->format('Y-m-d');
    $exportParams['to'] = $to->format('Y-m-d');
@endphp

@section('content')
<div class="container-fluid" style="padding:20px;max-width:1400px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
        <div>
            <h1 style="font-size:22px;font-weight:800;margin:0;">Отчёты клуба</h1>
            <div style="color:#a0a0a0;font-size:13px;margin-top:4px;">{{ $club->name }} · {{ $periodLabel }}</div>
        </div>
        <a href="{{ route('club.reports.export', $exportParams) }}" class="btn" style="background:#22c47a;color:#0a0a0d;font-weight:700;padding:10px 18px;border-radius:10px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
            <i class="bi bi-file-earmark-excel"></i> Экспорт в Excel
        </a>
    </div>

    {{-- === Preset + custom range === --}}
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:20px;">
        @php
            $presets = ['today' => 'Сегодня', 'week' => 'Неделя', 'month' => 'Месяц', 'prev_month' => 'Прошлый месяц'];
        @endphp
        @foreach ($presets as $key => $label)
            @php $isActive = $preset === $key; @endphp
            <a href="{{ route('club.reports.index', ['preset' => $key]) }}"
               style="padding:8px 14px;border-radius:18px;text-decoration:none;
                      background:{{ $isActive ? 'rgba(34,196,122,0.14)' : 'rgba(255,255,255,0.04)' }};
                      color:{{ $isActive ? '#22c47a' : '#a0a0a0' }};
                      font-weight:600;font-size:13px;">
                {{ $label }}
            </a>
        @endforeach
        <form method="GET" action="{{ route('club.reports.index') }}" style="display:inline-flex;gap:6px;align-items:center;margin-left:8px;">
            <input type="date" name="from" value="{{ $from->format('Y-m-d') }}" style="padding:6px 10px;border-radius:8px;background:#1c1c21;color:#f3f3f5;border:1px solid rgba(255,255,255,0.06);font-size:13px;">
            <span style="color:#6a6a73;">—</span>
            <input type="date" name="to" value="{{ $to->format('Y-m-d') }}" style="padding:6px 10px;border-radius:8px;background:#1c1c21;color:#f3f3f5;border:1px solid rgba(255,255,255,0.06);font-size:13px;">
            <button type="submit" style="padding:8px 14px;border-radius:8px;background:#22c47a;color:#0a0a0d;border:none;font-weight:700;font-size:13px;cursor:pointer;">Применить</button>
        </form>
    </div>

    @if($noPhoneTotal > 0)
    <a href="{{ route('club.reports.noPhone') }}"
       style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;margin-bottom:20px;border-radius:12px;
              background:rgba(249,115,22,0.10);border:1px solid rgba(249,115,22,0.35);text-decoration:none;color:inherit;transition:all .15s;"
       onmouseover="this.style.background='rgba(249,115,22,0.16)'" onmouseout="this.style.background='rgba(249,115,22,0.10)'">
        <div style="display:flex;align-items:center;gap:12px;">
            <i class="bi bi-exclamation-triangle-fill" style="font-size:22px;color:#f97316;"></i>
            <div>
                <div style="font-weight:700;color:#fdba74;font-size:14.5px;">Клиенты без телефона</div>
                <div style="font-size:12.5px;color:#a0a0a0;margin-top:2px;">Карточки клиентов без номера — нужно дозаполнить</div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="background:rgba(249,115,22,0.20);color:#f97316;padding:6px 14px;border-radius:20px;font-weight:800;font-size:14px;">
                {{ $noPhoneTotal }}
            </span>
            <i class="bi bi-chevron-right" style="color:#71717a;font-size:14px;"></i>
        </div>
    </a>
    @endif

    {{-- === KPI block === --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:20px;">
        @include('club.reports._kpi', ['label' => 'Выручка', 'value' => $formatMoney($data['revenue']), 'delta' => $deltaRevenue, 'icon' => 'bi-cash-stack', 'iconColor' => '#22c47a'])
        @include('club.reports._kpi', ['label' => 'Брони', 'value' => $data['count'] . (($data['cancelled'] > 0) ? ' (отменено: ' . $data['cancelled'] . ')' : ''), 'delta' => $deltaCount, 'icon' => 'bi-calendar-check', 'iconColor' => '#4a8bf5'])
        @include('club.reports._kpi', ['label' => 'Загруженность', 'value' => $data['occupancy'] . '%', 'delta' => $deltaOcc, 'icon' => 'bi-pie-chart', 'iconColor' => '#eab34e'])
        @include('club.reports._kpi', ['label' => 'Средний чек', 'value' => $formatMoney(round($data['avg_check'], 0)), 'delta' => $deltaAvg, 'icon' => 'bi-graph-up', 'iconColor' => '#a89cf5'])
    </div>

    {{-- === Charts row 1: Revenue by day (full width) === --}}
    <div style="background:#1c1c21;border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:18px;margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px;">
            <div style="font-size:15px;font-weight:700;">Выручка по дням</div>
            <div style="color:#6a6a73;font-size:12px;">{{ $formatMoney($data['revenue']) }} за период</div>
        </div>
        <canvas id="chartRevenue" height="90"></canvas>
    </div>

    {{-- === Charts row 2 === --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px;margin-bottom:16px;">
        <div style="background:#1c1c21;border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:18px;">
            <div style="font-size:15px;font-weight:700;margin-bottom:12px;">Загруженность по часам</div>
            <canvas id="chartHour" height="180"></canvas>
        </div>
        <div style="background:#1c1c21;border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:18px;">
            <div style="font-size:15px;font-weight:700;margin-bottom:12px;">По дням недели</div>
            <canvas id="chartDow" height="180"></canvas>
        </div>
    </div>

    {{-- === Charts row 3 === --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:16px;">
        <div style="background:#1c1c21;border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:18px;">
            <div style="font-size:15px;font-weight:700;margin-bottom:12px;">Выручка по кортам</div>
            @if (count($data['by_court']) > 0)
                <canvas id="chartCourt" height="180"></canvas>
            @else
                <div style="color:#6a6a73;font-size:13px;text-align:center;padding:20px;">Нет данных</div>
            @endif
        </div>
        <div style="background:#1c1c21;border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:18px;">
            <div style="font-size:15px;font-weight:700;margin-bottom:12px;">Источник брони</div>
            @if ($data['source_app'] + $data['source_admin'] > 0)
                <canvas id="chartSource" height="180"></canvas>
            @else
                <div style="color:#6a6a73;font-size:13px;text-align:center;padding:20px;">Нет данных</div>
            @endif
        </div>
        <div style="background:#1c1c21;border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:18px;">
            <div style="font-size:15px;font-weight:700;margin-bottom:12px;">Способы оплаты</div>
            @if (count($data['by_payment']) > 0)
                <canvas id="chartPayment" height="180"></canvas>
            @else
                <div style="color:#6a6a73;font-size:13px;text-align:center;padding:20px;">Нет данных</div>
            @endif
        </div>
    </div>

    {{-- === Tables === --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:16px;">
        <div style="background:#1c1c21;border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:18px;">
            <div style="font-size:15px;font-weight:700;margin-bottom:12px;">Топ клиенты</div>
            @if (count($data['top_clients']) > 0)
                <table style="width:100%;font-size:13px;">
                    <thead>
                        <tr style="color:#6a6a73;text-align:left;">
                            <th style="padding:6px 0;font-weight:600;">Клиент</th>
                            <th style="padding:6px 0;font-weight:600;text-align:center;">Бронь</th>
                            <th style="padding:6px 0;font-weight:600;text-align:right;">Сумма</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['top_clients'] as $c)
                            <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                <td style="padding:8px 0;">
                                    <div style="font-weight:600;">{{ $c['name'] }}</div>
                                    @if ($c['phone'])
                                        <div style="color:#6a6a73;font-size:11px;">+{{ $c['phone'] }}</div>
                                    @endif
                                </td>
                                <td style="padding:8px 0;text-align:center;">{{ $c['count'] }}</td>
                                <td style="padding:8px 0;text-align:right;color:#22c47a;font-weight:700;">{{ $formatMoney($c['sum']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div style="color:#6a6a73;font-size:13px;text-align:center;padding:20px;">Нет данных</div>
            @endif
        </div>

        <div style="background:#1c1c21;border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:18px;">
            <div style="font-size:15px;font-weight:700;margin-bottom:12px;">Брони с тренерами</div>
            @if (count($data['by_coach']) > 0)
                <table style="width:100%;font-size:13px;">
                    <thead>
                        <tr style="color:#6a6a73;text-align:left;">
                            <th style="padding:6px 0;font-weight:600;">Тренер</th>
                            <th style="padding:6px 0;font-weight:600;text-align:right;">Брони</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['by_coach'] as $c)
                            <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                <td style="padding:8px 0;font-weight:600;">{{ $c['name'] }}</td>
                                <td style="padding:8px 0;text-align:right;color:#a89cf5;font-weight:700;">{{ $c['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div style="color:#6a6a73;font-size:13px;text-align:center;padding:20px;">Нет данных</div>
            @endif
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const gridColor = 'rgba(255,255,255,0.05)';
    const tickColor = '#a0a0a0';
    Chart.defaults.color = tickColor;
    Chart.defaults.font.family = 'system-ui, -apple-system, Segoe UI, Roboto, sans-serif';

    const revenueByDay = @json($data['revenue_by_day']);
    const occByHour = @json($data['occupancy_by_hour']);
    const byDow = @json($data['by_day_of_week']);
    const byCourt = @json($data['by_court']);
    const sourceApp = {{ $data['source_app'] }};
    const sourceAdmin = {{ $data['source_admin'] }};
    const byPayment = @json($data['by_payment']);

    // Revenue by day — line
    new Chart(document.getElementById('chartRevenue'), {
        type: 'line',
        data: {
            labels: revenueByDay.map(x => x.date),
            datasets: [{
                label: 'Выручка (₸)',
                data: revenueByDay.map(x => x.value),
                borderColor: '#22c47a',
                backgroundColor: 'rgba(34,196,122,0.12)',
                fill: true,
                tension: 0.3,
                pointRadius: 3,
                pointBackgroundColor: '#22c47a',
            }],
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: gridColor } },
                y: { grid: { color: gridColor }, beginAtZero: true },
            },
        },
    });

    // Occupancy by hour — bar
    new Chart(document.getElementById('chartHour'), {
        type: 'bar',
        data: {
            labels: occByHour.map(x => x.hour),
            datasets: [{
                label: 'Брони',
                data: occByHour.map(x => x.count),
                backgroundColor: '#4a8bf5',
                borderRadius: 4,
            }],
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: gridColor } },
                y: { grid: { color: gridColor }, beginAtZero: true, ticks: { precision: 0 } },
            },
        },
    });

    // By day of week — bar
    new Chart(document.getElementById('chartDow'), {
        type: 'bar',
        data: {
            labels: byDow.map(x => x.label),
            datasets: [{
                label: 'Брони',
                data: byDow.map(x => x.count),
                backgroundColor: '#eab34e',
                borderRadius: 4,
            }],
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: gridColor } },
                y: { grid: { color: gridColor }, beginAtZero: true, ticks: { precision: 0 } },
            },
        },
    });

    // By court — doughnut
    if (byCourt.length > 0) {
        new Chart(document.getElementById('chartCourt'), {
            type: 'doughnut',
            data: {
                labels: byCourt.map(x => x.name),
                datasets: [{
                    data: byCourt.map(x => x.value),
                    backgroundColor: ['#22c47a', '#4a8bf5', '#a89cf5', '#eab34e', '#f0554d', '#f08446', '#38bdf8'],
                    borderColor: '#1c1c21',
                    borderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom', labels: { padding: 10, boxWidth: 10 } } },
            },
        });
    }

    // Source — doughnut
    if (sourceApp + sourceAdmin > 0) {
        new Chart(document.getElementById('chartSource'), {
            type: 'doughnut',
            data: {
                labels: ['Приложение', 'Админ'],
                datasets: [{
                    data: [sourceApp, sourceAdmin],
                    backgroundColor: ['#22c47a', '#6a6a73'],
                    borderColor: '#1c1c21',
                    borderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom', labels: { padding: 10, boxWidth: 10 } } },
            },
        });
    }

    // Payment methods — horizontal bar
    if (byPayment.length > 0) {
        new Chart(document.getElementById('chartPayment'), {
            type: 'bar',
            data: {
                labels: byPayment.map(x => x.label),
                datasets: [{
                    label: 'Брони',
                    data: byPayment.map(x => x.count),
                    backgroundColor: '#a89cf5',
                    borderRadius: 4,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: gridColor }, beginAtZero: true, ticks: { precision: 0 } },
                    y: { grid: { color: gridColor } },
                },
            },
        });
    }
</script>
@endsection

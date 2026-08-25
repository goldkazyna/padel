{{-- Задолженности в разрезе клиента.

     Общий отчёт отвечает «сколько нам должны», этот — «за что должен вот
     этот человек»: даты, время, корт. Печатается из браузера в PDF, поэтому
     ниже есть отдельный набор print-стилей: на бумагу уходит только
     разбивка выбранного клиента, без меню и списка должников. --}}
@extends('layouts.app')

@section('title', 'Задолженности по клиенту')

@section('content')
<div class="dbt-wrap">

    <div class="dbt-head no-print">
        <a href="{{ route('club.reports.extra.index') }}" class="dbt-back"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1>Задолженности по клиенту</h1>
            <div class="dbt-sub">{{ $club->name }} · неоплаченные брони</div>
        </div>
        <div class="dbt-total">
            <b>{{ number_format($totalDebt, 0, '', ' ') }} ₸</b>
            <span>всего долг</span>
        </div>
    </div>

    <form method="GET" class="dbt-filter no-print">
        @if($current)<input type="hidden" name="client" value="{{ $current['key'] }}">@endif
        <span>Период</span>
        <input type="date" name="from" value="{{ $from?->format('Y-m-d') }}">
        <span>—</span>
        <input type="date" name="to" value="{{ $to?->format('Y-m-d') }}">
        <button type="submit">Применить</button>
        @if($from || $to)
            <a href="{{ route('club.reports.debts.client', ['client' => $current['key'] ?? null]) }}" class="dbt-reset">За всё время</a>
        @endif
    </form>

    <div class="dbt-cols">
        {{-- Кто должен --}}
        <div class="dbt-list no-print">
            <div class="dbt-label">Должники · {{ count($clients) }}</div>
            @forelse($clients as $c)
                <a href="{{ route('club.reports.debts.client', array_filter([
                        'client' => $c['key'],
                        'from' => $from?->format('Y-m-d'),
                        'to' => $to?->format('Y-m-d'),
                   ])) }}"
                   class="dbt-item {{ ($current['key'] ?? null) === $c['key'] ? 'active' : '' }}">
                    <div class="dbt-item-main">
                        <div class="dbt-item-name">{{ $c['name'] }}</div>
                        <div class="dbt-item-sub">
                            @if($c['phone'])@phoneFmt($c['phone'])@else номер не указан @endif
                            · {{ $c['count'] }} бр.
                        </div>
                    </div>
                    <div class="dbt-item-sum">{{ number_format($c['total'], 0, '', ' ') }} ₸</div>
                </a>
            @empty
                <div class="dbt-empty">Задолженностей нет</div>
            @endforelse
        </div>

        {{-- Разбивка --}}
        <div class="dbt-detail">
            @if(!$current)
                <div class="dbt-empty dbt-empty-big no-print">
                    <i class="bi bi-person-lines-fill"></i>
                    <p>Выберите клиента слева — покажем, за что он должен</p>
                </div>
            @else
                <div class="dbt-card">
                    <div class="dbt-card-head">
                        <div>
                            <div class="dbt-label">Задолженность клиента</div>
                            <h2>{{ $current['name'] }}</h2>
                            <div class="dbt-sub">
                                @if($current['phone'])@phoneFmt($current['phone'])@endif
                                · {{ $club->name }}
                                @if($from || $to)
                                    · период {{ $from?->format('d.m.Y') ?? '…' }} — {{ $to?->format('d.m.Y') ?? '…' }}
                                @endif
                            </div>
                        </div>
                        <div class="dbt-card-sum">
                            <b>{{ number_format($current['total'], 0, '', ' ') }} ₸</b>
                            <span>{{ $current['count'] }} неоплаченных броней</span>
                        </div>
                    </div>

                    @foreach($months as $month)
                        <div class="dbt-month">
                            <div class="dbt-month-head">
                                <span>{{ $month['label'] }}</span>
                                <b>{{ number_format($month['total'], 0, '', ' ') }} ₸</b>
                            </div>
                            <table class="dbt-table">
                                <thead>
                                    <tr>
                                        <th>Дата</th>
                                        <th>Время</th>
                                        <th>Корт</th>
                                        <th>Тренер</th>
                                        <th class="num">Сумма</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($month['rows'] as $row)
                                        @php
                                            $b = $row['booking'];
                                            $start = \Carbon\Carbon::parse($b->start_time)->format('H:i');
                                            $end = \Carbon\Carbon::parse($b->end_time)->format('H:i');
                                        @endphp
                                        <tr>
                                            <td>{{ \Carbon\Carbon::parse($b->date)->format('d.m.Y') }}</td>
                                            <td class="mono">{{ $start }}–{{ $end }}</td>
                                            <td>{{ $b->court->name ?? '—' }}</td>
                                            <td class="muted">
                                                {{ $b->coach ? ($b->coach->first_name ?? $b->coach->name) : '—' }}
                                            </td>
                                            <td class="num">{{ number_format($row['amount'], 0, '', ' ') }} ₸</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach

                    <div class="dbt-foot">
                        <span>Итого к оплате</span>
                        <b>{{ number_format($current['total'], 0, '', ' ') }} ₸</b>
                    </div>

                    <div class="dbt-print-note">
                        {{ $club->name }} · сформировано {{ now()->format('d.m.Y') }}
                    </div>
                </div>

                <button type="button" class="dbt-print no-print" onclick="window.print()">
                    <i class="bi bi-printer"></i> Печать / сохранить в PDF
                </button>
            @endif
        </div>
    </div>
</div>

<style>
.dbt-wrap{max-width:1200px;margin:0 auto;padding:20px 16px 40px;color:#f4f4f5;
  --card:#16161a;--card2:#1e1e24;--line:#27272a;--accent:#22c47a;--t2:#a1a1aa;--t3:#71717a;--red:#f0554d;}
.dbt-head{display:flex;align-items:center;gap:14px;margin-bottom:18px;}
.dbt-head h1{font-size:22px;font-weight:800;margin:0;}
.dbt-back{width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;
  background:var(--card);border:1px solid var(--line);border-radius:10px;color:var(--t2);text-decoration:none;}
.dbt-sub{color:var(--t3);font-size:13px;margin-top:2px;}
.dbt-total{margin-left:auto;text-align:right;}
.dbt-total b{display:block;font-size:22px;font-weight:800;color:var(--red);}
.dbt-total span{font-size:11px;color:var(--t3);}

.dbt-filter{display:flex;align-items:center;gap:8px;margin-bottom:18px;color:var(--t3);font-size:13px;}
.dbt-filter input{padding:6px 10px;border-radius:8px;background:#1c1c21;color:#f3f3f5;
  border:1px solid rgba(255,255,255,.06);font-size:13px;}
.dbt-filter button{padding:7px 14px;border-radius:8px;background:var(--accent);color:#0a0a0d;
  border:none;font-weight:700;font-size:13px;cursor:pointer;}
.dbt-reset{color:var(--t2);font-size:12.5px;text-decoration:none;margin-left:4px;}

.dbt-cols{display:grid;grid-template-columns:320px 1fr;gap:16px;align-items:start;}
@media (max-width:900px){.dbt-cols{grid-template-columns:1fr;}}
.dbt-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;
  color:var(--t3);margin-bottom:10px;}

.dbt-list{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;}
.dbt-item{display:flex;align-items:center;gap:10px;padding:10px 11px;border-radius:10px;
  background:var(--card2);margin-bottom:7px;text-decoration:none;color:inherit;}
.dbt-item:hover{background:#26262d;}
.dbt-item.active{border:1px solid var(--accent);background:rgba(34,196,122,.1);}
.dbt-item-main{flex:1;min-width:0;}
.dbt-item-name{font-size:14px;font-weight:700;color:#fff;}
.dbt-item-sub{font-size:11.5px;color:var(--t3);margin-top:2px;}
.dbt-item-sum{font-size:14px;font-weight:800;color:var(--red);white-space:nowrap;}

.dbt-detail{min-width:0;}
.dbt-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px 20px;}
.dbt-card-head{display:flex;align-items:flex-start;gap:16px;padding-bottom:14px;
  border-bottom:2px solid var(--accent);margin-bottom:16px;}
.dbt-card-head h2{font-size:20px;font-weight:800;margin:2px 0 4px;}
.dbt-card-sum{margin-left:auto;text-align:right;white-space:nowrap;}
.dbt-card-sum b{display:block;font-size:24px;font-weight:800;color:var(--red);}
.dbt-card-sum span{font-size:11px;color:var(--t3);}

.dbt-month{margin-bottom:18px;}
.dbt-month-head{display:flex;justify-content:space-between;align-items:baseline;
  padding-bottom:6px;margin-bottom:6px;border-bottom:1px solid var(--line);}
.dbt-month-head span{font-size:12.5px;font-weight:700;text-transform:capitalize;color:var(--t2);}
.dbt-month-head b{font-size:13px;font-weight:800;}

.dbt-table{width:100%;border-collapse:collapse;font-size:13px;}
.dbt-table th{text-align:left;font-size:10px;letter-spacing:.8px;text-transform:uppercase;
  color:var(--t3);font-weight:700;padding:4px 8px;}
.dbt-table td{padding:8px;border-bottom:1px solid rgba(255,255,255,.04);}
.dbt-table tr:last-child td{border-bottom:none;}
.dbt-table .num{text-align:right;font-weight:700;white-space:nowrap;}
.dbt-table .mono{font-variant-numeric:tabular-nums;white-space:nowrap;color:var(--t2);}
.dbt-table .muted{color:var(--t3);}

.dbt-foot{display:flex;justify-content:space-between;align-items:center;
  padding-top:12px;border-top:2px solid var(--accent);font-size:15px;}
.dbt-foot b{font-size:20px;font-weight:800;color:var(--red);}
.dbt-print-note{display:none;}

.dbt-print{margin-top:14px;padding:11px 18px;border-radius:10px;background:var(--accent);
  color:#0a0a0d;border:none;font-weight:700;font-size:14px;cursor:pointer;}

.dbt-empty{color:var(--t3);font-size:13.5px;padding:8px 2px;}
.dbt-empty-big{text-align:center;padding:60px 20px;background:var(--card);
  border:1px dashed var(--line);border-radius:14px;}
.dbt-empty-big i{font-size:28px;display:block;margin-bottom:10px;opacity:.5;}

/* На печать уходит только карточка клиента, на белом и без интерфейса. */
@media print{
  @page{margin:14mm;}
  body{background:#fff !important;}
  .no-print,.sidebar,.navbar,nav,header,footer{display:none !important;}
  .dbt-wrap{max-width:none;padding:0;color:#12181c;}
  .dbt-cols{display:block;}
  .dbt-card{background:#fff;border:none;padding:0;}
  .dbt-card-head{border-bottom:2px solid #12b05f;}
  .dbt-card-sum b,.dbt-foot b,.dbt-item-sum{color:#b4553f;}
  .dbt-label,.dbt-sub,.dbt-card-sum span,.dbt-month-head span,.dbt-table th,.dbt-table .muted{color:#5b6b73;}
  .dbt-table td{border-bottom:1px solid #eef2f4;}
  .dbt-month-head{border-bottom:1px solid #e3e9ec;}
  .dbt-foot{border-top:2px solid #12b05f;}
  .dbt-month{break-inside:avoid;}
  .dbt-print-note{display:block;margin-top:18px;padding-top:10px;
    border-top:1px solid #e3e9ec;color:#93a1a8;font-size:10px;}
}
</style>
@endsection

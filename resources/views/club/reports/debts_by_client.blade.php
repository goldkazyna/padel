{{-- Задолженности в разрезе клиента.

     Общий отчёт отвечает «сколько нам должны», этот — «за что должен вот
     этот человек»: даты, время, корт. Лист печатают и отдают клиенту,
     поэтому печатная версия — отдельный минималистичный документ: на бумагу
     уходит только карточка, без меню и списка должников. --}}
@extends('layouts.app')

@section('title', 'Задолженности по клиенту')

@section('content')
@php
    // Колонку тренера показываем, только если он вообще где-то есть:
    // столбец из одних прочерков только зашумляет лист.
    $hasCoach = $current
        ? collect($current['bookings'])->contains(fn ($r) => $r['booking']->coach_id)
        : false;
@endphp
<div class="dbt-wrap">

    <div class="dbt-head no-print">
        <a href="{{ route('club.reports.extra.index') }}" class="dbt-back"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1>Задолженности по клиенту</h1>
            <div class="dbt-sub">{{ $club->name }} · неоплаченные брони</div>
        </div>
        <div class="dbt-total-head">
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

        {{-- Документ --}}
        <div class="dbt-detail">
            @if(!$current)
                <div class="dbt-empty dbt-empty-big no-print">
                    <i class="bi bi-person-lines-fill"></i>
                    <p>Выберите клиента слева — покажем, за что он должен</p>
                </div>
            @else
                <article class="rep">
                    <header class="rep-head">
                        <div class="rep-logo">
                            @if($club->logo)
                                <img src="{{ url($club->logo) }}" alt="{{ $club->name }}">
                            @else
                                <span>{{ mb_strtoupper(mb_substr($club->name, 0, 2)) }}</span>
                            @endif
                        </div>
                        <div class="rep-id">
                            <div class="rep-club">{{ $club->name }}</div>
                            <h2 class="rep-title">Задолженность</h2>
                        </div>
                        <div class="rep-sum">
                            <b>{{ number_format($current['total'], 0, '', ' ') }} ₸</b>
                            <span>к оплате</span>
                        </div>
                    </header>

                    <dl class="rep-meta">
                        <div>
                            <dt>Клиент</dt>
                            <dd>{{ $current['name'] }}</dd>
                        </div>
                        @if($current['phone'])
                            <div>
                                <dt>Телефон</dt>
                                <dd>@phoneFmt($current['phone'])</dd>
                            </div>
                        @endif
                        <div>
                            <dt>Броней</dt>
                            <dd>{{ $current['count'] }}</dd>
                        </div>
                        <div>
                            <dt>Период</dt>
                            <dd>
                                @if($from || $to)
                                    {{ $from?->format('d.m.Y') ?? '…' }} — {{ $to?->format('d.m.Y') ?? '…' }}
                                @else
                                    за всё время
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @foreach($months as $month)
                        <section class="rep-month">
                            <div class="rep-month-cap">
                                <span>{{ $month['label'] }}</span>
                                <b>{{ number_format($month['total'], 0, '', ' ') }} ₸</b>
                            </div>
                            <table class="rep-table">
                                <thead>
                                    <tr>
                                        <th class="c-date">Дата</th>
                                        <th class="c-time">Время</th>
                                        <th>Корт</th>
                                        @if($hasCoach)<th>Тренер</th>@endif
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
                                            <td class="c-date">{{ \Carbon\Carbon::parse($b->date)->format('d.m.Y') }}</td>
                                            <td class="c-time">{{ $start }}–{{ $end }}</td>
                                            <td>{{ $b->court->name ?? '—' }}</td>
                                            @if($hasCoach)
                                                <td class="muted">{{ $b->coach ? ($b->coach->first_name ?? $b->coach->name) : '' }}</td>
                                            @endif
                                            <td class="num">{{ number_format($row['amount'], 0, '', ' ') }} ₸</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </section>
                    @endforeach

                    <footer class="rep-foot">
                        <div class="rep-foot-total">
                            <span>Итого к оплате</span>
                            <b>{{ number_format($current['total'], 0, '', ' ') }} ₸</b>
                        </div>
                        <div class="rep-note">
                            {{ $club->name }} · сформировано {{ now()->format('d.m.Y') }}
                        </div>
                    </footer>
                </article>

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
.dbt-total-head{margin-left:auto;text-align:right;}
.dbt-total-head b{display:block;font-size:22px;font-weight:800;color:var(--red);}
.dbt-total-head span{font-size:11px;color:var(--t3);}

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
.dbt-print{margin-top:14px;padding:11px 18px;border-radius:10px;background:var(--accent);
  color:#0a0a0d;border:none;font-weight:700;font-size:14px;cursor:pointer;}
.dbt-empty{color:var(--t3);font-size:13.5px;padding:8px 2px;}
.dbt-empty-big{text-align:center;padding:60px 20px;background:var(--card);
  border:1px dashed var(--line);border-radius:14px;}
.dbt-empty-big i{font-size:28px;display:block;margin-bottom:10px;opacity:.5;}

/* ── Сам документ ────────────────────────────────────────────────────── */
.rep{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:26px 28px;}
.rep-head{display:flex;align-items:center;gap:18px;padding-bottom:20px;}
.rep-logo{width:56px;height:56px;border-radius:14px;background:var(--card2);flex:0 0 auto;
  display:flex;align-items:center;justify-content:center;overflow:hidden;padding:7px;}
.rep-logo img{max-width:100%;max-height:100%;object-fit:contain;display:block;}
.rep-logo span{font-size:17px;font-weight:800;color:var(--accent);}
.rep-id{flex:1;min-width:0;}
.rep-club{font-size:11px;letter-spacing:1.6px;text-transform:uppercase;color:var(--t3);
  font-weight:700;margin-bottom:4px;}
.rep-title{font-size:23px;font-weight:800;margin:0;letter-spacing:-.2px;}
.rep-sum{text-align:right;white-space:nowrap;}
.rep-sum b{display:block;font-size:26px;font-weight:800;color:var(--red);line-height:1.1;}
.rep-sum span{font-size:11px;color:var(--t3);}

.rep-meta{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:0 0 24px;
  padding:14px 0;border-top:1px solid var(--line);border-bottom:1px solid var(--line);}
.rep-meta dt{font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--t3);
  font-weight:700;margin-bottom:3px;}
.rep-meta dd{margin:0;font-size:13.5px;font-weight:600;}

.rep-month{margin-bottom:22px;}
.rep-month-cap{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;}
.rep-month-cap span{font-size:12px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--t2);}
.rep-month-cap b{font-size:13px;font-weight:800;}

.rep-table{width:100%;border-collapse:collapse;font-size:13px;}
.rep-table th{text-align:left;font-size:9.5px;letter-spacing:1px;text-transform:uppercase;
  color:var(--t3);font-weight:700;padding:6px 8px;border-bottom:1px solid var(--line);}
.rep-table td{padding:8px;border-bottom:1px solid rgba(255,255,255,.05);}
.rep-table .num{text-align:right;font-weight:700;white-space:nowrap;font-variant-numeric:tabular-nums;}
.rep-table .c-date{white-space:nowrap;font-variant-numeric:tabular-nums;width:1%;}
.rep-table .c-time{white-space:nowrap;font-variant-numeric:tabular-nums;color:var(--t2);width:1%;}
.rep-table .muted{color:var(--t3);}

.rep-foot{margin-top:4px;}
.rep-foot-total{display:flex;justify-content:space-between;align-items:baseline;
  padding-top:14px;border-top:2px solid var(--accent);font-size:14px;font-weight:600;}
.rep-foot-total b{font-size:22px;font-weight:800;color:var(--red);}
.rep-note{margin-top:10px;font-size:10.5px;color:var(--t3);}

/* ── Печать: только документ, на белом ───────────────────────────────── */
@media print{
  @page{margin:16mm 14mm;}

  /* Макет держит контент в окне: .main-content — это 100dvh со своим
     скроллом. Без роспуска высот в PDF уходил только первый экран. */
  html,body{height:auto !important;overflow:visible !important;background:#fff !important;}
  .main-content{height:auto !important;min-height:0 !important;overflow:visible !important;
    margin-left:0 !important;padding:0 !important;}
  .no-print,.sidebar,.navbar,nav,header.page-header,footer.site-footer,
  .mobile-nav,.mobile-menu-btn,.overlay{display:none !important;}

  .dbt-wrap{max-width:none;padding:0;color:#12181c;overflow:visible;}
  .dbt-cols{display:block;}
  .rep{background:#fff;border:none;border-radius:0;padding:0;}

  .rep-head{padding-bottom:16px;border-bottom:2px solid #12b05f;}
  .rep-logo{background:#f4f7f8;}
  .rep-logo span{color:#12b05f;}
  .rep-club{color:#93a1a8;}
  .rep-title{color:#12181c;}
  .rep-sum b{color:#b4553f;}
  .rep-sum span,.rep-meta dt,.rep-month-cap span,.rep-table th,.rep-table .c-time,
  .rep-table .muted,.rep-note{color:#5b6b73;}
  .rep-meta{border-color:#e3e9ec;}
  .rep-table th{border-bottom:1px solid #e3e9ec;}
  .rep-table td{border-bottom:1px solid #f1f5f6;}
  .rep-foot-total{border-top:2px solid #12b05f;}
  .rep-foot-total b{color:#b4553f;}

  /* Месяц НЕ запрещаем разрывать: в блоке бывает полсотни строк, и запрет
     выталкивал его целиком на следующий лист — первая страница выходила
     пустой. Рвём только между строками, а шапку таблицы повторяем. */
  .rep-month{break-inside:auto;}
  .rep-table thead{display:table-header-group;}
  .rep-table tr{break-inside:avoid;}
  .rep-month-cap{break-after:avoid;}
  .rep-head,.rep-meta,.rep-foot{break-inside:avoid;}
}
</style>
@endsection

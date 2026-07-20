@extends('layouts.app')
@section('title', 'Отчёт расписания')

@section('content')
@php
    $statusMap = [
        'held' => ['Проведено', 's-held'],
        'planned' => ['Запланировано', 's-planned'],
        'cancelled' => ['Отменено', 's-cancelled'],
    ];
    $held = $sessions->where('status', 'held')->count();
    $planned = $sessions->where('status', 'planned')->count();
    $cancelled = $sessions->where('status', 'cancelled')->count();

    // Значения для выпадающих фильтров колонок.
    $rowData = $sessions->map(function ($s) use ($statusMap) {
        $group = $s->courtBooking?->client_name ?: ($s->group?->name ?? '—');
        $coach = $s->coach?->full_name ?: ($s->coach?->name ?? '—');
        $court = $s->court?->name ?: '—';
        $status = ($statusMap[$s->status] ?? [$s->status])[0];
        return compact('group', 'coach', 'court', 'status');
    });
    $groupsList = $rowData->pluck('group')->filter(fn($v) => $v !== '—')->unique()->sort()->values();
    $coachesList = $rowData->pluck('coach')->filter(fn($v) => $v !== '—')->unique()->sort()->values();
    $courtsList = $rowData->pluck('court')->filter(fn($v) => $v !== '—')->unique()->sort()->values();
@endphp
<div class="gr-page">
    <div class="gr-head">
        <h1 class="gr-title">Отчёт расписания <span class="gr-club">— {{ $club->name }}</span></h1>
        <span class="gr-spacer"></span>
        <a href="{{ route('club.groupSessions.index') }}" class="gr-btn gr-ghost">‹ К журналу</a>
    </div>
    <p class="gr-sub">Кто и когда проводил занятия за выбранный период.</p>

    <form method="GET" action="{{ route('club.groupSessions.report') }}" class="gr-filter">
        <label>С <input type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="gr-date"></label>
        <label>по <input type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="gr-date"></label>
        <button type="submit" class="gr-btn gr-green">Показать</button>
        @if($sessions->isNotEmpty())
        <button type="button" id="gr-export" class="gr-btn gr-excel">📥 Выгрузить в Excel</button>
        @endif
        <span class="gr-summary">
            Занятий: <b>{{ $sessions->count() }}</b> ·
            <span class="s-held">проведено {{ $held }}</span> ·
            <span class="s-planned">запланировано {{ $planned }}</span> ·
            <span class="s-cancelled">отменено {{ $cancelled }}</span>
        </span>
    </form>

    @if($sessions->isEmpty())
        <div class="gr-empty">За выбранный период занятий нет.</div>
    @else
    <div class="gr-shown-line">Показано: <b id="gr-shown">{{ $sessions->count() }}</b> из {{ $sessions->count() }}
        <button type="button" id="gr-reset" class="gr-reset">Сбросить фильтры</button>
    </div>
    <div class="gr-table">
        <div class="gr-thead">
            <span>Дата</span><span>Время</span><span>Группа</span><span>Тренер</span><span>Корт</span><span>Статус</span><span>Присут.</span><span>Списано</span>
        </div>
        <div class="gr-filterrow">
            <span></span>
            <span></span>
            <select data-col="group" class="gr-fi"><option value="">Все</option>@foreach($groupsList as $g)<option value="{{ $g }}">{{ $g }}</option>@endforeach</select>
            <select data-col="coach" class="gr-fi"><option value="">Все</option>@foreach($coachesList as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
            <select data-col="court" class="gr-fi"><option value="">Все</option>@foreach($courtsList as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
            <select data-col="status" class="gr-fi"><option value="">Все</option><option value="Проведено">Проведено</option><option value="Запланировано">Запланировано</option><option value="Отменено">Отменено</option></select>
            <span></span>
            <span></span>
        </div>
        @foreach($sessions as $s)
        @php [$stLabel, $stCls] = $statusMap[$s->status] ?? [$s->status, '']; @endphp
        @php
            $groupLabel = $s->courtBooking?->client_name ?: ($s->group?->name ?? '—');
            $actualCoach = $s->coach?->full_name ?: ($s->coach?->name ?? null);
            $assignedCoach = $s->courtBooking?->coach?->full_name ?: ($s->courtBooking?->coach?->name ?? null);
            $isSub = $assignedCoach && $actualCoach && $assignedCoach !== $actualCoach;
            $dateStr = \Carbon\Carbon::parse($s->date)->format('d.m.Y');
            $timeStr = substr($s->start_time,0,5).'–'.substr($s->end_time,0,5);
            $attStr = $s->status === 'held' ? $s->attended_count : '';
            $chgStr = $s->status === 'held' ? $s->charged_count : '';
        @endphp
        <div class="gr-row"
             data-date="{{ $dateStr }}"
             data-time="{{ $timeStr }}"
             data-group="{{ $groupLabel }}"
             data-coach="{{ $actualCoach ?? '—' }}"
             data-court="{{ $s->court?->name ?? '—' }}"
             data-status="{{ $stLabel }}"
             data-att="{{ $attStr }}"
             data-charged="{{ $chgStr }}">
            <span class="gr-date-c">{{ $dateStr }}</span>
            <span>{{ $timeStr }}</span>
            <span class="gr-group">{{ $groupLabel }}</span>
            <span class="gr-coach">
                {{ $actualCoach ?? '—' }}
                @if($isSub)<span class="gr-subc">замена · в брони: {{ $assignedCoach }}</span>@endif
            </span>
            <span class="gr-muted">{{ $s->court?->name }}</span>
            <span><span class="gr-status {{ $stCls }}">{{ $stLabel }}</span></span>
            <span>{{ $s->status === 'held' ? $s->attended_count : '—' }}</span>
            <span>{{ $s->status === 'held' ? $s->charged_count : '—' }}</span>
        </div>
        @endforeach
        <div class="gr-noresult" id="gr-noresult" style="display:none">Ничего не найдено по выбранным фильтрам.</div>
    </div>
    @endif
</div>

<style>
.gr-page{max-width:1200px;margin:0 auto;color:var(--text-primary)}
.gr-head{display:flex;align-items:center;gap:12px;margin-bottom:6px}
.gr-title{font-size:21px;font-weight:800;margin:0;letter-spacing:-.3px}
.gr-club{color:var(--text-muted);font-weight:500}
.gr-spacer{flex:1}
.gr-btn{border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;padding:9px 15px;text-decoration:none;display:inline-flex;align-items:center}
.gr-ghost{background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary)}
.gr-green{background:var(--accent);color:#06210f}
.gr-excel{background:#217346;color:#fff}
.gr-excel:hover{background:#1a5c38}
.gr-sub{color:var(--text-secondary);font-size:13px;margin:2px 0 16px}
.gr-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.gr-filter label{color:var(--text-secondary);font-size:13px;display:inline-flex;align-items:center;gap:6px}
.gr-date{background:var(--bg-card);border:1px solid var(--border);border-radius:9px;color:var(--text-primary);padding:7px 10px;font-size:13px;color-scheme:dark}
.gr-summary{margin-left:auto;color:var(--text-secondary);font-size:13px}
.gr-shown-line{color:var(--text-secondary);font-size:12.5px;margin-bottom:8px;display:flex;align-items:center;gap:12px}
.gr-shown-line b{color:var(--text-primary)}
.gr-reset{background:none;border:1px solid var(--border);color:var(--text-secondary);border-radius:8px;font-size:12px;padding:4px 10px;cursor:pointer}
.gr-reset:hover{border-color:var(--border-light);color:var(--text-primary)}
.gr-table{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.gr-thead,.gr-filterrow,.gr-row{display:grid;grid-template-columns:105px 118px 1fr 175px 100px 130px 82px 82px;gap:12px;align-items:center;padding:11px 16px}
.gr-thead{background:#16161a;border-bottom:1px solid var(--border);color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:.4px;text-align:center}
.gr-filterrow{background:#131317;border-bottom:1px solid var(--border);padding-top:9px;padding-bottom:9px}
.gr-fi{width:100%;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);padding:6px 8px;font-size:12px;text-align:center;color-scheme:dark;font-family:inherit}
.gr-fi:focus{outline:none;border-color:var(--accent)}
select.gr-fi{cursor:pointer}
.gr-row{border-bottom:1px solid var(--border);font-size:13.5px;text-align:center}
.gr-row>span{display:flex;flex-direction:column;align-items:center;justify-content:center}
.gr-row:last-child{border-bottom:none}
.gr-row:hover{background:rgba(255,255,255,.02)}
.gr-date-c{color:var(--text-secondary)}
.gr-group{font-weight:700}
.gr-coach .gr-subc{display:block;color:#f59e0b;font-size:11px;margin-top:2px;font-weight:600}
.gr-muted{color:var(--text-secondary)}
.gr-status{font-size:12px;font-weight:700;padding:3px 9px;border-radius:100px;white-space:nowrap}
.gr-status.s-held{background:rgba(34,197,94,.14);color:#22c55e}
.gr-status.s-planned{background:rgba(245,158,11,.14);color:#f59e0b}
.gr-status.s-cancelled{background:rgba(239,68,68,.14);color:#ef4444}
.s-held{color:#22c55e}.s-planned{color:#f59e0b}.s-cancelled{color:#ef4444}
.gr-empty,.gr-noresult{color:var(--text-secondary);padding:36px;text-align:center}
.gr-empty{background:var(--bg-card);border:1px dashed var(--border-light);border-radius:14px}
@media(max-width:900px){.gr-thead,.gr-filterrow{display:none}.gr-row{grid-template-columns:1fr 1fr;gap:4px;text-align:left}.gr-row>span{align-items:flex-start}}
</style>

<script>
(function(){
    var controls = Array.prototype.slice.call(document.querySelectorAll('.gr-fi'));
    var rows = Array.prototype.slice.call(document.querySelectorAll('.gr-row'));
    var shown = document.getElementById('gr-shown');
    var noResult = document.getElementById('gr-noresult');
    if(!controls.length || !rows.length) return;

    function apply(){
        var count = 0;
        rows.forEach(function(r){
            var ok = true;
            controls.forEach(function(c){
                var v = (c.value || '').trim().toLowerCase();
                if(!v) return;
                var cell = (r.getAttribute('data-' + c.dataset.col) || '').toLowerCase();
                if(c.tagName === 'SELECT'){ if(cell !== v) ok = false; }
                else { if(cell.indexOf(v) === -1) ok = false; }
            });
            r.style.display = ok ? '' : 'none';
            if(ok) count++;
        });
        if(shown) shown.textContent = count;
        if(noResult) noResult.style.display = count ? 'none' : '';
    }

    controls.forEach(function(c){
        c.addEventListener('input', apply);
        c.addEventListener('change', apply);
    });
    var reset = document.getElementById('gr-reset');
    if(reset) reset.addEventListener('click', function(){
        controls.forEach(function(c){ c.value = ''; });
        apply();
    });

    // Выгрузка отфильтрованных строк в Excel (.xls через HTML-таблицу).
    var exportBtn = document.getElementById('gr-export');
    if(exportBtn) exportBtn.addEventListener('click', function(){
        var esc = function(s){ return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); };
        var headers = ['Дата','Время','Группа','Тренер','Корт','Статус','Присутствовало','Списано'];
        var cols = ['date','time','group','coach','court','status','att','charged'];
        var visible = rows.filter(function(r){ return r.style.display !== 'none'; });
        var html = '<table border="1"><thead><tr>';
        headers.forEach(function(h){ html += '<th>' + esc(h) + '</th>'; });
        html += '</tr></thead><tbody>';
        visible.forEach(function(r){
            html += '<tr>';
            cols.forEach(function(c){
                var v = r.getAttribute('data-' + c) || '';
                if((c === 'att' || c === 'charged') && v === '') v = '—';
                html += '<td>' + esc(v) + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table>';

        var full = '﻿<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>' + html + '</body></html>';
        var blob = new Blob([full], { type: 'application/vnd.ms-excel' });
        var fromEl = document.querySelector('input[name="from"]');
        var toEl = document.querySelector('input[name="to"]');
        var fname = 'otchet-raspisaniya';
        if(fromEl && toEl && fromEl.value && toEl.value) fname += '_' + fromEl.value + '_' + toEl.value;
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = fname + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
    });
})();
</script>
@endsection

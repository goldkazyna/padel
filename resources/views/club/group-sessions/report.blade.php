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
    <div class="gr-table">
        <div class="gr-thead">
            <span>Дата</span><span>Время</span><span>Группа</span><span>Тренер</span><span>Корт</span><span>Статус</span><span class="r">Присут.</span><span class="r">Списано</span>
        </div>
        @foreach($sessions as $s)
        @php [$stLabel, $stCls] = $statusMap[$s->status] ?? [$s->status, '']; @endphp
        @php
            $groupLabel = $s->courtBooking?->client_name ?: ($s->group?->name ?? '—');
            $actualCoach = $s->coach?->full_name ?: ($s->coach?->name ?? null);
            $assignedCoach = $s->courtBooking?->coach?->full_name ?: ($s->courtBooking?->coach?->name ?? null);
            $isSub = $assignedCoach && $actualCoach && $assignedCoach !== $actualCoach;
        @endphp
        <div class="gr-row">
            <span class="gr-date-c">{{ \Carbon\Carbon::parse($s->date)->format('d.m.Y') }}</span>
            <span>{{ substr($s->start_time,0,5) }}–{{ substr($s->end_time,0,5) }}</span>
            <span class="gr-group">{{ $groupLabel }}</span>
            <span class="gr-coach">
                {{ $actualCoach ?? '—' }}
                @if($isSub)<span class="gr-sub">замена · в брони: {{ $assignedCoach }}</span>@endif
            </span>
            <span class="gr-muted">{{ $s->court?->name }}</span>
            <span><span class="gr-status {{ $stCls }}">{{ $stLabel }}</span></span>
            <span class="r">{{ $s->status === 'held' ? $s->attended_count : '—' }}</span>
            <span class="r">{{ $s->status === 'held' ? $s->charged_count : '—' }}</span>
        </div>
        @endforeach
    </div>
    @endif
</div>

<style>
.gr-page{max-width:1400px;color:var(--text-primary)}
.gr-head{display:flex;align-items:center;gap:12px;margin-bottom:6px}
.gr-title{font-size:21px;font-weight:800;margin:0;letter-spacing:-.3px}
.gr-club{color:var(--text-muted);font-weight:500}
.gr-spacer{flex:1}
.gr-btn{border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;padding:9px 15px;text-decoration:none;display:inline-flex;align-items:center}
.gr-ghost{background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary)}
.gr-green{background:var(--accent);color:#06210f}
.gr-sub{color:var(--text-secondary);font-size:13px;margin:2px 0 16px}
.gr-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.gr-filter label{color:var(--text-secondary);font-size:13px;display:inline-flex;align-items:center;gap:6px}
.gr-date{background:var(--bg-card);border:1px solid var(--border);border-radius:9px;color:var(--text-primary);padding:7px 10px;font-size:13px;color-scheme:dark}
.gr-summary{margin-left:auto;color:var(--text-secondary);font-size:13px}
.gr-table{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.gr-thead,.gr-row{display:grid;grid-template-columns:110px 120px 1fr 180px 100px 130px 90px 90px;gap:12px;align-items:center;padding:12px 16px}
.gr-thead{background:#16161a;border-bottom:1px solid var(--border);color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:.4px}
.gr-thead .r,.gr-row .r{text-align:right}
.gr-row{border-bottom:1px solid var(--border);font-size:13.5px}
.gr-row:last-child{border-bottom:none}
.gr-row:hover{background:rgba(255,255,255,.02)}
.gr-date-c{color:var(--text-secondary)}
.gr-group{font-weight:700}
.gr-coach .gr-sub{display:block;color:#f59e0b;font-size:11px;margin-top:2px;font-weight:600}
.gr-muted{color:var(--text-secondary)}
.gr-status{font-size:12px;font-weight:700;padding:3px 9px;border-radius:100px;white-space:nowrap}
.gr-status.s-held{background:rgba(34,197,94,.14);color:#22c55e}
.gr-status.s-planned{background:rgba(245,158,11,.14);color:#f59e0b}
.gr-status.s-cancelled{background:rgba(239,68,68,.14);color:#ef4444}
.s-held{color:#22c55e}.s-planned{color:#f59e0b}.s-cancelled{color:#ef4444}
.gr-empty{color:var(--text-secondary);padding:36px;text-align:center;background:var(--bg-card);border:1px dashed var(--border-light);border-radius:14px}
@media(max-width:900px){.gr-thead{display:none}.gr-row{grid-template-columns:1fr 1fr;gap:4px}}
</style>
@endsection

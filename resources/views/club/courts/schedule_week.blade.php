@extends('layouts.app')

@section('title', 'Расписание кортов · Неделя')

@section('content')

<style>
    .ws-page {
        --sch-bg: #0a0a0b;
        --sch-card: #111113;
        --sch-card-alt: #16161a;
        --sch-accent: #22c55e;
        --sch-blue: #3b82f6;
        --sch-text: #f4f4f5;
        --sch-text-dim: #a1a1aa;
        --sch-text-muted: #71717a;
        --sch-border: #27272a;
        --sch-border-light: #1c1c21;
        --sch-red: #ef4444;
        --sch-amber: #fb923c;

        background: var(--sch-bg);
        color: var(--sch-text);
        min-height: 100vh;
        padding: 20px;
    }

    /* === Header === */
    .ws-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 18px;
    }
    .ws-header h1 {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
    }
    .ws-header h1 .club {
        color: var(--sch-text-dim);
        font-weight: 400;
    }
    .ws-settings-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        background: var(--sch-card);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        color: var(--sch-text);
        text-decoration: none;
        font-size: 13px;
    }
    .ws-settings-link:hover { border-color: var(--sch-accent); color: var(--sch-accent); }

    /* === Toolbar === */
    .ws-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
        gap: 16px;
        flex-wrap: wrap;
    }
    .ws-week-nav {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .ws-nav-btn {
        width: 36px;
        height: 36px;
        background: var(--sch-card);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        color: var(--sch-text);
        font-size: 16px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }
    .ws-nav-btn:hover { border-color: var(--sch-accent); color: var(--sch-accent); }
    .ws-today-btn {
        padding: 8px 14px;
        background: var(--sch-card);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        color: var(--sch-text);
        text-decoration: none;
        font-size: 13px;
    }
    .ws-week-range {
        font-size: 18px;
        font-weight: 700;
        letter-spacing: -0.3px;
        margin: 0 8px;
        text-transform: capitalize;
    }
    .ws-view-tabs-wrap {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 4px;
    }
    .ws-view-tabs-label {
        font-size: 11px;
        color: var(--sch-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .ws-view-tabs {
        display: flex;
        gap: 2px;
        background: var(--sch-card);
        border: 1px solid var(--sch-border);
        border-radius: 10px;
        padding: 3px;
    }
    .ws-view-tabs a {
        padding: 7px 14px;
        border-radius: 8px;
        color: var(--sch-text-dim);
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
    }
    .ws-view-tabs a.active {
        background: rgba(34, 197, 94, 0.14);
        color: var(--sch-accent);
    }

    /* === Flash === */
    .ws-flash {
        padding: 12px 16px;
        border-radius: 10px;
        margin-bottom: 16px;
        font-size: 13px;
    }
    .ws-flash-success { background: rgba(34, 197, 94, 0.1); color: var(--sch-accent); border: 1px solid rgba(34, 197, 94, 0.25); }
    .ws-flash-error { background: rgba(239, 68, 68, 0.1); color: var(--sch-red); border: 1px solid rgba(239, 68, 68, 0.25); }

    /* === Grid === */
    .ws-grid {
        display: grid;
        grid-template-columns: 70px repeat(7, 1fr);
        background: var(--sch-card);
        border: 1px solid var(--sch-border);
        border-radius: 14px;
        overflow: hidden;
    }
    .ws-day-head {
        padding: 12px 12px 14px;
        border-left: 1px solid var(--sch-border-light);
        border-bottom: 1px solid var(--sch-border);
        background: var(--sch-card-alt);
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-height: 88px;
    }
    .ws-day-head:first-child { border-left: 0; }
    .ws-day-head .day-name {
        font-size: 11px;
        color: var(--sch-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .ws-day-head .day-num {
        font-size: 16px;
        font-weight: 700;
        text-transform: capitalize;
    }
    .ws-day-head .occupancy-bar {
        height: 3px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 2px;
        overflow: hidden;
        margin-top: auto;
    }
    .ws-day-head .occupancy-fill { height: 100%; border-radius: 2px; }
    .ws-day-head .occupancy-pct { font-size: 11px; font-weight: 600; }
    .ws-day-head.today { background: rgba(34, 197, 94, 0.06); }
    .ws-day-head.today .day-num { color: var(--sch-accent); }
    .ws-day-head.today .day-name { color: var(--sch-accent); }

    .ws-time-col {
        padding: 8px 0 0;
        text-align: center;
        font-size: 11px;
        color: var(--sch-text-muted);
        font-weight: 500;
        border-right: 1px solid var(--sch-border-light);
        border-bottom: 1px solid var(--sch-border-light);
        background: var(--sch-bg);
    }
    .ws-day-cell {
        padding: 4px;
        border-left: 1px solid var(--sch-border-light);
        border-bottom: 1px solid var(--sch-border-light);
        min-height: 92px;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }
    .ws-day-cell.today { background: rgba(34, 197, 94, 0.025); }

    /* === Court mini-card === */
    .ws-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
        padding: 5px 8px;
        border-radius: 7px;
        font-size: 11px;
        line-height: 1.25;
        cursor: pointer;
        min-height: 26px;
        border: 1px solid transparent;
        text-decoration: none;
        transition: filter 0.12s, border-color 0.12s;
    }
    .ws-card .left {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 1px;
    }
    .ws-card .name {
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .ws-card .meta {
        font-size: 10px;
        opacity: 0.75;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .ws-card .court-num {
        font-size: 10px;
        font-weight: 700;
        padding: 1px 6px;
        border-radius: 4px;
        background: rgba(255, 255, 255, 0.08);
        flex-shrink: 0;
        text-transform: uppercase;
    }

    /* Status colors — те же что в дневном виде */
    .ws-card.free {
        background: rgba(34, 197, 94, 0.08);
        border-color: rgba(34, 197, 94, 0.18);
        color: var(--sch-accent);
    }
    .ws-card.free .court-num { background: rgba(34, 197, 94, 0.20); color: var(--sch-accent); }
    .ws-card.free:hover { background: rgba(34, 197, 94, 0.20); border-color: var(--sch-accent); }

    .ws-card.paid {
        background: rgba(59, 130, 246, 0.10);
        border-color: rgba(59, 130, 246, 0.20);
        color: var(--sch-blue);
    }
    .ws-card.paid .court-num { background: rgba(59, 130, 246, 0.22); color: var(--sch-blue); }
    .ws-card.paid:hover { background: rgba(59, 130, 246, 0.18); border-color: var(--sch-blue); }
    .ws-card.paid .name { color: #cdddfb; }

    .ws-card.unpaid {
        background: rgba(251, 146, 60, 0.10);
        border-color: rgba(251, 146, 60, 0.20);
        color: var(--sch-amber);
    }
    .ws-card.unpaid .court-num { background: rgba(251, 146, 60, 0.22); color: var(--sch-amber); }
    .ws-card.unpaid:hover { background: rgba(251, 146, 60, 0.18); border-color: var(--sch-amber); }
    .ws-card.unpaid .name { color: #fbbf24; }

    .ws-card.unprocessed {
        background: rgba(239, 68, 68, 0.12);
        border-color: rgba(239, 68, 68, 0.25);
        color: var(--sch-red);
    }
    .ws-card.unprocessed .court-num { background: rgba(239, 68, 68, 0.25); color: var(--sch-red); }
    .ws-card.unprocessed:hover { background: rgba(239, 68, 68, 0.20); border-color: var(--sch-red); }
    .ws-card.unprocessed .name { color: #fca5a5; }

    .ws-card.blocked {
        background: rgba(113, 113, 122, 0.18);
        border-color: rgba(113, 113, 122, 0.28);
        color: var(--sch-text-dim);
    }
    .ws-card.blocked .court-num { background: rgba(113, 113, 122, 0.30); color: var(--sch-text-dim); }
    .ws-card.blocked:hover { background: rgba(113, 113, 122, 0.28); border-color: var(--sch-text-muted); }

    .ws-card.empty {
        background: rgba(255, 255, 255, 0.02);
        border-color: transparent;
        color: var(--sch-text-muted);
        opacity: 0.4;
        cursor: default;
    }

    /* === Legend === */
    .ws-legend {
        display: flex;
        gap: 18px;
        margin-top: 16px;
        padding: 12px 16px;
        background: var(--sch-card);
        border: 1px solid var(--sch-border);
        border-radius: 12px;
        font-size: 12px;
        color: var(--sch-text-dim);
        flex-wrap: wrap;
    }
    .ws-legend-item {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .ws-legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 3px;
        border: 1px solid;
    }
    .ws-legend-dot.free      { background: rgba(34, 197, 94, 0.20); border-color: rgba(34, 197, 94, 0.40); }
    .ws-legend-dot.paid      { background: rgba(59, 130, 246, 0.22); border-color: rgba(59, 130, 246, 0.45); }
    .ws-legend-dot.unpaid    { background: rgba(251, 146, 60, 0.22); border-color: rgba(251, 146, 60, 0.45); }
    .ws-legend-dot.unprocessed { background: rgba(239, 68, 68, 0.22); border-color: rgba(239, 68, 68, 0.45); }
    .ws-legend-dot.blocked   { background: rgba(113, 113, 122, 0.30); border-color: rgba(113, 113, 122, 0.50); }
</style>

<div class="ws-page">

    <!-- Header -->
    <div class="ws-header">
        <h1>Расписание кортов <span class="club">— {{ $club->name ?? '' }}</span></h1>
        <a href="{{ route('club.courts.index') }}" class="ws-settings-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
            Настройки кортов
        </a>
    </div>

    <!-- Flash -->
    @if(session('success'))<div class="ws-flash ws-flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="ws-flash ws-flash-error">{{ session('error') }}</div>@endif

    <!-- Toolbar -->
    <div class="ws-toolbar">
        <div class="ws-week-nav">
            <a href="{{ route('club.courts.scheduleWeek', ['date' => $prevWeek]) }}" class="ws-nav-btn">‹</a>
            <a href="{{ route('club.courts.scheduleWeek') }}" class="ws-today-btn">Сегодня</a>
            <a href="{{ route('club.courts.scheduleWeek', ['date' => $nextWeek]) }}" class="ws-nav-btn">›</a>
            <span class="ws-week-range">{{ $weekRangeLabel }}</span>
        </div>
        <div class="ws-view-tabs-wrap">
            <span class="ws-view-tabs-label">Отображение</span>
            <div class="ws-view-tabs">
                <a href="{{ route('club.courts.schedule', ['date' => $date]) }}">По кортам</a>
                <a href="{{ route('club.courts.scheduleWeek', ['date' => $date]) }}" class="active">По дням</a>
            </div>
        </div>
    </div>

    <!-- Grid -->
    <div class="ws-grid">
        <!-- Day headers -->
        <div class="ws-day-head"></div>
        @foreach($weekDays as $wd)
            @php
                $occColor = $wd['occupancy'] >= 80 ? '#ef4444' : ($wd['occupancy'] >= 40 ? '#fb923c' : '#22c55e');
            @endphp
            <div class="ws-day-head{{ $wd['isToday'] ? ' today' : '' }}">
                <span class="day-name">{{ $wd['dayName'] }}{{ $wd['isToday'] ? ' · Сегодня' : '' }}</span>
                <a href="{{ route('club.courts.schedule', ['date' => $wd['date']]) }}" style="color: inherit; text-decoration: none;">
                    <span class="day-num">{{ $wd['dayNumLabel'] }}</span>
                </a>
                @if($wd['occupancy'] > 0)
                    <span class="occupancy-pct" style="color: {{ $occColor }};">{{ $wd['occupancy'] }}%</span>
                @endif
                <div class="occupancy-bar">
                    <div class="occupancy-fill" style="width: {{ $wd['occupancy'] }}%; background: {{ $occColor }};"></div>
                </div>
            </div>
        @endforeach

        <!-- Time rows -->
        @foreach($timeSlots as $time)
            <div class="ws-time-col">{{ $time }}</div>
            @foreach($weekDays as $wd)
                <div class="ws-day-cell{{ $wd['isToday'] ? ' today' : '' }}">
                    @foreach($courts as $court)
                        @php
                            $sched = $wd['schedules'][$court->id] ?? [];
                            $slot = $sched[$time] ?? null;
                        @endphp
                        @if(!$slot)
                            <div class="ws-card empty"><div class="left"><span class="name">—</span></div><span class="court-num">{{ $court->name }}</span></div>
                        @elseif($slot['status'] === 'free')
                            @php
                                $maxSlots = $wd['maxFreeSlots'][$court->id . '-' . $time] ?? 1;
                                $bookUrl = route('club.courts.schedule', [
                                    'date' => $wd['date'],
                                    'open' => 'book',
                                    'courtId' => $court->id,
                                    'courtName' => $court->name,
                                    'time' => $time,
                                    'price' => $slot['price'],
                                    'maxSlots' => $maxSlots,
                                ]);
                            @endphp
                            <a href="{{ $bookUrl }}" class="ws-card free">
                                <div class="left">
                                    <span class="name">{{ number_format($slot['price'], 0, '', ' ') }} ₸</span>
                                </div>
                                <span class="court-num">{{ $court->name }}</span>
                            </a>
                        @elseif($slot['status'] === 'booked' && $slot['booking'])
                            @php
                                $b = $slot['booking'];
                                $cls = !$b->is_processed ? 'unprocessed' : ($b->is_paid ? 'paid' : 'unpaid');
                                $viewUrl = route('club.courts.schedule', [
                                    'date' => $wd['date'],
                                    'open' => 'view',
                                    'bookingId' => $b->id,
                                ]);
                            @endphp
                            <a href="{{ $viewUrl }}" class="ws-card {{ $cls }}">
                                <div class="left">
                                    <span class="name">{{ $b->client_name ?? 'Бронь' }}</span>
                                    @if($b->coach_id || $b->comment)
                                        <span class="meta">
                                            @if($b->coach)тренер: {{ $b->coach->first_name }}@endif
                                            @if($b->coach_id && $b->comment) · @endif
                                            @if($b->comment){{ $b->comment }}@endif
                                        </span>
                                    @endif
                                </div>
                                <span class="court-num">{{ $court->name }}</span>
                            </a>
                        @elseif($slot['status'] === 'blocked')
                            @php
                                $unblockUrl = route('club.courts.schedule', [
                                    'date' => $wd['date'],
                                    'open' => 'unblock',
                                    'blockId' => $slot['block']->id ?? 0,
                                ]);
                            @endphp
                            <a href="{{ $unblockUrl }}" class="ws-card blocked">
                                <div class="left">
                                    <span class="name">{{ $slot['block']->comment ?? 'Заблок.' }}</span>
                                </div>
                                <span class="court-num">{{ $court->name }}</span>
                            </a>
                        @else
                            <div class="ws-card empty"><div class="left"><span class="name">—</span></div><span class="court-num">{{ $court->name }}</span></div>
                        @endif
                    @endforeach
                </div>
            @endforeach
        @endforeach
    </div>

    <!-- Legend -->
    <div class="ws-legend">
        <div class="ws-legend-item"><span class="ws-legend-dot free"></span>Свободен</div>
        <div class="ws-legend-item"><span class="ws-legend-dot paid"></span>Оплачено</div>
        <div class="ws-legend-item"><span class="ws-legend-dot unpaid"></span>Не оплачено</div>
        <div class="ws-legend-item"><span class="ws-legend-dot unprocessed"></span>Не обработана</div>
        <div class="ws-legend-item"><span class="ws-legend-dot blocked"></span>Заблокирован</div>
        <div class="ws-legend-item" style="margin-left: auto; color: var(--sch-text-muted);">
            Тап по карточке открывает модалку этого слота
        </div>
    </div>

</div>

@endsection

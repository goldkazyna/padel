@extends('layouts.app')

@section('title', 'Расписание кортов · Неделя')

@section('content')
@php
    // Способ оплаты → [подпись, иконка, цвет] — компактная маркировка в недельном виде
    $paymentMeta = [
        'cash'        => ['Наличные',      'bi-cash-stack',          '#22c55e'],
        'card'        => ['Карта',         'bi-credit-card-2-front', '#3b82f6'],
        'kaspi'       => ['Kaspi',         'bi-qr-code',             '#f14635'],
        'certificate' => ['Сертификат',    'bi-award',               '#a855f7'],
        'club_card'   => ['Клубная карта', 'bi-person-vcard',        '#06b6d4'],
        'deposit'     => ['Депозит',       'bi-wallet2',             '#eab308'],
        'cashback'    => ['Кешбэк',        'bi-arrow-repeat',        '#ec4899'],
        'cashless'    => ['Безналичный',   'bi-bank',                '#14b8a6'],
        'free'        => ['Бесплатно',     'bi-gift',                '#94a3b8'],
    ];
@endphp

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
        grid-template-columns: 70px repeat(7, minmax(0, 1fr));
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

    /* Бейдж необработанных рядом с датой дня */
    .ws-day-head .day-num-row {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .ws-day-head .ws-day-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        background: #ef4444;
        border-radius: 10px;
        color: #fff;
        font-size: 11px;
        font-weight: 800;
        line-height: 1;
        box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.18);
        animation: pulse-red 1.6s infinite;
    }
    @keyframes pulse-red {
        0%, 100% { box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.18); }
        50% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0.08); }
    }

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
    .ws-coach { display: inline-flex; align-items: center; gap: 3px; vertical-align: middle; }
    .ws-coach-avatar { position: relative; width: 16px; height: 16px; border-radius: 50%; background: linear-gradient(135deg, #a78bfa, #7c3aed); display: inline-flex; align-items: center; justify-content: center; font-size: 8px; font-weight: 800; color: #fff; flex-shrink: 0; }
    .ws-coach-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
    .ws-coach-paid { position: absolute; bottom: -1px; right: -1px; width: 7px; height: 7px; border-radius: 50%; border: 1.5px solid #16161a; }
    .ws-coach-paid.paid { background: #22c55e; }
    .ws-coach-paid.unpaid { background: #f59e0b; }
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

    /* Unprocessed button и панель */
    .ws-unprocessed-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(239, 68, 68, 0.35);
        border-radius: 10px;
        color: #fca5a5;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
    }
    .ws-unprocessed-btn:hover { background: rgba(239, 68, 68, 0.20); border-color: #ef4444; color: #ef4444; }
    .ws-unprocessed-btn .dot {
        width: 8px; height: 8px; border-radius: 50%; background: #ef4444;
        animation: pulse-red 1.6s infinite;
    }
    .ws-unprocessed-btn-badge {
        background: #ef4444; color: #fff;
        padding: 2px 8px; border-radius: 10px;
        font-size: 12px; font-weight: 800;
    }

    .unprocessed-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1050;
    }
    .unprocessed-overlay.show { display: block; }
    .unprocessed-panel {
        position: fixed;
        top: 0; right: -460px;
        width: 440px; height: 100vh;
        background: #0a0a0b;
        border-left: 1px solid #27272a;
        z-index: 1051;
        display: flex;
        flex-direction: column;
        transition: right 0.3s ease;
        box-shadow: -8px 0 24px rgba(0, 0, 0, 0.3);
    }
    .unprocessed-panel.show { right: 0; }
    .unprocessed-panel-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 20px 24px; border-bottom: 1px solid #27272a; flex-shrink: 0;
    }
    .unprocessed-panel-header h2 {
        font-size: 18px; font-weight: 700; color: #f4f4f5;
        display: flex; align-items: center; gap: 10px; margin: 0;
    }
    .unprocessed-panel-header h2 i { color: #ef4444; }
    .unprocessed-panel-count {
        background: #ef4444; color: #fff;
        padding: 2px 10px; border-radius: 10px;
        font-size: 13px; font-weight: 800;
    }
    .unprocessed-panel-body {
        flex: 1; overflow-y: auto; padding: 16px;
        display: flex; flex-direction: column; gap: 12px;
    }
    .unprocessed-card {
        background: #111113;
        border: 1px solid rgba(239, 68, 68, 0.25);
        border-radius: 12px;
        padding: 16px;
    }
    .unprocessed-card-top {
        display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;
    }
    .unprocessed-card-name { font-size: 16px; font-weight: 700; color: #f4f4f5; display:block; }
    .unprocessed-card-phone { font-size: 13px; color: #71717a; }
    .unprocessed-card-price { font-size: 16px; font-weight: 800; color: #22c55e; }
    .unprocessed-card-details {
        display: flex; gap: 14px; flex-wrap: wrap;
        font-size: 13px; color: #a1a1aa; margin-bottom: 10px;
    }
    .unprocessed-card-details i { font-size: 12px; color: #71717a; margin-right: 4px; }
    .unprocessed-card-needs-coach {
        background: rgba(168, 156, 245, 0.15); color: #a89cf5;
        padding: 6px 10px; border-radius: 8px; font-size: 12px; font-weight: 600;
        margin-top: 6px; display: inline-flex; align-items: center; gap: 6px;
    }
    .unprocessed-card-comment {
        font-size: 13px; color: #71717a;
        padding: 8px 10px; background: rgba(113, 113, 122, 0.10);
        border-radius: 6px; margin-bottom: 10px;
    }
    .unprocessed-card-actions { display: flex; gap: 8px; }
    .unprocessed-btn-view {
        width: 100%; padding: 10px;
        background: #16161a; border: 1px solid #27272a;
        border-radius: 8px; color: #a1a1aa;
        font-size: 13px; font-weight: 700;
        cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;
    }
    .unprocessed-btn-view:hover { border-color: #22c55e; color: #22c55e; }
    .unprocessed-empty {
        text-align: center; padding: 60px 20px; color: #71717a;
    }
    .unprocessed-empty i { font-size: 40px; color: #22c55e; display: block; margin-bottom: 12px; }
</style>

<div class="ws-page">

    <!-- Header -->
    <div class="ws-header">
        <h1>Расписание кортов <span class="club">— {{ $club->name ?? '' }}</span></h1>
        <div style="display:flex; align-items:center; gap:10px;">
            <button type="button" id="unprocessedPanelBtn" class="ws-unprocessed-btn"
                    onclick="toggleUnprocessedPanel()"
                    style="{{ $unprocessedBookings->count() > 0 ? '' : 'display:none;' }}">
                <span class="dot"></span>
                Необработанные
                <span class="ws-unprocessed-btn-badge" id="unprocessedBtnCount">{{ $unprocessedBookings->count() }}</span>
            </button>
            <a href="{{ route('club.courts.index') }}" class="ws-settings-link">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                Настройки кортов
            </a>
        </div>
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
            <div class="ws-day-head{{ $wd['isToday'] ? ' today' : '' }}" data-date="{{ $wd['date'] }}">
                <span class="day-name">{{ $wd['dayName'] }}{{ $wd['isToday'] ? ' · Сегодня' : '' }}</span>
                <a href="{{ route('club.courts.schedule', ['date' => $wd['date']]) }}" style="color: inherit; text-decoration: none;">
                    <span class="day-num-row">
                        <span class="day-num">{{ $wd['dayNumLabel'] }}</span>
                        <span class="ws-day-badge" data-unprocessed-badge="{{ $wd['date'] }}"
                              title="Необработанных заявок"
                              style="{{ ($wd['unprocessed'] ?? 0) > 0 ? '' : 'display:none;' }}">
                            <span class="badge-count">{{ $wd['unprocessed'] ?? 0 }}</span>
                        </span>
                    </span>
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
                            @php $maxSlots = $wd['maxFreeSlots'][$court->id . '-' . $time] ?? 1; @endphp
                            <div class="ws-card free"
                                 onclick="openBookModal('{{ $court->id }}', '{{ addslashes($court->name) }}', '{{ $time }}', {{ $slot['price'] }}, {{ $maxSlots }}, '{{ $wd['date'] }}')">
                                <div class="left">
                                    <span class="name">{{ number_format($slot['price'], 0, '', ' ') }} ₸</span>
                                </div>
                                <span class="court-num">{{ $court->name }}</span>
                            </div>
                        @elseif($slot['status'] === 'booked' && $slot['booking'])
                            @php
                                $b = $slot['booking'];
                                $cls = !$b->is_processed ? 'unprocessed' : ($b->is_paid ? 'paid' : 'unpaid');
                                if ($b->booking_type) $cls .= ' bt-slot-' . $b->booking_type;
                                $pmW = $paymentMeta[$b->payment_method] ?? null;
                                // У групповой брони нет способа оплаты — показываем тип
                                if (!$pmW && $b->booking_type === 'group') {
                                    $pmW = ['Групповая', 'bi-people-fill', '#fbbf24'];
                                }
                                if ($pmW) $cls .= ' has-pm';
                                $bStart = \Carbon\Carbon::parse($b->start_time)->format('H:i');
                                $bEnd = \Carbon\Carbon::parse($b->end_time)->format('H:i');
                                $coachPhoto = null;
                                if ($b->coach_id) {
                                    $ccW = $clubCoaches->firstWhere('user_id', $b->coach_id);
                                    $coachPhoto = $ccW ? $ccW->photo : null;
                                }
                                // Мультитренер (спарринг): дополнительные тренеры сверх основного.
                                $extraCoachesW = [];
                                if ($b->booking_type !== 'group' && $b->coaches->count() > 1) {
                                    foreach ($b->coaches as $pcW) {
                                        if ((int) $pcW->coach_id === (int) $b->coach_id) continue;
                                        $pcObjW = $clubCoaches->firstWhere('user_id', $pcW->coach_id);
                                        $extraCoachesW[] = ['user' => $pcW->coach, 'photo' => $pcObjW ? $pcObjW->photo : null, 'paid' => $pcW->coach_paid];
                                    }
                                }
                            @endphp
                            <div class="ws-card {{ $cls }}" @if($pmW) style="--pm: {{ $pmW[2] }}" @endif
                                 onclick="openViewModal({{ json_encode([
                                    'id' => $b->id,
                                    'courtId' => $court->id,
                                    'date' => $wd['date'],
                                    'courtName' => $court->name,
                                    'startTime' => $bStart,
                                    'endTime' => $bEnd,
                                    'clientName' => $b->client_name ?? '',
                                    'clientPhone' => $b->client_phone ?? '',
                                    'price' => (float) ($b->price ?? 0),
                                    'paymentMethod' => $b->payment_method ?? '',
                                    'isPaid' => (bool) $b->is_paid,
                                    'isProcessed' => (bool) $b->is_processed,
                                    'comment' => $b->comment ?? '',
                                    'bookingType' => $b->booking_type ?? '',
                                    'groupId' => $bookingGroupIds[$b->id] ?? null,
                                    'coachId' => $b->coach_id,
                                    'coachPaid' => $b->coach_paid === null ? null : (bool) $b->coach_paid,
                                    'coachPrice' => $b->coach_price !== null ? (float) $b->coach_price : null,
                                    'coaches' => $b->coaches->map(fn($pc) => ['coachId' => (int) $pc->coach_id, 'price' => $pc->coach_price !== null ? (float) $pc->coach_price : null, 'paid' => (bool) $pc->coach_paid])->values(),
                                    'discount' => (float) ($b->discount ?? 0),
                                    'clubCardId' => $b->club_card_id,
                                    'cardCharged' => (bool) $b->card_charged_at,
                                    'hasCertificate' => (bool) $b->certificate_id,
                                    'slotDuration' => $court->slot_duration ?? 60,
                                ]) }})">
                                @if($pmW)<div class="ws-pm-strip" title="Оплата: {{ $pmW[0] }}"><i class="bi {{ $pmW[1] }}"></i><span>{{ $pmW[0] }}</span></div>@endif
                                <div class="left">
                                    <span class="name">{{ $b->client_name ?? 'Бронь' }}</span>
                                    @if($b->coach_id || $b->comment)
                                        <span class="meta">
                                            @if($b->coach)<span class="ws-coach"><span class="ws-coach-avatar">@if($coachPhoto)<img src="{{ $coachPhoto }}" alt="">@else{{ mb_strtoupper(mb_substr($b->coach->first_name ?? '?', 0, 1)) }}@endif
                                            @if($b->coach_paid !== null)<span class="ws-coach-paid {{ $b->coach_paid ? 'paid' : 'unpaid' }}" title="{{ $b->coach_paid ? 'Тренер оплачен' : 'Тренер не оплачен' }}"></span>@endif</span>{{ $b->coach->first_name }}</span>@endif
                                            @foreach($extraCoachesW as $ecW)<span class="ws-coach"><span class="ws-coach-avatar">@if($ecW['photo'])<img src="{{ $ecW['photo'] }}" alt="">@else{{ mb_strtoupper(mb_substr(optional($ecW['user'])->first_name ?? '?', 0, 1)) }}@endif @if($ecW['paid'] !== null)<span class="ws-coach-paid {{ $ecW['paid'] ? 'paid' : 'unpaid' }}" title="{{ $ecW['paid'] ? 'Тренер оплачен' : 'Тренер не оплачен' }}"></span>@endif</span>{{ optional($ecW['user'])->first_name }}</span>@endforeach
                                            @if($b->coach_id && $b->comment) · @endif
                                            @if($b->comment){{ $b->comment }}@endif
                                        </span>
                                    @endif
                                </div>
                                @if($b->source === 'app')<i class="bi bi-phone-fill slot-card-icon" title="Заявка из приложения"></i>@endif
                                @if($b->is_paid)<i class="bi bi-patch-check-fill slot-card-icon ws-ic-paid" title="Оплачено"></i>@endif
                                @if(!$pmW && $b->club_card_id)<i class="bi bi-credit-card-2-front slot-card-icon" title="Оплачено клубной картой"></i>@endif
                                <span class="court-num">{{ $court->name }}</span>
                            </div>
                        @elseif($slot['status'] === 'blocked')
                            <div class="ws-card blocked"
                                 onclick="openUnblockModal({{ $slot['block']->id ?? 0 }}, '{{ addslashes($court->name) }}', '{{ $time }}', '{{ addslashes($slot['block']->comment ?? '') }}')">
                                <div class="left">
                                    <span class="name">{{ $slot['block']->comment ?? 'Заблок.' }}</span>
                                </div>
                                <span class="court-num">{{ $court->name }}</span>
                            </div>
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
    </div>

    <div class="ws-legend" style="margin-top:8px;">
        <span style="font-size:12px;font-weight:700;color:var(--sch-text-dim);align-self:center;margin-right:2px;">Способ оплаты:</span>
        @foreach($paymentMeta as $pmItem)
            <div class="ws-legend-item"><span class="ws-legend-dot" style="background: {{ $pmItem[2] }};border-color: {{ $pmItem[2] }};"></span>{{ $pmItem[0] }}</div>
        @endforeach
    </div>

</div>

{{-- =================== Unprocessed Panel (выезжает справа) =================== --}}
<div class="unprocessed-overlay" id="unprocessedOverlay" onclick="toggleUnprocessedPanel()"></div>
<div class="unprocessed-panel" id="unprocessedPanel">
    <div class="unprocessed-panel-header">
        <h2>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12" y2="16"/></svg>
            Необработанные заявки
            <span class="unprocessed-panel-count" id="unprocessedPanelCount">{{ $unprocessedBookings->count() }}</span>
        </h2>
        <button class="sch-modal-close" onclick="toggleUnprocessedPanel()">&#10005;</button>
    </div>
    <div class="unprocessed-panel-body" id="unprocessedPanelBody">
        @forelse($unprocessedBookings as $ub)
            @php
                $ubDate = \Carbon\Carbon::parse($ub->date)->format('Y-m-d');
                $bStart = substr($ub->start_time, 0, 5);
                $bEnd = substr($ub->end_time, 0, 5);
                $viewData = [
                    'id' => $ub->id,
                    'courtId' => $ub->court->id,
                    'date' => $ubDate,
                    'courtName' => $ub->court->name,
                    'startTime' => $bStart,
                    'endTime' => $bEnd,
                    'price' => (float) $ub->price,
                    'discount' => (float) ($ub->discount ?? 0),
                    'clientName' => $ub->client_name,
                    'clientPhone' => $ub->client_phone,
                    'paymentMethod' => $ub->payment_method,
                    'isPaid' => (bool) $ub->is_paid,
                    'isProcessed' => (bool) $ub->is_processed,
                    'comment' => $ub->comment,
                    'bookingType' => $ub->booking_type,
                    'coachId' => $ub->coach_id,
                    'coachPaid' => $ub->coach_paid,
                    'coaches' => $ub->coaches->map(fn($pc) => ['coachId' => (int) $pc->coach_id, 'price' => $pc->coach_price !== null ? (float) $pc->coach_price : null, 'paid' => (bool) $pc->coach_paid])->values(),
                    'cardCharged' => (bool) $ub->card_charged_at,
                    'slotDuration' => $ub->court->slot_duration ?? 60,
                ];
            @endphp
            <div class="unprocessed-card" id="unprocessedCard{{ $ub->id }}">
                <div class="unprocessed-card-top">
                    <div>
                        <span class="unprocessed-card-name">{{ $ub->client_name }}</span>
                        @if($ub->client_phone)<span class="unprocessed-card-phone">@phoneFmt($ub->client_phone)</span>@endif
                    </div>
                    <span class="unprocessed-card-price">{{ number_format($ub->price, 0, '', ' ') }} ₸</span>
                </div>
                <div class="unprocessed-card-details">
                    <span>🎾 {{ $ub->court->name }}</span>
                    <span>📅 {{ \Carbon\Carbon::parse($ub->date)->locale('ru')->isoFormat('D MMM') }}</span>
                    <span>⏰ {{ $bStart }}–{{ $bEnd }}</span>
                </div>
                @if($ub->needs_coach)
                    <div class="unprocessed-card-needs-coach">🎾 Клиент просит тренера</div>
                @endif
                @if($ub->comment)
                    <div class="unprocessed-card-comment">{{ $ub->comment }}</div>
                @endif
                <div class="unprocessed-card-actions">
                    <button type="button" class="unprocessed-btn-view"
                            onclick='toggleUnprocessedPanel(); openViewModal(@json($viewData))'>
                        Просмотреть и обработать →
                    </button>
                </div>
            </div>
        @empty
            <div class="unprocessed-empty">
                <span style="font-size:48px;">✓</span>
                <p>Все заявки обработаны</p>
            </div>
        @endforelse
    </div>
</div>

<script>
    function toggleUnprocessedPanel() {
        document.getElementById('unprocessedPanel').classList.toggle('show');
        document.getElementById('unprocessedOverlay').classList.toggle('show');
    }
</script>

{{-- =================== Modal styles + HTML + JS (взято из дневного view, JS адаптирован под мульти-дату) =================== --}}

<style>
    .modal-wide { max-width: 1000px; }
    #bookModal .modal-content, #viewModal .modal-content, #unblockModal .modal-content { overflow: hidden; }

    /* ===== Бронирование/просмотр — выезжающая справа панель (drawer), как в дневном виде ===== */
    #bookModal .modal-dialog,
    #viewModal .modal-dialog {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        margin: 0;
        width: 94vw;
        max-width: 1600px;
        height: 100vh;
        height: 100dvh;
        align-items: stretch;
    }
    #bookModal .modal-content,
    #viewModal .modal-content {
        height: 100%;
        width: 100%;
        border-radius: 0 !important;
        border-top: none;
        border-right: none;
        border-bottom: none;
        overflow-y: auto;
        overflow-x: hidden;
    }
    #bookModal .sch-modal-header,
    #viewModal .sch-modal-header {
        position: sticky;
        top: 0;
        z-index: 5;
        background: #111113;
    }
    #bookModal.fade .modal-dialog,
    #viewModal.fade .modal-dialog {
        transform: translateX(100%);
        transition: transform 0.3s ease-out;
    }
    #bookModal.show .modal-dialog,
    #viewModal.show .modal-dialog {
        transform: none;
    }
    @media (max-width: 575.98px) {
        #bookModal .modal-dialog,
        #viewModal .modal-dialog {
            width: 100vw;
            max-width: 100vw;
        }
    }

    .modal-two-col { display: grid; grid-template-columns: 1fr 1fr; }
    .modal-col-left { padding: 20px 24px; border-right: 1px solid #27272a; }
    .modal-col-right { padding: 20px 24px; }
    .modal-section-title { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; margin-top: 16px; }

    .autocomplete-wrap { position: relative; }
    .autocomplete-list { display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 50; background: #16161a; border: 1px solid #27272a; border-radius: 10px; margin-top: 4px; max-height: 200px; overflow-y: auto; box-shadow: 0 8px 24px rgba(0,0,0,0.4); }
    .autocomplete-list.show { display: block; }
    .autocomplete-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #27272a; }
    .autocomplete-item:last-child { border-bottom: none; }
    .autocomplete-item:hover { background: rgba(34, 197, 94, 0.10); }
    .autocomplete-item-name { font-size: 14px; font-weight: 600; color: #f4f4f5; }
    .autocomplete-item-phone { font-size: 13px; color: #71717a; }

    .sch-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid #27272a; }
    .sch-modal-header h2 { font-size: 18px; font-weight: 700; color: #f4f4f5; margin: 0; }
    .sch-modal-close { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 8px; cursor: pointer; color: #a1a1aa; font-size: 18px; }
    .sch-modal-close:hover { border-color: #ef4444; color: #ef4444; }
    .sch-modal-body { padding: 24px; }
    .sch-modal-info { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
    .sch-modal-info-row { display: flex; justify-content: space-between; align-items: center; }
    .sch-modal-info-label { font-size: 13px; color: #71717a; font-weight: 600; }
    .sch-modal-info-value { font-size: 14px; font-weight: 700; color: #f4f4f5; }

    .form-group { margin-bottom: 16px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15); }
    .form-input::placeholder { color: #52525b; }

    .duration-selector { display: grid; grid-template-columns: repeat(6, 1fr); gap: 6px; }
    .duration-btn { flex: 1; height: 44px; padding: 0; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 16px; font-weight: 700; cursor: pointer; text-align: center; }
    .duration-btn small { font-size: 10px; font-weight: 600; opacity: 0.7; }
    .duration-btn.active { background: #22c55e; color: #0a0a0b; border-color: #22c55e; }
    .duration-btn:hover:not(.active) { border-color: #22c55e; color: #22c55e; }

    .payment-methods { display: flex; flex-wrap: wrap; gap: 6px; }
    .pay-btn { flex: 1 1 calc(25% - 6px); min-width: 90px; padding: 8px 4px; background: #16161a; border: 1px solid #27272a; border-radius: 8px; color: #a1a1aa; font-size: 12px; font-weight: 600; cursor: pointer; text-align: center; }
    .booking-type-buttons { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 4px; }
    .bt-btn { flex: 1 1 calc(50% - 6px); min-width: 120px; padding: 8px 10px; background: #16161a; border: 1px solid #27272a; border-radius: 8px; color: #a1a1aa; font-size: 12px; font-weight: 600; cursor: pointer; text-align: center; transition: all .2s; }
    .bt-btn:hover:not(.active) { border-color: #3f3f46; color: #f4f4f5; }
    .bt-soft.active { background: rgba(245,158,11,0.18); border-color: #f59e0b; color: #f59e0b; }
    .bt-group.active { background: rgba(59,130,246,0.18); border-color: #3b82f6; color: #3b82f6; }
    .bt-individual.active { background: rgba(229,231,235,0.18); border-color: #e5e7eb; color: #e5e7eb; }
    .bt-tournament.active { background: rgba(167,139,250,0.18); border-color: #a78bfa; color: #a78bfa; }
    .ws-card.bt-slot-soft { background: rgba(245,158,11,0.30) !important; }
    .ws-card.bt-slot-group { background: rgba(59,130,246,0.30) !important; }
    .ws-card.bt-slot-individual { background: rgba(229,231,235,0.22) !important; }
    .ws-card.bt-slot-tournament { background: rgba(167,139,250,0.30) !important; }
    .pay-btn.active { background: #22c55e; color: #0a0a0b; border-color: #22c55e; }
    .pay-btn:hover:not(.active) { border-color: #22c55e; color: #22c55e; }

    .coach-buttons { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
    @media (max-width: 600px) { .coach-buttons { grid-template-columns: 1fr; } }
    .coach-btn { padding: 8px 14px; background: #16161a; border: 1px solid #27272a; border-radius: 8px; color: #a1a1aa; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; text-align: left; justify-content: flex-start; }
    .coach-btn-name { flex: 1; min-width: 0; text-align: left; line-height: 1.25; }
    .coach-btn .coach-rate { color: #22c55e; font-weight: 700; font-size: 12px; flex-shrink: 0; white-space: nowrap; }
    .coach-btn-avatar { width: 22px; height: 22px; border-radius: 50%; overflow: hidden; background: linear-gradient(135deg, #a78bfa, #7c3aed); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; color: #fff; flex-shrink: 0; }
    .coach-btn-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .coach-btn.active { background: #a78bfa; color: #0a0a0b; border-color: #a78bfa; }
    .coach-btn.active .coach-rate { color: #0a0a0b; }
    .coach-btn.unavailable { opacity: 0.3; cursor: not-allowed; text-decoration: line-through; }
    .coach-btn:hover:not(.active):not(.unavailable) { border-color: #a78bfa; color: #a78bfa; }

    /* Выбранные тренеры (мультитренер / спарринг) */
    .sel-coaches { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
    .sel-coaches:empty { margin-top: 0; }
    .sel-coach-row { display: flex; flex-direction: column; gap: 9px; padding: 11px 12px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; }
    .sel-coach-head { display: flex; align-items: center; gap: 10px; }
    .sel-coach-avatar { width: 26px; height: 26px; border-radius: 50%; overflow: hidden; background: linear-gradient(135deg, #a78bfa, #7c3aed); display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; color: #fff; flex-shrink: 0; }
    .sel-coach-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .sel-coach-name { flex: 1; min-width: 90px; font-size: 13.5px; font-weight: 700; color: #e4e4e7; }
    .sel-coach-price { width: 120px; padding: 7px 10px; background: #111113; border: 1px solid #27272a; border-radius: 7px; color: #e4e4e7; text-align: right; font-weight: 700; font-size: 15px; }
    .sel-coach-price::-webkit-outer-spin-button, .sel-coach-price::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .sel-coach-price { -moz-appearance: textfield; appearance: textfield; }
    .sel-coach-paidtoggle { display: flex; gap: 6px; }
    .scp-btn { flex: 1; padding: 8px 10px; background: #111113; border: 1px solid #27272a; border-radius: 8px; color: #a1a1aa; font-size: 12.5px; font-weight: 700; cursor: pointer; text-align: center; transition: all 0.2s; }
    .scp-btn.scp-unpaid.active { background: rgba(251, 146, 60, 0.2); color: #fb923c; border-color: #fb923c; }
    .scp-btn.scp-paid.active { background: #22c55e; color: #0a0a0a; border-color: #22c55e; }

    .paid-toggle { display: flex; gap: 8px; }
    .paid-btn { flex: 1; padding: 10px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 13px; font-weight: 700; cursor: pointer; text-align: center; }
    .paid-btn.active[data-value="0"] { background: rgba(251, 146, 60, 0.20); color: #fb923c; border-color: #fb923c; }
    .paid-btn.active[data-value="1"] { background: #22c55e; color: #0a0a0b; border-color: #22c55e; }

    .processed-btn { flex: 1; padding: 10px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 13px; font-weight: 700; cursor: pointer; text-align: center; }
    .processed-btn.active[data-value="0"] { background: rgba(239, 68, 68, 0.20); color: #ef4444; border-color: #ef4444; }
    .processed-btn.active[data-value="1"] { background: #22c55e; color: #0a0a0b; border-color: #22c55e; }

    .price-edit-row { display: flex; gap: 10px; margin-bottom: 10px; }
    .price-edit-group { flex: 1; }
    .price-input { text-align: right; font-weight: 700; font-size: 15px !important; }

    .total-price { display: flex; flex-direction: column; gap: 8px; padding: 14px 20px; background: rgba(34, 197, 94, 0.10); border: 1px solid rgba(34, 197, 94, 0.20); border-radius: 10px; margin-bottom: 20px; }
    .total-row { display: flex; justify-content: space-between; align-items: center; }
    .total-sub-label { font-size: 13px; color: #a1a1aa; }
    .total-sub-value { font-size: 14px; font-weight: 700; color: #e4e4e7; }
    .total-row-final { padding-top: 8px; margin-top: 2px; border-top: 1px solid rgba(34, 197, 94, 0.25); }
    .total-price-label { font-size: 14px; font-weight: 600; color: #a1a1aa; }
    .total-price-value { font-size: 20px; font-weight: 800; color: #22c55e; }

    .sch-modal-footer { display: flex; gap: 12px; padding: 20px 24px; border-top: 1px solid #27272a; }
    .btn-cancel { flex: 1; padding: 14px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-confirm { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-confirm:hover { background: #16a34a; }
    .btn-danger { padding: 14px; background: #ef4444; border: none; border-radius: 10px; color: #fff; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-danger:hover { background: #dc2626; }
    .btn-block-slot { padding: 10px 20px; background: transparent; border: 1px solid #27272a; border-radius: 10px; color: #71717a; font-size: 13px; font-weight: 600; cursor: pointer; }
    .btn-block-slot:hover { border-color: #71717a; color: #a1a1aa; }

    /* Inline error в форме бронирования */
    .book-form-error {
        display: none;
        align-items: flex-start;
        gap: 10px;
        margin: 0 24px 4px;
        padding: 12px 14px;
        background: rgba(239, 68, 68, 0.08);
        border: 1px solid rgba(239, 68, 68, 0.35);
        border-radius: 10px;
        color: #fca5a5;
        font-size: 13.5px;
        font-weight: 600;
        line-height: 1.4;
    }
    .book-form-error.is-visible {
        display: flex;
        animation: bookFormErrorIn 0.22s ease;
    }
    .book-form-error i {
        font-size: 18px;
        color: #ef4444;
        flex-shrink: 0;
        margin-top: 1px;
    }
    .book-form-error-text { display: block; }
    @keyframes bookFormErrorIn {
        from { opacity: 0; transform: translateY(-4px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .form-input.has-error {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.18) !important;
    }
    .form-hint {
        display: block;
        margin-top: 6px;
        font-size: 11.5px;
        color: rgba(34, 197, 94, 0.85);
        font-weight: 600;
        line-height: 1.4;
    }
    .form-input.is-locked {
        background: rgba(34, 197, 94, 0.06) !important;
        border-color: rgba(34, 197, 94, 0.45) !important;
        color: #d4d4d8 !important;
        cursor: not-allowed;
    }
    input.form-input.is-locked {
        padding-right: 38px;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' fill='%2322c55e' viewBox='0 0 16 16' width='16' height='16'><path d='M8 1a3 3 0 0 0-3 3v3H4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2h-1V4a3 3 0 0 0-3-3zm0 1a2 2 0 0 1 2 2v3H6V4a2 2 0 0 1 2-2z'/></svg>");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }
    textarea.form-input.is-locked {
        padding-right: 32px;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' fill='%2322c55e' viewBox='0 0 16 16' width='14' height='14'><path d='M8 1a3 3 0 0 0-3 3v3H4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2h-1V4a3 3 0 0 0-3-3zm0 1a2 2 0 0 1 2 2v3H6V4a2 2 0 0 1 2-2z'/></svg>");
        background-repeat: no-repeat;
        background-position: top 10px right 10px;
    }
    .payment-methods.has-error,
    .paid-toggle.has-error,
    .client-card-buttons.has-error {
        position: relative;
        animation: shake 0.4s ease;
    }
    .payment-methods.has-error::before,
    .paid-toggle.has-error::before,
    .client-card-buttons.has-error::before {
        content: '';
        position: absolute;
        inset: -6px;
        border-radius: 12px;
        box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.55);
        pointer-events: none;
    }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25%      { transform: translateX(-4px); }
        75%      { transform: translateX(4px); }
    }

    /* Участники группы (идентично дневному виду) */
    .group-members-block {
        margin-bottom: 16px;
        padding: 12px 14px;
        background: rgba(255,255,255,0.02);
        border: 1px solid #27272a;
        border-radius: 10px;
    }
    .gm-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .gm-title { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; }
    .gm-count { font-size: 12px; color: #a1a1aa; background: rgba(255,255,255,0.05); padding: 2px 8px; border-radius: 999px; }
    .gm-list { list-style: none; margin: 0; padding: 0; }
    .gm-item { display: flex; align-items: center; flex-wrap: wrap; justify-content: space-between; gap: 6px 12px; padding: 8px 0; border-top: 1px solid rgba(255,255,255,0.05); }
    .gm-item:first-child { border-top: none; }
    .gm-name { font-size: 13px; color: #f4f4f5; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .gm-rem { font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px; white-space: nowrap; flex-shrink: 0; }
    .gm-rem-ok { background: rgba(34,197,94,0.12); color: #4ade80; }
    .gm-rem-low { background: rgba(234,179,8,0.12); color: #facc15; }
    .gm-rem-zero { background: rgba(239,68,68,0.12); color: #f87171; }
    .gm-frozen { margin-right: auto; display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: #38bdf8; background: rgba(56,189,248,0.12); border: 1px solid rgba(56,189,248,0.3); border-radius: 999px; padding: 2px 9px; white-space: nowrap; }
    .gm-item-frozen .gm-name { color: #94a3b8; }
    .gm-empty { font-size: 13px; color: #a1a1aa; text-align: center; padding: 4px 0; }

    /* Кнопки клубных карт клиента — хардкод-цвета (модалка вне .ws-page, var(--sch-*) недоступны) */
    .client-card-buttons { display: flex; flex-wrap: wrap; gap: 8px; }
    .client-card-btn {
        display: flex; flex-direction: column; align-items: flex-start; gap: 2px;
        padding: 9px 14px; background: #16161a; border: 1px solid #27272a;
        border-radius: 10px; cursor: pointer; transition: all 0.15s; min-width: 150px;
    }
    .client-card-btn .ccb-name { color: #f4f4f5; font-weight: 700; font-size: 13px; }
    .client-card-btn .ccb-code { color: #a1a1aa; font-size: 11px; font-family: monospace; letter-spacing: 1px; }
    .client-card-btn .ccb-sub { color: #22c55e; font-size: 12px; font-weight: 600; }
    .client-card-btn:hover:not(.active) { border-color: #22c55e; }
    .client-card-btn.active { border-color: #22c55e; background: rgba(34,197,94,.14); box-shadow: 0 0 0 1px #22c55e inset; }
    .client-card-btn.inactive { cursor: not-allowed; opacity: .55; filter: grayscale(.4); }
    .client-card-btn.inactive .ccb-sub { color: #a1a1aa; }

    /* Монохромная иконка: бронь оплачена клубной картой — перед надписью корта */
    .slot-card-icon { color: #a1a1aa; font-size: 11px; opacity: .9; margin-right: 6px; vertical-align: middle; }
    .slot-card-icon.ws-ic-paid { color: #22c55e; opacity: 1; }
    /* Полоса способа оплаты сверху карточки (как в дневном виде) */
    .ws-card { position: relative; }
    .ws-card.has-pm { padding-top: 17px; }
    .ws-pm-strip {
        position: absolute; top: 0; left: 0; right: 0; z-index: 1; pointer-events: none;
        display: flex; align-items: center; gap: 3px;
        height: 14px; padding: 0 6px;
        font-size: 8px; font-weight: 800; letter-spacing: 0.2px; text-transform: uppercase;
        color: var(--pm);
        background: color-mix(in srgb, var(--pm) 24%, #16161a);
        border-radius: 6px 6px 0 0;
        white-space: nowrap; overflow: hidden;
    }
    .ws-pm-strip i { font-size: 9px; flex-shrink: 0; }
    .ws-pm-strip span { overflow: hidden; text-overflow: ellipsis; }
</style>

<!-- Book Modal -->
<div class="modal fade" id="bookModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-wide">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="sch-modal-header">
                <h2>Бронирование</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <form id="bookForm" method="POST">
                @csrf
                <input type="hidden" name="date" id="bookDate" value="">
                <input type="hidden" name="start_time" id="bookStartTime">
                <input type="hidden" name="slots" id="bookSlots" value="1">

                <div class="modal-two-col">
                    <div class="modal-col-left">
                        <div class="sch-modal-info">
                            <div class="sch-modal-info-row"><span class="sch-modal-info-label">Корт</span><span class="sch-modal-info-value" id="bookCourtName"></span></div>
                            <div class="sch-modal-info-row"><span class="sch-modal-info-label">Дата</span><span class="sch-modal-info-value" id="bookDateLabel"></span></div>
                            <div class="sch-modal-info-row"><span class="sch-modal-info-label">Начало</span><span class="sch-modal-info-value" id="bookTime"></span></div>
                            <div class="sch-modal-info-row"><span class="sch-modal-info-label">Цена/час</span><span class="sch-modal-info-value" id="bookPrice"></span></div>
                        </div>

                        <div class="modal-section-title">Длительность</div>
                        <div class="duration-selector" id="durationSelector"></div>

                        <div class="modal-section-title">Цена и скидка</div>
                        <div class="price-edit-row">
                            <div class="price-edit-group">
                                <label class="form-label">Цена корта</label>
                                <input type="number" name="custom_price" id="bookCustomPrice" class="form-input price-input" min="0" step="100" onchange="updateFinalPrice()" oninput="updateFinalPrice()">
                            </div>
                            <div class="price-edit-group">
                                <label class="form-label">Скидка</label>
                                <input type="number" name="discount" id="bookDiscount" class="form-input price-input" min="0" step="100" value="0" onchange="updateFinalPrice()" oninput="updateFinalPrice()">
                            </div>
                        </div>
                        <div class="total-price">
                            <div class="total-row">
                                <span class="total-sub-label">Цена за корт</span>
                                <span class="total-sub-value" id="bookCourtPrice"></span>
                            </div>
                            <div class="total-row" id="bookCoachTotalRow" style="display:none;">
                                <span class="total-sub-label">Цена за тренера</span>
                                <span class="total-sub-value" id="bookCoachTotal"></span>
                            </div>
                            <div class="total-row total-row-final">
                                <span class="total-price-label">Итого</span>
                                <span class="total-price-value" id="bookTotalPrice"></span>
                            </div>
                        </div>

                        <div class="modal-section-title">Тренер</div>
                        <div class="coach-buttons" id="bookCoachButtons">
                            @foreach($clubCoaches as $cc)
                                <button type="button" class="coach-btn" data-coach-id="{{ $cc->user_id }}" data-rate="{{ $cc->hourly_rate ?? 0 }}" onclick="selectBookCoach(this)">
                                    <span class="coach-btn-avatar">@if($cc->photo)<img src="{{ $cc->photo }}" alt="">@else{{ mb_strtoupper(mb_substr($cc->user->first_name ?? $cc->user->name ?? '?', 0, 1)) }}@endif</span>
                                    <span class="coach-btn-name">{{ $cc->user->full_name }}</span>
                                    @if($cc->hourly_rate)<span class="coach-rate">{{ number_format($cc->hourly_rate, 0, '', ' ') }} ₸</span>@endif
                                </button>
                            @endforeach
                        </div>
                        <input type="hidden" name="coach_id" id="bookCoachId" value="">
                        <div id="bookSelectedCoaches" class="sel-coaches js-hide-for-group"></div>

                        <div class="form-group" id="bookCoachPriceGroup" style="display:none; margin-top:14px;">
                            <label class="form-label">Цена тренера, ₸</label>
                            <input type="number" name="coach_price" id="bookCoachPrice" class="form-input" min="0" step="100" placeholder="0">
                            <small class="form-hint" style="color:#71717a;">По умолчанию — ставка тренера, можно изменить</small>
                        </div>

                        <div class="form-group" id="bookCoachPaidGroup" style="display:none; margin-top:14px;">
                            <label class="form-label">Оплата тренера</label>
                            <input type="hidden" name="coach_paid" id="bookCoachPaidInput" value="">
                            <div class="paid-toggle">
                                <button type="button" class="paid-btn" data-value="0" onclick="setBookCoachPaid(this)">Не оплачен</button>
                                <button type="button" class="paid-btn" data-value="1" onclick="setBookCoachPaid(this)">Оплачен</button>
                            </div>
                        </div>
                    </div>

                    <div class="modal-col-right">
                        <div class="form-group autocomplete-wrap js-hide-for-group">
                            <label class="form-label">Напишите имя и фамилию клиента *</label>
                            <input type="text" name="client_name" id="bookClientName" class="form-input" placeholder="Например: Денис Дудников" required autocomplete="off">
                            <div class="autocomplete-list" id="bookNameList"></div>
                            <small class="form-hint" id="bookClientNameHint" style="display:none;">Имя из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».</small>
                        </div>
                        <div class="form-group autocomplete-wrap js-hide-for-group">
                            <label class="form-label">Телефон *</label>
                            <input type="text" name="client_phone" id="bookClientPhone" class="form-input" placeholder="+7 (___) ___-__-__" required autocomplete="off">
                            <div class="autocomplete-list" id="bookPhoneList"></div>
                        </div>

                        <div class="form-group js-hide-for-group">
                            <label class="form-label">Заметка о клиенте</label>
                            <textarea name="client_note" id="bookClientNote" class="form-input" rows="2" placeholder="Например: ВИП, играет с тренером, оплачивает картой"></textarea>
                            <small class="form-hint" id="bookClientNoteHint" style="display:none;">Заметка из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».</small>
                        </div>

                        <div class="form-group" id="bookCardWrap" style="display:none;">
                            <label class="form-label">Клубная карта клиента</label>
                            <input type="hidden" name="club_card_id" id="bookCardInput" value="">
                            <div class="client-card-buttons" id="bookCardButtons"></div>
                            <small class="form-hint" id="bookCardHint" style="display:none;"></small>
                        </div>

                        <div class="form-group" id="bookCertWrap" style="display:none;">
                            <label class="form-label">Сертификаты клиента</label>
                            <input type="hidden" name="certificate_id" id="bookCertInput" value="">
                            <div class="client-card-buttons" id="bookCertButtons"></div>
                            <small class="form-hint" id="bookCertHint" style="display:none;"></small>
                        </div>

                        <div class="modal-section-title">Тип брони</div>
                        <div class="booking-type-buttons" id="bookingTypeButtons">
                            <button type="button" class="bt-btn bt-soft" data-value="soft" onclick="selectBookingType(this)"><i class="bi bi-clock-history"></i><span>Мягкая бронь</span></button>
                            <button type="button" class="bt-btn bt-group" data-value="group" onclick="selectBookingType(this)"><i class="bi bi-people"></i><span>Групповые</span></button>
                            <button type="button" class="bt-btn bt-individual" data-value="individual" onclick="selectBookingType(this)"><i class="bi bi-person"></i><span>Индивидуальные</span></button>
                            <button type="button" class="bt-btn bt-tournament" data-value="tournament" onclick="selectBookingType(this)"><i class="bi bi-trophy"></i><span>Турнир</span></button>
                        </div>
                        <input type="hidden" name="booking_type" id="bookingTypeInput">

                        @if(isset($activeGroups) && $activeGroups->count())
                            <div id="bookGroupSelectWrap" style="display:none;">
                                <div class="modal-section-title">Группа (создаст занятие в журнале)</div>
                                <div class="form-group">
                                    <select name="group_id" id="bookGroupSelect" class="form-input" onchange="renderGroupMembers(this.value)">
                                        <option value="">— без привязки к группе —</option>
                                        @foreach($activeGroups as $g)
                                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div id="gmPrice" style="display:none;margin:8px 0;color:#a1a1aa;font-size:13px;"></div>
                                <div id="groupMembersBlock" class="group-members-block" style="display:none;">
                                    <div class="gm-header">
                                        <span class="gm-title">Участники</span>
                                        <span class="gm-count" id="gmCount"></span>
                                    </div>
                                    <ul id="gmList" class="gm-list"></ul>
                                    <div id="gmEmpty" class="gm-empty" style="display:none;">В группе пока нет участников</div>
                                    <a id="gmScheduleLink" href="#" target="_blank" rel="noopener"
                                       style="display:none;align-items:center;justify-content:center;gap:8px;margin-top:10px;padding:10px 12px;border-radius:10px;background:rgba(34,197,94,0.10);border:1px solid rgba(34,197,94,0.35);color:#34d17f;font-size:13px;font-weight:700;text-decoration:none;">
                                        <i class="bi bi-calendar3"></i> Всё расписание группы
                                    </a>
                                </div>
                                <div id="groupCoachBlock" class="form-group" style="display:none;">
                                    <label for="bookGroupCoachSelect" class="form-label">Тренер</label>
                                    <select id="bookGroupCoachSelect" class="form-input" onchange="document.getElementById('bookCoachId').value = this.value;">
                                        <option value="">— без тренера —</option>
                                        @foreach($clubCoaches as $cc)
                                            <option value="{{ $cc->user_id }}">{{ $cc->user->full_name }}</option>
                                        @endforeach
                                    </select>
                                    <small class="form-hint" id="bookGroupCoachHint" style="display:none;color:#a1a1aa;font-size:12px;margin-top:6px;">По умолчанию — тренер группы</small>
                                </div>
                            </div>
                            @php
                                $groupMembersData = $activeGroups->mapWithKeys(function ($g) {
                                    return [$g->id => [
                                        'coach_id' => $g->coach_id,
                                        'price' => (float) $g->price_per_session,
                                        'members' => $g->members->map(function ($m) {
                                            $bought = (int) $m->enrollments->sum('sessions');
                                            $used = (int) $m->attendance->where('charged', true)->count();
                                            return [
                                                'name' => optional($m->client)->name ?? '—',
                                                'remaining' => $bought - $used,
                                                // Диапазоны заморозок — флаг считаем в JS на дату выбранного слота.
                                                'freezes' => $m->freezes->map(fn($f) => [
                                                    'from' => $f->freeze_from->toDateString(),
                                                    'until' => $f->freeze_until->toDateString(),
                                                ])->values(),
                                                // Дата начала — флаг «ещё не начал» считаем в JS на дату слота.
                                                'starts_at' => $m->starts_at ? $m->starts_at->toDateString() : null,
                                                'note' => $m->note,
                                            ];
                                        })->values(),
                                    ]];
                                })->toArray();
                            @endphp
                            <script>window.__groupMembers = @json($groupMembersData);</script>
                        @endif
                        <script>window.__coachNames = @json($clubCoaches->mapWithKeys(fn($cc) => [$cc->user_id => ($cc->user->full_name ?? '')])->toArray());</script>

                        <div class="modal-section-title js-hide-for-group">Способ оплаты</div>
                        <div class="payment-methods js-hide-for-group" id="paymentMethods">
                            <button type="button" class="pay-btn" data-value="cash" onclick="selectPayment(this)"><i class="bi bi-cash-stack"></i><span>Наличные</span></button>
                            <button type="button" class="pay-btn" data-value="card" onclick="selectPayment(this)"><i class="bi bi-credit-card-2-front"></i><span>Карта</span></button>
                            <button type="button" class="pay-btn" data-value="kaspi" onclick="selectPayment(this)"><i class="bi bi-qr-code"></i><span>Kaspi</span></button>
                            <button type="button" class="pay-btn" data-value="certificate" onclick="selectPayment(this)"><i class="bi bi-award"></i><span>Сертификат</span></button>
                            <button type="button" class="pay-btn" data-value="club_card" onclick="selectPayment(this)"><i class="bi bi-person-vcard"></i><span>Клубная карта</span></button>
                            <button type="button" class="pay-btn" data-value="deposit" onclick="selectPayment(this)"><i class="bi bi-wallet2"></i><span>Депозит</span></button>
                            <button type="button" class="pay-btn" data-value="cashback" onclick="selectPayment(this)"><i class="bi bi-arrow-repeat"></i><span>Кешбэк</span></button>
                            <button type="button" class="pay-btn" data-value="cashless" onclick="selectPayment(this)"><i class="bi bi-bank"></i><span>Безналичный</span></button>
                            <button type="button" class="pay-btn" data-value="free" onclick="selectPayment(this)"><i class="bi bi-gift"></i><span>Бесплатно</span></button>
                        </div>
                        <input type="hidden" name="payment_method" id="paymentMethodInput">

                        <div class="form-group js-hide-for-group" style="margin-top: 14px;">
                            <label class="form-label">Статус оплаты *</label>
                            <input type="hidden" name="is_paid" id="isPaidInput" value="">
                            <div class="paid-toggle">
                                <button type="button" class="paid-btn" data-value="0" onclick="setPaid(this)">Не оплачено</button>
                                <button type="button" class="paid-btn" data-value="1" onclick="setPaid(this)">Оплачено</button>
                            </div>
                        </div>

                        <div id="groupBookingHint" class="form-group js-show-for-group" style="display:none;">
                            <div style="padding:12px 14px;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.25);border-radius:10px;color:#a1a1aa;font-size:13px;line-height:1.5;">
                                Для групповой брони данные о клиенте и оплате не требуются — занятие добавится в «Журнал занятий», оплата идёт через пакеты участников группы.
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Комментарий</label>
                            <textarea name="comment" class="form-input" rows="2" placeholder="Заметка к бронированию"></textarea>
                        </div>

                        <div style="text-align: center; margin-top: 16px;">
                            <button type="button" class="btn-block-slot" onclick="blockSlot()">Заблокировать слот</button>
                        </div>
                    </div>
                </div>

                @include('club.courts.partials._book_repeat')

                <div class="book-form-error" id="bookFormError" role="alert" aria-live="polite">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span class="book-form-error-text"></span>
                </div>

                <div class="sch-modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn-confirm">Забронировать</button>
                </div>
            </form>

            <form id="blockForm" method="POST" style="display:none;">
                @csrf
                <input type="hidden" name="date" id="blockDate" value="">
                <input type="hidden" name="start_time" id="blockStartTime">
                <input type="hidden" name="end_time" id="blockEndTime">
                <input type="hidden" name="comment" id="newBlockComment">
            </form>
        </div>
    </div>
</div>

<!-- Edit Booking Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-wide">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="sch-modal-header">
                <h2>Редактирование брони</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <form id="editBookingForm" method="POST">
                @csrf
                @method('PUT')

                <div class="modal-two-col">
                    <div class="modal-col-left">
                        <div class="sch-modal-info">
                            <div class="sch-modal-info-row"><span class="sch-modal-info-label">Корт</span><span class="sch-modal-info-value" id="viewCourtName"></span></div>
                            <div class="sch-modal-info-row"><span class="sch-modal-info-label">Дата</span><span class="sch-modal-info-value" id="viewDate"></span></div>
                            <div class="sch-modal-info-row"><span class="sch-modal-info-label">Начало</span><span class="sch-modal-info-value" id="viewTime"></span></div>
                            <div class="sch-modal-info-row"><span class="sch-modal-info-label">Цена/час</span><span class="sch-modal-info-value" id="viewPrice"></span></div>
                        </div>

                        <div class="modal-section-title">Длительность</div>
                        <input type="hidden" name="slots" id="editSlots" value="">
                        <div class="duration-selector" id="editDurationSelector"></div>

                        <div class="modal-section-title">Цена и скидка</div>
                        <div class="price-edit-row">
                            <div class="price-edit-group">
                                <label class="form-label">Цена</label>
                                <input type="number" name="custom_price" id="editCustomPrice" class="form-input price-input" min="0" step="100" onchange="updateEditFinalPrice()" oninput="updateEditFinalPrice()">
                            </div>
                            <div class="price-edit-group">
                                <label class="form-label">Скидка</label>
                                <input type="number" name="discount" id="editDiscount" class="form-input price-input" min="0" step="100" value="0" onchange="updateEditFinalPrice()" oninput="updateEditFinalPrice()">
                            </div>
                        </div>
                        <div class="total-price">
                            <div class="total-row">
                                <span class="total-sub-label">Цена за корт</span>
                                <span class="total-sub-value" id="editCourtPrice"></span>
                            </div>
                            <div class="total-row" id="editCoachTotalRow" style="display:none;">
                                <span class="total-sub-label">Цена за тренера</span>
                                <span class="total-sub-value" id="editCoachTotal"></span>
                            </div>
                            <div class="total-row total-row-final">
                                <span class="total-price-label">Итого</span>
                                <span class="total-price-value" id="editTotalPrice"></span>
                            </div>
                        </div>

                        <div class="form-group" id="editProcessedGroup" style="display:none;">
                            <label class="form-label">Статус обработки</label>
                            <input type="hidden" name="is_processed" id="editIsProcessedInput" value="1">
                            <div class="paid-toggle">
                                <button type="button" class="processed-btn" data-value="0" onclick="setEditProcessed(this)">Не обработан</button>
                                <button type="button" class="processed-btn" data-value="1" onclick="setEditProcessed(this)">Обработан</button>
                            </div>
                        </div>

                        <div class="modal-section-title">Тренер</div>
                        <div class="coach-buttons" id="editCoachButtons">
                            @foreach($clubCoaches as $cc)
                                <button type="button" class="coach-btn" data-coach-id="{{ $cc->user_id }}" data-rate="{{ $cc->hourly_rate ?? 0 }}" onclick="selectEditCoach(this)">
                                    <span class="coach-btn-avatar">@if($cc->photo)<img src="{{ $cc->photo }}" alt="">@else{{ mb_strtoupper(mb_substr($cc->user->first_name ?? $cc->user->name ?? '?', 0, 1)) }}@endif</span>
                                    <span class="coach-btn-name">{{ $cc->user->full_name }}</span>
                                    @if($cc->hourly_rate)<span class="coach-rate">{{ number_format($cc->hourly_rate, 0, '', ' ') }} ₸</span>@endif
                                </button>
                            @endforeach
                        </div>
                        <input type="hidden" name="coach_id" id="editCoachId" value="">
                        <div id="editSelectedCoaches" class="sel-coaches"></div>

                        <div class="form-group" id="editCoachPriceGroup" style="display:none; margin-top:14px;">
                            <label class="form-label">Цена тренера, ₸</label>
                            <input type="number" name="coach_price" id="editCoachPrice" class="form-input" min="0" step="100" placeholder="0">
                            <small class="form-hint" style="color:#71717a;">По умолчанию — ставка тренера, можно изменить</small>
                        </div>

                        <div class="form-group" id="editCoachPaidGroup" style="display:none; margin-top:14px;">
                            <label class="form-label">Оплата тренера</label>
                            <input type="hidden" name="coach_paid" id="editCoachPaidInput" value="">
                            <div class="paid-toggle">
                                <button type="button" class="paid-btn" data-value="0" onclick="setEditCoachPaid(this)">Не оплачен</button>
                                <button type="button" class="paid-btn" data-value="1" onclick="setEditCoachPaid(this)">Оплачен</button>
                            </div>
                        </div>
                    </div>

                    <div class="modal-col-right">
                        <div class="form-group autocomplete-wrap">
                            <label class="form-label" id="editClientLabel">Напишите имя и фамилию клиента *</label>
                            <input type="text" name="client_name" id="editClientName" class="form-input" placeholder="Например: Денис Дудников" autocomplete="off" required>
                            <div class="autocomplete-list" id="editNameList"></div>
                            <small class="form-hint" id="editClientNameHint" style="display:none;">Имя из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».</small>
                        </div>
                        <!-- Группа: тренер + участники (только для групповой брони) -->
                        <div id="editGroupBlock" style="display:none;">
                            <div id="editGmPrice" style="display:none;margin:4px 0 12px;color:#a1a1aa;font-size:13px;"></div>
                            <div class="modal-section-title">Тренер</div>
                            <div class="form-input" id="editGroupCoach" style="background:#18181b;">—</div>
                            <div class="group-members-block" style="margin-top:14px;">
                                <div class="gm-header">
                                    <span class="gm-title">Участники</span>
                                    <span class="gm-count" id="editGmCount"></span>
                                </div>
                                <ul id="editGmList" class="gm-list"></ul>
                                <div id="editGmEmpty" class="gm-empty" style="display:none;">В группе пока нет участников</div>
                                <a id="editGmScheduleLink" href="#" target="_blank" rel="noopener"
                                   style="display:none;align-items:center;justify-content:center;gap:8px;margin-top:10px;padding:10px 12px;border-radius:10px;background:rgba(34,197,94,0.10);border:1px solid rgba(34,197,94,0.35);color:#34d17f;font-size:13px;font-weight:700;text-decoration:none;">
                                    <i class="bi bi-calendar3"></i> Всё расписание группы
                                </a>
                            </div>
                            <div style="margin-top:14px;padding:12px 14px;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.25);border-radius:10px;color:#a1a1aa;font-size:13px;line-height:1.5;">
                                Для групповой брони данные о клиенте и оплате не требуются — занятие добавится в «Журнал занятий», оплата идёт через пакеты участников группы.
                            </div>
                        </div>

                        <div class="form-group autocomplete-wrap js-edit-hide-for-group">
                            <label class="form-label">Телефон *</label>
                            <input type="text" name="client_phone" id="editClientPhone" class="form-input" placeholder="+7 (___) ___-__-__" autocomplete="off" required>
                            <div class="autocomplete-list" id="editPhoneList"></div>
                        </div>

                        <div class="form-group js-edit-hide-for-group">
                            <label class="form-label">Заметка о клиенте</label>
                            <textarea name="client_note" id="editClientNote" class="form-input" rows="2" placeholder="Например: ВИП, играет с тренером, оплачивает картой"></textarea>
                            <small class="form-hint" id="editClientNoteHint" style="display:none;">Заметка из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».</small>
                        </div>

                        <div class="form-group" id="editCardWrap" style="display:none;">
                            <label class="form-label">Клубная карта клиента</label>
                            <input type="hidden" name="club_card_id" id="editCardInput" value="">
                            <div class="client-card-buttons" id="editCardButtons"></div>
                            <small class="form-hint" id="editCardHint" style="display:none;"></small>
                        </div>

                        <div class="modal-section-title">Тип брони</div>
                        <div class="booking-type-buttons" id="editBookingTypeButtons">
                            <button type="button" class="bt-btn bt-soft" data-value="soft" onclick="selectEditBookingType(this)"><i class="bi bi-clock-history"></i><span>Мягкая бронь</span></button>
                            <button type="button" class="bt-btn bt-group" data-value="group" onclick="selectEditBookingType(this)"><i class="bi bi-people"></i><span>Групповые</span></button>
                            <button type="button" class="bt-btn bt-individual" data-value="individual" onclick="selectEditBookingType(this)"><i class="bi bi-person"></i><span>Индивидуальные</span></button>
                            <button type="button" class="bt-btn bt-tournament" data-value="tournament" onclick="selectEditBookingType(this)"><i class="bi bi-trophy"></i><span>Турнир</span></button>
                        </div>
                        <input type="hidden" name="booking_type" id="editBookingTypeInput">

                        <div class="modal-section-title js-edit-hide-for-group">Способ оплаты *</div>
                        <div class="payment-methods js-edit-hide-for-group" id="editPaymentMethods">
                            <button type="button" class="pay-btn" data-value="cash" onclick="selectEditPayment(this)"><i class="bi bi-cash-stack"></i><span>Наличные</span></button>
                            <button type="button" class="pay-btn" data-value="card" onclick="selectEditPayment(this)"><i class="bi bi-credit-card-2-front"></i><span>Карта</span></button>
                            <button type="button" class="pay-btn" data-value="kaspi" onclick="selectEditPayment(this)"><i class="bi bi-qr-code"></i><span>Kaspi</span></button>
                            <button type="button" class="pay-btn" data-value="certificate" onclick="selectEditPayment(this)"><i class="bi bi-award"></i><span>Сертификат</span></button>
                            <button type="button" class="pay-btn" data-value="club_card" onclick="selectEditPayment(this)"><i class="bi bi-person-vcard"></i><span>Клубная карта</span></button>
                            <button type="button" class="pay-btn" data-value="deposit" onclick="selectEditPayment(this)"><i class="bi bi-wallet2"></i><span>Депозит</span></button>
                            <button type="button" class="pay-btn" data-value="cashback" onclick="selectEditPayment(this)"><i class="bi bi-arrow-repeat"></i><span>Кешбэк</span></button>
                            <button type="button" class="pay-btn" data-value="cashless" onclick="selectEditPayment(this)"><i class="bi bi-bank"></i><span>Безналичный</span></button>
                            <button type="button" class="pay-btn" data-value="free" onclick="selectEditPayment(this)"><i class="bi bi-gift"></i><span>Бесплатно</span></button>
                        </div>
                        <input type="hidden" name="payment_method" id="editPaymentMethodInput">

                        <div class="form-group js-edit-hide-for-group" style="margin-top: 14px;">
                            <label class="form-label">Статус оплаты *</label>
                            <input type="hidden" name="is_paid" id="editIsPaidInput" value="">
                            <div class="paid-toggle">
                                <button type="button" class="paid-btn" data-value="0" onclick="setEditPaid(this)">Не оплачено</button>
                                <button type="button" class="paid-btn" data-value="1" onclick="setEditPaid(this)">Оплачено</button>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 14px;">
                            <label class="form-label">Комментарий</label>
                            <textarea name="comment" id="editComment" class="form-input" rows="2" placeholder="Заметка к бронированию"></textarea>
                        </div>

                    </div>
                </div>

                <div class="book-form-error" id="editFormError" role="alert" aria-live="polite">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span class="book-form-error-text"></span>
                </div>

                <div class="sch-modal-footer" style="flex-direction: column; gap: 8px;">
                    <div style="display: flex; gap: 12px; width: 100%;">
                        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Закрыть</button>
                        <button type="submit" class="btn-confirm">Сохранить</button>
                    </div>
                    <button type="button" class="btn-danger" style="width: 100%;" id="cancelBookingBtn" onclick="cancelBooking()">Отменить бронь</button>
                </div>
            </form>
            <form id="cancelBookingForm" method="POST" style="display:none;">@csrf<input type="hidden" name="reason" id="cancelBookingReason" value=""></form>
            <div id="cancelBookingReasonModal" class="gcancel-modal" style="display:none;">
                <div class="gcancel-box">
                    <h3 class="gcancel-title">Отменить бронь занятия?</h3>
                    <p class="gcancel-sub">Корт освободится, занятие отменится. Причину видно в журнале группы.</p>
                    <label class="gcancel-label">Причина отмены</label>
                    <textarea id="cancelBookingReasonText" class="gcancel-textarea" rows="3" maxlength="255" placeholder="Например: заболел тренер, нет игроков, перенос…"></textarea>
                    <div id="cancelReasonError" style="display:none;color:#ef4444;font-size:13px;margin-top:6px;font-weight:600;">Укажите причину отмены — минимум 5 символов.</div>
                    <div class="gcancel-actions">
                        <button type="button" class="gcancel-btn-secondary" onclick="document.getElementById('cancelBookingReasonModal').style.display='none'">Назад</button>
                        <button type="button" class="gcancel-btn-danger" onclick="submitCancelWithReason()">Отменить бронь</button>
                    </div>
                </div>
            </div>
            <style>
            .gcancel-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 3000; align-items: center; justify-content: center; padding: 20px; }
            .gcancel-box { background: #131619; border: 1px solid #27272a; border-radius: 16px; padding: 24px; width: 100%; max-width: 440px; box-shadow: 0 20px 60px rgba(0,0,0,0.5); }
            .gcancel-title { font-size: 20px; font-weight: 800; color: #f4f4f5; margin: 0 0 6px; }
            .gcancel-sub { font-size: 14px; color: #a1a1aa; line-height: 1.5; margin: 0 0 16px; }
            .gcancel-label { display: block; font-size: 13px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
            .gcancel-textarea { width: 100%; background: #0c0e0f; border: 1px solid #27272a; border-radius: 10px; padding: 12px 14px; color: #e4e4e7; font-size: 15px; font-family: inherit; resize: vertical; box-sizing: border-box; }
            .gcancel-textarea:focus { outline: none; border-color: #ef4444; }
            .gcancel-actions { display: flex; gap: 10px; margin-top: 18px; }
            .gcancel-btn-secondary { flex: 1; padding: 12px; border-radius: 10px; border: 1px solid #27272a; background: #16161a; color: #a1a1aa; font-size: 15px; font-weight: 700; cursor: pointer; }
            .gcancel-btn-danger { flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
            </style>
        </div>
    </div>
</div>

<!-- Unblock Modal -->
<div class="modal fade" id="unblockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="sch-modal-header">
                <h2>Заблокированный слот</h2>
                <button class="sch-modal-close" data-bs-dismiss="modal">&#10005;</button>
            </div>
            <form id="updateBlockForm" method="POST">
                @csrf
                @method('PUT')
                <div class="sch-modal-body">
                    <div class="sch-modal-info">
                        <div class="sch-modal-info-row"><span class="sch-modal-info-label">Корт</span><span class="sch-modal-info-value" id="unblockCourtName"></span></div>
                        <div class="sch-modal-info-row"><span class="sch-modal-info-label">Время</span><span class="sch-modal-info-value" id="unblockTime"></span></div>
                    </div>
                    <div class="form-group" style="margin-top: 16px;">
                        <label class="form-label">Комментарий</label>
                        <textarea name="comment" id="blockComment" class="form-input" rows="2" placeholder="Заметка к блокировке"></textarea>
                    </div>
                </div>
                <div class="sch-modal-footer" style="flex-direction: column; gap: 8px;">
                    <div style="display: flex; gap: 12px; width: 100%;">
                        <button type="button" class="btn-cancel" data-bs-dismiss="modal">Закрыть</button>
                        <button type="submit" class="btn-confirm">Сохранить</button>
                    </div>
                    <button type="button" class="btn-danger" style="width: 100%;" onclick="unblockSlot()">Разблокировать</button>
                </div>
            </form>
            <form id="unblockForm" method="POST" style="display:none;">@csrf @method('DELETE')</form>
        </div>
    </div>
</div>

<script>
    const courtRoutes = {
        @foreach($courts as $court)
            '{{ $court->id }}': {
                book: '{{ route('club.courts.book', $court) }}',
                block: '{{ route('club.courts.blockSlot', $court) }}'
            },
        @endforeach
    };
    const freePrices = @json($freePrices);
    const orderedTimes = @json($timeSlots->values()->toArray());
    const freeSlotsByDate = @json($freeSlotsByDate);
    const coachAvailability = @json($coachAvailability); // {date: {coachId: {time: bool}}}

    let currentBook = { courtId: '', courtName: '', time: '', date: '', price: 0, maxSlots: 1, duration: 1 };

    function formatPrice(val) { return Number(val).toLocaleString('ru-RU'); }
    function hourLabel(n) { if (n === 1) return 'час'; if (n >= 2 && n <= 4) return 'часа'; return 'часов'; }

    function calcTotalPrice() {
        let total = 0;
        const startIdx = orderedTimes.indexOf(currentBook.time);
        if (startIdx === -1) return currentBook.price * currentBook.duration;
        for (let i = 0; i < currentBook.duration; i++) {
            const t = orderedTimes[startIdx + i];
            const key = currentBook.courtId + '-' + t;
            total += freePrices[key] || currentBook.price;
        }
        return total;
    }

    function calcBlockEndTime() {
        const slots = parseInt(document.getElementById('bookSlots').value) || 1;
        const startIdx = orderedTimes.indexOf(currentBook.time);
        if (startIdx >= 0 && startIdx + slots < orderedTimes.length) return orderedTimes[startIdx + slots];
        const [h, m] = currentBook.time.split(':').map(Number);
        // % 24: последний слот 23:00 + 1ч → «00:00» (не «24:00», которое
        // отклоняет валидатор H:i на бэке и блокировка не создаётся).
        const nh = (h + slots) % 24;
        return String(nh).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }

    function updateBookTotalPrice() {
        const autoPrice = calcTotalPrice();
        document.getElementById('bookCustomPrice').value = autoPrice;
        document.getElementById('bookDiscount').value = 0;
        updateFinalPrice();
        document.getElementById('bookSlots').value = currentBook.duration;
        // Длительность сбросила цену/скидку — переприменим выбранную карту/сертификат.
        if (typeof selectedCard === 'object' && selectedCard.book) applyCardPricing('book');
        if (typeof selectedCert === 'object' && selectedCert.book) applyCertPricing('book');
        recalcBookCoachPrice();
    }

    // Пересчитать цену тренера = ставка × длительность (если тренер выбран).
    function recalcBookCoachPrice() {
        const coachId = document.getElementById('bookCoachId').value;
        const priceInput = document.getElementById('bookCoachPrice');
        if (!coachId || !priceInput) return;
        const btn = document.querySelector('#bookCoachButtons .coach-btn.active');
        const rate = btn ? (parseFloat(btn.getAttribute('data-rate')) || 0) : 0;
        const dur = (typeof currentBook === 'object' && currentBook.duration) ? currentBook.duration : 1;
        if (rate > 0) priceInput.value = Math.round(rate * dur);
    }

    // Сумма цен всех выбранных тренеров (мультитренер).
    function sumBookCoachPrices() {
        let sum = 0;
        document.querySelectorAll('#bookSelectedCoaches .sel-coach-price').forEach(inp => {
            sum += parseInt(inp.value) || 0;
        });
        return sum;
    }
    function updateFinalPrice() {
        const price = parseInt(document.getElementById('bookCustomPrice').value) || 0;
        const discount = parseInt(document.getElementById('bookDiscount').value) || 0;
        const courtPrice = Math.max(0, price - discount);
        const coachTotal = sumBookCoachPrices();
        document.getElementById('bookCourtPrice').innerHTML = formatPrice(courtPrice) + ' &#8376;';
        const coachRow = document.getElementById('bookCoachTotalRow');
        if (coachTotal > 0) {
            document.getElementById('bookCoachTotal').innerHTML = formatPrice(coachTotal) + ' &#8376;';
            coachRow.style.display = '';
        } else {
            coachRow.style.display = 'none';
        }
        document.getElementById('bookTotalPrice').innerHTML = formatPrice(courtPrice + coachTotal) + ' &#8376;';
    }
    // Правка цены тренера в строке → пересчёт итога.
    document.getElementById('bookSelectedCoaches')?.addEventListener('input', updateFinalPrice);

    // ===== Итог в окне редактирования (корт + тренеры) =====
    function sumEditCoachPrices() {
        let sum = 0;
        document.querySelectorAll('#editSelectedCoaches .sel-coach-price').forEach(inp => {
            sum += parseInt(inp.value) || 0;
        });
        return sum;
    }
    function updateEditFinalPrice() {
        const priceEl = document.getElementById('editCustomPrice');
        if (!priceEl) return;
        const price = parseInt(priceEl.value) || 0;
        const discount = parseInt(document.getElementById('editDiscount').value) || 0;
        const courtPrice = Math.max(0, price - discount);
        const coachTotal = sumEditCoachPrices();
        document.getElementById('editCourtPrice').innerHTML = formatPrice(courtPrice) + ' &#8376;';
        const coachRow = document.getElementById('editCoachTotalRow');
        if (coachTotal > 0) {
            document.getElementById('editCoachTotal').innerHTML = formatPrice(coachTotal) + ' &#8376;';
            coachRow.style.display = '';
        } else {
            coachRow.style.display = 'none';
        }
        document.getElementById('editTotalPrice').innerHTML = formatPrice(courtPrice + coachTotal) + ' &#8376;';
    }
    document.getElementById('editSelectedCoaches')?.addEventListener('input', updateEditFinalPrice);

    function setDuration(n) {
        currentBook.duration = n;
        document.querySelectorAll('#durationSelector .duration-btn').forEach(btn => {
            btn.classList.toggle('active', parseInt(btn.getAttribute('data-duration')) === n);
        });
        updateBookTotalPrice();
    }

    function renderDurationButtons(maxSlots) {
        const container = document.getElementById('durationSelector');
        container.innerHTML = '';
        for (let i = 1; i <= maxSlots; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'duration-btn' + (i === 1 ? ' active' : '');
            btn.setAttribute('data-duration', i);
            btn.innerHTML = i + '<small> ' + hourLabel(i) + '</small>';
            btn.onclick = () => setDuration(i);
            container.appendChild(btn);
        }
    }

    function formatDateLabel(dateStr) {
        const months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
        const [y, m, d] = dateStr.split('-').map(Number);
        return d + ' ' + months[m - 1] + ' ' + y;
    }

    function openBookModal(courtId, courtName, time, price, maxSlots, date) {
        currentBook = { courtId, courtName, time, date, price, maxSlots: Math.min(maxSlots, 12), duration: 1 };

        document.getElementById('bookForm').action = courtRoutes[courtId].book;
        document.getElementById('blockForm').action = courtRoutes[courtId].block;
        document.getElementById('bookDate').value = date;
        document.getElementById('blockDate').value = date;
        document.getElementById('bookStartTime').value = time;
        document.getElementById('bookSlots').value = 1;
        document.getElementById('bookCourtName').textContent = courtName;
        document.getElementById('bookDateLabel').textContent = formatDateLabel(date);
        document.getElementById('bookTime').textContent = time;
        document.getElementById('bookPrice').innerHTML = formatPrice(price) + ' &#8376;';
        document.getElementById('blockStartTime').value = time;
        document.getElementById('blockEndTime').value = calcBlockEndTime();

        const form = document.getElementById('bookForm');
        const nameEl = form.querySelector('input[name="client_name"]');
        if (nameEl) {
            nameEl.value = '';
            nameEl.removeAttribute('readonly');
            nameEl.classList.remove('is-locked');
            nameEl.removeAttribute('title');
        }
        const nameHint = document.getElementById('bookClientNameHint');
        if (nameHint) nameHint.style.display = 'none';
        form.querySelector('input[name="client_phone"]').value = '';
        const noteEl = document.getElementById('bookClientNote');
        const noteHint = document.getElementById('bookClientNoteHint');
        if (noteEl) {
            noteEl.value = '';
            noteEl.removeAttribute('readonly');
            noteEl.classList.remove('is-locked');
            noteEl.removeAttribute('title');
        }
        if (noteHint) noteHint.style.display = 'none';
        editingBookingId = null; // новая бронь — резерв считаем полностью
        loadClientCards('book', '', null); // сброс карт клиента
        form.querySelector('textarea[name="comment"]').value = '';
        document.getElementById('paymentMethodInput').value = '';
        document.getElementById('isPaidInput').value = '';
        document.querySelectorAll('#paymentMethods .pay-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('#bookModal .paid-toggle .paid-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('bookingTypeInput').value = '';
        document.querySelectorAll('#bookingTypeButtons .bt-btn').forEach(b => b.classList.remove('active'));
        // Свежая бронь — негрупповая: показываем поля клиента/оплаты, прячем блок группы.
        document.querySelectorAll('.js-hide-for-group').forEach(el => el.style.display = '');
        document.querySelectorAll('.js-show-for-group').forEach(el => el.style.display = 'none');
        const bgsw = document.getElementById('bookGroupSelectWrap');
        if (bgsw) bgsw.style.display = 'none';
        if (typeof renderGroupMembers === 'function') renderGroupMembers('');
        ['bookClientName', 'bookClientPhone'].forEach(id => { const e = document.getElementById(id); if (e) e.required = true; });
        document.getElementById('bookCoachId').value = '';
        document.getElementById('bookCoachPaidGroup').style.display = 'none';
        document.getElementById('bookCoachPaidInput').value = '';
        document.querySelectorAll('#bookCoachPaidGroup .paid-btn').forEach(b => b.classList.remove('active'));
        const bcpgW = document.getElementById('bookCoachPriceGroup');
        if (bcpgW) bcpgW.style.display = 'none';
        const bcpW = document.getElementById('bookCoachPrice');
        if (bcpW) bcpW.value = '';
        updateCoachButtons();

        renderDurationButtons(currentBook.maxSlots);
        updateBookTotalPrice();

        if (typeof window.resetRepeatSection === 'function') window.resetRepeatSection();
        if (typeof clearBookFormError === 'function') clearBookFormError();

        new bootstrap.Modal(document.getElementById('bookModal')).show();
    }

    function openViewModal(data) {
        document.getElementById('viewCourtName').textContent = data.courtName;
        document.getElementById('viewDate').textContent = formatDateLabel(data.date);
        document.getElementById('viewTime').textContent = data.startTime;
        // Цена/час = цена брони ÷ длительность в часах (как в окне создания).
        let _durMin = parseTimeToMinutes(data.endTime) - parseTimeToMinutes(data.startTime);
        if (_durMin <= 0) _durMin += 1440;
        const _durH = Math.max(1, Math.round(_durMin / 60));
        document.getElementById('viewPrice').innerHTML = formatPrice(Math.round((data.price || 0) / _durH)) + ' &#8376;';

        unlockClientFields('edit');
        clearEditFormError();

        document.getElementById('editClientName').value = data.clientName || '';
        document.getElementById('editClientPhone').value = data.clientPhone ? '+' + data.clientPhone.replace(/\D/g, '') : '';
        document.getElementById('editClientNote').value = '';

        const phoneNorm = (data.clientPhone || '').replace(/\D/g, '');
        if (phoneNorm) {
            fetch('{{ route("club.clients.search") }}?q=' + encodeURIComponent(phoneNorm) + '&field=phone')
                .then(r => r.json())
                .then(clients => {
                    const exact = (clients || []).find(c => (c.phone || '').replace(/\D/g, '') === phoneNorm);
                    if (exact) lockClientFields('edit', exact.note || '');
                })
                .catch(() => {});
        }
        // Клубные карты клиента (с предвыбором текущей карты брони).
        editingBookingId = data.id || null;
        loadClientCards('edit', phoneNorm, data.clubCardId || null);

        document.getElementById('editPaymentMethodInput').value = data.paymentMethod || '';
        document.querySelectorAll('#editPaymentMethods .pay-btn').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-value') === data.paymentMethod);
        });

        const btVal = data.bookingType || '';
        document.getElementById('editBookingTypeInput').value = btVal;
        document.querySelectorAll('#editBookingTypeButtons .bt-btn').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-value') === btVal);
        });

        const paidVal = data.isPaid ? '1' : '0';
        document.getElementById('editIsPaidInput').value = paidVal;
        document.querySelectorAll('#viewModal .paid-toggle .paid-btn').forEach(b => {
            if (b.closest('#editCoachPaidGroup')) return;
            b.classList.toggle('active', b.getAttribute('data-value') === paidVal);
        });

        document.getElementById('editCustomPrice').value = Math.round(data.price) || 0;
        document.getElementById('editDiscount').value = Math.round(data.discount) || 0;
        document.getElementById('editComment').value = data.comment || '';

        const processedGroup = document.getElementById('editProcessedGroup');
        const processedVal = data.isProcessed ? '1' : '0';
        document.getElementById('editIsProcessedInput').value = processedVal;
        processedGroup.style.display = data.isProcessed ? 'none' : '';
        document.querySelectorAll('.processed-btn').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-value') === processedVal);
        });

        // Список тренеров брони: из пивота (мультитренер) либо синтез из одиночного
        // coachId (старые брони без пивота). Групповая — тренер отдельным селектом.
        let coachList = Array.isArray(data.coaches) ? data.coaches.slice() : [];
        if (coachList.length === 0 && data.coachId) {
            coachList = [{ coachId: data.coachId, price: data.coachPrice, paid: data.coachPaid }];
        }
        if (data.bookingType === 'group') coachList = [];
        const selectedIds = coachList.map(c => String(c.coachId));
        document.getElementById('editCoachId').value = selectedIds.length ? selectedIds[0] : '';

        const _sMin = parseTimeToMinutes(data.startTime);
        let _eMin = parseTimeToMinutes(data.endTime);
        if (_eMin <= _sMin) _eMin += 1440;
        const _hours = Math.max(1, Math.round((_eMin - _sMin) / 60));

        const dayCoaches = (coachAvailability[data.date] || {});
        document.querySelectorAll('#editCoachButtons .coach-btn').forEach(b => {
            const coachId = b.getAttribute('data-coach-id');
            const isSelected = selectedIds.includes(String(coachId));
            const available = isSelected || (dayCoaches[coachId] && dayCoaches[coachId][data.startTime]);
            b.classList.remove('active', 'unavailable');
            if (!available) b.classList.add('unavailable');
            if (isSelected) b.classList.add('active');
        });

        // Строки выбранных тренеров с ценой/оплатой.
        const editSelContainer = document.getElementById('editSelectedCoaches');
        editSelContainer.innerHTML = '';
        coachList.forEach(c => {
            const btn = document.querySelector('#editCoachButtons .coach-btn[data-coach-id="' + c.coachId + '"]');
            const name = btn ? (btn.querySelector('.coach-btn-name')?.textContent || '') : ('#' + c.coachId);
            let price = (c.price !== null && c.price !== undefined && c.price !== '') ? Math.round(c.price) : '';
            if (price === '') {
                const rate = btn ? (parseFloat(btn.getAttribute('data-rate')) || 0) : 0;
                price = rate > 0 ? Math.round(rate * _hours) : '';
            }
            editSelContainer.insertAdjacentHTML('beforeend', editCoachRowHtml(c.coachId, name, price, c.paid === true));
        });
        updateEditFinalPrice();

        // Старые одиночные поля в мультитренере не используются — прячем.
        const editCoachPaidGroupW = document.getElementById('editCoachPaidGroup');
        document.getElementById('editCoachPaidInput').value = '';
        editCoachPaidGroupW.style.display = 'none';
        const editCoachPriceGroupW = document.getElementById('editCoachPriceGroup');
        const editCoachPriceW = document.getElementById('editCoachPrice');
        if (editCoachPriceGroupW && editCoachPriceW) {
            editCoachPriceGroupW.style.display = 'none';
            editCoachPriceW.value = '';
        }

        document.getElementById('editBookingForm').action = '{{ url("club/courts/bookings") }}/' + data.id;
        document.getElementById('cancelBookingForm').action = '{{ url("club/courts/bookings") }}/' + data.id + '/cancel';
        window._viewHasCert = !!data.hasCertificate;

        // Если часы по клубной карте уже списаны — отмена брони недоступна.
        const cancelBookingBtn = document.getElementById('cancelBookingBtn');
        if (cancelBookingBtn) cancelBookingBtn.style.display = data.cardCharged ? 'none' : '';

        // Группа: тренер + участники + скрытие полей клиента/оплаты для групповой брони.
        renderEditGroup(data);
        applyEditGroupVisibility((data.bookingType || '') === 'group');

        renderEditDurationButtons(data);

        new bootstrap.Modal(document.getElementById('viewModal')).show();
    }

    let currentEdit = null;

    function renderEditDurationButtons(data) {
        const slotDur = data.slotDuration || 60;
        const startMin = parseTimeToMinutes(data.startTime);
        let endMin = parseTimeToMinutes(data.endTime);
        if (endMin <= startMin) endMin += 24 * 60;
        const currentSlots = Math.max(1, Math.round((endMin - startMin) / slotDur));
        const date = data.date;
        const courtId = data.courtId;
        const maxSlots = computeMaxEditSlots(courtId, date, data.startTime, currentSlots);

        const pricePerSlot = currentSlots > 0 ? (Number(data.price) / currentSlots) : 0;
        currentEdit = {
            id: data.id,
            courtId: courtId,
            date: date,
            startTime: data.startTime,
            slotDuration: slotDur,
            originalSlots: currentSlots,
            originalPricePerSlot: pricePerSlot,
        };

        document.getElementById('editSlots').value = currentSlots;
        const container = document.getElementById('editDurationSelector');
        if (!container) return;
        container.innerHTML = '';
        for (let i = 1; i <= maxSlots; i++) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'duration-btn' + (i === currentSlots ? ' active' : '');
            btn.dataset.slots = i;
            btn.textContent = formatDuration(i * slotDur);
            btn.onclick = function() {
                document.querySelectorAll('#editDurationSelector .duration-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById('editSlots').value = i;
                applyEditPriceForSlots(i);
                if (typeof selectedCert === 'object' && selectedCert.edit) applyCertPricing('edit');
            };
            container.appendChild(btn);
        }
    }

    function computeMaxEditSlots(courtId, date, startTime, currentSlots) {
        const startIdx = orderedTimes.indexOf(startTime);
        if (startIdx === -1) return currentSlots;
        const dateMap = (freeSlotsByDate || {})[date] || {};
        let max = currentSlots;
        let i = startIdx + currentSlots;
        while (i < orderedTimes.length && max < 12) {
            const key = courtId + '-' + orderedTimes[i];
            if (dateMap[key] !== undefined) {
                max++;
                i++;
            } else {
                break;
            }
        }
        return max;
    }

    function calcEditTotalPrice(slots) {
        if (!currentEdit) return 0;
        const startIdx = orderedTimes.indexOf(currentEdit.startTime);
        if (startIdx === -1) return currentEdit.originalPricePerSlot * slots;
        const dateMap = (freeSlotsByDate || {})[currentEdit.date] || {};
        let total = 0;
        for (let i = 0; i < slots; i++) {
            const t = orderedTimes[startIdx + i];
            if (!t) break;
            const key = currentEdit.courtId + '-' + t;
            if (dateMap[key] !== undefined) {
                total += Number(dateMap[key]);
            } else {
                total += currentEdit.originalPricePerSlot;
            }
        }
        return Math.round(total);
    }

    function applyEditPriceForSlots(slots) {
        const total = calcEditTotalPrice(slots);
        const priceInput = document.getElementById('editCustomPrice');
        const discountInput = document.getElementById('editDiscount');
        if (priceInput) priceInput.value = total;
        if (discountInput) discountInput.value = 0;
        // Пересчёт цены тренера под новую длительность.
        const coachId = document.getElementById('editCoachId').value;
        const coachPrice = document.getElementById('editCoachPrice');
        if (coachId && coachPrice) {
            const btn = document.querySelector('#editCoachButtons .coach-btn.active');
            const rate = btn ? (parseFloat(btn.getAttribute('data-rate')) || 0) : 0;
            if (rate > 0) coachPrice.value = Math.round(rate * slots);
        }
        // Мультитренер: пересчитать цену в каждой строке по ставке тренера.
        document.querySelectorAll('#editSelectedCoaches .sel-coach-row').forEach(row => {
            const cid = row.getAttribute('data-cid');
            const b = document.querySelector('#editCoachButtons .coach-btn[data-coach-id="' + cid + '"]');
            const rate = b ? (parseFloat(b.getAttribute('data-rate')) || 0) : 0;
            const priceInp = row.querySelector('.sel-coach-price');
            if (rate > 0 && priceInp) priceInp.value = Math.round(rate * slots);
        });
        updateEditFinalPrice();
    }

    function parseTimeToMinutes(t) {
        if (!t) return 0;
        const [h, m] = t.split(':').map(Number);
        return h * 60 + (m || 0);
    }

    function formatDuration(min) {
        const h = Math.floor(min / 60);
        const m = min % 60;
        if (m === 0) return h + 'ч';
        if (h === 0) return m + 'м';
        return h + 'ч ' + m + 'м';
    }

    function cancelBooking() {
        // Причину спрашиваем ВСЕГДА, для любой брони.
        const isGroup = document.getElementById('editBookingTypeInput').value === 'group';
        const hasCert = !!window._viewHasCert;
        const ta = document.getElementById('cancelBookingReasonText');
        if (ta) ta.value = '';
        const rm = document.getElementById('cancelBookingReasonModal');
        const rmTitle = rm ? rm.querySelector('.gcancel-title') : null;
        const rmSub = rm ? rm.querySelector('.gcancel-sub') : null;
        if (isGroup) {
            if (rmTitle) rmTitle.textContent = 'Отменить бронь занятия?';
            if (rmSub) rmSub.textContent = 'Корт освободится, занятие отменится. Причину видно в журнале группы — по ней потом понятно, за что отменили.';
        } else if (hasCert) {
            if (rmTitle) rmTitle.textContent = 'Отменить бронь?';
            if (rmSub) rmSub.textContent = 'Корт освободится, сертификат вернётся в активные. Причина сохранится к брони.';
        } else {
            if (rmTitle) rmTitle.textContent = 'Отменить бронь?';
            if (rmSub) rmSub.textContent = 'Корт освободится. Укажите причину — она сохранится к брони.';
        }
        const modalEl = document.getElementById('viewModal');
        if (modalEl && rm && rm.parentElement !== modalEl) modalEl.appendChild(rm);
        rm.style.display = 'flex';
        setTimeout(function () { if (ta) ta.focus(); }, 50);
    }
    function submitCancelWithReason() {
        const ta = document.getElementById('cancelBookingReasonText');
        const val = (ta ? ta.value : '').trim();
        const err = document.getElementById('cancelReasonError');
        if (val.length < 5) {
            if (err) err.style.display = 'block';
            if (ta) { ta.style.borderColor = '#ef4444'; ta.focus(); }
            return;
        }
        if (err) err.style.display = 'none';
        const reasonInput = document.getElementById('cancelBookingReason');
        if (reasonInput) reasonInput.value = val;
        document.getElementById('cancelBookingReasonModal').style.display = 'none';
        document.getElementById('cancelBookingForm').submit();
    }

    // Лок/анлок клиентских полей + валидация edit-формы (как в book)
    function lockClientFields(prefix, noteValue) {
        const nameInput = document.getElementById(prefix + 'ClientName');
        if (nameInput) {
            nameInput.setAttribute('readonly', 'readonly');
            nameInput.classList.add('is-locked');
            nameInput.title = 'Имя берётся из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».';
        }
        const nameHint = document.getElementById(prefix + 'ClientNameHint');
        if (nameHint) nameHint.style.display = 'block';
        const noteInput = document.getElementById(prefix + 'ClientNote');
        if (noteInput) {
            noteInput.value = noteValue || '';
            noteInput.setAttribute('readonly', 'readonly');
            noteInput.classList.add('is-locked');
            noteInput.title = 'Заметка берётся из карточки клиента. Чтобы изменить — отредактируйте карточку в разделе «Клиенты».';
        }
        const noteHint = document.getElementById(prefix + 'ClientNoteHint');
        if (noteHint) noteHint.style.display = 'block';
    }
    function unlockClientFields(prefix) {
        const nameInput = document.getElementById(prefix + 'ClientName');
        if (nameInput && nameInput.hasAttribute('readonly')) {
            nameInput.removeAttribute('readonly');
            nameInput.classList.remove('is-locked');
            nameInput.removeAttribute('title');
        }
        const nameHint = document.getElementById(prefix + 'ClientNameHint');
        if (nameHint) nameHint.style.display = 'none';
        const noteInput = document.getElementById(prefix + 'ClientNote');
        if (noteInput) {
            noteInput.removeAttribute('readonly');
            noteInput.classList.remove('is-locked');
            noteInput.removeAttribute('title');
        }
        const noteHint = document.getElementById(prefix + 'ClientNoteHint');
        if (noteHint) noteHint.style.display = 'none';
    }

    function showEditFormError(message, targetEl) {
        const errorBox = document.getElementById('editFormError');
        if (!errorBox) return;
        errorBox.querySelector('.book-form-error-text').textContent = message;
        errorBox.classList.add('is-visible');
        document.querySelectorAll('#editBookingForm .has-error').forEach(el => el.classList.remove('has-error'));
        if (targetEl) {
            targetEl.classList.add('has-error');
            try { targetEl.scrollIntoView({behavior: 'smooth', block: 'center'}); } catch (_) {}
            if (targetEl.tagName === 'INPUT') setTimeout(() => targetEl.focus({preventScroll: true}), 200);
        }
    }
    function clearEditFormError() {
        const errorBox = document.getElementById('editFormError');
        if (errorBox) errorBox.classList.remove('is-visible');
        document.querySelectorAll('#editBookingForm .has-error').forEach(el => el.classList.remove('has-error'));
    }

    document.getElementById('editBookingForm').addEventListener('submit', function(e) {
        const form = e.target;
        const nameInput = form.querySelector('input[name="client_name"]');
        const phoneInput = form.querySelector('input[name="client_phone"]');
        const paymentInput = form.querySelector('input[name="payment_method"]');
        const paidInput = form.querySelector('input[name="is_paid"]');
        const paymentGroup = document.getElementById('editPaymentMethods');
        const paidGroup = document.getElementById('editIsPaidInput').parentElement.querySelector('.paid-toggle');

        const nameIsLocked = nameInput && nameInput.hasAttribute('readonly');
        if (!nameIsLocked) {
            const words = (nameInput.value || '').trim().split(/\s+/).filter(Boolean);
            if (words.length < 2) {
                e.preventDefault();
                showEditFormError('Укажите имя и фамилию клиента (например: «Денис Дудников»)', nameInput);
                return;
            }
        }
        if (!(phoneInput.value || '').trim()) {
            e.preventDefault();
            showEditFormError('Укажите номер телефона клиента', phoneInput);
            return;
        }
        if (!paymentInput.value) {
            e.preventDefault();
            showEditFormError('Выберите способ оплаты', paymentGroup);
            return;
        }
        if (paymentInput.value === 'club_card' && !(document.getElementById('editCardInput').value || '').trim()) {
            e.preventDefault();
            showEditFormError('Выберите клубную карту', document.getElementById('editCardButtons'));
            return;
        }
        if (paidInput.value === '') {
            e.preventDefault();
            showEditFormError('Выберите статус оплаты: «Оплачено» или «Не оплачено»', paidGroup);
            return;
        }
        // Старое одиночное поле оплаты тренера проверяем, только если оно видимо.
        // В мультитренере оплата задаётся чекбоксом в строке (пустой не бывает).
        const coachId = document.getElementById('editCoachId').value;
        const coachPaid = document.getElementById('editCoachPaidInput').value;
        const legacyEditPaid = document.getElementById('editCoachPaidGroup');
        const legacyEditPaidVisible = legacyEditPaid && legacyEditPaid.style.display !== 'none';
        if (coachId && legacyEditPaidVisible && coachPaid === '') {
            e.preventDefault();
            showEditFormError('Выберите статус оплаты тренера: «Оплачен» или «Не оплачен»', document.querySelector('#editCoachPaidGroup .paid-toggle'));
            return;
        }
        clearEditFormError();
    });
    document.getElementById('editBookingForm').addEventListener('input', clearEditFormError);
    document.getElementById('editBookingForm').addEventListener('click', function(e) {
        if (e.target.closest('.pay-btn, .paid-btn')) clearEditFormError();
    });

    const editPhoneEl = document.getElementById('editClientPhone');
    if (editPhoneEl) {
        editPhoneEl.addEventListener('input', function() {
            if (document.getElementById('editClientName').hasAttribute('readonly')) {
                unlockClientFields('edit');
                document.getElementById('editClientNote').value = '';
            }
            clearTimeout(cardLoadTimer);
            cardLoadTimer = setTimeout(() => loadClientCards('edit', this.value, null), 400);
        });
    }

    function selectEditPayment(btn) {
        document.querySelectorAll('#editPaymentMethods .pay-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const val = btn.getAttribute('data-value');
        document.getElementById('editPaymentMethodInput').value = val;
        if (val !== 'club_card') deselectCard('edit');
        if (val !== 'certificate') deselectCert('edit');
        // «Бесплатно» — цена и скидка 0, статус → Оплачено
        if (val === 'free') {
            const p = document.getElementById('editCustomPrice'); if (p) p.value = 0;
            const d = document.getElementById('editDiscount'); if (d) d.value = 0;
            document.getElementById('editIsPaidInput').value = '1';
            document.querySelectorAll('#viewModal .paid-toggle .paid-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-value') === '1'));
        }
    }

    function setEditPaid(btn) {
        document.querySelectorAll('#viewModal .paid-toggle .paid-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('editIsPaidInput').value = btn.getAttribute('data-value');
    }

    function setEditProcessed(btn) {
        document.querySelectorAll('.processed-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('editIsProcessedInput').value = btn.getAttribute('data-value');
    }

    function openUnblockModal(blockId, courtName, time, comment) {
        document.getElementById('unblockCourtName').textContent = courtName;
        document.getElementById('unblockTime').textContent = time;
        document.getElementById('blockComment').value = comment || '';
        document.getElementById('updateBlockForm').action = '{{ url("club/courts/blocks") }}/' + blockId;
        document.getElementById('unblockForm').action = '{{ url("club/courts/blocks") }}/' + blockId;
        new bootstrap.Modal(document.getElementById('unblockModal')).show();
    }

    function unblockSlot() {
        if (confirm('Вы уверены, что хотите разблокировать этот слот?')) document.getElementById('unblockForm').submit();
    }

    function blockSlot() {
        document.getElementById('blockStartTime').value = currentBook.time;
        document.getElementById('blockEndTime').value = calcBlockEndTime();
        const commentField = document.querySelector('#bookForm textarea[name="comment"]');
        document.getElementById('newBlockComment').value = commentField ? commentField.value : '';
        document.getElementById('blockForm').submit();
    }

    function selectPayment(btn) {
        document.querySelectorAll('#paymentMethods .pay-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const val = btn.getAttribute('data-value');
        document.getElementById('paymentMethodInput').value = val;
        // Выбран не «Клубная карта» — снимаем карту и возвращаем обычную цену.
        if (val !== 'club_card') deselectCard('book');
        if (val !== 'certificate') deselectCert('book');
        // «Бесплатно» — цена и скидка 0, статус → Оплачено
        if (val === 'free') {
            const p = document.getElementById('bookCustomPrice'); if (p) p.value = 0;
            const d = document.getElementById('bookDiscount'); if (d) d.value = 0;
            updateFinalPrice();
            document.getElementById('isPaidInput').value = '1';
            document.querySelectorAll('#bookModal .paid-toggle .paid-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-value') === '1'));
        }
    }

    function setPaid(btn) {
        document.querySelectorAll('#bookModal .paid-toggle .paid-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('isPaidInput').value = btn.getAttribute('data-value');
    }

    // Тип брони (опционально, повторный клик снимает выбор)
    function selectBookingType(btn) {
        const input = document.getElementById('bookingTypeInput');
        const wasActive = btn.classList.contains('active');
        document.querySelectorAll('#bookingTypeButtons .bt-btn').forEach(b => b.classList.remove('active'));
        if (wasActive) { input.value = ''; }
        else { btn.classList.add('active'); input.value = btn.getAttribute('data-value'); }

        const isGroup = input.value === 'group';

        // Селект группы — только при типе «Групповые»
        const groupWrap = document.getElementById('bookGroupSelectWrap');
        if (groupWrap) {
            groupWrap.style.display = isGroup ? 'block' : 'none';
            if (!isGroup) {
                const sel = document.getElementById('bookGroupSelect');
                if (sel) sel.value = '';
                renderGroupMembers('');
            }
        }

        // При типе group прячем клиента/оплату/скидку, снимаем required.
        document.querySelectorAll('.js-hide-for-group').forEach(el => el.style.display = isGroup ? 'none' : '');
        document.querySelectorAll('.js-show-for-group').forEach(el => el.style.display = isGroup ? 'block' : 'none');
        ['bookClientName', 'bookClientPhone'].forEach(id => {
            const e = document.getElementById(id);
            if (e) { e.required = !isGroup; if (isGroup) e.value = ''; }
        });
        // Блок карты — показываем только если есть карты и не групповая бронь.
        const cardWrap = document.getElementById('bookCardWrap');
        if (cardWrap) cardWrap.style.display = (!isGroup && (cardCache.book || []).length) ? '' : 'none';
    }
    function selectEditBookingType(btn) {
        const input = document.getElementById('editBookingTypeInput');
        const wasActive = btn.classList.contains('active');
        document.querySelectorAll('#editBookingTypeButtons .bt-btn').forEach(b => b.classList.remove('active'));
        if (wasActive) { input.value = ''; }
        else { btn.classList.add('active'); input.value = btn.getAttribute('data-value'); }
        applyEditGroupVisibility(input.value === 'group');
    }

    // ====== Клубные карты в окне брони (кнопки) — идентично дневному виду ======
    const cardsForClientUrl = @json(route('club.cards.forClient'));
    const cardCache = { book: [], edit: [] };
    const selectedCard = { book: null, edit: null };
    let cardLoadTimer = null;

    function cardEsc(s) {
        const d = document.createElement('div');
        d.textContent = (s == null ? '' : String(s));
        return d.innerHTML;
    }

    // id редактируемой брони — чтобы исключить её из резерва часов карты
    // (иначе своя же бронь показала бы «нет свободных часов»).
    let editingBookingId = null;

    function loadClientCards(prefix, phone, preselectId) {
        loadClientCertificates(prefix, phone);
        const wrap = document.getElementById(prefix + 'CardWrap');
        const box = document.getElementById(prefix + 'CardButtons');
        const input = document.getElementById(prefix + 'CardInput');
        const hint = document.getElementById(prefix + 'CardHint');
        if (!wrap || !box) return;
        const reset = () => {
            wrap.style.display = 'none'; box.innerHTML = '';
            if (input) input.value = '';
            if (hint) hint.style.display = 'none';
            selectedCard[prefix] = null; cardCache[prefix] = [];
        };
        const digits = (phone || '').replace(/\D/g, '');
        if (digits.length < 5) { reset(); return; }
        let url = cardsForClientUrl + '?phone=' + encodeURIComponent(digits);
        if (preselectId) url += '&include_card_id=' + encodeURIComponent(preselectId);
        if (prefix === 'edit' && editingBookingId) url += '&exclude_booking_id=' + encodeURIComponent(editingBookingId);
        fetch(url)
            .then(r => r.json())
            .then(d => {
                // Неактивную (списанную/просроченную) карту показываем только если
                // это карта текущей брони — свободный выбор списанных карт запрещён.
                const cards = ((d && d.cards) ? d.cards : []).filter(c =>
                    !c.inactive || String(c.id) === String(preselectId));
                cardCache[prefix] = cards;
                selectedCard[prefix] = null;
                if (input) input.value = '';
                if (!cards.length) { reset(); return; }
                box.innerHTML = cards.map(c => {
                    const avail = (c.available != null ? c.available : c.balance);
                    const noHours = !!c.is_counter && avail <= 0; // все часы зарезервированы
                    const isPre = String(c.id) === String(preselectId);
                    const blocked = (c.inactive || noHours) && !isPre;
                    const cap = (c.capacity != null ? c.capacity : c.nominal);
                    const sub = c.is_counter ? ('осталось ' + avail + '/' + cap + ' ч')
                                             : ('скидка −' + c.discount_percent + '%');
                    let note = '';
                    if (c.inactive) note = ' · списано, не активна';
                    else if (noHours) note = ' · нет свободных часов';
                    const cls = 'client-card-btn' + (blocked ? ' inactive' : '');
                    const clickAttr = blocked ? '' : ' onclick="onCardButton(\'' + prefix + '\',' + c.id + ')"';
                    return '<button type="button" class="' + cls + '" data-id="' + c.id + '"' + clickAttr + '>' +
                        '<span class="ccb-name">' + cardEsc(c.type_name || 'Карта') + '</span>' +
                        '<span class="ccb-code">' + cardEsc(c.code) + '</span>' +
                        '<span class="ccb-sub">' + sub + note + '</span></button>';
                }).join('');
                wrap.style.display = '';
                if (preselectId) onCardButton(prefix, preselectId, true);
            })
            .catch(() => reset());
    }

    function onCardButton(prefix, cardId, keepIfSelected) {
        const card = (cardCache[prefix] || []).find(c => String(c.id) === String(cardId));
        if (!card) return;
        const box = document.getElementById(prefix + 'CardButtons');
        const input = document.getElementById(prefix + 'CardInput');
        const hint = document.getElementById(prefix + 'CardHint');
        const already = selectedCard[prefix] && String(selectedCard[prefix].id) === String(cardId);

        if (already && !keepIfSelected) {
            selectedCard[prefix] = null;
            if (input) input.value = '';
            if (box) box.querySelectorAll('.client-card-btn').forEach(b => b.classList.remove('active'));
            if (hint) hint.style.display = 'none';
            applyCardPricing(prefix);
            return;
        }

        selectedCard[prefix] = card;
        if (input) input.value = card.id;
        if (box) box.querySelectorAll('.client-card-btn').forEach(b =>
            b.classList.toggle('active', String(b.dataset.id) === String(cardId)));

        setPaymentClubCard(prefix);
        applyCardPricing(prefix);

        if (hint) {
            hint.textContent = card.is_counter
                ? ('Спишутся часы по длительности брони после её завершения (остаток: ' + (card.available != null ? card.available : card.balance) + ' ч).')
                : ('Скидка −' + card.discount_percent + '% применена к цене.');
            hint.style.display = '';
        }
    }

    function deselectCard(prefix) {
        if (!selectedCard[prefix]) return;
        selectedCard[prefix] = null;
        const input = document.getElementById(prefix + 'CardInput');
        const box = document.getElementById(prefix + 'CardButtons');
        const hint = document.getElementById(prefix + 'CardHint');
        if (input) input.value = '';
        if (box) box.querySelectorAll('.client-card-btn').forEach(b => b.classList.remove('active'));
        if (hint) hint.style.display = 'none';
        applyCardPricing(prefix);
    }

    function setPaymentClubCard(prefix) {
        const sel = (prefix === 'book') ? '#paymentMethods' : '#editPaymentMethods';
        const inputId = (prefix === 'book') ? 'paymentMethodInput' : 'editPaymentMethodInput';
        document.querySelectorAll(sel + ' .pay-btn').forEach(b =>
            b.classList.toggle('active', b.getAttribute('data-value') === 'club_card'));
        const inp = document.getElementById(inputId);
        if (inp) inp.value = 'club_card';
    }

    function applyCardPricing(prefix) {
        const card = selectedCard[prefix];
        const priceEl = document.getElementById(prefix === 'book' ? 'bookCustomPrice' : 'editCustomPrice');
        const discEl = document.getElementById(prefix === 'book' ? 'bookDiscount' : 'editDiscount');
        if (!priceEl || !discEl) return;

        if (!card) {
            discEl.value = 0;
            if (prefix === 'book') {
                if (typeof calcTotalPrice === 'function') priceEl.value = calcTotalPrice();
                updateFinalPrice();
            }
            return;
        }
        if (card.is_counter) {
            const nominal = parseInt(card.nominal) || 0;
            const cardPrice = parseInt(card.price) || 0;
            if (nominal > 0 && cardPrice > 0) {
                const perHour = Math.round(cardPrice / nominal);
                const slots = (prefix === 'book' && typeof currentBook === 'object') ? (currentBook.duration || 1) : 1;
                priceEl.value = perHour * slots;
            }
            discEl.value = 0;
        } else if (card.is_discount) {
            const price = parseInt(priceEl.value) || 0;
            discEl.value = Math.round(price * (card.discount_percent || 0) / 100);
        }
        if (prefix === 'book') updateFinalPrice();
    }

    // ==== Сертификаты клиента (по аналогии с картами) ====
    const certsForClientUrl = @json(route('club.certificates.forClient'));
    let certCache = { book: [], edit: [] };
    let selectedCert = { book: null, edit: null };

    function loadClientCertificates(prefix, phone) {
        const wrap = document.getElementById(prefix + 'CertWrap');
        const box = document.getElementById(prefix + 'CertButtons');
        const input = document.getElementById(prefix + 'CertInput');
        const hint = document.getElementById(prefix + 'CertHint');
        if (!wrap || !box) return;
        const reset = () => {
            wrap.style.display = 'none'; box.innerHTML = '';
            if (input) input.value = '';
            if (hint) hint.style.display = 'none';
            selectedCert[prefix] = null; certCache[prefix] = [];
        };
        const digits = (phone || '').replace(/\D/g, '');
        if (digits.length < 5) { reset(); return; }
        fetch(certsForClientUrl + '?phone=' + encodeURIComponent(digits))
            .then(r => r.json())
            .then(d => {
                const certs = (d && d.certificates) ? d.certificates : [];
                certCache[prefix] = certs;
                selectedCert[prefix] = null;
                if (input) input.value = '';
                if (!certs.length) { reset(); return; }
                box.innerHTML = certs.map(c =>
                    '<button type="button" class="client-card-btn" data-id="' + c.id + '" onclick="onCertButton(\'' + prefix + '\',' + c.id + ')">' +
                    '<span class="ccb-name">' + cardEsc(c.label) + '</span>' +
                    '<span class="ccb-code">' + cardEsc(c.number) + '</span>' +
                    '<span class="ccb-sub">' + (c.is_free ? 'бесплатно' : 'на сумму') + '</span></button>'
                ).join('');
                wrap.style.display = '';
            })
            .catch(() => reset());
    }

    function onCertButton(prefix, certId) {
        const cert = (certCache[prefix] || []).find(c => String(c.id) === String(certId));
        if (!cert) return;
        const box = document.getElementById(prefix + 'CertButtons');
        const input = document.getElementById(prefix + 'CertInput');
        const already = selectedCert[prefix] && String(selectedCert[prefix].id) === String(certId);
        if (already) { deselectCert(prefix); return; }

        deselectCard(prefix); // сертификат исключает карту
        selectedCert[prefix] = cert;
        if (input) input.value = cert.id;
        if (box) box.querySelectorAll('.client-card-btn').forEach(b =>
            b.classList.toggle('active', String(b.dataset.id) === String(certId)));
        setPaymentCertificate(prefix);
        applyCertPricing(prefix);
    }

    function deselectCert(prefix) {
        if (!selectedCert[prefix]) return;
        selectedCert[prefix] = null;
        const input = document.getElementById(prefix + 'CertInput');
        const box = document.getElementById(prefix + 'CertButtons');
        const hint = document.getElementById(prefix + 'CertHint');
        if (input) input.value = '';
        if (box) box.querySelectorAll('.client-card-btn').forEach(b => b.classList.remove('active'));
        if (hint) hint.style.display = 'none';
        const discEl = document.getElementById(prefix === 'book' ? 'bookDiscount' : 'editDiscount');
        if (discEl) discEl.value = 0;
        if (prefix === 'book' && typeof updateFinalPrice === 'function') updateFinalPrice();
        if (prefix === 'edit' && typeof updateEditFinalPrice === 'function') updateEditFinalPrice();
    }

    function setPaymentCertificate(prefix) {
        const sel = (prefix === 'book') ? '#paymentMethods' : '#editPaymentMethods';
        const inputId = (prefix === 'book') ? 'paymentMethodInput' : 'editPaymentMethodInput';
        document.querySelectorAll(sel + ' .pay-btn').forEach(b =>
            b.classList.toggle('active', b.getAttribute('data-value') === 'certificate'));
        const inp = document.getElementById(inputId);
        if (inp) inp.value = 'certificate';
    }

    // Часы брони (для сертификата на часы).
    function certBookingHours(prefix) {
        if (prefix === 'book') {
            return (typeof currentBook === 'object' && currentBook.duration) ? currentBook.duration : 1;
        }
        return parseInt(document.getElementById('editSlots')?.value) || 1;
    }

    function applyCertPricing(prefix) {
        const cert = selectedCert[prefix];
        const priceEl = document.getElementById(prefix === 'book' ? 'bookCustomPrice' : 'editCustomPrice');
        const discEl = document.getElementById(prefix === 'book' ? 'bookDiscount' : 'editDiscount');
        const hint = document.getElementById(prefix + 'CertHint');
        if (!cert || !priceEl || !discEl) return;
        const price = parseInt(priceEl.value) || 0;
        if (cert.value_type === 'hours') {
            // Сертификат на часы: покрываем только часы сертификата, остальное платно.
            const bookingHours = certBookingHours(prefix);
            const certHours = parseInt(cert.hours) || 0;
            const covered = Math.min(certHours, bookingHours);
            const perHour = bookingHours > 0 ? price / bookingHours : 0;
            const disc = Math.min(price, Math.round(perHour * covered));
            discEl.value = disc;
            if (hint) {
                hint.textContent = covered >= bookingHours
                    ? 'Бесплатно по сертификату (' + certHours + ' ч) — итог 0 ₸.'
                    : 'Сертификат покрывает ' + covered + ' ч из ' + bookingHours + ' — остальное платно.';
                hint.style.display = '';
            }
        } else {
            discEl.value = Math.min(cert.amount || 0, price); // номинал, но не больше цены
            if (hint) { hint.textContent = 'Скидка по сертификату: ' + (cert.amount || 0) + ' ₸.'; hint.style.display = ''; }
        }
        if (prefix === 'book' && typeof updateFinalPrice === 'function') updateFinalPrice();
        if (prefix === 'edit' && typeof updateEditFinalPrice === 'function') updateEditFinalPrice();
    }

    // ====== Группы (идентично дневному виду) ======
    // Заморожен ли участник на дату (YYYY-MM-DD) — вернёт дату «до» в DD.MM.YY или null.
    function gmFrozenUntil(m, dateStr) {
        if (!m.freezes || !dateStr) return null;
        for (var i = 0; i < m.freezes.length; i++) {
            var f = m.freezes[i];
            if (dateStr >= f.from && dateStr <= f.until) {
                var p = String(f.until).split('-');
                return p.length === 3 ? (p[2] + '.' + p[1] + '.' + p[0].slice(2)) : f.until;
            }
        }
        return null;
    }
    function renderGroupMembers(groupId) {
        const block = document.getElementById('groupMembersBlock');
        const list = document.getElementById('gmList');
        const empty = document.getElementById('gmEmpty');
        const count = document.getElementById('gmCount');
        const coachBlock = document.getElementById('groupCoachBlock');
        const coachSelect = document.getElementById('bookGroupCoachSelect');
        const coachHint = document.getElementById('bookGroupCoachHint');
        const coachIdInput = document.getElementById('bookCoachId');
        const priceEl = document.getElementById('gmPrice');
        const schedLink = document.getElementById('gmScheduleLink');
        if (!block || !list) return;
        if (!groupId) {
            block.style.display = 'none';
            list.innerHTML = '';
            if (coachBlock) coachBlock.style.display = 'none';
            if (coachSelect) coachSelect.value = '';
            if (coachIdInput) coachIdInput.value = '';
            if (priceEl) priceEl.style.display = 'none';
            if (schedLink) schedLink.style.display = 'none';
            return;
        }
        if (schedLink) {
            schedLink.href = '{{ url('club/groups') }}/' + groupId + '/schedule';
            schedLink.style.display = 'flex';
        }
        const data = (window.__groupMembers && window.__groupMembers[groupId]) || { coach_id: null, members: [] };
        if (priceEl) {
            const p = Number(data.price || 0);
            const cnt = (data.members || []).length;
            const fmt = n => new Intl.NumberFormat('ru-RU').format(n);
            priceEl.style.display = 'block';
            priceEl.innerHTML = p > 0
                ? 'Цена занятия: <b style="color:#22c55e;">' + fmt(p * cnt) + ' ₸</b> <span style="color:#71717a;">(' + fmt(p) + ' ₸ × ' + cnt + ')</span>'
                : '<span style="color:#71717a;">Цена занятия не задана в группе</span>';
        }
        const members = data.members || [];
        block.style.display = 'block';
        list.innerHTML = '';
        count.textContent = members.length ? members.length : '';
        if (members.length === 0) {
            empty.style.display = 'block';
        } else {
            empty.style.display = 'none';
            members.forEach(m => {
                const li = document.createElement('li');
                li.className = 'gm-item';
                const remaining = m.remaining;
                const lowClass = remaining <= 0 ? 'gm-rem-zero' : (remaining <= 2 ? 'gm-rem-low' : 'gm-rem-ok');
                const word = remaining === 1 ? 'занятие' : (remaining >= 2 && remaining <= 4 ? 'занятия' : 'занятий');
                const bd = document.getElementById('bookDate');
                const bdVal = bd ? bd.value : '';
                const fzUntil = gmFrozenUntil(m, bdVal);
                const frozen = fzUntil ? '<span class="gm-frozen">❄ заморожен до ' + fzUntil + '</span>' : '';
                const sa = m.starts_at;
                const notStarted = (sa && bdVal && bdVal < sa)
                    ? '<span class="gm-frozen" style="background:rgba(106,164,245,.14);color:#6aa4f5;">начнёт с ' + sa.slice(8,10) + '.' + sa.slice(5,7) + '.' + sa.slice(2,4) + '</span>'
                    : '';
                const note = m.note
                    ? '<span class="gm-mnote" style="flex-basis:100%;color:#71717a;font-size:11.5px;"><i class="bi bi-chat-square-text" style="font-size:10px;"></i> ' + escapeHtml(m.note) + '</span>'
                    : '';
                li.innerHTML = '<span class="gm-name">' + escapeHtml(m.name) + '</span>' + frozen + notStarted +
                    '<span class="gm-rem ' + lowClass + '">' + remaining + ' ' + word + '</span>' + note;
                if (fzUntil) li.classList.add('gm-item-frozen');
                list.appendChild(li);
            });
        }
        if (coachBlock && coachSelect && coachIdInput) {
            coachBlock.style.display = 'block';
            const defaultCoach = data.coach_id ? String(data.coach_id) : '';
            coachSelect.value = defaultCoach;
            coachIdInput.value = defaultCoach;
            // Занятые тренеры (ведут другое занятие / не работают) — серым и недоступны.
            const cb = (typeof currentBook === 'object' && currentBook) ? currentBook : {};
            markGroupCoachAvailabilityWeek(coachSelect, cb.date, cb.time, defaultCoach);
            if (coachHint) coachHint.style.display = defaultCoach ? 'block' : 'none';
        }
    }
    // Доступность тренеров в недельном виде: coachAvailability[date][coachId][time].
    function markGroupCoachAvailabilityWeek(selectEl, date, time, currentCoachId) {
        if (!selectEl) return;
        const dayAvail = (typeof coachAvailability === 'object' && coachAvailability && coachAvailability[date]) ? coachAvailability[date] : {};
        Array.from(selectEl.options).forEach(function (opt) {
            opt.textContent = opt.textContent.replace(/\s+—\s+занят$/, '');
            opt.disabled = false;
            if (!opt.value) return;
            const isCurrent = String(opt.value) === String(currentCoachId || '');
            const free = isCurrent || (dayAvail[opt.value] && dayAvail[opt.value][time]);
            if (!free) { opt.disabled = true; opt.textContent = opt.textContent + ' — занят'; }
        });
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function applyEditGroupVisibility(isGroup) {
        document.querySelectorAll('.js-edit-hide-for-group').forEach(function (el) {
            el.style.display = isGroup ? 'none' : '';
        });
        const phone = document.getElementById('editClientPhone');
        if (phone) { phone.required = !isGroup; }
        const name = document.getElementById('editClientName');
        if (name) { name.readOnly = isGroup; }
        const label = document.getElementById('editClientLabel');
        if (label) {
            label.textContent = isGroup ? 'Группа' : 'Напишите имя и фамилию клиента *';
        }
        const groupBlock = document.getElementById('editGroupBlock');
        if (groupBlock) groupBlock.style.display = isGroup ? 'block' : 'none';
        if (isGroup) {
            const cp = document.getElementById('editCoachPaidGroup');
            if (cp) cp.style.display = 'none';
        }
    }

    function renderEditGroup(data) {
        const coachEl = document.getElementById('editGroupCoach');
        if (coachEl) {
            coachEl.textContent = (window.__coachNames && window.__coachNames[data.coachId]) || '— без тренера —';
        }
        const list = document.getElementById('editGmList');
        const empty = document.getElementById('editGmEmpty');
        const count = document.getElementById('editGmCount');
        if (!list) return;
        list.innerHTML = '';
        const g = (window.__groupMembers && data.groupId && window.__groupMembers[data.groupId]) || null;
        const members = g ? (g.members || []) : [];
        const schedLink = document.getElementById('editGmScheduleLink');
        if (schedLink) {
            if (data.groupId) {
                schedLink.href = '{{ url('club/groups') }}/' + data.groupId + '/schedule';
                schedLink.style.display = 'flex';
            } else {
                schedLink.style.display = 'none';
            }
        }
        const priceEl = document.getElementById('editGmPrice');
        if (priceEl) {
            const p = Number((g && g.price) || 0);
            const cnt = members.length;
            const fmt = n => new Intl.NumberFormat('ru-RU').format(n);
            priceEl.style.display = 'block';
            priceEl.innerHTML = p > 0
                ? 'Цена занятия: <b style="color:#22c55e;">' + fmt(p * cnt) + ' ₸</b> <span style="color:#71717a;">(' + fmt(p) + ' ₸ × ' + cnt + ')</span>'
                : '<span style="color:#71717a;">Цена занятия не задана в группе</span>';
        }
        if (count) count.textContent = members.length ? members.length : '';
        if (!members.length) {
            if (empty) empty.style.display = 'block';
        } else {
            if (empty) empty.style.display = 'none';
            members.forEach(function (m) {
                const li = document.createElement('li');
                li.className = 'gm-item';
                const remaining = m.remaining;
                const lowClass = remaining <= 0 ? 'gm-rem-zero' : (remaining <= 2 ? 'gm-rem-low' : 'gm-rem-ok');
                const word = remaining === 1 ? 'занятие' : (remaining >= 2 && remaining <= 4 ? 'занятия' : 'занятий');
                const fzUntil = gmFrozenUntil(m, data.date || '');
                const frozen = fzUntil ? '<span class="gm-frozen">❄ заморожен до ' + fzUntil + '</span>' : '';
                const sa2 = m.starts_at;
                const notStarted = (sa2 && data.date && data.date < sa2)
                    ? '<span class="gm-frozen" style="background:rgba(106,164,245,.14);color:#6aa4f5;">начнёт с ' + sa2.slice(8,10) + '.' + sa2.slice(5,7) + '.' + sa2.slice(2,4) + '</span>'
                    : '';
                const note = m.note
                    ? '<span class="gm-mnote" style="flex-basis:100%;color:#71717a;font-size:11.5px;"><i class="bi bi-chat-square-text" style="font-size:10px;"></i> ' + escapeHtml(m.note) + '</span>'
                    : '';
                li.innerHTML = '<span class="gm-name">' + escapeHtml(m.name) + '</span>' + frozen + notStarted +
                    '<span class="gm-rem ' + lowClass + '">' + remaining + ' ' + word + '</span>' + note;
                if (fzUntil) li.classList.add('gm-item-frozen');
                list.appendChild(li);
            });
        }
    }

    // Валидация формы бронирования: имя+фамилия, способ оплаты, статус оплаты
    function showBookFormError(message, targetEl) {
        const errorBox = document.getElementById('bookFormError');
        if (!errorBox) return;
        errorBox.querySelector('.book-form-error-text').textContent = message;
        errorBox.classList.add('is-visible');

        document.querySelectorAll('#bookForm .has-error').forEach(el => el.classList.remove('has-error'));
        if (targetEl) {
            targetEl.classList.add('has-error');
            try { targetEl.scrollIntoView({behavior: 'smooth', block: 'center'}); } catch (_) {}
            if (targetEl.tagName === 'INPUT') {
                setTimeout(() => targetEl.focus({preventScroll: true}), 200);
            }
        }
    }

    function clearBookFormError() {
        const errorBox = document.getElementById('bookFormError');
        if (errorBox) errorBox.classList.remove('is-visible');
        document.querySelectorAll('#bookForm .has-error').forEach(el => el.classList.remove('has-error'));
    }

    document.getElementById('bookForm').addEventListener('submit', function(e) {
        const form = e.target;
        const bookingType = document.getElementById('bookingTypeInput').value;
        const isGroup = bookingType === 'group';
        const nameInput = form.querySelector('input[name="client_name"]');
        const phoneInput = form.querySelector('input[name="client_phone"]');
        const paymentInput = form.querySelector('input[name="payment_method"]');
        const paidInput = form.querySelector('input[name="is_paid"]');
        const paymentGroup = document.getElementById('paymentMethods');
        const paidGroup = document.getElementById('isPaidInput').parentElement.querySelector('.paid-toggle');

        // Для групповой брони поля клиента/оплаты не нужны — пропускаем эти проверки.
        if (!isGroup) {
            const words = (nameInput.value || '').trim().split(/\s+/).filter(Boolean);
            if (words.length < 2) {
                e.preventDefault();
                showBookFormError('Укажите имя и фамилию клиента (например: «Денис Дудников»)', nameInput);
                return;
            }
            if (!(phoneInput.value || '').trim()) {
                e.preventDefault();
                showBookFormError('Укажите номер телефона клиента', phoneInput);
                return;
            }
            if (!paymentInput.value) {
                e.preventDefault();
                showBookFormError('Выберите способ оплаты', paymentGroup);
                return;
            }
            if (paymentInput.value === 'club_card' && !(document.getElementById('bookCardInput').value || '').trim()) {
                e.preventDefault();
                showBookFormError('Выберите клубную карту', document.getElementById('bookCardButtons'));
                return;
            }
            if (paidInput.value === '') {
                e.preventDefault();
                showBookFormError('Выберите статус оплаты: «Оплачено» или «Не оплачено»', paidGroup);
                return;
            }
        }
        // Статус оплаты тренера обязателен только для разовых броней и только для
        // старого одиночного поля (видимо). В мультитренере оплата — чекбокс в строке.
        if (!isGroup) {
            const coachId = document.getElementById('bookCoachId').value;
            const coachPaid = document.getElementById('bookCoachPaidInput').value;
            const legacyBookPaid = document.getElementById('bookCoachPaidGroup');
            const legacyBookPaidVisible = legacyBookPaid && legacyBookPaid.style.display !== 'none';
            if (coachId && legacyBookPaidVisible && coachPaid === '') {
                e.preventDefault();
                showBookFormError('Выберите статус оплаты тренера: «Оплачен» или «Не оплачен»', document.querySelector('#bookCoachPaidGroup .paid-toggle'));
                return;
            }
        }
        clearBookFormError();
    });

    document.getElementById('bookForm').addEventListener('input', clearBookFormError);
    document.getElementById('bookForm').addEventListener('click', function(e) {
        if (e.target.closest('.pay-btn, .paid-btn')) clearBookFormError();
    });

    function updateCoachButtons() {
        const dayCoaches = (coachAvailability[currentBook.date] || {});
        document.querySelectorAll('#bookCoachButtons .coach-btn').forEach(btn => {
            const coachId = btn.getAttribute('data-coach-id');
            const available = dayCoaches[coachId] && dayCoaches[coachId][currentBook.time];
            btn.classList.remove('active', 'unavailable');
            if (!available) btn.classList.add('unavailable');
        });
        document.getElementById('bookCoachId').value = '';
        const sc = document.getElementById('bookSelectedCoaches');
        if (sc) sc.innerHTML = '';
    }

    // Аватар тренера берём из его кнопки выбора (там уже фото или инициал).
    function coachAvatarHtml(coachId) {
        const el = document.querySelector('.coach-btn[data-coach-id="' + coachId + '"] .coach-btn-avatar');
        return '<span class="sel-coach-avatar">' + (el ? el.innerHTML : '') + '</span>';
    }
    // Мультивыбор тренеров (спарринг): одна карточка-рамка на тренера —
    // аватар + имя + цена + переключатель «Не оплачен / Оплачен» под ним.
    function coachRowHtml(coachId, name, price, paid) {
        const priceVal = (price === '' || price === null || price === undefined) ? '' : price;
        return '<div class="sel-coach-row" data-cid="' + coachId + '">' +
            '<div class="sel-coach-head">' +
                coachAvatarHtml(coachId) +
                '<span class="sel-coach-name">' + escapeHtml(name) + '</span>' +
                '<input type="number" class="sel-coach-price" name="coaches[' + coachId + '][price]" min="0" step="100" placeholder="цена ₸" value="' + priceVal + '">' +
            '</div>' +
            '<input type="hidden" name="coaches[' + coachId + '][coach_id]" value="' + coachId + '">' +
            '<input type="checkbox" class="sel-coach-paid-cb" name="coaches[' + coachId + '][paid]" value="1"' + (paid ? ' checked' : '') + ' hidden>' +
            '<div class="sel-coach-paidtoggle">' +
                '<button type="button" class="scp-btn scp-unpaid' + (paid ? '' : ' active') + '" onclick="setCoachRowPaid(this,0)">Не оплачен</button>' +
                '<button type="button" class="scp-btn scp-paid' + (paid ? ' active' : '') + '" onclick="setCoachRowPaid(this,1)">Оплачен</button>' +
            '</div>' +
        '</div>';
    }
    function bookCoachRowHtml(coachId, name, price) {
        return coachRowHtml(coachId, name, price, false);
    }
    // Переключение статуса оплаты в строке тренера (двигает скрытый чекбокс).
    function setCoachRowPaid(btn, val) {
        const row = btn.closest('.sel-coach-row');
        if (!row) return;
        const cb = row.querySelector('.sel-coach-paid-cb');
        if (cb) cb.checked = (val === 1);
        row.querySelectorAll('.scp-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }
    function selectBookCoach(btn) {
        if (btn.classList.contains('unavailable')) return;
        const coachId = btn.getAttribute('data-coach-id');
        const container = document.getElementById('bookSelectedCoaches');
        const existing = container.querySelector('.sel-coach-row[data-cid="' + coachId + '"]');
        if (existing) {
            existing.remove();
            btn.classList.remove('active');
        } else {
            btn.classList.add('active');
            const nameEl = btn.querySelector('.coach-btn-name');
            const name = nameEl ? nameEl.textContent : '';
            const rate = parseFloat(btn.getAttribute('data-rate')) || 0;
            const dur = (typeof currentBook === 'object' && currentBook.duration) ? currentBook.duration : 1;
            const price = rate > 0 ? Math.round(rate * dur) : '';
            container.insertAdjacentHTML('beforeend', bookCoachRowHtml(coachId, name, price));
        }
        const first = container.querySelector('.sel-coach-row');
        document.getElementById('bookCoachId').value = first ? first.getAttribute('data-cid') : '';
        updateFinalPrice();
    }
    function setBookCoachPaid(btn) {
        document.querySelectorAll('#bookCoachPaidGroup .paid-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('bookCoachPaidInput').value = btn.getAttribute('data-value');
    }

    function editCoachRowHtml(coachId, name, price, paid) {
        return coachRowHtml(coachId, name, price, !!paid);
    }
    function selectEditCoach(btn) {
        if (btn.classList.contains('unavailable')) return;
        const coachId = btn.getAttribute('data-coach-id');
        const container = document.getElementById('editSelectedCoaches');
        const existing = container.querySelector('.sel-coach-row[data-cid="' + coachId + '"]');
        if (existing) {
            existing.remove();
            btn.classList.remove('active');
        } else {
            btn.classList.add('active');
            const nameEl = btn.querySelector('.coach-btn-name');
            const name = nameEl ? nameEl.textContent : '';
            const rate = parseFloat(btn.getAttribute('data-rate')) || 0;
            const slots = parseInt(document.getElementById('editSlots')?.value) || 1;
            const price = rate > 0 ? Math.round(rate * slots) : '';
            container.insertAdjacentHTML('beforeend', editCoachRowHtml(coachId, name, price, false));
        }
        const first = container.querySelector('.sel-coach-row');
        document.getElementById('editCoachId').value = first ? first.getAttribute('data-cid') : '';
        updateEditFinalPrice();
    }
    function setEditCoachPaid(btn) {
        document.querySelectorAll('#editCoachPaidGroup .paid-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('editCoachPaidInput').value = btn.getAttribute('data-value');
    }

    // === Polling новых заявок + звуковой сигнал + live-бейджи ===
    (function() {
        const weekDates = @json(collect($weekDays)->pluck('date'));
        // Стартовое состояние из server-side render (сразу можно сравнивать)
        const initialUnprocessed = @json(collect($weekDays)->mapWithKeys(fn($wd) => [$wd['date'] => (int) ($wd['unprocessed'] ?? 0)]));
        let lastByDate = initialUnprocessed;
        let pendingReload = false;

        // Звук "ding" — AudioContext создаём ОДИН раз, разблокируем
        // на первом клике пользователя по странице (autoplay policy браузеров).
        let audioCtx = null;

        function ensureAudioContext() {
            if (!audioCtx) {
                try {
                    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                } catch (e) { return null; }
            }
            return audioCtx;
        }

        // Любой клик на странице снимает блокировку аудио
        document.addEventListener('click', function unlockAudio() {
            const ctx = ensureAudioContext();
            if (ctx && ctx.state === 'suspended') ctx.resume();
        }, { capture: true });

        // Громкий двойной "пинг" — на НОВУЮ заявку
        function playDingNow(ctx) {
            const now = ctx.currentTime;
            [880, 1320].forEach((freq, i) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                const start = now + i * 0.12;
                gain.gain.setValueAtTime(0, start);
                gain.gain.linearRampToValueAtTime(0.30, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, start + 0.30);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(start);
                osc.stop(start + 0.32);
            });
        }

        // Тихий одиночный "пик" — напоминание, что есть необработанные
        function playReminderNow(ctx) {
            const now = ctx.currentTime;
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 700;
            gain.gain.setValueAtTime(0, now);
            gain.gain.linearRampToValueAtTime(0.10, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.18);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(now);
            osc.stop(now + 0.20);
        }

        function playSound(kind) {
            const ctx = ensureAudioContext();
            if (!ctx) return;
            const fn = kind === 'reminder' ? playReminderNow : playDingNow;
            if (ctx.state === 'suspended') {
                ctx.resume().then(() => fn(ctx)).catch(() => {});
            } else {
                fn(ctx);
            }
        }

        function playDing() { playSound('new'); }
        function playReminder() { playSound('reminder'); }

        // Флэш заголовка вкладки — надёжный визуальный фолбэк, работает ВСЕГДА.
        // Пользователь видит "(2) Расписание..." даже если звук заблокирован браузером.
        const originalTitle = document.title;
        let titleFlashTimer = null;
        function flashTitle(count) {
            clearInterval(titleFlashTimer);
            const flashed = `(${count}) 🔔 Новая заявка!`;
            let toggle = false;
            document.title = flashed;
            titleFlashTimer = setInterval(() => {
                document.title = toggle ? flashed : originalTitle;
                toggle = !toggle;
            }, 1200);

            // Останавливаем мигание при возврате на вкладку
            const onFocus = () => {
                clearInterval(titleFlashTimer);
                document.title = originalTitle;
                window.removeEventListener('focus', onFocus);
                document.removeEventListener('visibilitychange', onVis);
            };
            const onVis = () => { if (!document.hidden) onFocus(); };
            window.addEventListener('focus', onFocus);
            document.addEventListener('visibilitychange', onVis);
        }

        // Обновить бейдж конкретного дня
        function updateDayBadge(date, count) {
            const badge = document.querySelector(`[data-unprocessed-badge="${date}"]`);
            if (!badge) return;
            const counter = badge.querySelector('.badge-count');
            if (count > 0) {
                if (counter) counter.textContent = count;
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        }

        // Обновить счётчик на верхней кнопке «Необработанные»
        function updateTopButton(total) {
            const btn = document.getElementById('unprocessedPanelBtn');
            const cnt = document.getElementById('unprocessedBtnCount');
            if (!btn || !cnt) return;
            if (total > 0) {
                cnt.textContent = total;
                btn.style.display = '';
            } else {
                btn.style.display = 'none';
            }
        }

        function pollNew() {
            fetch('{{ route("club.unprocessedCount") }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
                .then(r => r.ok ? r.json() : null)
                .then(data => {
                    if (!data) return;
                    const byDate = data.by_date || {};

                    let hasNew = false;
                    for (const d of weekDates) {
                        const cur = byDate[d] || 0;
                        const prev = lastByDate[d] || 0;
                        if (cur > prev) hasNew = true;
                        // Live-обновление бейджа без перезагрузки
                        updateDayBadge(d, cur);
                    }
                    lastByDate = byDate;

                    // Обновляем общую кнопку (сумма по всему клубу из API)
                    updateTopButton(parseInt(data.count) || 0);

                    const totalCount = parseInt(data.count) || 0;

                    if (hasNew) {
                        // Громкий звук + флэш + reload только если есть НОВАЯ заявка
                        playDing();
                        flashTitle(totalCount);

                        const modalOpen = document.querySelector('.modal.show');
                        if (modalOpen) {
                            pendingReload = true;
                        } else {
                            setTimeout(() => window.location.reload(), 1200);
                        }
                    } else if (totalCount > 0) {
                        // Новых нет, но есть висящие необработанные — тихий "пик" напоминание
                        playReminder();
                    }
                })
                .catch(() => {});
        }

        // Если модалка закрылась и был отложенный reload — выполнить его
        document.addEventListener('hidden.bs.modal', function() {
            if (pendingReload && !document.querySelector('.modal.show')) {
                window.location.reload();
            }
        });

        setInterval(pollNew, 30000);
    })();

    // Autocomplete (имя/телефон клиентов)
    (function() {
        const searchUrl = @json(route('club.clients.search'));
        let debounceTimer = null;

        function setupAutocomplete(inputId, listId, field, pairedInputId) {
            const input = document.getElementById(inputId);
            const list = document.getElementById(listId);
            if (!input || !list) return;

            input.addEventListener('input', function() {
                const q = this.value.trim();
                clearTimeout(debounceTimer);
                if (q.length < 1) { list.classList.remove('show'); return; }
                debounceTimer = setTimeout(() => {
                    fetch(searchUrl + '?q=' + encodeURIComponent(q) + '&field=' + field)
                        .then(r => r.json())
                        .then(clients => {
                            if (!clients.length) { list.classList.remove('show'); return; }
                            list.innerHTML = clients.map(c =>
                                '<div class="autocomplete-item" data-name="' + (c.name || '').replace(/"/g, '&quot;') + '" data-phone="' + formatPhone(c.phone).replace(/"/g, '&quot;') + '" data-note="' + (c.note || '').replace(/"/g, '&quot;') + '">' +
                                '<span class="autocomplete-item-name">' + escHtml(c.name) + '</span>' +
                                '<span class="autocomplete-item-phone">' + escHtml(formatPhone(c.phone)) + '</span>' +
                                '</div>'
                            ).join('');
                            list.classList.add('show');
                            list.querySelectorAll('.autocomplete-item').forEach(item => {
                                item.addEventListener('click', function() {
                                    input.value = this.dataset[field];
                                    const paired = document.getElementById(pairedInputId);
                                    if (paired) paired.value = this.dataset[field === 'name' ? 'phone' : 'name'];
                                    // Существующий клиент — лочим имя+заметку (book или edit)
                                    const isBook = (inputId === 'bookClientName' || inputId === 'bookClientPhone');
                                    const isEdit = (inputId === 'editClientName' || inputId === 'editClientPhone');
                                    if (isBook || isEdit) {
                                        lockClientFields(isBook ? 'book' : 'edit', this.dataset.note || '');
                                        const ph = this.dataset.phone || '';
                                        loadClientCards(isBook ? 'book' : 'edit', ph, null);
                                    }
                                    list.classList.remove('show');
                                });
                            });
                        });
                }, 150);
            });
            input.addEventListener('blur', () => setTimeout(() => list.classList.remove('show'), 200));
        }

        function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
        function formatPhone(p) { return p ? '+' + p.replace(/\D/g, '') : ''; }

        setupAutocomplete('bookClientName', 'bookNameList', 'name', 'bookClientPhone');
        setupAutocomplete('bookClientPhone', 'bookPhoneList', 'phone', 'bookClientName');
        setupAutocomplete('editClientName', 'editNameList', 'name', 'editClientPhone');
        setupAutocomplete('editClientPhone', 'editPhoneList', 'phone', 'editClientName');

        // Ручная правка телефона — клиент уже не «выбран из базы», разлочим имя
        // и сбросим подгруженную заметку (это новый клиент / новые данные).
        const bookPhoneEl = document.getElementById('bookClientPhone');
        if (bookPhoneEl) {
            bookPhoneEl.addEventListener('input', function() {
                const nameInput = document.getElementById('bookClientName');
                if (nameInput && nameInput.hasAttribute('readonly')) {
                    nameInput.removeAttribute('readonly');
                    nameInput.classList.remove('is-locked');
                    nameInput.removeAttribute('title');
                }
                const nameHint = document.getElementById('bookClientNameHint');
                if (nameHint) nameHint.style.display = 'none';
                const noteInput = document.getElementById('bookClientNote');
                const noteHint = document.getElementById('bookClientNoteHint');
                if (noteInput) {
                    noteInput.value = '';
                    noteInput.removeAttribute('readonly');
                    noteInput.classList.remove('is-locked');
                    noteInput.removeAttribute('title');
                }
                if (noteHint) noteHint.style.display = 'none';
                // Подгрузка карт клиента по введённому телефону (с дебаунсом).
                clearTimeout(cardLoadTimer);
                cardLoadTimer = setTimeout(() => loadClientCards('book', this.value, null), 400);
            });
        }
    })();
</script>

@include('club.courts._booking_tiles_css')
@endsection

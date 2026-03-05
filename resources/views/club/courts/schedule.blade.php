@extends('layouts.app')
@section('title', 'Расписание — ' . $currentCourt->name)
@section('content')

<div class="schedule-container">
    <!-- Header -->
    <header class="schedule-header">
        <div class="schedule-title-block">
            <a href="{{ route('club.courts.index') }}" class="back-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
            <h1 class="schedule-title">Расписание</h1>
        </div>

        <!-- Week Navigation -->
        <div class="week-nav">
            <a href="{{ route('club.courts.schedule', ['court_id' => $currentCourt->id, 'week_start' => $weekStart->copy()->subWeek()->format('Y-m-d')]) }}" class="week-nav-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
            <span class="week-label">{{ $weekStart->format('d.m') }} — {{ $weekEnd->format('d.m.Y') }}</span>
            <a href="{{ route('club.courts.schedule', ['court_id' => $currentCourt->id, 'week_start' => $weekStart->copy()->addWeek()->format('Y-m-d')]) }}" class="week-nav-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        </div>
    </header>

    <!-- Flash Messages -->
    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    <!-- Court Tabs -->
    @if($courts->count() > 1)
    <div class="court-tabs">
        @foreach($courts as $court)
            <a href="{{ route('club.courts.schedule', ['court_id' => $court->id, 'week_start' => $weekStart->format('Y-m-d')]) }}"
               class="court-tab {{ $court->id === $currentCourt->id ? 'active' : '' }}">
                {{ $court->name }}
            </a>
        @endforeach
    </div>
    @else
    <div class="court-single">{{ $currentCourt->name }}</div>
    @endif

    <!-- Schedule Grid -->
    <div class="schedule-grid-wrap">
        <table class="schedule-grid">
            <thead>
                <tr>
                    <th class="time-col">Время</th>
                    @php
                        $dayNames = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
                    @endphp
                    @for($i = 0; $i < 7; $i++)
                        @php
                            $day = $weekStart->copy()->addDays($i);
                            $isToday = $day->isToday();
                            $isWeekend = $i >= 5;
                        @endphp
                        <th class="day-col {{ $isToday ? 'today' : '' }} {{ $isWeekend ? 'weekend' : '' }}">
                            <span class="day-name">{{ $dayNames[$i] }}</span>
                            <span class="day-date">{{ $day->format('d.m') }}</span>
                        </th>
                    @endfor
                </tr>
            </thead>
            <tbody>
                @forelse($timeSlots as $timeSlot)
                    <tr>
                        <td class="time-cell">{{ $timeSlot['start'] }}<br><small>{{ $timeSlot['end'] }}</small></td>
                        @for($i = 0; $i < 7; $i++)
                            @php
                                $day = $weekStart->copy()->addDays($i);
                                $dateKey = $day->format('Y-m-d');
                                $daySlots = $slotsByDate->get($dateKey, collect());
                                $slot = $daySlots->first(function($s) use ($timeSlot) {
                                    return \Carbon\Carbon::parse($s->start_time)->format('H:i') === $timeSlot['start']
                                        && \Carbon\Carbon::parse($s->end_time)->format('H:i') === $timeSlot['end'];
                                });
                                $isToday = $day->isToday();
                            @endphp
                            <td class="slot-cell {{ $isToday ? 'today' : '' }}">
                                @if($slot)
                                    @if($slot->isBooked())
                                        <div class="slot-block slot-booked" title="Забронирован — {{ number_format($slot->price, 0, '', ' ') }} ₸">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                            <span class="slot-price-mini">{{ number_format($slot->price, 0, '', ' ') }}</span>
                                        </div>
                                    @elseif($slot->status === 'blocked')
                                        <form action="{{ route('club.courts.toggleSlotBlock', $slot) }}" method="POST" class="slot-form">
                                            @csrf
                                            <button type="submit" class="slot-block slot-blocked" title="Заблокирован — нажмите чтобы разблокировать">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                                <span class="slot-price-mini">{{ number_format($slot->price, 0, '', ' ') }}</span>
                                            </button>
                                        </form>
                                    @else
                                        <form action="{{ route('club.courts.toggleSlotBlock', $slot) }}" method="POST" class="slot-form">
                                            @csrf
                                            <button type="submit" class="slot-block slot-available" title="Доступен — {{ number_format($slot->price, 0, '', ' ') }} ₸ — нажмите чтобы заблокировать">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="9 12 12 15 16 10"/></svg>
                                                <span class="slot-price-mini">{{ number_format($slot->price, 0, '', ' ') }}</span>
                                            </button>
                                        </form>
                                    @endif
                                @else
                                    <div class="slot-block slot-empty" title="Нет слота">—</div>
                                @endif
                            </td>
                        @endfor
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="empty-message">
                            Нет слотов на эту неделю.
                            <a href="{{ route('club.courts.slots', $currentCourt) }}">Сгенерировать слоты</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Legend -->
    <div class="schedule-legend">
        <div class="legend-item"><span class="legend-dot available"></span>Доступен</div>
        <div class="legend-item"><span class="legend-dot booked"></span>Забронирован</div>
        <div class="legend-item"><span class="legend-dot blocked"></span>Заблокирован</div>
        <div class="legend-item"><span class="legend-dot empty"></span>Нет слота</div>
    </div>
</div>

<style>
    :root {
        --slots-bg: #0a0a0b;
        --slots-bg-secondary: #111113;
        --slots-card: #16161a;
        --slots-card-hover: #1c1c21;
        --slots-accent: #22c55e;
        --slots-accent-dark: #16a34a;
        --slots-blue: #3b82f6;
        --slots-text: #f4f4f5;
        --slots-text-dim: #a1a1aa;
        --slots-text-muted: #71717a;
        --slots-border: #27272a;
        --slots-border-light: #3f3f46;
        --slots-yellow: #facc15;
        --slots-red: #ef4444;
        --slots-orange: #f97316;
    }

    .schedule-container {
        width: 100%;
        padding: 32px 40px;
    }

    /* Header */
    .schedule-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 28px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .schedule-title-block {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .back-link {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--slots-card);
        border: 1px solid var(--slots-border);
        border-radius: 10px;
        color: var(--slots-text-dim);
        transition: all 0.2s;
        text-decoration: none;
    }

    .back-link:hover {
        border-color: var(--slots-accent);
        color: var(--slots-accent);
    }

    .back-link svg {
        width: 20px;
        height: 20px;
    }

    .schedule-title {
        font-size: 26px;
        font-weight: 800;
        letter-spacing: -0.5px;
    }

    /* Week Navigation */
    .week-nav {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .week-nav-btn {
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--slots-card);
        border: 1px solid var(--slots-border);
        border-radius: 10px;
        color: var(--slots-text-dim);
        text-decoration: none;
        transition: all 0.2s;
    }

    .week-nav-btn:hover {
        border-color: var(--slots-accent);
        color: var(--slots-accent);
    }

    .week-nav-btn svg {
        width: 18px;
        height: 18px;
    }

    .week-label {
        font-size: 16px;
        font-weight: 700;
        color: var(--slots-text);
        min-width: 180px;
        text-align: center;
    }

    /* Flash Messages */
    .flash-message {
        padding: 14px 20px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 24px;
    }

    .flash-success {
        background: rgba(34, 197, 94, 0.15);
        color: var(--slots-accent);
        border: 1px solid rgba(34, 197, 94, 0.3);
    }

    .flash-error {
        background: rgba(239, 68, 68, 0.15);
        color: var(--slots-red);
        border: 1px solid rgba(239, 68, 68, 0.3);
    }

    /* Court Tabs */
    .court-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }

    .court-tab {
        padding: 10px 22px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 700;
        color: var(--slots-text-dim);
        background: var(--slots-card);
        border: 1px solid var(--slots-border);
        text-decoration: none;
        transition: all 0.2s;
    }

    .court-tab:hover {
        border-color: var(--slots-accent);
        color: var(--slots-text);
    }

    .court-tab.active {
        background: var(--slots-accent);
        color: var(--slots-bg);
        border-color: var(--slots-accent);
    }

    .court-single {
        font-size: 16px;
        font-weight: 700;
        color: var(--slots-text-dim);
        margin-bottom: 24px;
        padding: 10px 0;
    }

    /* Schedule Grid */
    .schedule-grid-wrap {
        background: var(--slots-bg-secondary);
        border: 1px solid var(--slots-border);
        border-radius: 16px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .schedule-grid {
        width: 100%;
        min-width: 700px;
        border-collapse: collapse;
    }

    .schedule-grid th {
        padding: 14px 8px;
        text-align: center;
        font-size: 13px;
        font-weight: 700;
        color: var(--slots-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: var(--slots-card);
        border-bottom: 1px solid var(--slots-border);
    }

    .schedule-grid th.today {
        color: var(--slots-accent);
    }

    .schedule-grid th.weekend .day-name {
        color: var(--slots-orange);
    }

    .schedule-grid th.time-col {
        width: 80px;
        text-align: left;
        padding-left: 16px;
    }

    .day-name {
        display: block;
        font-size: 14px;
        font-weight: 800;
    }

    .day-date {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: var(--slots-text-muted);
        margin-top: 2px;
    }

    .schedule-grid th.today .day-date {
        color: var(--slots-accent);
    }

    /* Cells */
    .schedule-grid td {
        padding: 4px;
        border-bottom: 1px solid var(--slots-border);
        vertical-align: middle;
        text-align: center;
    }

    .schedule-grid td.time-cell {
        text-align: left;
        padding-left: 16px;
        font-size: 13px;
        font-weight: 700;
        color: var(--slots-text);
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }

    .schedule-grid td.time-cell small {
        color: var(--slots-text-muted);
        font-weight: 600;
        font-size: 11px;
    }

    .schedule-grid td.today {
        background: rgba(34, 197, 94, 0.04);
    }

    .schedule-grid tbody tr:last-child td {
        border-bottom: none;
    }

    /* Slot Blocks */
    .slot-form {
        display: contents;
    }

    .slot-block {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 3px;
        width: 100%;
        min-height: 52px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 700;
        transition: all 0.2s;
        border: none;
        cursor: default;
        padding: 6px 4px;
    }

    .slot-block svg {
        width: 18px;
        height: 18px;
    }

    .slot-price-mini {
        font-size: 10px;
        font-weight: 700;
        opacity: 0.8;
    }

    /* Available */
    .slot-available {
        background: rgba(34, 197, 94, 0.15);
        color: var(--slots-accent);
        cursor: pointer;
        border: 1px solid rgba(34, 197, 94, 0.25);
    }

    .slot-available:hover {
        background: rgba(34, 197, 94, 0.25);
        border-color: var(--slots-accent);
        transform: scale(1.03);
    }

    /* Booked */
    .slot-booked {
        background: rgba(59, 130, 246, 0.15);
        color: var(--slots-blue);
        border: 1px solid rgba(59, 130, 246, 0.25);
    }

    /* Blocked */
    .slot-blocked {
        background: rgba(239, 68, 68, 0.15);
        color: var(--slots-red);
        cursor: pointer;
        border: 1px solid rgba(239, 68, 68, 0.25);
    }

    .slot-blocked:hover {
        background: rgba(239, 68, 68, 0.25);
        border-color: var(--slots-red);
        transform: scale(1.03);
    }

    /* Empty */
    .slot-empty {
        color: var(--slots-text-muted);
        opacity: 0.3;
        font-size: 16px;
        min-height: 52px;
    }

    /* Empty message */
    .empty-message {
        text-align: center;
        padding: 60px 20px !important;
        color: var(--slots-text-muted);
        font-size: 15px;
    }

    .empty-message a {
        color: var(--slots-accent);
        text-decoration: none;
        font-weight: 700;
    }

    .empty-message a:hover {
        text-decoration: underline;
    }

    /* Legend */
    .schedule-legend {
        display: flex;
        gap: 24px;
        margin-top: 20px;
        flex-wrap: wrap;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 600;
        color: var(--slots-text-dim);
    }

    .legend-dot {
        width: 14px;
        height: 14px;
        border-radius: 4px;
    }

    .legend-dot.available {
        background: rgba(34, 197, 94, 0.4);
        border: 1px solid var(--slots-accent);
    }

    .legend-dot.booked {
        background: rgba(59, 130, 246, 0.4);
        border: 1px solid var(--slots-blue);
    }

    .legend-dot.blocked {
        background: rgba(239, 68, 68, 0.4);
        border: 1px solid var(--slots-red);
    }

    .legend-dot.empty {
        background: var(--slots-card);
        border: 1px solid var(--slots-border);
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .schedule-container { padding: 24px 20px; }
    }

    @media (max-width: 768px) {
        .schedule-container { padding: 16px 12px; }
        .schedule-header { flex-direction: column; align-items: flex-start; }
        .week-label { font-size: 14px; min-width: auto; }
        .schedule-grid { min-width: 600px; }
    }
</style>
@endsection

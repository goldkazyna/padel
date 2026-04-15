@extends('layouts.app')
@section('title', 'Журнал действий')
@section('content')

<div class="log-page">

    <!-- HEADER + STATS -->
    <div class="log-header-bar">
        <div class="log-header-left">
            <h1 class="log-title">Журнал — {{ $club->name ?? '' }}</h1>
            <p class="log-subtitle">Все действия в клубе</p>
        </div>
        <div class="log-stats">
            <div class="log-stat">
                <div class="log-stat-num total">{{ $stats['total'] }}</div>
                <div class="log-stat-label">Всего</div>
            </div>
            <div class="log-stat">
                <div class="log-stat-num green">{{ $stats['created'] }}</div>
                <div class="log-stat-label">Создано</div>
            </div>
            <div class="log-stat">
                <div class="log-stat-num blue">{{ $stats['updated'] }}</div>
                <div class="log-stat-label">Изменено</div>
            </div>
            <div class="log-stat">
                <div class="log-stat-num red">{{ $stats['cancelled'] }}</div>
                <div class="log-stat-label">Отменено</div>
            </div>
            <div class="log-stat">
                <div class="log-stat-num orange">{{ $stats['blocked'] }}</div>
                <div class="log-stat-label">Блоки</div>
            </div>
        </div>
    </div>

    <!-- TOOLBAR -->
    <form method="GET" class="log-toolbar" id="logForm">
        <div class="log-search-wrap">
            <i class="bi bi-search"></i>
            <input class="log-search-box" type="text" name="search" value="{{ request('search') }}" placeholder="Поиск по описанию, имени...">
        </div>
        <div class="log-filter-chips">
            <a href="{{ route('club.activityLog', array_merge(request()->except('subject', 'page'), [])) }}"
               class="log-chip {{ !request('subject') ? 'active' : '' }}">Все <span class="log-chip-cnt">{{ $stats['total'] }}</span></a>
            <a href="{{ route('club.activityLog', array_merge(request()->except('page'), ['subject' => 'CourtBooking'])) }}"
               class="log-chip {{ request('subject') === 'CourtBooking' ? 'active' : '' }}"><i class="bi bi-calendar-check"></i> Брони <span class="log-chip-cnt">{{ $subjectCounts['CourtBooking'] }}</span></a>
            <a href="{{ route('club.activityLog', array_merge(request()->except('page'), ['subject' => 'ClubClient'])) }}"
               class="log-chip {{ request('subject') === 'ClubClient' ? 'active' : '' }}"><i class="bi bi-person"></i> Клиенты <span class="log-chip-cnt">{{ $subjectCounts['ClubClient'] }}</span></a>
            <a href="{{ route('club.activityLog', array_merge(request()->except('page'), ['subject' => 'CourtBlock'])) }}"
               class="log-chip {{ request('subject') === 'CourtBlock' ? 'active' : '' }}"><i class="bi bi-lock"></i> Блоки <span class="log-chip-cnt">{{ $subjectCounts['CourtBlock'] }}</span></a>
        </div>
        <select name="action" class="log-filter-select" onchange="document.getElementById('logForm').submit()">
            <option value="">Все действия</option>
            <option value="created" {{ request('action') === 'created' ? 'selected' : '' }}>Создание</option>
            <option value="updated" {{ request('action') === 'updated' ? 'selected' : '' }}>Изменение</option>
            <option value="cancelled" {{ request('action') === 'cancelled' ? 'selected' : '' }}>Отмена</option>
            <option value="blocked" {{ request('action') === 'blocked' ? 'selected' : '' }}>Блокировка</option>
            <option value="unblocked" {{ request('action') === 'unblocked' ? 'selected' : '' }}>Разблокировка</option>
            <option value="deleted" {{ request('action') === 'deleted' ? 'selected' : '' }}>Удаление</option>
        </select>
        <select name="user_id" class="log-filter-select" onchange="document.getElementById('logForm').submit()">
            <option value="">Все пользователи</option>
            @foreach($users as $u)
                <option value="{{ $u->id }}" {{ request('user_id') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
            @endforeach
        </select>
        @if(request('subject'))
            <input type="hidden" name="subject" value="{{ request('subject') }}">
        @endif
    </form>

    <!-- LOGS -->
    @forelse($groupedLogs as $date => $dayLogs)
        @php
            $carbonDate = \Carbon\Carbon::parse($date);
            if ($carbonDate->isToday()) {
                $dayLabel = 'Сегодня, ' . $carbonDate->translatedFormat('j F');
            } elseif ($carbonDate->isYesterday()) {
                $dayLabel = 'Вчера, ' . $carbonDate->translatedFormat('j F');
            } else {
                $dayLabel = $carbonDate->translatedFormat('j F, l');
            }
        @endphp
        <div class="log-day-group">
            <div class="log-day-label">
                {{ $dayLabel }}
                <span class="log-day-count">{{ $dayLogs->count() }} {{ trans_choice('действие|действия|действий', $dayLogs->count()) }}</span>
            </div>

            <table class="log-tbl">
                @if($loop->first)
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Действие</th>
                        <th>Объект</th>
                        <th>Описание</th>
                        <th style="text-align:right;">Автор</th>
                    </tr>
                </thead>
                @endif
                <tbody>
                    @foreach($dayLogs as $log)
                        @php
                            $hasChanges = !empty($log->changes) && is_array($log->changes);
                            $actionLabels = [
                                'created' => 'Создание',
                                'updated' => 'Изменение',
                                'cancelled' => 'Отмена',
                                'blocked' => 'Блокировка',
                                'unblocked' => 'Разблокировка',
                                'deleted' => 'Удаление',
                            ];
                            $subjectLabels = [
                                'CourtBooking' => ['label' => 'Бронь', 'class' => 'booking', 'icon' => 'bi-calendar-check'],
                                'ClubClient' => ['label' => 'Клиент', 'class' => 'client', 'icon' => 'bi-person'],
                                'CourtBlock' => ['label' => 'Блок', 'class' => 'block', 'icon' => 'bi-lock'],
                            ];
                            $subj = $subjectLabels[$log->subject_type] ?? ['label' => $log->subject_type, 'class' => '', 'icon' => 'bi-circle'];
                            $initials = '';
                            if ($log->user) {
                                $parts = explode(' ', $log->user->name ?? '');
                                $initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
                            }
                            $avatarColors = [
                                'created' => ['bg' => '#22c55e', 'color' => '#000'],
                                'updated' => ['bg' => '#3b82f6', 'color' => '#fff'],
                                'cancelled' => ['bg' => '#ef4444', 'color' => '#fff'],
                                'blocked' => ['bg' => '#f59e0b', 'color' => '#000'],
                                'unblocked' => ['bg' => '#a78bfa', 'color' => '#fff'],
                                'deleted' => ['bg' => '#ef4444', 'color' => '#fff'],
                            ];
                            $ac = $avatarColors[$log->action] ?? ['bg' => '#3f3f46', 'color' => '#fff'];
                        @endphp
                        <tr class="log-row {{ $hasChanges ? 'has-changes' : '' }}" @if($hasChanges) onclick="toggleChanges(this)" @endif>
                            <td class="col-time">{{ $log->created_at->format('H:i') }}</td>
                            <td class="col-action"><span class="action-badge {{ $log->action }}"><span class="dot"></span>{{ $actionLabels[$log->action] ?? $log->action }}</span></td>
                            <td class="col-subject"><span class="subject-tag {{ $subj['class'] }}"><i class="bi {{ $subj['icon'] }}"></i> {{ $subj['label'] }}</span></td>
                            <td class="col-desc">{{ $log->description }}</td>
                            <td class="col-user">
                                <div class="user-cell">
                                    <span class="user-name">{{ $log->user->name ?? 'Система' }}</span>
                                    <span class="user-avatar" style="background:{{ $ac['bg'] }};color:{{ $ac['color'] }};">{{ $initials }}</span>
                                </div>
                            </td>
                        </tr>
                        @if($hasChanges)
                        <tr class="changes-row">
                            <td colspan="5">
                                <div class="changes-block">
                                    @foreach($log->changes as $field => $value)
                                        @if(!in_array($field, ['updated_at', 'created_at']))
                                            <div class="change-item">
                                                <span class="change-field">{{ $field }}</span>
                                                @if(is_array($value) && isset($value['old']) && isset($value['new']))
                                                    <span class="change-old">{{ is_array($value['old']) ? json_encode($value['old']) : $value['old'] }}</span>
                                                    <span class="change-arrow">→</span>
                                                    <span class="change-new">{{ is_array($value['new']) ? json_encode($value['new']) : $value['new'] }}</span>
                                                @elseif(is_array($value))
                                                    @if(isset($value[0]))
                                                        {{-- old/new в массиве --}}
                                                        <span class="change-old">{{ $value[0] ?? '' }}</span>
                                                        <span class="change-arrow">→</span>
                                                        <span class="change-new">{{ $value[1] ?? '' }}</span>
                                                    @else
                                                        <span class="change-new">{{ json_encode($value) }}</span>
                                                    @endif
                                                @else
                                                    <span class="change-new">{{ $value }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <div class="log-empty">
            <i class="bi bi-clock-history"></i>
            <p>Нет записей</p>
        </div>
    @endforelse

    <!-- PAGINATION -->
    @if($logs->hasPages())
        <div class="log-pagination-bar">
            <div class="log-pagination-info">
                Показано {{ $logs->firstItem() }}–{{ $logs->lastItem() }} из {{ $logs->total() }}
            </div>
            <div class="log-pagination-btns">
                {{ $logs->appends(request()->query())->links('pagination::simple-default') }}
            </div>
        </div>
    @endif

</div>

<style>
.log-page { max-width: 1600px; margin: 0 auto; padding: 32px 24px; }

/* HEADER */
.log-header-bar { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; }
.log-title { font-size: 32px; font-weight: 800; letter-spacing: -0.5px; }
.log-subtitle { font-size: 18px; color: #52525b; margin-top: 4px; }
.log-stats { display: flex; gap: 8px; }
.log-stat {
    padding: 14px 20px; background: #111113; border: 1px solid #1e1e22;
    border-radius: 12px; text-align: center; min-width: 90px;
}
.log-stat-num { font-size: 28px; font-weight: 800; line-height: 1; }
.log-stat-num.total { color: #a1a1aa; }
.log-stat-num.green { color: #22c55e; }
.log-stat-num.blue { color: #3b82f6; }
.log-stat-num.red { color: #ef4444; }
.log-stat-num.orange { color: #f59e0b; }
.log-stat-label { font-size: 13px; color: #3f3f46; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; }

/* TOOLBAR */
.log-toolbar { display: flex; gap: 10px; margin-bottom: 24px; flex-wrap: wrap; align-items: center; }
.log-search-wrap { position: relative; flex: 1; min-width: 240px; }
.log-search-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #3f3f46; font-size: 18px; pointer-events: none; }
.log-search-box {
    width: 100%; background: #111113; border: 1px solid #1e1e22; border-radius: 10px;
    padding: 12px 16px 12px 42px; color: #e4e4e7; font-size: 16px; font-family: inherit;
}
.log-search-box:focus { outline: none; border-color: #27272a; }
.log-search-box::placeholder { color: #3f3f46; }

.log-filter-chips { display: flex; gap: 6px; }
.log-chip {
    padding: 10px 18px; border-radius: 8px; border: 1px solid #1e1e22;
    background: #111113; color: #52525b; font-size: 15px; font-weight: 600;
    cursor: pointer; text-decoration: none; transition: all 0.15s;
    display: flex; align-items: center; gap: 6px;
}
.log-chip:hover { border-color: #27272a; color: #71717a; }
.log-chip.active { background: #e4e4e7; color: #09090b; border-color: #e4e4e7; }
.log-chip-cnt { font-size: 13px; opacity: 0.5; }

.log-filter-select {
    background: #111113; border: 1px solid #1e1e22; border-radius: 10px;
    padding: 12px 16px; color: #a1a1aa; font-size: 16px; font-family: inherit;
    cursor: pointer; min-width: 180px; -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%233f3f46'%3E%3Cpath d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 14px center; padding-right: 36px;
}
.log-filter-select:focus { outline: none; border-color: #27272a; }

/* DAY GROUP */
.log-day-group { margin-bottom: 24px; }
.log-day-label {
    font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
    color: #3f3f46; margin-bottom: 8px; display: flex; align-items: center; gap: 12px;
    padding: 0 16px;
}
.log-day-label::after { content: ''; flex: 1; height: 1px; background: #1a1a1e; }
.log-day-count {
    font-size: 13px; font-weight: 600; color: #27272a; background: #111113;
    padding: 3px 10px; border-radius: 100px;
}

/* TABLE */
.log-tbl { width: 100%; border-collapse: separate; border-spacing: 0 3px; }
.log-tbl thead th {
    text-align: left; font-size: 13px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.8px; color: #3f3f46; padding: 10px 16px;
    border-bottom: 1px solid #1e1e22;
}
.log-tbl thead th:last-child { text-align: right; }

.log-row { transition: all 0.1s; cursor: default; }
.log-row:hover td { background: #111113; }
.log-row td {
    padding: 14px 16px; vertical-align: middle; font-size: 16px;
    background: transparent; transition: background 0.1s;
}
.log-row td:first-child { border-radius: 10px 0 0 10px; }
.log-row td:last-child { border-radius: 0 10px 10px 0; }

.col-time { font-size: 15px; color: #52525b; font-weight: 500; white-space: nowrap; font-variant-numeric: tabular-nums; width: 80px; }
.col-action { width: 160px; }
.col-subject { width: 130px; }
.col-desc { font-weight: 500; color: #d4d4d8; }
.col-user { width: 200px; }

/* ACTION BADGE */
.action-badge {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 14px; border-radius: 8px; font-size: 14px; font-weight: 700;
}
.action-badge .dot { width: 8px; height: 8px; border-radius: 50%; }
.action-badge.created { background: rgba(34,197,94,0.08); color: #22c55e; }
.action-badge.created .dot { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,0.5); }
.action-badge.updated { background: rgba(59,130,246,0.08); color: #3b82f6; }
.action-badge.updated .dot { background: #3b82f6; box-shadow: 0 0 6px rgba(59,130,246,0.5); }
.action-badge.cancelled { background: rgba(239,68,68,0.08); color: #ef4444; }
.action-badge.cancelled .dot { background: #ef4444; box-shadow: 0 0 6px rgba(239,68,68,0.5); }
.action-badge.blocked { background: rgba(245,158,11,0.08); color: #f59e0b; }
.action-badge.blocked .dot { background: #f59e0b; }
.action-badge.unblocked { background: rgba(167,139,250,0.08); color: #a78bfa; }
.action-badge.unblocked .dot { background: #a78bfa; }
.action-badge.deleted { background: rgba(239,68,68,0.08); color: #ef4444; }
.action-badge.deleted .dot { background: #ef4444; box-shadow: 0 0 6px rgba(239,68,68,0.5); }

/* SUBJECT TAG */
.subject-tag {
    font-size: 14px; font-weight: 600; padding: 5px 12px; border-radius: 8px;
    background: #111113; border: 1px solid #1e1e22; color: #52525b; white-space: nowrap;
    display: inline-flex; align-items: center; gap: 5px;
}
.subject-tag.booking { color: #60a5fa; border-color: rgba(59,130,246,0.15); background: rgba(59,130,246,0.05); }
.subject-tag.client { color: #4ade80; border-color: rgba(34,197,94,0.15); background: rgba(34,197,94,0.05); }
.subject-tag.block { color: #fbbf24; border-color: rgba(245,158,11,0.15); background: rgba(245,158,11,0.05); }

/* USER CELL */
.user-cell { display: flex; align-items: center; gap: 10px; justify-content: flex-end; }
.user-avatar {
    width: 30px; height: 30px; border-radius: 50%; display: flex;
    align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0;
}
.user-name { font-size: 15px; color: #71717a; font-weight: 500; text-align: right; }

/* CHANGES */
.log-row.has-changes { cursor: pointer; }
.log-row.has-changes .col-desc::after { content: ' ▾'; font-size: 13px; color: #3f3f46; }
.log-row.has-changes.expanded .col-desc::after { content: ' ▴'; color: #52525b; }
.changes-row { display: none; }
.changes-row.show { display: table-row; }
.changes-row td { padding: 0 16px 10px; }
.changes-block {
    margin-left: 80px; padding: 12px 16px; background: #0c0c0e;
    border-radius: 10px; border: 1px solid #1a1a1e;
    display: flex; flex-wrap: wrap; gap: 10px 28px;
}
.change-item { font-size: 15px; display: flex; align-items: center; gap: 8px; }
.change-field { color: #3f3f46; font-weight: 600; }
.change-old { color: #ef4444; text-decoration: line-through; font-size: 14px; }
.change-arrow { color: #27272a; }
.change-new { color: #22c55e; font-weight: 500; }

/* EMPTY */
.log-empty { text-align: center; padding: 80px 20px; color: #27272a; }
.log-empty i { font-size: 56px; }
.log-empty p { font-size: 18px; margin-top: 16px; color: #3f3f46; }

/* PAGINATION */
.log-pagination-bar {
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 24px; padding: 16px 0; border-top: 1px solid #1e1e22;
}
.log-pagination-info { font-size: 15px; color: #3f3f46; }
.log-pagination-btns nav { display: flex; gap: 6px; }

/* RESPONSIVE */
@media (max-width: 768px) {
    .log-page { padding: 20px 12px; }
    .log-header-bar { flex-direction: column; gap: 16px; }
    .log-stats { width: 100%; overflow-x: auto; }
    .log-toolbar { flex-direction: column; }
    .log-search-wrap { width: 100%; }
    .log-filter-chips { width: 100%; overflow-x: auto; }
    .log-filter-select { width: 100%; }
    .log-tbl thead { display: none; }
    .log-row { display: flex; flex-wrap: wrap; gap: 4px 8px; padding: 12px 14px; border-radius: 10px; }
    .log-row:hover { background: #111113; }
    .log-row td { padding: 0; display: inline; border-radius: 0 !important; background: transparent !important; }
    .col-time { order: 1; }
    .col-action { order: 2; }
    .col-desc { order: 4; width: 100%; margin-top: 4px; }
    .col-subject { order: 3; }
    .col-user { order: 5; width: 100%; margin-top: 4px; }
    .user-cell { justify-content: flex-start; }
    .changes-block { margin-left: 0; }
}
</style>

<script>
function toggleChanges(row) {
    const next = row.nextElementSibling;
    if (next && next.classList.contains('changes-row')) {
        next.classList.toggle('show');
        row.classList.toggle('expanded');
    }
}
</script>
@endsection

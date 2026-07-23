@extends('layouts.app')
@section('title', 'Журнал групп')
@section('content')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">

<div class="gjr-page">

    <!-- HEADER + STATS -->
    <div class="gjr-header-bar">
        <div class="gjr-header-left">
            <a href="{{ route('club.groups.index') }}" class="gjr-back"><i class="bi bi-arrow-left"></i></a>
            <div>
                <h1 class="gjr-title">Журнал групп @if($selectedGroup)<span class="gjr-title-sub">— {{ $selectedGroup->name }}</span>@endif</h1>
                <p class="gjr-subtitle">Что и когда происходило: занятия, участники, абонементы</p>
            </div>
        </div>
        <div class="gjr-stats">
            <div class="gjr-stat"><div class="gjr-stat-num total">{{ $stats['total'] }}</div><div class="gjr-stat-label">Всего</div></div>
            <div class="gjr-stat"><div class="gjr-stat-num blue">{{ $stats['sessions'] }}</div><div class="gjr-stat-label">Занятия</div></div>
            <div class="gjr-stat"><div class="gjr-stat-num green">{{ $stats['conducted'] }}</div><div class="gjr-stat-label">Проведено</div></div>
            <div class="gjr-stat"><div class="gjr-stat-num red">{{ $stats['cancelled'] }}</div><div class="gjr-stat-label">Отменено</div></div>
        </div>
    </div>

    <!-- DATE PICKER -->
    <div class="gjr-datepicker-row">
        <div class="gjr-datepicker-wrap">
            <i class="bi bi-calendar3"></i>
            <input type="text" id="gjrDatePicker" class="gjr-datepicker-input" readonly
                   value="{{ $date ? \Carbon\Carbon::parse($date)->translatedFormat('j F Y') : 'Все даты' }}">
        </div>
        @if($date)
            <a href="{{ route('club.groups.journal', request()->except(['date', 'page'])) }}" class="gjr-date-clear"><i class="bi bi-x-lg"></i> Сбросить</a>
        @endif
    </div>

    <!-- TOOLBAR -->
    <form method="GET" class="gjr-toolbar" id="gjrForm">
        <div class="gjr-search-wrap">
            <i class="bi bi-search"></i>
            <input class="gjr-search-box" type="text" name="search" value="{{ request('search') }}" placeholder="Поиск по описанию, имени...">
        </div>
        <select name="group" class="gjr-filter-select" onchange="document.getElementById('gjrForm').submit()">
            <option value="">Все группы</option>
            @foreach($groups as $g)
                <option value="{{ $g->id }}" {{ (string) request('group') === (string) $g->id ? 'selected' : '' }}>{{ $g->name }}{{ $g->status === 'archived' ? ' (архив)' : '' }}</option>
            @endforeach
        </select>
        <select name="action" class="gjr-filter-select" onchange="document.getElementById('gjrForm').submit()">
            <option value="">Все события</option>
            <option value="created" {{ request('action') === 'created' ? 'selected' : '' }}>Создание</option>
            <option value="updated" {{ request('action') === 'updated' ? 'selected' : '' }}>Изменение</option>
            <option value="conducted" {{ request('action') === 'conducted' ? 'selected' : '' }}>Проведено</option>
            <option value="cancelled" {{ request('action') === 'cancelled' ? 'selected' : '' }}>Отмена</option>
            <option value="enrolled" {{ request('action') === 'enrolled' ? 'selected' : '' }}>Продление</option>
            <option value="frozen" {{ request('action') === 'frozen' ? 'selected' : '' }}>Заморозка</option>
            <option value="unfrozen" {{ request('action') === 'unfrozen' ? 'selected' : '' }}>Разморозка</option>
            <option value="restored" {{ request('action') === 'restored' ? 'selected' : '' }}>Возврат</option>
            <option value="deleted" {{ request('action') === 'deleted' ? 'selected' : '' }}>Удаление</option>
        </select>
    </form>

    <!-- LOGS -->
    @php
        $actionLabels = [
            'created' => 'Создание', 'updated' => 'Изменение', 'cancelled' => 'Отмена',
            'deleted' => 'Удаление', 'conducted' => 'Проведено', 'frozen' => 'Заморозка',
            'unfrozen' => 'Разморозка', 'restored' => 'Возврат', 'enrolled' => 'Продление',
        ];
        $subjectLabels = [
            'ClubGroup' => ['label' => 'Группа', 'class' => 'grp', 'icon' => 'bi-people-fill'],
            'ClubGroupMember' => ['label' => 'Участник', 'class' => 'member', 'icon' => 'bi-person'],
            'ClubGroupSession' => ['label' => 'Занятие', 'class' => 'session', 'icon' => 'bi-calendar-check'],
        ];
    @endphp
    @forelse($groupedLogs as $day => $dayLogs)
        @php
            $carbonDate = \Carbon\Carbon::parse($day);
            if ($carbonDate->isToday()) { $dayLabel = 'Сегодня, ' . $carbonDate->translatedFormat('j F'); }
            elseif ($carbonDate->isYesterday()) { $dayLabel = 'Вчера, ' . $carbonDate->translatedFormat('j F'); }
            else { $dayLabel = $carbonDate->translatedFormat('j F, l'); }
        @endphp
        <div class="gjr-day-group">
            <div class="gjr-day-label">
                {{ $dayLabel }}
                <span class="gjr-day-count">{{ $dayLogs->count() }} {{ trans_choice('событие|события|событий', $dayLogs->count()) }}</span>
            </div>
            <table class="gjr-tbl">
                @if($loop->first)
                <thead><tr>
                    <th>Время</th><th>Событие</th><th>Объект</th><th>Группа</th><th>Описание</th><th style="text-align:right;">Автор</th>
                </tr></thead>
                @endif
                <tbody>
                    @foreach($dayLogs as $log)
                        @php
                            $hasChanges = !empty($log->changes) && is_array($log->changes);
                            $subj = $subjectLabels[$log->subject_type] ?? ['label' => $log->subject_type, 'class' => '', 'icon' => 'bi-circle'];
                            $actLabel = $actionLabels[$log->action] ?? $log->action;
                        @endphp
                        <tr class="gjr-row {{ $hasChanges ? 'has-changes' : '' }}" @if($hasChanges) onclick="gjrToggle(this)" @endif>
                            <td class="gjr-col-time">{{ $log->created_at->timezone('Asia/Almaty')->format('H:i') }}</td>
                            <td class="gjr-col-action"><span class="gjr-badge {{ $log->action }}"><span class="dot"></span>{{ $actLabel }}</span></td>
                            <td class="gjr-col-subject"><span class="gjr-subject {{ $subj['class'] }}"><i class="bi {{ $subj['icon'] }}"></i> {{ $subj['label'] }}</span></td>
                            <td class="gjr-col-group">{{ optional($log->group)->name ?? '—' }}</td>
                            <td class="gjr-col-desc">{{ $log->description }}</td>
                            <td class="gjr-col-user">{{ $log->user->name ?? 'Система' }}</td>
                        </tr>
                        @if($hasChanges)
                        <tr class="gjr-changes-row">
                            <td colspan="6">
                                <div class="gjr-changes-block">
                                    @foreach($log->changes as $field => $value)
                                        @if(!in_array($field, ['updated_at', 'created_at']))
                                            <div class="gjr-change-item">
                                                <span class="gjr-change-field">{{ $field }}</span>
                                                @if(is_array($value) && array_key_exists('old', $value) && array_key_exists('new', $value))
                                                    <span class="gjr-change-old">{{ is_array($value['old']) ? json_encode($value['old'], JSON_UNESCAPED_UNICODE) : $value['old'] }}</span>
                                                    <span class="gjr-change-arrow">→</span>
                                                    <span class="gjr-change-new">{{ is_array($value['new']) ? json_encode($value['new'], JSON_UNESCAPED_UNICODE) : $value['new'] }}</span>
                                                @else
                                                    <span class="gjr-change-new">{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value }}</span>
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
        <div class="gjr-empty">
            <i class="bi bi-clock-history"></i>
            <p>Пока нет событий по группам</p>
        </div>
    @endforelse

    @if($logs->hasPages())
        <div class="gjr-pagination-bar">
            <div class="gjr-pagination-info">Показано {{ $logs->firstItem() }}–{{ $logs->lastItem() }} из {{ $logs->total() }}</div>
            <div class="gjr-pagination-btns">
                @if($logs->onFirstPage())<span class="gjr-page-btn disabled"><i class="bi bi-chevron-left"></i></span>
                @else<a href="{{ $logs->previousPageUrl() }}" class="gjr-page-btn"><i class="bi bi-chevron-left"></i></a>@endif
                @foreach($logs->getUrlRange(max(1, $logs->currentPage() - 2), min($logs->lastPage(), $logs->currentPage() + 2)) as $page => $url)
                    <a href="{{ $url }}" class="gjr-page-btn {{ $page == $logs->currentPage() ? 'active' : '' }}">{{ $page }}</a>
                @endforeach
                @if($logs->hasMorePages())<a href="{{ $logs->nextPageUrl() }}" class="gjr-page-btn"><i class="bi bi-chevron-right"></i></a>
                @else<span class="gjr-page-btn disabled"><i class="bi bi-chevron-right"></i></span>@endif
            </div>
        </div>
    @endif
</div>

<style>
.gjr-page { max-width: 1600px; margin: 0 auto; padding: 32px 24px; }
.gjr-header-bar { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; gap: 16px; }
.gjr-header-left { display: flex; align-items: center; gap: 14px; }
.gjr-back { width: 42px; height: 42px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; background: #111113; border: 1px solid #1e1e22; color: #a1a1aa; font-size: 18px; text-decoration: none; transition: all .15s; }
.gjr-back:hover { border-color: #27272a; color: #e4e4e7; }
.gjr-title { font-size: 30px; font-weight: 800; letter-spacing: -0.5px; }
.gjr-title-sub { color: #22c55e; font-weight: 700; }
.gjr-subtitle { font-size: 16px; color: #52525b; margin-top: 4px; }
.gjr-stats { display: flex; gap: 8px; }
.gjr-stat { padding: 14px 20px; background: #111113; border: 1px solid #1e1e22; border-radius: 12px; text-align: center; min-width: 90px; }
.gjr-stat-num { font-size: 26px; font-weight: 800; line-height: 1; }
.gjr-stat-num.total { color: #a1a1aa; } .gjr-stat-num.green { color: #22c55e; } .gjr-stat-num.blue { color: #3b82f6; } .gjr-stat-num.red { color: #ef4444; }
.gjr-stat-label { font-size: 12px; color: #3f3f46; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; }

.gjr-toolbar { display: flex; gap: 10px; margin-bottom: 24px; flex-wrap: wrap; align-items: center; }
.gjr-search-wrap { position: relative; flex: 1; min-width: 240px; }
.gjr-search-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #3f3f46; font-size: 18px; pointer-events: none; }
.gjr-search-box { width: 100%; background: #111113; border: 1px solid #1e1e22; border-radius: 10px; padding: 12px 16px 12px 42px; color: #e4e4e7; font-size: 16px; font-family: inherit; }
.gjr-search-box:focus { outline: none; border-color: #27272a; } .gjr-search-box::placeholder { color: #3f3f46; }
.gjr-filter-select { background: #111113; border: 1px solid #1e1e22; border-radius: 10px; padding: 12px 16px; color: #a1a1aa; font-size: 16px; font-family: inherit; cursor: pointer; min-width: 200px; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%233f3f46'%3E%3Cpath d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 36px; }
.gjr-filter-select:focus { outline: none; border-color: #27272a; }

.gjr-day-group { margin-bottom: 24px; }
.gjr-day-label { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #3f3f46; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; padding: 0 16px; }
.gjr-day-label::after { content: ''; flex: 1; height: 1px; background: #1a1a1e; }
.gjr-day-count { font-size: 13px; font-weight: 600; color: #27272a; background: #111113; padding: 3px 10px; border-radius: 100px; }

.gjr-tbl { width: 100%; border-collapse: separate; border-spacing: 0 3px; }
.gjr-tbl thead th { text-align: left; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #3f3f46; padding: 10px 16px; border-bottom: 1px solid #1e1e22; }
.gjr-tbl thead th:last-child { text-align: right; }
.gjr-row { transition: all 0.1s; cursor: default; }
.gjr-row:hover td { background: #111113; }
.gjr-row td { padding: 13px 16px; vertical-align: middle; font-size: 15.5px; background: transparent; transition: background 0.1s; }
.gjr-row td:first-child { border-radius: 10px 0 0 10px; } .gjr-row td:last-child { border-radius: 0 10px 10px 0; }
.gjr-col-time { font-size: 15px; color: #52525b; font-weight: 500; white-space: nowrap; font-variant-numeric: tabular-nums; width: 70px; }
.gjr-col-action { width: 150px; } .gjr-col-subject { width: 120px; } .gjr-col-group { width: 170px; color: #a1a1aa; font-weight: 600; }
.gjr-col-desc { font-weight: 500; color: #d4d4d8; }
.gjr-col-user { width: 170px; font-size: 15px; color: #e4e4e7; font-weight: 700; text-align: right; }

.gjr-badge { display: inline-flex; align-items: center; gap: 8px; padding: 6px 13px; border-radius: 8px; font-size: 14px; font-weight: 700; background: #16161a; color: #a1a1aa; }
.gjr-badge .dot { width: 8px; height: 8px; border-radius: 50%; background: #52525b; }
.gjr-badge.created, .gjr-badge.restored, .gjr-badge.conducted, .gjr-badge.enrolled { background: rgba(34,197,94,0.08); color: #22c55e; }
.gjr-badge.created .dot, .gjr-badge.restored .dot, .gjr-badge.conducted .dot, .gjr-badge.enrolled .dot { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,0.5); }
.gjr-badge.updated { background: rgba(59,130,246,0.08); color: #3b82f6; } .gjr-badge.updated .dot { background: #3b82f6; box-shadow: 0 0 6px rgba(59,130,246,0.5); }
.gjr-badge.cancelled, .gjr-badge.deleted { background: rgba(239,68,68,0.08); color: #ef4444; } .gjr-badge.cancelled .dot, .gjr-badge.deleted .dot { background: #ef4444; box-shadow: 0 0 6px rgba(239,68,68,0.5); }
.gjr-badge.frozen { background: rgba(6,182,212,0.10); color: #22d3ee; } .gjr-badge.frozen .dot { background: #22d3ee; }
.gjr-badge.unfrozen { background: rgba(167,139,250,0.10); color: #a78bfa; } .gjr-badge.unfrozen .dot { background: #a78bfa; }

.gjr-subject { font-size: 14px; font-weight: 600; padding: 5px 12px; border-radius: 8px; background: #111113; border: 1px solid #1e1e22; color: #52525b; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px; }
.gjr-subject.grp { color: #f472b6; border-color: rgba(236,72,153,0.15); background: rgba(236,72,153,0.05); }
.gjr-subject.member { color: #4ade80; border-color: rgba(34,197,94,0.15); background: rgba(34,197,94,0.05); }
.gjr-subject.session { color: #60a5fa; border-color: rgba(59,130,246,0.15); background: rgba(59,130,246,0.05); }

.gjr-row.has-changes { cursor: pointer; }
.gjr-row.has-changes .gjr-col-desc::after { content: ' ▾'; font-size: 13px; color: #3f3f46; }
.gjr-row.has-changes.expanded .gjr-col-desc::after { content: ' ▴'; color: #52525b; }
.gjr-changes-row { display: none; } .gjr-changes-row.show { display: table-row; }
.gjr-changes-row td { padding: 0 16px 10px; }
.gjr-changes-block { margin-left: 70px; padding: 12px 16px; background: #0c0c0e; border-radius: 10px; border: 1px solid #1a1a1e; display: flex; flex-wrap: wrap; gap: 10px 28px; }
.gjr-change-item { font-size: 15px; display: flex; align-items: center; gap: 8px; }
.gjr-change-field { color: #3f3f46; font-weight: 600; }
.gjr-change-old { color: #ef4444; text-decoration: line-through; font-size: 14px; }
.gjr-change-arrow { color: #27272a; } .gjr-change-new { color: #22c55e; font-weight: 500; }

.gjr-empty { text-align: center; padding: 80px 20px; color: #27272a; }
.gjr-empty i { font-size: 56px; } .gjr-empty p { font-size: 18px; margin-top: 16px; color: #3f3f46; }

.gjr-datepicker-row { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.gjr-datepicker-wrap { display: flex; align-items: center; gap: 10px; background: #111113; border: 1px solid #1e1e22; border-radius: 10px; padding: 10px 16px; cursor: pointer; }
.gjr-datepicker-wrap i { color: #22c55e; font-size: 18px; }
.gjr-datepicker-input { background: transparent !important; border: none !important; color: #e4e4e7 !important; font-size: 16px !important; font-weight: 700 !important; cursor: pointer !important; padding: 0 !important; outline: none !important; font-family: inherit !important; width: 200px !important; }
.gjr-date-clear { display: flex; align-items: center; gap: 5px; padding: 10px 16px; border-radius: 10px; border: 1px solid #1e1e22; background: #111113; color: #71717a; font-size: 14px; font-weight: 600; text-decoration: none; }
.gjr-date-clear:hover { border-color: #ef4444; color: #ef4444; }

.gjr-pagination-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding: 16px 0; border-top: 1px solid #1e1e22; }
.gjr-pagination-info { font-size: 15px; color: #3f3f46; }
.gjr-pagination-btns { display: flex; gap: 4px; }
.gjr-page-btn { padding: 8px 14px; border-radius: 8px; border: 1px solid #1e1e22; background: #111113; color: #a1a1aa; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; min-width: 40px; }
.gjr-page-btn:hover { border-color: #27272a; color: #e4e4e7; } .gjr-page-btn.active { background: #e4e4e7; color: #09090b; border-color: #e4e4e7; } .gjr-page-btn.disabled { opacity: 0.3; pointer-events: none; }

@media (max-width: 768px) {
    .gjr-page { padding: 20px 12px; }
    .gjr-header-bar { flex-direction: column; gap: 16px; }
    .gjr-stats { width: 100%; overflow-x: auto; }
    .gjr-toolbar { flex-direction: column; } .gjr-search-wrap, .gjr-filter-select { width: 100%; }
    .gjr-tbl thead { display: none; }
    .gjr-row { display: flex; flex-wrap: wrap; gap: 4px 8px; padding: 12px 14px; border-radius: 10px; }
    .gjr-row td { padding: 0; display: inline; border-radius: 0 !important; background: transparent !important; }
    .gjr-col-desc, .gjr-col-user { width: 100%; margin-top: 4px; text-align: left; }
    .gjr-changes-block { margin-left: 0; }
}
</style>
<script>
function gjrToggle(row) {
    const next = row.nextElementSibling;
    if (next && next.classList.contains('gjr-changes-row')) { next.classList.toggle('show'); row.classList.toggle('expanded'); }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ru.js"></script>
<script>
flatpickr('#gjrDatePicker', {
    locale: 'ru', dateFormat: 'Y-m-d', altInput: true, altFormat: 'j F Y',
    defaultDate: {!! $date ? "'" . $date . "'" : 'null' !!},
    onChange: function(selectedDates, dateStr) {
        const params = new URLSearchParams(window.location.search);
        params.set('date', dateStr); params.delete('page');
        window.location.href = '{{ route('club.groups.journal') }}?' + params.toString();
    }
});
</script>
@endsection

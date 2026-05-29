@extends('layouts.app')
@section('title', 'Журнал занятий')
@section('content')

@php $qf = request()->only(['group_id', 'status']); @endphp

<div class="gsm-page">

    {{-- Header --}}
    <div class="gsm-header">
        <div class="gsm-title-block">
            <h1 class="gsm-title">Журнал занятий</h1>
            @php
                $n10 = $totalSessions % 10; $n100 = $totalSessions % 100;
                $word = ($n10 === 1 && $n100 !== 11) ? 'занятие'
                    : (($n10 >= 2 && $n10 <= 4 && !($n100 >= 12 && $n100 <= 14)) ? 'занятия' : 'занятий');
            @endphp
            <div class="gsm-subtitle">{{ $totalSessions }} {{ $word }} · сетка время × дата</div>
        </div>
        <form method="GET" action="{{ route('club.groupSessions.index') }}" class="gsm-controls">
            <select name="group_id" class="gsm-select" onchange="this.form.submit()">
                <option value="">Все группы</option>
                @foreach($groups as $g)
                    <option value="{{ $g->id }}" @selected(request('group_id') == $g->id)>{{ $g->name }}</option>
                @endforeach
            </select>
            <select name="status" class="gsm-select" onchange="this.form.submit()">
                <option value="">Все статусы</option>
                <option value="planned" @selected(request('status') === 'planned')>Запланировано</option>
                <option value="held" @selected(request('status') === 'held')>Проведено</option>
                <option value="cancelled" @selected(request('status') === 'cancelled')>Отменено</option>
            </select>
            <button type="button" class="gsm-create-btn" onclick="document.getElementById('createSessionModal').style.display='flex'">+ Создать</button>
        </form>
    </div>

    @if(session('success'))
        <div class="gsm-flash gsm-flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="gsm-flash gsm-flash-error">{{ session('error') }}</div>
    @endif

    {{-- Legend + navigation --}}
    <div class="gsm-legendbar">
        <div class="gsm-legend">
            <span class="gsm-leg"><span class="gsm-dot dot-planned"></span> Запланировано</span>
            <span class="gsm-leg"><span class="gsm-dot dot-held"></span> Проведено</span>
            <span class="gsm-leg"><span class="gsm-dot dot-cancelled"></span> Отменено</span>
        </div>
        <div class="gsm-nav">
            @if($prevFrom)
                <a class="gsm-nav-btn" href="{{ route('club.groupSessions.index', array_merge($qf, ['from' => $prevFrom])) }}" title="Раньше">←</a>
            @else
                <span class="gsm-nav-btn disabled">←</span>
            @endif
            <a class="gsm-nav-btn gsm-today" href="{{ route('club.groupSessions.index', array_merge($qf, ['from' => $today])) }}">Сегодня</a>
            @if($nextFrom)
                <a class="gsm-nav-btn" href="{{ route('club.groupSessions.index', array_merge($qf, ['from' => $nextFrom])) }}" title="Позже">→</a>
            @else
                <span class="gsm-nav-btn disabled">→</span>
            @endif
        </div>
    </div>

    {{-- Matrix --}}
    @if(count($columns) === 0)
        <div class="gsm-empty">
            <p>Занятий пока нет.</p>
            <button type="button" class="gsm-create-btn" onclick="document.getElementById('createSessionModal').style.display='flex'">+ Создать занятие</button>
        </div>
    @else
        <div class="gsm-grid-wrap">
            <div class="gsm-grid" style="grid-template-columns: 64px repeat({{ count($columns) }}, minmax(160px, 1fr));">

                {{-- Corner + day headers --}}
                <div class="gsm-corner"></div>
                @foreach($columns as $col)
                    <div class="gsm-dayhead {{ $col['isToday'] ? 'is-today' : '' }}">
                        <div class="gsm-dayhead-date">
                            <span class="gsm-daynum">{{ $col['dayNum'] }}</span>
                            <span class="gsm-daymonth">{{ $col['month'] }}</span>
                            <span class="gsm-dayweek">{{ $col['weekday'] }}</span>
                        </div>
                        <div class="gsm-daysum">
                            <span class="gsm-sum-total">{{ $col['total'] }}</span>
                            @if($col['attended'] > 0)
                                <span class="gsm-sum-att">✓{{ $col['attended'] }}</span>
                            @endif
                            @if($col['cancelled'] > 0)
                                <span class="gsm-sum-can">✗{{ $col['cancelled'] }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach

                {{-- Time rows --}}
                @foreach($times as $t)
                    <div class="gsm-timecell">{{ $t }}</div>
                    @foreach($columns as $col)
                        @php $cell = $matrix[$t][$col['date']] ?? []; @endphp
                        @if(count($cell) === 0)
                            <div class="gsm-cell gsm-cell-empty" onclick="gsmOpenCreate('{{ $col['date'] }}','{{ $t }}')"></div>
                        @else
                            <div class="gsm-cell">
                                @foreach($cell as $s)
                                    @php $cm = $coachMeta[$s->coach_id] ?? null; @endphp
                                    <a class="gsm-card status-{{ $s->status }}" href="{{ route('club.groupSessions.show', $s) }}">
                                        <div class="gsm-card-top">
                                            <span class="gsm-card-name">{{ $s->group->name }}</span>
                                            @if($s->status === 'held' && $s->attended_count > 0)
                                                <span class="gsm-card-badge">✓{{ $s->attended_count }}</span>
                                            @endif
                                        </div>
                                        <div class="gsm-card-bottom">
                                            @if($cm)
                                                <span class="gsm-avatar" @if(!$cm['photo']) style="background:{{ $cm['color'] }}" @endif title="{{ $cm['name'] }}">
                                                    @if($cm['photo'])<img src="{{ $cm['photo'] }}" alt="">@else{{ $cm['initials'] }}@endif
                                                </span>
                                            @else
                                                <span class="gsm-avatar gsm-avatar-none" title="Без тренера">—</span>
                                            @endif
                                            <span class="gsm-court">{{ $s->court->name }}</span>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                @endforeach

            </div>
        </div>
    @endif

</div>

{{-- Create session modal --}}
<div id="createSessionModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="gsm-modal-card" onclick="event.stopPropagation()">
        <div class="gsm-modal-head">
            <h5 class="gsm-modal-title">Создать занятие</h5>
            <button type="button" class="gsm-modal-close" onclick="document.getElementById('createSessionModal').style.display='none'">&#10005;</button>
        </div>
        <form method="POST" action="{{ route('club.groupSessions.store') }}">
            @csrf
            <div class="gsm-modal-body">
                <div class="gsm-fg">
                    <label class="gsm-label">Группа <span class="gsm-req">*</span></label>
                    <select name="group_id" class="gsm-input" required>
                        <option value="">— выберите группу —</option>
                        @foreach($groups as $g)
                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="gsm-fg">
                    <label class="gsm-label">Корт <span class="gsm-req">*</span></label>
                    <select name="court_id" class="gsm-input" required>
                        <option value="">— выберите корт —</option>
                        @foreach($courts as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="gsm-fg-row">
                    <div class="gsm-fg">
                        <label class="gsm-label">Дата <span class="gsm-req">*</span></label>
                        <input type="date" name="date" id="cs_date" class="gsm-input" required value="{{ $today }}">
                    </div>
                    <div class="gsm-fg">
                        <label class="gsm-label">Начало <span class="gsm-req">*</span></label>
                        <input type="time" name="start_time" id="cs_time" class="gsm-input" required value="10:00">
                    </div>
                </div>
                <div class="gsm-fg">
                    <label class="gsm-label">Слотов (продолжительность) <span class="gsm-req">*</span></label>
                    <input type="number" name="slots" class="gsm-input" min="1" max="8" value="1" required>
                    <div class="gsm-hint">1 слот = длительность слота корта (обычно 60 мин)</div>
                </div>
                <div class="gsm-fg">
                    <label class="gsm-label">Тренер</label>
                    <select name="coach_id" class="gsm-input">
                        <option value="">— из группы или без тренера —</option>
                        @foreach($coaches as $cc)
                            @if($cc->user)
                                <option value="{{ $cc->user_id }}">{{ $cc->user->full_name }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="gsm-modal-foot">
                <button type="button" class="gsm-btn-cancel" onclick="document.getElementById('createSessionModal').style.display='none'">Отмена</button>
                <button type="submit" class="gsm-btn-save">Создать</button>
            </div>
        </form>
    </div>
</div>

<script>
    function gsmOpenCreate(date, time) {
        var m = document.getElementById('createSessionModal');
        var d = document.getElementById('cs_date');
        var t = document.getElementById('cs_time');
        if (d && date) d.value = date;
        if (t && time) t.value = time;
        m.style.display = 'flex';
    }
</script>

<style>
    .gsm-page {
        --bg: #0a0a0b; --card: #111113; --card2: #16161a; --line: #27272a; --line2: #1c1c1f;
        --text: #f4f4f5; --dim: #a1a1aa; --muted: #71717a;
        --green: #22c55e; --blue: #6366f1; --red: #ef4444; --amber: #eab308;
        max-width: 1200px; margin: 0 auto; padding: 28px 24px; color: var(--text);
    }

    /* Header */
    .gsm-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .gsm-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; margin: 0; }
    .gsm-subtitle { font-size: 13px; color: var(--muted); margin-top: 4px; }
    .gsm-controls { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 0; }
    .gsm-select { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 10px 14px; font-size: 13px; color: var(--text); font-family: inherit; cursor: pointer; }
    .gsm-select:focus { outline: none; border-color: var(--green); }
    .gsm-create-btn { background: var(--green); color: #0a0a0b; border: none; padding: 10px 18px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: background 0.2s; }
    .gsm-create-btn:hover { background: #16a34a; }

    /* Flash */
    .gsm-flash { padding: 13px 18px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 18px; }
    .gsm-flash-success { background: rgba(34,197,94,0.15); color: var(--green); border: 1px solid rgba(34,197,94,0.3); }
    .gsm-flash-error { background: rgba(239,68,68,0.15); color: var(--red); border: 1px solid rgba(239,68,68,0.3); }

    /* Legend + nav */
    .gsm-legendbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
    .gsm-legend { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
    .gsm-leg { display: inline-flex; align-items: center; gap: 7px; font-size: 12px; color: var(--dim); font-weight: 600; }
    .gsm-dot { width: 9px; height: 9px; border-radius: 50%; }
    .dot-planned { background: var(--blue); }
    .dot-held { background: var(--green); }
    .dot-cancelled { background: var(--red); }
    .gsm-nav { display: flex; align-items: center; gap: 6px; }
    .gsm-nav-btn { min-width: 34px; height: 34px; padding: 0 12px; display: inline-flex; align-items: center; justify-content: center; background: var(--card); border: 1px solid var(--line); border-radius: 9px; color: var(--text); text-decoration: none; font-size: 14px; font-weight: 600; cursor: pointer; }
    .gsm-nav-btn:hover { border-color: var(--green); color: var(--green); }
    .gsm-nav-btn.disabled { opacity: 0.35; pointer-events: none; }
    .gsm-today { font-size: 13px; }

    /* Grid */
    .gsm-grid-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 16px; background: var(--card); }
    .gsm-grid { display: grid; min-width: 760px; }
    .gsm-corner { border-bottom: 1px solid var(--line); border-right: 1px solid var(--line2); background: var(--card); position: sticky; left: 0; z-index: 3; }
    .gsm-dayhead { padding: 14px 16px; border-bottom: 1px solid var(--line); border-left: 1px solid var(--line2); }
    .gsm-dayhead.is-today { background: rgba(34,197,94,0.06); }
    .gsm-dayhead-date { display: flex; align-items: baseline; gap: 6px; }
    .gsm-daynum { font-size: 17px; font-weight: 800; color: var(--text); }
    .gsm-daymonth { font-size: 13px; color: var(--dim); font-weight: 600; }
    .gsm-dayweek { font-size: 11px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
    .gsm-daysum { display: flex; align-items: center; gap: 8px; margin-top: 6px; font-size: 12px; font-weight: 700; }
    .gsm-sum-total { color: var(--muted); }
    .gsm-sum-att { color: var(--green); }
    .gsm-sum-can { color: var(--red); }

    .gsm-timecell { padding: 14px 10px; font-size: 12px; font-weight: 600; color: var(--muted); border-bottom: 1px solid var(--line2); border-right: 1px solid var(--line2); background: var(--card); position: sticky; left: 0; z-index: 2; text-align: center; }
    .gsm-cell { padding: 8px; border-bottom: 1px solid var(--line2); border-left: 1px solid var(--line2); display: flex; flex-direction: column; gap: 8px; min-height: 64px; }
    .gsm-cell-empty { cursor: pointer; transition: background 0.15s; }
    .gsm-cell-empty:hover { background: var(--card2); }

    /* Card */
    .gsm-card { display: block; background: var(--card2); border: 1px solid var(--line); border-left-width: 3px; border-radius: 9px; padding: 8px 10px; text-decoration: none; transition: border-color 0.15s, transform 0.1s; }
    .gsm-card:hover { transform: translateY(-1px); }
    .gsm-card.status-planned { border-left-color: var(--blue); }
    .gsm-card.status-held { border-left-color: var(--green); }
    .gsm-card.status-cancelled { border-left-color: var(--red); }
    .gsm-card-top { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 5px; }
    .gsm-card-name { font-size: 13px; font-weight: 700; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .gsm-card.status-cancelled .gsm-card-name { text-decoration: line-through; color: var(--muted); }
    .gsm-card-badge { flex-shrink: 0; font-size: 11px; font-weight: 800; color: var(--green); }
    .gsm-card-bottom { display: flex; align-items: center; gap: 7px; }
    .gsm-avatar { width: 20px; height: 20px; border-radius: 50%; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 800; color: #fff; overflow: hidden; }
    .gsm-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .gsm-avatar-none { background: #27272a; color: var(--muted); }
    .gsm-court { font-size: 12px; color: var(--dim); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* Empty */
    .gsm-empty { padding: 60px 20px; text-align: center; color: var(--muted); background: var(--card); border: 1px solid var(--line); border-radius: 16px; }
    .gsm-empty p { margin: 0 0 18px; font-size: 15px; }

    /* Modal */
    .gsm-modal-card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; margin: 20px; }
    .gsm-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--line); }
    .gsm-modal-title { font-size: 17px; font-weight: 700; color: var(--text); margin: 0; }
    .gsm-modal-close { background: none; border: none; color: var(--muted); font-size: 16px; cursor: pointer; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 6px; }
    .gsm-modal-close:hover { color: var(--red); }
    .gsm-modal-body { padding: 24px; }
    .gsm-modal-foot { display: flex; gap: 12px; padding: 20px 24px; border-top: 1px solid var(--line); }
    .gsm-fg { margin-bottom: 18px; flex: 1; }
    .gsm-fg-row { display: flex; gap: 14px; }
    .gsm-label { display: block; font-size: 12px; font-weight: 700; color: var(--dim); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .gsm-req { color: var(--red); }
    .gsm-input { width: 100%; background: var(--card2); border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; font-size: 15px; color: var(--text); font-weight: 500; font-family: inherit; box-sizing: border-box; }
    .gsm-input:focus { outline: none; border-color: var(--green); box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .gsm-hint { font-size: 12px; color: #52525b; margin-top: 6px; }
    .gsm-btn-cancel { flex: 1; padding: 14px; background: var(--card2); border: 1px solid var(--line); border-radius: 10px; color: var(--dim); font-size: 14px; font-weight: 700; cursor: pointer; }
    .gsm-btn-save { flex: 2; padding: 14px; background: var(--green); border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .gsm-btn-save:hover { background: #16a34a; }

    @media (max-width: 768px) {
        .gsm-header { flex-direction: column; }
        .gsm-controls { width: 100%; }
        .gsm-fg-row { flex-direction: column; gap: 0; }
    }
</style>

@endsection

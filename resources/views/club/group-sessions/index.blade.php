@extends('layouts.app')
@section('title', 'Журнал занятий')
@section('content')

<div class="gsessions-container">

    <div class="gsessions-header">
        <h1 class="gsessions-title">Журнал занятий</h1>
        <button class="btn-add" onclick="document.getElementById('createSessionModal').style.display='flex'">+ Создать занятие</button>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('club.groupSessions.index') }}" class="filter-bar">
        <select name="group_id" class="filter-select">
            <option value="">Все группы</option>
            @foreach($groups as $g)
                <option value="{{ $g->id }}" @selected(request('group_id') == $g->id)>{{ $g->name }}</option>
            @endforeach
        </select>
        <select name="status" class="filter-select">
            <option value="">Все статусы</option>
            <option value="planned" @selected(request('status') === 'planned')>Запланировано</option>
            <option value="held" @selected(request('status') === 'held')>Проведено</option>
            <option value="cancelled" @selected(request('status') === 'cancelled')>Отменено</option>
        </select>
        <input type="date" name="date" class="filter-date" value="{{ request('date') }}">
        <button type="submit" class="filter-apply">Применить</button>
        <a href="{{ route('club.groupSessions.index') }}" class="filter-reset">Сбросить</a>
    </form>

    {{-- Sessions list --}}
    <div class="sessions-table-card">
        <div class="sessions-table-header">
            <div class="col-date">Дата</div>
            <div class="col-time">Время</div>
            <div class="col-group">Группа</div>
            <div class="col-coach">Тренер</div>
            <div class="col-court">Корт</div>
            <div class="col-status">Статус</div>
            <div class="col-attended">Пришло</div>
        </div>
        @forelse($sessions as $s)
            <div class="session-row" onclick="window.location='{{ route('club.groupSessions.show', $s) }}'">
                <div class="col-date">{{ $s->date->format('d.m.Y') }}</div>
                <div class="col-time">{{ $s->start_time }}–{{ $s->end_time }}</div>
                <div class="col-group">
                    <a href="{{ route('club.groupSessions.show', $s) }}" class="group-link" onclick="event.stopPropagation()">
                        {{ $s->group->name }}
                    </a>
                </div>
                <div class="col-coach">{{ $s->coach?->name ?? '—' }}</div>
                <div class="col-court">{{ $s->court->name }}</div>
                <div class="col-status">
                    @if($s->status === 'held')
                        <span class="badge-held">Проведено</span>
                    @elseif($s->status === 'cancelled')
                        <span class="badge-cancelled">Отменено</span>
                    @else
                        <span class="badge-planned">Запланировано</span>
                    @endif
                </div>
                <div class="col-attended">{{ $s->attended_count ?? 0 }}</div>
            </div>
        @empty
            <div class="empty-state">
                <p>Занятий пока нет.</p>
            </div>
        @endforelse
    </div>

    @if($sessions->hasPages())
        <div style="margin-top:24px;">
            {{ $sessions->links() }}
        </div>
    @endif

</div>

{{-- Create session modal --}}
<div id="createSessionModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Создать занятие</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('createSessionModal').style.display='none'">&#10005;</button>
        </div>
        <form method="POST" action="{{ route('club.groupSessions.store') }}">
            @csrf
            <div class="modal-body-area">
                <div class="form-group">
                    <label class="form-label">Группа <span style="color:#ef4444">*</span></label>
                    <select name="group_id" class="form-input" required>
                        <option value="">— выберите группу —</option>
                        @foreach($groups as $g)
                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Корт <span style="color:#ef4444">*</span></label>
                    <select name="court_id" class="form-input" required>
                        <option value="">— выберите корт —</option>
                        @foreach($courts as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Дата <span style="color:#ef4444">*</span></label>
                    <input type="date" name="date" class="form-input" required value="{{ now()->format('Y-m-d') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Начало <span style="color:#ef4444">*</span></label>
                    <input type="time" name="start_time" class="form-input" required value="10:00">
                </div>
                <div class="form-group">
                    <label class="form-label">Слотов (продолжительность) <span style="color:#ef4444">*</span></label>
                    <input type="number" name="slots" class="form-input" min="1" max="8" value="1" required>
                    <div class="form-hint">1 слот = длительность слота корта (обычно 60 мин)</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Тренер</label>
                    <select name="coach_id" class="form-input">
                        <option value="">— из группы или без тренера —</option>
                        @foreach($coaches as $cc)
                            @if($cc->user)
                                <option value="{{ $cc->user_id }}">{{ $cc->user->name }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('createSessionModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Создать</button>
            </div>
        </form>
    </div>
</div>

<style>
    .gsessions-container { max-width: 1000px; margin: 0 auto; padding: 32px 24px; }
    .gsessions-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 16px; }
    .gsessions-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    .btn-add { display: flex; align-items: center; gap: 8px; background: #22c55e; color: #0a0a0b; border: none; padding: 12px 22px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
    .btn-add:hover { background: #16a34a; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 24px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; padding: 16px 20px; background: #111113; border: 1px solid #27272a; border-radius: 12px; }
    .filter-select, .filter-date { background: #16161a; border: 1px solid #27272a; border-radius: 8px; padding: 9px 12px; font-size: 13px; color: #f4f4f5; font-family: inherit; cursor: pointer; }
    .filter-select:focus, .filter-date:focus { outline: none; border-color: #22c55e; }
    .filter-apply { background: #22c55e; color: #0a0a0b; border: none; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; }
    .filter-apply:hover { background: #16a34a; }
    .filter-reset { color: #71717a; font-size: 13px; font-weight: 600; text-decoration: none; padding: 9px 14px; border-radius: 8px; border: 1px solid #27272a; background: #16161a; }
    .filter-reset:hover { color: #a1a1aa; border-color: #3f3f46; }

    .sessions-table-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; overflow: hidden; }
    .sessions-table-header { display: grid; grid-template-columns: 90px 100px 1fr 120px 100px 120px 60px; gap: 12px; padding: 12px 20px; border-bottom: 1px solid #27272a; }
    .sessions-table-header > div { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; }
    .session-row { display: grid; grid-template-columns: 90px 100px 1fr 120px 100px 120px 60px; gap: 12px; padding: 14px 20px; border-bottom: 1px solid #1c1c1f; cursor: pointer; transition: background 0.15s; align-items: center; }
    .session-row:last-child { border-bottom: none; }
    .session-row:hover { background: #16161a; }
    .col-date { font-size: 14px; font-weight: 600; color: #f4f4f5; }
    .col-time { font-size: 13px; color: #71717a; font-weight: 500; }
    .col-group { font-size: 14px; color: #a1a1aa; }
    .col-coach { font-size: 13px; color: #71717a; }
    .col-court { font-size: 13px; color: #a1a1aa; }
    .col-status { }
    .col-attended { font-size: 14px; font-weight: 600; color: #a1a1aa; }
    .group-link { color: #f4f4f5; font-weight: 600; text-decoration: none; }
    .group-link:hover { color: #22c55e; }
    .badge-held { display: inline-flex; align-items: center; padding: 3px 8px; background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); border-radius: 5px; font-size: 11px; font-weight: 700; }
    .badge-cancelled { display: inline-flex; align-items: center; padding: 3px 8px; background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); border-radius: 5px; font-size: 11px; font-weight: 700; }
    .badge-planned { display: inline-flex; align-items: center; padding: 3px 8px; background: rgba(113,113,122,0.15); color: #71717a; border: 1px solid rgba(113,113,122,0.3); border-radius: 5px; font-size: 11px; font-weight: 700; }

    .empty-state { padding: 48px 20px; text-align: center; color: #71717a; font-size: 15px; }

    /* Modal */
    .modal-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; margin: 20px; }
    .modal-header-row { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid #27272a; }
    .modal-title-text { font-size: 17px; font-weight: 700; color: #f4f4f5; margin: 0; }
    .modal-close-btn { background: none; border: none; color: #71717a; font-size: 16px; cursor: pointer; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; }
    .modal-close-btn:hover { color: #ef4444; }
    .modal-body-area { padding: 24px; }
    .modal-footer-row { display: flex; gap: 12px; padding: 20px 24px; border-top: 1px solid #27272a; }
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; box-sizing: border-box; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-input::placeholder { color: #52525b; }
    .form-hint { font-size: 12px; color: #52525b; margin-top: 6px; }
    .btn-cancel { flex: 1; padding: 14px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }

    @media (max-width: 768px) {
        .gsessions-header { flex-direction: column; align-items: flex-start; }
        .filter-bar { flex-direction: column; align-items: stretch; }
        .sessions-table-header { display: none; }
        .session-row { grid-template-columns: 1fr; gap: 4px; }
        .col-time, .col-coach { color: #71717a; }
    }
</style>

@endsection

@extends('layouts.app')
@section('title', 'Занятие: ' . $session->group->name)
@section('content')

@php
    $statusLabel = match($session->status) {
        'planned'   => 'Запланировано',
        'held'      => 'Проведено',
        'cancelled' => 'Отменено',
        default     => $session->status,
    };
    $statusClass = match($session->status) {
        'held'      => 'badge-held',
        'cancelled' => 'badge-cancelled',
        default     => 'badge-planned',
    };
@endphp

<div class="gsession-container">

    <div class="gsession-header">
        <div class="gsession-title-block">
            <a href="{{ route('club.groupSessions.index') }}" class="back-link">&#8592; Журнал занятий</a>
            <h1 class="gsession-title">{{ $session->group->name }}</h1>
        </div>
        <span class="{{ $statusClass }}">{{ $statusLabel }}</span>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    {{-- Session meta card --}}
    <div class="meta-card">
        <div class="meta-item-row">
            <span class="meta-icon">&#128197;</span>
            <span class="meta-value">{{ $session->date->format('d.m.Y') }}</span>
        </div>
        <div class="meta-divider"></div>
        <div class="meta-item-row">
            <span class="meta-icon">&#128336;</span>
            <span class="meta-value">{{ $session->start_time }}–{{ $session->end_time }}</span>
        </div>
        <div class="meta-divider"></div>
        <div class="meta-item-row">
            <span class="meta-icon">&#127968;</span>
            <span class="meta-value">{{ $session->court->name }}</span>
        </div>
        @if($session->coach)
            <div class="meta-divider"></div>
            <div class="meta-item-row">
                <span class="meta-icon">&#128100;</span>
                <span class="meta-value">{{ $session->coach->name }}</span>
            </div>
        @endif
    </div>

    @if($session->status === 'planned')

        @php
            $endsAt = \Carbon\Carbon::parse($session->date->format('Y-m-d') . ' ' . $session->end_time);
            $canConduct = now()->gte($endsAt);
        @endphp

        @if(!$canConduct)
            <div class="attendance-card" style="padding:18px 22px;">
                <div style="display:flex;align-items:center;gap:10px;color:#a1a1aa;font-size:14px;">
                    <span style="font-size:18px;">⏳</span>
                    <span>Отметить посещаемость можно после окончания занятия — <b style="color:#f4f4f5;">{{ $endsAt->format('H:i, d.m.Y') }}</b>.</span>
                </div>
            </div>
        @else
        {{-- Conduct form --}}
        <form method="POST" action="{{ route('club.groupSessions.conduct', $session) }}">
            @csrf
            <div class="attendance-card">
                <div class="attendance-card-header">
                    <h2 class="attendance-title">Отметить посещаемость</h2>
                </div>
                @if($members->isEmpty())
                    <div class="empty-state-small">В группе нет активных участников.</div>
                @else
                    <div class="attendance-table-header">
                        <div class="att-col-name">Участник</div>
                        <div class="att-col-rem">Остаток</div>
                        <div class="att-col-cb">Пришёл</div>
                        <div class="att-col-cb">Списать</div>
                    </div>
                    @foreach($members as $m)
                        @php $rem = $m->remaining; @endphp
                        <div class="attendance-row {{ $rem <= 0 ? 'row-warn' : '' }}">
                            <div class="att-col-name">
                                <span class="att-name">{{ $m->client->name }}</span>
                                @if($rem <= 0)
                                    <span class="need-renew-badge">нужно продлить</span>
                                @endif
                            </div>
                            <div class="att-col-rem">
                                <span class="rem-badge {{ $rem > 0 ? 'rem-ok' : 'rem-low' }}">{{ $rem }}</span>
                            </div>
                            <div class="att-col-cb">
                                <input type="checkbox"
                                    class="att-checkbox"
                                    name="attendance[{{ $m->id }}][attended]"
                                    value="1"
                                    id="att_{{ $m->id }}">
                            </div>
                            <div class="att-col-cb">
                                <input type="checkbox"
                                    class="att-checkbox"
                                    name="attendance[{{ $m->id }}][charged]"
                                    value="1"
                                    id="chg_{{ $m->id }}"
                                    {{ $rem <= 0 ? 'disabled' : '' }}>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            <div class="session-actions">
                <button type="submit" class="btn-conduct">&#10003; Провести занятие</button>
            </div>
        </form>
        @endif

        {{-- Cancel form --}}
        <form method="POST" action="{{ route('club.groupSessions.cancel', $session) }}" class="cancel-form"
              onsubmit="return confirm('Отменить занятие и освободить корт?')">
            @csrf
            <button type="submit" class="btn-cancel-session">&#10005; Отменить занятие</button>
        </form>

    @elseif($session->status === 'held')

        {{-- Read-only attendance --}}
        <div class="attendance-card">
            <div class="attendance-card-header">
                <h2 class="attendance-title">Посещаемость</h2>
            </div>
            @if($members->isEmpty())
                <div class="empty-state-small">Нет участников.</div>
            @else
                <div class="attendance-table-header">
                    <div class="att-col-name">Участник</div>
                    <div class="att-col-cb">Пришёл</div>
                    <div class="att-col-cb">Списано</div>
                </div>
                @foreach($members as $m)
                    @php $rec = $existing->get($m->id); @endphp
                    <div class="attendance-row">
                        <div class="att-col-name">
                            <span class="att-name">{{ $m->client->name }}</span>
                        </div>
                        <div class="att-col-cb">
                            @if($rec && $rec->attended)
                                <span class="check-icon check-yes">&#10003;</span>
                            @else
                                <span class="check-icon check-no">&#10005;</span>
                            @endif
                        </div>
                        <div class="att-col-cb">
                            @if($rec && $rec->charged)
                                <span class="check-icon check-warn">&#10003;</span>
                            @else
                                <span class="check-icon-muted">—</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

    @else

        {{-- Cancelled --}}
        <div class="cancelled-notice">
            <span class="cancelled-icon">&#9940;</span>
            <span class="cancelled-text">Занятие отменено.</span>
        </div>

    @endif

</div>

<style>
    .gsession-container { max-width: 800px; margin: 0 auto; padding: 32px 24px; }
    .gsession-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 16px; }
    .gsession-title-block { display: flex; flex-direction: column; gap: 6px; }
    .back-link { font-size: 13px; color: #71717a; text-decoration: none; font-weight: 600; }
    .back-link:hover { color: #a1a1aa; }
    .gsession-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; color: #f4f4f5; margin: 0; }
    .badge-held { display: inline-flex; align-items: center; padding: 6px 14px; background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); border-radius: 8px; font-size: 13px; font-weight: 700; }
    .badge-cancelled { display: inline-flex; align-items: center; padding: 6px 14px; background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); border-radius: 8px; font-size: 13px; font-weight: 700; }
    .badge-planned { display: inline-flex; align-items: center; padding: 6px 14px; background: rgba(113,113,122,0.15); color: #71717a; border: 1px solid rgba(113,113,122,0.3); border-radius: 8px; font-size: 13px; font-weight: 700; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 24px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .meta-card { display: flex; align-items: center; flex-wrap: wrap; gap: 0; background: #111113; border: 1px solid #27272a; border-radius: 14px; padding: 18px 24px; margin-bottom: 28px; gap: 16px; }
    .meta-item-row { display: flex; align-items: center; gap: 8px; }
    .meta-icon { font-size: 16px; color: #71717a; }
    .meta-value { font-size: 15px; font-weight: 600; color: #f4f4f5; }
    .meta-divider { width: 1px; height: 20px; background: #27272a; }

    .attendance-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; margin-bottom: 20px; overflow: hidden; }
    .attendance-card-header { padding: 18px 24px; border-bottom: 1px solid #27272a; }
    .attendance-title { font-size: 16px; font-weight: 700; color: #f4f4f5; margin: 0; }
    .attendance-table-header { display: grid; grid-template-columns: 1fr 80px 80px 80px; gap: 12px; padding: 10px 24px; background: #0e0e10; border-bottom: 1px solid #1c1c1f; }
    .attendance-table-header > div { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; }
    .attendance-row { display: grid; grid-template-columns: 1fr 80px 80px 80px; gap: 12px; padding: 14px 24px; border-bottom: 1px solid #1c1c1f; align-items: center; transition: background 0.15s; }
    .attendance-row:last-child { border-bottom: none; }
    .attendance-row:hover { background: #16161a; }
    .row-warn { background: rgba(234,179,8,0.05); }
    .att-col-name { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
    .att-col-rem, .att-col-cb { display: flex; align-items: center; justify-content: center; }
    .att-name { font-size: 14px; font-weight: 600; color: #f4f4f5; }
    .need-renew-badge { display: inline-flex; align-items: center; padding: 2px 8px; background: rgba(234,179,8,0.15); color: #eab308; border: 1px solid rgba(234,179,8,0.3); border-radius: 5px; font-size: 11px; font-weight: 700; }
    .rem-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; padding: 3px 8px; border-radius: 6px; font-size: 13px; font-weight: 700; }
    .rem-ok { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .rem-low { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
    .att-checkbox { width: 18px; height: 18px; accent-color: #22c55e; cursor: pointer; }
    .att-checkbox:disabled { opacity: 0.3; cursor: not-allowed; }
    .check-icon { font-size: 18px; font-weight: 700; }
    .check-yes { color: #22c55e; }
    .check-no { color: #52525b; }
    .check-warn { color: #eab308; }
    .check-icon-muted { color: #52525b; font-size: 16px; }

    .session-actions { display: flex; margin-bottom: 12px; }
    .btn-conduct { background: #22c55e; color: #0a0a0b; border: none; padding: 14px 28px; border-radius: 10px; font-size: 15px; font-weight: 800; cursor: pointer; transition: all 0.2s; }
    .btn-conduct:hover { background: #16a34a; }
    .cancel-form { display: inline; }
    .btn-cancel-session { background: transparent; color: #ef4444; border: 1px solid rgba(239,68,68,0.4); padding: 10px 20px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
    .btn-cancel-session:hover { background: rgba(239,68,68,0.1); border-color: #ef4444; }

    .cancelled-notice { display: flex; align-items: center; gap: 12px; padding: 20px 24px; background: #111113; border: 1px solid #27272a; border-radius: 14px; }
    .cancelled-icon { font-size: 22px; color: #71717a; }
    .cancelled-text { font-size: 15px; font-weight: 600; color: #71717a; }

    .empty-state-small { padding: 32px 24px; text-align: center; color: #71717a; font-size: 14px; }

    @media (max-width: 600px) {
        .gsession-header { flex-direction: column; align-items: flex-start; }
        .meta-card { flex-direction: column; align-items: flex-start; }
        .meta-divider { display: none; }
        .attendance-table-header, .attendance-row { grid-template-columns: 1fr 60px 60px 60px; }
    }
</style>

@endsection

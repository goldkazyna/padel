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
            // Время хранится как локальное клубное (Алматы); сервер в UTC.
            // Парсим явно с TZ Алматы, чтобы сравнение совпадало с реальностью клуба.
            $endsAt = \Carbon\Carbon::parse(
                $session->date->format('Y-m-d') . ' ' . $session->end_time,
                'Asia/Almaty'
            );
            $canConduct = now('Asia/Almaty')->gte($endsAt);
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
                    <div class="attendance-table-header att-grid">
                        <div class="att-col-name">Участник</div>
                        <div class="att-col-rem">Остаток</div>
                        <div class="att-col-cb">Пришёл</div>
                        <div class="att-col-cb">Списать</div>
                        <div class="att-col-trial">Пробное&nbsp;₸</div>
                    </div>
                    @foreach($members as $m)
                        @php
                            $rem = $m->remaining;
                            $frozen = $m->freezes->first(fn($f) => $f->freeze_from->lte($session->date) && $f->freeze_until->gte($session->date));
                        @endphp
                        <div class="attendance-row att-grid {{ $rem <= 0 && !$frozen ? 'row-warn' : '' }}">
                            <div class="att-col-name">
                                <span class="att-name">{{ $m->client->name }}</span>
                                @if($frozen)
                                    <span class="freeze-badge">❄ заморожен до {{ $frozen->freeze_until->format('d.m.y') }}</span>
                                @elseif($rem <= 0)
                                    <span class="need-renew-badge">нужно продлить</span>
                                @endif
                            </div>
                            <div class="att-col-rem">
                                <span class="rem-badge {{ $rem > 0 ? 'rem-ok' : 'rem-low' }}">{{ $rem }}</span>
                            </div>
                            <div class="att-col-cb">
                                <input type="checkbox" class="att-checkbox"
                                    name="attendance[{{ $m->id }}][attended]" value="1" id="att_{{ $m->id }}">
                            </div>
                            <div class="att-col-cb">
                                <input type="checkbox" class="att-checkbox chg-checkbox"
                                    name="attendance[{{ $m->id }}][charged]" value="1" id="chg_{{ $m->id }}"
                                    @if($frozen) data-locked-frozen="1" @endif
                                    {{ ($rem <= 0 || $frozen) ? 'disabled' : '' }}>
                            </div>
                            <div class="att-col-trial">
                                <input type="checkbox" class="att-checkbox trial-checkbox"
                                    name="attendance[{{ $m->id }}][is_trial]" value="1" id="trial_{{ $m->id }}"
                                    onchange="toggleTrial({{ $m->id }})">
                                <input type="number" min="0" step="100" placeholder="₸" class="trial-amount"
                                    name="attendance[{{ $m->id }}][trial_amount]" id="tamt_{{ $m->id }}" style="display:none;">
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            <div class="session-actions">
                <button type="submit" class="btn-conduct">&#10003; Провести занятие</button>
            </div>
        </form>

        {{-- Пробные гости (не члены группы) --}}
        <div class="attendance-card">
            <div class="attendance-card-header">
                <h2 class="attendance-title">Пробные гости</h2>
            </div>
            @forelse($guests as $g)
                <div class="attendance-row guest-row">
                    <div class="att-col-name">
                        <span class="att-name">{{ $g->client->name ?? '—' }}</span>
                        <span class="trial-badge">пробное</span>
                        <span class="guest-amt">{{ (int) $g->trial_amount > 0 ? number_format($g->trial_amount, 0, '', ' ') . ' ₸' : 'бесплатно' }}</span>
                    </div>
                    <form method="POST" action="{{ route('club.groupSessions.trialGuest.remove', [$session, $g]) }}"
                          onsubmit="return confirm('Убрать пробного гостя?')" style="margin-left:auto;">
                        @csrf @method('DELETE')
                        <button type="submit" class="action-btn-x" title="Убрать">&#10005;</button>
                    </form>
                </div>
            @empty
                <div class="empty-state-small" style="padding:16px 24px;">Пробных гостей нет.</div>
            @endforelse

            {{-- Добавить пробного гостя --}}
            <form method="POST" action="{{ route('club.groupSessions.trialGuest', $session) }}" class="guest-add" onsubmit="return guestValid()">
                @csrf
                <div class="guest-add-row">
                    <div style="position:relative;flex:1;">
                        <input type="text" id="guestSearch" class="guest-input" autocomplete="off"
                               placeholder="Поиск клиента по имени или телефону…" oninput="searchGuests(this.value)">
                        <div id="guestResults" class="guest-results" style="display:none;"></div>
                        <input type="hidden" name="client_id" id="guestClientId">
                        <div id="guestSelected" class="guest-selected" style="display:none;">
                            <span id="guestSelectedName"></span>
                            <button type="button" onclick="clearGuest()" class="guest-clear">&#10005;</button>
                        </div>
                    </div>
                    <input type="number" name="trial_amount" min="0" step="100" placeholder="Сумма ₸" class="guest-amount-input">
                    <button type="submit" class="btn-guest-add">+ Добавить</button>
                </div>
            </form>
        </div>
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

<script>
    // Пробное у члена: при включении снимаем «списать» (пробное не тратит пакет).
    function toggleTrial(id) {
        var trial = document.getElementById('trial_' + id);
        var amt = document.getElementById('tamt_' + id);
        var chg = document.getElementById('chg_' + id);
        if (trial.checked) {
            amt.style.display = '';
            if (chg) { chg.checked = false; chg.disabled = true; }
        } else {
            amt.style.display = 'none';
            if (chg && !chg.dataset.lockedFrozen) { chg.disabled = false; }
        }
    }

    // Поиск клиента для пробного гостя (тот же эндпоинт, что в группах).
    var guestTimer;
    function searchGuests(q) {
        clearTimeout(guestTimer);
        var box = document.getElementById('guestResults');
        q = (q || '').trim();
        if (q.length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
        var field = /\d/.test(q) ? 'phone' : 'name';
        guestTimer = setTimeout(function () {
            fetch('{{ route("club.clients.search") }}?field=' + field + '&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    box.innerHTML = '';
                    if (!list.length) {
                        box.innerHTML = '<div style="padding:12px;color:#71717a;font-size:13px;">Ничего не найдено</div>';
                        box.style.display = 'block';
                        return;
                    }
                    list.forEach(function (c) {
                        var item = document.createElement('div');
                        item.className = 'guest-result-item';
                        item.innerHTML = '<span>' + (c.name || '') + '</span><span style="color:#71717a">' + (c.phone || '') + '</span>';
                        item.addEventListener('click', function () { selectGuest(c.id, c.name || ''); });
                        box.appendChild(item);
                    });
                    box.style.display = 'block';
                });
        }, 250);
    }
    function selectGuest(id, name) {
        document.getElementById('guestClientId').value = id;
        document.getElementById('guestSelectedName').textContent = name;
        document.getElementById('guestSelected').style.display = 'flex';
        document.getElementById('guestResults').style.display = 'none';
        document.getElementById('guestSearch').value = '';
    }
    function clearGuest() {
        document.getElementById('guestClientId').value = '';
        document.getElementById('guestSelected').style.display = 'none';
    }
    function guestValid() {
        if (!document.getElementById('guestClientId').value) {
            alert('Выберите клиента для пробного занятия');
            return false;
        }
        return true;
    }
</script>

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

    /* 5-колоночная сетка с колонкой «Пробное» */
    .att-grid { grid-template-columns: 1fr 64px 64px 64px 104px; }
    .att-col-trial { display: flex; align-items: center; justify-content: center; gap: 6px; }
    .trial-amount { width: 64px; background: #16161a; border: 1px solid #27272a; border-radius: 7px; color: #f4f4f5; padding: 5px 7px; font-size: 13px; }
    .freeze-badge { display: inline-flex; align-items: center; padding: 2px 8px; background: rgba(56,189,248,.12); color: #38bdf8; border: 1px solid rgba(56,189,248,.3); border-radius: 5px; font-size: 11px; font-weight: 700; }
    .trial-badge { display: inline-flex; align-items: center; padding: 2px 8px; background: rgba(168,85,247,.14); color: #c084fc; border: 1px solid rgba(168,85,247,.3); border-radius: 5px; font-size: 11px; font-weight: 700; }

    .guest-row { display: flex; align-items: center; }
    .guest-amt { color: #a1a1aa; font-size: 13px; }
    .action-btn-x { background: #16161a; border: 1px solid #27272a; border-radius: 7px; color: #a1a1aa; width: 30px; height: 30px; cursor: pointer; }
    .action-btn-x:hover { border-color: #ef4444; color: #ef4444; }
    .guest-add { padding: 14px 24px; border-top: 1px solid #1c1c1f; }
    .guest-add-row { display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap; }
    .guest-input { flex: 1; min-width: 180px; background: #16161a; border: 1px solid #27272a; border-radius: 9px; color: #f4f4f5; padding: 9px 12px; font-size: 14px; }
    .guest-amount-input { width: 120px; background: #16161a; border: 1px solid #27272a; border-radius: 9px; color: #f4f4f5; padding: 9px 12px; font-size: 14px; }
    .btn-guest-add { background: rgba(168,85,247,.16); color: #c084fc; border: 1px solid rgba(168,85,247,.35); border-radius: 9px; padding: 9px 16px; font-weight: 700; font-size: 14px; cursor: pointer; }
    .btn-guest-add:hover { background: rgba(168,85,247,.28); }
    .guest-results { position: absolute; left: 0; right: 0; top: 100%; z-index: 10; background: #16161a; border: 1px solid #27272a; border-radius: 10px; margin-top: 4px; max-height: 220px; overflow-y: auto; }
    .guest-result-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #27272a; display: flex; justify-content: space-between; gap: 8px; color: #f4f4f5; font-size: 14px; }
    .guest-result-item:hover { background: #1a1a1e; }
    .guest-selected { align-items: center; justify-content: space-between; margin-top: 8px; padding: 9px 12px; background: #16161a; border: 1px solid #22c55e; border-radius: 9px; color: #22c55e; font-weight: 700; font-size: 14px; }
    .guest-clear { background: none; border: none; color: #71717a; cursor: pointer; font-size: 14px; }

    @media (max-width: 600px) {
        .gsession-header { flex-direction: column; align-items: flex-start; }
        .meta-card { flex-direction: column; align-items: flex-start; }
        .meta-divider { display: none; }
        .attendance-table-header, .attendance-row { grid-template-columns: 1fr 60px 60px 60px; }
        .att-grid { grid-template-columns: 1fr 48px 48px 48px 76px; }
        .guest-row { grid-template-columns: none; }
    }
</style>

@endsection

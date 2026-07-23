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
    // Инициалы для аватарки по имени клиента.
    $initials = function ($name) {
        $parts = preg_split('/\s+/', trim((string) $name));
        $parts = array_values(array_filter($parts, fn($p) => mb_strlen($p) > 0));
        $a = isset($parts[0]) ? mb_substr($parts[0], 0, 1) : '?';
        $b = isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '';
        return mb_strtoupper($a . $b);
    };
    $guestsCount = $guests->count();
    $guestsSum = (int) $guests->sum('trial_amount');
@endphp

<div class="gsession-container">

    <div class="gsession-header">
        <div class="gsession-title-block">
            <a href="{{ route('club.groupSessions.index') }}" class="back-link">&#8592; Журнал занятий</a>
            <h1 class="gsession-title">{{ $session->group->name }}</h1>
            <div class="gsession-subtitle">Групповое занятие · {{ $club->name }}</div>
        </div>
        <span class="gs-status gs-status-{{ $session->status }}"><span class="gs-dot"></span>{{ $statusLabel }}</span>
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
            <i class="bi bi-calendar3 meta-icon"></i>
            <span class="meta-value">{{ $session->date->format('d.m.Y') }}</span>
        </div>
        <div class="meta-divider"></div>
        <div class="meta-item-row">
            <i class="bi bi-clock meta-icon"></i>
            <span class="meta-value">{{ \Illuminate\Support\Str::substr($session->start_time, 0, 5) }}–{{ \Illuminate\Support\Str::substr($session->end_time, 0, 5) }}</span>
        </div>
        <div class="meta-divider"></div>
        <div class="meta-item-row">
            <i class="bi bi-grid meta-icon"></i>
            <span class="meta-value">{{ $session->court->name }}</span>
        </div>
        @if($session->coach)
            <div class="meta-divider"></div>
            <div class="meta-item-row">
                <i class="bi bi-person meta-icon"></i>
                <span class="meta-value">{{ $session->coach->name }}</span>
            </div>
        @endif
    </div>

    @if($session->status === 'planned')

        @php
            // Время хранится как локальное клубное (Алматы); сервер в UTC.
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
        <form method="POST" action="{{ route('club.groupSessions.conduct', $session) }}" id="conductForm">
            @csrf
            <div class="attendance-card">
                <div class="attendance-card-header">
                    <span class="dot dot-green"></span>
                    <h2 class="attendance-title">Отметить посещаемость</h2>
                </div>
                @if($members->isEmpty())
                    <div class="empty-state-small">В группе нет активных участников.</div>
                @else
                    <div class="att-head att-grid">
                        <div>Участник</div>
                        <div class="ta-center">Остаток</div>
                        <div class="ta-center">Статус</div>
                        <div class="ta-right">Списание</div>
                    </div>
                    @foreach($members as $m)
                        @php
                            $rem = $m->remaining;
                            $frozen = $m->freezes->first(fn($f) => $f->freeze_from->lte($session->date) && $f->freeze_until->gte($session->date));
                            // Ещё не начал ходить — занимает место, но не списывается.
                            $notStarted = $m->starts_at && $m->starts_at->gt($session->date);
                            $canCharge = $rem > 0 && !$frozen && !$notStarted;
                            // Дефолт: списать (если можно), иначе «не был».
                            $default = $canCharge ? 'charge' : 'absent';
                        @endphp
                        <div class="att-row att-grid" data-member="{{ $m->id }}">
                            <div class="att-col-name">
                                <span class="avatar">{{ $initials($m->client->name) }}</span>
                                <div class="name-block">
                                    <span class="att-name">{{ $m->client->name }}</span>
                                    @if($frozen)<span class="freeze-badge">❄ заморожен до {{ $frozen->freeze_until->format('d.m.y') }}</span>@endif
                                    @if($notStarted)<span class="freeze-badge" style="background:rgba(106,164,245,.14);color:#6aa4f5;">начнёт с {{ $m->starts_at->format('d.m.y') }}</span>@endif
                                </div>
                            </div>
                            <div class="ta-center">
                                <span class="rem-badge {{ $rem <= 0 ? 'rem-low' : ($rem <= 2 ? 'rem-mid' : 'rem-ok') }}">{{ $rem }} зан.</span>
                            </div>
                            <div class="ta-center">
                                <div class="seg">
                                    <label class="seg-btn seg-charge {{ $canCharge ? '' : 'is-disabled' }}">
                                        <input type="radio" name="attendance[{{ $m->id }}][status]" value="charge"
                                               {{ $default === 'charge' ? 'checked' : '' }} {{ $canCharge ? '' : 'disabled' }}
                                               onchange="onStatus({{ $m->id }})">
                                        <span>Списать</span>
                                    </label>
                                    <label class="seg-btn seg-trial {{ $frozen ? 'is-disabled' : '' }}">
                                        <input type="radio" name="attendance[{{ $m->id }}][status]" value="trial"
                                               {{ $frozen ? 'disabled' : '' }}
                                               onchange="onStatus({{ $m->id }})">
                                        <span>Пробное</span>
                                    </label>
                                    <label class="seg-btn seg-absent">
                                        <input type="radio" name="attendance[{{ $m->id }}][status]" value="absent"
                                               {{ $default === 'absent' ? 'checked' : '' }}
                                               onchange="onStatus({{ $m->id }})">
                                        <span>Не был</span>
                                    </label>
                                </div>
                            </div>
                            <div class="ta-right att-col-charge">
                                <span class="charge-val" id="cval_{{ $m->id }}" style="{{ $default === 'charge' ? '' : 'display:none;' }}">−1 зан.</span>
                                <span class="trial-amt-wrap" id="tamtw_{{ $m->id }}" style="display:none;">
                                    <input type="number" min="0" step="100" class="trial-amount" placeholder="0"
                                           name="attendance[{{ $m->id }}][trial_amount]" id="tamt_{{ $m->id }}"
                                           oninput="recalcSummary()"> ₸
                                </span>
                                <span class="dash" id="dash_{{ $m->id }}" style="{{ $default === 'absent' ? '' : 'display:none;' }}">—</span>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        </form>

        {{-- Пробные гости --}}
        <div class="attendance-card">
                <div class="attendance-card-header">
                    <span class="dot dot-purple"></span>
                    <h2 class="attendance-title">Пробные гости</h2>
                    <span class="card-hint">без карты, за деньги</span>
                </div>
                @forelse($guests as $g)
                    <div class="att-row guest-row">
                        <div class="att-col-name">
                            <span class="avatar avatar-guest">{{ $initials($g->client->name ?? '?') }}</span>
                            <div class="name-block">
                                <span class="att-name">{{ $g->client->name ?? '—' }}</span>
                                <span class="trial-badge">пробное · {{ (int) $g->trial_amount > 0 ? number_format($g->trial_amount, 0, '', ' ') . ' ₸' : 'бесплатно' }}</span>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('club.groupSessions.trialGuest.remove', [$session, $g]) }}"
                              onsubmit="return confirm('Убрать пробного гостя?')" style="margin-left:auto;">
                            @csrf @method('DELETE')
                            <button type="submit" class="action-btn-x" title="Убрать">&#10005;</button>
                        </form>
                    </div>
                @empty
                    <div class="empty-state-small" style="padding:18px 24px;">Пробных гостей нет.</div>
                @endforelse
        </div>

        {{-- Добавить пробного гостя --}}
        <form method="POST" action="{{ route('club.groupSessions.trialGuest', $session) }}" class="guest-add-card" onsubmit="return guestValid()">
            @csrf
            <div class="guest-add-row">
                <div style="position:relative;flex:1;min-width:180px;">
                    <input type="text" id="guestSearch" class="guest-input" autocomplete="off"
                           placeholder="Имя гостя" oninput="searchGuests(this.value)">
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

        <div class="session-summary">
                <div class="summary-head">При проведении произойдёт</div>
                <div class="summary-row">
                    <span class="summary-label"><i class="bi bi-credit-card-2-front summary-ic"></i> Спишется занятий с карт</span>
                    <span class="summary-val sv-green" id="sumCharge">0</span>
                </div>
                <div class="summary-row">
                    <span class="summary-label"><i class="bi bi-star summary-ic"></i> Пробных (участники + гости)</span>
                    <span class="summary-val sv-purple" id="sumTrial">0</span>
                </div>
                <div class="summary-row">
                    <span class="summary-label"><i class="bi bi-coin summary-ic"></i> Принято денег за пробные</span>
                    <span class="summary-val sv-purple" id="sumMoney">0 ₸</span>
                </div>
            </div>

            <div class="session-actions">
                <button type="submit" form="conductForm" class="btn-conduct">&#10003; Провести занятие</button>
                <button type="button" class="btn-cancel-session" onclick="document.getElementById('cancelReasonModal').style.display='flex'">&#10005; Отменить занятие</button>
            </div>

        {{-- Отмена занятия с указанием причины (сохраняется в журнал группы) --}}
        <form method="POST" action="{{ route('club.groupSessions.cancel', $session) }}" id="cancelForm">
            @csrf
            <div id="cancelReasonModal" class="gcancel-modal" style="display:none;">
                <div class="gcancel-box">
                    <h3 class="gcancel-title">Отменить занятие?</h3>
                    <p class="gcancel-sub">Корт освободится. Причину видно в журнале группы — по ней потом понятно, за что отменили.</p>
                    <label class="gcancel-label">Причина отмены</label>
                    <textarea name="reason" id="cancelReasonText" class="gcancel-textarea" rows="3" maxlength="255" placeholder="Например: заболел тренер, нет игроков, перенос…"></textarea>
                    <div id="cancelReasonErr" style="display:none;color:#ef4444;font-size:13px;margin-top:6px;font-weight:600;">Укажите причину отмены — минимум 5 символов.</div>
                    <div class="gcancel-actions">
                        <button type="button" class="gcancel-btn-secondary" onclick="document.getElementById('cancelReasonModal').style.display='none'">Назад</button>
                        <button type="button" class="gcancel-btn-danger" onclick="submitCancelSession()">Отменить занятие</button>
                    </div>
                </div>
            </div>
        </form>
        <script>
        function submitCancelSession() {
            var ta = document.getElementById('cancelReasonText');
            var val = (ta ? ta.value : '').trim();
            var err = document.getElementById('cancelReasonErr');
            if (val.length < 5) {
                if (err) err.style.display = 'block';
                if (ta) { ta.style.borderColor = '#ef4444'; ta.focus(); }
                return;
            }
            document.getElementById('cancelForm').submit();
        }
        </script>
        @endif

    @elseif($session->status === 'held')

        {{-- Read-only attendance --}}
        <div class="attendance-card">
            <div class="attendance-card-header">
                <span class="dot dot-green"></span>
                <h2 class="attendance-title">Посещаемость</h2>
            </div>
            @php $heldRows = $members->isEmpty() && $guests->isEmpty(); @endphp
            @if($heldRows)
                <div class="empty-state-small">Нет участников.</div>
            @else
                @foreach($members as $m)
                    @php $rec = $existing->get($m->id); @endphp
                    <div class="att-row guest-row">
                        <div class="att-col-name">
                            <span class="avatar">{{ $initials($m->client->name) }}</span>
                            <span class="att-name">{{ $m->client->name }}</span>
                        </div>
                        <div style="margin-left:auto;">
                            @if($rec && $rec->charged)
                                <span class="status-pill sp-charge">списано −1</span>
                            @elseif($rec && $rec->is_trial)
                                <span class="status-pill sp-trial">пробное{{ (int)$rec->trial_amount > 0 ? ' · '.number_format($rec->trial_amount,0,'',' ').' ₸' : '' }}</span>
                            @else
                                <span class="status-pill sp-absent">не был</span>
                            @endif
                        </div>
                    </div>
                @endforeach
                @foreach($guests as $g)
                    <div class="att-row guest-row">
                        <div class="att-col-name">
                            <span class="avatar avatar-guest">{{ $initials($g->client->name ?? '?') }}</span>
                            <span class="att-name">{{ $g->client->name ?? '—' }} <span class="guest-tag">гость</span></span>
                        </div>
                        <div style="margin-left:auto;">
                            <span class="status-pill sp-trial">пробное{{ (int)$g->trial_amount > 0 ? ' · '.number_format($g->trial_amount,0,'',' ').' ₸' : '' }}</span>
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
.gcancel-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
.gcancel-box { background: #131619; border: 1px solid #27272a; border-radius: 16px; padding: 24px; width: 100%; max-width: 440px; box-shadow: 0 20px 60px rgba(0,0,0,0.5); }
.gcancel-title { font-size: 20px; font-weight: 800; color: #f4f4f5; margin: 0 0 6px; }
.gcancel-sub { font-size: 14px; color: #a1a1aa; line-height: 1.5; margin: 0 0 16px; }
.gcancel-label { display: block; font-size: 13px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; }
.gcancel-textarea { width: 100%; background: #0c0e0f; border: 1px solid #27272a; border-radius: 10px; padding: 12px 14px; color: #e4e4e7; font-size: 15px; font-family: inherit; resize: vertical; box-sizing: border-box; }
.gcancel-textarea:focus { outline: none; border-color: #ef4444; }
.gcancel-actions { display: flex; gap: 10px; margin-top: 18px; }
.gcancel-btn-secondary { flex: 1; padding: 12px; border-radius: 10px; border: 1px solid #27272a; background: #16161a; color: #a1a1aa; font-size: 15px; font-weight: 700; cursor: pointer; }
.gcancel-btn-secondary:hover { border-color: #3f3f46; color: #e4e4e7; }
.gcancel-btn-danger { flex: 1; padding: 12px; border-radius: 10px; border: none; background: #ef4444; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
.gcancel-btn-danger:hover { background: #dc2626; }
</style>

<script>
    var GUESTS_COUNT = {{ $guestsCount }};
    var GUESTS_SUM = {{ $guestsSum }};

    // Переключение статуса участника → показ соответствующего поля в «Списание».
    function onStatus(id) {
        var checked = document.querySelector('input[name="attendance[' + id + '][status]"]:checked');
        var val = checked ? checked.value : 'absent';
        var cval = document.getElementById('cval_' + id);
        var tw = document.getElementById('tamtw_' + id);
        var dash = document.getElementById('dash_' + id);
        if (cval) cval.style.display = (val === 'charge') ? '' : 'none';
        if (tw) tw.style.display = (val === 'trial') ? 'inline-flex' : 'none';
        if (dash) dash.style.display = (val === 'absent') ? '' : 'none';
        recalcSummary();
    }

    // Живая сводка «При проведении произойдёт».
    function recalcSummary() {
        var charge = 0, trial = 0, money = 0;
        document.querySelectorAll('input[type=radio]:checked').forEach(function (r) {
            if (!r.name.startsWith('attendance[')) return;
            if (r.value === 'charge') charge++;
            if (r.value === 'trial') {
                trial++;
                var id = r.name.match(/attendance\[(\d+)\]/)[1];
                var amt = document.getElementById('tamt_' + id);
                money += amt && amt.value ? parseInt(amt.value, 10) || 0 : 0;
            }
        });
        trial += GUESTS_COUNT;
        money += GUESTS_SUM;
        var fmt = function (n) { return n.toLocaleString('ru-RU'); };
        var elC = document.getElementById('sumCharge');
        var elT = document.getElementById('sumTrial');
        var elM = document.getElementById('sumMoney');
        if (elC) elC.textContent = charge;
        if (elT) elT.textContent = trial;
        if (elM) elM.textContent = fmt(money) + ' ₸';
    }

    // Поиск клиента для пробного гостя.
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

    document.addEventListener('DOMContentLoaded', recalcSummary);
</script>

<style>
    .gsession-container { max-width: 1000px; margin: 0 auto; padding: 32px 24px; }
    .gsession-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
    .gsession-title-block { display: flex; flex-direction: column; gap: 6px; }
    .back-link { font-size: 13px; color: #71717a; text-decoration: none; font-weight: 600; }
    .back-link:hover { color: #a1a1aa; }
    .gsession-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; color: #f4f4f5; margin: 0; }
    .gsession-subtitle { font-size: 13px; color: #71717a; }
    /* Статус-плашка как в макете: тёмный пилл + цветная точка. */
    .gs-status { display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; background: #1c1c1f; border: 1px solid #2a2a2e; border-radius: 9px; font-size: 13px; font-weight: 700; color: #d4d4d8; white-space: nowrap; }
    .gs-dot { width: 8px; height: 8px; border-radius: 50%; background: #71717a; }
    .gs-status-planned .gs-dot { background: #f59e0b; }
    .gs-status-held .gs-dot { background: #22c55e; }
    .gs-status-cancelled .gs-dot { background: #ef4444; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .meta-card { display: flex; align-items: center; flex-wrap: wrap; background: #111113; border: 1px solid #27272a; border-radius: 14px; padding: 16px 22px; margin-bottom: 18px; gap: 16px; }
    .meta-item-row { display: flex; align-items: center; gap: 8px; }
    .meta-icon { font-size: 16px; color: #71717a; }
    .meta-value { font-size: 15px; font-weight: 700; color: #f4f4f5; }
    .meta-divider { width: 1px; height: 20px; background: #27272a; }

    .attendance-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; margin-bottom: 16px; overflow: hidden; }
    .attendance-card-header { display: flex; align-items: center; gap: 10px; padding: 16px 22px; border-bottom: 1px solid #1c1c1f; }
    .attendance-title { font-size: 16px; font-weight: 800; color: #f4f4f5; margin: 0; }
    .card-hint { margin-left: auto; font-size: 12px; color: #71717a; }
    .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .dot-green { background: #22c55e; }
    .dot-purple { background: #a855f7; }

    .att-grid { display: grid; grid-template-columns: 1fr 92px minmax(232px, auto) 92px; gap: 14px; align-items: center; }
    .att-head { padding: 10px 22px; background: #0e0e10; border-bottom: 1px solid #1c1c1f; }
    .att-head > div { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; }
    .ta-center { text-align: center; display: flex; align-items: center; justify-content: center; }
    .ta-right { text-align: right; justify-content: flex-end; display: flex; align-items: center; }
    .att-row { padding: 14px 22px; border-bottom: 1px solid #1c1c1f; }
    .att-row:last-child { border-bottom: none; }
    /* Строка гостя/проведённого: имя слева, кнопка/статус справа в одну линию. */
    .guest-row { display: flex; align-items: center; gap: 12px; }
    .guest-row .att-col-name { flex: 1; }
    .guest-row form { margin-left: auto; }
    .att-col-name { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .avatar { flex-shrink: 0; width: 38px; height: 38px; border-radius: 50%; background: #3f3f46; color: #e4e4e7; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; }
    .avatar-guest { background: #2e1f3a; color: #c084fc; }
    .name-block { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
    .att-name { font-size: 14px; font-weight: 700; color: #f4f4f5; }
    .freeze-badge { display: inline-flex; align-items: center; align-self: flex-start; padding: 1px 7px; background: rgba(56,189,248,.12); color: #38bdf8; border: 1px solid rgba(56,189,248,.3); border-radius: 5px; font-size: 11px; font-weight: 700; }
    .trial-badge { display: inline-flex; align-items: center; align-self: flex-start; padding: 1px 7px; background: rgba(168,85,247,.14); color: #c084fc; border: 1px solid rgba(168,85,247,.3); border-radius: 5px; font-size: 11px; font-weight: 700; }
    .guest-tag { font-size: 11px; color: #c084fc; font-weight: 700; }

    .rem-badge { display: inline-flex; align-items: center; justify-content: center; padding: 4px 10px; border-radius: 7px; font-size: 13px; font-weight: 800; white-space: nowrap; }
    .rem-ok { background: rgba(34,197,94,0.14); color: #22c55e; border: 1px solid rgba(34,197,94,0.28); }
    .rem-mid { background: rgba(245,158,11,0.14); color: #f59e0b; border: 1px solid rgba(245,158,11,0.28); }
    .rem-low { background: rgba(239,68,68,0.14); color: #ef4444; border: 1px solid rgba(239,68,68,0.28); }

    .seg { display: inline-flex; background: #0e0e10; border: 1px solid #27272a; border-radius: 9px; padding: 3px; gap: 2px; }
    .seg-btn { position: relative; display: inline-flex; align-items: center; padding: 6px 12px; border-radius: 7px; font-size: 13px; font-weight: 700; color: #71717a; cursor: pointer; user-select: none; transition: all .12s; }
    .seg-btn input { position: absolute; opacity: 0; width: 0; height: 0; }
    .seg-btn.is-disabled { opacity: .35; cursor: not-allowed; }
    .seg-charge:has(input:checked) { background: rgba(34,197,94,.16); color: #22c55e; }
    .seg-trial:has(input:checked) { background: rgba(168,85,247,.2); color: #c084fc; }
    .seg-absent:has(input:checked) { background: #27272a; color: #d4d4d8; }

    .att-col-charge { gap: 4px; white-space: nowrap; }
    .charge-val { color: #22c55e; font-weight: 800; font-size: 14px; }
    .trial-amt-wrap { align-items: center; gap: 4px; color: #c084fc; font-weight: 700; font-size: 13px; }
    .trial-amount { width: 68px; background: #0e0e10; border: 1px solid #a855f7; border-radius: 8px; color: #f4f4f5; padding: 6px 8px; font-size: 13px; text-align: right; }
    .dash { color: #52525b; }

    .action-btn-x { background: #16161a; border: 1px solid #27272a; border-radius: 7px; color: #a1a1aa; width: 30px; height: 30px; cursor: pointer; }
    .action-btn-x:hover { border-color: #ef4444; color: #ef4444; }

    .guest-add-card { background: #111113; border: 1px solid #27272a; border-radius: 14px; padding: 14px 18px; margin-bottom: 16px; }
    .guest-add-row { display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap; }
    .guest-input { flex: 1; min-width: 180px; background: #0e0e10; border: 1px solid #27272a; border-radius: 9px; color: #f4f4f5; padding: 11px 13px; font-size: 14px; }
    .guest-amount-input { width: 130px; background: #0e0e10; border: 1px solid #27272a; border-radius: 9px; color: #f4f4f5; padding: 11px 13px; font-size: 14px; }
    .btn-guest-add { background: rgba(168,85,247,.18); color: #c084fc; border: 1px solid rgba(168,85,247,.4); border-radius: 9px; padding: 11px 18px; font-weight: 800; font-size: 14px; cursor: pointer; white-space: nowrap; }
    .btn-guest-add:hover { background: rgba(168,85,247,.3); }
    .guest-results { position: absolute; left: 0; right: 0; top: 100%; z-index: 10; background: #16161a; border: 1px solid #27272a; border-radius: 10px; margin-top: 4px; max-height: 220px; overflow-y: auto; }
    .guest-result-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #27272a; display: flex; justify-content: space-between; gap: 8px; color: #f4f4f5; font-size: 14px; }
    .guest-result-item:hover { background: #1a1a1e; }
    .guest-selected { align-items: center; justify-content: space-between; margin-top: 8px; padding: 10px 13px; background: #16161a; border: 1px solid #22c55e; border-radius: 9px; color: #22c55e; font-weight: 700; font-size: 14px; }
    .guest-clear { background: none; border: none; color: #71717a; cursor: pointer; font-size: 14px; }

    .session-summary { background: #111113; border: 1px solid #27272a; border-radius: 16px; padding: 18px 22px; margin-bottom: 18px; }
    .summary-head { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 12px; }
    .summary-row { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #1a1a1d; }
    .summary-row:last-child { border-bottom: none; }
    .summary-label { display: flex; align-items: center; gap: 10px; color: #d4d4d8; font-size: 14px; font-weight: 600; }
    .summary-ic { font-size: 15px; color: #a1a1aa; }
    .summary-val { font-size: 17px; font-weight: 800; }
    .sv-green { color: #22c55e; }
    .sv-purple { color: #c084fc; }

    .session-actions { display: flex; gap: 12px; }
    .btn-conduct { flex: 1; background: #22c55e; color: #0a0a0b; border: none; padding: 15px 28px; border-radius: 12px; font-size: 15px; font-weight: 800; cursor: pointer; transition: all 0.2s; }
    .btn-conduct:hover { background: #16a34a; }
    .btn-cancel-session { background: transparent; color: #ef4444; border: 1px solid rgba(239,68,68,0.4); padding: 15px 22px; border-radius: 12px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
    .btn-cancel-session:hover { background: rgba(239,68,68,0.1); border-color: #ef4444; }

    .status-pill { display: inline-flex; align-items: center; padding: 5px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; }
    .sp-charge { background: rgba(34,197,94,.14); color: #22c55e; }
    .sp-trial { background: rgba(168,85,247,.16); color: #c084fc; }
    .sp-absent { background: #27272a; color: #71717a; }

    .cancelled-notice { display: flex; align-items: center; gap: 12px; padding: 20px 24px; background: #111113; border: 1px solid #27272a; border-radius: 14px; }
    .cancelled-icon { font-size: 22px; color: #71717a; }
    .cancelled-text { font-size: 15px; font-weight: 600; color: #71717a; }
    .empty-state-small { padding: 28px 24px; text-align: center; color: #71717a; font-size: 14px; }

    @media (max-width: 640px) {
        .gsession-header { flex-direction: column; align-items: flex-start; }
        .meta-card { gap: 12px 16px; }
        .att-head { display: none; }
        .att-grid { grid-template-columns: 1fr; gap: 10px; }
        .att-row .ta-center, .att-row .ta-right { justify-content: flex-start; }
        .seg { width: 100%; }
        .seg-btn { flex: 1; justify-content: center; }
        .session-actions { flex-direction: column; }
    }
</style>

@endsection

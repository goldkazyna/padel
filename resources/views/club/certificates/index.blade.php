@extends('layouts.app')
@section('title', 'Сертификаты')

@section('content')
@include('club.cards._cards_shared_css')
<div class="cc-page">
    <div class="cc-head">
        <h1 class="cc-title">Сертификаты <span class="cc-club">— {{ $club->name }}</span></h1>
        <span class="cc-stat"><b>{{ $certificates->total() }}</b><span>Всего</span></span>
        <span class="cc-spacer"></span>
        <a href="{{ route('club.certificates.design') }}" class="cc-btn cc-ghost">Конструктор шаблона</a>
        <button class="cc-btn cc-green" onclick="openCertModal()">+ Добавить сертификат</button>
    </div>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash-message flash-error">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="flash-message flash-error">@foreach($errors->all() as $e){{ $e }}<br>@endforeach</div>@endif

    @if($certificates->count() === 0)
        <div class="cc-empty">Сертификатов пока нет. Создайте первый.</div>
    @else
    <div class="crt-table-wrap">
        <table class="crt-table">
            <thead>
                <tr>
                    <th>Номер</th>
                    <th>Тип</th>
                    <th>Номинал</th>
                    <th>Кому</th>
                    <th>Создан</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($certificates as $c)
                <tr>
                    <td class="crt-num">{{ $c->number }}</td>
                    <td><span class="crt-badge {{ $c->isNamed() ? 'crt-named' : 'crt-generic' }}">{{ $c->type_name }}</span></td>
                    <td class="crt-nominal">{{ $c->valueLabel() }}</td>
                    <td>{{ $c->recipient_name ?? '—' }}</td>
                    <td>{{ $c->created_at->format('d.m.Y H:i') }}</td>
                    <td class="crt-actions">
                        <a href="{{ route('club.certificates.show', $c) }}" target="_blank" class="cc-btn cc-ghost cc-sm">Открыть</a>
                        <form method="POST" action="{{ route('club.certificates.destroy', $c) }}" onsubmit="return confirm('Удалить сертификат {{ $c->number }}?')" style="display:inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="cc-btn cc-danger cc-sm">Удалить</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="crt-pagination">{{ $certificates->links() }}</div>
    @endif
</div>

{{-- Модал добавления сертификата --}}
<div id="certModal" class="ct-modal" onclick="if(event.target===this)this.style.display='none'">
    <div class="ct-modal-card" onclick="event.stopPropagation()">
        <div class="ct-modal-head">
            <h5>Добавить сертификат</h5>
            <button type="button" class="ct-modal-close" onclick="document.getElementById('certModal').style.display='none'">&#10005;</button>
        </div>
        <form method="POST" action="{{ route('club.certificates.store') }}">
            @csrf
            <div class="ct-modal-body">
                <div class="ct-field">
                    <label>Тип сертификата</label>
                    <div class="crt-seg">
                        <label class="crt-opt">
                            <input type="radio" name="type" value="generic" checked onchange="crtToggleType()">
                            <span class="crt-opt-ico"><i class="bi bi-ticket-perforated"></i></span>
                            <span>Обычный</span>
                        </label>
                        <label class="crt-opt">
                            <input type="radio" name="type" value="named" onchange="crtToggleType()">
                            <span class="crt-opt-ico"><i class="bi bi-person-badge"></i></span>
                            <span>Именной</span>
                        </label>
                    </div>
                </div>
                <div class="ct-field" id="crtNameField" style="display:none; position:relative;">
                    <label>ФИО получателя (поиск по клиентам)</label>
                    <input type="text" name="recipient_name" id="crtNameInput" placeholder="Начните вводить ФИО или телефон…" maxlength="255" autocomplete="off" oninput="crtSearchClients()">
                    <input type="hidden" name="client_id" id="crtClientId">
                    <div id="crtClientDrop" class="crt-drop" style="display:none;"></div>
                    <div id="crtClientHint" class="crt-hint2"></div>
                </div>
                <div class="ct-field">
                    <label>Номинал сертификата</label>
                    <div class="crt-seg crt-seg-3">
                        <label class="crt-opt">
                            <input type="radio" name="value_type" value="amount" checked onchange="crtToggleValue()">
                            <span class="crt-opt-ico">₸</span>
                            <span>На сумму</span>
                        </label>
                        <label class="crt-opt">
                            <input type="radio" name="value_type" value="hours" onchange="crtToggleValue()">
                            <span class="crt-opt-ico"><i class="bi bi-clock"></i></span>
                            <span>Бесплатные часы</span>
                        </label>
                        <label class="crt-opt">
                            <input type="radio" name="value_type" value="tournament" onchange="crtToggleValue()">
                            <span class="crt-opt-ico"><i class="bi bi-trophy"></i></span>
                            <span>Бесплатный турнир</span>
                        </label>
                    </div>
                </div>
                <div class="ct-field" id="crtAmountField">
                    <label>Сумма, ₸</label>
                    <input type="number" name="amount" min="1" step="1" placeholder="Напр.: 10000">
                </div>
                <div class="ct-field" id="crtHoursField" style="display:none;">
                    <label>Количество часов</label>
                    <input type="number" name="hours" min="1" step="1" value="1" placeholder="Напр.: 1">
                </div>
                <div class="ct-field" id="crtTournField" style="display:none;">
                    <label>Количество турниров</label>
                    <input type="number" name="tournaments" min="1" step="1" value="1" placeholder="Напр.: 1">
                </div>
                <div class="ct-field">
                    <label>Заголовок / за что (необязательно)</label>
                    <input type="text" name="title" placeholder="Напр.: За участие в турнире" maxlength="255">
                </div>
                <p class="crt-hint">Уникальный номер сгенерируется автоматически.</p>
            </div>
            <div class="ct-modal-foot">
                <button type="button" class="btn-cancel" onclick="document.getElementById('certModal').style.display='none'">Отмена</button>
                <button type="submit" class="cc-btn cc-green">Создать</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Недостающие в общем cc-* : статистика и пустое состояние */
.cc-stat { display:inline-flex; flex-direction:column; align-items:center; margin-right:18px; }
.cc-stat b { color:#fff; font-size:1.05rem; line-height:1.1; }
.cc-stat span { color:#a1a1aa; font-size:.72rem; }
.cc-empty { background:#18181b; border:1px solid #27272a; border-radius:12px; padding:28px; text-align:center; color:#a1a1aa; }

/* Модалка (стиль как у карт) */
.ct-modal { display:none; position:fixed; inset:0; z-index:2000; align-items:center; justify-content:center; background:rgba(0,0,0,.7); }
.ct-modal-card { background:#111113; border:1px solid #27272a; border-radius:16px; width:460px; max-width:94vw; }
.ct-modal-head { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #27272a; }
.ct-modal-head h5 { color:#fff; margin:0; font-size:17px; }
.ct-modal-close { background:none; border:none; color:#a1a1aa; font-size:18px; cursor:pointer; }
.ct-modal-body { padding:18px 20px; }
.ct-field { margin-bottom:14px; }
.ct-field label { display:block; color:#a1a1aa; font-size:12px; margin-bottom:6px; }
.ct-field input, .ct-field select { width:100%; background:#18181b; border:1px solid #27272a; border-radius:10px; padding:10px 12px; color:#fff; }
.ct-modal-foot { display:flex; gap:12px; padding:14px 20px; border-top:1px solid #27272a; }
.btn-cancel { flex:1; background:#27272a; color:#d4d4d8; border:none; border-radius:10px; padding:11px; cursor:pointer; }

.crt-table-wrap { overflow-x:auto; background:#18181b; border:1px solid #27272a; border-radius:12px; }
.crt-table { width:100%; border-collapse:collapse; font-size:.92rem; }
.crt-table th { text-align:left; padding:12px 16px; color:#a1a1aa; font-weight:600; border-bottom:1px solid #27272a; white-space:nowrap; }
.crt-table td { padding:12px 16px; border-bottom:1px solid #1f1f23; color:#e4e4e7; vertical-align:middle; }
.crt-table tr:last-child td { border-bottom:none; }
.crt-num { font-family:monospace; color:#22C55E; }
.crt-nominal { font-weight:700; color:#e4e4e7; white-space:nowrap; }
.crt-badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.78rem; }
.crt-named { background:rgba(124,58,237,.18); color:#a78bfa; }
.crt-generic { background:rgba(148,163,184,.15); color:#cbd5e1; }
.crt-actions { text-align:right; white-space:nowrap; }
.cc-btn.cc-sm { padding:5px 12px; font-size:.82rem; }
.cc-btn.cc-danger { background:#3f1d1d; color:#f87171; border:1px solid #7f1d1d; }
.cc-btn.cc-danger:hover { background:#7f1d1d; color:#fff; }
.crt-pagination { margin-top:16px; }
/* Сегментированный выбор (карточки с иконкой) */
.crt-seg { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.crt-seg-3 { grid-template-columns:1fr 1fr 1fr; }
.crt-opt {
    display:flex !important; flex-direction:column; align-items:center; justify-content:center;
    gap:6px; text-align:center; padding:14px 8px;
    border:1.5px solid #27272a; border-radius:12px; cursor:pointer;
    color:#d4d4d8 !important; font-size:.84rem !important; line-height:1.2;
    margin-bottom:0 !important; transition:border-color .15s, background .15s, color .15s; position:relative;
}
.crt-opt:hover { border-color:#3f3f46; }
.crt-opt input { position:absolute; opacity:0; pointer-events:none; }
.crt-opt-ico { font-size:1.15rem; color:#71717a; transition:color .15s; line-height:1; }
.crt-opt:has(input:checked) { border-color:#22C55E; background:rgba(34,197,94,.10); color:#fff !important; }
.crt-opt:has(input:checked) .crt-opt-ico { color:#22C55E; }
.crt-hint { color:#71717a; font-size:.82rem; margin:6px 0 0; }

/* Полировка полей модалки */
#certModal .ct-field input:focus, #certModal .ct-field select:focus { outline:none; border-color:#22C55E; }
#certModal input[type=number]::-webkit-outer-spin-button,
#certModal input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
#certModal input[type=number] { -moz-appearance:textfield; }
#certModal .ct-modal-body { max-height:78vh; overflow-y:auto; }
#certModal .ct-modal-foot { justify-content:flex-end; }
#certModal .btn-cancel { flex:0 0 auto; padding:11px 22px; }
#certModal .ct-modal-foot .cc-btn { padding:11px 26px; }
.ct-modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:14px 20px; border-top:1px solid #27272a; }

/* Автокомплит клиентов */
.crt-drop { position:absolute; left:0; right:0; top:100%; margin-top:4px; z-index:10; background:#111113; border:1px solid #27272a; border-radius:10px; max-height:220px; overflow-y:auto; box-shadow:0 10px 30px rgba(0,0,0,.5); }
.crt-drop-item { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:9px 12px; cursor:pointer; border-bottom:1px solid #1f1f23; }
.crt-drop-item:last-child { border-bottom:none; }
.crt-drop-item:hover { background:#18181b; }
.crt-drop-item span { color:#e4e4e7; font-size:.9rem; }
.crt-drop-item small { color:#22C55E; font-family:monospace; font-size:.8rem; white-space:nowrap; }
.crt-hint2 { color:#71717a; font-size:.8rem; margin-top:6px; min-height:1em; }
</style>

<script>
function openCertModal() {
    document.getElementById('certModal').style.display = 'flex';
    crtToggleType();
    crtToggleValue();
}
function crtToggleValue() {
    var v = document.querySelector('input[name="value_type"]:checked').value;
    document.getElementById('crtAmountField').style.display = v === 'amount' ? 'block' : 'none';
    document.getElementById('crtHoursField').style.display = v === 'hours' ? 'block' : 'none';
    document.getElementById('crtTournField').style.display = v === 'tournament' ? 'block' : 'none';
}
function crtToggleType() {
    var named = document.querySelector('input[name="type"]:checked').value === 'named';
    document.getElementById('crtNameField').style.display = named ? 'block' : 'none';
    if (!named) {
        document.getElementById('crtClientId').value = '';
        document.getElementById('crtClientDrop').style.display = 'none';
        document.getElementById('crtClientHint').textContent = '';
    }
}

var crtSearchTimer = null;
function crtSearchClients() {
    var input = document.getElementById('crtNameInput');
    var drop = document.getElementById('crtClientDrop');
    var hint = document.getElementById('crtClientHint');
    var q = input.value.trim();
    // Ручной ввод сбрасывает привязку к клиенту.
    document.getElementById('crtClientId').value = '';

    if (q.length < 3) {
        drop.style.display = 'none';
        hint.textContent = 'Для поиска введите не меньше 3 символов.';
        return;
    }
    hint.textContent = 'Поиск…';
    clearTimeout(crtSearchTimer);
    crtSearchTimer = setTimeout(function () {
        var isPhone = /^[\d\s\+\-\(\)]+$/.test(q);
        var url = "{{ route('club.clients.search') }}?q=" + encodeURIComponent(q) + "&field=" + (isPhone ? 'phone' : 'name');
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (list) {
                if (!Array.isArray(list) || list.length === 0) {
                    drop.style.display = 'none';
                    hint.textContent = 'Клиент не найден — сертификат создастся на введённое ФИО.';
                    return;
                }
                hint.textContent = 'Найдено: ' + list.length + '. Выберите из списка или оставьте своё ФИО.';
                drop.innerHTML = '';
                list.forEach(function (c) {
                    var it = document.createElement('div');
                    it.className = 'crt-drop-item';
                    var nm = document.createElement('span'); nm.textContent = c.name || '—';
                    var ph = document.createElement('small'); ph.textContent = c.phone || '';
                    it.appendChild(nm); it.appendChild(ph);
                    it.addEventListener('click', function () {
                        input.value = c.name || '';
                        document.getElementById('crtClientId').value = c.id;
                        drop.style.display = 'none';
                        hint.textContent = 'Выбран клиент: ' + (c.name || '') + (c.phone ? ' · ' + c.phone : '');
                    });
                    drop.appendChild(it);
                });
                drop.style.display = 'block';
            })
            .catch(function () { drop.style.display = 'none'; hint.textContent = ''; });
    }, 300);
}
</script>
@endsection

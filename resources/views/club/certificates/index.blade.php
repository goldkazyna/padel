@extends('layouts.app')
@section('title', 'Сертификаты')

@section('content')
<div class="cc-page">
    <div class="cc-head">
        <h1 class="cc-title">Сертификаты <span class="cc-club">— {{ $club->name }}</span></h1>
        <span class="cc-stat"><b>{{ $certificates->total() }}</b><span>Всего</span></span>
        <span class="cc-spacer"></span>
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
                    <div class="crt-type-toggle">
                        <label class="crt-type-opt">
                            <input type="radio" name="type" value="generic" checked onchange="crtToggleType()">
                            <span>Обычный</span>
                        </label>
                        <label class="crt-type-opt">
                            <input type="radio" name="type" value="named" onchange="crtToggleType()">
                            <span>Именной</span>
                        </label>
                    </div>
                </div>
                <div class="ct-field" id="crtNameField" style="display:none;">
                    <label>ФИО получателя</label>
                    <input type="text" name="recipient_name" id="crtNameInput" placeholder="Иванов Иван Иванович" maxlength="255">
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
.crt-table-wrap { overflow-x:auto; background:#18181b; border:1px solid #27272a; border-radius:12px; }
.crt-table { width:100%; border-collapse:collapse; font-size:.92rem; }
.crt-table th { text-align:left; padding:12px 16px; color:#a1a1aa; font-weight:600; border-bottom:1px solid #27272a; white-space:nowrap; }
.crt-table td { padding:12px 16px; border-bottom:1px solid #1f1f23; color:#e4e4e7; vertical-align:middle; }
.crt-table tr:last-child td { border-bottom:none; }
.crt-num { font-family:monospace; color:#22C55E; }
.crt-badge { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.78rem; }
.crt-named { background:rgba(124,58,237,.18); color:#a78bfa; }
.crt-generic { background:rgba(148,163,184,.15); color:#cbd5e1; }
.crt-actions { text-align:right; white-space:nowrap; }
.cc-btn.cc-sm { padding:5px 12px; font-size:.82rem; }
.cc-btn.cc-danger { background:#3f1d1d; color:#f87171; border:1px solid #7f1d1d; }
.cc-btn.cc-danger:hover { background:#7f1d1d; color:#fff; }
.crt-pagination { margin-top:16px; }
.crt-type-toggle { display:flex; gap:10px; }
.crt-type-opt { flex:1; display:flex; align-items:center; gap:8px; padding:10px 14px; border:1px solid #27272a; border-radius:10px; cursor:pointer; color:#e4e4e7; }
.crt-type-opt input { accent-color:#22C55E; }
.crt-hint { color:#71717a; font-size:.82rem; margin:6px 0 0; }
.ct-modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:14px 20px; border-top:1px solid #27272a; }
</style>

<script>
function openCertModal() {
    document.getElementById('certModal').style.display = 'flex';
    crtToggleType();
}
function crtToggleType() {
    var named = document.querySelector('input[name="type"]:checked').value === 'named';
    document.getElementById('crtNameField').style.display = named ? 'block' : 'none';
}
</script>
@endsection

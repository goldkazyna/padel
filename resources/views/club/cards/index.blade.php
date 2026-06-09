@extends('layouts.app')
@section('title', 'Клубные карты')

@section('content')
<div class="cards-page">
    <div class="cards-header">
        <h1 class="cards-title">Клубные карты <span class="cards-title-club">— {{ $club->name }}</span></h1>
        <button class="btn-add" onclick="openCardTypeModal()">+ Создать тип карты</button>
    </div>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash-message flash-error">{{ session('error') }}</div>@endif
    @if($errors->any())
        <div class="flash-message flash-error">@foreach($errors->all() as $e){{ $e }}<br>@endforeach</div>
    @endif

    <div class="cards-stat-row">
        <div class="cards-stat"><span class="cards-stat-num">{{ $issuedCount }}</span><span class="cards-stat-label">Выпущено карт</span></div>
    </div>

    <h2 class="cards-section-title">Типы карт</h2>
    @if($types->isEmpty())
        <div class="cards-empty">Типов карт пока нет. Создайте первый.</div>
    @else
    <div class="cards-types">
        @foreach($types as $t)
        <div class="card-type">
            <div class="ct-main">
                <div class="ct-name">{{ $t->name }}</div>
                <div class="ct-kind">{{ $t->kindName() }}</div>
            </div>
            <div class="ct-val">
                @if($t->isCounter())
                    <span class="ct-badge">{{ $t->nominal }} занятий</span>
                @else
                    <span class="ct-badge ct-badge-discount">−{{ $t->discount_percent }}%</span>
                @endif
                @if($t->default_validity_days)
                    <span class="ct-validity">срок {{ $t->default_validity_days }} дн.</span>
                @else
                    <span class="ct-validity">бессрочно</span>
                @endif
            </div>
            <div class="ct-actions">
                <span class="ct-issued">{{ $t->active_cards_count }} активных</span>
                <button class="ct-btn" title="Редактировать"
                    onclick='openCardTypeModal(@json($t))'><i class="bi bi-pencil"></i></button>
                <form action="{{ route('club.cardTypes.destroy', $t) }}" method="POST" class="d-inline"
                      onsubmit="return confirm('Удалить тип карты «{{ $t->name }}»?')">
                    @csrf @method('DELETE')
                    <button class="ct-btn ct-btn-del" title="Удалить"><i class="bi bi-x-lg"></i></button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>

<!-- Модал создания/редактирования типа -->
<div id="cardTypeModal" class="ct-modal" onclick="if(event.target===this)this.style.display='none'">
    <div class="ct-modal-card" onclick="event.stopPropagation()">
        <div class="ct-modal-head">
            <h5 id="ctModalTitle">Создать тип карты</h5>
            <button type="button" class="ct-modal-close" onclick="document.getElementById('cardTypeModal').style.display='none'">&#10005;</button>
        </div>
        <form id="cardTypeForm" method="POST" action="{{ route('club.cardTypes.store') }}">
            @csrf
            <input type="hidden" name="_method" id="ctMethod" value="POST">
            <div class="ct-modal-body">
                <div class="ct-field">
                    <label>Название *</label>
                    <input type="text" name="name" id="ctName" required placeholder="Напр.: 10 посещений">
                </div>
                <div class="ct-field">
                    <label>Вид карты *</label>
                    <select name="kind" id="ctKind" onchange="ctToggleKind()">
                        <option value="visits">Посещения корта</option>
                        <option value="trainer">Занятия с тренером</option>
                        <option value="discount_court">Скидка на корт</option>
                        <option value="discount_trainer">Скидка на тренера</option>
                    </select>
                </div>
                <div class="ct-field" id="ctNominalField">
                    <label>Номинал (число занятий)</label>
                    <input type="number" name="nominal" id="ctNominal" min="1" max="10000" value="10">
                </div>
                <div class="ct-field" id="ctDiscountField" style="display:none;">
                    <label>Скидка, %</label>
                    <input type="number" name="discount_percent" id="ctDiscount" min="1" max="100" value="10">
                </div>
                <div class="ct-field">
                    <label>Срок действия по умолчанию, дней (пусто = бессрочно)</label>
                    <input type="number" name="default_validity_days" id="ctValidity" min="1" max="3650" placeholder="бессрочно">
                </div>
            </div>
            <div class="ct-modal-foot">
                <button type="button" class="btn-cancel" onclick="document.getElementById('cardTypeModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<style>
.cards-page { max-width: 980px; }
.cards-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
.cards-title { font-size:24px; font-weight:800; color:#fff; margin:0; }
.cards-title-club { color:#71717a; font-weight:500; font-size:16px; }
.btn-add { background:#22c55e; color:#fff; border:none; border-radius:10px; padding:10px 16px; font-weight:700; cursor:pointer; }
.flash-message { padding:10px 14px; border-radius:10px; margin-bottom:14px; }
.flash-success { background:rgba(34,197,94,.12); color:#22c55e; border:1px solid rgba(34,197,94,.3); }
.flash-error { background:rgba(239,68,68,.12); color:#ef4444; border:1px solid rgba(239,68,68,.3); }
.cards-stat-row { display:flex; gap:12px; margin-bottom:20px; }
.cards-stat { background:#18181b; border:1px solid #27272a; border-radius:12px; padding:14px 20px; min-width:140px; }
.cards-stat-num { display:block; font-size:26px; font-weight:800; color:#fff; }
.cards-stat-label { color:#a1a1aa; font-size:12px; }
.cards-section-title { color:#a1a1aa; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin:8px 0 12px; }
.cards-empty { color:#71717a; padding:24px; text-align:center; background:#18181b; border:1px solid #27272a; border-radius:12px; }
.cards-types { display:flex; flex-direction:column; gap:10px; }
.card-type { display:flex; align-items:center; gap:16px; background:#18181b; border:1px solid #27272a; border-radius:12px; padding:14px 16px; }
.ct-main { flex:1; min-width:0; }
.ct-name { color:#fff; font-weight:700; font-size:15px; }
.ct-kind { color:#a1a1aa; font-size:12px; margin-top:2px; }
.ct-val { display:flex; flex-direction:column; gap:4px; align-items:flex-end; }
.ct-badge { background:rgba(124,58,237,.18); color:#a78bfa; padding:3px 10px; border-radius:6px; font-size:12px; font-weight:700; }
.ct-badge-discount { background:rgba(245,132,70,.18); color:#f08446; }
.ct-validity { color:#71717a; font-size:11px; }
.ct-actions { display:flex; align-items:center; gap:8px; }
.ct-issued { color:#71717a; font-size:12px; margin-right:6px; }
.ct-btn { background:#27272a; border:none; color:#d4d4d8; width:32px; height:32px; border-radius:8px; cursor:pointer; }
.ct-btn-del { color:#ef4444; }
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
.btn-save { flex:2; background:#22c55e; color:#fff; border:none; border-radius:10px; padding:11px; font-weight:700; cursor:pointer; }
</style>

<script>
function ctToggleKind() {
    const kind = document.getElementById('ctKind').value;
    const counter = (kind === 'visits' || kind === 'trainer');
    document.getElementById('ctNominalField').style.display = counter ? '' : 'none';
    document.getElementById('ctDiscountField').style.display = counter ? 'none' : '';
}
function openCardTypeModal(t) {
    const form = document.getElementById('cardTypeForm');
    if (t) {
        document.getElementById('ctModalTitle').textContent = 'Редактировать тип карты';
        form.action = '{{ url("club/card-types") }}/' + t.id;
        document.getElementById('ctMethod').value = 'PUT';
        document.getElementById('ctName').value = t.name || '';
        document.getElementById('ctKind').value = t.kind;
        document.getElementById('ctNominal').value = t.nominal || 10;
        document.getElementById('ctDiscount').value = t.discount_percent || 10;
        document.getElementById('ctValidity').value = t.default_validity_days || '';
    } else {
        document.getElementById('ctModalTitle').textContent = 'Создать тип карты';
        form.action = '{{ route("club.cardTypes.store") }}';
        document.getElementById('ctMethod').value = 'POST';
        form.reset();
        document.getElementById('ctMethod').value = 'POST';
    }
    ctToggleKind();
    document.getElementById('cardTypeModal').style.display = 'flex';
}
</script>
@endsection

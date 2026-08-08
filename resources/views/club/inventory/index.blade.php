@extends('layouts.app')

@section('content')
<div class="inv-page">
    <div class="inv-head">
        <h1 class="inv-title"><i class="bi bi-box-seam"></i> Инвентарь</h1>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="flash-message flash-error">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    {{-- Добавление позиции --}}
    <form action="{{ route('club.inventory.store') }}" method="POST" class="inv-add">
        @csrf
        <input type="text" name="name" class="inv-input" placeholder="Аренда ракетки"
               value="{{ old('name') }}" required maxlength="255">
        <input type="number" name="price" class="inv-input inv-input-price" placeholder="3000"
               value="{{ old('price') }}" required min="0" step="1">
        <button type="submit" class="inv-btn inv-green">
            <i class="bi bi-plus-lg"></i> Добавить
        </button>
    </form>

    {{-- Список позиций --}}
    @if($items->isEmpty())
        <div class="inv-empty">
            Позиций пока нет. Добавьте первую — например, «Аренда ракетки» за 3000 ₸.
        </div>
    @else
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Название</th>
                    <th>Цена</th>
                    <th>Статус</th>
                    <th class="inv-actions-col"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td>{{ $item->name }}</td>
                        <td>{{ number_format((float) $item->price, 0, ',', ' ') }} ₸</td>
                        <td>
                            @if($item->is_active)
                                <span class="inv-badge inv-on">Активна</span>
                            @else
                                <span class="inv-badge inv-off">Выключена</span>
                            @endif
                        </td>
                        <td class="inv-actions">
                            <button type="button" class="inv-ic"
                                    onclick="openInventoryModal({{ $item->id }}, @js($item->name), {{ (float) $item->price }}, {{ $item->is_active ? 'true' : 'false' }})">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form action="{{ route('club.inventory.destroy', $item) }}" method="POST"
                                  onsubmit="return confirm(@js('Удалить позицию «'.$item->name.'»? Если она может понадобиться позже, лучше выключите её.'))">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inv-ic inv-ic-del"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- Модалка редактирования — структура повторяет _type_modal у клубных карт --}}
<div id="inventoryModal" class="inv-modal" onclick="if(event.target===this)this.style.display='none'">
    <div class="inv-modal-card" onclick="event.stopPropagation()">
        <div class="inv-modal-head">
            <h5>Позиция инвентаря</h5>
            <button type="button" class="inv-modal-close"
                    onclick="document.getElementById('inventoryModal').style.display='none'">&#10005;</button>
        </div>
        <form id="inventoryEditForm" method="POST">
            @csrf
            @method('PUT')
            <div class="inv-modal-body">
                <label class="inv-label">Название</label>
                <input type="text" name="name" id="inventoryName" class="inv-input" required maxlength="255">

                <label class="inv-label">Цена, ₸</label>
                <input type="number" name="price" id="inventoryPrice" class="inv-input" required min="0" step="1">

                <label class="inv-check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" id="inventoryActive" value="1">
                    <span>Активна</span>
                </label>
            </div>
            <div class="inv-modal-foot">
                <button type="submit" class="inv-btn inv-green">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<style>
.flash-message{padding:10px 14px;border-radius:10px;margin-bottom:14px}
.flash-success{background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3)}
.flash-error{background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.3)}
.inv-page{max-width:1000px}
.inv-head{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.inv-title{font-size:21px;font-weight:800;margin:0;letter-spacing:-.3px;color:var(--text-primary)}
.inv-btn{border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;padding:9px 15px;display:inline-flex;align-items:center;gap:6px}
.inv-green{background:var(--accent);color:#06210f}
.inv-green:hover{background:var(--accent-dark)}
.inv-add{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.inv-input{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:9px 12px;color:var(--text-primary);font-size:14px;flex:1 1 260px}
.inv-input-price{flex:0 1 160px}
.inv-table{width:100%;border-collapse:collapse;background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.inv-table th{text-align:left;padding:11px 14px;font-size:12px;color:var(--text-muted);border-bottom:1px solid var(--border);font-weight:700}
.inv-table td{padding:12px 14px;border-bottom:1px solid var(--border);color:var(--text-primary);font-size:14px}
.inv-table tr:last-child td{border-bottom:none}
.inv-actions-col{width:120px}
.inv-actions{display:flex;gap:6px;align-items:center}
.inv-actions form{display:inline}
.inv-ic{background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary);border-radius:8px;padding:6px 9px;cursor:pointer}
.inv-ic:hover{color:var(--text-primary)}
.inv-ic-del:hover{color:#ef4444;border-color:rgba(239,68,68,.4)}
.inv-badge{font-size:11px;font-weight:800;padding:3px 8px;border-radius:7px}
.inv-on{background:rgba(34,197,94,.18);color:#4ade80}
.inv-off{background:rgba(161,161,170,.15);color:#a1a1aa}
.inv-empty{padding:26px;text-align:center;color:var(--text-muted);background:var(--bg-card);border:1px solid var(--border);border-radius:12px}
.inv-modal{display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,.7)}
.inv-modal-card{background:#111113;border:1px solid #27272a;border-radius:16px;width:460px;max-width:94vw}
.inv-modal-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #27272a}
.inv-modal-head h5{color:#fff;margin:0;font-size:17px}
.inv-modal-close{background:none;border:none;color:#a1a1aa;font-size:18px;cursor:pointer}
.inv-modal-body{padding:18px 20px;display:flex;flex-direction:column;gap:10px}
.inv-modal-foot{padding:14px 20px;border-top:1px solid #27272a;display:flex;justify-content:flex-end}
.inv-label{font-size:12px;color:var(--text-muted);font-weight:700}
.inv-check{display:flex;align-items:center;gap:8px;color:var(--text-primary);font-size:14px;cursor:pointer;margin-top:4px}
</style>

<script>
    function openInventoryModal(id, name, price, isActive) {
        const form = document.getElementById('inventoryEditForm');
        form.action = '{{ url('club/inventory') }}/' + id;
        document.getElementById('inventoryName').value = name;
        document.getElementById('inventoryPrice').value = price;
        document.getElementById('inventoryActive').checked = isActive;
        document.getElementById('inventoryModal').style.display = 'flex';
    }
</script>
@endsection

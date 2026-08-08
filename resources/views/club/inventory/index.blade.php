@extends('layouts.app')
@section('title', 'Инвентарь')

@section('content')
<div class="inv-page">

    {{-- Шапка раздела — как на «Клиентах» и «Клубных картах» --}}
    <div class="inv-head">
        <i class="bi bi-box-seam inv-hi"></i>
        <h1 class="inv-title">Инвентарь</h1>
        <span class="inv-count">{{ $items->count() }}</span>
        <span class="inv-club">— {{ $club->name }}</span>
        <span class="inv-spacer"></span>
        <button type="button" class="inv-btn inv-green" onclick="openInventoryAdd()">
            <i class="bi bi-plus-lg"></i> Добавить позицию
        </button>
    </div>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash-message flash-error">{{ session('error') }}</div>@endif
    @if($errors->any())
        <div class="flash-message flash-error">
            @foreach($errors->all() as $error){{ $error }}<br>@endforeach
        </div>
    @endif

    @if($items->isEmpty())
        <div class="inv-empty">
            <i class="bi bi-box-seam"></i>
            Позиций пока нет. Добавьте первую — например, «Аренда ракетки» за 3000 ₸.
        </div>
    @else
        <div class="inv-grid">
            @foreach($items as $item)
                <div class="inv-tile{{ $item->is_active ? '' : ' inv-tile-off' }}">
                    <div class="inv-tile-name">{{ $item->name }}</div>
                    <div class="inv-tile-price{{ $item->is_active ? '' : ' inv-muted' }}">
                        {{ number_format((int) $item->price, 0, ',', ' ') }} ₸
                    </div>
                    <div class="inv-tile-foot">
                        @if($item->is_active)
                            <span class="inv-badge inv-on">Активна</span>
                        @else
                            <span class="inv-badge inv-off">Выключена</span>
                        @endif
                        <span class="inv-spacer"></span>
                        <button type="button" class="inv-ic" title="Редактировать"
                                onclick="openInventoryEdit({{ $item->id }}, @js($item->name), {{ (int) $item->price }}, {{ $item->is_active ? 'true' : 'false' }})">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form action="{{ route('club.inventory.destroy', $item) }}" method="POST"
                              class="inv-del-form"
                              onsubmit="return confirm(@js('Удалить позицию «' . $item->name . '»? Если она может понадобиться позже, лучше выключите её.'))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inv-ic inv-ic-del" title="Удалить">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- Модалка добавления --}}
<div id="invAddModal" class="inv-modal" onclick="if(event.target===this)closeInventoryModals()">
    <div class="inv-modal-card" onclick="event.stopPropagation()">
        <div class="inv-modal-head">
            <h5>Новая позиция</h5>
            <button type="button" class="inv-modal-close" onclick="closeInventoryModals()">&#10005;</button>
        </div>
        <form action="{{ route('club.inventory.store') }}" method="POST">
            @csrf
            {{-- Маркер формы: по нему old() не подставляется в чужую форму --}}
            <input type="hidden" name="inv_form" value="create">
            <div class="inv-modal-body">
                <label class="inv-label" for="invAddName">Название</label>
                <input type="text" name="name" id="invAddName" class="inv-input" required maxlength="255"
                       placeholder="Аренда ракетки"
                       value="{{ old('inv_form') === 'create' ? old('name') : '' }}">

                <label class="inv-label" for="invAddPrice">Цена, ₸</label>
                <input type="number" name="price" id="invAddPrice" class="inv-input" required min="0" step="1"
                       placeholder="3000"
                       value="{{ old('inv_form') === 'create' ? old('price') : '' }}">
            </div>
            <div class="inv-modal-foot">
                <button type="button" class="inv-btn inv-ghost" onclick="closeInventoryModals()">Отмена</button>
                <button type="submit" class="inv-btn inv-green">Добавить</button>
            </div>
        </form>
    </div>
</div>

{{-- Модалка редактирования --}}
<div id="invEditModal" class="inv-modal" onclick="if(event.target===this)closeInventoryModals()">
    <div class="inv-modal-card" onclick="event.stopPropagation()">
        <div class="inv-modal-head">
            <h5>Позиция инвентаря</h5>
            <button type="button" class="inv-modal-close" onclick="closeInventoryModals()">&#10005;</button>
        </div>
        <form id="invEditForm" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="inv_form" value="edit">
            <div class="inv-modal-body">
                <label class="inv-label" for="invEditName">Название</label>
                <input type="text" name="name" id="invEditName" class="inv-input" required maxlength="255">

                <label class="inv-label" for="invEditPrice">Цена, ₸</label>
                <input type="number" name="price" id="invEditPrice" class="inv-input" required min="0" step="1">

                <label class="inv-check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" id="invEditActive" value="1">
                    <span>Активна</span>
                </label>
            </div>
            <div class="inv-modal-foot">
                <button type="button" class="inv-btn inv-ghost" onclick="closeInventoryModals()">Отмена</button>
                <button type="submit" class="inv-btn inv-green">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<style>
.inv-page{max-width:1100px}

/* Шапка */
.inv-head{display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.inv-hi{font-size:20px;color:var(--accent)}
.inv-title{font-size:21px;font-weight:800;margin:0;letter-spacing:-.3px;color:var(--text-primary)}
.inv-count{background:var(--accent-glow);color:var(--accent);font-size:12px;font-weight:800;padding:3px 9px;border-radius:20px}
.inv-club{color:var(--text-muted);font-weight:500;font-size:15px}
.inv-spacer{flex:1}

/* Кнопки */
.inv-btn{border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;padding:9px 15px;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
.inv-green{background:var(--accent);color:#06210f}
.inv-green:hover{background:var(--accent-dark)}
.inv-ghost{background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary)}
.inv-ghost:hover{background:var(--bg-card-hover);color:var(--text-primary)}

/* Плитки позиций */
.inv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.inv-tile{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:10px;transition:.15s}
.inv-tile:hover{background:var(--bg-card-hover);border-color:var(--border-light)}
.inv-tile-off{opacity:.55}
.inv-tile-name{font-weight:700;font-size:15px;line-height:1.3;color:var(--text-primary);word-break:break-word}
.inv-tile-price{font-size:22px;font-weight:800;color:var(--accent)}
.inv-muted{color:var(--text-muted)}
.inv-tile-foot{display:flex;align-items:center;gap:8px;margin-top:auto;padding-top:10px;border-top:1px solid var(--border)}
.inv-del-form{display:inline}

/* Значки статуса */
.inv-badge{font-size:11px;font-weight:800;padding:3px 8px;border-radius:7px}
.inv-on{background:var(--accent-glow);color:var(--accent)}
.inv-off{background:rgba(156,163,175,.16);color:var(--text-secondary)}

/* Кнопки-иконки */
.inv-ic{background:transparent;border:1px solid var(--border);color:var(--text-secondary);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:13px}
.inv-ic:hover{color:var(--text-primary);background:var(--bg-card-hover)}
.inv-ic-del:hover{color:#ef4444;border-color:rgba(239,68,68,.45)}

/* Пустое состояние */
.inv-empty{background:var(--bg-card);border:1px dashed var(--border-light);border-radius:14px;padding:34px;text-align:center;color:var(--text-muted)}
.inv-empty i{font-size:28px;display:block;margin-bottom:10px;opacity:.5}

/* Модалки */
.inv-modal{display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,.7);padding:16px}
.inv-modal.inv-open{display:flex}
.inv-modal-card{background:var(--bg-secondary);border:1px solid var(--border);border-radius:16px;width:460px;max-width:100%}
.inv-modal-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border)}
.inv-modal-head h5{color:var(--text-primary);margin:0;font-size:17px;font-weight:800}
.inv-modal-close{background:none;border:none;color:var(--text-muted);font-size:16px;cursor:pointer}
.inv-modal-close:hover{color:var(--text-primary)}
.inv-modal-body{padding:18px 20px;display:flex;flex-direction:column;gap:8px}
.inv-modal-foot{padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}
.inv-label{font-size:12px;color:var(--text-muted);font-weight:700;margin-top:6px}
.inv-input{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:9px 12px;color:var(--text-primary);font-size:14px;width:100%}
.inv-input:focus{outline:none;border-color:var(--accent)}
.inv-check{display:flex;align-items:center;gap:8px;color:var(--text-primary);font-size:14px;cursor:pointer;margin-top:12px}
</style>

<script>
    const INVENTORY_UPDATE_URL = @js(route('club.inventory.update', ['item' => '__ID__']));

    function closeInventoryModals() {
        document.querySelectorAll('.inv-modal').forEach(m => m.classList.remove('inv-open'));
    }

    function openInventoryAdd() {
        document.getElementById('invAddModal').classList.add('inv-open');
        document.getElementById('invAddName').focus();
    }

    function openInventoryEdit(id, name, price, isActive) {
        document.getElementById('invEditForm').action = INVENTORY_UPDATE_URL.replace('__ID__', id);
        document.getElementById('invEditName').value = name;
        document.getElementById('invEditPrice').value = price;
        document.getElementById('invEditActive').checked = isActive;
        document.getElementById('invEditModal').classList.add('inv-open');
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeInventoryModals();
    });

    // Добавление не прошло валидацию — сразу открываем модалку с введёнными значениями,
    // чтобы не заставлять набирать заново.
    @if($errors->any() && old('inv_form') === 'create')
        openInventoryAdd();
    @endif
</script>
@endsection

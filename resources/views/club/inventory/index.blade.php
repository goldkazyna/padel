@extends('layouts.app')
@section('title', 'Инвентарь')

@section('content')
<div class="inv-page">

    {{-- Шапка раздела. Размеры и отступы — как на странице «Клиенты». --}}
    <header class="inv-header">
        <div class="inv-title-block">
            <i class="bi bi-box-seam"></i>
            <h1 class="inv-title">Инвентарь</h1>
            <span class="inv-count">{{ $items->count() }}</span>
            <span class="inv-club">{{ $club->name }}</span>
        </div>
        <div class="inv-header-actions">
            <button type="button" class="inv-btn-add" onclick="openInventoryAdd()">
                <i class="bi bi-plus-lg"></i>
                Добавить позицию
            </button>
        </div>
    </header>

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
                    @if(($outByItem[$item->id] ?? 0) > 0)
                        {{-- Сколько единиц этой позиции сейчас не вернули --}}
                        <span class="inv-out-badge">{{ $outByItem[$item->id] }} на руках</span>
                    @endif
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

    {{-- ===================== Выданный инвентарь ===================== --}}
    <section class="inv-issued">
        <div class="inv-sec-head">
            <h2 class="inv-sec-title">Выданный инвентарь</h2>
            @if($holders->isNotEmpty())
                @php $hn = $holders->count(); @endphp
                <span class="inv-sec-count">
                    {{ $hn }}
                    @php
                        $mod100 = $hn % 100;
                        $mod10 = $hn % 10;
                    @endphp
                    @if($mod100 >= 11 && $mod100 <= 14) клиентов
                    @elseif($mod10 === 1) клиент
                    @elseif(in_array($mod10, [2, 3, 4])) клиента
                    @else клиентов
                    @endif
                </span>
            @endif
            <span class="inv-spacer"></span>
            <button type="button" class="inv-btn-give" onclick="openInventoryGive()">
                <i class="bi bi-box-arrow-up"></i>
                Выдать
            </button>
        </div>

        @if($holders->isEmpty())
            <div class="inv-empty">
                <i class="bi bi-hand-index"></i>
                Ничего не выдано. Нажмите «Выдать», когда отдаёте клиенту ракетку или мячи —
                чтобы потом не гадать, у кого они остались.
            </div>
        @else
            <div class="inv-cards">
                @foreach($holders as $holder)
                    <div class="inv-card{{ $holder['late'] ? ' inv-card-late' : '' }}">
                        <div class="inv-card-top">
                            <div class="inv-ava">{{ mb_substr($holder['client']->name ?? '?', 0, 1) }}</div>
                            <div class="inv-card-who">
                                <b>{{ $holder['client']->name ?? 'Клиент удалён' }}</b>
                                <span>{{ $holder['client']->phone ?? '' }}</span>
                            </div>
                        </div>

                        <div class="inv-lines">
                            @foreach($holder['lines'] as $line)
                                <div class="inv-line">
                                    <span class="inv-line-name">{{ $line->name }}</span>
                                    <span class="inv-line-qty">&times;{{ $line->quantity }}</span>
                                    <form action="{{ route('club.inventory.returnItem', $line) }}" method="POST" class="inv-line-form">
                                        @csrf
                                        <button type="submit" class="inv-line-take" title="Принять эту позицию">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                </div>
                            @endforeach
                        </div>

                        <div class="inv-card-foot">
                            <span class="inv-age">
                                @if($holder['late'])<i class="bi bi-exclamation-triangle-fill"></i>@endif
                                {{ $holder['age'] }} на руках
                            </span>
                            <span class="inv-spacer"></span>
                            <form action="{{ route('club.inventory.returnClient', $holder['client']) }}" method="POST">
                                @csrf
                                <button type="submit" class="inv-btn-take">Принять всё</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>

{{-- Модалка выдачи --}}
<div id="invGiveModal" class="inv-modal" onclick="if(event.target===this)closeInventoryModals()">
    <div class="inv-modal-card" onclick="event.stopPropagation()">
        <div class="inv-modal-head">
            <h5>Выдать инвентарь</h5>
            <button type="button" class="inv-modal-close" onclick="closeInventoryModals()">&#10005;</button>
        </div>
        <form action="{{ route('club.inventory.issue') }}" method="POST" id="invGiveForm">
            @csrf
            <div class="inv-modal-body">
                <label class="inv-label" for="invGiveClient">Клиент</label>
                <div class="inv-client-pick">
                    <input type="text" id="invGiveClient" class="inv-input" autocomplete="off"
                           placeholder="Начните вводить имя или телефон">
                    <input type="hidden" name="club_client_id" id="invGiveClientId">
                    <div id="invClientResults" class="inv-suggest"></div>
                </div>

                <label class="inv-label">Что выдаём</label>
                <div class="inv-pickrow">
                    <select id="invGiveItem" class="inv-input">
                        @foreach($issuable as $it)
                            <option value="{{ $it->id }}" data-name="{{ $it->name }}">{{ $it->name }}</option>
                        @endforeach
                    </select>
                    <input type="number" id="invGiveQty" class="inv-input inv-qty" value="1" min="1" max="999">
                    <button type="button" class="inv-btn inv-ghost" onclick="addInventoryLine()" title="Добавить позицию">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
                <div id="invPicked" class="inv-picked"></div>

                <label class="inv-label" for="invGiveComment">Комментарий</label>
                <input type="text" name="comment" id="invGiveComment" class="inv-input" maxlength="1000"
                       placeholder="Например: оставил документ в залог">

                <p class="inv-note">
                    Выдача не создаёт продажу и не идёт в кассу — это учёт того,
                    что ушло с полки и должно вернуться.
                </p>
            </div>
            <div class="inv-modal-foot">
                <button type="button" class="inv-btn inv-ghost" onclick="closeInventoryModals()">Отмена</button>
                <button type="submit" class="inv-btn inv-green">Выдать</button>
            </div>
        </form>
    </div>
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
/* Отступы контейнера — как у .clients-container */
.inv-page{width:100%;padding:32px 40px}

/* Шапка: те же размеры и отступы, что у .clients-header */
.inv-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;gap:16px;flex-wrap:wrap}
.inv-title-block{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.inv-title-block i{font-size:26px;color:var(--accent)}
.inv-title{font-size:26px;font-weight:800;letter-spacing:-.5px;margin:0;color:var(--text-primary)}
.inv-count{background:var(--accent);color:var(--bg-primary);padding:4px 12px;border-radius:20px;font-size:13px;font-weight:700}
.inv-club{color:var(--text-muted);font-weight:500;font-size:15px}
.inv-header-actions{display:flex;align-items:center;gap:10px}
.inv-spacer{flex:1}

/* Кнопка добавления — как .btn-add-client */
.inv-btn-add{display:flex;align-items:center;gap:8px;background:var(--accent);color:var(--bg-primary);border:none;padding:12px 20px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer}
.inv-btn-add:hover{background:var(--accent-dark)}

/* Кнопки в модалках */
.inv-btn{border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:14px;padding:11px 18px;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
.inv-green{background:var(--accent);color:var(--bg-primary)}
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

/* ===================== Выданный инвентарь ===================== */

/* Красный бейдж на плитке: сколько единиц позиции сейчас на руках */
.inv-out-badge{position:absolute;top:-8px;right:-6px;background:#ef4444;color:#fff;font-size:11px;
    font-weight:800;padding:3px 9px;border-radius:20px;box-shadow:0 2px 10px rgba(239,68,68,.45);white-space:nowrap}
.inv-tile{position:relative}

.inv-issued{margin-top:34px}
.inv-sec-head{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.inv-sec-title{font-size:19px;font-weight:800;color:var(--text-primary);margin:0}
.inv-sec-count{background:rgba(239,68,68,.14);color:#ef4444;font-size:12px;font-weight:800;padding:3px 10px;border-radius:9px}
.inv-btn-give{background:var(--accent);border:none;color:#062b14;border-radius:10px;padding:8px 14px;
    font-size:13px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:7px}
.inv-btn-give:hover{background:var(--accent-dark);color:#fff}

.inv-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.inv-card{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:16px;
    display:flex;flex-direction:column;gap:12px;position:relative;overflow:hidden}
/* Полоска слева: красная — на руках, жёлтая — висит больше суток */
.inv-card::before{content:'';position:absolute;top:0;bottom:0;left:0;width:3px;background:#ef4444}
.inv-card-late::before{background:#f59e0b}
.inv-card-top{display:flex;align-items:center;gap:11px}
.inv-ava{width:40px;height:40px;border-radius:11px;background:var(--accent-glow);color:var(--accent);
    display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;flex:none;text-transform:uppercase}
.inv-card-who b{font-size:14.5px;color:var(--text-primary);display:block;line-height:1.3;word-break:break-word}
.inv-card-who span{font-size:12px;color:var(--text-muted)}

.inv-lines{display:flex;flex-direction:column;gap:7px}
.inv-line{display:flex;align-items:center;gap:8px;font-size:13.5px;padding:7px 10px;
    background:var(--bg-primary);border:1px solid var(--border);border-radius:9px}
.inv-line-name{flex:1;color:var(--text-primary);word-break:break-word}
.inv-line-qty{font-weight:800;color:var(--accent)}
.inv-line-form{display:inline;line-height:0}
.inv-line-take{background:transparent;border:none;color:var(--text-muted);cursor:pointer;font-size:13px;padding:2px 4px}
.inv-line-take:hover{color:var(--accent)}

.inv-card-foot{display:flex;align-items:center;gap:8px;margin-top:auto;padding-top:11px;border-top:1px solid var(--border)}
.inv-age{font-size:12.5px;font-weight:700;color:#ef4444}
.inv-card-late .inv-age{color:#f59e0b}
.inv-btn-take{background:var(--accent-glow);border:1px solid var(--accent);color:var(--accent);
    border-radius:10px;padding:7px 13px;font-size:13px;font-weight:800;cursor:pointer}
.inv-btn-take:hover{background:var(--accent);color:#062b14}

/* Форма выдачи */
.inv-client-pick{position:relative}
.inv-suggest{display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:20;
    background:var(--bg-card);border:1px solid var(--border-light);border-radius:10px;overflow:hidden;max-height:230px;overflow-y:auto}
.inv-suggest.inv-open{display:block}
.inv-suggest button{display:block;width:100%;text-align:left;background:transparent;border:none;
    padding:9px 12px;color:var(--text-primary);font-size:13.5px;cursor:pointer}
.inv-suggest button:hover{background:var(--bg-card-hover)}
.inv-suggest button span{display:block;font-size:12px;color:var(--text-muted)}
.inv-suggest .inv-suggest-empty{padding:10px 12px;color:var(--text-muted);font-size:13px}
.inv-pickrow{display:flex;gap:8px;align-items:center}
.inv-qty{width:96px;flex:none}
.inv-picked{display:flex;flex-direction:column;gap:7px;margin-top:6px}
.inv-note{font-size:12px;color:var(--text-muted);line-height:1.5;margin:6px 0 0}

/* Адаптив — та же граница и отступы, что у .clients-container */
@media (max-width: 900px) {
    .inv-page{padding:24px 20px}
    .inv-title, .inv-title-block i{font-size:22px}
    .inv-header-actions{width:100%}
    .inv-btn-add{width:100%;justify-content:center}
    .inv-cards{grid-template-columns:1fr}
}
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

    /* ===================== Выдача инвентаря ===================== */

    const CLIENTS_SEARCH_URL = @js(route('club.clients.search'));

    // Набранные позиции. Держим в массиве и перерисовываем целиком: имена полей
    // должны идти сплошными индексами, иначе после удаления строки id и количество
    // разъедутся по разным элементам массива на сервере.
    let invPicked = [];

    function openInventoryGive() {
        invPicked = [];
        renderInventoryPicked();
        document.getElementById('invGiveClient').value = '';
        document.getElementById('invGiveClientId').value = '';
        document.getElementById('invGiveComment').value = '';
        document.getElementById('invGiveQty').value = 1;
        hideInventorySuggest();
        document.getElementById('invGiveModal').classList.add('inv-open');
        document.getElementById('invGiveClient').focus();
    }

    function hideInventorySuggest() {
        document.getElementById('invClientResults').classList.remove('inv-open');
    }

    let invSearchTimer = null;
    document.getElementById('invGiveClient')?.addEventListener('input', function () {
        // Выбранного клиента сбрасываем: текст правят — значит выбор больше не актуален.
        document.getElementById('invGiveClientId').value = '';
        const q = this.value.trim();
        clearTimeout(invSearchTimer);
        if (q.length < 2) { hideInventorySuggest(); return; }

        invSearchTimer = setTimeout(() => {
            // Цифры в строке — значит ищут по телефону.
            const field = /\d/.test(q) ? 'phone' : 'name';
            fetch(`${CLIENTS_SEARCH_URL}?q=${encodeURIComponent(q)}&field=${field}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(r => r.json())
                .then(list => renderInventorySuggest(list))
                .catch(() => hideInventorySuggest());
        }, 250);
    });

    function renderInventorySuggest(list) {
        const box = document.getElementById('invClientResults');
        box.innerHTML = '';
        if (!Array.isArray(list) || list.length === 0) {
            box.innerHTML = '<div class="inv-suggest-empty">Никого не нашли</div>';
            box.classList.add('inv-open');
            return;
        }
        list.forEach(c => {
            const b = document.createElement('button');
            b.type = 'button';
            b.innerHTML = `${escapeInventoryHtml(c.name)}<span>${escapeInventoryHtml(c.phone || '')}</span>`;
            b.onclick = () => {
                document.getElementById('invGiveClient').value = c.name;
                document.getElementById('invGiveClientId').value = c.id;
                hideInventorySuggest();
            };
            box.appendChild(b);
        });
        box.classList.add('inv-open');
    }

    function addInventoryLine() {
        const sel = document.getElementById('invGiveItem');
        const opt = sel.options[sel.selectedIndex];
        if (!opt) return;

        const qty = Math.max(1, parseInt(document.getElementById('invGiveQty').value, 10) || 1);
        const id = parseInt(opt.value, 10);

        // Ту же позицию добавили второй раз — складываем, а не плодим строки.
        const found = invPicked.find(p => p.id === id);
        if (found) {
            found.quantity += qty;
        } else {
            invPicked.push({ id: id, name: opt.dataset.name, quantity: qty });
        }

        document.getElementById('invGiveQty').value = 1;
        renderInventoryPicked();
    }

    function removeInventoryLine(id) {
        invPicked = invPicked.filter(p => p.id !== id);
        renderInventoryPicked();
    }

    function renderInventoryPicked() {
        const box = document.getElementById('invPicked');
        box.innerHTML = '';
        invPicked.forEach((p, i) => {
            const row = document.createElement('div');
            row.className = 'inv-line';
            row.innerHTML =
                `<span class="inv-line-name">${escapeInventoryHtml(p.name)}</span>` +
                `<span class="inv-line-qty">&times;${p.quantity}</span>` +
                `<input type="hidden" name="items[${i}][id]" value="${p.id}">` +
                `<input type="hidden" name="items[${i}][quantity]" value="${p.quantity}">`;
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'inv-line-take';
            del.title = 'Убрать из выдачи';
            del.innerHTML = '<i class="bi bi-x-lg"></i>';
            del.onclick = () => removeInventoryLine(p.id);
            row.appendChild(del);
            box.appendChild(row);
        });
    }

    function escapeInventoryHtml(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    // Не отправляем заведомо пустую форму — сервер ответит тем же, но лишним перезагрузом.
    document.getElementById('invGiveForm')?.addEventListener('submit', function (e) {
        if (!document.getElementById('invGiveClientId').value) {
            e.preventDefault();
            alert('Выберите клиента из подсказки');
            return;
        }
        if (invPicked.length === 0) {
            e.preventDefault();
            alert('Добавьте хотя бы одну позицию');
        }
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('.inv-client-pick')) hideInventorySuggest();
    });

    // Добавление не прошло валидацию — сразу открываем модалку с введёнными значениями,
    // чтобы не заставлять набирать заново.
    @if($errors->any() && old('inv_form') === 'create')
        openInventoryAdd();
    @endif
</script>
@endsection

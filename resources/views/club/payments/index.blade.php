@extends('layouts.app')

@section('title', 'Платежи')

@section('content')
@php
    $plexyReady = $club->hasPlexyConfigured();
    $newLinkId = session('new_link_id');
@endphp

<div class="page-header">
    <div>
        <h2>Платежи</h2>
        <p>{{ $club->name }} · счета клиентам по ссылке</p>
    </div>
    @if($plexyReady)
        <button type="button" class="btn-primary-custom" onclick="openBillModal()">
            <i class="bi bi-plus-lg"></i> Выставить счёт
        </button>
    @endif
</div>

@if(session('success'))
    <div class="pay-alert pay-alert-ok">
        <i class="bi bi-check-circle"></i> {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="pay-alert pay-alert-err">
        <i class="bi bi-exclamation-triangle"></i> {{ session('error') }}
    </div>
@endif

@if(!$plexyReady)
    <div class="pay-empty">
        <i class="bi bi-credit-card"></i>
        <div>
            <b>Онлайн-оплата не настроена</b>
            <span>Чтобы выставлять счета, супер-админ должен указать ключи Plexy в настройках клуба и включить онлайн-оплату.</span>
        </div>
    </div>
@else

<div class="pay-wrap">
    {{-- Сводка за 30 дней --}}
    <div class="pay-stats">
        <div class="pay-stat">
            <div class="pay-stat-icon ok"><i class="bi bi-check-lg"></i></div>
            <div>
                <b>{{ number_format($paidSum, 0, '.', ' ') }} ₸</b>
                <span>получено за 30 дней · {{ $paidCount }} шт.</span>
            </div>
        </div>
        <div class="pay-stat">
            <div class="pay-stat-icon wait"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <b>{{ number_format($pendingSum, 0, '.', ' ') }} ₸</b>
                <span>ждут оплаты · {{ $pendingCount }} шт.</span>
            </div>
        </div>
    </div>

    {{-- Фильтры --}}
    <form method="GET" class="pay-filter">
        <input type="text" name="search" value="{{ request('search') }}"
               class="pay-input" placeholder="Описание, имя или телефон">
        <select name="status" class="pay-input">
            <option value="">Все статусы</option>
            <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Ждут оплаты</option>
            <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>Оплачены</option>
            <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>Просрочены</option>
            <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Отменены</option>
        </select>
        <button type="submit" class="pay-btn-ghost">Показать</button>
        @if(request('search') || request('status'))
            <a href="{{ route('club.payments.index') }}" class="pay-btn-ghost">Сбросить</a>
        @endif
    </form>

    @if($links->isEmpty())
        <div class="pay-empty">
            <i class="bi bi-receipt"></i>
            <div>
                <b>Счетов пока нет</b>
                <span>Нажмите «Выставить счёт», укажите сумму и назначение — получите ссылку для клиента.</span>
            </div>
        </div>
    @else
        <div class="pay-list">
            @foreach($links as $link)
                <div class="pay-card {{ $link->id == $newLinkId ? 'fresh' : '' }}">
                    <div class="pay-card-main">
                        <div class="pay-card-top">
                            <span class="pay-amount">{{ number_format($link->amount, 0, '.', ' ') }} ₸</span>
                            @php
                                $badge = match(true) {
                                    $link->isPaid() => 'paid',
                                    $link->status === 'cancelled' => 'off',
                                    $link->isStale() || $link->status === 'expired' => 'stale',
                                    default => 'wait',
                                };
                            @endphp
                            <span class="pay-badge {{ $badge }}">{{ $link->statusLabel() }}</span>
                        </div>
                        <div class="pay-desc">{{ $link->description }}</div>
                        <div class="pay-meta">
                            @if($link->client_name)
                                <span><i class="bi bi-person"></i> {{ $link->client_name }}</span>
                            @endif
                            @if($link->client_phone)
                                <span><i class="bi bi-telephone"></i> {{ $link->client_phone }}</span>
                            @endif
                            <span><i class="bi bi-calendar3"></i>
                                {{ $link->created_at->timezone(\App\Models\Shift::TZ)->format('d.m.Y, H:i') }}
                            </span>
                            @if($link->creator)
                                <span><i class="bi bi-person-badge"></i> {{ $link->creator->name }}</span>
                            @endif
                            @if($link->isPaid() && $link->paid_at)
                                <span class="ok"><i class="bi bi-check-circle"></i>
                                    оплачен {{ $link->paid_at->timezone(\App\Models\Shift::TZ)->format('d.m.Y, H:i') }}
                                </span>
                            @elseif($link->expires_at && $link->status === 'pending')
                                <span><i class="bi bi-clock"></i>
                                    до {{ $link->expires_at->timezone(\App\Models\Shift::TZ)->format('d.m.Y, H:i') }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="pay-card-actions">
                        @if($link->plexy_url && !$link->isPaid() && $link->status !== 'cancelled')
                            <button type="button" class="pay-act"
                                    onclick="copyLink(this, '{{ $link->plexy_url }}')" title="Скопировать ссылку">
                                <i class="bi bi-clipboard"></i> Копировать
                            </button>
                            @if($link->client_phone)
                                @php
                                    $wa = preg_replace('/\D/', '', $link->client_phone);
                                    $text = rawurlencode($link->description . ' — ' . number_format($link->amount, 0, '.', ' ') . ' ₸: ' . $link->plexy_url);
                                @endphp
                                <a class="pay-act" target="_blank" rel="noopener"
                                   href="https://wa.me/{{ $wa }}?text={{ $text }}" title="Отправить в WhatsApp">
                                    <i class="bi bi-whatsapp"></i> WhatsApp
                                </a>
                            @endif
                            <form method="POST" action="{{ route('club.payments.sync', $link) }}" class="d-inline">
                                @csrf
                                <button class="pay-act" title="Спросить банк о статусе">
                                    <i class="bi bi-arrow-clockwise"></i> Проверить
                                </button>
                            </form>
                            <form method="POST" action="{{ route('club.payments.cancel', $link) }}" class="d-inline"
                                  onsubmit="return confirm('Отменить счёт? Ссылка перестанет работать.')">
                                @csrf
                                @method('DELETE')
                                <button class="pay-act danger" title="Отменить счёт">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        @elseif($link->isPaid())
                            <span class="pay-done"><i class="bi bi-check-circle-fill"></i></span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-3">{{ $links->links() }}</div>
    @endif
</div>

{{-- Окно выставления счёта --}}
<div class="pay-overlay" id="billOverlay" onclick="if(event.target === this) closeBillModal()">
    <form method="POST" action="{{ route('club.payments.store') }}" class="pay-modal" id="billForm">
        @csrf
        <div class="pay-modal-head">
            <div>
                <div class="pay-eyebrow"><i class="bi bi-receipt"></i> Счёт клиенту</div>
                <div class="pay-modal-title">Новая ссылка на оплату</div>
            </div>
            <button type="button" class="pay-close" onclick="closeBillModal()"><i class="bi bi-x-lg"></i></button>
        </div>

        <label class="pay-label">Сумма, ₸</label>
        <input type="number" name="amount" class="pay-input full" min="1" step="1"
               value="{{ old('amount') }}" required autofocus>

        <label class="pay-label">За что</label>
        <input type="text" name="description" class="pay-input full" maxlength="200"
               value="{{ old('description') }}" placeholder="Клубная карта на 10 часов" required>

        <label class="pay-label">Клиент <span class="pay-opt">необязательно</span></label>
        <div class="pay-search">
            <input type="text" id="clientQuery" class="pay-input full" autocomplete="off"
                   placeholder="Имя или телефон — от 3 символов">
            <input type="hidden" name="club_client_id" id="clientId" value="{{ old('club_client_id') }}">
            <div class="pay-found" id="clientFound"></div>
            <div class="pay-chosen" id="clientChosen" style="display:none">
                <i class="bi bi-person-check"></i>
                <span id="clientChosenName"></span>
                <button type="button" class="pay-chosen-x" onclick="clearClient()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
        <div class="pay-hint">Выберите клиента, чтобы отправить ссылку в WhatsApp одним нажатием.</div>

        <label class="pay-label">Ссылка действует</label>
        <select name="expires_in_hours" class="pay-input full">
            <option value="1">1 час</option>
            <option value="3">3 часа</option>
            <option value="24" selected>1 день</option>
            <option value="72">3 дня</option>
            <option value="168">7 дней</option>
        </select>

        <div class="pay-modal-actions">
            <button type="button" class="pay-btn-ghost" onclick="closeBillModal()">Отмена</button>
            <button type="submit" class="pay-btn" id="billSubmit">
                <span class="pay-spinner"></span>
                <span class="pay-btn-text"><i class="bi bi-link-45deg"></i> Создать ссылку</span>
            </button>
        </div>
    </form>
</div>

<script>
function openBillModal() {
    document.getElementById('billOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeBillModal() {
    document.getElementById('billOverlay').classList.remove('open');
    document.body.style.overflow = '';
}
function copyLink(btn, url) {
    navigator.clipboard.writeText(url).then(function () {
        var old = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Скопировано';
        setTimeout(function () { btn.innerHTML = old; }, 1500);
    });
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeBillModal();
});

// ---- поиск клиента ----
var clientTimer = null;

function clearClient() {
    document.getElementById('clientId').value = '';
    document.getElementById('clientChosen').style.display = 'none';
    document.getElementById('clientQuery').style.display = '';
    document.getElementById('clientQuery').value = '';
    document.getElementById('clientFound').innerHTML = '';
}

function chooseClient(id, name, phone) {
    document.getElementById('clientId').value = id;
    document.getElementById('clientChosenName').textContent = phone ? name + ' · ' + phone : name;
    document.getElementById('clientChosen').style.display = 'flex';
    document.getElementById('clientQuery').style.display = 'none';
    document.getElementById('clientFound').innerHTML = '';
}

document.getElementById('clientQuery').addEventListener('input', function () {
    var q = this.value.trim();
    var box = document.getElementById('clientFound');

    clearTimeout(clientTimer);
    if (q.length < 3) {
        box.innerHTML = '';
        return;
    }

    // Пауза, чтобы не бить в базу на каждой букве.
    clientTimer = setTimeout(function () {
        fetch('{{ route('club.payments.clients') }}?q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (list) {
                if (!list.length) {
                    box.innerHTML = '<div class="pay-found-empty">Никого не нашли</div>';
                    return;
                }
                box.innerHTML = list.map(function (c) {
                    var phone = c.phone || '';
                    return '<button type="button" class="pay-found-row" '
                        + 'onclick="chooseClient(' + c.id + ', '
                        + JSON.stringify(c.name) + ', ' + JSON.stringify(phone) + ')">'
                        + '<span>' + c.name + '</span>'
                        + (phone ? '<small>' + phone + '</small>' : '')
                        + '</button>';
                }).join('');
            })
            .catch(function () { box.innerHTML = ''; });
    }, 250);
});
// Создание ссылки идёт через банк — второй клик выставил бы второй счёт.
document.getElementById('billForm').addEventListener('submit', function (e) {
    var btn = document.getElementById('billSubmit');
    if (btn.disabled) { e.preventDefault(); return; }
    btn.disabled = true;
    btn.classList.add('sending');
    btn.querySelector('.pay-btn-text').textContent = 'Создаём…';
});
</script>
@endif

<style>
.pay-wrap { max-width: 1000px; }

.pay-alert {
    display: flex; align-items: center; gap: 10px;
    border-radius: 12px; padding: 12px 16px; margin-bottom: 16px;
    max-width: 1000px;
}
.pay-alert-ok { background: var(--accent-glow); border: 1px solid var(--accent); color: var(--accent); }
.pay-alert-err { background: rgba(239,68,68,.12); border: 1px solid #ef4444; color: #ef4444; }

.pay-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 18px; }
.pay-stat {
    display: flex; align-items: center; gap: 14px;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 14px; padding: 16px 18px;
}
.pay-stat-icon {
    width: 40px; height: 40px; flex-shrink: 0; border-radius: 11px;
    display: grid; place-items: center; font-size: 1.05rem;
}
.pay-stat-icon.ok { background: var(--accent-glow); color: var(--accent); }
.pay-stat-icon.wait { background: rgba(245,158,11,.13); color: #f59e0b; }
.pay-stat b { display: block; font-size: 1.3rem; color: var(--text-primary); line-height: 1.15; }
.pay-stat span { font-size: .8rem; color: var(--text-secondary); }

.pay-filter { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
.pay-input {
    background: var(--bg-primary); border: 1px solid var(--border);
    border-radius: 10px; padding: 10px 14px;
    color: var(--text-primary); font-size: .92rem;
}
.pay-input.full { width: 100%; margin-bottom: 4px; }
.pay-input:focus { outline: none; border-color: var(--accent); }

.pay-list { display: flex; flex-direction: column; gap: 10px; }
.pay-card {
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 14px; padding: 15px 18px;
}
.pay-card.fresh { border-color: var(--accent); background: var(--accent-glow); }
.pay-card-main { flex: 1; min-width: 220px; }
.pay-card-top { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.pay-amount { font-size: 1.15rem; font-weight: 700; color: var(--text-primary); }
.pay-badge {
    border-radius: 6px; padding: 2px 9px;
    font-size: .74rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
}
.pay-badge.paid { background: var(--accent); color: #000; }
.pay-badge.wait { background: rgba(245,158,11,.15); color: #f59e0b; }
.pay-badge.stale { background: rgba(239,68,68,.13); color: #ef4444; }
.pay-badge.off { background: var(--bg-primary); color: var(--text-secondary); }
.pay-desc { color: var(--text-primary); font-size: .98rem; margin-bottom: 5px; }
.pay-meta { display: flex; gap: 14px; flex-wrap: wrap; color: var(--text-secondary); font-size: .82rem; }
.pay-meta .ok { color: var(--accent); }

.pay-card-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.pay-act {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--bg-primary); border: 1px solid var(--border);
    border-radius: 9px; padding: 8px 12px;
    color: var(--text-secondary); font-size: .85rem;
    cursor: pointer; text-decoration: none;
}
.pay-act:hover { color: var(--text-primary); border-color: var(--border-light); }
.pay-act.danger:hover { color: #ef4444; border-color: #ef4444; }
.pay-done { color: var(--accent); font-size: 1.5rem; }

.pay-empty {
    display: flex; align-items: center; gap: 14px;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 14px; padding: 24px 22px; color: var(--text-secondary);
    max-width: 1000px;
}
.pay-empty i { font-size: 1.6rem; color: var(--border-light); }
.pay-empty b { display: block; color: var(--text-primary); font-size: .98rem; margin-bottom: 3px; }
.pay-empty span { font-size: .88rem; }

/* ---- окно ---- */
.pay-overlay {
    display: none; position: fixed; inset: 0; z-index: 1000;
    background: rgba(0,0,0,.6); padding: 20px; overflow-y: auto;
}
.pay-overlay.open { display: flex; align-items: center; justify-content: center; }
.pay-modal {
    width: 100%; max-width: 460px;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 18px; padding: 22px 24px;
}
.pay-modal-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 16px; }
.pay-eyebrow {
    display: flex; align-items: center; gap: 7px; color: var(--accent);
    font-size: .74rem; font-weight: 700; letter-spacing: .1em;
    text-transform: uppercase; margin-bottom: 5px;
}
.pay-modal-title { color: var(--text-primary); font-size: 1.08rem; font-weight: 600; }
.pay-close {
    margin-left: auto; background: transparent; border: none;
    color: var(--text-secondary); cursor: pointer; padding: 4px;
}
.pay-label {
    display: block; color: var(--text-secondary);
    font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;
    margin: 12px 0 6px;
}
.pay-opt { text-transform: none; letter-spacing: 0; opacity: .7; }
.pay-hint { color: var(--text-secondary); font-size: .78rem; margin-top: 4px; }

/* ---- поиск клиента ---- */
.pay-search { position: relative; }
.pay-found {
    display: flex; flex-direction: column;
    max-height: 210px; overflow-y: auto;
}
.pay-found:not(:empty) {
    border: 1px solid var(--border);
    border-radius: 10px;
    margin-top: 6px;
    background: var(--bg-primary);
}
.pay-found-row {
    display: flex; align-items: baseline; gap: 10px;
    background: transparent; border: none;
    border-bottom: 1px solid var(--border);
    padding: 10px 14px;
    color: var(--text-primary); font-size: .9rem;
    cursor: pointer; text-align: left; width: 100%;
}
.pay-found-row:last-child { border-bottom: none; }
.pay-found-row:hover { background: var(--bg-card-hover); }
.pay-found-row small { color: var(--text-secondary); font-size: .8rem; margin-left: auto; }
.pay-found-empty { padding: 10px 14px; color: var(--text-secondary); font-size: .86rem; }
.pay-chosen {
    display: flex; align-items: center; gap: 9px;
    background: var(--accent-glow); border: 1px solid var(--accent);
    border-radius: 10px; padding: 10px 14px;
    color: var(--accent); font-size: .9rem;
}
.pay-chosen-x {
    margin-left: auto; background: transparent; border: none;
    color: var(--accent); cursor: pointer; padding: 0 2px;
}
.pay-modal-actions { display: flex; gap: 10px; margin-top: 20px; }
.pay-btn {
    flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    background: var(--accent); color: #000; border: none; border-radius: 10px;
    padding: 12px 20px; font-size: .95rem; font-weight: 600; cursor: pointer;
}
.pay-btn:disabled { opacity: .55; cursor: not-allowed; }
.pay-btn-ghost {
    background: transparent; color: var(--text-secondary);
    border: 1px solid var(--border); border-radius: 10px;
    padding: 12px 18px; font-size: .92rem; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center;
}
.pay-btn-ghost:hover { color: var(--text-primary); border-color: var(--border-light); }
.pay-spinner { display: none; }
.pay-btn.sending .pay-spinner {
    display: inline-block; width: 15px; height: 15px;
    border: 2px solid rgba(0,0,0,.25); border-top-color: #000;
    border-radius: 50%; animation: pay-spin .7s linear infinite;
}
@keyframes pay-spin { to { transform: rotate(360deg); } }

@media (max-width: 720px) {
    .pay-stats { grid-template-columns: 1fr; }
}
</style>
@endsection

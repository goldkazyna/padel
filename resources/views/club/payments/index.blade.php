@extends('layouts.app')

@section('title', 'Платежи')

@section('content')
@php
    $plexyReady = $club->hasPlexyConfigured();
    $tz = \App\Models\Shift::TZ;
    $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
               'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    // Лента по дням: заголовок дня + сколько за него собрали.
    $byDay = $links->getCollection()->groupBy(
        fn ($l) => $l->created_at->timezone($tz)->format('Y-m-d')
    );

    // Данные для карточки справа — рисуется на клиенте без перезагрузки.
    $cards = $links->getCollection()->mapWithKeys(fn ($l) => [$l->id => [
        'amount' => number_format($l->amount, 0, '.', ' ') . ' ₸',
        'description' => $l->description,
        'status' => $l->status,
        'statusLabel' => $l->statusLabel(),
        'client' => $l->client_name,
        'phone' => $l->client_phone,
        'created' => $l->created_at->timezone($tz)->format('d.m.Y, H:i'),
        'paid' => $l->paid_at?->timezone($tz)->format('d.m.Y, H:i'),
        'expires' => $l->expires_at?->timezone($tz)->format('d.m.Y, H:i'),
        'author' => $l->creator?->name,
        'url' => $l->plexy_url,
        'canAct' => !$l->isPaid() && $l->status !== 'cancelled' && $l->plexy_url,
        'wa' => $l->client_phone ? preg_replace('/\D/', '', $l->client_phone) : null,
        'waText' => rawurlencode($l->description . ' — '
            . number_format($l->amount, 0, '.', ' ') . ' ₸: ' . $l->plexy_url),
        'syncUrl' => route('club.payments.sync', $l),
        'cancelUrl' => route('club.payments.cancel', $l),
    ]]);
@endphp

<div class="pay-wrap">
    <div class="pay-head">
        <div>
            <h2>Платежи</h2>
            <p>{{ $club->name }} · счета клиентам по ссылке</p>
        </div>
    </div>

    @if(!$plexyReady)
        <div class="pay-empty">
            <i class="bi bi-credit-card"></i>
            <div>
                <b>Онлайн-оплата не настроена</b>
                <span>Чтобы выставлять счета, супер-админ должен указать ключи Plexy в настройках клуба и включить онлайн-оплату.</span>
            </div>
        </div>
    @else

    {{-- Панель действий: всё слева, в потоке контента --}}
    <div class="pay-bar">
        <button type="button" class="pay-btn" onclick="openBillModal()">
            <i class="bi bi-plus-lg"></i> Выставить счёт
        </button>

        <form method="POST" action="{{ route('club.payments.syncAll') }}" class="d-inline" id="syncAllForm">
            @csrf
            <button class="pay-btn-ghost" id="syncAllBtn" title="Спросить банк по всем ожидающим счетам">
                <i class="bi bi-arrow-clockwise"></i> Обновить
            </button>
        </form>

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

        <div class="pay-totals">
            <div>
                <b class="ok">{{ number_format($paidSum, 0, '.', ' ') }} ₸</b>
                <span>получено · {{ $paidCount }}</span>
            </div>
            <div>
                <b class="wait">{{ number_format($pendingSum, 0, '.', ' ') }} ₸</b>
                <span>ждут · {{ $pendingCount }}</span>
            </div>
        </div>
    </div>

    @if($links->isEmpty())
        <div class="pay-empty">
            <i class="bi bi-receipt"></i>
            <div>
                <b>Счетов пока нет</b>
                <span>Нажмите «Выставить счёт», укажите сумму и назначение — получите ссылку для клиента.</span>
            </div>
        </div>
    @else
        <div class="pay-grid">
            {{-- Лента счетов по дням --}}
            <div class="pay-feed">
                @foreach($byDay as $day => $dayLinks)
                    @php
                        $date = \Carbon\Carbon::parse($day);
                        $dayPaid = $dayLinks->where('status', 'paid')->sum('amount');
                        $dayAll = $dayLinks->sum('amount');
                    @endphp
                    <div class="pay-day">
                        <span class="pay-day-date">{{ $date->day }} {{ $months[$date->month] }}</span>
                        <span class="pay-day-sum">
                            оплачено {{ number_format($dayPaid, 0, '.', ' ') }} из
                            {{ number_format($dayAll, 0, '.', ' ') }} ₸
                        </span>
                        <span class="pay-day-line"></span>
                    </div>

                    @foreach($dayLinks as $link)
                        @php
                            $dot = match(true) {
                                $link->isPaid() => 'ok',
                                $link->status === 'cancelled' => 'off',
                                $link->isStale() || $link->status === 'expired' => 'bad',
                                default => 'wait',
                            };
                        @endphp
                        <button type="button" class="pay-item" data-id="{{ $link->id }}"
                                onclick="selectLink({{ $link->id }})">
                            <span class="pay-dot {{ $dot }}"></span>
                            <span class="pay-item-time">{{ $link->created_at->timezone($tz)->format('H:i') }}</span>
                            <span class="pay-item-main">
                                <span class="pay-item-desc">{{ $link->description }}</span>
                                <span class="pay-item-who">
                                    {{ $link->client_name ?: 'без клиента' }}
                                    @if($link->isPaid()) · оплачен @endif
                                </span>
                            </span>
                            <span class="pay-item-amount">{{ number_format($link->amount, 0, '.', ' ') }} ₸</span>
                        </button>
                    @endforeach
                @endforeach

                <div class="mt-3">{{ $links->links() }}</div>
            </div>

            {{-- Карточка выбранного счёта --}}
            <div class="pay-side" id="paySide">
                <div class="pay-side-empty" id="paySideEmpty">
                    <i class="bi bi-hand-index-thumb"></i>
                    <span>Выберите счёт слева, чтобы увидеть детали и отправить ссылку</span>
                </div>

                <div class="pay-side-body" id="paySideBody" style="display:none">
                    <div class="pay-side-amount" id="sideAmount"></div>
                    <div class="pay-side-desc" id="sideDesc"></div>
                    <span class="pay-badge" id="sideBadge"></span>

                    <div class="pay-kv-list" id="sideDetails"></div>

                    <div class="pay-side-acts" id="sideActs"></div>
                </div>
            </div>
        </div>
    @endif

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
                   value="{{ old('amount') }}" required>

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
                <button type="submit" class="pay-btn wide" id="billSubmit">
                    <span class="pay-spinner"></span>
                    <span class="pay-btn-text"><i class="bi bi-link-45deg"></i> Создать ссылку</span>
                </button>
            </div>
        </form>
    </div>
    @endif
</div>

@if($plexyReady)
<script>
var payCards = @json($cards);
var csrf = '{{ csrf_token() }}';

// ---- карточка справа ----
function selectLink(id) {
    var card = payCards[id];
    if (!card) return;

    document.querySelectorAll('.pay-item').forEach(function (el) {
        el.classList.toggle('sel', String(el.dataset.id) === String(id));
    });

    document.getElementById('paySideEmpty').style.display = 'none';
    document.getElementById('paySideBody').style.display = '';

    document.getElementById('sideAmount').textContent = card.amount;
    document.getElementById('sideDesc').textContent = card.description;

    var badge = document.getElementById('sideBadge');
    badge.textContent = card.statusLabel;
    badge.className = 'pay-badge ' + (
        card.status === 'paid' ? 'paid' :
        card.status === 'cancelled' ? 'off' :
        card.status === 'expired' ? 'stale' : 'wait'
    );

    var rows = [
        ['Клиент', card.client || '—'],
        ['Телефон', card.phone || '—'],
        ['Создан', card.created],
    ];
    if (card.paid) rows.push(['Оплачен', card.paid]);
    else if (card.expires) rows.push(['Действует до', card.expires]);
    if (card.author) rows.push(['Выставил', card.author]);

    var box = document.getElementById('sideDetails');
    box.innerHTML = '';
    rows.forEach(function (r) {
        var line = document.createElement('div');
        line.className = 'pay-kv';
        var k = document.createElement('span');
        k.textContent = r[0];
        var v = document.createElement('b');
        v.textContent = r[1];
        line.appendChild(k);
        line.appendChild(v);
        box.appendChild(line);
    });

    var acts = document.getElementById('sideActs');
    acts.innerHTML = '';
    if (!card.canAct) return;

    acts.appendChild(makeAct('bi-clipboard', 'Копировать ссылку', function (btn) {
        navigator.clipboard.writeText(card.url).then(function () {
            var old = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Скопировано';
            setTimeout(function () { btn.innerHTML = old; }, 1500);
        });
    }));

    if (card.wa) {
        var wa = document.createElement('a');
        wa.className = 'pay-act';
        wa.target = '_blank';
        wa.rel = 'noopener';
        wa.href = 'https://wa.me/' + card.wa + '?text=' + card.waText;
        wa.innerHTML = '<i class="bi bi-whatsapp"></i> Отправить в WhatsApp';
        acts.appendChild(wa);
    }

    acts.appendChild(makePost(card.syncUrl, 'bi-arrow-clockwise', 'Проверить оплату', null));
    acts.appendChild(makePost(card.cancelUrl, 'bi-x-lg', 'Отменить счёт',
        'Отменить счёт? Ссылка перестанет работать.', true));
}

function makeAct(icon, text, onClick) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pay-act';
    btn.innerHTML = '<i class="bi ' + icon + '"></i> ' + text;
    btn.addEventListener('click', function () { onClick(btn); });
    return btn;
}

function makePost(url, icon, text, confirmText, danger) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    if (confirmText) {
        form.addEventListener('submit', function (e) {
            if (!confirm(confirmText)) e.preventDefault();
        });
    }

    var token = document.createElement('input');
    token.type = 'hidden';
    token.name = '_token';
    token.value = csrf;
    form.appendChild(token);

    if (danger) {
        var method = document.createElement('input');
        method.type = 'hidden';
        method.name = '_method';
        method.value = 'DELETE';
        form.appendChild(method);
    }

    var btn = document.createElement('button');
    btn.className = 'pay-act' + (danger ? ' danger' : '');
    btn.innerHTML = '<i class="bi ' + icon + '"></i> ' + text;
    form.appendChild(btn);

    return form;
}

// ---- окно счёта ----
function openBillModal() {
    document.getElementById('billOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeBillModal() {
    document.getElementById('billOverlay').classList.remove('open');
    document.body.style.overflow = '';
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
                box.innerHTML = '';

                if (!list.length) {
                    var empty = document.createElement('div');
                    empty.className = 'pay-found-empty';
                    empty.textContent = 'Никого не нашли';
                    box.appendChild(empty);
                    return;
                }

                // Через DOM: имя клиента может содержать кавычки и скобки.
                list.forEach(function (c) {
                    var phone = c.phone || '';

                    var row = document.createElement('button');
                    row.type = 'button';
                    row.className = 'pay-found-row';

                    var name = document.createElement('span');
                    name.textContent = c.name;
                    row.appendChild(name);

                    if (phone) {
                        var tel = document.createElement('small');
                        tel.textContent = phone;
                        row.appendChild(tel);
                    }

                    row.addEventListener('click', function () {
                        chooseClient(c.id, c.name, phone);
                    });

                    box.appendChild(row);
                });
            })
            .catch(function () { box.innerHTML = ''; });
    }, 250);
});

// Создание идёт через банк — второй клик выставил бы второй счёт.
document.getElementById('billForm').addEventListener('submit', function (e) {
    var btn = document.getElementById('billSubmit');
    if (btn.disabled) { e.preventDefault(); return; }
    btn.disabled = true;
    btn.classList.add('sending');
    btn.querySelector('.pay-btn-text').textContent = 'Создаём…';
});

// Обновление опрашивает банк по каждому счёту — это не мгновенно.
document.getElementById('syncAllForm').addEventListener('submit', function () {
    var btn = document.getElementById('syncAllBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Обновляем…';
});

// Первый счёт открыт сразу — панель справа не пустует.
document.addEventListener('DOMContentLoaded', function () {
    var first = document.querySelector('.pay-item');
    if (first) selectLink(first.dataset.id);
});
</script>
@endif

<style>
.pay-wrap { max-width: 1120px; }
.pay-head { margin-bottom: 18px; }
.pay-head h2 { margin: 0 0 4px; }
.pay-head p { margin: 0; color: var(--text-secondary); font-size: .9rem; }

/* ---- панель действий ---- */
.pay-bar {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    margin-bottom: 20px;
}
.pay-filter { display: flex; gap: 8px; flex-wrap: wrap; }
.pay-input {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 10px; padding: 10px 13px;
    color: var(--text-primary); font-size: .89rem;
}
.pay-input.full { width: 100%; margin-bottom: 4px; }
.pay-input:focus { outline: none; border-color: var(--accent); }
.pay-totals { display: flex; gap: 20px; margin-left: auto; }
.pay-totals b { display: block; font-size: 1.05rem; line-height: 1.2; }
.pay-totals b.ok { color: var(--accent); }
.pay-totals b.wait { color: #f59e0b; }
.pay-totals span { font-size: .74rem; color: var(--text-secondary); }

.pay-btn {
    background: var(--accent); color: #000; border: none; border-radius: 10px;
    padding: 10px 18px; font-size: .92rem; font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
}
.pay-btn.wide { flex: 1; }
.pay-btn:disabled { opacity: .55; cursor: not-allowed; }
.pay-btn-ghost {
    background: transparent; color: var(--text-secondary);
    border: 1px solid var(--border); border-radius: 10px;
    padding: 10px 16px; font-size: .89rem; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 7px;
}
.pay-btn-ghost:hover { color: var(--text-primary); border-color: var(--border-light); }
.pay-btn-ghost:disabled { opacity: .55; cursor: not-allowed; }

/* ---- две колонки ---- */
.pay-grid { display: grid; grid-template-columns: minmax(0, 1fr) 330px; gap: 18px; align-items: start; }

/* ---- лента по дням ---- */
.pay-day { display: flex; align-items: center; gap: 10px; margin: 20px 0 10px; }
.pay-day:first-child { margin-top: 0; }
.pay-day-date { font-weight: 600; font-size: .93rem; color: var(--text-primary); }
.pay-day-sum { color: var(--text-secondary); font-size: .82rem; }
.pay-day-line { flex: 1; height: 1px; background: var(--border); }

.pay-item {
    display: flex; align-items: center; gap: 13px; width: 100%;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 12px; padding: 13px 16px; margin-bottom: 8px;
    cursor: pointer; text-align: left;
    transition: border-color .15s, background .15s;
}
.pay-item:hover { border-color: var(--border-light); }
.pay-item.sel { border-color: var(--accent); background: var(--accent-glow); }
.pay-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.pay-dot.ok { background: var(--accent); }
.pay-dot.wait { background: #f59e0b; }
.pay-dot.bad { background: #ef4444; }
.pay-dot.off { background: var(--border-light); }
.pay-item-time { color: var(--text-secondary); font-size: .82rem; width: 40px; flex-shrink: 0; }
.pay-item-main { flex: 1; min-width: 0; }
.pay-item-desc {
    display: block; color: var(--text-primary); font-size: .94rem;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.pay-item-who { display: block; color: var(--text-secondary); font-size: .8rem; margin-top: 2px; }
.pay-item-amount { font-weight: 700; font-size: 1rem; white-space: nowrap; color: var(--text-primary); }

/* ---- карточка справа ---- */
.pay-side {
    position: sticky; top: 20px;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 16px; padding: 20px;
}
.pay-side-empty {
    display: flex; flex-direction: column; align-items: center; gap: 10px;
    text-align: center; color: var(--text-secondary); font-size: .87rem;
    padding: 20px 10px;
}
.pay-side-empty i { font-size: 1.6rem; color: var(--border-light); }
.pay-side-amount { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
.pay-side-desc { color: var(--text-secondary); font-size: .92rem; margin: 4px 0 12px; }
.pay-badge {
    display: inline-block; border-radius: 6px; padding: 2px 9px;
    font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
}
.pay-badge.paid { background: var(--accent); color: #000; }
.pay-badge.wait { background: rgba(245,158,11,.15); color: #f59e0b; }
.pay-badge.stale { background: rgba(239,68,68,.13); color: #ef4444; }
.pay-badge.off { background: var(--bg-primary); color: var(--text-secondary); }

.pay-kv-list { margin-top: 16px; }
.pay-kv {
    display: flex; justify-content: space-between; gap: 12px;
    padding: 8px 0; font-size: .85rem;
    border-bottom: 1px solid var(--border);
}
.pay-kv:last-child { border-bottom: none; }
.pay-kv span { color: var(--text-secondary); flex-shrink: 0; }
.pay-kv b { color: var(--text-primary); font-weight: 500; text-align: right; word-break: break-word; }

.pay-side-acts { display: flex; flex-direction: column; gap: 8px; margin-top: 16px; }
.pay-side-acts form { display: block; }
.pay-act {
    display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%;
    background: var(--bg-primary); border: 1px solid var(--border);
    border-radius: 10px; padding: 10px 12px;
    color: var(--text-secondary); font-size: .87rem;
    cursor: pointer; text-decoration: none;
}
.pay-act:hover { color: var(--text-primary); border-color: var(--border-light); }
.pay-act.danger:hover { color: #ef4444; border-color: #ef4444; }

/* ---- пусто ---- */
.pay-empty {
    display: flex; align-items: center; gap: 14px;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 14px; padding: 24px 22px; color: var(--text-secondary);
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
.pay-modal-actions { display: flex; gap: 10px; margin-top: 20px; }
.pay-spinner { display: none; }
.pay-btn.sending .pay-spinner {
    display: inline-block; width: 15px; height: 15px;
    border: 2px solid rgba(0,0,0,.25); border-top-color: #000;
    border-radius: 50%; animation: pay-spin .7s linear infinite;
}
@keyframes pay-spin { to { transform: rotate(360deg); } }

/* ---- поиск клиента ---- */
.pay-search { position: relative; }
.pay-found { display: flex; flex-direction: column; max-height: 210px; overflow-y: auto; }
.pay-found:not(:empty) {
    border: 1px solid var(--border); border-radius: 10px;
    margin-top: 6px; background: var(--bg-primary);
}
.pay-found-row {
    display: flex; align-items: baseline; gap: 10px; width: 100%;
    background: transparent; border: none;
    border-bottom: 1px solid var(--border);
    padding: 10px 14px; color: var(--text-primary); font-size: .9rem;
    cursor: pointer; text-align: left;
}
.pay-found-row:last-child { border-bottom: none; }
.pay-found-row:hover { background: var(--bg-card-hover); }
.pay-found-row small { color: var(--text-secondary); font-size: .8rem; margin-left: auto; }
.pay-found-empty { padding: 10px 14px; color: var(--text-secondary); font-size: .86rem; }
.pay-chosen {
    display: flex; align-items: center; gap: 9px;
    background: var(--accent-glow); border: 1px solid var(--accent);
    border-radius: 10px; padding: 10px 14px; color: var(--accent); font-size: .9rem;
}
.pay-chosen-x {
    margin-left: auto; background: transparent; border: none;
    color: var(--accent); cursor: pointer; padding: 0 2px;
}

@media (max-width: 900px) {
    .pay-grid { grid-template-columns: 1fr; }
    .pay-side { position: static; }
    .pay-totals { margin-left: 0; }
}
</style>
@endsection

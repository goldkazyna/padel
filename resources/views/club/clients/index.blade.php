@extends('layouts.app')
@section('title', 'Клиенты')
@section('content')

<div class="clients-container">
    <!-- Header -->
    <header class="clients-header">
        <div class="clients-title-block">
            <i class="bi bi-person-lines-fill"></i>
            <h1 class="clients-title">Клиенты</h1>
            <span class="clients-count">{{ $totalCount }}</span>
        </div>
        <div class="clients-header-actions">
            <a href="{{ route('club.clients.duplicates') }}" class="btn-duplicates-client">
                <i class="bi bi-people"></i>
                Дубликаты
            </a>
            <a href="{{ route('club.clients.import.form') }}" class="btn-export-client">
                <i class="bi bi-cloud-upload"></i>
                Импорт
            </a>
            <a href="{{ route('club.clients.export') }}" class="btn-export-client">
                <i class="bi bi-file-earmark-excel"></i>
                Excel
            </a>
            <button class="btn-add-client" onclick="openAddModal()">
                <i class="bi bi-plus-lg"></i>
                Добавить
            </button>
        </div>
    </header>

    <!-- Обзоры: клубные карты и групповые -->
    <div class="clients-overview-btns">
        <a href="{{ route('club.clients.cards') }}">
            <i class="bi bi-credit-card-2-front"></i>
            Клубные карты
        </a>
        <a href="{{ route('club.clients.groups') }}">
            <i class="bi bi-people-fill"></i>
            Групповые
        </a>
    </div>
    <style>
        .clients-overview-btns{display:flex;gap:10px;margin:0 0 14px;flex-wrap:wrap;}
        .clients-overview-btns a{display:inline-flex;align-items:center;gap:8px;padding:12px 18px;
            border-radius:10px;background:var(--cl-card,#16161a);border:1px solid var(--cl-border,#27272a);
            color:var(--cl-text,#f4f4f5);text-decoration:none;font-weight:700;font-size:14px;}
        .clients-overview-btns a:hover{background:#1e1e24;border-color:#3f3f46;}
        .clients-overview-btns i{color:var(--cl-accent,#22c55e);font-size:17px;}

        /* Бейдж сертификатов в строке клиента */
        .client-cert-badge{display:inline-flex;align-items:center;gap:5px;margin-top:4px;
            font-size:12px;font-weight:700;color:#a78bfa;}
        .client-cert-badge i{font-size:13px;}
        .client-cert-badge span{color:#71717a;font-weight:600;}

        /* Иконка «есть аккаунт в приложении Padel KZ» */
        .client-app-badge{width:26px;height:26px;object-fit:contain;margin-left:auto;flex:none;
            border-radius:6px;opacity:.95;}

        /* Кнопка «Сертификаты» в деталях клиента */
        .client-cert-btn{display:flex;align-items:center;gap:10px;padding:13px 16px;
            border-radius:12px;background:var(--cl-card,#16161a);border:1px solid var(--cl-border,#27272a);
            color:var(--cl-text,#f4f4f5);text-decoration:none;font-weight:700;transition:border-color .15s,background .15s;}
        .client-cert-btn:hover{border-color:#22c55e;background:rgba(34,197,94,.08);}
        .client-cert-btn > i:first-child{color:#22c55e;font-size:18px;}
        .client-cert-btn b{background:#22c55e;color:#062e15;border-radius:20px;padding:1px 9px;font-size:13px;}
        .client-cert-btn em{color:#a1a1aa;font-style:normal;font-weight:500;font-size:13px;}
        .client-cert-btn-arrow{margin-left:auto;color:#71717a;}
    </style>

    <!-- Search -->
    <form method="GET" class="clients-search">
        <i class="bi bi-search"></i>
        <input type="text" name="search" class="search-input" placeholder="Поиск по имени или телефону..." value="{{ request('search') }}">
        @if(request('search'))
            <a href="{{ route('club.clients.index') }}" class="search-clear">
                <i class="bi bi-x-lg"></i>
            </a>
        @endif
    </form>

    @if(session('success'))
        <div class="alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert-error">
            <i class="bi bi-exclamation-triangle-fill"></i>
            {{ session('error') }}
        </div>
    @endif
    @if(session('link_warning'))
        <div class="alert-error">
            <i class="bi bi-exclamation-triangle-fill"></i>
            {{ session('link_warning') }}
        </div>
    @endif

    <!-- Layout: list + detail -->
    <div class="clients-layout">
        <!-- Left: list -->
        <div class="clients-list-col">
            <div class="clients-list">
                @forelse($clients as $client)
                    @php
                        $nameWords = array_values(array_filter(preg_split('/\s+/', trim($client->name)), fn($w) => mb_strlen($w) > 0));
                        $noLastName = count($nameWords) < 2;
                    @endphp
                    <a href="{{ route('club.clients.index', ['selected' => $client->id, 'search' => request('search'), 'page' => $clients->currentPage()]) }}"
                       class="clients-list-item {{ $selectedClient && $selectedClient->id === $client->id ? 'selected' : '' }}">
                        <div class="client-avatar">{{ mb_strtoupper(mb_substr($client->name, 0, 1)) }}</div>
                        <div class="client-list-info">
                            <div class="client-list-name">
                                <span>{{ $client->name }}</span>
                                @if($noLastName)
                                    <span class="client-no-lastname" title="В карточке нет фамилии — добавьте её">
                                        <i class="bi bi-exclamation-circle-fill"></i>
                                        Поставьте фамилию
                                    </span>
                                @endif
                            </div>
                            <div class="client-list-phone">@phoneFmt($client->phone)</div>
                            @if(($client->certificates_count ?? 0) > 0)
                                <div class="client-cert-badge"><i class="bi bi-award"></i> {{ $client->certificates_count }}@if(($client->certificates_used_count ?? 0) > 0) <span>({{ $client->certificates_used_count }})</span>@endif</div>
                            @endif
                        </div>
                        @php($clientDigitsRow = preg_replace('/\D/', '', (string) $client->phone))
                        @if($client->user_id || isset($appPhones[$clientDigitsRow]))
                            <img src="{{ asset('images/padel-logo.png') }}" alt="Padel KZ" title="Зарегистрирован в приложении Padel KZ" class="client-app-badge">
                        @endif
                    </a>
                @empty
                    <div class="clients-empty">
                        <i class="bi bi-person-x"></i>
                        <p>Клиентов пока нет</p>
                    </div>
                @endforelse
            </div>

            @if($clients->hasPages())
            <div class="clients-pagination">
                <div class="pagination-info">
                    {{ $clients->firstItem() }}–{{ $clients->lastItem() }} из {{ $clients->total() }}
                </div>
                <div class="pagination-controls">
                    @if($clients->onFirstPage())
                        <button class="page-btn" disabled><i class="bi bi-chevron-left"></i></button>
                    @else
                        <a href="{{ $clients->previousPageUrl() }}" class="page-btn"><i class="bi bi-chevron-left"></i></a>
                    @endif

                    @foreach($clients->getUrlRange(1, $clients->lastPage()) as $page => $url)
                        @if($page == $clients->currentPage())
                            <button class="page-btn active">{{ $page }}</button>
                        @elseif($page == 1 || $page == $clients->lastPage() || abs($page - $clients->currentPage()) <= 2)
                            <a href="{{ $url }}" class="page-btn">{{ $page }}</a>
                        @elseif($page == 2 || $page == $clients->lastPage() - 1)
                            <span class="page-btn dots">...</span>
                        @endif
                    @endforeach

                    @if($clients->hasMorePages())
                        <a href="{{ $clients->nextPageUrl() }}" class="page-btn"><i class="bi bi-chevron-right"></i></a>
                    @else
                        <button class="page-btn" disabled><i class="bi bi-chevron-right"></i></button>
                    @endif
                </div>
            </div>
            @endif
        </div>

        <!-- Right: detail panel -->
        <div class="clients-detail-col">
            @if($selectedClient)
            @php
                $selectedNameWords = array_values(array_filter(preg_split('/\s+/', trim($selectedClient->name)), fn($w) => mb_strlen($w) > 0));
                $selectedNoLastName = count($selectedNameWords) < 2;
            @endphp
            <div class="client-detail">
                <div class="client-detail-header">
                    <div class="client-detail-avatar">{{ mb_strtoupper(mb_substr($selectedClient->name, 0, 1)) }}</div>
                    <div class="client-detail-name">{{ $selectedClient->name }}</div>
                    <div class="client-detail-phone">@phoneFmt($selectedClient->phone)</div>
                    @if($selectedNoLastName)
                        <div class="client-detail-no-lastname">
                            <i class="bi bi-exclamation-circle-fill"></i>
                            В карточке нет фамилии — добавьте её через «Редактировать»
                        </div>
                    @endif
                </div>

                <div class="client-detail-section">
                    <div class="client-detail-label">Информация</div>
                    <div class="client-detail-fields">
                        <div class="client-detail-field">
                            <span class="field-label">Пол</span>
                            <span class="field-value">
                                @if($selectedClient->gender === 'male') Мужской
                                @elseif($selectedClient->gender === 'female') Женский
                                @else — @endif
                            </span>
                        </div>
                        <div class="client-detail-field">
                            <span class="field-label">Дата рождения</span>
                            <span class="field-value">{{ $selectedClient->birth_date ? $selectedClient->birth_date->format('d.m.Y') : '—' }}</span>
                        </div>
                        @if($selectedClient->email)
                        <div class="client-detail-field">
                            <span class="field-label">E-mail</span>
                            <span class="field-value">
                                <a href="mailto:{{ $selectedClient->email }}" class="client-email-link">{{ $selectedClient->email }}</a>
                            </span>
                        </div>
                        @endif
                        @if($selectedClient->card_number)
                        <div class="client-detail-field">
                            <span class="field-label">Номер карты</span>
                            <span class="field-value">
                                <span class="client-card-badge">{{ $selectedClient->card_number }}</span>
                            </span>
                        </div>
                        @endif
                        <div class="client-detail-field">
                            <span class="field-label">Добавлен</span>
                            <span class="field-value">{{ $selectedClient->created_at->format('d.m.Y') }}</span>
                        </div>
                    </div>
                </div>

                <div class="client-detail-section">
                    <a href="{{ route('club.certificates.client', $selectedClient) }}" class="client-cert-btn">
                        <i class="bi bi-award"></i>
                        <span>Сертификаты</span>
                        <b>{{ $selectedClient->certificates_count ?? 0 }}</b>
                        @if(($selectedClient->certificates_used_count ?? 0) > 0)
                            <em>({{ $selectedClient->certificates_used_count }} использ.)</em>
                        @endif
                        <i class="bi bi-chevron-right client-cert-btn-arrow"></i>
                    </a>
                </div>

                @if(isset($clientGroups) && $clientGroups->count())
                <div class="client-detail-section">
                    <div class="client-detail-label">Группы</div>
                    <div class="client-detail-fields">
                        @foreach($clientGroups as $gm)
                        <div class="client-detail-field">
                            <a href="{{ route('club.groups.show', $gm->group) }}" style="color:var(--cl-text);text-decoration:none;">{{ $gm->group->name }}</a>
                            <span class="field-label">осталось: {{ $gm->remaining }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                @if(isset($clientTrials) && $clientTrials->isNotEmpty())
                <div class="client-detail-section">
                    <div class="client-detail-label">Пробные занятия <span class="trial-count-badge">{{ $clientTrials->count() }}</span></div>
                    <div class="client-detail-fields">
                        @foreach($clientTrials as $tr)
                        <div class="client-detail-field">
                            <span>{{ optional($tr->session)->date ? $tr->session->date->format('d.m.Y') : '—' }} · {{ optional(optional($tr->session)->group)->name ?? 'Занятие' }}</span>
                            <span class="field-label">{{ (int) $tr->trial_amount > 0 ? number_format($tr->trial_amount, 0, '', ' ') . ' ₸' : 'бесплатно' }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                <div class="client-detail-section">
                    <div class="client-detail-label client-cards-head">
                        <span>Клубные карты</span>
                        <button type="button" class="btn-attach-card" onclick="openIssueCardModal()">+ Привязать</button>
                    </div>
                    @if($clientCards->isEmpty())
                        <div class="client-cards-empty">Карт нет</div>
                    @else
                    <div class="client-cards-list">
                        @foreach($clientCards as $card)
                        @php $actual = $card->isActual(); @endphp
                        <div class="client-card-item {{ $actual ? '' : 'is-inactive' }}">
                            <div class="cci-main" role="button" title="История карты"
                                 onclick="openCardHistory('{{ route('club.cards.history', $card) }}?bare=1')">
                                <div class="cci-name">{{ $card->type?->name ?? 'Карта' }}</div>
                                <div class="cci-sub">
                                    <span class="cci-code">{{ $card->code }}</span>
                                    @if($card->expires_at)
                                        · до {{ $card->expires_at->format('d.m.Y') }}
                                    @endif
                                    @unless($actual) · <span class="cci-dead">не активна</span> @endunless
                                </div>
                            </div>
                            <div class="cci-right">
                                @if($card->isCounter())
                                    <span class="cci-balance">{{ (int) $card->balance }}<span class="cci-of">/{{ (int) $card->initial_balance }} ч</span></span>
                                @else
                                    <span class="cci-balance cci-discount">−{{ $card->type?->discount_percent }}%</span>
                                @endif
                                @php
                                    $cardCanDelete = !$card->transactions()->exists()
                                        && !($card->isCounter() && (int) $card->balance <= 0);
                                @endphp
                                @if($cardCanDelete)
                                    <form action="{{ route('club.cards.destroy', $card) }}" method="POST"
                                          onsubmit="return confirm('Удалить карту «{{ $card->type?->name }}» (код {{ $card->code }})?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="cci-unlink" title="Удалить (карта без списаний)"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>

                @if($selectedClient->note)
                <div class="client-detail-section">
                    <div class="client-detail-label">Заметка</div>
                    <div class="client-detail-note">{{ $selectedClient->note }}</div>
                </div>
                @endif

                <div class="client-detail-actions">
                    <button type="button" class="btn-bookings"
                            onclick="openBookingsDrawer('{{ route('club.clients.bookings', $selectedClient) }}?bare=1')">
                        <i class="bi bi-calendar-week"></i>
                        Брони
                    </button>
                    <button class="btn-edit" onclick="openEditModal()">
                        <i class="bi bi-pencil"></i>
                        Редактировать
                    </button>
                    <form action="{{ route('club.clients.destroy', $selectedClient) }}" method="POST"
                          onsubmit="return confirm('Удалить клиента {{ $selectedClient->name }}?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-delete">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </form>
                </div>
            </div>
            @else
            <div class="client-detail-empty">
                <i class="bi bi-person"></i>
                <p>Выберите клиента</p>
            </div>
            @endif
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Добавить клиента</h2>
            <button class="modal-close" onclick="closeModal('addModal')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <form action="{{ route('club.clients.store') }}" method="POST">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Имя *</label>
                    <input type="text" name="name" class="form-input" placeholder="Имя клиента" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Телефон</label>
                    <input type="text" name="phone" class="form-input" placeholder="+7 ___ ___ __ __">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Пол</label>
                        <select name="gender" class="form-input">
                            <option value="">—</option>
                            <option value="male">Мужской</option>
                            <option value="female">Женский</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Дата рождения</label>
                        <input type="date" name="birth_date" class="form-input">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Заметка</label>
                    <textarea name="note" class="form-input form-textarea" placeholder="Заметка о клиенте..." rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Отмена</button>
                <button type="submit" class="btn-save">Добавить</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
@if($selectedClient)
<div class="modal-overlay" id="editModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Редактировать клиента</h2>
            <button class="modal-close" onclick="closeModal('editModal')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <form action="{{ route('club.clients.update', $selectedClient) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Имя *</label>
                    <input type="text" name="name" class="form-input" value="{{ $selectedClient->name }}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Телефон</label>
                    <input type="text" name="phone" class="form-input" value="{{ $selectedClient->phone }}">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Пол</label>
                        <select name="gender" class="form-input">
                            <option value="">—</option>
                            <option value="male" {{ $selectedClient->gender === 'male' ? 'selected' : '' }}>Мужской</option>
                            <option value="female" {{ $selectedClient->gender === 'female' ? 'selected' : '' }}>Женский</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Дата рождения</label>
                        <input type="date" name="birth_date" class="form-input" value="{{ $selectedClient->birth_date?->format('Y-m-d') }}">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Заметка</label>
                    <textarea name="note" class="form-input form-textarea" rows="3">{{ $selectedClient->note }}</textarea>
                </div>

                {{-- Привязка к пользователю приложения --}}
                <div class="form-group" style="border-top:1px solid #27272a;padding-top:14px;margin-top:4px">
                    <label class="form-label">Пользователь приложения</label>
                    @if($selectedClient->user)
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;background:#18181b;border:1px solid #27272a;border-radius:8px;padding:10px 12px;margin-bottom:8px">
                            <span style="font-size:13px;color:#e4e4e7">
                                Привязан: <b>{{ $selectedClient->user->name }}</b><span style="color:#8a8a8f"> · {{ $selectedClient->user->phone }}</span>
                            </span>
                            <button type="submit" formnovalidate
                                    formaction="{{ route('club.clients.unlinkAppUser', $selectedClient) }}"
                                    style="background:rgba(248,113,113,.14);border:1px solid rgba(248,113,113,.4);color:#f87171;font-size:12px;font-weight:700;padding:7px 13px;border-radius:8px;cursor:pointer;white-space:nowrap">
                                Отозвать
                            </button>
                        </div>
                    @endif
                    <input type="text" name="app_link_phone" class="form-input" placeholder="+7 702 806 53 31">
                    <small class="form-hint">Если телефон в базе не совпадает с номером в приложении — впишите сюда номер клиента из приложения (можно с +7, 8, пробелами), чтобы он увидел свои клубные карты. Пусто — не менять.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Отмена</button>
                <button type="submit" class="btn-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- Issue Card Modal -->
<div class="modal-overlay" id="issueCardModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Привязать карту</h2>
            <button class="modal-close" onclick="closeModal('issueCardModal')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <form action="{{ route('club.cards.issue') }}" method="POST">
            @csrf
            <input type="hidden" name="club_client_id" value="{{ $selectedClient->id }}">
            <div class="modal-body">
                @if($cardTypes->isEmpty())
                    <div class="client-cards-empty">Сначала создайте типы карт в разделе «Клубные карты».</div>
                @else
                <div class="form-group">
                    <label class="form-label">Тип карты *</label>
                    <select name="club_card_type_id" id="issueType" class="form-input" onchange="issueToggle()" required>
                        @foreach($cardTypes as $ct)
                        <option value="{{ $ct->id }}"
                            data-counter="{{ $ct->isCounter() ? 1 : 0 }}"
                            data-nominal="{{ (int) $ct->nominal }}"
                            data-discount="{{ (int) $ct->discount_percent }}"
                            data-days="{{ (int) $ct->default_validity_days }}"
                            data-date="{{ $ct->default_expires_at?->format('Y-m-d') }}">
                            {{ $ct->name }} ({{ $ct->kindName() }})
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" id="issueBalanceField">
                    <label class="form-label">Остаток (число часов)</label>
                    <input type="number" name="balance" id="issueBalance" class="form-input" min="0" max="100000">
                    <div class="issue-hint">Пусто = номинал типа</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Действует до (необязательно)</label>
                    <input type="date" name="expires_at" id="issueExpires" class="form-input">
                    <div class="issue-hint" id="issueExpiresHint"></div>
                </div>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('issueCardModal')">Отмена</button>
                @if($cardTypes->isNotEmpty())
                <button type="submit" class="btn-save">Привязать</button>
                @endif
            </div>
        </form>
    </div>
</div>
@endif

<!-- Брони клиента — выезжающая справа панель (загружается в iframe) -->
<div class="modal-overlay" id="bookingsModal">
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h2 class="modal-title">Брони клиента</h2>
            <button class="modal-close" onclick="closeModal('bookingsModal')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body">
            <iframe id="bookingsFrame" class="bookings-frame" src="about:blank" loading="lazy"></iframe>
        </div>
    </div>
</div>

<!-- История клубной карты — выезжающая справа панель -->
<div class="modal-overlay" id="cardHistoryModal">
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h2 class="modal-title">История карты</h2>
            <button class="modal-close" onclick="closeModal('cardHistoryModal')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body">
            <iframe id="cardHistoryFrame" class="bookings-frame" src="about:blank" loading="lazy"></iframe>
        </div>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addModal').classList.add('active');
}
function openBookingsDrawer(url) {
    document.getElementById('bookingsFrame').src = url;
    document.getElementById('bookingsModal').classList.add('active');
}
function openCardHistory(url) {
    document.getElementById('cardHistoryFrame').src = url;
    document.getElementById('cardHistoryModal').classList.add('active');
}
function openEditModal() {
    document.getElementById('editModal').classList.add('active');
}
function openIssueCardModal() {
    document.getElementById('issueCardModal').classList.add('active');
    issueToggle();
}
function issueToggle() {
    const sel = document.getElementById('issueType');
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    const counter = opt.dataset.counter === '1';
    document.getElementById('issueBalanceField').style.display = counter ? '' : 'none';
    const bal = document.getElementById('issueBalance');
    if (counter && !bal.value) bal.placeholder = opt.dataset.nominal || '';
    // Подсказка срока по умолчанию из типа.
    const hint = document.getElementById('issueExpiresHint');
    if (opt.dataset.date) hint.textContent = 'По умолчанию: до ' + opt.dataset.date.split('-').reverse().join('.');
    else if (opt.dataset.days && opt.dataset.days !== '0') hint.textContent = 'По умолчанию: ' + opt.dataset.days + ' дн. с сегодня';
    else hint.textContent = 'По умолчанию: бессрочно';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
document.querySelectorAll('.modal-overlay').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(function(m) {
            m.classList.remove('active');
        });
    }
});
</script>

<style>
:root {
    --cl-bg: #0a0a0b;
    --cl-bg-secondary: #111113;
    --cl-card: #16161a;
    --cl-card-hover: #1c1c21;
    --cl-accent: #22c55e;
    --cl-accent-dark: #16a34a;
    --cl-blue: #3b82f6;
    --cl-text: #f4f4f5;
    --cl-text-dim: #a1a1aa;
    --cl-text-muted: #71717a;
    --cl-border: #27272a;
    --cl-border-light: #3f3f46;
    --cl-red: #ef4444;
}

.clients-container {
    width: 100%;
    padding: 32px 40px;
}

/* Header */
.clients-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
}
.clients-title-block {
    display: flex;
    align-items: center;
    gap: 14px;
}
.clients-title-block i {
    font-size: 26px;
    color: var(--cl-accent);
}
.clients-title {
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -0.5px;
}
.clients-count {
    background: var(--cl-accent);
    color: var(--cl-bg);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
}
.clients-header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
.btn-add-client {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--cl-accent);
    color: var(--cl-bg);
    border: none;
    padding: 12px 20px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-add-client:hover {
    background: var(--cl-accent-dark);
    transform: translateY(-1px);
}
.btn-export-client {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--cl-card);
    border: 1px solid var(--cl-border);
    color: var(--cl-text-dim);
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-export-client:hover {
    border-color: #22c55e;
    color: #22c55e;
}
.btn-export-client i { font-size: 16px; }
.btn-duplicates-client {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--cl-card);
    border: 1px solid var(--cl-border);
    color: var(--cl-text-dim);
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-duplicates-client:hover {
    border-color: #f97316;
    color: #f97316;
}
.btn-duplicates-client i { font-size: 16px; }

/* Search */
.clients-search {
    position: relative;
    max-width: 400px;
    margin-bottom: 24px;
}
.clients-search > i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 18px;
    color: var(--cl-text-muted);
}
.clients-search .search-input {
    width: 100%;
    background: var(--cl-card);
    border: 1px solid var(--cl-border);
    border-radius: 10px;
    padding: 12px 40px 12px 44px;
    font-size: 14px;
    font-weight: 500;
    color: var(--cl-text);
    transition: all 0.2s;
}
.clients-search .search-input::placeholder { color: var(--cl-text-muted); }
.clients-search .search-input:focus {
    outline: none;
    border-color: var(--cl-accent);
    box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
}
.search-clear {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--cl-text-muted);
    text-decoration: none;
    font-size: 16px;
}
.search-clear:hover { color: var(--cl-text); }

/* Alert */
.alert-success {
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    color: var(--cl-accent);
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 24px;
}
.alert-error {
    background: rgba(239, 68, 68, 0.10);
    border: 1px solid rgba(239, 68, 68, 0.35);
    color: #fca5a5;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert-error i { color: #ef4444; font-size: 18px; }

/* Layout */
.clients-layout {
    display: flex;
    gap: 24px;
}
.clients-list-col {
    flex: 1;
    min-width: 0;
}
.clients-detail-col {
    width: 380px;
    flex-shrink: 0;
}

/* List */
.clients-list {
    background: var(--cl-bg-secondary);
    border: 1px solid var(--cl-border);
    border-radius: 16px;
    overflow: hidden;
}
.clients-list-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--cl-border);
    cursor: pointer;
    transition: background 0.15s;
    text-decoration: none;
    color: inherit;
}
.clients-list-item:last-child { border-bottom: none; }
.clients-list-item:hover { background: var(--cl-card-hover); }
.clients-list-item.selected {
    background: var(--cl-card-hover);
    border-left: 3px solid var(--cl-accent);
}
.client-avatar {
    width: 42px;
    height: 42px;
    background: linear-gradient(135deg, var(--cl-accent), var(--cl-accent-dark));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 700;
    color: var(--cl-bg);
    flex-shrink: 0;
}
.client-list-info { flex: 1; min-width: 0; }
.client-list-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--cl-text);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.client-list-phone {
    font-size: 13px;
    color: var(--cl-text-muted);
}
.client-no-lastname {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: rgba(249, 115, 22, 0.12);
    color: #f97316;
    border: 1px solid rgba(249, 115, 22, 0.3);
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.4;
    white-space: nowrap;
}
.client-no-lastname i { font-size: 12px; }
.client-detail-no-lastname {
    margin-top: 12px;
    padding: 10px 12px;
    background: rgba(249, 115, 22, 0.10);
    border: 1px solid rgba(249, 115, 22, 0.35);
    border-radius: 10px;
    color: #fdba74;
    font-size: 12.5px;
    font-weight: 600;
    line-height: 1.4;
    display: flex;
    align-items: center;
    gap: 8px;
    text-align: left;
}
.client-detail-no-lastname i {
    color: #f97316;
    font-size: 16px;
    flex-shrink: 0;
}
.clients-empty {
    padding: 60px 20px;
    text-align: center;
    color: var(--cl-text-muted);
}
.clients-empty i { font-size: 40px; margin-bottom: 12px; display: block; }
.clients-empty p { font-size: 15px; }

/* Pagination */
.clients-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    margin-top: 12px;
}
.pagination-info {
    font-size: 13px;
    color: var(--cl-text-muted);
}
.pagination-controls {
    display: flex;
    align-items: center;
    gap: 6px;
}
.page-btn {
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--cl-bg-secondary);
    border: 1px solid var(--cl-border);
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--cl-text-dim);
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.page-btn:hover { border-color: var(--cl-border-light); color: var(--cl-text); }
.page-btn.active {
    background: var(--cl-accent);
    border-color: var(--cl-accent);
    color: var(--cl-bg);
}
.page-btn:disabled { opacity: 0.3; cursor: default; }
.page-btn.dots { border: none; background: none; cursor: default; }

/* Detail panel */
.client-detail {
    background: var(--cl-bg-secondary);
    border: 1px solid var(--cl-border);
    border-radius: 16px;
    padding: 24px;
    position: sticky;
    top: 24px;
}
.client-detail-header {
    text-align: center;
    margin-bottom: 24px;
}
.client-detail-avatar {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, var(--cl-accent), var(--cl-accent-dark));
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 700;
    color: var(--cl-bg);
    margin: 0 auto 12px;
}
.client-detail-name {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 4px;
}
.client-detail-phone {
    font-size: 14px;
    color: var(--cl-text-dim);
}
.client-detail-section {
    margin-bottom: 20px;
}
.client-detail-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--cl-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}
.client-detail-fields {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.client-detail-field {
    display: flex;
    justify-content: space-between;
    padding: 10px 14px;
    background: var(--cl-card);
    border-radius: 8px;
}
.field-label {
    font-size: 13px;
    color: var(--cl-text-muted);
}
.field-value {
    font-size: 14px;
    font-weight: 600;
}
.client-email-link {
    color: #60a5fa;
    text-decoration: none;
    font-weight: 600;
    word-break: break-all;
}
.client-email-link:hover { text-decoration: underline; }
.client-card-badge {
    display: inline-block;
    padding: 2px 10px;
    background: rgba(168,85,247,0.12);
    border: 1px solid rgba(168,85,247,0.25);
    border-radius: 999px;
    color: #c084fc;
    font-size: 12px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.3px;
}
.client-detail-note {
    padding: 12px 14px;
    background: var(--cl-card);
    border-radius: 8px;
    font-size: 13px;
    color: var(--cl-text-dim);
    line-height: 1.5;
}
.client-detail-actions {
    display: flex;
    gap: 8px;
    margin-top: 20px;
}
.btn-edit {
    flex: 1;
    padding: 12px;
    background: var(--cl-card);
    border: 1px solid var(--cl-border);
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    color: var(--cl-text-dim);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.2s;
}
.btn-edit:hover { border-color: var(--cl-blue); color: var(--cl-blue); }
.btn-bookings {
    flex: 1;
    padding: 12px;
    background: rgba(34,197,94,0.12);
    border: 1px solid rgba(34,197,94,0.4);
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    color: #22c55e;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-bookings:hover { background: rgba(34,197,94,0.2); color: #22c55e; }
.btn-delete {
    padding: 12px 16px;
    background: var(--cl-card);
    border: 1px solid var(--cl-border);
    border-radius: 10px;
    font-size: 14px;
    color: var(--cl-text-muted);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}
.btn-delete:hover { border-color: var(--cl-red); color: var(--cl-red); }
.client-detail-empty {
    background: var(--cl-bg-secondary);
    border: 1px solid var(--cl-border);
    border-radius: 16px;
    padding: 60px 24px;
    text-align: center;
    color: var(--cl-text-muted);
}
.client-detail-empty i { font-size: 40px; margin-bottom: 12px; display: block; }

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: stretch;
    justify-content: flex-end;
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s;
}
.modal-overlay.active {
    opacity: 1;
    visibility: visible;
}
/* Выезжающая справа панель (drawer), как в расписании кортов */
.modal-content {
    background: var(--cl-bg-secondary);
    border: none;
    border-left: 1px solid var(--cl-border);
    border-radius: 0;
    width: 100%;
    max-width: 480px;
    height: 100%;
    overflow-y: auto;
    transform: translateX(100%);
    transition: transform 0.3s ease-out;
}
.modal-overlay.active .modal-content {
    transform: translateX(0);
}
/* Панель «Брони» — шире */
.modal-content.modal-content-wide { max-width: 1000px; }
.modal-content.modal-content-wide .modal-body { padding: 0; }
.bookings-frame { width: 100%; height: calc(100vh - 64px); border: 0; display: block; }
@media (max-width: 575.98px) {
    .modal-content { max-width: 100vw; }
}
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid var(--cl-border);
}
.modal-title {
    font-size: 18px;
    font-weight: 700;
}
.modal-close {
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    background: var(--cl-card);
    border: 1px solid var(--cl-border);
    border-radius: 8px;
    cursor: pointer;
    color: var(--cl-text-dim);
    font-size: 16px;
    transition: all 0.2s;
}
.modal-close:hover { border-color: var(--cl-red); color: var(--cl-red); }
.modal-body { padding: 24px; }
.modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    padding: 20px 24px;
    border-top: 1px solid var(--cl-border);
}
.form-group { margin-bottom: 20px; }
.form-group:last-child { margin-bottom: 0; }
.form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--cl-text-dim);
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.form-input {
    width: 100%;
    background: var(--cl-card);
    border: 1px solid var(--cl-border);
    border-radius: 10px;
    padding: 14px 16px;
    font-size: 15px;
    font-weight: 500;
    color: var(--cl-text);
    transition: all 0.2s;
}
.form-input::placeholder { color: var(--cl-text-muted); }
.form-input:focus {
    outline: none;
    border-color: var(--cl-accent);
    box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
}
.form-textarea {
    resize: vertical;
    min-height: 80px;
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.btn-cancel {
    padding: 12px 20px;
    background: var(--cl-card);
    border: 1px solid var(--cl-border);
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    color: var(--cl-text-dim);
    cursor: pointer;
    transition: all 0.2s;
}
.btn-cancel:hover { border-color: var(--cl-border-light); color: var(--cl-text); }
.btn-save {
    padding: 12px 24px;
    background: var(--cl-accent);
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    color: var(--cl-bg);
    cursor: pointer;
    transition: all 0.2s;
}
.btn-save:hover { background: var(--cl-accent-dark); }

/* Клубные карты в карточке клиента */
.client-cards-head { display:flex; align-items:center; justify-content:space-between; }
.btn-attach-card { background:rgba(34,197,94,.14); color:var(--cl-accent); border:1px solid rgba(34,197,94,.3); border-radius:8px; padding:4px 10px; font-size:12px; font-weight:700; cursor:pointer; }
.client-cards-empty { color:var(--cl-text-muted); font-size:13px; padding:6px 0; }
.trial-count-badge { display:inline-flex; align-items:center; justify-content:center; min-width:18px; height:18px; padding:0 6px; margin-left:6px; border-radius:9px; background:rgba(168,85,247,.18); color:#c084fc; font-size:11px; font-weight:700; }
.client-cards-list { display:flex; flex-direction:column; gap:8px; margin-top:6px; }
.client-card-item { display:flex; align-items:center; gap:10px; background:var(--cl-card); border:1px solid var(--cl-border); border-radius:10px; padding:10px 12px; }
.client-card-item.is-inactive { opacity:.55; }
.cci-main { flex:1; min-width:0; cursor:pointer; }
.cci-main:hover .cci-name { color:#22c55e; }
.cci-name { color:var(--cl-text); font-weight:600; font-size:14px; }
.cci-sub { color:var(--cl-text-muted); font-size:11px; margin-top:2px; }
.cci-code { font-family:monospace; letter-spacing:1px; color:var(--cl-text-dim); }
.cci-dead { color:#ef4444; }
.cci-right { display:flex; align-items:center; gap:10px; }
.cci-balance { color:var(--cl-accent); font-weight:800; font-size:16px; }
.cci-balance.cci-discount { color:#f08446; font-size:14px; }
.cci-of { color:var(--cl-text-muted); font-weight:500; font-size:12px; }
.cci-unlink { background:transparent; border:none; color:var(--cl-text-muted); cursor:pointer; padding:4px; }
.cci-unlink:hover { color:#ef4444; }
.issue-hint { color:var(--cl-text-muted); font-size:11px; margin-top:4px; }

/* Responsive */
@media (max-width: 900px) {
    .clients-container { padding: 24px 20px; }
    .clients-detail-col { display: none; }
    .clients-search { max-width: none; }
}
</style>
@endsection

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

    <!-- Layout: list + detail -->
    <div class="clients-layout">
        <!-- Left: list -->
        <div class="clients-list-col">
            <div class="clients-list">
                @forelse($clients as $client)
                    <a href="{{ route('club.clients.index', ['selected' => $client->id, 'search' => request('search'), 'page' => $clients->currentPage()]) }}"
                       class="clients-list-item {{ $selectedClient && $selectedClient->id === $client->id ? 'selected' : '' }}">
                        <div class="client-avatar">{{ mb_strtoupper(mb_substr($client->name, 0, 1)) }}</div>
                        <div class="client-list-info">
                            <div class="client-list-name">{{ $client->name }}</div>
                            <div class="client-list-phone">{{ $client->phone ? '+' . preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $client->phone) : '—' }}</div>
                        </div>
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
            <div class="client-detail">
                <div class="client-detail-header">
                    <div class="client-detail-avatar">{{ mb_strtoupper(mb_substr($selectedClient->name, 0, 1)) }}</div>
                    <div class="client-detail-name">{{ $selectedClient->name }}</div>
                    <div class="client-detail-phone">{{ $selectedClient->phone ? '+' . preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $selectedClient->phone) : '—' }}</div>
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
                        <div class="client-detail-field">
                            <span class="field-label">Добавлен</span>
                            <span class="field-value">{{ $selectedClient->created_at->format('d.m.Y') }}</span>
                        </div>
                    </div>
                </div>

                @if($selectedClient->note)
                <div class="client-detail-section">
                    <div class="client-detail-label">Заметка</div>
                    <div class="client-detail-note">{{ $selectedClient->note }}</div>
                </div>
                @endif

                <div class="client-detail-actions">
                    <a href="{{ route('club.clients.bookings', $selectedClient) }}" class="btn-bookings">
                        <i class="bi bi-calendar-week"></i>
                        Брони
                    </a>
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
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Отмена</button>
                <button type="submit" class="btn-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>
@endif

<script>
function openAddModal() {
    document.getElementById('addModal').classList.add('active');
}
function openEditModal() {
    document.getElementById('editModal').classList.add('active');
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
}
.client-list-phone {
    font-size: 13px;
    color: var(--cl-text-muted);
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
    align-items: center;
    justify-content: center;
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s;
}
.modal-overlay.active {
    opacity: 1;
    visibility: visible;
}
.modal-content {
    background: var(--cl-bg-secondary);
    border: 1px solid var(--cl-border);
    border-radius: 16px;
    width: 100%;
    max-width: 480px;
    transform: translateY(20px);
    transition: transform 0.3s;
}
.modal-overlay.active .modal-content {
    transform: translateY(0);
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

/* Responsive */
@media (max-width: 900px) {
    .clients-container { padding: 24px 20px; }
    .clients-detail-col { display: none; }
    .clients-search { max-width: none; }
}
</style>
@endsection

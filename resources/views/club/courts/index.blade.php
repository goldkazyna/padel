@extends('layouts.app')
@section('title', 'Корты')
@section('content')

<div class="courts-container">
    <!-- Header -->
    <header class="courts-header">
        <div class="courts-title-block">
            <i class="bi bi-grid-3x3" style="font-size: 28px; color: var(--courts-accent);"></i>
            <h1 class="courts-title">Корты</h1>
            <span class="courts-count">{{ $stats['total'] }}</span>
        </div>
        <div class="courts-actions">
            <button class="btn-add-court" onclick="openModal('createCourt')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Добавить корт
            </button>
        </div>
    </header>

    <!-- Flash Messages -->
    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    <!-- Stats -->
    <div class="courts-stats">
        <div class="stat-card">
            <div class="stat-value">{{ $stats['total'] }}</div>
            <div class="stat-label">Всего</div>
        </div>
        <div class="stat-card stat-active">
            <div class="stat-value">{{ $stats['active'] }}</div>
            <div class="stat-label">Активных</div>
        </div>
        <div class="stat-card stat-inactive">
            <div class="stat-value">{{ $stats['inactive'] }}</div>
            <div class="stat-label">Неактивных</div>
        </div>
    </div>

    <!-- Table -->
    <div class="courts-table-wrap">
        <table class="courts-table">
            <thead>
                <tr>
                    <th>Название</th>
                    <th>Описание</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse($courts as $court)
                    <tr>
                        <td>
                            <span class="court-name">{{ $court->name }}</span>
                        </td>
                        <td>
                            <span class="court-description">{{ $court->description ?: '—' }}</span>
                        </td>
                        <td>
                            @if($court->is_active)
                                <span class="status-badge status-active">Активен</span>
                            @else
                                <span class="status-badge status-inactive">Неактивен</span>
                            @endif
                        </td>
                        <td>
                            <div class="court-actions">
                                <button class="action-btn edit" title="Редактировать" onclick="openModal('editCourt{{ $court->id }}')">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <a href="{{ route('club.courts.slots', $court) }}" class="action-btn slots" title="Слоты">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                </a>
                                <form action="{{ route('club.courts.toggleActive', $court) }}" method="POST" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="action-btn {{ $court->is_active ? 'toggle-off' : 'toggle-on' }}" title="{{ $court->is_active ? 'Деактивировать' : 'Активировать' }}">
                                        @if($court->is_active)
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        @else
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                        @endif
                                    </button>
                                </form>
                                <form action="{{ route('club.courts.destroy', $court) }}" method="POST" style="display:inline;" onsubmit="return confirm('Удалить корт «{{ $court->name }}»?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="action-btn delete" title="Удалить">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 40px; color: #71717a;">
                            Корты не найдены. Добавьте первый корт.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Create Modal -->
<div class="modal-overlay" id="createCourt">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Добавить корт</h2>
            <button class="modal-close" onclick="closeModal('createCourt')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form action="{{ route('club.courts.store') }}" method="POST">
            @csrf
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Название</label>
                    <input type="text" name="name" class="form-input" placeholder="Например: Корт 1" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Описание</label>
                    <textarea name="description" class="form-input form-textarea" placeholder="Описание корта (необязательно)" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('createCourt')">Отмена</button>
                <button type="submit" class="btn-save">Добавить</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modals -->
@foreach($courts as $court)
<div class="modal-overlay" id="editCourt{{ $court->id }}">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Редактировать корт</h2>
            <button class="modal-close" onclick="closeModal('editCourt{{ $court->id }}')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form action="{{ route('club.courts.update', $court) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Название</label>
                    <input type="text" name="name" class="form-input" value="{{ $court->name }}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Описание</label>
                    <textarea name="description" class="form-input form-textarea" rows="3">{{ $court->description }}</textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editCourt{{ $court->id }}')">Отмена</button>
                <button type="submit" class="btn-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>
@endforeach

<script>
    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    document.querySelectorAll('.modal-overlay').forEach(function(modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(function(modal) {
                modal.classList.remove('active');
            });
        }
    });
</script>

<style>
    :root {
        --courts-bg: #0a0a0b;
        --courts-bg-secondary: #111113;
        --courts-card: #16161a;
        --courts-card-hover: #1c1c21;
        --courts-accent: #22c55e;
        --courts-accent-dark: #16a34a;
        --courts-blue: #3b82f6;
        --courts-text: #f4f4f5;
        --courts-text-dim: #a1a1aa;
        --courts-text-muted: #71717a;
        --courts-border: #27272a;
        --courts-border-light: #3f3f46;
        --courts-yellow: #facc15;
        --courts-red: #ef4444;
        --courts-orange: #f97316;
    }

    .courts-container {
        width: 100%;
        padding: 32px 40px;
    }

    /* Header */
    .courts-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 32px;
    }

    .courts-title-block {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .courts-title {
        font-size: 26px;
        font-weight: 800;
        letter-spacing: -0.5px;
    }

    .courts-count {
        background: var(--courts-accent);
        color: var(--courts-bg);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 700;
    }

    .btn-add-court {
        display: flex;
        align-items: center;
        gap: 8px;
        background: var(--courts-accent);
        color: var(--courts-bg);
        border: none;
        padding: 12px 20px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-add-court:hover {
        background: var(--courts-accent-dark);
        transform: translateY(-1px);
    }

    .btn-add-court svg {
        width: 18px;
        height: 18px;
    }

    /* Flash Messages */
    .flash-message {
        padding: 14px 20px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 24px;
    }

    .flash-success {
        background: rgba(34, 197, 94, 0.15);
        color: var(--courts-accent);
        border: 1px solid rgba(34, 197, 94, 0.3);
    }

    .flash-error {
        background: rgba(239, 68, 68, 0.15);
        color: var(--courts-red);
        border: 1px solid rgba(239, 68, 68, 0.3);
    }

    /* Stats */
    .courts-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin-bottom: 24px;
    }

    .stat-card {
        background: var(--courts-bg-secondary);
        border: 1px solid var(--courts-border);
        border-radius: 12px;
        padding: 20px;
        text-align: center;
    }

    .stat-value {
        font-size: 32px;
        font-weight: 800;
        letter-spacing: -1px;
        color: var(--courts-text);
    }

    .stat-label {
        font-size: 13px;
        color: var(--courts-text-muted);
        margin-top: 4px;
    }

    .stat-active .stat-value { color: var(--courts-accent); }
    .stat-active { border-color: rgba(34, 197, 94, 0.2); }
    .stat-inactive .stat-value { color: var(--courts-red); }
    .stat-inactive { border-color: rgba(239, 68, 68, 0.2); }

    /* Table */
    .courts-table-wrap {
        background: var(--courts-bg-secondary);
        border: 1px solid var(--courts-border);
        border-radius: 16px;
        overflow: hidden;
    }

    .courts-table {
        width: 100%;
        border-collapse: collapse;
    }

    .courts-table th {
        padding: 16px 20px;
        text-align: left;
        font-size: 12px;
        font-weight: 700;
        color: var(--courts-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: var(--courts-card);
        border-bottom: 1px solid var(--courts-border);
    }

    .courts-table th:first-child { padding-left: 24px; }
    .courts-table th:last-child { padding-right: 24px; text-align: center; }

    .courts-table td {
        padding: 18px 20px;
        font-size: 15px;
        border-bottom: 1px solid var(--courts-border);
        vertical-align: middle;
    }

    .courts-table td:first-child { padding-left: 24px; }
    .courts-table td:last-child { padding-right: 24px; text-align: center; }

    .courts-table tbody tr {
        transition: background 0.15s;
    }

    .courts-table tbody tr:hover {
        background: var(--courts-card-hover);
    }

    .courts-table tbody tr:last-child td {
        border-bottom: none;
    }

    .court-name {
        font-weight: 600;
        color: var(--courts-text);
    }

    .court-description {
        color: var(--courts-text-dim);
        font-size: 14px;
    }

    /* Status Badge */
    .status-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
    }

    .status-active {
        background: rgba(34, 197, 94, 0.15);
        color: var(--courts-accent);
    }

    .status-inactive {
        background: rgba(239, 68, 68, 0.15);
        color: var(--courts-red);
    }

    /* Actions */
    .court-actions {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .action-btn {
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--courts-card);
        border: 1px solid var(--courts-border);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
    }

    .action-btn svg {
        width: 18px;
        height: 18px;
        color: var(--courts-text-dim);
        transition: color 0.2s;
    }

    .action-btn:hover {
        border-color: var(--courts-border-light);
        background: var(--courts-card-hover);
    }

    .action-btn:hover svg { color: var(--courts-text); }
    .action-btn.edit:hover { border-color: var(--courts-blue); }
    .action-btn.edit:hover svg { color: var(--courts-blue); }
    .action-btn.slots:hover { border-color: var(--courts-orange); }
    .action-btn.slots:hover svg { color: var(--courts-orange); }
    .action-btn.toggle-off:hover { border-color: var(--courts-yellow); }
    .action-btn.toggle-off:hover svg { color: var(--courts-yellow); }
    .action-btn.toggle-on:hover { border-color: var(--courts-accent); }
    .action-btn.toggle-on:hover svg { color: var(--courts-accent); }
    .action-btn.delete:hover { border-color: var(--courts-red); }
    .action-btn.delete:hover svg { color: var(--courts-red); }

    /* Modal */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
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
        background: var(--courts-bg-secondary);
        border: 1px solid var(--courts-border);
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
        border-bottom: 1px solid var(--courts-border);
    }

    .modal-title {
        font-size: 18px;
        font-weight: 700;
    }

    .modal-close {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--courts-card);
        border: 1px solid var(--courts-border);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .modal-close svg {
        width: 20px;
        height: 20px;
        color: var(--courts-text-dim);
    }

    .modal-close:hover { border-color: var(--courts-red); }
    .modal-close:hover svg { color: var(--courts-red); }

    .modal-body { padding: 24px; }

    .form-group { margin-bottom: 20px; }
    .form-group:last-child { margin-bottom: 0; }

    .form-label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: var(--courts-text-dim);
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-input {
        width: 100%;
        background: var(--courts-card);
        border: 1px solid var(--courts-border);
        border-radius: 10px;
        padding: 14px 16px;
        font-size: 15px;
        font-weight: 500;
        color: var(--courts-text);
        transition: all 0.2s;
    }

    .form-input::placeholder { color: var(--courts-text-muted); }

    .form-input:focus {
        outline: none;
        border-color: var(--courts-accent);
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
    }

    .form-textarea {
        resize: vertical;
        font-family: inherit;
    }

    .modal-footer {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 12px;
        padding: 20px 24px;
        border-top: 1px solid var(--courts-border);
    }

    .btn-cancel {
        padding: 12px 20px;
        background: var(--courts-card);
        border: 1px solid var(--courts-border);
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        color: var(--courts-text-dim);
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-cancel:hover {
        border-color: var(--courts-border-light);
        color: var(--courts-text);
    }

    .btn-save {
        padding: 12px 24px;
        background: var(--courts-accent);
        border: none;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 700;
        color: var(--courts-bg);
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-save:hover {
        background: var(--courts-accent-dark);
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .courts-container { padding: 24px 20px; }
    }

    @media (max-width: 768px) {
        .courts-stats { grid-template-columns: 1fr; }
        .courts-header { flex-direction: column; gap: 16px; align-items: flex-start; }
    }
</style>
@endsection

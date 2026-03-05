@extends('layouts.app')
@section('title', 'Слоты — ' . $court->name)
@section('content')

<div class="slots-container">
    <!-- Header -->
    <header class="slots-header">
        <div class="slots-title-block">
            <a href="{{ route('club.courts.index') }}" class="back-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
            <h1 class="slots-title">{{ $court->name }}</h1>
            <span class="slots-subtitle">Управление слотами</span>
        </div>
    </header>

    <!-- Flash Messages -->
    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    <!-- Generate Slots Form -->
    <div class="generate-card">
        <h2 class="generate-title">Генерация слотов</h2>
        <form action="{{ route('club.courts.generateSlots', $court) }}" method="POST" class="generate-form">
            @csrf
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Дата от</label>
                    <input type="date" name="date_from" class="form-input" value="{{ $date }}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Дата до</label>
                    <input type="date" name="date_to" class="form-input" value="{{ $date }}" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Время от</label>
                    <input type="time" name="time_from" class="form-input" value="08:00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Время до</label>
                    <input type="time" name="time_to" class="form-input" value="22:00" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Длительность</label>
                    <select name="duration_minutes" class="form-input">
                        <option value="60">60 минут</option>
                        <option value="90">90 минут</option>
                        <option value="30">30 минут</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Цена (₸)</label>
                    <input type="number" name="price" class="form-input" value="5000" min="0" step="100" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Цена выходных (₸)</label>
                    <input type="number" name="weekend_price" class="form-input" placeholder="Как в будни" min="0" step="100">
                    <small style="color: var(--slots-text-muted); margin-top: 4px;">Сб/Вс. Оставьте пустым — будет как в будни</small>
                </div>
                <div class="form-group"></div>
            </div>

            <!-- Weekday checkboxes -->
            <div class="form-group">
                <label class="form-label">Дни недели</label>
                <div class="weekdays-row">
                    @php
                        $dayNames = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
                        $dayOrder = [1, 2, 3, 4, 5, 6, 0]; // Пн-Вс
                    @endphp
                    @foreach($dayOrder as $day)
                        <label class="weekday-checkbox">
                            <input type="checkbox" name="weekdays[]" value="{{ $day }}" checked>
                            <span class="weekday-label">{{ $dayNames[$day] }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <button type="submit" class="btn-generate">Сгенерировать слоты</button>
        </form>
    </div>

    <!-- Single Slot Form -->
    <div class="generate-card">
        <h2 class="generate-title">Добавить один слот</h2>
        <form action="{{ route('club.courts.storeSlot', $court) }}" method="POST" class="generate-form">
            @csrf
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Дата</label>
                    <input type="date" name="date" class="form-input" value="{{ $date }}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Цена (₸)</label>
                    <input type="number" name="price" class="form-input" value="5000" min="0" step="100" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Начало</label>
                    <input type="time" name="start_time" class="form-input" value="10:00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Конец</label>
                    <input type="time" name="end_time" class="form-input" value="11:00" required>
                </div>
            </div>
            <button type="submit" class="btn-generate" style="background: var(--slots-blue);">Добавить слот</button>
        </form>
    </div>

    <!-- Date Filter -->
    <div class="date-filter">
        <form method="GET" class="date-filter-form">
            <label class="form-label">Дата</label>
            <input type="date" name="date" class="form-input" value="{{ $date }}" onchange="this.form.submit()">
        </form>
    </div>

    <!-- Bulk Actions -->
    @if($slots->count() > 0)
    <div class="bulk-actions" id="bulkActions" style="display: none;">
        <form id="bulkForm" action="{{ route('club.courts.bulkSlots', $court) }}" method="POST">
            @csrf
            <div id="bulkSlotInputs"></div>
            <div class="bulk-actions-bar">
                <span class="bulk-count" id="bulkCount">Выбрано: 0</span>
                <button type="submit" name="action" value="delete" class="bulk-btn bulk-delete" onclick="return confirm('Удалить выбранные слоты?')">Удалить</button>
                <button type="submit" name="action" value="block" class="bulk-btn bulk-block">Заблокировать</button>
                <button type="submit" name="action" value="unblock" class="bulk-btn bulk-unblock">Разблокировать</button>
            </div>
        </form>
    </div>
    @endif

    <!-- Slots Table -->
    <div class="slots-table-wrap">
        <table class="slots-table">
            <thead>
                <tr>
                    @if($slots->count() > 0)
                    <th style="width: 40px;">
                        <label class="custom-checkbox">
                            <input type="checkbox" id="selectAll">
                            <span class="checkmark"></span>
                        </label>
                    </th>
                    @endif
                    <th>Время</th>
                    <th>Цена</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse($slots as $slot)
                    <tr>
                        <td>
                            <label class="custom-checkbox">
                                <input type="checkbox" class="slot-checkbox" value="{{ $slot->id }}" {{ $slot->isBooked() ? 'disabled' : '' }}>
                                <span class="checkmark"></span>
                            </label>
                        </td>
                        <td>
                            <span class="slot-time">{{ \Carbon\Carbon::parse($slot->start_time)->format('H:i') }} — {{ \Carbon\Carbon::parse($slot->end_time)->format('H:i') }}</span>
                        </td>
                        <td>
                            <span class="slot-price">{{ number_format($slot->price, 0, '', ' ') }} ₸</span>
                        </td>
                        <td>
                            @if($slot->isBooked())
                                <span class="status-badge status-booked">Забронирован</span>
                            @elseif($slot->status === 'blocked')
                                <span class="status-badge status-blocked">Заблокирован</span>
                            @else
                                <span class="status-badge status-available">Доступен</span>
                            @endif
                        </td>
                        <td>
                            <div class="slot-actions">
                                @if(!$slot->isBooked())
                                    <form action="{{ route('club.courts.toggleSlotBlock', $slot) }}" method="POST" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="action-btn {{ $slot->status === 'blocked' ? 'toggle-on' : 'toggle-off' }}" title="{{ $slot->status === 'blocked' ? 'Разблокировать' : 'Заблокировать' }}">
                                            @if($slot->status === 'blocked')
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                                            @else
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                            @endif
                                        </button>
                                    </form>
                                    <form action="{{ route('club.courts.deleteSlot', $slot) }}" method="POST" style="display:inline;" onsubmit="return confirm('Удалить слот?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="action-btn delete" title="Удалить">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </form>
                                @else
                                    <span style="color: var(--slots-text-muted); font-size: 13px;">Забронирован</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #71717a;">
                            Нет слотов на выбранную дату
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<style>
    :root {
        --slots-bg: #0a0a0b;
        --slots-bg-secondary: #111113;
        --slots-card: #16161a;
        --slots-card-hover: #1c1c21;
        --slots-accent: #22c55e;
        --slots-accent-dark: #16a34a;
        --slots-blue: #3b82f6;
        --slots-text: #f4f4f5;
        --slots-text-dim: #a1a1aa;
        --slots-text-muted: #71717a;
        --slots-border: #27272a;
        --slots-border-light: #3f3f46;
        --slots-yellow: #facc15;
        --slots-red: #ef4444;
        --slots-orange: #f97316;
    }

    .slots-container {
        width: 100%;
        padding: 32px 40px;
    }

    /* Header */
    .slots-header {
        display: flex;
        align-items: center;
        margin-bottom: 32px;
    }

    .slots-title-block {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .back-link {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--slots-card);
        border: 1px solid var(--slots-border);
        border-radius: 10px;
        color: var(--slots-text-dim);
        transition: all 0.2s;
        text-decoration: none;
    }

    .back-link:hover {
        border-color: var(--slots-accent);
        color: var(--slots-accent);
    }

    .back-link svg {
        width: 20px;
        height: 20px;
    }

    .slots-title {
        font-size: 26px;
        font-weight: 800;
        letter-spacing: -0.5px;
    }

    .slots-subtitle {
        font-size: 14px;
        color: var(--slots-text-muted);
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
        color: var(--slots-accent);
        border: 1px solid rgba(34, 197, 94, 0.3);
    }

    .flash-error {
        background: rgba(239, 68, 68, 0.15);
        color: var(--slots-red);
        border: 1px solid rgba(239, 68, 68, 0.3);
    }

    /* Generate Card */
    .generate-card {
        background: var(--slots-bg-secondary);
        border: 1px solid var(--slots-border);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
    }

    .generate-title {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 20px;
    }

    .generate-form {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }

    .form-group { display: flex; flex-direction: column; }

    .form-label {
        font-size: 13px;
        font-weight: 600;
        color: var(--slots-text-dim);
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-input {
        width: 100%;
        background: var(--slots-card);
        border: 1px solid var(--slots-border);
        border-radius: 10px;
        padding: 14px 16px;
        font-size: 15px;
        font-weight: 500;
        color: var(--slots-text);
        transition: all 0.2s;
    }

    .form-input:focus {
        outline: none;
        border-color: var(--slots-accent);
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
    }

    .btn-generate {
        align-self: flex-start;
        display: flex;
        align-items: center;
        gap: 8px;
        background: var(--slots-accent);
        color: var(--slots-bg);
        border: none;
        padding: 14px 28px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-generate:hover {
        background: var(--slots-accent-dark);
        transform: translateY(-1px);
    }

    /* Weekdays */
    .weekdays-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .weekday-checkbox {
        display: flex;
        align-items: center;
        cursor: pointer;
    }

    .weekday-checkbox input {
        display: none;
    }

    .weekday-label {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 48px;
        height: 40px;
        background: var(--slots-card);
        border: 1px solid var(--slots-border);
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        color: var(--slots-text-dim);
        transition: all 0.2s;
        user-select: none;
    }

    .weekday-checkbox input:checked + .weekday-label {
        background: var(--slots-accent);
        color: var(--slots-bg);
        border-color: var(--slots-accent);
    }

    .weekday-checkbox:hover .weekday-label {
        border-color: var(--slots-accent);
    }

    /* Custom Checkbox */
    .custom-checkbox {
        display: inline-flex;
        align-items: center;
        cursor: pointer;
        position: relative;
    }

    .custom-checkbox input {
        display: none;
    }

    .custom-checkbox .checkmark {
        width: 20px;
        height: 20px;
        background: var(--slots-card);
        border: 1.5px solid var(--slots-border-light);
        border-radius: 5px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .custom-checkbox input:checked + .checkmark {
        background: var(--slots-accent);
        border-color: var(--slots-accent);
    }

    .custom-checkbox input:checked + .checkmark::after {
        content: '';
        width: 6px;
        height: 10px;
        border: solid var(--slots-bg);
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
        margin-top: -2px;
    }

    .custom-checkbox input:disabled + .checkmark {
        opacity: 0.3;
        cursor: not-allowed;
    }

    /* Bulk Actions */
    .bulk-actions {
        margin-bottom: 16px;
    }

    .bulk-actions-bar {
        display: flex;
        align-items: center;
        gap: 12px;
        background: var(--slots-bg-secondary);
        border: 1px solid var(--slots-border);
        border-radius: 12px;
        padding: 12px 20px;
    }

    .bulk-count {
        font-size: 14px;
        font-weight: 600;
        color: var(--slots-text-dim);
        margin-right: auto;
    }

    .bulk-btn {
        padding: 8px 18px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        border: 1px solid var(--slots-border);
        background: var(--slots-card);
        color: var(--slots-text-dim);
        cursor: pointer;
        transition: all 0.2s;
    }

    .bulk-btn:hover {
        background: var(--slots-card-hover);
    }

    .bulk-delete:hover {
        border-color: var(--slots-red);
        color: var(--slots-red);
    }

    .bulk-block:hover {
        border-color: var(--slots-yellow);
        color: var(--slots-yellow);
    }

    .bulk-unblock:hover {
        border-color: var(--slots-accent);
        color: var(--slots-accent);
    }

    /* Date Filter */
    .date-filter {
        margin-bottom: 24px;
    }

    .date-filter-form {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .date-filter-form .form-label {
        margin-bottom: 0;
        white-space: nowrap;
    }

    .date-filter-form .form-input {
        max-width: 200px;
    }

    /* Slots Table */
    .slots-table-wrap {
        background: var(--slots-bg-secondary);
        border: 1px solid var(--slots-border);
        border-radius: 16px;
        overflow: hidden;
    }

    .slots-table {
        width: 100%;
        border-collapse: collapse;
    }

    .slots-table th {
        padding: 16px 20px;
        text-align: left;
        font-size: 12px;
        font-weight: 700;
        color: var(--slots-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: var(--slots-card);
        border-bottom: 1px solid var(--slots-border);
    }

    .slots-table th:first-child { padding-left: 24px; }
    .slots-table th:last-child { padding-right: 24px; text-align: center; }

    .slots-table td {
        padding: 18px 20px;
        font-size: 15px;
        border-bottom: 1px solid var(--slots-border);
        vertical-align: middle;
    }

    .slots-table td:first-child { padding-left: 24px; }
    .slots-table td:last-child { padding-right: 24px; text-align: center; }

    .slots-table tbody tr {
        transition: background 0.15s;
    }

    .slots-table tbody tr:hover {
        background: var(--slots-card-hover);
    }

    .slots-table tbody tr:last-child td {
        border-bottom: none;
    }

    .slot-time {
        font-weight: 600;
        color: var(--slots-text);
        font-variant-numeric: tabular-nums;
    }

    .slot-price {
        font-weight: 600;
        color: var(--slots-text-dim);
    }

    /* Status Badge */
    .status-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
    }

    .status-available {
        background: rgba(34, 197, 94, 0.15);
        color: var(--slots-accent);
    }

    .status-booked {
        background: rgba(59, 130, 246, 0.15);
        color: var(--slots-blue);
    }

    .status-blocked {
        background: rgba(239, 68, 68, 0.15);
        color: var(--slots-red);
    }

    /* Actions */
    .slot-actions {
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
        background: var(--slots-card);
        border: 1px solid var(--slots-border);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .action-btn svg {
        width: 18px;
        height: 18px;
        color: var(--slots-text-dim);
        transition: color 0.2s;
    }

    .action-btn:hover {
        border-color: var(--slots-border-light);
        background: var(--slots-card-hover);
    }

    .action-btn:hover svg { color: var(--slots-text); }
    .action-btn.toggle-on:hover { border-color: var(--slots-accent); }
    .action-btn.toggle-on:hover svg { color: var(--slots-accent); }
    .action-btn.toggle-off:hover { border-color: var(--slots-yellow); }
    .action-btn.toggle-off:hover svg { color: var(--slots-yellow); }
    .action-btn.delete:hover { border-color: var(--slots-red); }
    .action-btn.delete:hover svg { color: var(--slots-red); }

    /* Responsive */
    @media (max-width: 1024px) {
        .slots-container { padding: 24px 20px; }
    }

    @media (max-width: 768px) {
        .form-row { grid-template-columns: 1fr; }
        .bulk-actions-bar { flex-wrap: wrap; }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.slot-checkbox:not(:disabled)');
    const bulkActions = document.getElementById('bulkActions');
    const bulkCount = document.getElementById('bulkCount');
    const bulkSlotInputs = document.getElementById('bulkSlotInputs');

    if (!selectAll || !checkboxes.length) return;

    function updateBulkUI() {
        const checked = document.querySelectorAll('.slot-checkbox:checked');
        const count = checked.length;

        if (count > 0) {
            bulkActions.style.display = 'block';
            bulkCount.textContent = 'Выбрано: ' + count;

            // Update hidden inputs
            bulkSlotInputs.innerHTML = '';
            checked.forEach(function(cb) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'slot_ids[]';
                input.value = cb.value;
                bulkSlotInputs.appendChild(input);
            });
        } else {
            bulkActions.style.display = 'none';
        }
    }

    selectAll.addEventListener('change', function() {
        checkboxes.forEach(function(cb) {
            cb.checked = selectAll.checked;
        });
        updateBulkUI();
    });

    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', function() {
            const allChecked = document.querySelectorAll('.slot-checkbox:not(:disabled)').length ===
                               document.querySelectorAll('.slot-checkbox:checked').length;
            selectAll.checked = allChecked;
            updateBulkUI();
        });
    });
});
</script>
@endsection

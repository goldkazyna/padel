@extends('layouts.app')
@section('title', 'Настройки кортов')
@section('content')

<div class="courts-container">
    <!-- Header -->
    <div class="courts-header">
        <div class="courts-header-left">
            <a href="{{ route('club.courts.schedule') }}" class="back-link" title="Назад к расписанию">&#8249;</a>
            <h1 class="courts-title">Настройки кортов</h1>
        </div>
        <button class="btn-add" onclick="window.dispatchEvent(new CustomEvent('open-create-modal'))">+ Добавить корт</button>
    </div>

    <!-- Flash Messages -->
    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    <!-- Court Cards -->
    @forelse($courts as $court)
        <div class="court-card{{ !$court->is_active ? ' court-inactive' : '' }}">
            <div class="court-card-header">
                <div class="court-card-left">
                    <span class="court-name">{{ $court->name }}</span>
                    @if($court->is_active)
                        <span class="court-status active">Активен</span>
                    @else
                        <span class="court-status inactive">Неактивен</span>
                    @endif
                </div>
                <div class="court-card-actions">
                    <button class="action-btn edit" title="Редактировать" onclick="window.dispatchEvent(new CustomEvent('open-edit-{{ $court->id }}'))">&#9998;</button>
                    <form action="{{ route('club.courts.toggleActive', $court) }}" method="POST" style="display:inline;">
                        @csrf
                        <button type="submit" class="action-btn toggle" title="{{ $court->is_active ? 'Деактивировать' : 'Активировать' }}">&#9673;</button>
                    </form>
                    <form action="{{ route('club.courts.destroy', $court) }}" method="POST" style="display:inline;" onsubmit="return confirm('Удалить корт «{{ $court->name }}»?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="action-btn delete" title="Удалить">&#10005;</button>
                    </form>
                </div>
            </div>
            <div class="court-card-details">
                <div class="detail-group">
                    <span class="detail-label">Часы работы</span>
                    <span class="detail-value">{{ \Carbon\Carbon::parse($court->open_time)->format('H:i') }} — {{ \Carbon\Carbon::parse($court->close_time)->format('H:i') }}</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label">Шаг</span>
                    <span class="detail-value">{{ $court->slot_duration }} мин</span>
                </div>
                @if($court->description)
                    <div class="detail-group">
                        <span class="detail-label">Описание</span>
                        <span class="detail-value">{{ $court->description }}</span>
                    </div>
                @endif
            </div>
            @if($court->priceRanges->count())
                <div style="padding: 0 24px 20px;">
                    <span class="detail-label" style="margin-bottom: 8px; display: block;">Ценовые интервалы</span>
                    <div class="price-tags">
                        @foreach($court->priceRanges as $range)
                            <div class="price-tag">
                                <span class="price-tag-time">{{ \Carbon\Carbon::parse($range->time_from)->format('H:i') }}–{{ \Carbon\Carbon::parse($range->time_to)->format('H:i') }}</span>
                                <span class="price-tag-value">{{ number_format($range->price, 0, '', ' ') }} ₸</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @empty
        <div class="empty-state">
            <p>Корты не найдены. Добавьте первый корт.</p>
            <button class="btn-add" onclick="window.dispatchEvent(new CustomEvent('open-create-modal'))">+ Добавить корт</button>
        </div>
    @endforelse
</div>

<!-- Create Modal -->
<div x-data="{
    open: false,
    ranges: [{ time_from: '08:00', time_to: '22:00', price: '' }],
    addRange() {
        let lastTo = this.ranges.length ? this.ranges[this.ranges.length - 1].time_to : '08:00';
        this.ranges.push({ time_from: lastTo, time_to: '22:00', price: '' });
    },
    removeRange(index) {
        if (this.ranges.length > 1) this.ranges.splice(index, 1);
    }
}" x-on:open-create-modal.window="open = true" @keydown.escape.window="open = false">
    <div class="modal-overlay" x-show="open" x-cloak x-transition.opacity @click.self="open = false">
        <div class="modal" @click.stop>
            <div class="modal-header">
                <h2>Добавить корт</h2>
                <button type="button" class="modal-close" @click="open = false">&#10005;</button>
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
                        <textarea name="description" class="form-input form-textarea" placeholder="Покрытие, особенности (необязательно)"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Начало работы</label>
                            <input type="time" name="open_time" class="form-input" value="08:00" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Конец работы</label>
                            <input type="time" name="close_time" class="form-input" value="22:00" required>
                        </div>
                    </div>

                    <hr class="section-divider">

                    <div class="section-title">Ценовые интервалы</div>
                    <div class="price-ranges">
                        <template x-for="(range, index) in ranges" :key="index">
                            <div class="price-range-row">
                                <div class="form-group">
                                    <label class="form-label">С</label>
                                    <input type="time" class="form-input" x-model="range.time_from" :name="'price_ranges[' + index + '][time_from]'" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">До</label>
                                    <input type="time" class="form-input" x-model="range.time_to" :name="'price_ranges[' + index + '][time_to]'" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Цена (₸)</label>
                                    <input type="number" class="form-input" x-model="range.price" :name="'price_ranges[' + index + '][price]'" placeholder="0" required>
                                </div>
                                <button type="button" class="remove-btn" title="Удалить" @click="removeRange(index)" x-show="ranges.length > 1">&#10005;</button>
                            </div>
                        </template>
                    </div>
                    <button type="button" class="add-range-btn" @click="addRange()">+ Добавить интервал</button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" @click="open = false">Отмена</button>
                    <button type="submit" class="btn-save">Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modals -->
@foreach($courts as $court)
    <div x-data="{
        open: false,
        ranges: {{ json_encode($court->priceRanges->map(fn($r) => [
            'time_from' => \Carbon\Carbon::parse($r->time_from)->format('H:i'),
            'time_to' => \Carbon\Carbon::parse($r->time_to)->format('H:i'),
            'price' => $r->price,
        ])->values()) }},
        addRange() {
            let lastTo = this.ranges.length ? this.ranges[this.ranges.length - 1].time_to : '08:00';
            this.ranges.push({ time_from: lastTo, time_to: '22:00', price: '' });
        },
        removeRange(index) {
            if (this.ranges.length > 1) this.ranges.splice(index, 1);
        }
    }" x-on:open-edit-{{ $court->id }}.window="open = true" @keydown.escape.window="open = false">
        <div class="modal-overlay" x-show="open" x-cloak x-transition.opacity @click.self="open = false">
            <div class="modal" @click.stop>
                <div class="modal-header">
                    <h2>Редактировать — {{ $court->name }}</h2>
                    <button type="button" class="modal-close" @click="open = false">&#10005;</button>
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
                            <textarea name="description" class="form-input form-textarea" placeholder="Покрытие, особенности (необязательно)">{{ $court->description }}</textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Начало работы</label>
                                <input type="time" name="open_time" class="form-input" value="{{ \Carbon\Carbon::parse($court->open_time)->format('H:i') }}" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Конец работы</label>
                                <input type="time" name="close_time" class="form-input" value="{{ \Carbon\Carbon::parse($court->close_time)->format('H:i') }}" required>
                            </div>
                        </div>

                        <hr class="section-divider">

                        <div class="section-title">Ценовые интервалы</div>
                        <div class="price-ranges">
                            <template x-for="(range, index) in ranges" :key="index">
                                <div class="price-range-row">
                                    <div class="form-group">
                                        <label class="form-label">С</label>
                                        <input type="time" class="form-input" x-model="range.time_from" :name="'price_ranges[' + index + '][time_from]'" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">До</label>
                                        <input type="time" class="form-input" x-model="range.time_to" :name="'price_ranges[' + index + '][time_to]'" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Цена (₸)</label>
                                        <input type="number" class="form-input" x-model="range.price" :name="'price_ranges[' + index + '][price]'" placeholder="0" required>
                                    </div>
                                    <button type="button" class="remove-btn" title="Удалить" @click="removeRange(index)" x-show="ranges.length > 1">&#10005;</button>
                                </div>
                            </template>
                        </div>
                        <button type="button" class="add-range-btn" @click="addRange()">+ Добавить интервал</button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" @click="open = false">Отмена</button>
                        <button type="submit" class="btn-save">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach

<style>
    :root {
        --courts-bg: #0a0a0b;
        --courts-card: #111113;
        --courts-card-alt: #16161a;
        --courts-accent: #22c55e;
        --courts-accent-dark: #16a34a;
        --courts-text: #f4f4f5;
        --courts-text-dim: #a1a1aa;
        --courts-text-muted: #71717a;
        --courts-text-dark: #52525b;
        --courts-border: #27272a;
        --courts-border-light: #3f3f46;
        --courts-blue: #3b82f6;
        --courts-yellow: #facc15;
        --courts-red: #ef4444;
    }

    .courts-container {
        max-width: 900px;
        margin: 0 auto;
        padding: 32px 24px;
    }

    /* Header */
    .courts-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 32px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .courts-header-left {
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
        background: var(--courts-card-alt);
        border: 1px solid var(--courts-border);
        border-radius: 10px;
        color: var(--courts-text-dim);
        text-decoration: none;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 20px;
    }

    .back-link:hover {
        border-color: var(--courts-accent);
        color: var(--courts-accent);
    }

    .courts-title {
        font-size: 24px;
        font-weight: 800;
        letter-spacing: -0.5px;
    }

    .btn-add {
        display: flex;
        align-items: center;
        gap: 8px;
        background: var(--courts-accent);
        color: var(--courts-bg);
        border: none;
        padding: 12px 22px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-add:hover {
        background: var(--courts-accent-dark);
        transform: translateY(-1px);
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

    /* Court Card */
    .court-card {
        background: var(--courts-card);
        border: 1px solid var(--courts-border);
        border-radius: 16px;
        margin-bottom: 16px;
        overflow: hidden;
        transition: border-color 0.2s;
    }

    .court-card:hover {
        border-color: var(--courts-border-light);
    }

    .court-card.court-inactive {
        opacity: 0.6;
    }

    .court-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px 24px;
    }

    .court-card-left {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .court-name {
        font-size: 18px;
        font-weight: 700;
    }

    .court-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 700;
    }

    .court-status.active {
        background: rgba(34, 197, 94, 0.15);
        color: var(--courts-accent);
    }

    .court-status.inactive {
        background: rgba(239, 68, 68, 0.15);
        color: var(--courts-red);
    }

    .court-card-actions {
        display: flex;
        gap: 8px;
    }

    .action-btn {
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--courts-card-alt);
        border: 1px solid var(--courts-border);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
        color: var(--courts-text-dim);
        font-size: 16px;
    }

    .action-btn:hover {
        border-color: var(--courts-border-light);
        color: var(--courts-text);
    }

    .action-btn.edit:hover { border-color: var(--courts-blue); color: var(--courts-blue); }
    .action-btn.toggle:hover { border-color: var(--courts-yellow); color: var(--courts-yellow); }
    .action-btn.delete:hover { border-color: var(--courts-red); color: var(--courts-red); }

    .court-card-details {
        padding: 0 24px 20px;
        display: flex;
        gap: 32px;
        flex-wrap: wrap;
    }

    .detail-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .detail-label {
        font-size: 11px;
        font-weight: 700;
        color: var(--courts-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .detail-value {
        font-size: 14px;
        font-weight: 600;
        color: var(--courts-text-dim);
    }

    /* Price tags */
    .price-tags {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .price-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        background: var(--courts-card-alt);
        border: 1px solid var(--courts-border);
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
    }

    .price-tag-time {
        color: var(--courts-text-muted);
    }

    .price-tag-value {
        color: var(--courts-accent);
        font-weight: 700;
    }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--courts-text-muted);
    }

    .empty-state p {
        font-size: 16px;
        margin-bottom: 20px;
    }

    [x-cloak] { display: none !important; }

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
        overflow-y: auto;
        padding: 40px 16px;
    }

    .modal {
        background: var(--courts-card);
        border: 1px solid var(--courts-border);
        border-radius: 16px;
        width: 100%;
        max-width: 520px;
    }

    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px 24px;
        border-bottom: 1px solid var(--courts-border);
    }

    .modal-header h2 {
        font-size: 18px;
        font-weight: 700;
    }

    .modal-close {
        width: 36px; height: 36px;
        display: flex; align-items: center; justify-content: center;
        background: var(--courts-card-alt);
        border: 1px solid var(--courts-border);
        border-radius: 8px;
        cursor: pointer;
        color: var(--courts-text-dim);
        font-size: 18px;
        transition: all 0.2s;
    }

    .modal-close:hover { border-color: var(--courts-red); color: var(--courts-red); }

    .modal-body {
        padding: 24px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group:last-child {
        margin-bottom: 0;
    }

    .form-label {
        display: block;
        font-size: 12px;
        font-weight: 700;
        color: var(--courts-text-dim);
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-input {
        width: 100%;
        background: var(--courts-card-alt);
        border: 1px solid var(--courts-border);
        border-radius: 10px;
        padding: 12px 16px;
        font-size: 15px;
        color: var(--courts-text);
        font-weight: 500;
        font-family: inherit;
    }

    .form-input:focus {
        outline: none;
        border-color: var(--courts-accent);
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
    }

    .form-input::placeholder { color: var(--courts-text-dark); }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .form-textarea {
        resize: vertical;
        min-height: 60px;
    }

    /* Section divider */
    .section-divider {
        border: none;
        border-top: 1px solid var(--courts-border);
        margin: 24px 0;
    }

    .section-title {
        font-size: 15px;
        font-weight: 700;
        color: var(--courts-text);
        margin-bottom: 16px;
    }

    /* Price range rows */
    .price-ranges {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .price-range-row {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr 40px;
        gap: 8px;
        align-items: end;
    }

    .price-range-row .form-group {
        margin-bottom: 0;
    }

    .price-range-row .form-label {
        font-size: 10px;
        margin-bottom: 6px;
    }

    .price-range-row .form-input {
        padding: 10px 12px;
        font-size: 14px;
    }

    .remove-btn {
        width: 40px;
        height: 42px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--courts-card-alt);
        border: 1px solid var(--courts-border);
        border-radius: 10px;
        cursor: pointer;
        color: var(--courts-text-muted);
        font-size: 18px;
        transition: all 0.2s;
    }

    .remove-btn:hover {
        border-color: var(--courts-red);
        color: var(--courts-red);
    }

    .add-range-btn {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 10px 16px;
        background: transparent;
        border: 1px dashed var(--courts-border-light);
        border-radius: 10px;
        color: var(--courts-text-muted);
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        margin-top: 4px;
    }

    .add-range-btn:hover {
        border-color: var(--courts-accent);
        color: var(--courts-accent);
    }

    /* Modal footer */
    .modal-footer {
        display: flex;
        gap: 12px;
        padding: 20px 24px;
        border-top: 1px solid var(--courts-border);
    }

    .btn-cancel {
        flex: 1;
        padding: 14px;
        background: var(--courts-card-alt);
        border: 1px solid var(--courts-border);
        border-radius: 10px;
        color: var(--courts-text-dim);
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-cancel:hover { border-color: var(--courts-border-light); color: var(--courts-text); }

    .btn-save {
        flex: 2;
        padding: 14px;
        background: var(--courts-accent);
        border: none;
        border-radius: 10px;
        color: var(--courts-bg);
        font-size: 14px;
        font-weight: 800;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-save:hover { background: var(--courts-accent-dark); }

    /* Responsive */
    @media (max-width: 768px) {
        .courts-header { flex-direction: column; align-items: flex-start; }
        .court-card-header { flex-direction: column; gap: 12px; align-items: flex-start; }
        .court-card-details { flex-direction: column; gap: 16px; }
        .price-range-row { grid-template-columns: 1fr 1fr; }
    }
</style>
@endsection

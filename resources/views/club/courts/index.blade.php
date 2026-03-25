@extends('layouts.app')
@section('title', 'Настройки кортов')
@section('content')

<div class="courts-container">
    <div class="courts-header">
        <div class="courts-header-left">
            <a href="{{ route('club.courts.schedule') }}" class="back-link" title="Назад к расписанию">&#8249;</a>
            <h1 class="courts-title">Настройки кортов</h1>
        </div>
        <button class="btn-add" data-bs-toggle="modal" data-bs-target="#createModal">+ Добавить корт</button>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

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
                    <button class="action-btn edit" title="Редактировать" data-bs-toggle="modal" data-bs-target="#editModal{{ $court->id }}">&#9998;</button>
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
            <button class="btn-add" data-bs-toggle="modal" data-bs-target="#createModal">+ Добавить корт</button>
        </div>
    @endforelse
</div>

<!-- Create Modal (Bootstrap) -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="modal-header" style="border-bottom: 1px solid #27272a; padding: 20px 24px;">
                <h5 class="modal-title" style="font-weight: 700;">Добавить корт</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('club.courts.store') }}" method="POST">
                @csrf
                <div class="modal-body" style="padding: 24px;">
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
                    <hr style="border-color: #27272a; margin: 24px 0;">
                    <div class="section-title">Ценовые интервалы</div>
                    <div class="price-ranges" id="createRanges">
                        <div class="price-range-row">
                            <div class="form-group">
                                <label class="form-label">С</label>
                                <input type="time" class="form-input" name="price_ranges[0][time_from]" value="08:00" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">До</label>
                                <input type="time" class="form-input" name="price_ranges[0][time_to]" value="22:00" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Цена (₸)</label>
                                <input type="number" class="form-input" name="price_ranges[0][price]" placeholder="5000" required>
                            </div>
                            <button type="button" class="remove-btn" onclick="removeRange(this)" style="display:none;">&#10005;</button>
                        </div>
                    </div>
                    <button type="button" class="add-range-btn" onclick="addRange('createRanges')">+ Добавить интервал</button>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #27272a; padding: 20px 24px;">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn-save">Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modals (Bootstrap) -->
@foreach($courts as $court)
<div class="modal fade" id="editModal{{ $court->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="modal-header" style="border-bottom: 1px solid #27272a; padding: 20px 24px;">
                <h5 class="modal-title" style="font-weight: 700;">Редактировать — {{ $court->name }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('club.courts.update', $court) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-body" style="padding: 24px;">
                    <div class="form-group">
                        <label class="form-label">Название</label>
                        <input type="text" name="name" class="form-input" value="{{ $court->name }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Описание</label>
                        <textarea name="description" class="form-input form-textarea">{{ $court->description }}</textarea>
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
                    <hr style="border-color: #27272a; margin: 24px 0;">
                    <div class="section-title">Ценовые интервалы</div>
                    <div class="price-ranges" id="editRanges{{ $court->id }}">
                        @foreach($court->priceRanges as $i => $range)
                        <div class="price-range-row">
                            <div class="form-group">
                                <label class="form-label">С</label>
                                <input type="time" class="form-input" name="price_ranges[{{ $i }}][time_from]" value="{{ \Carbon\Carbon::parse($range->time_from)->format('H:i') }}" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">До</label>
                                <input type="time" class="form-input" name="price_ranges[{{ $i }}][time_to]" value="{{ \Carbon\Carbon::parse($range->time_to)->format('H:i') }}" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Цена (₸)</label>
                                <input type="number" class="form-input" name="price_ranges[{{ $i }}][price]" value="{{ intval($range->price) }}" required>
                            </div>
                            <button type="button" class="remove-btn" onclick="removeRange(this)" {!! $court->priceRanges->count() <= 1 ? 'style="display:none;"' : '' !!}>&#10005;</button>
                        </div>
                        @endforeach
                    </div>
                    <button type="button" class="add-range-btn" onclick="addRange('editRanges{{ $court->id }}')">+ Добавить интервал</button>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #27272a; padding: 20px 24px;">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn-save">Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

<script>
function addRange(containerId) {
    var container = document.getElementById(containerId);
    var rows = container.querySelectorAll('.price-range-row');
    var idx = rows.length;
    var lastRow = rows[rows.length - 1];
    var lastTo = lastRow ? lastRow.querySelector('input[name*="time_to"]').value : '08:00';

    var div = document.createElement('div');
    div.className = 'price-range-row';
    div.innerHTML = '<div class="form-group"><label class="form-label">С</label><input type="time" class="form-input" name="price_ranges[' + idx + '][time_from]" value="' + lastTo + '" required></div>' +
        '<div class="form-group"><label class="form-label">До</label><input type="time" class="form-input" name="price_ranges[' + idx + '][time_to]" value="22:00" required></div>' +
        '<div class="form-group"><label class="form-label">Цена (₸)</label><input type="number" class="form-input" name="price_ranges[' + idx + '][price]" placeholder="5000" required></div>' +
        '<button type="button" class="remove-btn" onclick="removeRange(this)">&#10005;</button>';
    container.appendChild(div);
    updateRemoveButtons(container);
}

function removeRange(btn) {
    var row = btn.closest('.price-range-row');
    var container = row.parentElement;
    row.remove();
    // reindex
    container.querySelectorAll('.price-range-row').forEach(function(row, i) {
        row.querySelectorAll('input').forEach(function(input) {
            var name = input.getAttribute('name');
            if (name) input.setAttribute('name', name.replace(/price_ranges\[\d+\]/, 'price_ranges[' + i + ']'));
        });
    });
    updateRemoveButtons(container);
}

function updateRemoveButtons(container) {
    var rows = container.querySelectorAll('.price-range-row');
    rows.forEach(function(row) {
        var btn = row.querySelector('.remove-btn');
        if (btn) btn.style.display = rows.length > 1 ? 'flex' : 'none';
    });
}
</script>

<style>
    .courts-container { max-width: 900px; margin: 0 auto; padding: 32px 24px; }
    .courts-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; flex-wrap: wrap; gap: 16px; }
    .courts-header-left { display: flex; align-items: center; gap: 14px; }
    .back-link { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; text-decoration: none; font-size: 20px; transition: all 0.2s; }
    .back-link:hover { border-color: #22c55e; color: #22c55e; }
    .courts-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    .btn-add { display: flex; align-items: center; gap: 8px; background: #22c55e; color: #0a0a0b; border: none; padding: 12px 22px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
    .btn-add:hover { background: #16a34a; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 24px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .court-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; margin-bottom: 16px; overflow: hidden; }
    .court-card:hover { border-color: #3f3f46; }
    .court-card.court-inactive { opacity: 0.6; }
    .court-card-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; }
    .court-card-left { display: flex; align-items: center; gap: 16px; }
    .court-name { font-size: 18px; font-weight: 700; }
    .court-status { padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; }
    .court-status.active { background: rgba(34,197,94,0.15); color: #22c55e; }
    .court-status.inactive { background: rgba(239,68,68,0.15); color: #ef4444; }
    .court-card-actions { display: flex; gap: 8px; }
    .action-btn { width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 8px; cursor: pointer; color: #a1a1aa; font-size: 16px; transition: all 0.2s; }
    .action-btn.edit:hover { border-color: #3b82f6; color: #3b82f6; }
    .action-btn.toggle:hover { border-color: #facc15; color: #facc15; }
    .action-btn.delete:hover { border-color: #ef4444; color: #ef4444; }

    .court-card-details { padding: 0 24px 20px; display: flex; gap: 32px; flex-wrap: wrap; }
    .detail-group { display: flex; flex-direction: column; gap: 4px; }
    .detail-label { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; }
    .detail-value { font-size: 14px; font-weight: 600; color: #a1a1aa; }
    .price-tags { display: flex; gap: 8px; flex-wrap: wrap; }
    .price-tag { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #16161a; border: 1px solid #27272a; border-radius: 8px; font-size: 13px; font-weight: 600; }
    .price-tag-time { color: #71717a; }
    .price-tag-value { color: #22c55e; font-weight: 700; }
    .empty-state { text-align: center; padding: 60px 20px; color: #71717a; }
    .empty-state p { font-size: 16px; margin-bottom: 20px; }

    /* Form inside modals */
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-input::placeholder { color: #52525b; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-textarea { resize: vertical; min-height: 60px; }
    .section-title { font-size: 15px; font-weight: 700; color: #f4f4f5; margin-bottom: 16px; }
    .price-ranges { display: flex; flex-direction: column; gap: 10px; }
    .price-range-row { display: grid; grid-template-columns: 1fr 1fr 1fr 40px; gap: 8px; align-items: end; }
    .price-range-row .form-group { margin-bottom: 0; }
    .price-range-row .form-label { font-size: 10px; margin-bottom: 6px; }
    .price-range-row .form-input { padding: 10px 12px; font-size: 14px; }
    .remove-btn { width: 40px; height: 42px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 10px; cursor: pointer; color: #71717a; font-size: 18px; transition: all 0.2s; }
    .remove-btn:hover { border-color: #ef4444; color: #ef4444; }
    .add-range-btn { display: flex; align-items: center; gap: 6px; padding: 10px 16px; background: transparent; border: 1px dashed #3f3f46; border-radius: 10px; color: #71717a; font-size: 13px; font-weight: 600; cursor: pointer; margin-top: 4px; transition: all 0.2s; }
    .add-range-btn:hover { border-color: #22c55e; color: #22c55e; }
    .btn-cancel { flex: 1; padding: 14px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }

    @media (max-width: 768px) {
        .courts-header { flex-direction: column; align-items: flex-start; }
        .court-card-header { flex-direction: column; gap: 12px; align-items: flex-start; }
        .court-card-details { flex-direction: column; gap: 16px; }
        .price-range-row { grid-template-columns: 1fr 1fr; }
    }
</style>
@endsection

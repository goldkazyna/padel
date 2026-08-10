@extends('layouts.app')

@section('title', 'Чек-листы смены')

@section('content')
<div class="page-header">
    <div>
        <h2>Чек-листы смены</h2>
        <p>{{ $club->name }} · что менеджер проверяет при открытии и закрытии</p>
    </div>
    <a href="{{ route('club.shifts.index') }}" class="btn-outline-custom">
        <i class="bi bi-journal-text"></i> Журнал смен
    </a>
</div>

<div class="chk-note mb-4">
    <i class="bi bi-info-circle me-2"></i>
    Менеджер обязан отметить все пункты, иначе смена не откроется. Галочка
    означает «проверил»: если что-то не в порядке, он отмечает пункт и пишет
    замечание — оно попадёт в журнал.
</div>

<div class="chk-grid">
    @foreach([['opening', 'Открытие смены', $opening], ['closing', 'Закрытие смены', $closing]] as [$type, $title, $items])
        <div class="chk-col">
            <div class="section-subheader">
                <i class="bi {{ $type === 'opening' ? 'bi-sunrise' : 'bi-moon-stars' }}"></i> {{ $title }}
            </div>

            <form method="POST" action="{{ route('club.shiftChecklists.store') }}" class="chk-add">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">
                <input type="text" name="title" class="form-control" maxlength="500"
                       placeholder="Новый пункт, например: проверить корты" required>
                <button type="submit" class="btn-primary-custom">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </form>

            @php $active = $items->where('is_active', true); @endphp

            @if($active->isEmpty())
                <div class="chk-empty">Пунктов пока нет — менеджера ничего не задержит на входе.</div>
            @endif

            @foreach($active as $item)
                <div class="chk-item">
                    <form method="POST" action="{{ route('club.shiftChecklists.update', $item) }}" class="chk-row">
                        @csrf
                        @method('PUT')
                        <input type="number" name="sort_order" class="form-control chk-order"
                               value="{{ $item->sort_order }}" min="0" max="999" title="Порядок">
                        <input type="text" name="title" class="form-control" value="{{ $item->title }}" maxlength="500">
                        <button type="submit" class="btn-outline-custom" title="Сохранить">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    </form>
                    <form method="POST" action="{{ route('club.shiftChecklists.destroy', $item) }}"
                          onsubmit="return confirm('Убрать пункт из чек-листа? В прошлых сменах он останется.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-outline-custom chk-del" title="Убрать">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </form>
                </div>
            @endforeach

            @php $disabled = $items->where('is_active', false); @endphp
            @if($disabled->isNotEmpty())
                <div class="chk-disabled-title">Убранные пункты</div>
                @foreach($disabled as $item)
                    <div class="chk-item chk-off">
                        <span class="chk-off-title">{{ $item->title }}</span>
                        <form method="POST" action="{{ route('club.shiftChecklists.restore', $item) }}">
                            @csrf
                            <button type="submit" class="btn-outline-custom" title="Вернуть">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                        </form>
                    </div>
                @endforeach
            @endif
        </div>
    @endforeach
</div>

<style>
.chk-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.chk-col { min-width: 0; }
.chk-add { display: flex; gap: 8px; margin-bottom: 12px; }
.chk-item { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.chk-row { display: flex; gap: 8px; flex: 1; margin: 0; }
.chk-order { width: 68px; flex: 0 0 68px; text-align: center; }
.chk-del { color: var(--text-secondary); }
.chk-empty, .chk-disabled-title { color: var(--text-secondary); font-size: 0.9rem; margin: 8px 0; }
.chk-off { opacity: 0.55; }
.chk-off-title { flex: 1; color: var(--text-secondary); }
.chk-note {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-left: 3px solid var(--accent);
    border-radius: 10px;
    padding: 12px 16px;
    color: var(--text-secondary);
}
@media (max-width: 900px) {
    .chk-grid { grid-template-columns: 1fr; }
}
</style>
@endsection

@extends('layouts.app')

@section('title', $type === 'opening' ? 'Открытие смены' : 'Закрытие смены')

@section('content')
@php
    $isOpening = $type === 'opening';
@endphp

<div class="page-header">
    <div>
        <h2>{{ $isOpening ? 'Открытие смены' : 'Закрытие смены' }}</h2>
        <p>
            {{ $club->name }} ·
            {{ $isOpening
                ? 'Пройдите проверку перед началом работы'
                : 'Пройдите проверку перед уходом' }}
        </p>
    </div>
</div>

@if(session('error'))
    <div class="shift-alert mb-4">
        <i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}
    </div>
@endif

@if(!$isOpening && isset($shift) && $shift->isStale())
    <div class="shift-alert mb-4">
        <i class="bi bi-clock-history me-2"></i>
        Смена от {{ $shift->opened_at->format('d.m.Y, H:i') }} осталась незакрытой.
        Закройте её, чтобы начать новую.
    </div>
@endif

@if($items->isEmpty())
    <div class="shift-note mb-4">
        Для этого чек-листа пока нет пунктов — обратитесь к администратору клуба.
    </div>
@endif

<form method="POST" action="{{ $isOpening ? route('club.shift.open') : route('club.shift.close') }}">
    @csrf

    <div class="shift-hint mb-3">
        <i class="bi bi-info-circle me-2"></i>
        Отметьте каждый пункт — галочка означает «проверил». Если что-то не в
        порядке, всё равно отметьте и напишите замечание: администратор увидит его в журнале.
    </div>

    <div class="shift-list">
        @foreach($items as $item)
            @php
                $old = old('items.' . $item->id, []);
                $checked = !empty($old['done']);
            @endphp
            <div class="shift-item">
                <label class="shift-item-head">
                    <input type="hidden" name="items[{{ $item->id }}][done]" value="0">
                    <input type="checkbox" name="items[{{ $item->id }}][done]" value="1"
                           class="form-check-input shift-check" {{ $checked ? 'checked' : '' }}>
                    <span class="shift-item-title">{{ $item->title }}</span>
                </label>
                <input type="text" name="items[{{ $item->id }}][comment]"
                       class="form-control shift-comment"
                       maxlength="1000"
                       value="{{ $old['comment'] ?? '' }}"
                       placeholder="Замечание, если есть">
            </div>
        @endforeach
    </div>

    <div class="d-flex gap-3 mt-4 flex-wrap">
        <button type="submit" class="btn-primary-custom">
            <i class="bi {{ $isOpening ? 'bi-play-fill' : 'bi-box-arrow-right' }}"></i>
            {{ $isOpening ? 'Открыть смену' : 'Закрыть смену и выйти' }}
        </button>
        @if(!$isOpening)
            <a href="{{ route('club.dashboard') }}" class="btn-outline-custom">
                <i class="bi bi-arrow-left"></i> Вернуться к работе
            </a>
        @endif
    </div>
</form>

<style>
.shift-list { display: flex; flex-direction: column; gap: 10px; }
.shift-item {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 16px;
}
.shift-item-head {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    cursor: pointer;
    user-select: none;
    margin-bottom: 8px;
}
.shift-check { width: 22px; height: 22px; margin-top: 1px; flex-shrink: 0; }
.shift-item-title { color: var(--text-primary); font-size: 1.05rem; line-height: 1.35; }
.shift-comment { max-width: 520px; }

.shift-hint, .shift-note {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-left: 3px solid var(--accent);
    border-radius: 10px;
    padding: 12px 16px;
    color: var(--text-secondary);
}
.shift-alert {
    background: rgba(245, 158, 11, 0.12);
    border: 1px solid #f59e0b;
    border-radius: 10px;
    padding: 12px 16px;
    color: #f59e0b;
}
</style>
@endsection

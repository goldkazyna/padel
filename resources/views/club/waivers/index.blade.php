@extends('layouts.app')

@section('title', 'Отказы от ответственности')

@section('content')
<div class="page-header">
    <div>
        <h2>Отказы от ответственности</h2>
        <p>Кто подписал и что именно</p>
    </div>
</div>

<form method="GET" class="mb-4" style="max-width:420px">
    <input type="text" name="q" value="{{ $search }}" class="form-control"
           placeholder="Поиск по имени или телефону">
</form>

<div class="waivers-list">
    @forelse($signatures as $signature)
        <div class="waiver-row">
            <div class="waiver-info">
                <div class="waiver-name">{{ $signature->full_name }}</div>
                <small class="text-secondary">@phoneFmt($signature->phone)</small>
            </div>
            <div class="waiver-date">{{ $signature->signed_at->translatedFormat('j F Y, H:i') }}</div>
            <a class="btn-outline-custom btn-sm" data-bs-toggle="collapse"
               href="#waiver{{ $signature->id }}" role="button">
                <i class="bi bi-eye"></i> Посмотреть
            </a>
        </div>
        <div class="collapse" id="waiver{{ $signature->id }}">
            <div class="waiver-detail">
                <img src="{{ route('club.waivers.image', $signature) }}" alt="Подпись" class="waiver-sign">
                <pre class="waiver-text">{{ $signature->waiver_text }}</pre>
            </div>
        </div>
    @empty
        <div class="card-dark">
            <div class="card-body text-center py-5">
                <i class="bi bi-pencil-square fs-1 text-secondary mb-3 d-block"></i>
                <p class="text-secondary mb-0">Пока никто не подписал</p>
            </div>
        </div>
    @endforelse
</div>

<div class="mt-3">{{ $signatures->links() }}</div>

<style>
.waivers-list { display: flex; flex-direction: column; gap: 8px; }
.waiver-row {
    display: flex; align-items: center; gap: 16px;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 12px; padding: 14px 18px;
}
.waiver-info { flex: 1; min-width: 0; }
.waiver-name { font-weight: 600; color: var(--text-primary); }
.waiver-date { color: var(--text-secondary); font-size: 13px; white-space: nowrap; }
.waiver-detail {
    background: var(--bg-secondary); border: 1px solid var(--border);
    border-radius: 12px; padding: 16px; margin: 4px 0 8px;
}
.waiver-sign {
    background: #fff; border-radius: 8px; padding: 8px;
    max-width: 320px; width: 100%; display: block; margin-bottom: 14px;
}
.waiver-text {
    color: var(--text-secondary); font-size: 13px; line-height: 1.55;
    white-space: pre-wrap; word-break: break-word; margin: 0;
    font-family: inherit;
}
@media (max-width: 576px) {
    .waiver-row { flex-wrap: wrap; gap: 10px; }
    .waiver-date { width: 100%; order: 3; }
}
</style>
@endsection

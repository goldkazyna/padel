@extends('layouts.app')
@section('title', 'Дубликаты клиентов')
@section('content')

<div class="dup-container">
    <header class="dup-header">
        <div class="dup-title-block">
            <a href="{{ route('club.clients.index') }}" class="dup-back">
                <i class="bi bi-arrow-left"></i>
            </a>
            <i class="bi bi-people-fill"></i>
            <h1 class="dup-title">Дубликаты клиентов</h1>
            @if($totalGroups > 0)
                <span class="dup-count">{{ $totalGroups }} {{ trans_choice('группа|группы|групп', $totalGroups) }} · {{ $totalDuplicates }} лишних</span>
            @endif
        </div>
    </header>

    @if(session('success'))
        <div class="alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert-error">{{ session('error') }}</div>
    @endif

    @if($groups->isEmpty())
        <div class="dup-empty">
            <i class="bi bi-check2-circle"></i>
            <p>Дубликатов по номеру телефона не найдено</p>
        </div>
    @else
        <div class="dup-info">
            <i class="bi bi-info-circle"></i>
            <span>Каждая группа — клиенты с одинаковым нормализованным номером телефона. По умолчанию оставляем самого старого. Его пустые поля заполнятся из дубликатов, брони перепривяжутся на него.</span>
        </div>

        @foreach($groups as $idx => $group)
            <form action="{{ route('club.clients.duplicates.merge') }}" method="POST" class="dup-group"
                  onsubmit="return confirm('Объединить {{ $group->count() }} клиентов? Дубликаты будут удалены.')">
                @csrf
                <div class="dup-group-header">
                    <div class="dup-group-phone">
                        <i class="bi bi-telephone"></i>
                        {{ '+' . preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $group->first()->phone) }}
                    </div>
                    <div class="dup-group-count">{{ $group->count() }} клиента</div>
                </div>
                <div class="dup-cards">
                    @foreach($group as $i => $client)
                        <label class="dup-card {{ $i === 0 ? 'dup-card-keep' : '' }}">
                            <input type="radio" name="keep_id" value="{{ $client->id }}" {{ $i === 0 ? 'checked' : '' }}>
                            <input type="hidden" name="merge_ids[]" value="{{ $client->id }}">
                            <div class="dup-card-avatar">{{ mb_strtoupper(mb_substr($client->name, 0, 1)) }}</div>
                            <div class="dup-card-info">
                                <div class="dup-card-name">{{ $client->name }}</div>
                                <div class="dup-card-meta">
                                    @if($client->gender)
                                        <span class="dup-card-tag">{{ $client->gender === 'male' ? 'М' : 'Ж' }}</span>
                                    @endif
                                    @if($client->birth_date)
                                        <span class="dup-card-tag">{{ $client->birth_date->format('d.m.Y') }}</span>
                                    @endif
                                    <span class="dup-card-date">Добавлен {{ $client->created_at->format('d.m.Y') }}</span>
                                </div>
                                @if($client->note)
                                    <div class="dup-card-note">{{ Str::limit($client->note, 80) }}</div>
                                @endif
                            </div>
                            <div class="dup-card-mark">
                                <span class="dup-mark-keep"><i class="bi bi-check-circle-fill"></i> Оставить</span>
                                <span class="dup-mark-merge"><i class="bi bi-trash3"></i> Удалить</span>
                            </div>
                        </label>
                    @endforeach
                </div>
                <div class="dup-actions">
                    <button type="submit" class="btn-merge">
                        <i class="bi bi-arrows-collapse"></i>
                        Объединить
                    </button>
                </div>
            </form>
        @endforeach
    @endif
</div>

<script>
// При смене radio "оставить" — выставляем merge_ids: остаются все, кроме выбранного.
// Но т.к. бэкенд сам игнорирует keep_id среди merge_ids, ничего менять не надо —
// просто обновляем визуал.
document.querySelectorAll('.dup-group').forEach(function(form) {
    form.querySelectorAll('input[type="radio"][name="keep_id"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            form.querySelectorAll('.dup-card').forEach(function(card) {
                card.classList.remove('dup-card-keep');
            });
            radio.closest('.dup-card').classList.add('dup-card-keep');
        });
    });
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
    --cl-orange: #f97316;
    --cl-text: #f4f4f5;
    --cl-text-dim: #a1a1aa;
    --cl-text-muted: #71717a;
    --cl-border: #27272a;
    --cl-border-light: #3f3f46;
    --cl-red: #ef4444;
}
.dup-container {
    width: 100%;
    padding: 32px 40px;
    max-width: 1100px;
    margin: 0 auto;
}
.dup-header {
    display: flex;
    align-items: center;
    margin-bottom: 24px;
}
.dup-title-block {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.dup-back {
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
    background: var(--cl-card);
    border: 1px solid var(--cl-border);
    border-radius: 10px;
    color: var(--cl-text-dim);
    text-decoration: none;
    transition: all 0.2s;
}
.dup-back:hover { border-color: var(--cl-border-light); color: var(--cl-text); }
.dup-title-block > i {
    font-size: 24px;
    color: var(--cl-orange);
}
.dup-title {
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.5px;
}
.dup-count {
    background: rgba(249, 115, 22, 0.15);
    color: var(--cl-orange);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}
.alert-success, .alert-error {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 20px;
}
.alert-success {
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    color: var(--cl-accent);
}
.alert-error {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: var(--cl-red);
}
.dup-empty {
    background: var(--cl-bg-secondary);
    border: 1px solid var(--cl-border);
    border-radius: 16px;
    padding: 80px 20px;
    text-align: center;
    color: var(--cl-text-muted);
}
.dup-empty i {
    font-size: 56px;
    color: var(--cl-accent);
    margin-bottom: 16px;
    display: block;
}
.dup-empty p { font-size: 16px; }
.dup-info {
    background: rgba(59, 130, 246, 0.08);
    border: 1px solid rgba(59, 130, 246, 0.3);
    color: var(--cl-text-dim);
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.dup-info i { color: var(--cl-blue); font-size: 16px; flex-shrink: 0; margin-top: 1px; }

.dup-group {
    background: var(--cl-bg-secondary);
    border: 1px solid var(--cl-border);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 20px;
}
.dup-group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--cl-border);
}
.dup-group-phone {
    font-size: 16px;
    font-weight: 700;
    color: var(--cl-text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.dup-group-phone i { color: var(--cl-accent); }
.dup-group-count {
    font-size: 12px;
    color: var(--cl-text-muted);
    background: var(--cl-card);
    padding: 4px 10px;
    border-radius: 12px;
    font-weight: 600;
}
.dup-cards {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.dup-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: var(--cl-card);
    border: 1.5px solid var(--cl-border);
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.15s;
}
.dup-card:hover { border-color: var(--cl-border-light); }
.dup-card input[type="radio"] { display: none; }
.dup-card-avatar {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--cl-text-muted), var(--cl-border-light));
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; font-weight: 700;
    color: var(--cl-bg);
    flex-shrink: 0;
}
.dup-card-info { flex: 1; min-width: 0; }
.dup-card-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--cl-text);
    margin-bottom: 4px;
}
.dup-card-meta {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
}
.dup-card-tag {
    background: var(--cl-bg-secondary);
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 11px;
    color: var(--cl-text-dim);
    font-weight: 600;
}
.dup-card-date {
    font-size: 12px;
    color: var(--cl-text-muted);
}
.dup-card-note {
    margin-top: 6px;
    font-size: 12px;
    color: var(--cl-text-muted);
    font-style: italic;
}
.dup-card-mark {
    flex-shrink: 0;
}
.dup-mark-keep, .dup-mark-merge {
    display: none;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
}
.dup-card-keep {
    border-color: var(--cl-accent);
    background: rgba(34, 197, 94, 0.05);
}
.dup-card-keep .dup-card-avatar {
    background: linear-gradient(135deg, var(--cl-accent), var(--cl-accent-dark));
    color: var(--cl-bg);
}
.dup-card-keep .dup-mark-keep {
    display: inline-flex;
    background: rgba(34, 197, 94, 0.15);
    color: var(--cl-accent);
}
.dup-card:not(.dup-card-keep) .dup-mark-merge {
    display: inline-flex;
    background: rgba(239, 68, 68, 0.1);
    color: var(--cl-red);
}
.dup-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
}
.btn-merge {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--cl-accent);
    color: var(--cl-bg);
    border: none;
    padding: 11px 22px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-merge:hover {
    background: var(--cl-accent-dark);
    transform: translateY(-1px);
}

@media (max-width: 700px) {
    .dup-container { padding: 24px 16px; }
    .dup-card-mark { display: none; }
}
</style>
@endsection

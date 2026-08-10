@extends('layouts.app')

@section('title', 'Журнал смен')

@section('content')
<div class="page-header">
    <div>
        <h2>Журнал смен</h2>
        <p>{{ $club->name }} · кто работал и что заметил</p>
    </div>
    <a href="{{ route('club.shiftChecklists.index') }}" class="btn-outline-custom">
        <i class="bi bi-list-check"></i> Чек-листы
    </a>
</div>

<form method="GET" class="jr-filter mb-4">
    <input type="date" name="from" value="{{ $from }}" class="form-control">
    <span class="jr-dash">—</span>
    <input type="date" name="to" value="{{ $to }}" class="form-control">
    <button type="submit" class="btn-primary-custom">Показать</button>
</form>

@if($comments->isNotEmpty())
    <div class="section-subheader"><i class="bi bi-exclamation-triangle"></i> Замечания</div>
    <div class="jr-comments mb-4">
        @foreach($comments as $c)
            <div class="jr-comment">
                <div class="jr-comment-head">
                    <span class="jr-who">{{ $c->shift?->user?->name ?? '—' }}</span>
                    <span class="jr-when">{{ $c->created_at->format('d.m.Y, H:i') }}</span>
                    <span class="jr-type">{{ $c->type === 'opening' ? 'открытие' : 'закрытие' }}</span>
                </div>
                <div class="jr-item-title">{{ $c->title_snapshot }}</div>
                <div class="jr-comment-text">{{ $c->comment }}</div>
            </div>
        @endforeach
    </div>
@endif

<div class="section-subheader"><i class="bi bi-clock-history"></i> Смены</div>

@if($shifts->isEmpty())
    <div class="jr-empty">За выбранный период смен не было.</div>
@else
    <div class="leaderboard-table-wrapper">
        <table class="leaderboard-table">
            <thead>
                <tr>
                    <th>Менеджер</th>
                    <th>Открыл</th>
                    <th>Закрыл</th>
                    <th>Длительность</th>
                    <th>Отмечено</th>
                    <th>Замечаний</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($shifts as $shift)
                    @php
                        $done = $shift->results->where('is_done', true)->count();
                        $total = $shift->results->count();
                        $withComment = $shift->results->filter(fn ($r) => filled($r->comment))->count();
                        $minutes = $shift->durationMinutes();
                    @endphp
                    <tr>
                        <td>{{ $shift->user?->name ?? '—' }}</td>
                        <td>{{ $shift->opened_at->format('d.m.Y, H:i') }}</td>
                        <td>
                            @if($shift->closed_at)
                                {{ $shift->closed_at->format('d.m.Y, H:i') }}
                            @else
                                <span class="jr-open">смена идёт</span>
                            @endif
                        </td>
                        <td>
                            @if($minutes !== null)
                                {{ intdiv($minutes, 60) }} ч {{ $minutes % 60 }} мин
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $done }} из {{ $total }}</td>
                        <td>
                            @if($withComment > 0)
                                <span class="jr-badge">{{ $withComment }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('club.shifts.show', $shift) }}" class="btn-outline-custom">
                                Открыть
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<style>
.jr-filter { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.jr-filter .form-control { max-width: 180px; }
.jr-dash { color: var(--text-secondary); }
.jr-comments { display: flex; flex-direction: column; gap: 10px; }
.jr-comment {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-left: 3px solid #f59e0b;
    border-radius: 10px;
    padding: 12px 16px;
}
.jr-comment-head { display: flex; gap: 12px; align-items: center; margin-bottom: 4px; }
.jr-who { color: var(--text-primary); font-weight: 600; }
.jr-when, .jr-type { color: var(--text-secondary); font-size: 0.85rem; }
.jr-item-title { color: var(--text-secondary); font-size: 0.9rem; }
.jr-comment-text { color: var(--text-primary); margin-top: 2px; }
.jr-open { color: var(--accent); }
.jr-badge {
    display: inline-block;
    min-width: 22px;
    padding: 2px 8px;
    border-radius: 6px;
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
    font-weight: 700;
}
.jr-empty { color: var(--text-secondary); }
</style>
@endsection

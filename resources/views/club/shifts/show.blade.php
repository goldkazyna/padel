@extends('layouts.app')

@section('title', 'Смена')

@section('content')
<div class="page-header">
    <div>
        <h2>Смена {{ $shift->openedAtLocal()->format('d.m.Y') }}</h2>
        <p>
            {{ $shift->user?->name ?? '—' }} ·
            {{ $shift->openedAtLocal()->format('H:i') }}
            @if($shift->closed_at)
                — {{ $shift->closedAtLocal()->format('H:i') }}
                @php $m = $shift->durationMinutes(); @endphp
                ({{ intdiv($m, 60) }} ч {{ $m % 60 }} мин)
            @else
                — смена идёт
            @endif
        </p>
    </div>
    <a href="{{ route('club.shifts.index') }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i> К журналу
    </a>
</div>

@foreach([['Открытие', $opening, 'bi-sunrise'], ['Закрытие', $closing, 'bi-moon-stars']] as [$title, $rows, $icon])
    <div class="section-subheader"><i class="bi {{ $icon }}"></i> {{ $title }}</div>

    @if($rows->isEmpty())
        <div class="sh-empty mb-4">Чек-лист не пройден.</div>
    @else
        <div class="sh-list mb-4">
            @foreach($rows as $row)
                <div class="sh-row {{ filled($row->comment) ? 'has-comment' : '' }}">
                    <i class="bi {{ $row->is_done ? 'bi-check-circle-fill sh-ok' : 'bi-circle sh-miss' }}"></i>
                    <div class="sh-body">
                        <div class="sh-title">{{ $row->title_snapshot }}</div>
                        @if(filled($row->comment))
                            <div class="sh-comment">{{ $row->comment }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endforeach

<style>
.sh-list { display: flex; flex-direction: column; gap: 8px; }
.sh-row {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 16px;
}
.sh-row.has-comment { border-left: 3px solid #f59e0b; }
.sh-ok { color: var(--accent); }
.sh-miss { color: var(--text-secondary); }
.sh-body { flex: 1; }
.sh-title { color: var(--text-primary); }
.sh-comment { color: #f59e0b; font-size: 0.92rem; margin-top: 4px; }
.sh-empty { color: var(--text-secondary); }
</style>
@endsection

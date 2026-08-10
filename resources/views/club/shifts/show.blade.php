@extends('layouts.app')

@section('title', 'Смена')

@section('content')
@php
    $minutes = $shift->durationMinutes();
    $name = $shift->user?->name ?? '—';
    $parts = preg_split('/\s+/', trim($name));
    $initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
    $allRows = $shift->results;
    $doneCount = $allRows->where('is_done', true)->count();
    $commentCount = $allRows->filter(fn ($r) => filled($r->comment))->count();
@endphp

<div class="page-header">
    <div>
        <h2>Смена {{ $shift->openedAtLocal()->format('d.m.Y') }}</h2>
        <p>{{ $club->name }} · подробности прохождения чек-листов</p>
    </div>
    <a href="{{ route('club.shifts.index') }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i> К журналу
    </a>
</div>

<div class="sh-wrap">
    {{-- Шапка смены --}}
    <div class="sh-head">
        <div class="sh-ava">{{ $initials !== '' ? $initials : '—' }}</div>
        <div class="sh-who">
            <div class="sh-name">
                {{ $name }}
                @if($shift->isOpen())
                    <span class="sh-live">смена идёт</span>
                @endif
            </div>
            <div class="sh-when">
                {{ $shift->openedAtLocal()->format('H:i') }}
                @if($shift->closed_at)
                    — {{ $shift->closedAtLocal()->format('H:i') }}
                @endif
            </div>
        </div>
        <div class="sh-facts">
            <div class="sh-fact">
                <b>@if($minutes !== null){{ intdiv($minutes, 60) }} ч {{ $minutes % 60 }} мин @else — @endif</b>
                <span>длительность</span>
            </div>
            <div class="sh-fact">
                <b>{{ $doneCount }}/{{ $allRows->count() }}</b>
                <span>отмечено</span>
            </div>
            <div class="sh-fact {{ $commentCount > 0 ? 'warn' : '' }}">
                <b>{{ $commentCount }}</b>
                <span>замечаний</span>
            </div>
        </div>
    </div>

    @foreach([['Открытие смены', $opening, 'bi-sunrise'], ['Закрытие смены', $closing, 'bi-moon-stars']] as [$title, $rows, $icon])
        <div class="sh-section">
            <i class="bi {{ $icon }}"></i> {{ $title }}
            @if($rows->isNotEmpty())
                <span class="sh-section-count">{{ $rows->where('is_done', true)->count() }}/{{ $rows->count() }}</span>
            @endif
        </div>

        @if($rows->isEmpty())
            <div class="sh-empty">
                <i class="bi bi-dash-circle"></i>
                <div>
                    <b>Чек-лист не пройден</b>
                    <span>{{ $shift->isOpen() ? 'Смена ещё не закрыта.' : 'Пунктов для этого чек-листа не было.' }}</span>
                </div>
            </div>
        @else
            <div class="sh-list">
                @foreach($rows as $row)
                    <div class="sh-row {{ filled($row->comment) ? 'has-comment' : '' }}">
                        <i class="bi {{ $row->is_done ? 'bi-check-circle-fill sh-ok' : 'bi-circle sh-miss' }}"></i>
                        <div class="sh-body">
                            <div class="sh-title">{{ $row->title_snapshot }}</div>
                            @if(filled($row->comment))
                                <div class="sh-comment">
                                    <i class="bi bi-chat-left-quote"></i>
                                    <span>{{ $row->comment }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endforeach
</div>

<style>
.sh-wrap { max-width: 900px; }

/* ---- шапка ---- */
.sh-head {
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 20px 22px;
    margin-bottom: 26px;
}
.sh-ava {
    width: 50px; height: 50px; flex-shrink: 0;
    border-radius: 14px;
    display: grid; place-items: center;
    background: var(--accent); color: #000;
    font-weight: 700; font-size: 1.05rem;
}
.sh-who { flex: 1; min-width: 150px; }
.sh-name {
    color: var(--text-primary); font-weight: 600; font-size: 1.1rem;
    display: flex; align-items: center; gap: 9px; flex-wrap: wrap;
}
.sh-live {
    background: var(--accent); color: #000;
    border-radius: 6px; padding: 1px 8px;
    font-size: .72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em;
}
.sh-when { color: var(--text-secondary); font-size: .89rem; margin-top: 3px; }
.sh-facts { display: flex; gap: 26px; }
.sh-fact { text-align: right; }
.sh-fact b { display: block; color: var(--text-primary); font-size: 1.05rem; white-space: nowrap; }
.sh-fact span { font-size: .77rem; color: var(--text-secondary); }
.sh-fact.warn b { color: #f59e0b; }

/* ---- секции ---- */
.sh-section {
    display: flex; align-items: center; gap: 9px;
    color: var(--text-secondary);
    font-size: .8rem; font-weight: 600;
    letter-spacing: .09em; text-transform: uppercase;
    margin: 0 0 13px;
}
.sh-section-count {
    background: var(--accent-glow); color: var(--accent);
    border-radius: 6px; padding: 1px 8px;
    font-size: .8rem; letter-spacing: 0;
}

/* ---- пункты ---- */
.sh-list { display: flex; flex-direction: column; gap: 9px; margin-bottom: 28px; }
.sh-row {
    display: flex; gap: 13px; align-items: flex-start;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 18px;
}
.sh-row.has-comment { border-left: 3px solid #f59e0b; }
.sh-row > i { font-size: 1.15rem; margin-top: 1px; }
.sh-ok { color: var(--accent); }
.sh-miss { color: var(--text-secondary); opacity: .5; }
.sh-body { flex: 1; min-width: 0; }
.sh-title { color: var(--text-primary); line-height: 1.45; }
.sh-comment {
    display: flex; gap: 8px; align-items: flex-start;
    color: #f59e0b; font-size: .91rem; line-height: 1.45;
    margin-top: 8px;
    background: rgba(245, 158, 11, .08);
    border-radius: 9px;
    padding: 9px 12px;
}
.sh-comment i { flex-shrink: 0; margin-top: 2px; font-size: .85rem; }

/* ---- пусто ---- */
.sh-empty {
    display: flex; align-items: center; gap: 14px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px 22px;
    margin-bottom: 28px;
    color: var(--text-secondary);
}
.sh-empty i { font-size: 1.5rem; color: var(--border-light); }
.sh-empty b { display: block; color: var(--text-primary); font-size: .96rem; margin-bottom: 2px; }
.sh-empty span { font-size: .87rem; }

@media (max-width: 700px) {
    .sh-facts { width: 100%; justify-content: space-between; gap: 12px; }
    .sh-fact { text-align: left; }
}
</style>
@endsection

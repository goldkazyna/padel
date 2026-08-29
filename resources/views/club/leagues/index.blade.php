@extends('layouts.app')

@section('title', 'Лиги')

@section('content')
<div class="page-header">
    <div>
        <h2>Лиги {{ $club ? '— ' . $club->name : '' }}</h2>
        <p>Серия турниров с общей таблицей</p>
    </div>
    <a href="{{ route('club.leagues.create') }}" class="btn-primary-custom">
        <i class="bi bi-plus-circle"></i>
        <span>Создать лигу</span>
    </a>
</div>

@if($leagues->isEmpty())
    <div class="card-dark">
        <div class="card-body text-center py-5">
            <i class="bi bi-collection fs-1 text-secondary mb-3"></i>
            <p class="text-secondary mb-2">Лиг пока нет</p>
            <p class="text-secondary small mb-3">
                Лига — это несколько турниров подряд с общим составом и одной таблицей.
                Например, «Сентябрь Кап» из восьми этапов Americano Flex.
            </p>
            <a href="{{ route('club.leagues.create') }}" class="btn-primary-custom">
                <i class="bi bi-plus-circle"></i> Создать первую лигу
            </a>
        </div>
    </div>
@else
    <div class="leagues-grid">
        @foreach($leagues as $league)
            @php
                $done = $league->finishedStagesCount();
                $total = max($league->stages_planned, $league->stages_count);
                $percent = $total > 0 ? round($done / $total * 100) : 0;
            @endphp
            <a href="{{ route('club.leagues.show', $league) }}" class="league-card">
                <div class="league-card-top">
                    <div>
                        <div class="league-card-name">{{ $league->name }}</div>
                        <div class="league-card-meta">
                            @if($league->start_date)
                                {{ $league->start_date->locale('ru')->translatedFormat('j M') }}
                                @if($league->end_date)
                                    — {{ $league->end_date->locale('ru')->translatedFormat('j M Y') }}
                                @endif
                                ·
                            @endif
                            {{ $league->players_count }} {{ trans_choice('участник|участника|участников', $league->players_count) }}
                        </div>
                    </div>
                    <span class="league-status league-status-{{ $league->status }}">{{ $league->status_name }}</span>
                </div>

                <div class="league-progress">
                    <div class="league-progress-bar"><span style="width: {{ $percent }}%"></span></div>
                    <div class="league-progress-text">Этапов сыграно: {{ $done }} из {{ $total }}</div>
                </div>
            </a>
        @endforeach
    </div>
@endif

<style>
.leagues-grid { display: grid; gap: 12px; }
.league-card {
    display: block; text-decoration: none; color: inherit;
    background: var(--card-bg, #16161a); border: 1px solid rgba(255,255,255,.06);
    border-radius: 14px; padding: 16px 18px; transition: border-color .2s;
}
.league-card:hover { border-color: rgba(34,197,94,.35); }
.league-card-top { display: flex; align-items: flex-start; gap: 12px; }
.league-card-name { font-size: 16px; font-weight: 700; color: #fff; }
.league-card-meta { font-size: 12.5px; color: var(--text-secondary); margin-top: 2px; }
.league-status {
    margin-left: auto; font-size: 11px; font-weight: 700; padding: 4px 10px;
    border-radius: 20px; white-space: nowrap;
    background: rgba(255,255,255,.06); color: var(--text-secondary);
}
.league-status-open { background: rgba(34,197,94,.14); color: #22c55e; }
.league-status-in_progress { background: rgba(251,191,36,.14); color: #fbbf24; }
.league-status-completed { background: rgba(255,255,255,.08); color: #a1a1aa; }
.league-status-cancelled { background: rgba(248,113,113,.14); color: #f87171; }
.league-progress { margin-top: 14px; }
.league-progress-bar { height: 6px; border-radius: 6px; background: rgba(255,255,255,.07); overflow: hidden; }
.league-progress-bar span { display: block; height: 100%; background: #22c55e; }
.league-progress-text { margin-top: 6px; font-size: 12px; color: var(--text-secondary); }
</style>
@endsection

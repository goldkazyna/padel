@extends('layouts.app')

@section('title', 'Главная')

@section('content')
@php
    $user = auth()->user();
    $stats = $user->getAllMatchesStats();
@endphp

<div class="page-header">
    <div>
        <h2>Добро пожаловать, {{ $user->first_name }}! 👋</h2>
        <p>Вот что происходит с твоей игрой</p>
    </div>
    <a href="{{ route('tournaments.index') }}" class="btn-primary-custom">
        <i class="bi bi-plus-circle"></i>
        <span>Записаться на турнир</span>
    </a>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">{{ $user->rating }}</div>
                <div class="stat-label">Рейтинг</div>
            </div>
            <div class="stat-icon green">
                <i class="bi bi-star-fill"></i>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">{{ $user->level }}</div>
                <div class="stat-label">Уровень</div>
            </div>
            <div class="stat-icon blue">
                <i class="bi bi-graph-up-arrow"></i>
            </div>
        </div>
        <div class="mt-2">
            <span class="badge-success-custom">{{ $user->level_name }}</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">{{ $stats['total'] }}</div>
                <div class="stat-label">Матчей</div>
            </div>
            <div class="stat-icon purple">
                <i class="bi bi-controller"></i>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">{{ $user->winRate() }}%</div>
                <div class="stat-label">Винрейт</div>
            </div>
            <div class="stat-icon orange">
                <i class="bi bi-percent"></i>
            </div>
        </div>
    </div>
</div>

<!-- Ближайшие турниры -->
<div class="card-dark mb-4">
    <div class="card-header">
        <h5><i class="bi bi-calendar-event"></i> Ближайшие турниры</h5>
        <a href="{{ route('tournaments.index') }}" class="btn-outline-custom">Все турниры</a>
    </div>
    <div class="card-body">
        @if(isset($upcomingTournaments) && $upcomingTournaments->count() > 0)
            <div class="tournaments-list">
                @foreach($upcomingTournaments as $tournament)
                    <div class="tournament-row">
                        <div class="tournament-date">
                            <div class="date-day">{{ $tournament->start_date->format('d') }}</div>
                            <div class="date-month">{{ $tournament->start_date->translatedFormat('M') }}</div>
                        </div>
                        <div class="tournament-info">
                            <div class="tournament-name">{{ $tournament->name }}</div>
                            <div class="tournament-meta">
                                <span><i class="bi bi-geo-alt"></i> {{ $tournament->club?->name ?? ($tournament->creator?->name ?? 'Личный турнир') }}</span>
                                <span><i class="bi bi-people"></i> {{ $tournament->participants->count() }}/{{ $tournament->max_participants }}</span>
                                <span><i class="bi bi-bar-chart"></i> {{ $tournament->min_level }}-{{ $tournament->max_level }}</span>
                            </div>
                        </div>
                        <div class="tournament-action">
                            @if($tournament->isRegistered($user))
                                <span class="badge-success-custom">Записан</span>
                            @elseif($tournament->canRegister($user))
                                <a href="{{ route('tournaments.show', $tournament) }}" class="btn-primary-custom btn-sm">Записаться</a>
                            @else
                                <a href="{{ route('tournaments.show', $tournament) }}" class="btn-outline-custom btn-sm">Подробнее</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-secondary mb-0">Пока нет доступных турниров</p>
        @endif
    </div>
</div>

<!-- Мои турниры -->
@if(isset($myTournaments) && $myTournaments->count() > 0)
<div class="card-dark">
    <div class="card-header">
        <h5><i class="bi bi-trophy"></i> Мои турниры</h5>
    </div>
    <div class="card-body">
        <div class="tournaments-list">
            @foreach($myTournaments as $tournament)
                <div class="tournament-row">
                    <div class="tournament-date">
                        <div class="date-day">{{ $tournament->start_date->format('d') }}</div>
                        <div class="date-month">{{ $tournament->start_date->translatedFormat('M') }}</div>
                    </div>
                    <div class="tournament-info">
                        <div class="tournament-name">{{ $tournament->name }}</div>
                        <div class="tournament-meta">
                            <span><i class="bi bi-geo-alt"></i> {{ $tournament->club?->name ?? ($tournament->creator?->name ?? 'Личный турнир') }}</span>
                            <span class="badge-{{ $tournament->status_color }}-custom">{{ $tournament->status_name }}</span>
                        </div>
                    </div>
                    <div class="tournament-action">
                        <a href="{{ route('tournaments.show', $tournament) }}" class="btn-outline-custom btn-sm">Открыть</a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endif

<style>
.tournaments-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.tournament-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px;
    background: rgba(255,255,255,0.03);
    border-radius: 12px;
    transition: all 0.2s;
}

.tournament-row:hover {
    background: rgba(255,255,255,0.06);
}

.tournament-date {
    text-align: center;
    min-width: 50px;
    padding: 8px 12px;
    background: var(--accent);
    border-radius: 10px;
    color: #000;
}

.date-day {
    font-size: 1.3rem;
    font-weight: 700;
    line-height: 1;
}

.date-month {
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 600;
}

.tournament-info {
    flex: 1;
    min-width: 0;
}

.tournament-name {
    font-weight: 600;
    margin-bottom: 4px;
}

.tournament-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 0.8rem;
    color: var(--text-secondary);
}

.tournament-meta i {
    margin-right: 4px;
}

.tournament-action {
    flex-shrink: 0;
}

.btn-sm {
    padding: 6px 12px !important;
    font-size: 0.8rem !important;
}

@media (max-width: 768px) {
    .tournament-row {
        flex-wrap: wrap;
    }
    
    .tournament-action {
        width: 100%;
        margin-top: 8px;
    }
    
    .tournament-action a,
    .tournament-action span {
        width: 100%;
        text-align: center;
    }
}
</style>
@endsection
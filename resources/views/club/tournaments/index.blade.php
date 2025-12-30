@extends('layouts.app')

@section('title', 'Управление турнирами')

@section('content')
<div class="page-header">
    <div>
        <h2>Турниры {{ $club ? '— ' . $club->name : '' }}</h2>
        <p>Управление турнирами клуба</p>
    </div>
    <a href="{{ route('club.tournaments.create') }}" class="btn-primary-custom">
        <i class="bi bi-plus-circle"></i>
        <span>Создать турнир</span>
    </a>
</div>

<div class="tournaments-list">
    @forelse($tournaments as $tournament)
        <div class="tournament-row {{ $tournament->status }}">
            <div class="tournament-date-box">
                <div class="tournament-day">{{ $tournament->start_date->format('d') }}</div>
                <div class="tournament-month">{{ $tournament->start_date->translatedFormat('M') }}</div>
            </div>
            <div class="tournament-info">
                <div class="tournament-name">{{ $tournament->name }}</div>
                <div class="tournament-meta">
                    @if(!$club)
                        <span><i class="bi bi-building"></i> {{ $tournament->club->name }}</span>
                    @endif
                    <span><i class="bi bi-clock"></i> {{ $tournament->start_date->format('H:i') }}</span>
                    <span><i class="bi bi-bar-chart"></i> {{ $tournament->min_level }}–{{ $tournament->max_level }}</span>
                </div>
            </div>
            <div class="tournament-stats d-none d-lg-flex">
                <div class="tournament-stat">
                    <div class="tournament-stat-value">{{ $tournament->participants()->count() }}/{{ $tournament->max_participants }}</div>
                    <div class="tournament-stat-label">Участников</div>
                </div>
                <div class="tournament-stat">
                    <div class="tournament-stat-value">{{ $tournament->matches()->count() }}</div>
                    <div class="tournament-stat-label">Матчей</div>
                </div>
            </div>
            <div class="tournament-status">
                <span class="badge-{{ $tournament->status_color }}-custom">{{ $tournament->status_name }}</span>
            </div>
            <div class="tournament-actions">
                <a href="{{ route('club.tournaments.show', $tournament) }}" class="btn-outline-custom btn-sm" title="Просмотр">
                    <i class="bi bi-eye"></i>
                </a>
                <a href="{{ route('club.tournaments.edit', $tournament) }}" class="btn-outline-custom btn-sm" title="Редактировать">
                    <i class="bi bi-pencil"></i>
                </a>
                @if($tournament->status === 'draft')
                    <form action="{{ route('club.tournaments.destroy', $tournament) }}" method="POST" class="d-inline"
                          onsubmit="return confirm('Удалить турнир?')">
                        @csrf
                        @method('DELETE')
                        <button class="btn-danger-custom btn-sm" title="Удалить">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <div class="card-dark">
            <div class="card-body text-center py-5">
                <i class="bi bi-trophy fs-1 text-secondary mb-3"></i>
                <p class="text-secondary mb-3">Турниров пока нет</p>
                <a href="{{ route('club.tournaments.create') }}" class="btn-primary-custom">
                    <i class="bi bi-plus-circle"></i> Создать первый турнир
                </a>
            </div>
        </div>
    @endforelse
</div>

<style>
.tournaments-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.tournament-row {
    display: flex;
    align-items: center;
    padding: 16px 20px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border);
    gap: 16px;
    transition: all 0.2s;
}

.tournament-row:hover {
    border-color: var(--accent);
    transform: translateX(4px);
}

.tournament-row.open {
    border-left: 3px solid #22c55e;
}

.tournament-row.in_progress {
    border-left: 3px solid #3b82f6;
}

.tournament-row.completed {
    border-left: 3px solid #06b6d4;
}

.tournament-row.cancelled {
    border-left: 3px solid #ef4444;
    opacity: 0.7;
}

.tournament-date-box {
    width: 54px;
    height: 54px;
    background: linear-gradient(135deg, var(--accent) 0%, #16a34a 100%);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.tournament-day {
    font-size: 1.3rem;
    font-weight: 700;
    line-height: 1;
}

.tournament-month {
    font-size: 0.7rem;
    text-transform: uppercase;
    opacity: 0.9;
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
    gap: 16px;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.tournament-meta i {
    margin-right: 4px;
}

.tournament-stats {
    display: flex;
    gap: 24px;
}

.tournament-stat {
    text-align: center;
}

.tournament-stat-value {
    font-weight: 700;
    font-size: 1.1rem;
}

.tournament-stat-label {
    font-size: 0.7rem;
    color: var(--text-secondary);
}

.tournament-status {
    min-width: 140px;
    text-align: center;
}

.tournament-actions {
    display: flex;
    gap: 8px;
}

@media (max-width: 768px) {
    .tournament-row {
        flex-wrap: wrap;
        padding: 14px 16px;
    }
    
    .tournament-date-box {
        width: 48px;
        height: 48px;
    }
    
    .tournament-day {
        font-size: 1.1rem;
    }
    
    .tournament-info {
        flex: 1;
        min-width: calc(100% - 140px);
    }
    
    .tournament-meta {
        flex-direction: column;
        gap: 4px;
    }
    
    .tournament-status {
        order: 3;
        min-width: auto;
    }
    
    .tournament-actions {
        order: 4;
        margin-left: auto;
    }
}
</style>
@endsection
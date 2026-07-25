@extends('layouts.app')

@section('title', 'Управление турнирами')

@section('content')
<div class="page-header">
    <div>
        <h2>Турниры {{ $club ? '— ' . $club->name : '' }}</h2>
        <p>Управление турнирами клуба</p>
    </div>
    @if(auth()->user()->isSuperAdmin() || ($club && auth()->user()->hasTournamentsFullAccess($club)))
    <a href="{{ route('club.tournaments.create') }}" class="btn-primary-custom">
        <i class="bi bi-plus-circle"></i>
        <span>Создать турнир</span>
    </a>
    @endif
</div>

<div class="tournaments-list">
    @if($groupedTournaments->isEmpty())
        <div class="card-dark">
            <div class="card-body text-center py-5">
                <i class="bi bi-trophy fs-1 text-secondary mb-3"></i>
                <p class="text-secondary mb-3">Турниров пока нет</p>
                @if(auth()->user()->isSuperAdmin() || ($club && auth()->user()->hasTournamentsFullAccess($club)))
                <a href="{{ route('club.tournaments.create') }}" class="btn-primary-custom">
                    <i class="bi bi-plus-circle"></i> Создать первый турнир
                </a>
                @endif
            </div>
        </div>
    @else
        @foreach($groupedTournaments as $monthKey => $tournaments)
            @php
                $isCurrentMonth = $monthKey === now()->format('Y-m');
            @endphp
            <div class="month-group">
                <div class="month-header {{ $isCurrentMonth ? 'open' : '' }}" onclick="toggleMonth(this)">
                    <div class="month-header-left">
                        <i class="bi bi-chevron-right month-arrow"></i>
                        <span class="month-title">{{ $monthKey === 'no-date' ? 'Без даты' : \Carbon\Carbon::parse($monthKey . '-01')->translatedFormat('F Y') }}</span>
                        <span class="month-count">{{ $tournaments->count() }}</span>
                    </div>
                </div>
                <div class="month-body" @if(!$isCurrentMonth) style="display: none;" @endif>
                    @foreach($tournaments as $tournament)
                        <div class="tournament-row {{ $tournament->status }}">
                            <div class="tournament-date-box">
                                <div class="tournament-day">{{ $tournament->start_date?->format('d') ?? '—' }}</div>
                                <div class="tournament-month">{{ $tournament->start_date?->translatedFormat('M') ?? '' }}</div>
                            </div>
                            <div class="tournament-info">
                                <div class="tournament-name">
                                    {{ $tournament->name }}
                                    @if($tournament->verified_only)
                                        <i class="bi bi-patch-check-fill text-success" title="Только для верифицированных игроков"></i>
                                    @endif
                                </div>
                                <div class="tournament-meta">
                                    @if(!$club)
                                        <span><i class="bi bi-building"></i> {{ $tournament->club->name }}</span>
                                    @endif
                                    @if($tournament->start_date)
                                    <span><i class="bi bi-clock"></i> {{ $tournament->start_date->format('H:i') }}</span>
                                    @endif
                                    <span><i class="bi bi-bar-chart"></i> {{ $tournament->min_level }}–{{ $tournament->max_level }}</span>
                                    @if($tournament->verified_only)
                                        <span class="text-success"><i class="bi bi-patch-check"></i> Только верифицированные</span>
                                    @endif
                                </div>
                            </div>
                            <div class="tournament-stats d-none d-lg-flex">
                                <div class="tournament-stat">
                                    <div class="tournament-stat-value">{{ $tournament->totalParticipantsCount() }}/{{ $tournament->max_participants }}</div>
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
                                @if(auth()->user()->isSuperAdmin() || ($club && auth()->user()->hasTournamentsFullAccess($club)))
                                <a href="{{ route('club.tournaments.edit', $tournament) }}" class="btn-outline-custom btn-sm" title="Редактировать">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                @if($tournament->status === 'open')
                                    <form action="{{ route('club.tournaments.sendPush', $tournament) }}" method="POST" class="d-inline"
                                          onsubmit="return confirm('Отправить push-уведомление о турнире?')">
                                        @csrf
                                        <button class="btn-outline-custom btn-sm btn-push" title="Отправить Push">
                                            <i class="bi bi-bell"></i>
                                        </button>
                                    </form>
                                @endif
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
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
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
.btn-telegram {
    color: #229ED9;
    border-color: #229ED9;
}

.btn-telegram:hover {
    background: #229ED9;
    color: white;
}

.btn-push {
    color: #f59e0b;
    border-color: #f59e0b;
}

.btn-push:hover {
    background: #f59e0b;
    color: white;
}

/* Month groups */
.month-group {
    margin-bottom: 4px;
}

.month-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px;
    cursor: pointer;
    border-radius: 8px;
    user-select: none;
    transition: background 0.15s;
}

.month-header:hover {
    background: var(--bg-card);
}

.month-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}

.month-arrow {
    font-size: 0.85rem;
    color: var(--text-secondary);
    transition: transform 0.2s;
}

.month-header.open .month-arrow {
    transform: rotate(90deg);
}

.month-title {
    font-weight: 600;
    font-size: 0.95rem;
    text-transform: capitalize;
}

.month-count {
    background: var(--bg-card);
    border: 1px solid var(--border);
    color: var(--text-secondary);
    font-size: 0.75rem;
    font-weight: 600;
    padding: 1px 8px;
    border-radius: 10px;
}

.month-body {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-top: 4px;
}
</style>

<script>
function toggleMonth(header) {
    const body = header.nextElementSibling;
    const isOpen = header.classList.contains('open');

    if (isOpen) {
        body.style.display = 'none';
        header.classList.remove('open');
    } else {
        body.style.display = '';
        header.classList.add('open');
    }
}
</script>
@endsection
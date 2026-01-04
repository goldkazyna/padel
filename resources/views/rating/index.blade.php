@extends('layouts.app')

@section('title', 'Рейтинг игроков')

@section('content')
<div class="page-header">
    <div>
        <h2>Рейтинг игроков</h2>
        <p>Топ игроков платформы</p>
    </div>
</div>

<div class="rating-list">
    @forelse($players as $player)
        @php
            $position = ($players->currentPage() - 1) * $players->perPage() + $loop->iteration;
        @endphp
        <a href="{{ route('players.show', $player) }}" class="rating-row {{ $position === 1 ? 'gold' : ($position === 2 ? 'silver' : ($position === 3 ? 'bronze' : '')) }}">
            <div class="rank {{ $position === 1 ? 'gold' : ($position === 2 ? 'silver' : ($position === 3 ? 'bronze' : '')) }}">
                #{{ $position }}
            </div>
            <div class="player-avatar">
                {{ strtoupper(substr($player->first_name, 0, 1) . substr($player->last_name, 0, 1)) }}
            </div>
            <div class="player-info">
                <div class="player-name">
                    {{ $player->full_name }}
                    @if($player->id === auth()->id())
                        <span class="you-badge">Вы</span>
                    @endif
                </div>
                <div class="player-level">{{ $player->level }} · {{ $player->level_name }}</div>
            </div>
            <div class="player-stats">
                <div class="stat">
                    <div class="stat-value">{{ $player->wins() + $player->losses() }}</div>
                    <div class="stat-label">Матчей</div>
                </div>
                <div class="stat">
                    <div class="stat-value text-success">{{ $player->wins() }}</div>
                    <div class="stat-label">Побед</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{{ $player->winRate() }}%</div>
                    <div class="stat-label">Винрейт</div>
                </div>
            </div>
            <div class="player-rating">{{ $player->rating }}</div>
        </a>
    @empty
        <div class="card-dark">
            <div class="card-body text-center py-5">
                <i class="bi bi-people fs-1 text-secondary mb-3"></i>
                <p class="text-secondary mb-0">Пока нет игроков</p>
            </div>
        </div>
    @endforelse
</div>

{{-- Пагинация --}}
@if($players->hasPages())
<div class="pagination-custom">
    <div class="pagination-info">
        Показано {{ $players->firstItem() }}–{{ $players->lastItem() }} из {{ $players->total() }}
    </div>
    <div class="pagination-buttons">
        {{-- Назад --}}
        @if($players->onFirstPage())
            <span class="page-btn disabled"><i class="bi bi-chevron-left"></i></span>
        @else
            <a href="{{ $players->previousPageUrl() }}" class="page-btn"><i class="bi bi-chevron-left"></i></a>
        @endif

        {{-- Номера страниц --}}
        @php
            $currentPage = $players->currentPage();
            $lastPage = $players->lastPage();
            
            // Показываем максимум 5 страниц
            $start = max(1, $currentPage - 2);
            $end = min($lastPage, $currentPage + 2);
            
            // Корректируем если в начале или конце
            if ($currentPage <= 3) {
                $end = min($lastPage, 5);
            }
            if ($currentPage >= $lastPage - 2) {
                $start = max(1, $lastPage - 4);
            }
        @endphp

        @if($start > 1)
            <a href="{{ $players->url(1) }}" class="page-btn">1</a>
            @if($start > 2)
                <span class="page-dots">...</span>
            @endif
        @endif

        @for($i = $start; $i <= $end; $i++)
            @if($i == $currentPage)
                <span class="page-btn active">{{ $i }}</span>
            @else
                <a href="{{ $players->url($i) }}" class="page-btn">{{ $i }}</a>
            @endif
        @endfor

        @if($end < $lastPage)
            @if($end < $lastPage - 1)
                <span class="page-dots">...</span>
            @endif
            <a href="{{ $players->url($lastPage) }}" class="page-btn">{{ $lastPage }}</a>
        @endif

        {{-- Вперёд --}}
        @if($players->hasMorePages())
            <a href="{{ $players->nextPageUrl() }}" class="page-btn"><i class="bi bi-chevron-right"></i></a>
        @else
            <span class="page-btn disabled"><i class="bi bi-chevron-right"></i></span>
        @endif
    </div>
</div>
@endif

<style>
.rating-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.rating-row {
    display: flex;
    align-items: center;
    padding: 16px 20px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border);
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    color: inherit;
}

.rating-row:hover {
    border-color: var(--accent);
    transform: translateX(4px);
}

.rating-row.gold {
    background: linear-gradient(90deg, rgba(234, 179, 8, 0.15) 0%, var(--bg-card) 50%);
    border-color: rgba(234, 179, 8, 0.3);
}

.rating-row.silver {
    background: linear-gradient(90deg, rgba(156, 163, 175, 0.15) 0%, var(--bg-card) 50%);
    border-color: rgba(156, 163, 175, 0.3);
}

.rating-row.bronze {
    background: linear-gradient(90deg, rgba(180, 83, 9, 0.15) 0%, var(--bg-card) 50%);
    border-color: rgba(180, 83, 9, 0.3);
}

.rank {
    width: 50px;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-secondary);
}

.rank.gold { color: #eab308; }
.rank.silver { color: #9ca3af; }
.rank.bronze { color: #b45309; }

.player-avatar {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, var(--accent) 0%, #16a34a 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    margin-right: 14px;
    flex-shrink: 0;
}

.player-info {
    flex: 1;
    min-width: 0;
}

.player-name {
    font-weight: 600;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.you-badge {
    background: var(--accent);
    color: #000;
    font-size: 0.65rem;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 600;
}

.player-level {
    font-size: 0.8rem;
    color: var(--accent);
}

.player-stats {
    display: flex;
    gap: 24px;
    margin-right: 20px;
}

.stat {
    text-align: center;
}

.stat-value {
    font-weight: 600;
}

.stat-label {
    font-size: 0.7rem;
    color: var(--text-secondary);
}

.player-rating {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--accent);
    min-width: 70px;
    text-align: right;
}

/* Пагинация */
.pagination-custom {
    margin-top: 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}

.pagination-info {
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.pagination-buttons {
    display: flex;
    align-items: center;
    gap: 6px;
}

.page-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 10px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: #fff;
    text-decoration: none;
    font-weight: 500;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.page-btn:hover:not(.disabled):not(.active) {
    background: var(--accent);
    color: #000;
    border-color: var(--accent);
}

.page-btn.active {
    background: var(--accent);
    color: #000;
    border-color: var(--accent);
}

.page-btn.disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.page-dots {
    color: var(--text-secondary);
    padding: 0 4px;
}

/* Мобильная адаптация */
@media (max-width: 768px) {
    .rating-row {
        padding: 12px 14px;
    }
    
    .rank {
        width: 36px;
        font-size: 0.9rem;
    }
    
    .player-avatar {
        width: 36px;
        height: 36px;
        font-size: 0.8rem;
        margin-right: 10px;
        border-radius: 10px;
    }
    
    .player-name {
        font-size: 0.9rem;
    }
    
    .player-level {
        font-size: 0.75rem;
    }
    
    .player-stats {
        display: none;
    }
    
    .player-rating {
        font-size: 1.1rem;
        min-width: 50px;
    }
    
    /* Пагинация на мобилке */
    .pagination-custom {
        margin-top: 20px;
    }
    
    .pagination-info {
        font-size: 0.8rem;
    }
    
    .pagination-buttons {
        gap: 4px;
    }
    
    .page-btn {
        min-width: 32px;
        height: 32px;
        font-size: 0.8rem;
        padding: 0 8px;
    }
}

@media (max-width: 400px) {
    .rating-row {
        padding: 10px 12px;
    }
    
    .rank {
        width: 30px;
        font-size: 0.85rem;
    }
    
    .player-avatar {
        width: 32px;
        height: 32px;
        font-size: 0.7rem;
        margin-right: 8px;
    }
    
    .player-rating {
        font-size: 1rem;
        min-width: 45px;
    }
    
    .you-badge {
        font-size: 0.6rem;
        padding: 1px 4px;
    }
}
</style>
@endsection
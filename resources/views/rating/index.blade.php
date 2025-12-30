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
    @forelse($players as $index => $player)
        <div class="rating-row {{ $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : '')) }}"
             data-bs-toggle="modal" 
             data-bs-target="#playerModal{{ $player->id }}">
            <div class="rank {{ $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : 'text-secondary')) }}">
                #{{ $index + 1 }}
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
        </div>
    @empty
        <div class="card-dark">
            <div class="card-body text-center py-5">
                <i class="bi bi-people fs-1 text-secondary mb-3"></i>
                <p class="text-secondary mb-0">Пока нет игроков</p>
            </div>
        </div>
    @endforelse
</div>

<!-- Модальные окна -->
@foreach($players as $index => $player)
<div class="modal fade" id="playerModal{{ $player->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--bg-card); border: 1px solid var(--border);">
            <div class="modal-header border-0">
                <h5 class="modal-title">Профиль игрока</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <div class="user-avatar mx-auto mb-3" style="width:72px;height:72px;font-size:1.8rem;">
                        {{ strtoupper(substr($player->first_name, 0, 1) . substr($player->last_name, 0, 1)) }}
                    </div>
                    <h4 class="mb-1">{{ $player->full_name }}</h4>
                    <span class="badge-success-custom">{{ $player->level }} · {{ $player->level_name }}</span>
                </div>
                
                <div class="row g-3 text-center">
                    <div class="col-4">
                        <div class="p-3 rounded-3" style="background: var(--bg-secondary);">
                            <div class="fs-4 fw-bold">{{ $index + 1 }}</div>
                            <small class="text-secondary">Место</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 rounded-3" style="background: var(--bg-secondary);">
                            <div class="fs-4 fw-bold text-success">{{ $player->rating }}</div>
                            <small class="text-secondary">Рейтинг</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 rounded-3" style="background: var(--bg-secondary);">
                            <div class="fs-4 fw-bold">{{ $player->winRate() }}%</div>
                            <small class="text-secondary">Винрейт</small>
                        </div>
                    </div>
                </div>
                
                <hr style="border-color: var(--border);">
                
                <div class="row text-center">
                    <div class="col-4">
                        <div class="fs-5 fw-bold">{{ $player->wins() + $player->losses() }}</div>
                        <small class="text-secondary">Матчей</small>
                    </div>
                    <div class="col-4">
                        <div class="fs-5 fw-bold text-success">{{ $player->wins() }}</div>
                        <small class="text-secondary">Побед</small>
                    </div>
                    <div class="col-4">
                        <div class="fs-5 fw-bold text-danger">{{ $player->losses() }}</div>
                        <small class="text-secondary">Поражений</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endforeach

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
    font-size: 1.2rem;
    font-weight: 700;
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

@media (max-width: 768px) {
    .player-stats {
        display: none;
    }
    
    .player-rating {
        font-size: 1.2rem;
        min-width: 60px;
    }
    
    .rating-row {
        padding: 14px 16px;
    }
    
    .rank {
        width: 40px;
        font-size: 1rem;
    }
    
    .player-avatar {
        width: 38px;
        height: 38px;
        font-size: 0.85rem;
        margin-right: 12px;
    }
}
</style>
@endsection
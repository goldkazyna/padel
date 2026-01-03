@extends('layouts.app')

@section('title', $player->full_name)

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('rating.index') }}" class="btn-back mb-3">
            <i class="bi bi-arrow-left"></i> К рейтингу
        </a>
        <h2>Профиль игрока</h2>
    </div>
</div>

{{-- Карточка игрока --}}
<div class="player-profile-card mb-4">
    <div class="profile-header">
        <div class="profile-avatar">
            {{ strtoupper(substr($player->first_name, 0, 1) . substr($player->last_name, 0, 1)) }}
        </div>
        <div class="profile-info">
            <h1 class="profile-name">{{ $player->full_name }}</h1>
            <div class="profile-badges">
                <span class="badge-level">{{ $player->level }}</span>
                <span class="badge-rank">#{{ $rank }} в рейтинге</span>
            </div>
        </div>
        <div class="profile-rating">
            <div class="rating-value">{{ $player->rating }}</div>
            <div class="rating-label">Рейтинг</div>
        </div>
    </div>
    
    <div class="profile-stats">
        <div class="stat-box">
            <div class="stat-value">{{ $stats['total'] }}</div>
            <div class="stat-label">Матчей</div>
        </div>
        <div class="stat-box">
            <div class="stat-value text-success">{{ $stats['won'] }}</div>
            <div class="stat-label">Побед</div>
        </div>
        <div class="stat-box">
            <div class="stat-value text-danger">{{ $stats['lost'] }}</div>
            <div class="stat-label">Поражений</div>
        </div>
        <div class="stat-box">
            <div class="stat-value {{ $player->winRate() >= 50 ? 'text-success' : 'text-danger' }}">
                {{ $player->winRate() }}%
            </div>
            <div class="stat-label">Винрейт</div>
        </div>
    </div>
</div>

{{-- График рейтинга --}}
@if($ratingHistory->count() > 0)
<div class="card-dark mb-4">
    <div class="card-header-dark">
        <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>История рейтинга</h5>
    </div>
    <div class="card-body-dark">
        <div class="rating-chart-container">
            <canvas id="ratingChart"></canvas>
        </div>
        
        <div class="rating-history-list mt-4">
            @foreach($ratingHistory as $history)
                <div class="rating-history-item">
                    <div class="history-date">{{ $history->created_at->format('d.m.Y') }}</div>
                    <div class="history-reason">{{ $history->reason }}</div>
                    <div class="history-change {{ $history->change >= 0 ? 'positive' : 'negative' }}">
                        {{ $history->change >= 0 ? '+' : '' }}{{ $history->change }}
                    </div>
                    <div class="history-rating">{{ $history->rating_after }}</div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endif




{{-- История матчей --}}
<div class="card-dark">
    <div class="card-header-dark">
        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>История матчей</h5>
    </div>
    <div class="card-body-dark">
        @if(count($matchHistory) > 0)
            <div class="match-history-list">
                @foreach($matchHistory as $match)
                    <div class="match-history-item {{ $match['won'] ? 'won' : 'lost' }}">
                        <div class="match-result-indicator">
                            @if($match['won'])
                                <i class="bi bi-trophy-fill"></i>
                            @else
                                <i class="bi bi-x-circle-fill"></i>
                            @endif
                        </div>
                        <div class="match-details">
                            <div class="match-tournament">
                                <span class="match-type-badge">{{ $match['type'] }}</span>
                                {{ $match['tournament'] }}
                            </div>
                            <div class="match-players">
                                <span class="text-secondary">С партнёром:</span> {{ $match['partner'] }}
                                <span class="text-secondary ms-2">Против:</span> {{ $match['opponents'] }}
                            </div>
                        </div>
                        <div class="match-score-box {{ $match['won'] ? 'won' : 'lost' }}">
                            {{ $match['score'] }}
                        </div>
                        <div class="match-date">
                            {{ $match['date']->format('d.m.Y') }}
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-5">
                <i class="bi bi-calendar-x fs-1 text-secondary mb-3"></i>
                <p class="text-secondary mb-0">Пока нет сыгранных матчей</p>
            </div>
        @endif
    </div>
</div>

<style>
/* График рейтинга */
.rating-chart-container {
    height: 250px;
    margin-bottom: 16px;
}

.rating-history-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.rating-history-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px 16px;
    background: rgba(255,255,255,0.03);
    border-radius: 8px;
}

.history-date {
    font-size: 0.85rem;
    color: var(--text-secondary);
    min-width: 80px;
}

.history-reason {
    flex: 1;
    font-weight: 500;
}

.history-change {
    font-weight: 700;
    min-width: 60px;
    text-align: right;
}

.history-change.positive {
    color: var(--accent);
}

.history-change.negative {
    color: #ef4444;
}

.history-rating {
    font-weight: 600;
    min-width: 50px;
    text-align: right;
    color: var(--text-secondary);
}
.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.9rem;
}

.btn-back:hover {
    color: var(--accent);
}

.player-profile-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
}

.profile-header {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 24px;
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, transparent 50%);
}

.profile-avatar {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, var(--accent) 0%, #16a34a 100%);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    font-weight: 700;
    flex-shrink: 0;
}

.profile-info {
    flex: 1;
}

.profile-name {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.profile-badges {
    display: flex;
    gap: 8px;
}

.badge-level {
    background: var(--accent);
    color: #000;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.85rem;
}

.badge-rank {
    background: rgba(255,255,255,0.1);
    color: #fff;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
}

.profile-rating {
    text-align: center;
}

.rating-value {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--accent);
    line-height: 1;
}

.rating-label {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-top: 4px;
}

.profile-stats {
    display: flex;
    border-top: 1px solid var(--border);
}

.stat-box {
    flex: 1;
    text-align: center;
    padding: 20px;
    border-right: 1px solid var(--border);
}

.stat-box:last-child {
    border-right: none;
}

.stat-box .stat-value {
    font-size: 1.5rem;
    font-weight: 700;
}

.stat-box .stat-label {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-top: 4px;
}

/* История матчей */
.match-history-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.match-history-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: rgba(255,255,255,0.03);
    border-radius: 12px;
    border-left: 4px solid transparent;
}

.match-history-item.won {
    border-left-color: var(--accent);
}

.match-history-item.lost {
    border-left-color: #ef4444;
}

.match-result-indicator {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    flex-shrink: 0;
}

.match-history-item.won .match-result-indicator {
    background: rgba(34, 197, 94, 0.2);
    color: var(--accent);
}

.match-history-item.lost .match-result-indicator {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

.match-details {
    flex: 1;
    min-width: 0;
}

.match-tournament {
    font-weight: 600;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.match-type-badge {
    background: rgba(255,255,255,0.1);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
}

.match-players {
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.match-score-box {
    font-size: 1.2rem;
    font-weight: 700;
    padding: 8px 16px;
    border-radius: 8px;
    min-width: 70px;
    text-align: center;
}

.match-score-box.won {
    background: rgba(34, 197, 94, 0.2);
    color: var(--accent);
}

.match-score-box.lost {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

.match-date {
    font-size: 0.8rem;
    color: var(--text-secondary);
    min-width: 80px;
    text-align: right;
}

@media (max-width: 768px) {
    .profile-header {
        flex-wrap: wrap;
    }
    
    .profile-rating {
        width: 100%;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid var(--border);
    }
    
    .profile-stats {
        flex-wrap: wrap;
    }
    
    .stat-box {
        width: 50%;
        border-bottom: 1px solid var(--border);
    }
    
    .stat-box:nth-child(2) {
        border-right: none;
    }
    
    .stat-box:nth-child(3),
    .stat-box:nth-child(4) {
        border-bottom: none;
    }
    
    .match-history-item {
        flex-wrap: wrap;
    }
    
    .match-details {
        width: calc(100% - 52px);
    }
    
    .match-score-box {
        margin-left: 52px;
    }
    
    .match-date {
        margin-left: auto;
    }
}
</style>
@if($ratingHistory->count() > 0)
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('ratingChart').getContext('2d');
    
    const data = @json($ratingHistory->reverse()->values());
    const labels = data.map(h => {
        const date = new Date(h.created_at);
        return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
    });
    const ratings = data.map(h => h.rating_after);
    
    // Добавляем начальную точку
    if (data.length > 0) {
        labels.unshift('Старт');
        ratings.unshift(data[0].rating_before);
    }
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Рейтинг',
                data: ratings,
                borderColor: '#22c55e',
                backgroundColor: 'rgba(34, 197, 94, 0.1)',
                fill: true,
                tension: 0.3,
                pointBackgroundColor: '#22c55e',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: '#1f2937',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    borderColor: '#374151',
                    borderWidth: 1,
                    padding: 12,
                    displayColors: false,
                }
            },
            scales: {
                x: {
                    grid: {
                        color: 'rgba(255,255,255,0.05)'
                    },
                    ticks: {
                        color: '#9ca3af'
                    }
                },
                y: {
                    grid: {
                        color: 'rgba(255,255,255,0.05)'
                    },
                    ticks: {
                        color: '#9ca3af'
                    }
                }
            }
        }
    });
});
</script>
@endif
@endsection
@extends('layouts.app')
@section('title', 'Мой профиль')
@section('content')
<div class="page-header">
    <div>
        <h2>Мой профиль</h2>
        <p>Ваша статистика и достижения</p>
    </div>
    <a href="{{ route('profile.edit') }}" class="btn-primary-custom">
        <i class="bi bi-pencil"></i>
        <span>Редактировать</span>
    </a>
</div>

<div class="row g-4">
    <!-- Левая колонка -->
    <div class="col-lg-4">
        <!-- Карточка профиля -->
        <div class="profile-card-main mb-4">
            <div class="profile-card-header">
                <div class="profile-avatar-large">
                    {{ strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1)) }}
                </div>
                <div class="profile-rank-badge">#{{ $rank }}</div>
            </div>
            
            <div class="profile-card-body">
                <h3 class="profile-name">
                    {{ $user->full_name }}
                    @if($trend === 'up')
                        <i class="bi bi-graph-up-arrow text-success" title="Рейтинг растёт"></i>
                    @elseif($trend === 'down')
                        <i class="bi bi-graph-down-arrow text-danger" title="Рейтинг падает"></i>
                    @endif
                </h3>
                <p class="profile-email">{{ $user->email }}</p>
                
                <div class="profile-level-badge">
                    <i class="bi bi-award"></i>
                    {{ $user->level_name }} ({{ $user->level }})
                </div>
                
                @if($streak['count'] >= 3)
                    <div class="profile-streak {{ $streak['type'] }}">
                        @if($streak['type'] === 'win')
                            🔥 {{ $streak['count'] }} побед подряд!
                        @else
                            ❄️ Серия из {{ $streak['count'] }} поражений
                        @endif
                    </div>
                @endif
            </div>
            
            <div class="profile-stats-row">
                <div class="profile-stat">
                    <div class="profile-stat-value text-accent">{{ $user->rating }}</div>
                    <div class="profile-stat-label">Рейтинг</div>
                </div>
                <div class="profile-stat">
                    <div class="profile-stat-value">{{ $stats['total'] }}</div>
                    <div class="profile-stat-label">Матчей</div>
                </div>
                <div class="profile-stat">
                    <div class="profile-stat-value">{{ $tournamentStats['total'] }}</div>
                    <div class="profile-stat-label">Турниров</div>
                </div>
            </div>
        </div>

        <!-- Статистика матчей -->
        <div class="card-dark mb-4">
            <div class="card-header-dark">
                <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Статистика</h5>
            </div>
            <div class="card-body-dark">
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-icon win"><i class="bi bi-trophy"></i></div>
                        <div class="stat-info">
                            <div class="stat-number text-success">{{ $stats['won'] }}</div>
                            <div class="stat-text">Побед</div>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon loss"><i class="bi bi-x-circle"></i></div>
                        <div class="stat-info">
                            <div class="stat-number text-danger">{{ $stats['lost'] }}</div>
                            <div class="stat-text">Поражений</div>
                        </div>
                    </div>
                    <div class="stat-item full-width">
                        <div class="stat-icon winrate"><i class="bi bi-percent"></i></div>
                        <div class="stat-info">
                            <div class="stat-number {{ $user->winRate() >= 50 ? 'text-success' : 'text-danger' }}">
                                {{ $user->winRate() }}%
                            </div>
                            <div class="stat-text">Винрейт</div>
                        </div>
                        <div class="winrate-bar">
                            <div class="winrate-fill" style="width: {{ $user->winRate() }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Лучший партнёр -->
        @if($bestPartner)
        <div class="card-dark mb-4">
            <div class="card-header-dark">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>Лучший партнёр</h5>
            </div>
            <div class="card-body-dark">
                <div class="partner-card">
                    <div class="partner-avatar">
                        {{ strtoupper(substr($bestPartner['name'], 0, 2)) }}
                    </div>
                    <div class="partner-info">
                        <div class="partner-name">{{ $bestPartner['name'] }}</div>
                        <div class="partner-stats">
                            <span class="text-success">{{ $bestPartner['wins'] }} побед</span>
                            из {{ $bestPartner['total'] }} матчей
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Личные данные -->
        <div class="card-dark">
            <div class="card-header-dark">
                <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Личные данные</h5>
            </div>
            <div class="card-body-dark">
                <div class="info-row">
                    <span class="info-label">Телефон</span>
                    <span class="info-value">{{ $user->phone ?? '—' }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Дата рождения</span>
                    <span class="info-value">{{ $user->birth_date?->format('d.m.Y') ?? '—' }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Пол</span>
                    <span class="info-value">
                        @if($user->gender === 'male') Мужской
                        @elseif($user->gender === 'female') Женский
                        @else — @endif
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">На платформе</span>
                    <span class="info-value">с {{ $user->created_at->format('d.m.Y') }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Правая колонка -->
    <div class="col-lg-8">
        <!-- Достижения -->
        @if(count($achievements) > 0)
        <div class="card-dark mb-4">
            <div class="card-header-dark">
                <h5 class="mb-0"><i class="bi bi-award me-2"></i>Достижения</h5>
            </div>
            <div class="card-body-dark">
                <div class="achievements-grid">
                    @foreach($achievements as $achievement)
                        <div class="achievement-card" title="{{ $achievement['desc'] }}">
                            <span class="achievement-icon">{{ $achievement['icon'] }}</span>
                            <span class="achievement-name">{{ $achievement['name'] }}</span>
                            <span class="achievement-desc">{{ $achievement['desc'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        <!-- График рейтинга -->
        @if($ratingHistory->count() > 0)
        <div class="card-dark mb-4">
            <div class="card-header-dark d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>История рейтинга</h5>
                <span class="text-secondary small">Последние {{ $ratingHistory->count() }} турниров</span>
            </div>
            <div class="card-body-dark">
                <div class="rating-chart-container">
                    <canvas id="ratingChart"></canvas>
                </div>
                
                <div class="rating-history-compact mt-3">
                    @foreach($ratingHistory->take(5) as $history)
                        <div class="history-row">
                            <span class="history-date">{{ $history->created_at->format('d.m') }}</span>
                            <span class="history-name">{{ Str::limit($history->reason, 25) }}</span>
                            <span class="history-change {{ $history->change >= 0 ? 'positive' : 'negative' }}">
                                {{ $history->change >= 0 ? '+' : '' }}{{ $history->change }}
                            </span>
                            <span class="history-total">{{ $history->rating_after }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        <!-- Турниры по типам -->
        <div class="card-dark mb-4">
            <div class="card-header-dark">
                <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Турниры</h5>
            </div>
            <div class="card-body-dark">
                <div class="tournaments-overview">
                    <div class="tournament-total">
                        <div class="tournament-total-number">{{ $tournamentStats['total'] }}</div>
                        <div class="tournament-total-label">Всего турниров</div>
                    </div>
                    <div class="tournament-breakdown">
                        <div class="tournament-type-card">
                            <div class="type-icon americano"><i class="bi bi-arrow-repeat"></i></div>
                            <div class="type-info">
                                <div class="type-name">Американо</div>
                                <div class="type-count">{{ $tournamentStats['by_type']['americano'] ?? 0 }}</div>
                            </div>
                        </div>
                        <div class="tournament-type-card">
                            <div class="type-icon mexicano"><i class="bi bi-shuffle"></i></div>
                            <div class="type-info">
                                <div class="type-name">Мексикано</div>
                                <div class="type-count">{{ $tournamentStats['by_type']['mexicano'] ?? 0 }}</div>
                            </div>
                        </div>
                        <div class="tournament-type-card">
                            <div class="type-icon team"><i class="bi bi-diagram-3"></i></div>
                            <div class="type-info">
                                <div class="type-name">Групповой</div>
                                <div class="type-count">{{ $tournamentStats['by_type']['team'] ?? 0 }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                @if($tournamentStats['wins'] > 0)
                <div class="tournament-wins-banner">
                    <i class="bi bi-trophy-fill"></i>
                    <span>Победитель {{ $tournamentStats['wins'] }} {{ trans_choice('турнира|турниров|турниров', $tournamentStats['wins']) }}!</span>
                </div>
                @endif
            </div>
        </div>

        <!-- Последние матчи -->
        @if(count($matchHistory) > 0)
        <div class="card-dark">
            <div class="card-header-dark d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Последние матчи</h5>
            </div>
            <div class="card-body-dark">
                <div class="matches-list">
                    @foreach($matchHistory as $match)
                        <div class="match-row {{ $match['won'] ? 'won' : 'lost' }}">
                            <div class="match-result">
                                @if($match['won'])
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                @else
                                    <i class="bi bi-x-circle-fill text-danger"></i>
                                @endif
                            </div>
                            <div class="match-info">
                                <div class="match-tournament">
                                    <span class="match-type">{{ $match['type'] }}</span>
                                    {{ $match['tournament'] }}
                                </div>
                                <div class="match-players-row">
                                    с {{ $match['partner'] }} vs {{ $match['opponents'] }}
                                </div>
                            </div>
                            <div class="match-score {{ $match['won'] ? 'won' : 'lost' }}">
                                {{ $match['score'] }}
                            </div>
                            <div class="match-date-col">
                                {{ $match['date']->format('d.m') }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
    </div>
</div>

<style>
.card-header-dark, .card-body-dark{
	padding:20px;
}
/* Карточка профиля */
.profile-card-main {
    background: linear-gradient(135deg, #1a472a 0%, #0f2618 100%);
    border-radius: 20px;
    overflow: hidden;
    border: 1px solid rgba(34, 197, 94, 0.3);
}

.profile-card-header {
    position: relative;
    padding: 30px 20px 20px;
    text-align: center;
    background: linear-gradient(180deg, rgba(34, 197, 94, 0.2) 0%, transparent 100%);
}

.profile-avatar-large {
    width: 100px;
    height: 100px;
    background: linear-gradient(135deg, var(--accent) 0%, #16a34a 100%);
    border-radius: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0 auto;
    box-shadow: 0 8px 32px rgba(34, 197, 94, 0.3);
}

.profile-rank-badge {
    position: absolute;
    top: 20px;
    right: 20px;
    background: rgba(255,255,255,0.15);
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.9rem;
}

.profile-card-body {
    padding: 0 20px 20px;
    text-align: center;
}

.profile-name {
    font-size: 1.4rem;
    font-weight: 700;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.profile-email {
    color: rgba(255,255,255,0.6);
    margin-bottom: 16px;
    font-size: 0.9rem;
}

.profile-level-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.15);
    padding: 8px 16px;
    border-radius: 20px;
    font-weight: 500;
}

.profile-streak {
    margin-top: 12px;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.profile-streak.win {
    background: rgba(34, 197, 94, 0.2);
    color: var(--accent);
}

.profile-streak.loss {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

.profile-stats-row {
    display: flex;
    border-top: 1px solid rgba(255,255,255,0.1);
}

.profile-stat {
    flex: 1;
    padding: 20px 10px;
    text-align: center;
    border-right: 1px solid rgba(255,255,255,0.1);
}

.profile-stat:last-child {
    border-right: none;
}

.profile-stat-value {
    font-size: 1.5rem;
    font-weight: 700;
}

.profile-stat-value.text-accent {
    color: var(--accent);
}

.profile-stat-label {
    font-size: 0.75rem;
    color: rgba(255,255,255,0.6);
    margin-top: 4px;
}

/* Статистика */
.stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: rgba(255,255,255,0.03);
    border-radius: 12px;
}

.stat-item.full-width {
    grid-column: 1 / -1;
    flex-wrap: wrap;
}

.stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.stat-icon.win { background: rgba(34, 197, 94, 0.2); color: var(--accent); }
.stat-icon.loss { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
.stat-icon.winrate { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }

.stat-number {
    font-size: 1.3rem;
    font-weight: 700;
}

.stat-text {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

.winrate-bar {
    width: 100%;
    height: 6px;
    background: rgba(255,255,255,0.1);
    border-radius: 3px;
    margin-top: 12px;
    overflow: hidden;
}

.winrate-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--accent), #16a34a);
    border-radius: 3px;
    transition: width 0.5s ease;
}

/* Партнёр */
.partner-card {
    display: flex;
    align-items: center;
    gap: 14px;
}

.partner-avatar {
    width: 52px;
    height: 52px;
    background: linear-gradient(135deg, var(--accent), #16a34a);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.partner-name {
    font-weight: 600;
    font-size: 1.05rem;
}

.partner-stats {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-top: 2px;
}

/* Информация */
.info-row {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    color: var(--text-secondary);
}

.info-value {
    font-weight: 500;
}

/* Достижения */
.achievements-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
}

.achievement-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px 12px;
    background: rgba(255,255,255,0.03);
    border-radius: 12px;
    text-align: center;
    transition: all 0.2s;
    cursor: default;
}

.achievement-card:hover {
    background: rgba(255,255,255,0.06);
    transform: translateY(-2px);
}

.achievement-icon {
    font-size: 2rem;
    margin-bottom: 8px;
}

.achievement-name {
    font-weight: 600;
    font-size: 0.9rem;
}

.achievement-desc {
    font-size: 0.7rem;
    color: var(--text-secondary);
    margin-top: 4px;
}

/* График рейтинга */
.rating-chart-container {
    height: 180px;
}

.rating-history-compact {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.history-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    background: rgba(255,255,255,0.02);
    border-radius: 8px;
    font-size: 0.85rem;
}

.history-date {
    color: var(--text-secondary);
    min-width: 45px;
}

.history-name {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.history-change {
    font-weight: 700;
    min-width: 45px;
    text-align: right;
}

.history-change.positive { color: var(--accent); }
.history-change.negative { color: #ef4444; }

.history-total {
    color: var(--text-secondary);
    min-width: 45px;
    text-align: right;
}

/* Турниры */
.tournaments-overview {
    display: flex;
    gap: 20px;
    align-items: center;
}

.tournament-total {
    text-align: center;
    padding: 20px 30px;
    background: rgba(255,255,255,0.03);
    border-radius: 16px;
}

.tournament-total-number {
    font-size: 3rem;
    font-weight: 700;
    color: var(--accent);
    line-height: 1;
}

.tournament-total-label {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-top: 8px;
}

.tournament-breakdown {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.tournament-type-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: rgba(255,255,255,0.03);
    border-radius: 10px;
}

.type-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.type-icon.americano { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
.type-icon.mexicano { background: rgba(234, 179, 8, 0.2); color: #eab308; }
.type-icon.team { background: rgba(168, 85, 247, 0.2); color: #a855f7; }

.type-name {
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.type-count {
    font-weight: 700;
    font-size: 1.1rem;
}

.tournament-wins-banner {
    margin-top: 16px;
    padding: 14px;
    background: linear-gradient(90deg, rgba(234, 179, 8, 0.2), rgba(234, 179, 8, 0.05));
    border: 1px solid rgba(234, 179, 8, 0.3);
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #eab308;
    font-weight: 600;
}

/* Матчи */
.matches-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.match-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px;
    background: rgba(255,255,255,0.02);
    border-radius: 10px;
    border-left: 3px solid transparent;
}

.match-row.won { border-left-color: var(--accent); }
.match-row.lost { border-left-color: #ef4444; }

.match-result {
    font-size: 1.2rem;
}

.match-info {
    flex: 1;
    min-width: 0;
}

.match-tournament {
    font-weight: 600;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.match-type {
    background: rgba(255,255,255,0.1);
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
}

.match-players-row {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-top: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.match-score {
    font-weight: 700;
    font-size: 1.1rem;
    padding: 6px 12px;
    border-radius: 8px;
}

.match-score.won { background: rgba(34, 197, 94, 0.15); color: var(--accent); }
.match-score.lost { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

.match-date-col {
    font-size: 0.8rem;
    color: var(--text-secondary);
    min-width: 40px;
    text-align: right;
}

@media (max-width: 768px) {
    .tournaments-overview {
        flex-direction: column;
    }
    
    .tournament-total {
        width: 100%;
    }
    
    .match-players-row {
        display: none;
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
    
    if (data.length > 0) {
        labels.unshift('');
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
                tension: 0.4,
                pointBackgroundColor: '#22c55e',
                pointBorderColor: '#0f2618',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: {
                    grid: { color: 'rgba(255,255,255,0.03)' },
                    ticks: { color: '#6b7280', font: { size: 10 } }
                },
                y: {
                    grid: { color: 'rgba(255,255,255,0.03)' },
                    ticks: { color: '#6b7280' }
                }
            }
        }
    });
});
</script>
@endif
@endsection
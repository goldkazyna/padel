<div class="info-grid mb-4">
    <div class="info-card">
        <div class="info-icon"><i class="bi bi-calendar3"></i></div>
        <div class="info-content">
            <div class="info-label">Дата</div>
            <div class="info-value">{{ $tournament->start_date->format('d.m.Y H:i') }}</div>
        </div>
    </div>

    <div class="info-card">
        <div class="info-icon"><i class="bi bi-bar-chart"></i></div>
        <div class="info-content">
            <div class="info-label">Уровень</div>
            <div class="info-value">{{ $tournament->min_level }} — {{ $tournament->max_level }}</div>
        </div>
    </div>
    
    <div class="info-card">
        <div class="info-icon"><i class="bi bi-people-fill"></i></div>
        <div class="info-content">
            <div class="info-label">Участников</div>
            <div class="info-value">{{ $tournament->participants->count() }} / {{ $tournament->max_participants }}</div>
        </div>
    </div>
    
    <div class="info-card">
        <div class="info-icon"><i class="bi bi-cash"></i></div>
        <div class="info-content">
            <div class="info-label">Стоимость</div>
            <div class="info-value">{{ $tournament->price > 0 ? number_format($tournament->price, 0) . ' ₸' : 'Бесплатно' }}</div>
        </div>
    </div>
    
    
    <div class="info-card status">
        <span class="badge-{{ $tournament->status_color }}-custom">{{ $tournament->status_name }}</span>
    </div>
</div>
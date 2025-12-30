@extends('layouts.app')

@section('title', 'Клубы')

@section('content')
<div class="page-header">
    <div>
        <h2>Клубы</h2>
        <p>Управление клубами платформы</p>
    </div>
    <a href="{{ route('admin.clubs.create') }}" class="btn-primary-custom">
        <i class="bi bi-plus-circle"></i>
        <span>Добавить клуб</span>
    </a>
</div>

<div class="clubs-list">
    @forelse($clubs as $club)
        <div class="club-row">
            <div class="club-icon">
                <i class="bi bi-building"></i>
            </div>
            <div class="club-info">
                <div class="club-name">{{ $club->name }}</div>
                <div class="club-meta">
                    <span><i class="bi bi-geo-alt"></i> {{ $club->address }}</span>
                    @if($club->phone)
                        <span class="d-none d-md-inline"><i class="bi bi-telephone"></i> {{ $club->phone }}</span>
                    @endif
                </div>
            </div>
            <div class="club-stats d-none d-lg-flex">
                <div class="club-stat">
                    <div class="club-stat-value">{{ $club->admins_count }}</div>
                    <div class="club-stat-label">Админов</div>
                </div>
                <div class="club-stat">
                    <div class="club-stat-value">{{ $club->tournaments_count ?? 0 }}</div>
                    <div class="club-stat-label">Турниров</div>
                </div>
            </div>
            <div class="club-status">
                @if($club->is_active)
                    <span class="badge-success-custom">Активен</span>
                @else
                    <span class="badge-secondary-custom">Неактивен</span>
                @endif
            </div>
            <div class="club-actions">
                <a href="{{ route('admin.clubs.admins', $club) }}" class="btn-outline-custom btn-sm" title="Админы">
                    <i class="bi bi-people"></i>
                </a>
                <a href="{{ route('admin.clubs.edit', $club) }}" class="btn-outline-custom btn-sm" title="Редактировать">
                    <i class="bi bi-pencil"></i>
                </a>
                <form action="{{ route('admin.clubs.destroy', $club) }}" method="POST" class="d-inline" 
                      onsubmit="return confirm('Удалить клуб?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-danger-custom btn-sm" title="Удалить">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            </div>
        </div>
    @empty
        <div class="card-dark">
            <div class="card-body text-center py-5">
                <i class="bi bi-buildings fs-1 text-secondary mb-3"></i>
                <p class="text-secondary mb-0">Клубов пока нет</p>
            </div>
        </div>
    @endforelse
</div>

<style>
.clubs-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.club-row {
    display: flex;
    align-items: center;
    padding: 16px 20px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border);
    gap: 16px;
    transition: all 0.2s;
}

.club-row:hover {
    border-color: var(--accent);
    transform: translateX(4px);
}

.club-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--accent) 0%, #16a34a 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}

.club-info {
    flex: 1;
    min-width: 0;
}

.club-name {
    font-weight: 600;
    margin-bottom: 4px;
}

.club-meta {
    display: flex;
    gap: 16px;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.club-meta i {
    margin-right: 4px;
}

.club-stats {
    display: flex;
    gap: 24px;
}

.club-stat {
    text-align: center;
}

.club-stat-value {
    font-weight: 700;
    font-size: 1.1rem;
}

.club-stat-label {
    font-size: 0.7rem;
    color: var(--text-secondary);
}

.club-status {
    min-width: 90px;
    text-align: center;
}

.club-actions {
    display: flex;
    gap: 8px;
}

@media (max-width: 768px) {
    .club-row {
        flex-wrap: wrap;
        padding: 14px 16px;
    }
    
    .club-icon {
        width: 42px;
        height: 42px;
        font-size: 1.1rem;
    }
    
    .club-info {
        flex: 1;
        min-width: calc(100% - 130px);
    }
    
    .club-meta {
        flex-direction: column;
        gap: 4px;
    }
    
    .club-status {
        order: 3;
        min-width: auto;
    }
    
    .club-actions {
        order: 4;
        margin-left: auto;
    }
}
</style>
@endsection
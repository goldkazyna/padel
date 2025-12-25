@extends('layouts.app')

@section('title', 'Панель супер-админа')

@section('content')
<div class="page-header">
    <div>
        <h2>Панель управления</h2>
        <p>Статистика платформы</p>
    </div>
    <a href="{{ route('admin.clubs.create') }}" class="btn-primary-custom">
        <i class="bi bi-plus-circle"></i>
        <span>Добавить клуб</span>
    </a>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">{{ \App\Models\User::count() }}</div>
                <div class="stat-label">Игроков</div>
            </div>
            <div class="stat-icon blue">
                <i class="bi bi-people-fill"></i>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">{{ \App\Models\Club::count() }}</div>
                <div class="stat-label">Клубов</div>
            </div>
            <div class="stat-icon green">
                <i class="bi bi-buildings"></i>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">0</div>
                <div class="stat-label">Турниров</div>
            </div>
            <div class="stat-icon purple">
                <i class="bi bi-trophy-fill"></i>
            </div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">0</div>
                <div class="stat-label">Матчей</div>
            </div>
            <div class="stat-icon orange">
                <i class="bi bi-controller"></i>
            </div>
        </div>
    </div>
</div>

<!-- Recent clubs -->
<div class="card-dark">
    <div class="card-header">
        <h5><i class="bi bi-buildings"></i> Клубы</h5>
        <a href="{{ route('admin.clubs.index') }}" class="btn-outline-custom">Все клубы</a>
    </div>
    <div class="card-body">
        @php $clubs = \App\Models\Club::latest()->take(5)->get(); @endphp
        @if($clubs->count() > 0)
            <div class="table-responsive">
                <table class="table table-dark-custom mb-0">
                    <thead>
                        <tr>
                            <th>Название</th>
                            <th>Адрес</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($clubs as $club)
                            <tr>
                                <td>{{ $club->name }}</td>
                                <td class="text-secondary">{{ $club->address }}</td>
                                <td>
                                    @if($club->is_active)
                                        <span class="badge-success-custom">Активен</span>
                                    @else
                                        <span class="badge-secondary-custom">Неактивен</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-secondary mb-0">Клубов пока нет</p>
        @endif
    </div>
</div>
@endsection
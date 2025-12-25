@extends('layouts.app')

@section('title', 'Главная')

@section('content')
<div class="page-header">
    <div>
        <h2>Добро пожаловать, {{ auth()->user()->first_name }}! 👋</h2>
        <p>Вот что происходит с твоей игрой</p>
    </div>
    <a href="#" class="btn-primary-custom">
        <i class="bi bi-plus-circle"></i>
        <span>Записаться на турнир</span>
    </a>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">{{ auth()->user()->rating }}</div>
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
                <div class="stat-value">{{ auth()->user()->level }}</div>
                <div class="stat-label">Уровень</div>
            </div>
            <div class="stat-icon blue">
                <i class="bi bi-graph-up-arrow"></i>
            </div>
        </div>
        <div class="mt-2">
            <span class="badge-success-custom">{{ auth()->user()->level_name }}</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div>
                <div class="stat-value">0</div>
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
                <div class="stat-value">0%</div>
                <div class="stat-label">Винрейт</div>
            </div>
            <div class="stat-icon orange">
                <i class="bi bi-percent"></i>
            </div>
        </div>
    </div>
</div>

<!-- Tournaments -->
<div class="card-dark">
    <div class="card-header">
        <h5><i class="bi bi-calendar-event"></i> Ближайшие турниры</h5>
        <a href="#" class="btn-outline-custom">Все турниры</a>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-0">Пока нет доступных турниров. Скоро здесь появятся!</p>
    </div>
</div>
@endsection
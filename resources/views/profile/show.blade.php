@extends('layouts.app')

@section('title', 'Мой профиль')

@section('content')
<div class="page-header">
    <div>
        <h2>Мой профиль</h2>
        <p>Информация о вашем аккаунте</p>
    </div>
    <a href="{{ route('profile.edit') }}" class="btn-primary-custom">
        <i class="bi bi-pencil"></i>
        <span>Редактировать</span>
    </a>
</div>

<div class="row g-4">
    <!-- Profile card -->
    <div class="col-lg-4">
        <div class="profile-card-gradient mb-4">
            <div class="profile-avatar-large">
                {{ strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1)) }}
            </div>
            <h4 class="mb-1">{{ $user->full_name }}</h4>
            <p class="mb-3 opacity-75">{{ $user->email }}</p>
            <div class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-pill" style="background: rgba(255,255,255,0.2);">
                <i class="bi bi-award"></i>
                {{ $user->level_name }} ({{ $user->level }})
            </div>
            <div class="d-flex gap-4 mt-4 pt-4" style="border-top: 1px solid rgba(255,255,255,0.2);">
                <div class="text-center flex-fill">
                    <div class="fs-4 fw-bold">{{ $user->rating }}</div>
                    <small class="opacity-75">Рейтинг</small>
                </div>
                <div class="text-center flex-fill">
                    <div class="fs-4 fw-bold">0</div>
                    <small class="opacity-75">Матчей</small>
                </div>
                <div class="text-center flex-fill">
                    <div class="fs-4 fw-bold">0</div>
                    <small class="opacity-75">Турниров</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Info card -->
    <div class="col-lg-8">
        <div class="card-dark">
            <div class="card-header">
                <h5><i class="bi bi-person-badge"></i> Личные данные</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4 text-secondary">Имя</div>
                    <div class="col-md-8">{{ $user->first_name }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 text-secondary">Фамилия</div>
                    <div class="col-md-8">{{ $user->last_name }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 text-secondary">Email</div>
                    <div class="col-md-8">{{ $user->email }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 text-secondary">Телефон</div>
                    <div class="col-md-8">{{ $user->phone ?? '—' }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 text-secondary">Дата рождения</div>
                    <div class="col-md-8">{{ $user->birth_date?->format('d.m.Y') ?? '—' }}</div>
                </div>
                <div class="row">
                    <div class="col-md-4 text-secondary">Пол</div>
                    <div class="col-md-8">
                        @if($user->gender === 'male') Мужской
                        @elseif($user->gender === 'female') Женский
                        @else — @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
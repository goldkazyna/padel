@extends('layouts.app')

@section('title', 'Мой профиль')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Мой профиль</h5>
                    <a href="{{ route('profile.edit') }}" class="btn btn-primary btn-sm">
                        <i class="bi bi-pencil me-1"></i> Редактировать
                    </a>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">Имя</div>
                        <div class="col-md-8">{{ $user->first_name }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">Фамилия</div>
                        <div class="col-md-8">{{ $user->last_name }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">Email</div>
                        <div class="col-md-8">{{ $user->email }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">Телефон</div>
                        <div class="col-md-8">{{ $user->phone ?? '—' }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">Дата рождения</div>
                        <div class="col-md-8">{{ $user->birth_date?->format('d.m.Y') ?? '—' }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">Пол</div>
                        <div class="col-md-8">
                            @if($user->gender === 'male') Мужской
                            @elseif($user->gender === 'female') Женский
                            @else — @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card mb-3">
                <div class="card-body text-center">
                    <div class="display-4 text-primary">{{ $user->rating }}</div>
                    <div class="text-muted">Рейтинг</div>
                </div>
            </div>

            <div class="card">
                <div class="card-body text-center">
                    <div class="display-4 text-success">{{ $user->level }}</div>
                    <div class="text-muted">Уровень</div>
                    <span class="badge bg-secondary mt-2">{{ $user->level_name }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
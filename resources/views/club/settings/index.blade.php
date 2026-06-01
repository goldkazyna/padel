@extends('layouts.app')

@section('title', 'Настройки')

@section('content')
<div class="page-header">
    <div>
        <h2>Настройки</h2>
        <p>Ваш аккаунт</p>
    </div>
</div>

@if(session('success'))
    <div class="alert-success-custom mb-4">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="alert-danger-custom mb-4">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row">
    <div class="col-lg-6">
        {{-- Профиль --}}
        <div class="card-dark mb-4">
            <div class="card-body">
                <h5 class="mb-4">Профиль</h5>
                <form action="{{ route('club.settings.profile') }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="mb-4">
                        <label class="form-label">Имя</label>
                        <input type="text" name="name" class="form-control"
                               value="{{ old('name', $user->name) }}" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Телефон</label>
                        <input type="text" class="form-control" value="@phoneFmt($user->phone)" disabled>
                        <small class="text-secondary">Телефон менять нельзя — это логин в систему</small>
                    </div>
                    <button type="submit" class="btn-primary-custom">Сохранить</button>
                </form>
            </div>
        </div>

        {{-- Смена пароля --}}
        <div class="card-dark">
            <div class="card-body">
                <h5 class="mb-4">Смена пароля</h5>
                <form action="{{ route('club.settings.password') }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="mb-4">
                        <label class="form-label">Текущий пароль</label>
                        <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Новый пароль</label>
                        <input type="password" name="password" class="form-control" required autocomplete="new-password">
                        <small class="text-secondary">Минимум 6 символов</small>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Повторите новый пароль</label>
                        <input type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn-primary-custom">Изменить пароль</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

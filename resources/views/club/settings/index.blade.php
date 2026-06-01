@extends('layouts.app')

@section('title', 'Настройки')

@section('content')

<div class="settings-container">
    <div class="settings-page-header">
        <h1 class="settings-page-title">Настройки</h1>
        <p class="settings-page-sub">Ваш аккаунт</p>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="flash-message flash-error">
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    {{-- Профиль --}}
    <form action="{{ route('club.settings.profile') }}" method="POST" class="settings-card">
        @csrf
        @method('PUT')
        <h2 class="settings-card-title">Профиль</h2>

        <div class="form-group">
            <label class="form-label">Имя</label>
            <input type="text" name="name" class="form-input" value="{{ old('name', $user->name) }}" required>
        </div>

        <div class="form-group">
            <label class="form-label">Телефон</label>
            <input type="text" class="form-input form-input-readonly" value="@phoneFmt($user->phone)" readonly>
            <small class="form-hint">Телефон менять нельзя — это логин в систему</small>
        </div>

        <div class="settings-actions">
            <button type="submit" class="btn-save">Сохранить</button>
        </div>
    </form>

    {{-- Смена пароля --}}
    <form action="{{ route('club.settings.password') }}" method="POST" class="settings-card">
        @csrf
        @method('PUT')
        <h2 class="settings-card-title">Смена пароля</h2>

        <div class="form-group">
            <label class="form-label">Текущий пароль</label>
            <input type="password" name="current_password" class="form-input" required autocomplete="current-password">
        </div>

        <div class="form-group">
            <label class="form-label">Новый пароль</label>
            <input type="password" name="password" class="form-input" required autocomplete="new-password">
            <small class="form-hint">Минимум 6 символов</small>
        </div>

        <div class="form-group">
            <label class="form-label">Повторите новый пароль</label>
            <input type="password" name="password_confirmation" class="form-input" required autocomplete="new-password">
        </div>

        <div class="settings-actions">
            <button type="submit" class="btn-save">Изменить пароль</button>
        </div>
    </form>
</div>

<style>
    .settings-container { max-width: 640px; margin: 0 auto; padding: 32px 24px; }
    .settings-page-header { margin-bottom: 24px; }
    .settings-page-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; margin: 0; }
    .settings-page-sub { color: #71717a; font-size: 14px; margin: 4px 0 0; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }

    .settings-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; padding: 24px; margin-bottom: 20px; }
    .settings-card-title { font-size: 17px; font-weight: 800; margin: 0 0 20px; color: #f4f4f5; }

    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-input-readonly { background: #0e0e10; color: #71717a; cursor: not-allowed; }
    .form-input-readonly:focus { border-color: #27272a; box-shadow: none; }
    .form-hint { color: #52525b; font-size: 11px; display: block; margin-top: 6px; }

    .settings-actions { margin-top: 4px; }
    .btn-save { padding: 13px 28px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }
</style>
@endsection

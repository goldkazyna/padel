@extends('layouts.guest')

@section('content')
<div class="auth-card">
    <div class="text-center mb-4">
        <div class="telegram-avatar mb-3">
            @if(!empty($telegramData['photo_url']))
                <img src="{{ $telegramData['photo_url'] }}" alt="Avatar" class="rounded-circle" width="80">
            @else
                <div class="avatar-placeholder">
                    <i class="bi bi-telegram"></i>
                </div>
            @endif
        </div>
        <h4>Привет, {{ $telegramData['first_name'] ?? 'друг' }}!</h4>
        <p class="text-secondary">Завершите регистрацию</p>
    </div>

    <form method="POST" action="{{ route('auth.telegram.complete') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
            <input type="email" class="form-control @error('email') is-invalid @enderror" 
                   id="email" name="email" value="{{ old('email') }}" required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <label for="phone" class="form-label">Телефон</label>
            <input type="tel" class="form-control @error('phone') is-invalid @enderror" 
                   id="phone" name="phone" value="{{ old('phone') }}">
            @error('phone')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-check-lg me-2"></i>Завершить регистрацию
        </button>
    </form>

    <div class="mt-3 text-center">
        <a href="{{ route('login') }}" class="text-secondary">
            <i class="bi bi-arrow-left me-1"></i>Назад к входу
        </a>
    </div>
</div>

<style>
.avatar-placeholder {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #0088cc, #29b6f6);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    font-size: 2.5rem;
    color: #fff;
}
</style>
@endsection

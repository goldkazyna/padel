@extends('layouts.app')

@section('title', 'Редактировать профиль')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Редактировать профиль</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('profile.update') }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label class="form-label">Имя *</label>
                            <input type="text" name="first_name" class="form-control @error('first_name') is-invalid @enderror" 
                                   value="{{ old('first_name', $user->first_name) }}" required>
                            @error('first_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Фамилия *</label>
                            <input type="text" name="last_name" class="form-control @error('last_name') is-invalid @enderror" 
                                   value="{{ old('last_name', $user->last_name) }}" required>
                            @error('last_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Телефон</label>
                            <input type="text" name="phone" class="form-control @error('phone') is-invalid @enderror" 
                                   value="{{ old('phone', $user->phone) }}" placeholder="+7 777 123 4567">
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Дата рождения</label>
                            <input type="date" name="birth_date" class="form-control @error('birth_date') is-invalid @enderror" 
                                   value="{{ old('birth_date', $user->birth_date?->format('Y-m-d')) }}">
                            @error('birth_date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Пол</label>
                            <select name="gender" class="form-select @error('gender') is-invalid @enderror">
                                <option value="">— Не указан —</option>
                                <option value="male" {{ old('gender', $user->gender) === 'male' ? 'selected' : '' }}>Мужской</option>
                                <option value="female" {{ old('gender', $user->gender) === 'female' ? 'selected' : '' }}>Женский</option>
                            </select>
                            @error('gender')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> Сохранить
                            </button>
                            <a href="{{ route('profile.show') }}" class="btn btn-secondary">Отмена</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
@extends('layouts.app')

@section('title', 'Редактировать турнир')

@section('content')
<div class="page-header">
    <div>
        <h2>Редактировать турнир</h2>
        <p>{{ $tournament->name }}</p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card-dark">
            <div class="card-body">
                <form action="{{ route('club.tournaments.update', $tournament) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="mb-4">
                        <label class="form-label">Название *</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $tournament->name) }}" required>
                        @error('name')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Описание</label>
                        <textarea name="description" class="form-control" rows="3">{{ old('description', $tournament->description) }}</textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Дата и время *</label>
                            <input type="datetime-local" name="start_date" class="form-control" 
                                   value="{{ old('start_date', $tournament->start_date->format('Y-m-d\TH:i')) }}" required>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Дедлайн регистрации *</label>
                            <input type="datetime-local" name="registration_deadline" class="form-control" 
                                   value="{{ old('registration_deadline', $tournament->registration_deadline->format('Y-m-d\TH:i')) }}" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Мин. уровень *</label>
                            <select name="min_level" class="form-select" required>
                                @for($i = 1; $i <= 5.75; $i += 0.25)
                                    <option value="{{ number_format($i, 2) }}" {{ old('min_level', $tournament->min_level) == number_format($i, 2) ? 'selected' : '' }}>
                                        {{ number_format($i, 2) }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Макс. уровень *</label>
                            <select name="max_level" class="form-select" required>
                                @for($i = 1; $i <= 5.75; $i += 0.25)
                                    <option value="{{ number_format($i, 2) }}" {{ old('max_level', $tournament->max_level) == number_format($i, 2) ? 'selected' : '' }}>
                                        {{ number_format($i, 2) }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Макс. участников *</label>
                            <input type="number" name="max_participants" class="form-control" 
                                   value="{{ old('max_participants', $tournament->max_participants) }}" min="2" max="128" required>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Стоимость (₸)</label>
                            <input type="number" name="price" class="form-control" 
                                   value="{{ old('price', $tournament->price) }}" min="0">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Статус *</label>
                        <select name="status" class="form-select" required>
                            <option value="draft" {{ old('status', $tournament->status) === 'draft' ? 'selected' : '' }}>Черновик</option>
                            <option value="open" {{ old('status', $tournament->status) === 'open' ? 'selected' : '' }}>Открыта регистрация</option>
                            <option value="closed" {{ old('status', $tournament->status) === 'closed' ? 'selected' : '' }}>Регистрация закрыта</option>
                            <option value="in_progress" {{ old('status', $tournament->status) === 'in_progress' ? 'selected' : '' }}>Идёт турнир</option>
                            <option value="completed" {{ old('status', $tournament->status) === 'completed' ? 'selected' : '' }}>Завершён</option>
                            <option value="cancelled" {{ old('status', $tournament->status) === 'cancelled' ? 'selected' : '' }}>Отменён</option>
                        </select>
                    </div>

                    <div class="d-flex gap-3">
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-check-lg"></i> Сохранить
                        </button>
                        <a href="{{ route('club.tournaments.index') }}" class="btn-outline-custom">Отмена</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
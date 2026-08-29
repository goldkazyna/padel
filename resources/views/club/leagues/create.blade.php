@extends('layouts.app')

@section('title', 'Создать лигу')

@section('content')
<div class="page-header">
    <div>
        <h2>Создать лигу</h2>
        <p>Серия турниров с общим составом и одной таблицей</p>
    </div>
    <a href="{{ route('club.leagues.index') }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i> К списку
    </a>
</div>

<form method="POST" action="{{ route('club.leagues.store') }}" class="league-form">
    @csrf

    <div class="card-dark mb-4">
        <div class="card-body">
            <div class="alert-info-custom mb-4">
                <i class="bi bi-info-circle me-2"></i>
                Этапы лиги — обычные турниры <b>Americano Flex</b>: проводятся, судятся и
                начисляют рейтинг как всегда. Лига добавляет общий состав и сводную
                таблицу, где очки складываются за все этапы.
            </div>

            <div class="mb-4">
                <label class="form-label">Название лиги *</label>
                <input type="text" name="name" class="form-control-custom" required
                       value="{{ old('name') }}" placeholder="Например: Сентябрь Кап">
                @error('name')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>

            <div class="mb-4">
                <label class="form-label">Описание</label>
                <textarea name="description" rows="3" class="form-control-custom"
                          placeholder="Регламент, призы, расписание этапов">{{ old('description') }}</textarea>
            </div>

            <div class="row">
                <div class="col-md-4 mb-4">
                    <label class="form-label">Сколько этапов *</label>
                    <input type="number" name="stages_planned" class="form-control-custom" required
                           min="2" max="30" value="{{ old('stages_planned', 8) }}">
                    <small class="form-hint">Можно добавить больше или меньше по ходу.</small>
                </div>
                <div class="col-md-4 mb-4">
                    <label class="form-label">Начало</label>
                    <input type="datetime-local" name="start_date" class="form-control-custom"
                           value="{{ old('start_date') }}">
                </div>
                <div class="col-md-4 mb-4">
                    <label class="form-label">Окончание</label>
                    <input type="datetime-local" name="end_date" class="form-control-custom"
                           value="{{ old('end_date') }}">
                </div>
            </div>

            <div class="row">
                <div class="col-md-3 mb-4">
                    <label class="form-label">Уровень от</label>
                    <input type="number" step="0.25" min="1" max="7" name="min_level"
                           class="form-control-custom" value="{{ old('min_level') }}">
                </div>
                <div class="col-md-3 mb-4">
                    <label class="form-label">Уровень до</label>
                    <input type="number" step="0.25" min="1" max="7" name="max_level"
                           class="form-control-custom" value="{{ old('max_level') }}">
                </div>
                <div class="col-md-3 mb-4">
                    <label class="form-label">Мест в лиге</label>
                    <input type="number" name="max_players" class="form-control-custom"
                           min="4" max="200" value="{{ old('max_players') }}">
                </div>
                <div class="col-md-3 mb-4">
                    <label class="form-label">Цена этапа, ₸</label>
                    <input type="number" name="price" class="form-control-custom"
                           min="0" value="{{ old('price') }}">
                    <small class="form-hint">Подставится в каждый этап.</small>
                </div>
            </div>

            <label class="checkbox-custom">
                <input type="checkbox" name="is_rated" value="1" {{ old('is_rated', true) ? 'checked' : '' }}>
                <span>Этапы влияют на рейтинг</span>
            </label>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn-primary-custom">
            <i class="bi bi-check2"></i> Создать лигу
        </button>
        <a href="{{ route('club.leagues.index') }}" class="btn-outline-custom">Отмена</a>
    </div>
</form>

<style>
.league-form .form-hint { display: block; margin-top: 6px; font-size: 12px; color: var(--text-secondary); }
.checkbox-custom { display: flex; align-items: center; gap: 10px; cursor: pointer; }
</style>
@endsection

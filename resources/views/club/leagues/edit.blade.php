@extends('layouts.app')

@section('title', 'Изменить лигу')

@section('content')
<div class="page-header">
    <div>
        <h2>Изменить лигу</h2>
        <p>{{ $league->name }}</p>
    </div>
    <a href="{{ route('club.leagues.show', $league) }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i> Назад
    </a>
</div>

<form method="POST" action="{{ route('club.leagues.update', $league) }}">
    @csrf
    @method('PUT')

    <div class="card-dark mb-4">
        <div class="card-body">
            <div class="mb-4">
                <label class="form-label">Название лиги *</label>
                <input type="text" name="name" class="form-control-custom" required
                       value="{{ old('name', $league->name) }}">
            </div>

            <div class="mb-4">
                <label class="form-label">Описание</label>
                <textarea name="description" rows="3" class="form-control-custom">{{ old('description', $league->description) }}</textarea>
            </div>

            <div class="row">
                <div class="col-md-4 mb-4">
                    <label class="form-label">Сколько этапов *</label>
                    <input type="number" name="stages_planned" class="form-control-custom" required
                           min="2" max="30" value="{{ old('stages_planned', $league->stages_planned) }}">
                </div>
                <div class="col-md-4 mb-4">
                    <label class="form-label">Начало</label>
                    <input type="datetime-local" name="start_date" class="form-control-custom"
                           value="{{ old('start_date', $league->start_date?->format('Y-m-d\TH:i')) }}">
                </div>
                <div class="col-md-4 mb-4">
                    <label class="form-label">Окончание</label>
                    <input type="datetime-local" name="end_date" class="form-control-custom"
                           value="{{ old('end_date', $league->end_date?->format('Y-m-d\TH:i')) }}">
                </div>
            </div>

            <div class="row">
                <div class="col-md-3 mb-4">
                    <label class="form-label">Уровень от</label>
                    <input type="number" step="0.25" min="1" max="7" name="min_level"
                           class="form-control-custom" value="{{ old('min_level', $league->min_level) }}">
                </div>
                <div class="col-md-3 mb-4">
                    <label class="form-label">Уровень до</label>
                    <input type="number" step="0.25" min="1" max="7" name="max_level"
                           class="form-control-custom" value="{{ old('max_level', $league->max_level) }}">
                </div>
                <div class="col-md-3 mb-4">
                    <label class="form-label">Мест в лиге</label>
                    <input type="number" name="max_players" class="form-control-custom"
                           min="4" max="200" value="{{ old('max_players', $league->max_players) }}">
                </div>
                <div class="col-md-3 mb-4">
                    <label class="form-label">Цена этапа, ₸</label>
                    <input type="number" name="price" class="form-control-custom"
                           min="0" value="{{ old('price', $league->price) }}">
                </div>
            </div>

            <label class="d-flex align-items-center gap-2">
                <input type="checkbox" name="is_rated" value="1" {{ old('is_rated', $league->is_rated) ? 'checked' : '' }}>
                <span>Этапы влияют на рейтинг</span>
            </label>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn-primary-custom"><i class="bi bi-check2"></i> Сохранить</button>
        <a href="{{ route('club.leagues.show', $league) }}" class="btn-outline-custom">Отмена</a>
    </div>
</form>

<div class="card-dark">
    <div class="card-body">
        <label class="form-label">Статус лиги</label>
        <form method="POST" action="{{ route('club.leagues.status', $league) }}" class="d-flex gap-2 align-items-center">
            @csrf
            <select name="status" class="form-control-custom" style="max-width: 260px;">
                @foreach(\App\Models\League::STATUSES as $value => $label)
                    <option value="{{ $value }}" {{ $league->status === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-outline-custom">Применить</button>
        </form>
        <small class="text-secondary d-block mt-2">
            Завершённая лига остаётся видимой: таблица и этапы никуда не деваются.
        </small>
    </div>
</div>
@endsection

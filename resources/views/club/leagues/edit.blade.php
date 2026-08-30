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

<div class="row">
    <div class="col-lg-8">
        <div class="card-dark">
            <div class="card-body">
                @if($errors->any())
                    <div class="alert-danger-custom mb-4">
                        <ul class="mb-0">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('club.leagues.update', $league) }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-4">
                        <label class="form-label">Название лиги *</label>
                        <input type="text" name="name" class="form-control" required
                               value="{{ old('name', $league->name) }}">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Описание</label>
                        <textarea name="description" class="form-control" rows="3">{{ old('description', $league->description) }}</textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <label class="form-label">Сколько этапов *</label>
                            <input type="number" name="stages_planned" class="form-control" required
                                   min="2" max="30" value="{{ old('stages_planned', $league->stages_planned) }}">
                        </div>
                        <div class="col-md-4 mb-4">
                            <label class="form-label">Начало</label>
                            <input type="datetime-local" name="start_date" class="form-control"
                                   value="{{ old('start_date', $league->start_date?->format('Y-m-d\TH:i')) }}">
                        </div>
                        <div class="col-md-4 mb-4">
                            <label class="form-label">Окончание</label>
                            <input type="datetime-local" name="end_date" class="form-control"
                                   value="{{ old('end_date', $league->end_date?->format('Y-m-d\TH:i')) }}">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-4">
                            <label class="form-label">Уровень от</label>
                            <select name="min_level" class="form-select">
                                <option value="">Не важно</option>
                                @for($i = 1; $i <= 5.75; $i += 0.25)
                                    <option value="{{ number_format($i, 2) }}"
                                        {{ old('min_level', $league->min_level ? number_format((float) $league->min_level, 2) : '') == number_format($i, 2) ? 'selected' : '' }}>
                                        {{ number_format($i, 2) }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-3 mb-4">
                            <label class="form-label">Уровень до</label>
                            <select name="max_level" class="form-select">
                                <option value="">Не важно</option>
                                @for($i = 1; $i <= 5.75; $i += 0.25)
                                    <option value="{{ number_format($i, 2) }}"
                                        {{ old('max_level', $league->max_level ? number_format((float) $league->max_level, 2) : '') == number_format($i, 2) ? 'selected' : '' }}>
                                        {{ number_format($i, 2) }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-3 mb-4">
                            <label class="form-label">Мест в лиге</label>
                            <input type="number" name="max_players" class="form-control"
                                   min="4" max="200" value="{{ old('max_players', $league->max_players) }}">
                        </div>
                        <div class="col-md-3 mb-4">
                            <label class="form-label">Цена этапа, ₸</label>
                            <input type="number" name="price" class="form-control"
                                   min="0" value="{{ old('price', $league->price) }}">
                        </div>
                    </div>

                    <div class="lg-section-title">Как играются этапы</div>
                    <div class="lg-section-note">
                        Все этапы лиги играются одинаково — задайте формат один раз,
                        и он подставится в каждый турнир.
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <label class="form-label">Кортов на этапе *</label>
                            <input type="number" name="courts_count" class="form-control" required
                                   min="1" max="8" value="{{ old('courts_count', $league->courts_count ?? 2) }}">
                            <div class="form-hint">Игроков нужно минимум кортов x 4.</div>
                        </div>
                        <div class="col-md-4 mb-4">
                            <label class="form-label">Длительность, ч</label>
                            <select name="duration_hours" class="form-select">
                                <option value="">Не указывать</option>
                                @for($h = 1; $h <= 8; $h++)
                                    <option value="{{ $h }}" {{ old('duration_hours', $league->duration_hours) == $h ? 'selected' : '' }}>{{ $h }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-4 mb-4">
                            <label class="form-label">Игра до скольки очков</label>
                            <select name="points_to_win" class="form-select">
                                <option value="">Не указывать</option>
                                @foreach([16, 21, 24, 32, 42] as $pts)
                                    <option value="{{ $pts }}" {{ old('points_to_win', $league->points_to_win) == $pts ? 'selected' : '' }}>{{ $pts }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" name="is_paired" id="isPaired"
                               value="1" {{ old('is_paired', $league->is_paired) ? 'checked' : '' }}>
                        <label class="form-check-label" for="isPaired">
                            <b>Парная лига</b> — фиксированные пары, партнёр не меняется весь этап.
                            Пары на каждом этапе собирает организатор.
                        </label>
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" name="verified_only" id="verifiedOnly"
                               value="1" {{ old('verified_only', $league->verified_only) ? 'checked' : '' }}>
                        <label class="form-check-label" for="verifiedOnly">Только игроки с подтверждённым уровнем</label>
                    </div>

                    <div class="form-check mb-4">
                        <input type="checkbox" class="form-check-input" name="chat_enabled" id="chatEnabled"
                               value="1" {{ old('chat_enabled', $league->chat_enabled) ? 'checked' : '' }}>
                        <label class="form-check-label" for="chatEnabled">Чат на этапах включён</label>
                    </div>

                    <div class="form-check mb-4">
                        <input type="checkbox" class="form-check-input" name="is_rated" id="isRated"
                               value="1" {{ old('is_rated', $league->is_rated) ? 'checked' : '' }}>
                        <label class="form-check-label" for="isRated">Этапы влияют на рейтинг</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-check2"></i> Сохранить
                        </button>
                        <a href="{{ route('club.leagues.show', $league) }}" class="btn-outline-custom">Отмена</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card-dark">
            <div class="card-header">
                <h5><i class="bi bi-flag"></i> Статус лиги</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('club.leagues.status', $league) }}">
                    @csrf
                    <select name="status" class="form-select mb-3">
                        @foreach(\App\Models\League::STATUSES as $value => $label)
                            <option value="{{ $value }}" {{ $league->status === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn-outline-custom w-100 justify-content-center">
                        Применить
                    </button>
                </form>
                <p class="lg-note">
                    Завершённая лига остаётся видимой: таблица и этапы никуда не деваются.
                </p>
            </div>
        </div>
    </div>
</div>

<style>
.lg-section-title { font-size: 15px; font-weight: 700; color: var(--text-primary, #f4f6f7); margin: 8px 0 4px; }
.lg-section-note { font-size: 12.5px; color: var(--text-muted, #71717a); margin-bottom: 16px; line-height: 1.5; }
.form-hint { margin-top: 6px; font-size: 12px; color: var(--text-muted, #71717a); }
.lg-note { margin: 14px 0 0; font-size: 12.5px; color: var(--text-muted, #71717a); line-height: 1.5; }
</style>
@endsection

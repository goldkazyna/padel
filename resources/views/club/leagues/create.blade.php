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

                <form action="{{ route('club.leagues.store') }}" method="POST">
                    @csrf

                    <div class="mb-4">
                        <label class="form-label">Название лиги *</label>
                        <input type="text" name="name" class="form-control" required
                               value="{{ old('name') }}" placeholder="Например: Сентябрь Кап">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Описание</label>
                        <textarea name="description" class="form-control" rows="3"
                                  placeholder="Регламент, призы, расписание этапов">{{ old('description') }}</textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <label class="form-label">Сколько этапов *</label>
                            <input type="number" name="stages_planned" class="form-control" required
                                   min="2" max="30" value="{{ old('stages_planned', 8) }}">
                            <div class="form-hint">Можно добавить больше или меньше по ходу.</div>
                        </div>
                        <div class="col-md-4 mb-4">
                            <label class="form-label">Начало</label>
                            <input type="datetime-local" name="start_date" class="form-control"
                                   value="{{ old('start_date') }}">
                        </div>
                        <div class="col-md-4 mb-4">
                            <label class="form-label">Окончание</label>
                            <input type="datetime-local" name="end_date" class="form-control"
                                   value="{{ old('end_date') }}">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-4">
                            <label class="form-label">Уровень от</label>
                            <input type="number" step="0.25" min="1" max="7" name="min_level"
                                   class="form-control" value="{{ old('min_level') }}">
                        </div>
                        <div class="col-md-3 mb-4">
                            <label class="form-label">Уровень до</label>
                            <input type="number" step="0.25" min="1" max="7" name="max_level"
                                   class="form-control" value="{{ old('max_level') }}">
                        </div>
                        <div class="col-md-3 mb-4">
                            <label class="form-label">Мест в лиге</label>
                            <input type="number" name="max_players" class="form-control"
                                   min="4" max="200" value="{{ old('max_players', 12) }}">
                        </div>
                        <div class="col-md-3 mb-4">
                            <label class="form-label">Цена этапа, ₸</label>
                            <input type="number" name="price" class="form-control"
                                   min="0" value="{{ old('price') }}">
                            <div class="form-hint">Подставится в каждый этап.</div>
                        </div>
                    </div>

                    <div class="form-check mb-4">
                        <input type="checkbox" class="form-check-input" name="is_rated" id="isRated"
                               value="1" {{ old('is_rated', true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="isRated">Этапы влияют на рейтинг</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-check2"></i> Создать лигу
                        </button>
                        <a href="{{ route('club.leagues.index') }}" class="btn-outline-custom">Отмена</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card-dark">
            <div class="card-header">
                <h5><i class="bi bi-info-circle"></i> Как работает лига</h5>
            </div>
            <div class="card-body">
                <div class="lg-hint">
                    <div class="lg-hint-num">1</div>
                    <div>
                        <b>Создаёте лигу</b> — например, «Сентябрь Кап» из восьми этапов.
                    </div>
                </div>
                <div class="lg-hint">
                    <div class="lg-hint-num">2</div>
                    <div>
                        <b>Собираете состав.</b> Игроки записываются один раз в лигу,
                        а не в каждый турнир отдельно.
                    </div>
                </div>
                <div class="lg-hint">
                    <div class="lg-hint-num">3</div>
                    <div>
                        <b>Добавляете этапы.</b> Каждый этап — обычный турнир
                        Americano&nbsp;Flex, состав лиги попадает в него сразу.
                    </div>
                </div>
                <div class="lg-hint">
                    <div class="lg-hint-num">4</div>
                    <div>
                        <b>Считается общая таблица</b> — очки складываются за все
                        сыгранные этапы.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.form-hint { margin-top: 6px; font-size: 12px; color: var(--text-muted, #71717a); }
.lg-hint { display: flex; gap: 12px; align-items: flex-start; font-size: 13.5px; color: var(--text-secondary); line-height: 1.5; }
.lg-hint + .lg-hint { margin-top: 16px; }
.lg-hint b { color: var(--text-primary, #f4f6f7); font-weight: 700; }
.lg-hint-num { flex: 0 0 auto; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(34,197,94,.14); color: #22c55e; font-size: 12px; font-weight: 800; }
</style>
@endsection

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
						<label class="form-label">Тип турнира</label>
						<input type="text" class="form-control" value="{{ $tournament->type_name }}" disabled>
						<small class="text-secondary">Тип нельзя изменить после создания</small>
					</div>
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
							<input type="datetime-local" 
								   name="start_date" 
								   class="form-control" 
								   value="{{ old('start_date', $tournament->start_date->format('Y-m-d\TH:i')) }}" 
								   required
								   style="cursor: pointer;"
								   onclick="this.showPicker()">
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
					@if($tournament->isAmericano())
					<div class="row">
						<div class="col-md-6 mb-4">
							<label class="form-label">Количество групп</label>
							<input type="text" class="form-control" value="{{ $tournament->groups_count }}" disabled>
							<small class="text-secondary">Нельзя изменить после создания</small>
						</div>
					</div>

					{{-- Плей-офф опции --}}
					<div class="mb-4">
						<label class="form-label">Плей-офф</label>
						<div class="playoff-options">
							<div class="form-check">
								<input type="checkbox" class="form-check-input" name="has_playoff" id="hasPlayoff" value="1" 
									   {{ old('has_playoff', $tournament->has_playoff) ? 'checked' : '' }} onchange="togglePlayoffType()">
								<label class="form-check-label" for="hasPlayoff">
									Добавить плей-офф после группового этапа
								</label>
							</div>
							
							<div id="playoffTypeOptions" class="mt-3 ms-4" style="{{ old('has_playoff', $tournament->has_playoff) ? '' : 'display: none;' }}">
								<div class="form-check">
									<input type="radio" class="form-check-input" name="playoff_type" id="finalOnly" value="final_only" 
										   {{ old('playoff_type', $tournament->playoff_type ?? 'final_only') === 'final_only' ? 'checked' : '' }} onchange="togglePlayoffFormat()">
									<label class="form-check-label" for="finalOnly">
										Только финал
									</label>
								</div>
								@if($tournament->groups_count >= 2)
								<div class="form-check mt-2">
									<input type="radio" class="form-check-input" name="playoff_type" id="semifinalFinal" value="semifinal_final"
										   {{ old('playoff_type', $tournament->playoff_type) === 'semifinal_final' ? 'checked' : '' }} onchange="togglePlayoffFormat()">
									<label class="form-check-label" for="semifinalFinal">
										Полуфинал + Финал
									</label>
								</div>
								
								{{-- Выбор формата пар --}}
								<div id="playoffFormatOptions" class="mt-3" style="{{ old('playoff_type', $tournament->playoff_type) === 'semifinal_final' ? '' : 'display: none;' }}">
									<label class="form-label">Формат пар в полуфиналах</label>
									<select name="playoff_format" id="playoffFormat" class="form-select">
										<option value="mix" {{ old('playoff_format', $tournament->playoff_format) === 'mix' ? 'selected' : '' }}>Микс (A1+B2 vs A3+B4, A2+B1 vs B3+A4)</option>
										<option value="group_vs" {{ old('playoff_format', $tournament->playoff_format) === 'group_vs' ? 'selected' : '' }}>Группа vs Группа (A1+A2 vs B1+B2, A3+A4 vs B3+B4)</option>
										<option value="tops" {{ old('playoff_format', $tournament->playoff_format) === 'tops' ? 'selected' : '' }}>Топы вместе (A1+B1 vs A3+B3, A2+B2 vs A4+B4)</option>
										<option value="cross" {{ old('playoff_format', $tournament->playoff_format) === 'cross' ? 'selected' : '' }}>Крест (A1+B4 vs B1+A4, A2+B3 vs B2+A3)</option>
									</select>
									<small class="text-secondary mt-2 d-block">
										A1 = 1-е место группы A, B2 = 2-е место группы B и т.д.
									</small>
								</div>
								@endif
							</div>
						</div>
					</div>
					@endif
					@if($tournament->isMexicano())
					<div class="row">
						<div class="col-md-4 mb-4">
							<label class="form-label">Сумма очков за матч</label>
							<select name="points_to_win" class="form-select">
								<option value="32" {{ old('points_to_win', $tournament->points_to_win) == 32 ? 'selected' : '' }}>32 очка</option>
								<option value="42" {{ old('points_to_win', $tournament->points_to_win) == 42 ? 'selected' : '' }}>42 очка</option>
								<option value="24" {{ old('points_to_win', $tournament->points_to_win) == 24 ? 'selected' : '' }}>24 очка</option>
							</select>
						</div>
						<div class="col-md-4 mb-4">
							<label class="form-label">Количество раундов</label>
							<input type="text" class="form-control" value="{{ $tournament->rounds_count }}" disabled>
							<small class="text-secondary">Нельзя изменить после создания</small>
						</div>
					</div>
					@endif	
					@if($tournament->isTeamBased())
					<div class="row">
						<div class="col-md-4 mb-4">
							<label class="form-label">Количество групп</label>
							<input type="text" class="form-control" value="{{ $tournament->groups_count }}" disabled>
							<small class="text-secondary">Нельзя изменить после создания</small>
						</div>
						<div class="col-md-4 mb-4">
							<label class="form-label">Выходят из группы</label>
							<input type="text" class="form-control" value="{{ $tournament->teams_advance }} пары" disabled>
							<small class="text-secondary">Нельзя изменить после создания</small>
						</div>
						<div class="col-md-4 mb-4">
							<label class="form-label">Очки за матч</label>
							<select name="points_to_win" class="form-select">
								<option value="24" {{ old('points_to_win', $tournament->points_to_win) == 24 ? 'selected' : '' }}>до 24</option>
								<option value="32" {{ old('points_to_win', $tournament->points_to_win) == 32 ? 'selected' : '' }}>до 32</option>
								<option value="21" {{ old('points_to_win', $tournament->points_to_win) == 21 ? 'selected' : '' }}>до 21</option>
							</select>
						</div>
					</div>
					@endif					
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
<script>
function togglePlayoffType() {
    const hasPlayoff = document.getElementById('hasPlayoff');
    const playoffTypeOptions = document.getElementById('playoffTypeOptions');
    
    if (hasPlayoff && playoffTypeOptions) {
        playoffTypeOptions.style.display = hasPlayoff.checked ? 'block' : 'none';
        togglePlayoffFormat();
    }
}

function togglePlayoffFormat() {
    const semifinalFinal = document.getElementById('semifinalFinal');
    const playoffFormatOptions = document.getElementById('playoffFormatOptions');
    
    if (playoffFormatOptions && semifinalFinal) {
        playoffFormatOptions.style.display = semifinalFinal.checked ? 'block' : 'none';
    }
}
</script>
<style>
.form-control:disabled {
    background-color: #141414 !important;
}
</style>
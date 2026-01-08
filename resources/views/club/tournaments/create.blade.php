@extends('layouts.app')

@section('title', 'Создать турнир')

@section('content')
<div class="page-header">
    <div>
        <h2>Создать турнир</h2>
        <p>Новый турнир для клуба</p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card-dark">
            <div class="card-body">
                <form action="{{ route('club.tournaments.store') }}" method="POST">
				@if($errors->any())
					<div class="alert-danger-custom mb-4">
						<ul class="mb-0">
							@foreach($errors->all() as $error)
								<li>{{ $error }}</li>
							@endforeach
						</ul>
					</div>
				@endif
                    @csrf

                    @if($clubs->count() > 1)
                        <div class="mb-4">
                            <label class="form-label">Клуб *</label>
                            <select name="club_id" class="form-select" required>
                                @foreach($clubs as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <input type="hidden" name="club_id" value="{{ $clubs->first()->id }}">
                    @endif
					<div class="mb-4">
						<label class="form-label">Тип турнира *</label>
						<select name="type" id="tournamentType" class="form-select" required onchange="toggleTypeFields()">
							<option value="classic" {{ old('type') === 'classic' ? 'selected' : '' }}>Классический</option>
							<option value="americano" {{ old('type') === 'americano' ? 'selected' : '' }}>Американо</option>
							<option value="mexicano" {{ old('type') === 'mexicano' ? 'selected' : '' }}>Мексикано</option>
							 <option value="team" {{ old('type') === 'team' ? 'selected' : '' }}>Групповой + Плей-офф</option>
						</select>
					</div>
                    <div class="mb-4">
                        <label class="form-label">Название *</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
                        @error('name')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Описание</label>
                        <textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea>
                    </div>

                    <div class="row">
						<div class="col-md-6 mb-4">
							<label class="form-label">Дата и время *</label>
							<input type="datetime-local" 
								   name="start_date" 
								   class="form-control" 
								   value="{{ old('start_date') }}" 
								   required
								   style="cursor: pointer;"
								   onclick="this.showPicker()">
							@error('start_date')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
						</div>
					</div>

                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Мин. уровень *</label>
                            <select name="min_level" class="form-select" required>
                                @for($i = 1; $i <= 5.75; $i += 0.25)
                                    <option value="{{ number_format($i, 2) }}" {{ old('min_level', '1.00') == number_format($i, 2) ? 'selected' : '' }}>
                                        {{ number_format($i, 2) }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Макс. уровень *</label>
                            <select name="max_level" class="form-select" required>
                                @for($i = 1; $i <= 5.75; $i += 0.25)
                                    <option value="{{ number_format($i, 2) }}" {{ old('max_level', '5.75') == number_format($i, 2) ? 'selected' : '' }}>
                                        {{ number_format($i, 2) }}
                                    </option>
                                @endfor
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Макс. участников *</label>
                            <input type="number" name="max_participants" class="form-control" value="{{ old('max_participants', 16) }}" min="2" max="128" required>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Стоимость (₸)</label>
                            <input type="number" name="price" class="form-control" value="{{ old('price', 0) }}" min="0">
                        </div>
						<div class="col-md-6 mb-4">
							<label class="form-label">Забронировать мест</label>
							<input type="number" name="reserve_count" class="form-control" 
								   value="{{ old('reserve_count', 0) }}" min="0" max="10">
							<small class="text-secondary">Места для знакомых, которых заменишь позже</small>
						</div>
						{{-- Названия кортов --}}
						<div class="mb-4" id="courtsSection">
							<label class="form-label">Названия кортов</label>
							<div id="courtsInputs">
								{{-- Генерируется через JavaScript --}}
							</div>
							<small class="text-secondary">Оставьте пустым для "Корт 1", "Корт 2" и т.д.</small>
						</div>
                    </div>
					<div id="americanoFields" style="display: none;">
						<div class="row">
							<div class="col-md-6 mb-4">
								<label class="form-label">Количество групп *</label>
								<select name="groups_count" id="americanoGroupsCount" class="form-select" onchange="togglePlayoffOptions()" disabled>
									<option value="1" {{ old('groups_count', 1) == 1 ? 'selected' : '' }}>1 группа</option>
									<option value="2" {{ old('groups_count') == 2 ? 'selected' : '' }}>2 группы</option>
								</select>
							</div>
							<div class="col-md-6 mb-4">
								<label class="form-label">Количество раундов *</label>
								<input type="number" name="rounds_count" id="americanoRoundsCount" class="form-control" disabled
									   value="{{ old('rounds_count', 15) }}" min="1" max="30">
								<small class="text-secondary">Авто: игроков в группе - 1</small>
							</div>
						</div>
						
						{{-- Плей-офф опции --}}
						<div class="mb-4">
							<label class="form-label">Плей-офф</label>
							<div class="playoff-options">
								<div class="form-check">
									<input type="checkbox" class="form-check-input" name="has_playoff" id="hasPlayoff" value="1" 
										   {{ old('has_playoff') ? 'checked' : '' }} onchange="togglePlayoffType()">
									<label class="form-check-label" for="hasPlayoff">
										Добавить плей-офф после группового этапа
									</label>
								</div>
								
								<div id="playoffTypeOptions" class="mt-3 ms-4" style="display: none;">
									<div class="form-check">
										<input type="radio" class="form-check-input" name="playoff_type" id="finalOnly" value="final_only" 
											   {{ old('playoff_type', 'final_only') === 'final_only' ? 'checked' : '' }} onchange="togglePlayoffFormat()">
										<label class="form-check-label" for="finalOnly">
											Только финал
										</label>
									</div>
									<div class="form-check mt-2" id="semifinalOption" style="display: none;">
										<input type="radio" class="form-check-input" name="playoff_type" id="semifinalFinal" value="semifinal_final"
											   {{ old('playoff_type') === 'semifinal_final' ? 'checked' : '' }} onchange="togglePlayoffFormat()">
										<label class="form-check-label" for="semifinalFinal">
											Полуфинал + Финал
										</label>
									</div>
									
									{{-- Выбор формата пар --}}
									<div id="playoffFormatOptions" class="mt-3" style="display: none;">
										<label class="form-label">Формат пар в полуфиналах</label>
										<select name="playoff_format" id="playoffFormat" class="form-select">
											<option value="mix" {{ old('playoff_format') === 'mix' ? 'selected' : '' }}>Микс (A1+B2 vs A3+B4, A2+B1 vs B3+A4)</option>
											<option value="group_vs" {{ old('playoff_format') === 'group_vs' ? 'selected' : '' }}>Группа vs Группа (A1+A2 vs B1+B2, A3+A4 vs B3+B4)</option>
											<option value="tops" {{ old('playoff_format') === 'tops' ? 'selected' : '' }}>Топы вместе (A1+B1 vs A3+B3, A2+B2 vs A4+B4)</option>
											<option value="cross" {{ old('playoff_format') === 'cross' ? 'selected' : '' }}>Крест (A1+B4 vs B1+A4, A2+B3 vs B2+A3)</option>
										</select>
										<small class="text-secondary mt-2 d-block">
											A1 = 1-е место группы A, B2 = 2-е место группы B и т.д.
										</small>
									</div>
								</div>
							</div>
						</div>
						
						<div class="alert-success-custom mb-4">
							<i class="bi bi-info-circle me-2"></i>
							<strong>Американо:</strong> Участники делятся на группы по рейтингу. Каждый играет с каждым в паре. Очки считаются индивидуально.
						</div>
					</div>
					
					
					<div id="mexicanoFields" style="display: none;">
						<div class="row">
							<div class="col-md-4 mb-4">
								<label class="form-label">Сумма очков за матч *</label>
								<select name="points_to_win" class="form-select">
									<option value="32" {{ old('points_to_win', 32) == 32 ? 'selected' : '' }}>32 очка</option>
									<option value="42" {{ old('points_to_win') == 42 ? 'selected' : '' }}>42 очка</option>
									<option value="24" {{ old('points_to_win') == 24 ? 'selected' : '' }}>24 очка</option>
								</select>
							</div>
							<div class="col-md-4 mb-4">
								<label class="form-label">Количество раундов *</label>
								<select name="rounds_count" id="mexicanoRoundsCount" class="form-select" disabled>
									<option value="5" {{ old('rounds_count', 5) == 5 ? 'selected' : '' }}>5 раундов</option>
									<option value="6" {{ old('rounds_count') == 6 ? 'selected' : '' }}>6 раундов</option>
									<option value="7" {{ old('rounds_count') == 7 ? 'selected' : '' }}>7 раундов</option>
									<option value="8" {{ old('rounds_count') == 8 ? 'selected' : '' }}>8 раундов</option>
								</select>
							</div>
						</div>
						
						<div class="alert-success-custom mb-4">
							<i class="bi bi-info-circle me-2"></i>
							<strong>Мексикано:</strong> Пары формируются динамически после каждого раунда. Играют те, кто набрал похожее количество очков. Все играют вместе без разделения на группы.
						</div>
					</div>
					<div id="teamFields" style="display: none;">
						<div class="row">
							<div class="col-md-4 mb-4">
								<label class="form-label">Количество групп *</label>
								<select name="groups_count" id="teamGroupsCount" class="form-select" disabled>
									<option value="2" {{ old('groups_count', 2) == 2 ? 'selected' : '' }}>2 группы</option>
									<option value="4" {{ old('groups_count') == 4 ? 'selected' : '' }}>4 группы</option>
								</select>
							</div>
							<div class="col-md-4 mb-4">
								<label class="form-label">Выходят из группы *</label>
								<select name="teams_advance" class="form-select">
									<option value="1" {{ old('teams_advance') == 1 ? 'selected' : '' }}>1 пара</option>
									<option value="2" {{ old('teams_advance', 2) == 2 ? 'selected' : '' }}>2 пары</option>
									<option value="3" {{ old('teams_advance') == 3 ? 'selected' : '' }}>3 пары</option>
								</select>
							</div>
							<div class="col-md-4 mb-4">
								<label class="form-label">Очки за матч *</label>
								<select name="points_to_win" class="form-select">
									<option value="24" {{ old('points_to_win', 24) == 24 ? 'selected' : '' }}>до 24</option>
									<option value="32" {{ old('points_to_win') == 32 ? 'selected' : '' }}>до 32</option>
									<option value="21" {{ old('points_to_win') == 21 ? 'selected' : '' }}>до 21</option>
								</select>
							</div>
						</div>
						
						<div class="alert-info-custom mb-4">
							<i class="bi bi-info-circle me-2"></i>
							<strong>Групповой + Плей-офф:</strong> Фиксированные пары регистрируются вместе. 
							Групповой этап — каждая пара играет с каждой. 
							Лучшие выходят в плей-офф (на вылет).
						</div>
					</div>
					
					
					
					
					
					
					
					
					
					
					
                    <div class="mb-4">
                        <label class="form-label">Статус *</label>
                        <select name="status" class="form-select" required>
                            <option value="draft" {{ old('status') === 'draft' ? 'selected' : '' }}>Черновик</option>
                            <option value="open" {{ old('status') === 'open' ? 'selected' : '' }}>Открыть регистрацию</option>
                        </select>
                    </div>

                    <div class="d-flex gap-3">
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-check-lg"></i> Создать
                        </button>
                        <a href="{{ route('club.tournaments.index') }}" class="btn-outline-custom">Отмена</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function toggleTypeFields() {
    const type = document.getElementById('tournamentType').value;
    const americanoFields = document.getElementById('americanoFields');
    const mexicanoFields = document.getElementById('mexicanoFields');
    const teamFields = document.getElementById('teamFields');
    
    if (americanoFields) americanoFields.style.display = 'none';
    if (mexicanoFields) mexicanoFields.style.display = 'none';
    if (teamFields) teamFields.style.display = 'none';
    
	if (type === 'americano' && americanoFields) {
		americanoFields.style.display = 'block';
		document.getElementById('americanoGroupsCount').disabled = false;
		if (document.getElementById('teamGroupsCount')) {
			document.getElementById('teamGroupsCount').disabled = true;
		}
		if (document.getElementById('americanoRoundsCount')) {
			document.getElementById('americanoRoundsCount').disabled = false;
		}
		if (document.getElementById('mexicanoRoundsCount')) {
			document.getElementById('mexicanoRoundsCount').disabled = true;
		}
		togglePlayoffOptions();
		togglePlayoffType();
		generateCourtsInputs();
		updateAmericanoRounds();
	} else if (type === 'mexicano' && mexicanoFields) {
        mexicanoFields.style.display = 'block';
		if (document.getElementById('mexicanoRoundsCount')) {
			document.getElementById('mexicanoRoundsCount').disabled = false;
		}
		if (document.getElementById('americanoRoundsCount')) {
			document.getElementById('americanoRoundsCount').disabled = true;
		}
	} else if (type === 'team' && teamFields) {
		teamFields.style.display = 'block';
		document.getElementById('teamGroupsCount').disabled = false;
		if (document.getElementById('americanoGroupsCount')) {
			document.getElementById('americanoGroupsCount').disabled = true;
		}
	}
}

function togglePlayoffType() {
    const hasPlayoff = document.getElementById('hasPlayoff');
    const playoffTypeOptions = document.getElementById('playoffTypeOptions');
    
    if (hasPlayoff && playoffTypeOptions) {
        playoffTypeOptions.style.display = hasPlayoff.checked ? 'block' : 'none';
        togglePlayoffFormat();
    }
}

function togglePlayoffOptions() {
    const groupsCount = document.getElementById('americanoGroupsCount');
    const semifinalOption = document.getElementById('semifinalOption');
    const finalOnly = document.getElementById('finalOnly');
    
    if (groupsCount && semifinalOption) {
        const groups = parseInt(groupsCount.value);
        
        if (groups >= 2) {
            semifinalOption.style.display = 'block';
        } else {
            semifinalOption.style.display = 'none';
            if (finalOnly) finalOnly.checked = true;
            togglePlayoffFormat();
        }
    }
}

function togglePlayoffFormat() {
    const semifinalFinal = document.getElementById('semifinalFinal');
    const playoffFormatOptions = document.getElementById('playoffFormatOptions');
    const groupsCount = document.getElementById('americanoGroupsCount');
    
    if (playoffFormatOptions && semifinalFinal && groupsCount) {
        const groups = parseInt(groupsCount.value);
        // Показываем выбор формата только если 2 группы И выбран полуфинал+финал
        if (groups >= 2 && semifinalFinal.checked) {
            playoffFormatOptions.style.display = 'block';
        } else {
            playoffFormatOptions.style.display = 'none';
        }
    }
}
function generateCourtsInputs() {
    const maxParticipants = document.querySelector('input[name="max_participants"]')?.value || 16;
    const courtsCount = Math.ceil(maxParticipants / 4);
    const container = document.getElementById('courtsInputs');
    
    if (!container) return;
    
    let html = '';
    for (let i = 1; i <= courtsCount; i++) {
        html += `
            <div class="input-group mb-2">
                <span class="input-group-text">Корт ${i}</span>
                <input type="text" name="courts[]" class="form-control" placeholder="Название корта ${i}">
            </div>
        `;
    }
    container.innerHTML = html;
}
function updateAmericanoRounds() {
    const maxParticipants = parseInt(document.querySelector('input[name="max_participants"]')?.value) || 16;
    const groupsCount = parseInt(document.getElementById('americanoGroupsCount')?.value) || 1;
    const roundsInput = document.getElementById('americanoRoundsCount');
    
    if (roundsInput) {
        const playersPerGroup = Math.floor(maxParticipants / groupsCount);
        const defaultRounds = playersPerGroup - 1;
        roundsInput.value = defaultRounds;
        roundsInput.max = defaultRounds;
    }
}


document.addEventListener('DOMContentLoaded', function() {
    toggleTypeFields();
    
    // Слушаем изменение количества групп
    const groupsSelect = document.getElementById('americanoGroupsCount');
    if (groupsSelect) {
        groupsSelect.addEventListener('change', function() {
            togglePlayoffOptions();
            togglePlayoffFormat();
        });
    }
    

	// Слушаем изменения для авто-расчёта раундов Американо
	const americanoGroupsSelect = document.getElementById('americanoGroupsCount');
	if (americanoGroupsSelect) {
		americanoGroupsSelect.addEventListener('change', updateAmericanoRounds);
	}

	const maxParticipantsInput = document.querySelector('input[name="max_participants"]');
	if (maxParticipantsInput) {
		maxParticipantsInput.addEventListener('change', function() {
			generateCourtsInputs();
			updateAmericanoRounds();
		});
	}
});
</script>
@endsection
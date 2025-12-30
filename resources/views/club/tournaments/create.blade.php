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
                            <input type="datetime-local" name="start_date" class="form-control" value="{{ old('start_date') }}" required>
                            @error('start_date')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Дедлайн регистрации *</label>
                            <input type="datetime-local" name="registration_deadline" class="form-control" value="{{ old('registration_deadline') }}" required>
                            @error('registration_deadline')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
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
                    </div>
					<div id="americanoFields" style="display: none;">
					<div class="row">
						<div class="col-md-6 mb-4">
							<label class="form-label">Очки для победы *</label>
							<select name="points_to_win" class="form-select">
								<option value="16" {{ old('points_to_win', 16) == 16 ? 'selected' : '' }}>До 16 очков</option>
								<option value="24" {{ old('points_to_win') == 24 ? 'selected' : '' }}>До 24 очков</option>
								<option value="32" {{ old('points_to_win') == 32 ? 'selected' : '' }}>До 32 очков</option>
							</select>
						</div>
						<div class="col-md-6 mb-4">
							<label class="form-label">Количество групп *</label>
							<select name="groups_count" class="form-select">
								<option value="1" {{ old('groups_count', 1) == 1 ? 'selected' : '' }}>1 группа</option>
								<option value="2" {{ old('groups_count') == 2 ? 'selected' : '' }}>2 группы</option>
							</select>
						</div>
					</div>
					
					<div class="alert-success-custom mb-4">
						<i class="bi bi-info-circle me-2"></i>
						<strong>Американо:</strong> Участники делятся на группы по рейтингу. Каждый играет с каждым в паре. Очки считаются индивидуально.
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
    
    if (type === 'americano') {
        americanoFields.style.display = 'block';
    } else {
        americanoFields.style.display = 'none';
    }
}

// При загрузке страницы
document.addEventListener('DOMContentLoaded', toggleTypeFields);
</script>
@endsection
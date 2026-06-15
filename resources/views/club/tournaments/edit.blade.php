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
                        @if($tournament->type === 'americano_flex')
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Количество кортов (Americano Flex)</label>
                            <input type="number" name="flex_courts_count" class="form-control"
                                   value="{{ old('flex_courts_count', $tournament->courts_count ?? 2) }}" min="1" max="8">
                            <small class="text-secondary">Сколько кортов реально играет. Каждый раунд играют кортов × 4 игроков, остальные в очереди. Менять только до старта турнира.</small>
                        </div>
                        @endif
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Лист ожидания {{ $tournament->isTeamBased() ? '(в парах)' : '' }}</label>
                            <input type="number" name="waitlist_size" class="form-control"
                                   value="{{ old('waitlist_size', $tournament->waitlist_size ?? 0) }}" min="0" max="32">
                            <small class="text-secondary">Сколько человек/пар встанут в очередь, когда турнир заполнится</small>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Таймер модерации, часов</label>
                            <input type="number" name="moderation_hours" class="form-control"
                                   value="{{ old('moderation_hours', $tournament->moderation_hours) }}" min="0" max="720" placeholder="Пусто = без таймера">
                            <small class="text-secondary">Через сколько часов снять неоплаченную заявку (пусто = бессрочно)</small>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Таймер модерации, минут <span style="font-weight:400; color:#a1a1aa;">(для отладки)</span></label>
                            <input type="number" name="moderation_minutes" class="form-control"
                                   value="{{ old('moderation_minutes', $tournament->moderation_minutes) }}" min="0" max="1440" placeholder="Если задано — важнее часов">
                            <small class="text-secondary">Для теста; если задано — приоритетнее часов</small>
                        </div>
                    </div>
					<div class="mb-4">
						<div class="form-check">
							<input type="checkbox" class="form-check-input" name="verified_only" id="verifiedOnly" value="1" {{ old('verified_only', $tournament->verified_only) ? 'checked' : '' }}>
							<label class="form-check-label" for="verifiedOnly">Только для верифицированных игроков</label>
							<div><small class="text-secondary">Заявки смогут подавать только верифицированные игроки (есть аватар и сыгран хотя бы один турнир)</small></div>
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
					@php
						$editGroups      = $tournament->groups_count;
						$editPlayoffType = old('playoff_type', $tournament->playoff_type);
						$editHasPlayoff  = old('has_playoff', $tournament->has_playoff);
						$editIsSemi      = $editPlayoffType === 'semifinal_final';
						$showFormatBlock = $editIsSemi || ($editPlayoffType === 'final_only' && $editGroups === 1);
					@endphp
					<div class="mb-4">
						<label class="form-label">Плей-офф</label>
						<div class="playoff-options">
							<div class="form-check">
								<input type="checkbox" class="form-check-input" name="has_playoff" id="hasPlayoff" value="1"
									   {{ $editHasPlayoff ? 'checked' : '' }} onchange="togglePlayoffType()">
								<label class="form-check-label" for="hasPlayoff">
									Добавить плей-офф после группового этапа
								</label>
							</div>

							<div id="playoffTypeOptions" class="mt-3 ms-4" style="{{ $editHasPlayoff ? '' : 'display: none;' }}">
								<div class="form-check">
									<input type="radio" class="form-check-input" name="playoff_type" id="finalOnly" value="final_only"
										   {{ (!$editIsSemi) ? 'checked' : '' }} onchange="togglePlayoffFormat()">
									<label class="form-check-label" for="finalOnly">
										Только финал
									</label>
								</div>
								<div class="form-check mt-2">
									<input type="radio" class="form-check-input" name="playoff_type" id="semifinalFinal" value="semifinal_final"
										   {{ $editIsSemi ? 'checked' : '' }} onchange="togglePlayoffFormat()">
									<label class="form-check-label" for="semifinalFinal">
										Полуфинал + Финал
									</label>
								</div>

								{{-- Выбор формата пар --}}
								<div id="playoffFormatOptions" class="mt-3" style="{{ $showFormatBlock ? '' : 'display: none;' }}">
									<label class="form-label" id="playoffFormatLabel">
										@if($editGroups >= 2)
											Формат пар в полуфиналах
										@elseif($editIsSemi)
											Формат пар в полуфиналах
										@else
											Формат пар в финале
										@endif
									</label>
									<select name="playoff_format" id="playoffFormat" class="form-select">
										@if($editGroups >= 2)
											<option value="mix" {{ old('playoff_format', $tournament->playoff_format) === 'mix' ? 'selected' : '' }}>Микс (A1+B2 vs A3+B4, A2+B1 vs B3+A4)</option>
											<option value="group_vs" {{ old('playoff_format', $tournament->playoff_format) === 'group_vs' ? 'selected' : '' }}>Группа vs Группа (A1+A2 vs B1+B2, A3+A4 vs B3+B4)</option>
											<option value="tops" {{ old('playoff_format', $tournament->playoff_format) === 'tops' ? 'selected' : '' }}>Топы вместе (A1+B1 vs A3+B3, A2+B2 vs A4+B4)</option>
											<option value="cross" {{ old('playoff_format', $tournament->playoff_format) === 'cross' ? 'selected' : '' }}>Крест (A1+B4 vs B1+A4, A2+B3 vs B2+A3)</option>
										@elseif($editIsSemi)
											{{-- 1 группа + полуфинал+финал --}}
											<option value="mix" {{ old('playoff_format', $tournament->playoff_format) === 'mix' ? 'selected' : '' }}>Микс (1+8 vs 4+5, 2+7 vs 3+6)</option>
											<option value="tops" {{ old('playoff_format', $tournament->playoff_format) === 'tops' ? 'selected' : '' }}>Топы вместе (1+2 vs 7+8, 3+4 vs 5+6)</option>
											<option value="balanced" {{ old('playoff_format', $tournament->playoff_format) === 'balanced' ? 'selected' : '' }}>Сбалансированный (1+4 vs 5+8, 2+3 vs 6+7)</option>
										@else
											{{-- 1 группа + только финал --}}
											<option value="cross" {{ old('playoff_format', $tournament->playoff_format) === 'cross' ? 'selected' : '' }}>1+4 vs 2+3 (крест)</option>
											<option value="tops" {{ old('playoff_format', $tournament->playoff_format) === 'tops' ? 'selected' : '' }}>1+2 vs 3+4 (топы вместе)</option>
											<option value="mix" {{ old('playoff_format', $tournament->playoff_format) === 'mix' ? 'selected' : '' }}>1+3 vs 2+4 (микс)</option>
										@endif
									</select>
									<small class="text-secondary mt-2 d-block">
										@if($editGroups >= 2)
											A1 = 1-е место группы A, B2 = 2-е место группы B и т.д.
										@else
											Цифры = места в таблице лидеров после основных раундов
										@endif
									</small>
								</div>
							</div>
						</div>
					</div>

					{{-- Нижняя сетка и матч за 3-е место --}}
					<div class="row mt-2" id="americanoBracketOptions" style="{{ $editHasPlayoff ? '' : 'display: none;' }}">
						<div class="col-md-6 mb-2">
							<div class="form-check">
								<input type="checkbox" name="has_lower_bracket" value="1" id="americanoLowerBracket" class="form-check-input"
									{{ old('has_lower_bracket', $tournament->has_lower_bracket) ? 'checked' : '' }}>
								<label for="americanoLowerBracket" class="form-check-label">
									Нижняя сетка <small class="text-muted">(утешительная — для следующего тира игроков)</small>
								</label>
							</div>
						</div>
						<div class="col-md-6 mb-2" id="americanoBronzeWrap" style="{{ $editIsSemi ? '' : 'display: none;' }}">
							<div class="form-check">
								<input type="checkbox" name="has_bronze_match" value="1" id="americanoBronze" class="form-check-input"
									{{ old('has_bronze_match', $tournament->has_bronze_match) ? 'checked' : '' }}>
								<label for="americanoBronze" class="form-check-label">Матч за 3-е место</label>
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
                            {{-- Статусы «закрыта/идёт/завершён» вручную не выставляем — опция
                                 остаётся только если турнир уже в ней, чтобы при сохранении
                                 статус не слетел на «Черновик». --}}
                            @if($tournament->status === 'closed')
                            <option value="closed" selected>Регистрация закрыта</option>
                            @endif
                            @if($tournament->status === 'in_progress')
                            <option value="in_progress" selected>Идёт турнир</option>
                            @endif
                            @if($tournament->status === 'completed')
                            <option value="completed" selected>Завершён</option>
                            @endif
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
    const bracketOptions = document.getElementById('americanoBracketOptions');

    if (hasPlayoff && playoffTypeOptions) {
        playoffTypeOptions.style.display = hasPlayoff.checked ? 'block' : 'none';
        if (bracketOptions) {
            bracketOptions.style.display = hasPlayoff.checked ? 'flex' : 'none';
        }
        togglePlayoffFormat();
    }
}

function togglePlayoffFormat() {
    const semifinalFinal = document.getElementById('semifinalFinal');
    const playoffFormatOptions = document.getElementById('playoffFormatOptions');
    const bronzeWrap = document.getElementById('americanoBronzeWrap');
    const bronze = document.getElementById('americanoBronze');

    if (playoffFormatOptions && semifinalFinal) {
        playoffFormatOptions.style.display = semifinalFinal.checked ? 'block' : 'none';
    }
    if (bronzeWrap) {
        const isSemi = semifinalFinal && semifinalFinal.checked;
        bronzeWrap.style.display = isSemi ? 'block' : 'none';
        if (!isSemi && bronze) bronze.checked = false;
    }
}
</script>
<style>
.form-control:disabled {
    background-color: #141414 !important;
}
</style>

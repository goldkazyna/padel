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

                    @php
                        $venueClubSelectedId = old('venue_club_id', $tournament->venue_club_id);
                        $venueClubSelected = $venueClubSelectedId ? $venueClubs->firstWhere('id', (int) $venueClubSelectedId) : null;
                    @endphp
                    <div class="mb-4">
                        <label class="form-label">Клуб (площадка)</label>
                        <div class="venue-club-wrapper">
                            <input type="text" id="venueClubSearch" class="form-control" placeholder="Начните вводить название клуба..." autocomplete="off" value="{{ $venueClubSelected->name ?? '' }}">
                            <button type="button" id="venueClubClearBtn" class="venue-club-clear-btn" style="{{ $venueClubSelected ? '' : 'display:none;' }}" title="Очистить">&times;</button>
                            <div id="venueClubResults" class="venue-club-results">
                                @forelse($venueClubs as $vc)
                                    <div class="venue-club-item" data-id="{{ $vc->id }}" data-name="{{ $vc->name }}" data-search="{{ mb_strtolower($vc->name . ' ' . $vc->city) }}">
                                        <span class="venue-club-item-name">{{ $vc->name }}</span>
                                        <span class="venue-club-item-city">{{ $vc->city }}</span>
                                    </div>
                                @empty
                                    <div class="venue-club-empty">Клубы не найдены</div>
                                @endforelse
                            </div>
                        </div>
                        <input type="hidden" name="venue_club_id" id="venueClubId" value="{{ $venueClubSelectedId }}">
                        <small class="text-secondary">Необязательно. Где физически играют — увидят записавшиеся.</small>
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
						<div class="col-md-6 mb-4">
							<label class="form-label">Длительность</label>
							<select name="duration_hours" class="form-select">
								<option value="">Не указана</option>
								@for ($h = 1; $h <= 8; $h++)
									<option value="{{ $h }}" {{ (string) old('duration_hours', $tournament->duration_hours) === (string) $h ? 'selected' : '' }}>{{ $h }} {{ $h === 1 ? 'час' : ($h <= 4 ? 'часа' : 'часов') }}</option>
								@endfor
							</select>
							<div class="form-text">Необязательно. Если указать — в деталях покажем время начала и конца.</div>
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
                        @if($tournament->isJustPadelIt() && !$tournament->is_paired)
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Количество кортов</label>
                            <input type="number" name="courts_count" class="form-control"
                                   value="{{ old('courts_count', $tournament->courts_count) }}" min="1" max="32"
                                   placeholder="напр. 3">
                            <small class="text-secondary">Игроков должно быть ровно кортов × 4, чтобы начать посев. Напр. 3 корта = 12 игроков.</small>
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
							<input type="checkbox" class="form-check-input" name="is_rated" id="isRated" value="1" {{ old('is_rated', $tournament->is_rated) ? 'checked' : '' }}>
							<label class="form-check-label" for="isRated">Рейтинговый турнир</label>
							<div><small class="text-secondary">Снимите галочку, чтобы турнир не влиял на рейтинг игроков</small></div>
						</div>
						<div class="form-check mt-2">
							<input type="checkbox" class="form-check-input" name="verified_only" id="verifiedOnly" value="1" {{ old('verified_only', $tournament->verified_only) ? 'checked' : '' }}>
							<label class="form-check-label" for="verifiedOnly">Только для верифицированных игроков</label>
							<div><small class="text-secondary">Заявки смогут подавать только верифицированные игроки (есть аватар и сыгран хотя бы один турнир)</small></div>
						</div>
					</div>
					@if($tournament->isAmericano())
					@php
						$amNotStarted  = in_array($tournament->status, ['draft', 'open']);
						$amGroupsFormed = $tournament->groups()->count() > 0;
						$amGroups = old('groups_count', $tournament->groups_count);
						$amRounds = old('rounds_count', $tournament->rounds_count);
					@endphp
					<div class="row">
						<div class="col-md-6 mb-4">
							<label class="form-label">Количество групп</label>
							@if($amNotStarted && !$amGroupsFormed)
								<select name="groups_count" class="form-select">
									@for($g = 1; $g <= 4; $g++)
										<option value="{{ $g }}" {{ (int) $amGroups === $g ? 'selected' : '' }}>{{ $g }} {{ $g === 1 ? 'группа' : ($g >= 5 ? 'групп' : 'группы') }}</option>
									@endfor
								</select>
								<small class="text-secondary">Игроки распределятся по группам при формировании.</small>
							@else
								<input type="text" class="form-control" value="{{ $tournament->groups_count }}" disabled>
								<small class="text-secondary">
									@if($amGroupsFormed)
										Группы уже сформированы. Чтобы изменить — сбросьте их в редакторе групп.
									@else
										Турнир уже начат — изменить нельзя.
									@endif
								</small>
							@endif
						</div>
						<div class="col-md-6 mb-4">
							<label class="form-label">Количество раундов</label>
							@if($amNotStarted)
								<input type="number" name="rounds_count" class="form-control"
									   value="{{ $amRounds }}" min="1" max="30">
								<small class="text-secondary">Не больше, чем «игроков в группе − 1». По умолчанию — авто.</small>
							@else
								<input type="text" class="form-control" value="{{ $tournament->rounds_count }}" disabled>
								<small class="text-secondary">Турнир уже начат — изменить нельзя.</small>
							@endif
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
					@php
						$editMexHasPlayoff = old('has_playoff', $tournament->has_playoff);
						$editMexPlayoffType = old('playoff_type', $tournament->playoff_type ?? 'final_only');
						$editMexPlayoffFormat = old('playoff_format', $tournament->playoff_format ?? 'mix');
					@endphp
					<div class="row">
						<div class="col-md-4 mb-4">
							<label class="form-label">Количество раундов</label>
							<input type="text" class="form-control" value="{{ $tournament->rounds_count }}" disabled>
							<small class="text-secondary">Нельзя изменить после создания</small>
						</div>
					</div>
					{{-- Плей-офф Мексикано — можно включить/выключить во время турнира --}}
					<div class="mb-4">
						<label class="form-label">Плей-офф</label>
						<div class="form-check">
							<input type="checkbox" class="form-check-input" name="has_playoff" id="mexicanoHasPlayoff" value="1"
								   {{ $editMexHasPlayoff ? 'checked' : '' }} onchange="toggleMexicanoPlayoffType()">
							<label class="form-check-label" for="mexicanoHasPlayoff">
								Добавить плей-офф после основных раундов
							</label>
						</div>
						<div id="mexicanoPlayoffTypeOptions" class="mt-3 ms-4" style="{{ $editMexHasPlayoff ? '' : 'display: none;' }}">
							<div class="form-check">
								<input type="radio" class="form-check-input" name="playoff_type" id="mexicanoFinalOnly" value="final_only"
									   {{ $editMexPlayoffType === 'final_only' ? 'checked' : '' }} onchange="toggleMexicanoPlayoffFormat()">
								<label class="form-check-label" for="mexicanoFinalOnly">
									Только финал (топ-4: 1+4 vs 2+3)
								</label>
							</div>
							<div class="form-check mt-2">
								<input type="radio" class="form-check-input" name="playoff_type" id="mexicanoSemifinalFinal" value="semifinal_final"
									   {{ $editMexPlayoffType === 'semifinal_final' ? 'checked' : '' }} onchange="toggleMexicanoPlayoffFormat()">
								<label class="form-check-label" for="mexicanoSemifinalFinal">
									Полуфинал + Финал (топ-8)
								</label>
							</div>
							<div id="mexicanoPlayoffFormatOptions" class="mt-3" style="{{ $editMexPlayoffType === 'semifinal_final' ? '' : 'display: none;' }}">
								<label class="form-label">Формат пар в полуфиналах</label>
								<select name="playoff_format" id="mexicanoPlayoffFormat" class="form-select">
									<option value="mix" {{ $editMexPlayoffFormat === 'mix' ? 'selected' : '' }}>Микс (1+8 vs 4+5, 2+7 vs 3+6)</option>
									<option value="tops" {{ $editMexPlayoffFormat === 'tops' ? 'selected' : '' }}>Топы вместе (1+2 vs 7+8, 3+4 vs 5+6)</option>
									<option value="balanced" {{ $editMexPlayoffFormat === 'balanced' ? 'selected' : '' }}>Сбалансированный (1+4 vs 5+8, 2+3 vs 6+7)</option>
								</select>
								<small class="text-secondary mt-2 d-block">
									Цифры = места в таблице лидеров после основных раундов
								</small>
							</div>
						</div>
					</div>
					@endif
					@if($tournament->isTeamBased())
					<div class="row">
						<div class="col-md-12 mb-4">
							<label class="form-label">Кто собирает пары</label>
							<select name="pairing_mode" class="form-select">
								<option value="self" {{ old('pairing_mode', $tournament->pairing_mode) === 'self' ? 'selected' : '' }}>Сами игроки (поиск партнёра)</option>
								<option value="admin" {{ old('pairing_mode', $tournament->pairing_mode) === 'admin' ? 'selected' : '' }}>Админ собирает (запись по одному)</option>
							</select>
							<small class="text-secondary">«Админ собирает» — игроки записываются поодиночке, пары вы соберёте перед стартом.</small>
						</div>
					</div>
					<div class="row">
						<div class="col-md-6 mb-4">
							<label class="form-label">Количество групп</label>
							<input type="text" class="form-control" value="{{ $tournament->groups_count }}" disabled>
							<small class="text-secondary">Нельзя изменить после создания</small>
						</div>
						<div class="col-md-6 mb-4">
							<label class="form-label">Выходят из группы</label>
							<input type="text" class="form-control" value="{{ $tournament->teams_advance }} пары" disabled>
							<small class="text-secondary">Нельзя изменить после создания</small>
						</div>
					</div>
					<div class="row">
						<div class="col-md-6 mb-4">
							<label class="form-label">Количество кортов</label>
							<input type="number" name="courts_count" class="form-control"
								   value="{{ old('courts_count', $tournament->courts_count) }}" min="1" max="32"
								   placeholder="оставьте пустым для авто">
							<small class="text-muted">Если заполнено — матчи группового этапа пойдут волнами, не более N одновременно.</small>
						</div>
					</div>

					{{-- Плей-офф для командного турнира --}}
					@php $editTeamHasPlayoff = old('has_playoff', $tournament->has_playoff); @endphp
					<div class="mb-3">
						<div class="form-check">
							<input type="checkbox" name="has_playoff" value="1" id="teamHasPlayoff" class="form-check-input"
								{{ $editTeamHasPlayoff ? 'checked' : '' }}
								onchange="toggleTeamPlayoffOptions()">
							<label for="teamHasPlayoff" class="form-check-label">
								<strong>С плей-офф</strong> <small class="text-muted">(на вылет после групп). Снимите — будет только групповой этап, место по таблице.</small>
							</label>
						</div>
					</div>
					<div id="teamPlayoffOptions" class="row" style="{{ $editTeamHasPlayoff ? '' : 'display:none;' }}">
						@if((int) $tournament->groups_count === 2 && (int) $tournament->teams_advance === 2)
						<div class="col-md-12 mb-2">
							<label class="form-label">Формат плей-офф</label>
							<select name="playoff_format" id="teamPlayoffFormat" class="form-select">
								<option value="">Стандартный (сетка по числу выходящих пар)</option>
								<option value="winners_final" {{ old('playoff_format', $tournament->playoff_format) === 'winners_final' ? 'selected' : '' }}>Финал первых мест (A1 vs B1), вторые за 3-е место (A2 vs B2)</option>
							</select>
							<small class="text-secondary mt-2 d-block">A1 = 1-е место группы A. Первые места играют финал, вторые — за 3-е место.</small>
						</div>
						@endif
						<div class="col-md-6 mb-2">
							<div class="form-check">
								<input type="checkbox" name="has_lower_bracket" value="1" id="teamLowerBracket" class="form-check-input"
									{{ old('has_lower_bracket', $tournament->has_lower_bracket) ? 'checked' : '' }}>
								<label for="teamLowerBracket" class="form-check-label">
									Нижняя сетка <small class="text-muted">(для проигравших в QF)</small>
								</label>
							</div>
						</div>
						<div class="col-md-6 mb-2">
							<div class="form-check">
								<input type="checkbox" name="has_bronze_match" value="1" id="teamBronze" class="form-check-input"
									{{ old('has_bronze_match', $tournament->has_bronze_match) ? 'checked' : '' }}>
								<label for="teamBronze" class="form-check-label">
									Матч за 3-е место
								</label>
							</div>
						</div>
					</div>
					@endif
                    <div class="mb-4">
                        <label class="form-label">Статус *</label>
                        @if($tournament->status === 'completed')
                            {{-- Завершённый турнир: статус менять нельзя — рейтинг уже начислен,
                                 переоткрытие/повторное завершение задвоит рейтинг. --}}
                            <select class="form-select" disabled>
                                <option selected>Завершён</option>
                            </select>
                            <input type="hidden" name="status" value="completed">
                            <small class="text-muted d-block mt-1">Турнир завершён — статус изменить нельзя.</small>
                        @else
                            <select name="status" class="form-select" required>
                                <option value="draft" {{ old('status', $tournament->status) === 'draft' ? 'selected' : '' }}>Черновик</option>
                                <option value="open" {{ old('status', $tournament->status) === 'open' ? 'selected' : '' }}>Открыта регистрация</option>
                                {{-- Статусы «закрыта/идёт» вручную не выставляем — опция
                                     остаётся только если турнир уже в ней, чтобы при сохранении
                                     статус не слетел на «Черновик». --}}
                                @if($tournament->status === 'closed')
                                <option value="closed" selected>Регистрация закрыта</option>
                                @endif
                                @if($tournament->status === 'in_progress')
                                <option value="in_progress" selected>Идёт турнир</option>
                                @endif
                                <option value="cancelled" {{ old('status', $tournament->status) === 'cancelled' ? 'selected' : '' }}>Отменён</option>
                            </select>
                        @endif
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
function toggleTeamPlayoffOptions() {
    const cb = document.getElementById('teamHasPlayoff');
    const opts = document.getElementById('teamPlayoffOptions');
    if (cb && opts) opts.style.display = cb.checked ? 'flex' : 'none';
}

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

function toggleMexicanoPlayoffType() {
    const cb = document.getElementById('mexicanoHasPlayoff');
    const opts = document.getElementById('mexicanoPlayoffTypeOptions');
    if (cb && opts) {
        opts.style.display = cb.checked ? 'block' : 'none';
        toggleMexicanoPlayoffFormat();
    }
}

function toggleMexicanoPlayoffFormat() {
    const semi = document.getElementById('mexicanoSemifinalFinal');
    const fmt = document.getElementById('mexicanoPlayoffFormatOptions');
    if (fmt) fmt.style.display = (semi && semi.checked) ? 'block' : 'none';
}

(function() {
    var search = document.getElementById('venueClubSearch');
    var hidden = document.getElementById('venueClubId');
    var results = document.getElementById('venueClubResults');
    var clearBtn = document.getElementById('venueClubClearBtn');
    if (!search || !hidden || !results) return;

    var items = Array.prototype.slice.call(results.querySelectorAll('.venue-club-item'));

    function showResults() { results.classList.add('show'); }
    function hideResults() { results.classList.remove('show'); }

    function filterItems() {
        var q = search.value.trim().toLowerCase();
        items.forEach(function(item) {
            var match = !q || item.dataset.search.indexOf(q) !== -1;
            item.style.display = match ? '' : 'none';
        });
    }

    function toggleClearBtn() {
        if (clearBtn) clearBtn.style.display = hidden.value ? 'inline-flex' : 'none';
    }

    search.addEventListener('focus', function() {
        filterItems();
        showResults();
    });

    search.addEventListener('input', function() {
        hidden.value = '';
        toggleClearBtn();
        filterItems();
        showResults();
    });

    items.forEach(function(item) {
        item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            hidden.value = item.getAttribute('data-id');
            search.value = item.getAttribute('data-name');
            toggleClearBtn();
            hideResults();
        });
    });

    document.addEventListener('click', function(e) {
        if (e.target !== search && !results.contains(e.target)) {
            hideResults();
        }
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            hidden.value = '';
            search.value = '';
            toggleClearBtn();
            filterItems();
            search.focus();
        });
    }

    toggleClearBtn();
})();
</script>
<style>
.form-control:disabled {
    background-color: #141414 !important;
}
.venue-club-wrapper {
    position: relative;
}
.venue-club-clear-btn {
    position: absolute;
    right: 10px;
    top: 8px;
    background: none;
    border: none;
    color: #ef4444;
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    padding: 4px 6px;
}
.venue-club-results {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
    max-height: 250px;
    overflow-y: auto;
    z-index: 1000;
    margin-top: 4px;
}
.venue-club-results.show {
    display: block;
}
.venue-club-item {
    padding: 10px 12px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    gap: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    transition: background 0.2s;
}
.venue-club-item:hover {
    background: rgba(34, 197, 94, 0.1);
}
.venue-club-item:last-child {
    border-bottom: none;
}
.venue-club-item-city {
    color: var(--text-secondary);
    font-size: 0.85rem;
    white-space: nowrap;
}
.venue-club-empty {
    padding: 10px 12px;
    color: var(--text-secondary);
    font-size: 0.9rem;
}
</style>

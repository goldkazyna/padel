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
							<option value="americano" {{ old('type', 'americano') === 'americano' ? 'selected' : '' }}>Американо</option>
							<option value="americano_flex" {{ old('type') === 'americano_flex' ? 'selected' : '' }}>Americano Flex — с очередью игроков</option>
							<option value="mexicano" {{ old('type') === 'mexicano' ? 'selected' : '' }}>Мексикано</option>
							 <option value="team" {{ old('type') === 'team' ? 'selected' : '' }}>Групповой + Плей-офф</option>
							<option value="king_of_court" {{ old('type') === 'king_of_court' ? 'selected' : '' }}>Король корта</option>
							<option value="round_robin" {{ old('type') === 'round_robin' ? 'selected' : '' }}>Round Robin (индивидуальный)</option>
							<option value="bali_koc" {{ old('type') === 'bali_koc' ? 'selected' : '' }}>Король Корта (Bali Format)</option>
							<option value="just_padel_it" {{ old('type') === 'just_padel_it' ? 'selected' : '' }}>Just Padel It</option>
							<option value="escalera" {{ old('type') === 'escalera' ? 'selected' : '' }}>Эскалера</option>
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

                    <div class="mb-4">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="has_prizes" id="hasPrizes" value="1" {{ old('has_prizes') ? 'checked' : '' }} onchange="togglePrizes()">
                            <label class="form-check-label" for="hasPrizes">Призовой турнир</label>
                        </div>
                        <div id="prizesWrap" class="mt-2" style="display:{{ old('has_prizes') ? 'block' : 'none' }};">
                            <label class="form-label">Призы</label>
                            <textarea name="prizes" class="form-control" rows="3" maxlength="2000" placeholder="Напишите, какие призы будут: 1 место — …, 2 место — …, и т.д.">{{ old('prizes') }}</textarea>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Клуб (площадка)</label>
                        <div class="venue-club-wrapper">
                            <input type="text" id="venueClubSearch" class="form-control" placeholder="Начните вводить название клуба..." autocomplete="off">
                            <button type="button" id="venueClubClearBtn" class="venue-club-clear-btn" style="display:none;" title="Очистить">&times;</button>
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
                        <input type="hidden" name="venue_club_id" id="venueClubId" value="{{ old('venue_club_id') }}">
                        <small class="text-secondary">Необязательно. Где физически играют — увидят записавшиеся.</small>
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
						<div class="col-md-6 mb-4">
							<label class="form-label">Длительность</label>
							<select name="duration_hours" class="form-select">
								<option value="">Не указана</option>
								@for ($h = 1; $h <= 8; $h++)
									<option value="{{ $h }}" {{ (string) old('duration_hours') === (string) $h ? 'selected' : '' }}>{{ $h }} {{ $h === 1 ? 'час' : ($h <= 4 ? 'часа' : 'часов') }}</option>
								@endfor
							</select>
							<div class="form-text">Необязательно. Если указать — в деталях покажем время начала и конца.</div>
							@error('duration_hours')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
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
							<label class="form-label">Забронировать мест <span id="reserveHintPairs" style="display:none; font-weight:400; color:#a1a1aa;">(укажите кол-во пар)</span></label>
							<input type="number" name="reserve_count" class="form-control"
								   value="{{ old('reserve_count', 0) }}" min="0" max="10">
							<small class="text-secondary">Места для знакомых, которых заменишь позже</small>
						</div>
						<div class="col-md-6 mb-4">
							<label class="form-label">Лист ожидания <span id="waitlistHintPairs" style="display:none; font-weight:400; color:#a1a1aa;">(в парах)</span></label>
							<input type="number" name="waitlist_size" class="form-control"
								   value="{{ old('waitlist_size', 0) }}" min="0" max="32">
							<small class="text-secondary">Сколько человек/пар встанут в очередь, когда турнир заполнится</small>
						</div>
						<div class="col-md-6 mb-4">
							<label class="form-label">Таймер модерации, часов</label>
							<input type="number" name="moderation_hours" class="form-control"
								   value="{{ old('moderation_hours') }}" min="0" max="720" placeholder="Пусто = без таймера">
							<small class="text-secondary">Через сколько часов снять неоплаченную заявку (пусто = бессрочно)</small>
						</div>
						<div class="col-md-6 mb-4">
							<label class="form-label">Таймер модерации, минут <span style="font-weight:400; color:#a1a1aa;">(для отладки)</span></label>
							<input type="number" name="moderation_minutes" class="form-control"
								   value="{{ old('moderation_minutes') }}" min="0" max="1440" placeholder="Если задано — важнее часов">
							<small class="text-secondary">Для теста; если задано — приоритетнее часов</small>
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
					<div class="mb-4">
							<div class="form-check">
								<input type="checkbox" class="form-check-input" name="is_rated" id="isRated" value="1" {{ old('is_rated', '1') ? 'checked' : '' }}>
								<label class="form-check-label" for="isRated">Рейтинговый турнир</label>
								<div><small class="text-secondary">Снимите галочку, чтобы турнир не влиял на рейтинг игроков</small></div>
							</div>
							<div class="form-check mt-2">
								<input type="checkbox" class="form-check-input" name="verified_only" id="verifiedOnly" value="1" {{ old('verified_only') ? 'checked' : '' }}>
								<label class="form-check-label" for="verifiedOnly">Только для верифицированных игроков</label>
								<div><small class="text-secondary">Заявки смогут подавать только верифицированные игроки (есть аватар и сыгран хотя бы один турнир)</small></div>
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
											<label class="form-label" id="playoffFormatLabel">Формат пар</label>
											<select name="playoff_format" id="playoffFormat" class="form-select">
												<!-- Options будут заполняться через JavaScript -->
											</select>
											<small class="text-secondary mt-2 d-block" id="playoffFormatHint"></small>
										</div>
								</div>
							</div>
						</div>

						<div class="row mt-2" id="americanoBracketOptions" style="display:none;">
							<div class="col-md-6 mb-2">
								<div class="form-check">
									<input type="checkbox" name="has_lower_bracket" value="1" id="americanoLowerBracket" class="form-check-input"
										{{ old('has_lower_bracket') ? 'checked' : '' }}>
									<label for="americanoLowerBracket" class="form-check-label">
										Нижняя сетка <small class="text-muted">(утешительная — для следующего тира игроков)</small>
									</label>
								</div>
								<small class="text-secondary d-block" id="americanoLowerHint"></small>
							</div>
							<div class="col-md-6 mb-2" id="americanoBronzeWrap">
								<div class="form-check">
									<input type="checkbox" name="has_bronze_match" value="1" id="americanoBronze" class="form-check-input"
										{{ old('has_bronze_match') ? 'checked' : '' }}>
									<label for="americanoBronze" class="form-check-label">Матч за 3-е место</label>
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
							<div class="col-md-6 mb-4">
								<label class="form-label">Количество раундов *</label>
								<select name="rounds_count" id="mexicanoRoundsCount" class="form-select" disabled>
									@for($i = 1; $i <= 15; $i++)
										<option value="{{ $i }}" {{ old('rounds_count', 7) == $i ? 'selected' : '' }}>{{ $i }} {{ $i == 1 ? 'раунд' : ($i < 5 ? 'раунда' : 'раундов') }}</option>
									@endfor
								</select>
							</div>
						</div>
						
						{{-- Плей-офф опции --}}
						<div class="mb-4">
							<label class="form-label">Плей-офф</label>
							<div class="playoff-options">
								<div class="form-check">
									<input type="checkbox" class="form-check-input" name="has_playoff" id="mexicanoHasPlayoff" value="1" 
										   {{ old('has_playoff') ? 'checked' : '' }} onchange="toggleMexicanoPlayoffType()">
									<label class="form-check-label" for="mexicanoHasPlayoff">
										Добавить плей-офф после основных раундов
									</label>
								</div>
								
								<div id="mexicanoPlayoffTypeOptions" class="mt-3 ms-4" style="display: none;">
									<div class="form-check">
										<input type="radio" class="form-check-input" name="playoff_type" id="mexicanoFinalOnly" value="final_only" 
											   {{ old('playoff_type', 'final_only') === 'final_only' ? 'checked' : '' }}>
										<label class="form-check-label" for="mexicanoFinalOnly">
											Только финал (топ-4: 1+4 vs 2+3)
										</label>
									</div>
									<div class="form-check mt-2">
										<input type="radio" class="form-check-input" name="playoff_type" id="mexicanoSemifinalFinal" value="semifinal_final"
											   {{ old('playoff_type') === 'semifinal_final' ? 'checked' : '' }} onchange="toggleMexicanoPlayoffFormat()">
										<label class="form-check-label" for="mexicanoSemifinalFinal">
											Полуфинал + Финал (топ-8)
										</label>
									</div>
									
									{{-- Выбор формата пар для полуфиналов --}}
									<div id="mexicanoPlayoffFormatOptions" class="mt-3" style="display: none;">
										<label class="form-label">Формат пар в полуфиналах</label>
										<select name="playoff_format" id="mexicanoPlayoffFormat" class="form-select">
											<option value="mix" {{ old('playoff_format', 'mix') === 'mix' ? 'selected' : '' }}>Микс (1+8 vs 4+5, 2+7 vs 3+6)</option>
											<option value="tops" {{ old('playoff_format') === 'tops' ? 'selected' : '' }}>Топы вместе (1+2 vs 7+8, 3+4 vs 5+6)</option>
											<option value="balanced" {{ old('playoff_format') === 'balanced' ? 'selected' : '' }}>Сбалансированный (1+4 vs 5+8, 2+3 vs 6+7)</option>
										</select>
										<small class="text-secondary mt-2 d-block">
											Цифры = места в таблице лидеров после основных раундов
										</small>
									</div>
								</div>
							</div>
						</div>
						
						<div class="alert-success-custom mb-4">
							<i class="bi bi-info-circle me-2"></i>
							<strong>Мексикано:</strong> Пары формируются динамически после каждого раунда. Играют те, кто набрал похожее количество очков. Все играют вместе без разделения на группы.
						</div>
					</div>
					<div id="teamFields" style="display: none;">
						<div class="row">
							<div class="col-md-12 mb-4">
								<label class="form-label">Кто собирает пары *</label>
								<select name="pairing_mode" id="teamPairingMode" class="form-select">
									<option value="self" {{ old('pairing_mode', 'self') === 'self' ? 'selected' : '' }}>Сами игроки (поиск партнёра)</option>
									<option value="admin" {{ old('pairing_mode') === 'admin' ? 'selected' : '' }}>Админ собирает (запись по одному)</option>
								</select>
								<small class="text-secondary">«Админ собирает» — игроки записываются поодиночке, пары вы соберёте перед стартом.</small>
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-4">
								<label class="form-label">Количество групп *</label>
								<select name="groups_count" id="teamGroupsCount" class="form-select" disabled onchange="toggleTeamPlayoffFormat()">
									<option value="1" {{ old('groups_count') == 1 ? 'selected' : '' }}>1 группа</option>
									<option value="2" {{ old('groups_count', 2) == 2 ? 'selected' : '' }}>2 группы</option>
									<option value="3" {{ old('groups_count') == 3 ? 'selected' : '' }}>3 группы</option>
									<option value="4" {{ old('groups_count') == 4 ? 'selected' : '' }}>4 группы</option>
								</select>
							</div>
							<div class="col-md-6 mb-4">
								<label class="form-label">Выходят из группы *</label>
								<select name="teams_advance" id="teamsAdvance" class="form-select" onchange="toggleTeamPlayoffFormat()">
									<option value="1" {{ old('teams_advance') == 1 ? 'selected' : '' }}>1 пара</option>
									<option value="2" {{ old('teams_advance', 2) == 2 ? 'selected' : '' }}>2 пары</option>
									<option value="3" {{ old('teams_advance') == 3 ? 'selected' : '' }}>3 пары</option>
									<option value="4" {{ old('teams_advance') == 4 ? 'selected' : '' }}>4 пары</option>
								</select>
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-4">
								<label class="form-label">Количество кортов</label>
								<input type="number" name="courts_count" class="form-control"
									   value="{{ old('courts_count') }}" min="1" max="32"
									   placeholder="оставьте пустым для авто">
								<small class="text-muted">Если заполнено — матчи группового этапа пойдут волнами, не более N одновременно.</small>
							</div>
						</div>
						<div class="mb-3">
							<div class="form-check">
								<input type="checkbox" name="has_playoff" value="1" id="teamHasPlayoff" class="form-check-input"
									{{ old('type') === 'team' ? (old('has_playoff') ? 'checked' : '') : 'checked' }}
									onchange="toggleTeamPlayoffOptions(); toggleTeamPlayoffFormat();">
								<label for="teamHasPlayoff" class="form-check-label">
									<strong>С плей-офф</strong> <small class="text-muted">(на вылет после групп). Снимите — будет только групповой этап, место по таблице.</small>
								</label>
							</div>
						</div>
						<div id="teamPlayoffOptions" class="row">
							<div class="col-md-12 mb-2" id="teamPlayoffFormatWrap" style="display:none;">
								<label class="form-label" id="teamPlayoffFormatLabel">Формат плей-офф</label>
								<select name="playoff_format" id="teamPlayoffFormat" class="form-select" disabled>
									<option value="">Стандартный (сетка по числу выходящих пар)</option>
									<option value="winners_final" {{ old('playoff_format') === 'winners_final' ? 'selected' : '' }}>Финал первых мест (A1 vs B1), вторые за 3-е место (A2 vs B2)</option>
								</select>
								<small class="text-secondary mt-2 d-block" id="teamPlayoffFormatHint">Доступно при 2 группах и 2 парах, выходящих из группы. A1 = 1-е место группы A. Первые места играют финал, вторые — за 3-е место.</small>
							</div>
							<div class="col-md-6 mb-2">
								<div class="form-check">
									<input type="checkbox" name="has_lower_bracket" value="1" id="hasLowerBracket" class="form-check-input"
										{{ old('has_lower_bracket') ? 'checked' : '' }}>
									<label for="hasLowerBracket" class="form-check-label">
										Нижняя сетка <small class="text-muted">(для проигравших в QF)</small>
									</label>
								</div>
							</div>
							<div class="col-md-6 mb-2">
								<div class="form-check">
									<input type="checkbox" name="has_bronze_match" value="1" id="hasBronzeMatch" class="form-check-input"
										{{ old('has_bronze_match') ? 'checked' : '' }}>
									<label for="hasBronzeMatch" class="form-check-label">
										Матч за 3-е место
									</label>
								</div>
							</div>
						</div>

						<div class="alert-info-custom mb-4">
							<i class="bi bi-info-circle me-2"></i>
							<strong>Групповой + Плей-офф:</strong> Фиксированные пары регистрируются вместе.
							Групповой этап — каждая пара играет с каждой.
							Лучшие выходят в плей-офф (на вылет). Можно отключить плей-офф —
							тогда турнир завершается после групп, место — по таблице.
						</div>
					</div>

					<div id="kingOfCourtFields" style="display: none;">
						<div class="alert-info-custom mb-4">
							<i class="bi bi-info-circle me-2"></i>
							<strong>Король корта:</strong> Игроков должно быть кратно 4 (минимум 8). Кортов = игроков ÷ 4.
							После каждого раунда: победители корта 1 остаются, проигравшие последнего корта остаются,
							остальные двигаются вверх/вниз. Пары перемешиваются каждый раунд.
							Раундов столько, сколько решит админ — кнопка «Завершить турнир» появляется,
							когда последний раунд доигран.
						</div>
						<div class="form-check mb-2">
							<input type="checkbox" name="is_paired" value="1" id="kocFixedPairs" class="form-check-input"
								{{ old('is_paired') ? 'checked' : '' }}>
							<label class="form-check-label" for="kocFixedPairs">
								<strong>Фиксированные пары</strong> — игроки регистрируются по одному, затем админ
								собирает постоянные пары. Пары не перемешиваются, таблица — по парам.
							</label>
						</div>
					</div>

					<div id="justPadelItFields" style="display: none;">
						<div class="alert-success-custom mb-4">
							<i class="bi bi-info-circle me-2"></i>
							<strong>Just Padel It:</strong> Победители переходят на корт выше, проигравшие — ниже. За победу начисляются бонусы: корт 1 → +3, корт 2 → +2, остальные → +1. Число игроков — кратно 4, минимум 8.
						</div>
						<div class="mb-3">
							<div class="form-check">
								<input type="checkbox" class="form-check-input" name="is_paired" value="1" id="jpiFixedPairs"
									   {{ old('is_paired') ? 'checked' : '' }}>
								<label class="form-check-label" for="jpiFixedPairs">
									Фиксированные пары <small class="text-muted">(партнёр на весь турнир). Снимите — случайные пары со сменой партнёров.</small>
								</label>
							</div>
						</div>
						<div class="mb-3">
							<label class="form-label">Ранжирование таблицы</label>
							<div class="form-check">
								<input type="radio" class="form-check-input" name="jpi_rank_by_wins" id="jpiRankPoints" value="0" {{ old('jpi_rank_by_wins') ? '' : 'checked' }}>
								<label class="form-check-label" for="jpiRankPoints">По очкам <small class="text-muted">(по умолчанию)</small></label>
							</div>
							<div class="form-check">
								<input type="radio" class="form-check-input" name="jpi_rank_by_wins" id="jpiRankWins" value="1" {{ old('jpi_rank_by_wins') ? 'checked' : '' }}>
								<label class="form-check-label" for="jpiRankWins">По победам</label>
							</div>
						</div>
					</div>

					<div id="escaleraFields" style="display: none;">
						<div class="alert-info-custom mb-4">
							<i class="bi bi-info-circle me-2"></i>
							<strong>Эскалера:</strong> лестница из кортов, на каждом четверо. За раунд внутри корта
							играются три коротких матча — каждый с каждым в паре. Первый на корте поднимается выше,
							четвёртый опускается ниже, двое средних остаются. В таблицу идут не очки, а позиция игрока
							в общем строю: подняться на корт выше — единственный способ улучшить результат.
						</div>
						<div class="row">
							<div class="col-md-6 mb-4">
								<label class="form-label">Количество кортов *</label>
								<select name="escalera_courts_count" id="escaleraCourtsCount" class="form-select"
										onchange="updateEscaleraParticipants()">
									@for($c = 2; $c <= 10; $c++)
										<option value="{{ $c }}" {{ (int) old('escalera_courts_count', 4) === $c ? 'selected' : '' }}>
											{{ $c }} {{ $c === 2 ? 'корта' : ($c <= 4 ? 'корта' : 'кортов') }} — {{ $c * 4 }} игроков
										</option>
									@endfor
								</select>
								<small class="text-secondary">Число участников проставляется автоматически: корты × 4.</small>
							</div>
						</div>
						<div class="mb-3">
							<label class="form-label">Итоговая таблица</label>
							<div class="form-check">
								<input type="radio" class="form-check-input" name="escalera_standings_mode"
									   id="escaleraModePoints" value="points"
									   {{ old('escalera_standings_mode', 'points') === 'raw_points' ? '' : 'checked' }}>
								<label class="form-check-label" for="escaleraModePoints">
									По баллам за позиции <small class="text-muted">(по умолчанию)</small>
								</label>
							</div>
							<div class="form-check">
								<input type="radio" class="form-check-input" name="escalera_standings_mode"
									   id="escaleraModeRaw" value="raw_points"
									   {{ old('escalera_standings_mode') === 'raw_points' ? 'checked' : '' }}>
								<label class="form-check-label" for="escaleraModeRaw">По сумме очков за матчи</label>
							</div>
							<small class="text-secondary mt-2 d-block">
								По баллам считается родной зачёт формата: номер корта встроен в позицию, поэтому
								игроку выгодно подниматься на корт выше. По сумме очков в зачёт идут все набранные
								очки — тогда игроку становится выгоднее оставаться внизу и обыгрывать более слабых.
							</small>
						</div>
					</div>

					<div id="roundRobinFields" style="display: none;">
						<div class="alert-info-custom mb-4">
							<i class="bi bi-info-circle me-2"></i>
							<strong>Round Robin (индивидуальный):</strong> Игроков кратно 4 (минимум 8). Каждый раунд —
							пары как в Американо (круговая раскладка). После каждого раунда админ жмёт «Следующий раунд»
							или «Завершить турнир» в любой момент. Полный круг для 8 игроков — 7 раундов, дальше можно
							продолжать (8-й раунд = 1-й). Ранжирование: число побед → разница геймов → личная встреча.
							За победу в матче +1 победа, геймы идут в tie-breaker. Ничьих нет.
						</div>
					</div>

					<div id="baliKocFields" style="display: none;">
						<div class="alert-info-custom mb-4">
							<i class="bi bi-info-circle me-2"></i>
							<strong>Король Корта (Bali Format):</strong> Игроков кратно 4 (минимум 8). После набора админ
							вручную создаёт фиксированные пары — пары не миксуются. Ротация как в KOC: победители ↑,
							проигравшие ↓. Очки: раунд 1 — 1/0; со 2-го раунда победитель корта K из N кортов получает
							(N+2−K) очков, проигравший — 0. Турнирная таблица по парам: очки → личная встреча →
							выигранные геймы.
						</div>
					</div>

					<div id="americanoFlexFields" style="display: none;">
						<div class="alert-info-custom mb-4">
							<i class="bi bi-info-circle me-2"></i>
							<strong>Americano Flex:</strong> Адаптивная очередь игроков на M кортах. Подходит при любом
							количестве участников (в т.ч. не кратном 4). Кто отдыхал прошлый раунд — играет в следующем.
							Пары и соперники миксуются с минимизацией повторов. Без групп и плей-офф: админ сам решает,
							когда «Следующий раунд» и когда «Завершить турнир». Минимум: количество_кортов × 4 + 1 игрок.
						</div>
						<div class="row">
							<div class="col-md-6 mb-4">
								<label class="form-label">Количество кортов *</label>
								<input type="number" name="flex_courts_count" id="flexCourtsCount" class="form-control"
									   value="{{ old('flex_courts_count', 2) }}" min="1" max="8"
									   oninput="generateCourtsInputs()">
								<small class="text-secondary">Сколько кортов реально играет. Игроков должно быть больше, чем кортов × 4 (например, 2 корта → от 9 игроков).</small>
							</div>
						</div>
						<div class="mb-3">
							<div class="form-check">
								<input type="checkbox" name="is_paired" value="1" id="flexIsPaired" class="form-check-input"
									{{ old('is_paired') ? 'checked' : '' }}>
								<label for="flexIsPaired" class="form-check-label">
									<strong>Парный</strong> <small class="text-muted">— фиксированные пары (партнёр не меняется). Игроки записываются по одному, пары собирает админ. Число игроков — чётное; на 2 корта: 5 пар = 1 отдыхает.</small>
								</label>
							</div>
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
function togglePrizes() {
    var on = document.getElementById('hasPrizes').checked;
    document.getElementById('prizesWrap').style.display = on ? 'block' : 'none';
}
function toggleTypeFields() {
    const type = document.getElementById('tournamentType').value;
    const americanoFields = document.getElementById('americanoFields');
    const mexicanoFields = document.getElementById('mexicanoFields');
    const teamFields = document.getElementById('teamFields');
    const kingOfCourtFields = document.getElementById('kingOfCourtFields');
    const roundRobinFields = document.getElementById('roundRobinFields');
    const baliKocFields = document.getElementById('baliKocFields');
    const americanoFlexFields = document.getElementById('americanoFlexFields');
    const justPadelItFields = document.getElementById('justPadelItFields');
    const escaleraFields = document.getElementById('escaleraFields');

    // Подсказка "(укажите кол-во пар)" для team турниров
    var reserveHint = document.getElementById('reserveHintPairs');
    if (reserveHint) reserveHint.style.display = (type === 'team') ? 'inline' : 'none';
    var waitlistHint = document.getElementById('waitlistHintPairs');
    if (waitlistHint) waitlistHint.style.display = (type === 'team') ? 'inline' : 'none';

    // Скрываем все
    if (americanoFields) americanoFields.style.display = 'none';
    if (mexicanoFields) mexicanoFields.style.display = 'none';
    if (teamFields) teamFields.style.display = 'none';
    if (kingOfCourtFields) kingOfCourtFields.style.display = 'none';
    if (roundRobinFields) roundRobinFields.style.display = 'none';
    if (baliKocFields) baliKocFields.style.display = 'none';
    if (americanoFlexFields) americanoFlexFields.style.display = 'none';
    if (justPadelItFields) justPadelItFields.style.display = 'none';
    if (escaleraFields) escaleraFields.style.display = 'none';

    // Team-галка «С плей-офф» по умолчанию отмечена и лежит в скрытом блоке —
    // отключаем её для не-team, чтобы она не уходила в submit (has_playoff).
    var teamHasPlayoffCb = document.getElementById('teamHasPlayoff');
    if (teamHasPlayoffCb) teamHasPlayoffCb.disabled = (type !== 'team');

    // Отключаем все playoff_format селекты
    const playoffFormat = document.getElementById('playoffFormat');
    const mexicanoPlayoffFormat = document.getElementById('mexicanoPlayoffFormat');
    const teamPlayoffFormatSelect = document.getElementById('teamPlayoffFormat');
    if (playoffFormat) playoffFormat.disabled = true;
    if (mexicanoPlayoffFormat) mexicanoPlayoffFormat.disabled = true;
    if (teamPlayoffFormatSelect) teamPlayoffFormatSelect.disabled = true;
    
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
        // Включаем американо формат
        if (playoffFormat) playoffFormat.disabled = false;
        
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
        // Включаем мексикано формат
        if (mexicanoPlayoffFormat) mexicanoPlayoffFormat.disabled = false;
        
        generateCourtsInputs();
        toggleMexicanoPlayoffType();
    } else if (type === 'team' && teamFields) {
        teamFields.style.display = 'block';
        document.getElementById('teamGroupsCount').disabled = false;
        if (document.getElementById('americanoGroupsCount')) {
            document.getElementById('americanoGroupsCount').disabled = true;
        }
        toggleTeamPlayoffOptions();
        toggleTeamPlayoffFormat();
        generateCourtsInputs();
    } else if (type === 'king_of_court' && kingOfCourtFields) {
        kingOfCourtFields.style.display = 'block';
        generateCourtsInputs();
    } else if (type === 'round_robin' && roundRobinFields) {
        roundRobinFields.style.display = 'block';
        generateCourtsInputs();
    } else if (type === 'bali_koc' && baliKocFields) {
        baliKocFields.style.display = 'block';
        generateCourtsInputs();
    } else if (type === 'americano_flex' && americanoFlexFields) {
        americanoFlexFields.style.display = 'block';
        generateCourtsInputs();
    } else if (type === 'just_padel_it' && justPadelItFields) {
        justPadelItFields.style.display = 'block';
        generateCourtsInputs();
    } else if (type === 'escalera' && escaleraFields) {
        escaleraFields.style.display = 'block';
    }

    // Эскалера жёстко связывает число участников с числом кортов; для других
    // типов вызов снимает блокировку поля участников.
    updateEscaleraParticipants();
}

// Эскалера: участников всегда кортов × 4, руками поле не правится —
// иначе расстановка по кортам разъедется.
function updateEscaleraParticipants() {
    const type = document.getElementById('tournamentType')?.value;
    const maxInput = document.querySelector('input[name="max_participants"]');
    if (!maxInput) return;

    if (type !== 'escalera') {
        maxInput.readOnly = false;
        return;
    }

    const courts = parseInt(document.getElementById('escaleraCourtsCount')?.value) || 2;
    maxInput.value = courts * 4;
    maxInput.readOnly = true;
    generateCourtsInputs();
}

function toggleTeamPlayoffOptions() {
    const cb = document.getElementById('teamHasPlayoff');
    const opts = document.getElementById('teamPlayoffOptions');
    if (cb && opts) {
        opts.style.display = cb.checked ? 'flex' : 'none';
        if (!cb.checked) {
            const lb = document.getElementById('hasLowerBracket');
            const bm = document.getElementById('hasBronzeMatch');
            if (lb) lb.checked = false;
            if (bm) bm.checked = false;
        }
    }
}

// Формат «финал первых мест» (winners_final) осмыслен только при
// 2 группах и 2 парах, выходящих из группы. В остальных случаях —
// скрываем и отключаем select, чтобы значение не ушло в submit.
function toggleTeamPlayoffFormat() {
    const hasPlayoff = document.getElementById('teamHasPlayoff');
    const groupsCount = document.getElementById('teamGroupsCount');
    const teamsAdvance = document.getElementById('teamsAdvance');
    const wrap = document.getElementById('teamPlayoffFormatWrap');
    const select = document.getElementById('teamPlayoffFormat');

    if (!hasPlayoff || !groupsCount || !teamsAdvance || !wrap || !select) return;

    const groups = parseInt(groupsCount.value);
    const advance = parseInt(teamsAdvance.value);
    const show = hasPlayoff.checked && groups === 2 && advance === 2;

    wrap.style.display = show ? 'block' : 'none';
    select.disabled = !show;
    if (!show) {
        select.value = '';
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
    const semifinalOption = document.getElementById('semifinalOption');
    if (semifinalOption) {
        semifinalOption.style.display = 'block';
    }
}

function togglePlayoffFormat() {
    const semifinalFinal = document.getElementById('semifinalFinal');
    const finalOnly = document.getElementById('finalOnly');
    const hasPlayoff = document.getElementById('hasPlayoff');
    const playoffFormatOptions = document.getElementById('playoffFormatOptions');
    const playoffFormatSelect = document.getElementById('playoffFormat');
    const playoffFormatLabel = document.getElementById('playoffFormatLabel');
    const playoffFormatHint = document.getElementById('playoffFormatHint');
    const groupsCount = document.getElementById('americanoGroupsCount');
    
    if (!playoffFormatOptions || !playoffFormatSelect || !groupsCount || !hasPlayoff) return;
    
    // Скрываем по умолчанию
    playoffFormatOptions.style.display = 'none';

    // Если плей-офф не включен - скрываем сеточные опции и выходим
    if (!hasPlayoff.checked) {
        const bracketOptionsEl = document.getElementById('americanoBracketOptions');
        if (bracketOptionsEl) bracketOptionsEl.style.display = 'none';
        return;
    }
    
    const groups = parseInt(groupsCount.value);
    
    // 2+ группы и полуфинал+финал
    if (groups >= 2 && semifinalFinal && semifinalFinal.checked) {
        playoffFormatOptions.style.display = 'block';
        playoffFormatLabel.textContent = 'Формат пар в полуфиналах';
        playoffFormatHint.textContent = 'A1 = 1-е место группы A, B2 = 2-е место группы B и т.д.';
        playoffFormatSelect.innerHTML = `
            <option value="mix">Микс (A1+B2 vs A3+B4, A2+B1 vs B3+A4)</option>
            <option value="group_vs">Группа vs Группа (A1+A2 vs B1+B2, A3+A4 vs B3+B4)</option>
            <option value="tops">Топы вместе (A1+B1 vs A3+B3, A2+B2 vs A4+B4)</option>
            <option value="cross">Крест (A1+B4 vs B1+A4, A2+B3 vs B2+A3)</option>
        `;
    }
    // 1 группа и только финал
    else if (groups === 1 && finalOnly && finalOnly.checked) {
        playoffFormatOptions.style.display = 'block';
        playoffFormatLabel.textContent = 'Формат пар в финале';
        playoffFormatHint.textContent = '1 = 1-е место, 2 = 2-е место и т.д.';
        playoffFormatSelect.innerHTML = `
            <option value="cross">1+4 vs 2+3 (крест)</option>
            <option value="tops">1+2 vs 3+4 (топы вместе)</option>
            <option value="mix">1+3 vs 2+4 (микс)</option>
        `;
    }
    // 1 группа и полуфинал+финал (топ-8)
    else if (groups === 1 && semifinalFinal && semifinalFinal.checked) {
        playoffFormatOptions.style.display = 'block';
        playoffFormatLabel.textContent = 'Формат пар в полуфиналах';
        playoffFormatHint.textContent = 'Цифры = места в таблице после групп';
        playoffFormatSelect.innerHTML = `
            <option value="mix">Микс (1+8 vs 4+5, 2+7 vs 3+6)</option>
            <option value="tops">Топы вместе (1+2 vs 7+8, 3+4 vs 5+6)</option>
            <option value="balanced">Сбалансированный (1+4 vs 5+8, 2+3 vs 6+7)</option>
        `;
    }

    // Опции сеток Американо
    const bracketOptions = document.getElementById('americanoBracketOptions');
    const bronzeWrap = document.getElementById('americanoBronzeWrap');
    const lowerHint = document.getElementById('americanoLowerHint');
    if (bracketOptions && hasPlayoff) {
        bracketOptions.style.display = hasPlayoff.checked ? 'flex' : 'none';
        const isSemi = semifinalFinal && semifinalFinal.checked;
        // матч за 3-е место осмыслен только при ПФ+финал
        if (bronzeWrap) bronzeWrap.style.display = isSemi ? 'block' : 'none';
        if (!isSemi) {
            const bronze = document.getElementById('americanoBronze');
            if (bronze) bronze.checked = false;
        }
        const minPlayers = isSemi ? 16 : 8;
        if (lowerHint) lowerHint.textContent = 'Для нижней сетки нужно минимум ' + minPlayers + ' участников';
    }
}
function generateCourtsInputs() {
    const type = document.getElementById('tournamentType')?.value;
    let courtsCount;
    if (type === 'americano_flex') {
        // Для Flex количество кортов задаётся вручную, а не считается от игроков
        courtsCount = parseInt(document.getElementById('flexCourtsCount')?.value) || 2;
    } else {
        const maxParticipants = document.querySelector('input[name="max_participants"]')?.value || 16;
        courtsCount = Math.ceil(maxParticipants / 4);
    }
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
function toggleMexicanoPlayoffType() {
    const hasPlayoff = document.getElementById('mexicanoHasPlayoff');
    const playoffTypeOptions = document.getElementById('mexicanoPlayoffTypeOptions');
    
    if (hasPlayoff && playoffTypeOptions) {
        playoffTypeOptions.style.display = hasPlayoff.checked ? 'block' : 'none';
        toggleMexicanoPlayoffFormat();
    }
}

function toggleMexicanoPlayoffFormat() {
    const semifinalFinal = document.getElementById('mexicanoSemifinalFinal');
    const playoffFormatOptions = document.getElementById('mexicanoPlayoffFormatOptions');
    
    if (playoffFormatOptions && semifinalFinal) {
        playoffFormatOptions.style.display = semifinalFinal.checked ? 'block' : 'none';
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
@endsection
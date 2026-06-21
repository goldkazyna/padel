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
								<select name="groups_count" id="teamGroupsCount" class="form-select" disabled>
									<option value="1" {{ old('groups_count') == 1 ? 'selected' : '' }}>1 группа</option>
									<option value="2" {{ old('groups_count', 2) == 2 ? 'selected' : '' }}>2 группы</option>
									<option value="3" {{ old('groups_count') == 3 ? 'selected' : '' }}>3 группы</option>
									<option value="4" {{ old('groups_count') == 4 ? 'selected' : '' }}>4 группы</option>
								</select>
							</div>
							<div class="col-md-6 mb-4">
								<label class="form-label">Выходят из группы *</label>
								<select name="teams_advance" class="form-select">
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
									onchange="toggleTeamPlayoffOptions()">
								<label for="teamHasPlayoff" class="form-check-label">
									<strong>С плей-офф</strong> <small class="text-muted">(на вылет после групп). Снимите — будет только групповой этап, место по таблице.</small>
								</label>
							</div>
						</div>
						<div id="teamPlayoffOptions" class="row">
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
function toggleTypeFields() {
    const type = document.getElementById('tournamentType').value;
    const americanoFields = document.getElementById('americanoFields');
    const mexicanoFields = document.getElementById('mexicanoFields');
    const teamFields = document.getElementById('teamFields');
    const kingOfCourtFields = document.getElementById('kingOfCourtFields');
    const roundRobinFields = document.getElementById('roundRobinFields');
    const baliKocFields = document.getElementById('baliKocFields');
    const americanoFlexFields = document.getElementById('americanoFlexFields');

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

    // Team-галка «С плей-офф» по умолчанию отмечена и лежит в скрытом блоке —
    // отключаем её для не-team, чтобы она не уходила в submit (has_playoff).
    var teamHasPlayoffCb = document.getElementById('teamHasPlayoff');
    if (teamHasPlayoffCb) teamHasPlayoffCb.disabled = (type !== 'team');

    // Отключаем все playoff_format селекты
    const playoffFormat = document.getElementById('playoffFormat');
    const mexicanoPlayoffFormat = document.getElementById('mexicanoPlayoffFormat');
    if (playoffFormat) playoffFormat.disabled = true;
    if (mexicanoPlayoffFormat) mexicanoPlayoffFormat.disabled = true;
    
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
    }
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
</script>
@endsection
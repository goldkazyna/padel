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
						@if($canSwitchType)
							@php
								$registeredNow = $tournament->participants()->wherePivotIn('status', ['registered', 'pending'])->count();
							@endphp
							{{-- Смена формата применяется сразу: форма отправляется и
								 возвращается сюда же, уже с полями нового типа. Иначе
								 организатор правил бы настройки старого формата. --}}
							<input type="hidden" name="apply_type" id="applyType" value="">
							<div class="d-flex gap-2">
								<select name="type" id="tournamentTypeSelect" class="form-select">
									@foreach($switchTypes as $key => $label)
										<option value="{{ $key }}" {{ old('type', $tournament->type) === $key ? 'selected' : '' }}>{{ $label }}</option>
									@endforeach
								</select>
								{{-- Кнопка — основной путь: работает и без JS. Скрипт ниже
									 отправляет форму сам, как только выбран другой формат. --}}
								<button type="submit" name="apply_type" value="1" class="btn-outline-custom" style="white-space: nowrap;">
									Применить
								</button>
							</div>
							<small class="text-secondary">
								Формат меняется прямо здесь, пока турнир не начат — записавшиеся ({{ $registeredNow }}) остаются.
								Выберите формат и нажмите «Применить»: страница сохранится и откроется заново с его настройками.
								Число участников подгонится под него — у Американо, Мексикано, Короля корта, Round Robin
								и Just Padel It оно кратно четырём, у Ladder ровно корты × 4. Если людей станет не хватать,
								турнир просто дождётся остальных.
							</small>
						@else
							<input type="text" class="form-control" value="{{ $tournament->type_name }}" disabled>
							<small class="text-secondary">
								@if($tournament->is_paired || in_array($tournament->type, ['team', 'bali_koc'], true))
									Парный формат менять нельзя: пары уже собраны, в одиночном турнире их некуда перенести.
								@else
									Турнир уже начат — формат менять нельзя.
								@endif
							</small>
						@endif
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

                    @php $prizesOn = old('has_prizes', $tournament->has_prizes); @endphp
                    <div class="mb-4">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="has_prizes" id="hasPrizes" value="1" {{ $prizesOn ? 'checked' : '' }} onchange="togglePrizes()">
                            <label class="form-check-label" for="hasPrizes">Призовой турнир</label>
                        </div>
                        <div id="prizesWrap" class="mt-2" style="display:{{ $prizesOn ? 'block' : 'none' }};">
                            <label class="form-label">Призы</label>
                            <textarea name="prizes" class="form-control" rows="3" maxlength="2000" placeholder="Напишите, какие призы будут: 1 место — …, 2 место — …, и т.д.">{{ old('prizes', $tournament->prizes) }}</textarea>
                        </div>
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

                    @php
                        // Ladder: параметры формата меняем только до старта —
                        // после старта сетка уже разложена по кортам.
                        $isEscalera = $tournament->isEscalera();
                        $escaleraEditable = $isEscalera && in_array($tournament->status, ['draft', 'open'], true);
                    @endphp
                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Макс. участников *</label>
                            <input type="number" name="max_participants" class="form-control"
                                   value="{{ old('max_participants', $tournament->max_participants) }}" min="2" max="128" required
                                   {{ $isEscalera ? 'readonly' : '' }}>
                            @if($isEscalera)
                                <small class="text-secondary">Считается автоматически: кортов × 4.</small>
                            @endif
                        </div>
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Стоимость (₸)</label>
                            <input type="number" name="price" class="form-control"
                                   value="{{ old('price', $tournament->price) }}" min="0">
                        </div>
                        @if($isEscalera)
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Количество кортов *</label>
                            <select name="escalera_courts_count" id="escaleraCourtsCount" class="form-select"
                                    onchange="updateEscaleraParticipants()"
                                    {{ $escaleraEditable ? '' : 'disabled' }}>
                                @for($c = 2; $c <= 10; $c++)
                                    <option value="{{ $c }}" {{ (int) old('escalera_courts_count', $tournament->courts_count ?: 4) === $c ? 'selected' : '' }}>
                                        {{ $c }} {{ $c <= 4 ? 'корта' : 'кортов' }} — {{ $c * 4 }} игроков
                                    </option>
                                @endfor
                            </select>
                            <small class="text-secondary">
                                @if($escaleraEditable)
                                    Число участников пересчитается само: корты × 4.
                                @else
                                    Турнир уже начат — количество кортов менять нельзя.
                                @endif
                            </small>
                        </div>
                        @php $escMode = old('escalera_standings_mode', $tournament->escalera_standings_mode ?? 'raw_points'); @endphp
                        <div class="col-md-12 mb-4">
                            <label class="form-label">Итоговая таблица</label>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" name="escalera_standings_mode"
                                       id="escaleraModePoints" value="points"
                                       {{ $escMode === 'raw_points' ? '' : 'checked' }}
                                       {{ $escaleraEditable ? '' : 'disabled' }}>
                                <label class="form-check-label" for="escaleraModePoints">
                                    По баллам за позиции <small class="text-secondary">(по умолчанию)</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" name="escalera_standings_mode"
                                       id="escaleraModeRaw" value="raw_points"
                                       {{ $escMode === 'raw_points' ? 'checked' : '' }}
                                       {{ $escaleraEditable ? '' : 'disabled' }}>
                                <label class="form-check-label" for="escaleraModeRaw">По сумме очков за матчи
                                    @if($tournament->escalera_win_bonus)
                                        <small class="text-secondary">(плюс бонус: победа +2, ничья +1)</small>
                                    @else
                                        <small class="text-secondary">(турнир создан до появления бонуса за результат — считается только забитое)</small>
                                    @endif
                                </label>
                            </div>
                            @unless($escaleraEditable)
                                <small class="text-secondary">Турнир уже начат — режим таблицы менять нельзя.</small>
                            @endunless
                        </div>
                        @endif
                        @if($tournament->type === 'americano_flex')
                        @php $flexNotStarted = in_array($tournament->status, ['draft', 'open'], true); @endphp
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Количество кортов (Americano Flex)</label>
                            @if($flexNotStarted)
                                <input type="number" name="flex_courts_count" class="form-control"
                                       value="{{ old('flex_courts_count', $tournament->courts_count ?? 2) }}" min="1" max="8">
                                <small class="text-secondary">Сколько кортов реально играет. Каждый раунд играют кортов × 4 игроков, остальные в очереди — значит игроков нужно минимум кортов × 4.</small>
                            @else
                                <input type="text" class="form-control" value="{{ $tournament->courts_count }}" disabled>
                                <small class="text-secondary">Турнир уже начат — изменить нельзя: число кортов задаёт размер раунда, и правка на ходу перекроила бы расписание.</small>
                            @endif
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
                        @if($tournament->isPairedJustPadelIt())
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Кто собирает пары</label>
                            <input type="text" class="form-control" disabled
                                   value="{{ $tournament->isSelfPairing() ? 'Сами игроки (поиск партнёра)' : 'Админ собирает (запись по одному)' }}">
                            <small class="text-secondary">Задаётся при создании. Сменить нельзя: уже записавшиеся перестали бы отображаться — при парной записи они хранятся парами, при одиночной по одному.</small>
                        </div>
                        @endif
                        @if($tournament->isJustPadelIt())
                        @php $jpiByWins = old('jpi_rank_by_wins', $tournament->jpi_rank_by_wins); @endphp
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Ранжирование таблицы</label>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" name="jpi_rank_by_wins" id="jpiRankPoints" value="0" {{ $jpiByWins ? '' : 'checked' }}>
                                <label class="form-check-label" for="jpiRankPoints">По очкам <small class="text-secondary">(по умолчанию)</small></label>
                            </div>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" name="jpi_rank_by_wins" id="jpiRankWins" value="1" {{ $jpiByWins ? 'checked' : '' }}>
                                <label class="form-check-label" for="jpiRankWins">По победам</label>
                            </div>
                        </div>
                        @endif
                        @php $reserveEditable = in_array($tournament->status, ['draft', 'open'], true); @endphp
                        <div class="col-md-6 mb-4">
                            <label class="form-label">Забронировать мест {{ $tournament->isTeamBased() ? '(в парах)' : '' }}</label>
                            @if($reserveEditable)
                                <input type="number" name="reserve_count" class="form-control"
                                       value="{{ old('reserve_count', $reserveCount) }}" min="0" max="10">
                                <small class="text-secondary">Места для знакомых, которых заменишь позже. Уменьшите число — лишние брони снимутся.</small>
                            @else
                                <input type="text" class="form-control" value="{{ $reserveCount }}" disabled>
                                <small class="text-secondary">Турнир уже начат — изменить нельзя.</small>
                            @endif
                        </div>
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
                    {{-- Названия кортов. Количество считаем так же, как на
                         создании: у Flex/Ladder оно задаётся своим полем, у
                         остальных типов — от числа игроков (4 на корт). --}}
                    @php
                        $courtsSaved = is_array($tournament->courts) ? $tournament->courts : [];
                        $courtsOld = old('courts');
                        $courtsAuto = (int) ceil(($tournament->max_participants ?: 4) / 4);
                        $courtsShown = $tournament->courts_count > 0
                            ? (int) $tournament->courts_count
                            : $courtsAuto;
                        $courtsShown = max($courtsShown, count($courtsSaved), 1);
                        $courtsFixed = in_array($tournament->type, ['americano_flex', 'escalera'], true);
                    @endphp
                    <div class="mb-4" id="courtsSection" data-fixed-count="{{ $courtsFixed ? '1' : '0' }}">
                        <label class="form-label">Названия кортов</label>
                        <div id="courtsInputs">
                            @for($i = 0; $i < $courtsShown; $i++)
                            <div class="input-group mb-2">
                                <span class="input-group-text">Корт {{ $i + 1 }}</span>
                                <input type="text" name="courts[]" class="form-control"
                                       value="{{ is_array($courtsOld) ? ($courtsOld[$i] ?? '') : ($courtsSaved[$i] ?? '') }}"
                                       placeholder="Название корта {{ $i + 1 }}">
                            </div>
                            @endfor
                        </div>
                        <small class="text-secondary">Оставьте пустым для "Корт 1", "Корт 2" и т.д.</small>
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
								<select name="groups_count" id="americanoGroupsCount" class="form-select" onchange="togglePlayoffFormat()">
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
						$showFormatBlock = $editIsSemi || $editGroups >= 3 || ($editPlayoffType === 'final_only' && $editGroups === 1);
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
										@if($editGroups >= 3)
											Формат плей-офф
										@elseif($editGroups >= 2)
											Формат пар в полуфиналах
										@elseif($editIsSemi)
											Формат пар в полуфиналах
										@else
											Формат пар в финале
										@endif
									</label>
									<select name="playoff_format" id="playoffFormat" class="form-select">
										@if($editGroups >= 3)
											{{-- 3+ групп: пары по местам в группах не сложить, идём по общей таблице --}}
											<option value="table_qf" selected>Общая таблица (1+4 и 2+3 ждут в полуфинале, 5–12 играют четвертьфинал)</option>
										@elseif($editGroups >= 2)
											<option value="mix" {{ old('playoff_format', $tournament->playoff_format) === 'mix' ? 'selected' : '' }}>Микс (A1+B2 vs A3+B4, A2+B1 vs B3+A4)</option>
											<option value="group_vs" {{ old('playoff_format', $tournament->playoff_format) === 'group_vs' ? 'selected' : '' }}>Группа vs Группа (A1+A2 vs B1+B2, A3+A4 vs B3+B4)</option>
											<option value="tops" {{ old('playoff_format', $tournament->playoff_format) === 'tops' ? 'selected' : '' }}>Топы вместе (A1+B1 vs A3+B3, A2+B2 vs A4+B4)</option>
											<option value="cross" {{ old('playoff_format', $tournament->playoff_format) === 'cross' ? 'selected' : '' }}>Крест (A1+B4 vs B1+A4, A2+B3 vs B2+A3)</option>
											<option value="top_bottom" {{ old('playoff_format', $tournament->playoff_format) === 'top_bottom' ? 'selected' : '' }}>Верх/низ (A1+B3 vs A2+B4, A3+B1 vs A4+B2)</option>
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
									<small class="text-secondary mt-2 d-block" id="playoffFormatHint">
										@if($editGroups >= 3)
											Цифры = места в общей таблице всех групп. Нужно минимум 12 игроков.
										@elseif($editGroups >= 2)
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
					@php $mexNotStarted = in_array($tournament->status, ['draft', 'open'], true); @endphp
					<div class="row">
						<div class="col-md-4 mb-4">
							<label class="form-label">Количество раундов</label>
							@if($mexNotStarted)
								<input type="number" name="rounds_count" class="form-control"
									   value="{{ old('rounds_count', $tournament->rounds_count) }}" min="1" max="30">
								<small class="text-secondary">Сколько раундов сыграть (обычно 5–9).</small>
							@else
								<input type="text" class="form-control" value="{{ $tournament->rounds_count }}" disabled>
								<small class="text-secondary">Турнир уже начат — изменить нельзя. Закончить раньше плана можно кнопкой «Завершить отборочный этап».</small>
							@endif
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
					@if($tournament->type === 'americano_flex')
					@php
						$flexTaken = $tournament->takenSlotsCount();
						$flexPaired = old('is_paired', $tournament->is_paired);
					@endphp
					<div class="mb-4">
						<label class="form-label">Формат записи</label>
						<div class="form-check">
							<input type="checkbox" class="form-check-input" name="is_paired" id="flexIsPaired" value="1"
								   {{ $flexPaired ? 'checked' : '' }} {{ $flexTaken > 0 ? 'disabled' : '' }}>
							<label class="form-check-label" for="flexIsPaired">
								<strong>Парный</strong> — фиксированные пары, партнёр не меняется. Игроки записываются по одному, пары собираете вы. Число игроков должно быть чётным.
							</label>
						</div>
						<small class="text-secondary">
							@if($flexTaken > 0)
								Уже есть записи ({{ $flexTaken }}) — переключать нельзя: в парном режиме игроки хранятся парами, в обычном поодиночке, и половина состава перестала бы отображаться.
							@else
								Снятая галочка — обычный Flex: партнёры и соперники миксуются каждый раунд, таблица личная.
							@endif
						</small>
					</div>
					@endif
					@if($tournament->isKingOfCourt())
					@php
						$kocTaken = $tournament->takenSlotsCount();
						$kocPaired = old('is_paired', $tournament->is_paired);
					@endphp
					<div class="mb-4">
						<label class="form-label">Формат записи</label>
						<div class="form-check">
							<input type="checkbox" class="form-check-input" name="is_paired" id="kocFixedPairs" value="1"
								   {{ $kocPaired ? 'checked' : '' }} {{ $kocTaken > 0 ? 'disabled' : '' }}>
							<label class="form-check-label" for="kocFixedPairs">
								<strong>Фиксированные пары</strong> — игроки записываются по одному, затем пары собираете вы. Пары не перемешиваются, таблица ведётся по парам.
							</label>
						</div>
						<small class="text-secondary">
							@if($kocTaken > 0)
								Уже есть записи ({{ $kocTaken }}) — переключать нельзя: в парном режиме игроки хранятся парами, в обычном поодиночке, и половина состава перестала бы отображаться.
							@else
								Снятая галочка — обычный Король корта: пары перемешиваются каждый раунд, таблица личная.
							@endif
						</small>
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
function togglePrizes() {
    var on = document.getElementById('hasPrizes').checked;
    document.getElementById('prizesWrap').style.display = on ? 'block' : 'none';
}

// Ladder: участников всегда кортов × 4 — поле участников только для чтения
// и пересчитывается при смене количества кортов.
function updateEscaleraParticipants() {
    var courtsSelect = document.getElementById('escaleraCourtsCount');
    var maxInput = document.querySelector('input[name="max_participants"]');
    if (!courtsSelect || !maxInput) return;

    var courts = parseInt(courtsSelect.value) || 2;
    maxInput.value = courts * 4;
}
document.addEventListener('DOMContentLoaded', updateEscaleraParticipants);
// Приводим блок плей-офф к сохранённому состоянию: без этого список форматов
// остаётся серверной разметкой и не знает про смену числа групп.
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('playoffFormat')) togglePlayoffFormat();
});
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

    // Число групп берём из живого селекта: его могли только что поменять,
    // а серверная разметка знает лишь сохранённое значение.
    const groupsSelect = document.getElementById('americanoGroupsCount');
    const amGroupsCount = groupsSelect
        ? parseInt(groupsSelect.value)
        : {{ (int) ($tournament->groups_count ?? 1) }};

    const playoffFormatSelect = document.getElementById('playoffFormat');
    const finalOnly = document.getElementById('finalOnly');

    // 3+ групп играют только по общей таблице: топ-4 ждут в полуфинале,
    // 5–12 играют четвертьфинал. Один финал такую сетку не вместит.
    if (amGroupsCount >= 3) {
        if (semifinalFinal) semifinalFinal.checked = true;
        if (finalOnly) { finalOnly.checked = false; finalOnly.disabled = true; }
    } else if (finalOnly) {
        finalOnly.disabled = false;
    }

    // Список форматов пересобираем под текущее число групп, сохраняя выбор,
    // если он в новом наборе есть.
    if (playoffFormatSelect) {
        const isSemi = semifinalFinal && semifinalFinal.checked;
        let options;
        if (amGroupsCount >= 3) {
            options = [['table_qf', 'Общая таблица (1+4 и 2+3 ждут в полуфинале, 5–12 играют четвертьфинал)']];
        } else if (amGroupsCount >= 2) {
            options = [
                ['mix', 'Микс (A1+B2 vs A3+B4, A2+B1 vs B3+A4)'],
                ['group_vs', 'Группа vs Группа (A1+A2 vs B1+B2, A3+A4 vs B3+B4)'],
                ['tops', 'Топы вместе (A1+B1 vs A3+B3, A2+B2 vs A4+B4)'],
                ['cross', 'Крест (A1+B4 vs B1+A4, A2+B3 vs B2+A3)'],
                ['top_bottom', 'Верх/низ (A1+B3 vs A2+B4, A3+B1 vs A4+B2)'],
            ];
        } else if (isSemi) {
            options = [
                ['mix', 'Микс (1+8 vs 4+5, 2+7 vs 3+6)'],
                ['tops', 'Топы вместе (1+2 vs 7+8, 3+4 vs 5+6)'],
                ['balanced', 'Сбалансированный (1+4 vs 5+8, 2+3 vs 6+7)'],
            ];
        } else {
            options = [
                ['cross', '1+4 vs 2+3 (крест)'],
                ['tops', '1+2 vs 3+4 (топы вместе)'],
                ['mix', '1+3 vs 2+4 (микс)'],
            ];
        }

        const previous = playoffFormatSelect.value;
        playoffFormatSelect.innerHTML = options
            .map(([value, label]) => '<option value="' + value + '">' + label + '</option>')
            .join('');
        if (options.some(([value]) => value === previous)) {
            playoffFormatSelect.value = previous;
        }

        const hint = document.getElementById('playoffFormatHint');
        const label = document.getElementById('playoffFormatLabel');
        if (label) {
            label.textContent = amGroupsCount >= 3
                ? 'Формат плей-офф'
                : (amGroupsCount >= 2 || isSemi ? 'Формат пар в полуфиналах' : 'Формат пар в финале');
        }
        if (hint) {
            hint.textContent = amGroupsCount >= 3
                ? 'Цифры = места в общей таблице всех групп. Нужно минимум 12 игроков.'
                : (amGroupsCount >= 2
                    ? 'A1 = 1-е место группы A, B2 = 2-е место группы B и т.д.'
                    : 'Цифры = места в таблице лидеров после основных раундов');
        }
    }

    if (playoffFormatOptions && semifinalFinal) {
        playoffFormatOptions.style.display =
            (semifinalFinal.checked || amGroupsCount >= 3) ? 'block' : 'none';
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
<script>
// Смена формата: применяем сразу, чтобы дальше правились поля нового типа,
// а не старого. Форма уходит на сохранение и возвращается на эту же страницу.
(function () {
    const select = document.getElementById('tournamentTypeSelect');
    const flag = document.getElementById('applyType');
    if (!select || !flag) return;

    const initial = select.value;
    select.addEventListener('change', function () {
        if (select.value === initial) return;
        flag.value = '1';
        select.form.submit();
    });
})();
</script>
<script>
// Названия кортов: у большинства типов число кортов считается от числа
// игроков (4 на корт), поэтому пересобираем поля при правке «Макс.
// участников». Уже введённые названия переносим в новые поля.
// У Flex и Ladder корты задаются своим полем — там секция помечена
// data-fixed-count="1" и не трогается.
(function () {
    const section = document.getElementById('courtsSection');
    if (!section || section.dataset.fixedCount === '1') return;

    const container = document.getElementById('courtsInputs');
    const maxInput = document.querySelector('input[name="max_participants"]');
    if (!container || !maxInput) return;

    function rebuild() {
        const players = parseInt(maxInput.value) || 4;
        const needed = Math.max(1, Math.ceil(players / 4));
        const inputs = container.querySelectorAll('input[name="courts[]"]');
        if (inputs.length === needed) return;

        const values = Array.from(inputs).map((input) => input.value);
        container.innerHTML = '';

        for (let i = 1; i <= needed; i++) {
            const group = document.createElement('div');
            group.className = 'input-group mb-2';

            const label = document.createElement('span');
            label.className = 'input-group-text';
            label.textContent = 'Корт ' + i;

            const input = document.createElement('input');
            input.type = 'text';
            input.name = 'courts[]';
            input.className = 'form-control';
            input.placeholder = 'Название корта ' + i;
            input.value = values[i - 1] || '';

            group.appendChild(label);
            group.appendChild(input);
            container.appendChild(group);
        }
    }

    maxInput.addEventListener('input', rebuild);
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

{{-- resources/views/club/tournaments/partials/_team_groups.blade.php --}}
<div class="card-dark mb-4">
    <div class="card-header-dark">
        <h5 class="mb-0"><i class="bi bi-collection text-info me-2"></i>Групповой этап</h5>
    </div>
    <div class="card-body-dark">
        <div class="row">
            @foreach($tournament->teamGroups as $group)
                <div class="col-lg-6 mb-4">
                    <div class="group-card">
                        <div class="group-header">
                            <h6 class="mb-0">{{ $group->name }}</h6>
                            @if($group->isCompleted())
                                <span class="badge bg-success">Завершена</span>
                            @else
                                <span class="badge bg-primary">В игре</span>
                            @endif
                        </div>
                        
                        {{-- Таблица группы --}}
						@php
							$sortedStandings = app(\App\Services\TeamTournamentService::class)->getSortedStandings($group);
						@endphp
						<div class="table-responsive mb-3">
							<table class="table table-dark table-sm mb-0">
								<thead>
									<tr>
										<th style="width: 30px;">#</th>
										<th>Пара</th>
										<th class="text-center" title="Сыграно">И</th>
										<th class="text-center" title="Победы">В</th>
										<th class="text-center" title="Поражения">П</th>
										<th class="text-center" title="Забито">ЗМ</th>
										<th class="text-center" title="Пропущено">ПМ</th>
										<th class="text-center" title="Разница">+/-</th>
										<th class="text-center" title="Очки"><strong>О</strong></th>
									</tr>
								</thead>
								<tbody>
									@foreach($sortedStandings as $index => $standing)
										@php $team = \App\Models\TournamentTeam::find($standing['team_id']); @endphp
                                        <tr class="{{ $index < $tournament->teams_advance ? 'table-success-custom' : '' }}">
											<td>{{ $index + 1 }}</td>
											<td><span class="team-name-cell">{{ $team->name }}</span></td>
											<td class="text-center">{{ $standing['played'] }}</td>
											<td class="text-center">{{ $standing['won'] }}</td>
											<td class="text-center">{{ $standing['lost'] }}</td>
											<td class="text-center">{{ $standing['points_for'] }}</td>
											<td class="text-center">{{ $standing['points_against'] }}</td>
											<td class="text-center">
												@php $diff = $standing['points_for'] - $standing['points_against']; @endphp
												<span class="{{ $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : '') }}">
													{{ $diff > 0 ? '+' : '' }}{{ $diff }}
												</span>
											</td>
											<td class="text-center"><strong>{{ $standing['points'] }}</strong></td>
										</tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        
                        {{-- Матчи группы --}}
                        <div class="group-matches">
                            <h6 class="text-secondary mb-2"><i class="bi bi-calendar3 me-1"></i>Матчи</h6>
                            @php $matchesByRound = $group->matches->groupBy('round_number'); @endphp
                            @foreach($matchesByRound as $roundNumber => $matches)
                                <div class="round-block mb-2">
                                    <div class="round-label">Тур {{ $roundNumber }}</div>
                                    @foreach($matches as $match)
										<div class="group-match-card" data-match-id="{{ $match->id }}">
											@if($match->court_number)
												<div class="match-court-header">
													<i class="bi bi-geo-alt"></i> {{ $tournament->getCourtName($match->court_number) }}
												</div>
											@endif
											<div class="match-team-name {{ $match->winner_id === $match->team1_id ? 'winner' : '' }}">
												{{ $match->team1->name }}
											</div>
											<div class="match-score-block">
												@if($match->isCompleted())
													<span class="match-score">{{ $match->team1_score }} : {{ $match->team2_score }}</span>
													<button class="btn-score-edit-sm" data-bs-toggle="modal" data-bs-target="#editGroupMatchModal{{ $match->id }}">
														<i class="bi bi-pencil"></i>
													</button>
												@elseif($match->status === 'in_progress')
													<button class="btn-score-sm" data-bs-toggle="modal" data-bs-target="#groupMatchModal{{ $match->id }}">
														<i class="bi bi-pencil-square"></i> Счёт
													</button>
												@else
													<span class="text-secondary">—</span>
												@endif
											</div>
											<div class="match-team-name {{ $match->winner_id === $match->team2_id ? 'winner' : '' }}">
												{{ $match->team2->name }}
											</div>
										</div>

                                        {{-- Модалка ввода счёта --}}
                                        @if($match->status === 'in_progress' && !$match->isCompleted())
                                        <div class="modal fade" id="groupMatchModal{{ $match->id }}" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content modal-dark">
                                                    <div class="modal-header border-0">
                                                        <h5 class="modal-title">{{ $group->name }} — Тур {{ $roundNumber }}</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form action="{{ route('club.team.saveGroupMatchScore', $match) }}" method="POST">
                                                        @csrf
                                                        <div class="modal-body">
                                                            <div class="score-input-grid">
                                                                <div class="score-team">
                                                                    <div class="score-team-names">{{ $match->team1->name }}</div>
                                                                    <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" max="99" required placeholder="0">
                                                                </div>
                                                                <div class="score-separator">:</div>
                                                                <div class="score-team">
                                                                    <div class="score-team-names">{{ $match->team2->name }}</div>
                                                                    <input type="number" name="team2_score" class="form-control form-control-lg text-center" min="0" max="99" required placeholder="0">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer border-0">
                                                            <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Отмена</button>
                                                            <button type="submit" class="btn-primary-custom"><i class="bi bi-check-lg me-1"></i> Сохранить</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        @endif

                                        {{-- Модалка редактирования --}}
                                        @if($match->isCompleted())
                                        <div class="modal fade" id="editGroupMatchModal{{ $match->id }}" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content modal-dark">
                                                    <div class="modal-header border-0">
                                                        <h5 class="modal-title">Редактировать — {{ $group->name }}</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form action="{{ route('club.team.updateGroupMatchScore', $match) }}" method="POST">
                                                        @csrf
                                                        @method('PUT')
                                                        <div class="modal-body">
                                                            <div class="score-input-grid">
                                                                <div class="score-team">
                                                                    <div class="score-team-names">{{ $match->team1->name }}</div>
                                                                    <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" max="99" required value="{{ $match->team1_score }}">
                                                                </div>
                                                                <div class="score-separator">:</div>
                                                                <div class="score-team">
                                                                    <div class="score-team-names">{{ $match->team2->name }}</div>
                                                                    <input type="number" name="team2_score" class="form-control form-control-lg text-center" min="0" max="99" required value="{{ $match->team2_score }}">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer border-0">
                                                            <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Отмена</button>
                                                            <button type="submit" class="btn-primary-custom"><i class="bi bi-check-lg me-1"></i> Обновить</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        
        {{-- Кнопка генерации плей-офф --}}
        @php $groupStageCompleted = app(\App\Services\TeamTournamentService::class)->isGroupStageCompleted($tournament); @endphp
        @if($groupStageCompleted && $tournament->playoffMatches->count() === 0)
            <div class="text-center mt-4">
                <form action="{{ route('club.team.generatePlayoff', $tournament) }}" method="POST" onsubmit="return confirm('Сгенерировать сетку плей-офф?')">
                    @csrf
                    <button type="submit" class="btn-primary-custom btn-lg">
                        <i class="bi bi-diagram-3 me-2"></i> Сгенерировать плей-офф
                    </button>
                </form>
            </div>
        @elseif(!$groupStageCompleted)
            <div class="text-center mt-3">
                <span class="text-secondary"><i class="bi bi-hourglass-split me-1"></i> Завершите все матчи группового этапа</span>
            </div>
        @endif
    </div>
</div>

<style>
.match-court-header {
    text-align: center;
    font-size: 0.8rem;
    color: #0dcaf0;
    background: rgba(13, 202, 240, 0.1);
    padding: 6px 16px;
    border-radius: 20px;
    font-weight: 600;
    margin-right: 12px;
    display: inline-block;
    width: 100px;
}
</style>
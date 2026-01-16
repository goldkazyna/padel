{{-- resources/views/club/tournaments/partials/_team_playoff.blade.php --}}
<div class="card-dark mb-4">
    <div class="card-header-dark">
        <h5 class="mb-0"><i class="bi bi-trophy text-warning me-2"></i>Плей-офф</h5>
    </div>
    <div class="card-body-dark">
        @php
            $stages = $tournament->playoffMatches->groupBy('stage');
            $stageOrder = ['quarter' => '1/4 финала', 'semi' => 'Полуфинал', 'final' => 'Финал'];
        @endphp
        
        <div class="playoff-bracket">
            @foreach($stageOrder as $stageKey => $stageName)
                @if(isset($stages[$stageKey]))
                    <div class="playoff-stage">
                        <div class="stage-title">{{ $stageName }}</div>
                        <div class="stage-matches">
                            @foreach($stages[$stageKey] as $match)
								<div class="playoff-match-card {{ $match->isCompleted() ? 'completed' : '' }}">
									@if($match->court_number)
										<div class="match-court-header-playoff">
											<i class="bi bi-geo-alt"></i> {{ $tournament->getCourtName($match->court_number) }}
										</div>
									@endif
									<div class="playoff-team {{ $match->winner_id === $match->team1_id ? 'winner' : '' }}">
										<span class="playoff-team-name">{{ $match->team1 ? $match->team1->name : $match->team1_source }}</span>
										@if($match->isCompleted())
											<span class="playoff-score">{{ $match->team1_score }}</span>
										@endif
									</div>
									<div class="playoff-team {{ $match->winner_id === $match->team2_id ? 'winner' : '' }}">
										<span class="playoff-team-name">{{ $match->team2 ? $match->team2->name : $match->team2_source }}</span>
										@if($match->isCompleted())
											<span class="playoff-score">{{ $match->team2_score }}</span>
										@endif
									</div>
									<div class="playoff-actions">
										@if($match->status === 'in_progress' && $match->team1_id && $match->team2_id)
											<button class="btn-score-sm" data-bs-toggle="modal" data-bs-target="#playoffModal{{ $match->id }}">
												<i class="bi bi-pencil-square"></i>
											</button>
										@elseif($match->isCompleted())
											<button class="btn-score-edit-sm" data-bs-toggle="modal" data-bs-target="#editPlayoffModal{{ $match->id }}">
												<i class="bi bi-pencil"></i>
											</button>
										@endif
									</div>
								</div>

                                {{-- Модалка ввода --}}
                                @if($match->status === 'in_progress' && $match->team1_id && $match->team2_id)
                                <div class="modal fade" id="playoffModal{{ $match->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content modal-dark">
                                            <div class="modal-header border-0">
                                                <h5 class="modal-title">{{ $stageName }}</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form action="{{ route('club.team.savePlayoffScore', $match) }}" method="POST">
                                                @csrf
                                                <div class="modal-body">
                                                    <div class="score-input-grid">
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $match->team1->name }}</div>
                                                            <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required placeholder="0">
                                                        </div>
                                                        <div class="score-separator">:</div>
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $match->team2->name }}</div>
                                                            <input type="number" name="team2_score" class="form-control form-control-lg text-center" min="0" required placeholder="0">
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
                                <div class="modal fade" id="editPlayoffModal{{ $match->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content modal-dark">
                                            <div class="modal-header border-0">
                                                <h5 class="modal-title">Редактировать — {{ $stageName }}</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form action="{{ route('club.team.updatePlayoffScore', $match) }}" method="POST">
                                                @csrf
                                                @method('PUT')
                                                <div class="modal-body">
                                                    <div class="score-input-grid">
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $match->team1->name }}</div>
                                                            <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required value="{{ $match->team1_score }}">
                                                        </div>
                                                        <div class="score-separator">:</div>
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $match->team2->name }}</div>
                                                            <input type="number" name="team2_score" class="form-control form-control-lg text-center" min="0" required value="{{ $match->team2_score }}">
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
                    </div>
                @endif
            @endforeach
        </div>

        {{-- Победитель --}}
        @php $finalMatch = $tournament->playoffMatches->where('stage', 'final')->first(); @endphp
        @if($finalMatch && $finalMatch->isCompleted())
            <div class="winner-block mt-4">
                <div class="winner-trophy"><i class="bi bi-trophy-fill"></i></div>
                <div class="winner-title">Победитель турнира</div>
                <div class="winner-name">{{ $finalMatch->winner->name }}</div>
                <div class="winner-players">{{ $finalMatch->winner->full_name }}</div>
            </div>
        @endif
    </div>
</div>


<style>
.match-court-header-playoff {
    text-align: center;
    font-size: 0.8rem;
    color: #0dcaf0;
    background: rgba(13, 202, 240, 0.1);
    padding: 6px 16px;
    border-radius: 20px;
    font-weight: 600;
    margin-bottom: 10px;
	margin-top:10px;
    display: inline-block;
    width: 100%;
}
</style>
{{-- resources/views/club/tournaments/mexicano/partials/_rounds.blade.php --}}
<div>
    <h6 class="text-white mb-3"><i class="bi bi-layers-fill text-primary me-2"></i>Раунды</h6>
    <div class="rounds-grid">
        @foreach($tournament->mexicanoRounds as $round)
            <div class="round-card" data-round-id="{{ $round->id }}">
                <div class="round-header">
                    <div class="round-title">
                        @if($round->isCompleted())
                            <i class="bi bi-check-circle-fill text-success"></i>
                        @elseif($round->isInProgress())
                            <i class="bi bi-play-circle-fill text-primary"></i>
                        @else
                            <i class="bi bi-clock text-secondary"></i>
                        @endif
                        Раунд {{ $round->round_number }}
                    </div>
                    <span class="round-status {{ $round->status }}">
                        {{ $round->isCompleted() ? 'Завершён' : ($round->isInProgress() ? 'Идёт' : 'Ожидание') }}
                    </span>
                </div>
                <div class="round-matches">
                    @foreach($round->matches as $match)
                        <div class="match-card" data-match-id="{{ $match->id }}">
							@if($match->court_number)
								<div class="match-court-header">
									<i class="bi bi-geo-alt"></i> {{ $tournament->getCourtName($match->court_number) }}
								</div>
							@endif
							<div class="match-teams">
								<div class="match-team {{ $match->winning_team === 1 ? 'winner' : '' }}">
									<div class="team-players">
										<div class="player-line">{{ $match->team1Player1->full_name }} <span class="player-level">{{ $match->team1Player1->level }}</span></div>
										<div class="player-line">{{ $match->team1Player2->full_name }} <span class="player-level">{{ $match->team1Player2->level }}</span></div>
									</div>
									@if($match->isCompleted())
										<div class="team-score">{{ $match->team1_score }}</div>
									@endif
								</div>
								
								<div class="match-vs">
									@if($match->isCompleted())
										<button class="btn-score-edit" 
												data-bs-toggle="modal" 
												data-bs-target="#editScoreModal{{ $match->id }}"
												title="Редактировать счёт">
											<i class="bi bi-pencil"></i>
										</button>
									@elseif($round->isInProgress())
										<button class="btn-score" 
												data-bs-toggle="modal" 
												data-bs-target="#scoreModal{{ $match->id }}">
											<i class="bi bi-pencil-square"></i>
										</button>
									@else
										<span class="vs-pending">VS</span>
									@endif
								</div>
								
								<div class="match-team {{ $match->winning_team === 2 ? 'winner' : '' }}">
									@if($match->isCompleted())
										<div class="team-score">{{ $match->team2_score }}</div>
									@endif
									<div class="team-players">
										<div class="player-line">{{ $match->team2Player1->full_name }} <span class="player-level">{{ $match->team2Player1->level }}</span></div>
										<div class="player-line">{{ $match->team2Player2->full_name }} <span class="player-level">{{ $match->team2Player2->level }}</span></div>
									</div>
								</div>
							</div>
						</div>

                        {{-- Модалка ввода счёта --}}
                        @if($round->isInProgress() && !$match->isCompleted())
                            <div class="modal fade" id="scoreModal{{ $match->id }}" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content modal-dark">
                                        <div class="modal-header border-0">
                                            <h5 class="modal-title">Ввести счёт</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form action="{{ route('club.mexicano.saveScore', $match) }}" method="POST">
                                            @csrf
                                            <div class="modal-body">
                                                <div class="score-input-grid">
                                                    <div class="score-team">
                                                        <div class="score-team-names">
                                                            {{ $match->team1Player1->first_name }} / {{ $match->team1Player2->first_name }}
                                                        </div>
                                                        <input type="number" name="team1_score" class="form-control form-control-lg text-center" 
                                                               min="0" max="99" required>
                                                    </div>
                                                    <div class="score-separator">:</div>
                                                    <div class="score-team">
                                                        <div class="score-team-names">
                                                            {{ $match->team2Player1->first_name }} / {{ $match->team2Player2->first_name }}
                                                        </div>
                                                        <input type="number" name="team2_score" class="form-control form-control-lg text-center" 
                                                               min="0" max="99" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-0">
                                                <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Отмена</button>
                                                <button type="submit" class="btn-primary-custom">
                                                    <i class="bi bi-check-lg"></i> Сохранить
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endif

                        {{-- Модалка редактирования счёта --}}
                        @if($match->isCompleted())
                            <div class="modal fade" id="editScoreModal{{ $match->id }}" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content modal-dark">
                                        <div class="modal-header border-0">
                                            <h5 class="modal-title">Редактировать счёт</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form action="{{ route('club.mexicano.updateScore', $match) }}" method="POST">
                                            @csrf
                                            @method('PUT')
                                            <div class="modal-body">
                                                <div class="score-input-grid">
                                                    <div class="score-team">
                                                        <div class="score-team-names">
                                                            {{ $match->team1Player1->first_name }} / {{ $match->team1Player2->first_name }}
                                                        </div>
                                                        <input type="number" name="team1_score" class="form-control form-control-lg text-center" 
                                                               min="0" max="99" required value="{{ $match->team1_score }}">
                                                    </div>
                                                    <div class="score-separator">:</div>
                                                    <div class="score-team">
                                                        <div class="score-team-names">
                                                            {{ $match->team2Player1->first_name }} / {{ $match->team2Player2->first_name }}
                                                        </div>
                                                        <input type="number" name="team2_score" class="form-control form-control-lg text-center" 
                                                               min="0" max="99" required value="{{ $match->team2_score }}">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-0">
                                                <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Отмена</button>
                                                <button type="submit" class="btn-primary-custom">
                                                    <i class="bi bi-check-lg"></i> Обновить
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- Кнопка следующего раунда --}}
    @if($tournament->status === 'in_progress')
        @php
            $canGenerateNext = app(\App\Services\MexicanoService::class)->canGenerateNextRound($tournament);
            $currentRoundNumber = $tournament->mexicanoRounds->max('round_number') ?? 0;
        @endphp
        
        @if($canGenerateNext)
            <div class="text-center mt-4">
                <form action="{{ route('club.mexicano.nextRound', $tournament) }}" method="POST"
                      onsubmit="return confirm('Сгенерировать раунд {{ $currentRoundNumber + 1 }}? Пары будут составлены по текущим очкам.')">
                    @csrf
                    <button type="submit" class="btn-primary-custom btn-lg">
                        <i class="bi bi-plus-circle me-2"></i> Сгенерировать раунд {{ $currentRoundNumber + 1 }}
                    </button>
                </form>
            </div>
        @endif
    @endif
</div>


<style>
.match-card {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.match-teams {
    display: flex;
    align-items: center;
    width: 100%;
    justify-content: space-between;
}
.match-court-header {
    text-align: center;
    font-size: 0.8rem;
    color: #0dcaf0;
    background: rgba(13, 202, 240, 0.1);
    padding: 6px 16px;
    border-radius: 20px;
    font-weight: 600;
    margin-bottom: 12px;
    display: inline-block;
    width: 100%;
}
</style>
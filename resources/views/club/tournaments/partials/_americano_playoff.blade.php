{{-- resources/views/club/tournaments/partials/_americano_playoff.blade.php --}}
<hr/>
@if($tournament->isAmericano() && $tournament->hasPlayoff() && $tournament->playoffMatches()->count() > 0)
<div class="mb-4">
    <div class="card-header-dark">
        <h5 class="mb-0"><i class="bi bi-trophy text-warning me-2"></i>Плей-офф</h5>
    </div>
    <div class="card-body-dark">
        @php
            $stages = $tournament->playoffMatches->groupBy('stage');
            $stageOrder = ['Полуфинал' => 'Полуфинал', 'Финал' => 'Финал'];
        @endphp
        
        <div class="playoff-bracket">
            @foreach($stageOrder as $stageKey => $stageName)
                @if(isset($stages[$stageKey]))
                    <div class="playoff-stage">
                        <div class="stage-title">{{ $stageName }}</div>
                        <div class="stage-matches">
                            @foreach($stages[$stageKey] as $match)
                                @php
                                    $team1Name = $match->team1Player1 && $match->team1Player2 
									? $match->team1Player1->name . ' / ' . $match->team1Player2->name 
									: 'Ожидание...';
								$team2Name = $match->team2Player1 && $match->team2Player2 
									? $match->team2Player1->name . ' / ' . $match->team2Player2->name 
									: 'Ожидание...';
                                    $team1Wins = $match->status === 'completed' && $match->team1_score > $match->team2_score;
                                    $team2Wins = $match->status === 'completed' && $match->team2_score > $match->team1_score;
                                @endphp
                                <div class="playoff-match-card {{ $match->status === 'completed' ? 'completed' : '' }}">
                                    <div class="playoff-team {{ $team1Wins ? 'winner' : '' }}">
                                        <span class="playoff-team-name">{{ $team1Name }}</span>
                                        @if($match->status === 'completed')
                                            <span class="playoff-score">{{ $match->team1_score }}</span>
                                        @endif
                                    </div>
                                    <div class="playoff-team {{ $team2Wins ? 'winner' : '' }}">
                                        <span class="playoff-team-name">{{ $team2Name }}</span>
                                        @if($match->status === 'completed')
                                            <span class="playoff-score">{{ $match->team2_score }}</span>
                                        @endif
                                    </div>
                                    <div class="playoff-actions">
                                        @if($match->status === 'pending' && $match->team1_player1_id && $match->team2_player1_id)
                                            <button class="btn-score-sm" data-bs-toggle="modal" data-bs-target="#americanoPlayoffModal{{ $match->id }}">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        @elseif($match->status === 'completed' && $tournament->status !== 'completed')
                                            <button class="btn-score-edit-sm" data-bs-toggle="modal" data-bs-target="#editAmericanoPlayoffModal{{ $match->id }}">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                {{-- Модалка ввода --}}
                                @if($match->status === 'pending' && $match->team1_player1_id && $match->team2_player1_id)
                                <div class="modal fade" id="americanoPlayoffModal{{ $match->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content modal-dark">
                                            <div class="modal-header border-0">
                                                <h5 class="modal-title">{{ $stageName }}</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form action="{{ route('club.americano.savePlayoffScore', $match) }}" method="POST">
                                                @csrf
                                                <div class="modal-body">
                                                    <div class="score-input-grid">
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $team1Name }}</div>
                                                            <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required placeholder="0">
                                                        </div>
                                                        <div class="score-separator">:</div>
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $team2Name }}</div>
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
                                @if($match->status === 'completed')
                                <div class="modal fade" id="editAmericanoPlayoffModal{{ $match->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content modal-dark">
                                            <div class="modal-header border-0">
                                                <h5 class="modal-title">Редактировать — {{ $stageName }}</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form action="{{ route('club.americano.updatePlayoffScore', $match) }}" method="POST">
                                                @csrf
                                                @method('PUT')
                                                <div class="modal-body">
                                                    <div class="score-input-grid">
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $team1Name }}</div>
                                                            <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required value="{{ $match->team1_score }}">
                                                        </div>
                                                        <div class="score-separator">:</div>
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $team2Name }}</div>
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
        @php $finalMatch = $tournament->playoffMatches->where('stage', 'Финал')->first(); @endphp
        @if($finalMatch && $finalMatch->status === 'completed')
            @php
                $winnerP1 = $finalMatch->team1_score > $finalMatch->team2_score ? $finalMatch->team1Player1 : $finalMatch->team2Player1;
                $winnerP2 = $finalMatch->team1_score > $finalMatch->team2_score ? $finalMatch->team1Player2 : $finalMatch->team2Player2;
            @endphp
            <div class="winner-block mt-4">
                <div class="winner-trophy"><i class="bi bi-trophy-fill"></i></div>
                <div class="winner-title">Победители турнира</div>
                <div class="winner-name">{{ $winnerP1->first_name }} / {{ $winnerP2->first_name }}</div>
                <div class="winner-players">{{ $winnerP1->name }} & {{ $winnerP2->name }}</div>
            </div>
        @endif
    </div>
</div>
@endif
<style>
.playoff-stage {
    min-width: 700px;
}
.playoff-team-name{
	    max-width: 700px;
		font-size:35px;
}
.score-team-names{
	font-size:24px;
}
.playoff-score{
	font-size:40px;
}
</style>
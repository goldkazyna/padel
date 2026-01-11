{{-- resources/views/club/tournaments/mexicano/partials/_playoff.blade.php --}}
@if($tournament->hasPlayoff() && $tournament->playoffMatches()->count() > 0)
<div class="card-dark mb-4">
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
                                        ? $match->team1Player1->full_name . ' / ' . $match->team1Player2->full_name 
                                        : 'Ожидание...';
                                    $team2Name = $match->team2Player1 && $match->team2Player2 
                                        ? $match->team2Player1->full_name . ' / ' . $match->team2Player2->full_name 
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
                                            <button class="btn-score-sm" data-bs-toggle="modal" data-bs-target="#mexicanoPlayoffModal{{ $match->id }}">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        @elseif($match->status === 'completed')
                                            <button class="btn-score-edit-sm" data-bs-toggle="modal" data-bs-target="#editMexicanoPlayoffModal{{ $match->id }}">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                {{-- Модалка ввода счёта --}}
                                @if($match->status === 'pending' && $match->team1_player1_id && $match->team2_player1_id)
                                <div class="modal fade" id="mexicanoPlayoffModal{{ $match->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content modal-dark">
                                            <div class="modal-header border-0">
                                                <h5 class="modal-title">{{ $stageName }} — Ввод счёта</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form action="{{ route('club.mexicano.savePlayoffScore', $match) }}" method="POST">
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
                                @if($match->status === 'completed')
                                <div class="modal fade" id="editMexicanoPlayoffModal{{ $match->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content modal-dark">
                                            <div class="modal-header border-0">
                                                <h5 class="modal-title">{{ $stageName }} — Редактировать счёт</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form action="{{ route('club.mexicano.updatePlayoffScore', $match) }}" method="POST">
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
                @endif
            @endforeach
        </div>

        {{-- Победитель --}}
        @php
            $finalMatch = $tournament->playoffMatches()->where('stage', 'Финал')->first();
        @endphp
        @if($finalMatch && $finalMatch->status === 'completed')
            @php
                $winners = $finalMatch->team1_score > $finalMatch->team2_score
                    ? [$finalMatch->team1Player1, $finalMatch->team1Player2]
                    : [$finalMatch->team2Player1, $finalMatch->team2Player2];
            @endphp
            <div class="winner-block mt-4">
                <div class="winner-trophy">🏆</div>
                <div class="winner-title">Победители</div>
                <div class="winner-name">{{ $winners[0]->full_name }} / {{ $winners[1]->full_name }}</div>
            </div>
        @endif
    </div>
</div>
@endif

<style>
.playoff-stage {
    min-width: 391px;
}
.playoff-team-name{
	    max-width: 340px;
}
</style>
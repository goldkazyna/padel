{{-- resources/views/club/tournaments/partials/_americano_playoff_match.blade.php --}}
{{-- Variables: $match, $stageName, $tournament --}}
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

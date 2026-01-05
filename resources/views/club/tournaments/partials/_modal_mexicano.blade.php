@if($type === 'input')
<div class="modal fade" id="mexicanoScoreModal{{ $match->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-dark">
            <div class="modal-header border-0">
                <h5 class="modal-title">Ввод счёта — Раунд {{ $round->round_number }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('club.mexicano.saveScore', $match) }}" method="POST" data-ajax-score data-match-id="{{ $match->id }}" data-mexicano="true">
                @csrf
                <div class="modal-body">
                    <div class="score-input-grid">
                        <div class="score-team">
                            <div class="score-team-names">{{ $match->team1Player1->first_name }} / {{ $match->team1Player2->first_name }}</div>
                            <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" max="99" required placeholder="0">
                        </div>
                        <div class="score-separator">:</div>
                        <div class="score-team">
                            <div class="score-team-names">{{ $match->team2Player1->first_name }} / {{ $match->team2Player2->first_name }}</div>
                            <input type="number" name="team2_score" class="form-control form-control-lg text-center" min="0" max="99" required placeholder="0">
                        </div>
                    </div>
                    <div class="text-center text-secondary mt-2">
                        <small>Сумма должна быть {{ $tournament->points_to_win }}</small>
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
@else
<div class="modal fade" id="editMexicanoScoreModal{{ $match->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-dark">
            <div class="modal-header border-0">
                <h5 class="modal-title">Редактировать — Раунд {{ $round->round_number }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('club.mexicano.updateScore', $match) }}" method="POST" data-ajax-score data-match-id="{{ $match->id }}" data-mexicano="true">
                @csrf
                @method('PUT')
                <div class="modal-body">
                    <div class="score-input-grid">
                        <div class="score-team">
                            <div class="score-team-names">{{ $match->team1Player1->first_name }} / {{ $match->team1Player2->first_name }}</div>
                            <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" max="99" required value="{{ $match->team1_score }}">
                        </div>
                        <div class="score-separator">:</div>
                        <div class="score-team">
                            <div class="score-team-names">{{ $match->team2Player1->first_name }} / {{ $match->team2Player2->first_name }}</div>
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
<div class="modal fade" id="{{ $modalId }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-dark">
            <div class="modal-header">
                <h5 class="modal-title">Ввод счёта</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route($route, $match) }}" method="POST" 
                  data-ajax-score data-match-id="{{ $match->id }}" @isset($group) data-group-id="{{ $group->id }}" @endisset>
                @csrf
                <div class="modal-body">
                    <div class="score-input-grid">
                        <div class="score-team">
                            <div class="score-team-name">
                                {{ $match->team1Player1->full_name }} / {{ $match->team1Player2->full_name }}
                            </div>
                            <input type="number" name="team1_score" class="score-input" 
                                   min="0" max="{{ $tournament->points_to_win }}" required>
                        </div>
                        <div class="score-separator">:</div>
                        <div class="score-team">
                            <div class="score-team-name">
                                {{ $match->team2Player1->full_name }} / {{ $match->team2Player2->full_name }}
                            </div>
                            <input type="number" name="team2_score" class="score-input" 
                                   min="0" max="{{ $tournament->points_to_win }}" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn-primary-custom">
                        <i class="bi bi-check-lg"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
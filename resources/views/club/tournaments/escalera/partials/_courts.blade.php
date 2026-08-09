{{-- resources/views/club/tournaments/escalera/partials/_courts.blade.php --}}
{{-- Вёрстка повторяет «Короля корта» (kingofcourt/partials/_rounds): те же
     карточки раундов, бейджи кортов и модалки ввода счёта. Отличие формата —
     на корте не один матч, а три, поэтому матчи сгруппированы под бейджем корта. --}}
@php
    $courtsTotal = (int) $tournament->courts_count;
    $lastRoundId = $tournament->escaleraRounds->last()?->id;
    $scoresLocked = $tournament->status === 'completed';
@endphp

<div class="rounds-grid">
    @foreach($tournament->escaleraRounds as $round)
        @php
            $roundDone = $round->isCompleted();
            $isOpen = $round->id === $lastRoundId;
            $statusClass = $roundDone ? 'completed' : 'active';
        @endphp
        <div class="round-card {{ $statusClass }}">
            <div class="round-header" onclick="toggleEscRound('esc-round-{{ $round->id }}')" style="cursor: pointer;">
                <div class="round-title">
                    @if($roundDone)
                        <i class="bi bi-check-circle-fill text-success"></i>
                    @else
                        <i class="bi bi-play-circle-fill text-primary"></i>
                    @endif
                    Раунд {{ $round->round_number }}
                </div>
                <div class="round-header-right">
                    <span class="round-status {{ $statusClass }}">{{ $roundDone ? 'Закрыт' : 'Идёт' }}</span>
                    <i class="bi bi-chevron-down collapse-icon {{ $isOpen ? '' : 'collapsed' }}"
                       id="icon-esc-round-{{ $round->id }}"></i>
                </div>
            </div>

            <div class="round-matches collapsible-content {{ $isOpen ? '' : 'collapsed' }}" id="esc-round-{{ $round->id }}">
                @if($roundDone && !$scoresLocked)
                    <div class="esc-round-hint">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Раунд закрыт. Правка счёта пересчитает места и таблицу, но игроков по кортам не двинет —
                        перемещения уже произошли.
                    </div>
                @endif

                @foreach($round->courts as $court)
                    @php
                        $courtNumber = (int) $court->court_number;
                        $filled = $court->matches->filter(fn ($m) => $m->isCompleted())->count();

                        if ($courtNumber === 1) {
                            $courtBadgeClass = 'court-top';
                            $courtNote = 'верхний';
                        } elseif ($courtNumber === $courtsTotal) {
                            $courtBadgeClass = 'court-bottom';
                            $courtNote = 'нижний';
                        } else {
                            $courtBadgeClass = 'court-middle';
                            $courtNote = null;
                        }
                    @endphp
                    <div class="esc-court-block">
                        <div class="match-court-header {{ $courtBadgeClass }}">
                            <i class="bi bi-geo-alt"></i> Корт {{ $courtNumber }}
                            @if($courtNote)
                                <span class="esc-court-note">{{ $courtNote }}</span>
                            @endif
                            <span class="esc-court-progress">{{ $filled }}/3</span>
                        </div>

                        @foreach($court->matches as $match)
                            @php
                                $p1 = $users->get($match->team1_player1_id);
                                $p2 = $users->get($match->team1_player2_id);
                                $p3 = $users->get($match->team2_player1_id);
                                $p4 = $users->get($match->team2_player2_id);

                                $done = $match->isCompleted();
                                $winner = null;
                                if ($done) {
                                    $winner = $match->team1_score > $match->team2_score
                                        ? 1
                                        : ($match->team2_score > $match->team1_score ? 2 : null);
                                }

                                $team1Names = (optional($p1)->name ?? '—') . ' / ' . (optional($p2)->name ?? '—');
                                $team2Names = (optional($p3)->name ?? '—') . ' / ' . (optional($p4)->name ?? '—');
                            @endphp
                            <div class="match-card" data-match-id="{{ $match->id }}">
                                <div class="esc-match-num">Матч {{ $match->match_number }}</div>
                                <div class="match-teams">
                                    <div class="match-team {{ $winner === 1 ? 'winner' : '' }}">
                                        <div class="team-players">
                                            <div class="player-line">{{ optional($p1)->name ?? '—' }} <span class="player-level">{{ optional($p1)->level }}</span></div>
                                            <div class="player-line">{{ optional($p2)->name ?? '—' }} <span class="player-level">{{ optional($p2)->level }}</span></div>
                                        </div>
                                        @if($done)
                                            <div class="team-score">{{ $match->team1_score }}</div>
                                        @endif
                                    </div>

                                    <div class="match-vs">
                                        @if($scoresLocked)
                                            <span class="vs-pending">VS</span>
                                        @elseif($done)
                                            <button class="btn-score-edit"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#escScoreModal{{ $match->id }}"
                                                    title="Редактировать счёт">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        @else
                                            <button class="btn-score"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#escScoreModal{{ $match->id }}">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        @endif
                                    </div>

                                    <div class="match-team {{ $winner === 2 ? 'winner' : '' }}">
                                        @if($done)
                                            <div class="team-score">{{ $match->team2_score }}</div>
                                        @endif
                                        <div class="team-players">
                                            <div class="player-line">{{ optional($p3)->name ?? '—' }} <span class="player-level">{{ optional($p3)->level }}</span></div>
                                            <div class="player-line">{{ optional($p4)->name ?? '—' }} <span class="player-level">{{ optional($p4)->level }}</span></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @if(!$scoresLocked)
                                <div class="modal fade" id="escScoreModal{{ $match->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content modal-dark">
                                            <div class="modal-header border-0">
                                                <h5 class="modal-title">
                                                    {{ $done ? 'Редактировать' : 'Ввести счёт' }} · Корт {{ $courtNumber }}, матч {{ $match->match_number }}
                                                </h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form action="{{ route('club.escalera.saveScore', $match) }}" method="POST">
                                                @csrf
                                                <div class="modal-body">
                                                    <div class="score-input-grid">
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $team1Names }}</div>
                                                            <input type="number" name="team1_score" class="form-control form-control-lg text-center"
                                                                   min="0" max="99" required value="{{ $match->team1_score }}">
                                                        </div>
                                                        <div class="score-separator">:</div>
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $team2Names }}</div>
                                                            <input type="number" name="team2_score" class="form-control form-control-lg text-center"
                                                                   min="0" max="99" required value="{{ $match->team2_score }}">
                                                        </div>
                                                    </div>
                                                    <div class="esc-modal-hint">
                                                        Счёт любой — формат матча организатор определяет сам.
                                                    </div>
                                                </div>
                                                <div class="modal-footer border-0">
                                                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Отмена</button>
                                                    <button type="submit" class="btn-primary-custom">
                                                        <i class="bi bi-check-lg"></i> {{ $done ? 'Обновить' : 'Сохранить' }}
                                                    </button>
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
    @endforeach
</div>

<script>
function toggleEscRound(id) {
    const content = document.getElementById(id);
    const icon = document.getElementById('icon-' + id);

    if (content && icon) {
        content.classList.toggle('collapsed');
        icon.classList.toggle('collapsed');
    }
}
</script>

@include('club.tournaments.partials._round_cards_style')

<style>
/* Только отличия эскалеры от «Короля корта»: на корте три матча, а не один. */
/* Раунд из 3-5 кортов по три матча выше, чем у КОС, — потолок сворачивания больше. */
.collapsible-content { max-height: 8000px; }
.match-court-header { display: block; }

/* Своё для эскалеры: корт — группа из трёх матчей, плюс посадка над ними. */
.esc-court-block {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.esc-court-note { font-size: 16px; font-weight: 400; opacity: 0.75; margin-left: 6px; }
.esc-court-progress { float: right; font-size: 16px; font-weight: 400; opacity: 0.75; }

.esc-match-num { align-self: flex-start; color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 4px; }

/* Раскладка модалки счёта: два поля и двоеточие между ними. */
.score-input-grid { display: flex; align-items: flex-end; gap: 16px; }
.score-input-grid .score-team { flex: 1; text-align: center; }
.score-input-grid .score-separator { padding-bottom: 6px; }
.esc-round-hint { color: var(--esc-warn); font-size: 0.9rem; margin-bottom: 12px; }
.esc-modal-hint { margin-top: 14px; color: var(--text-secondary); font-size: 0.9rem; text-align: center; }

@media (max-width: 800px) {
    .player-line { font-size: 20px; }
    .team-score { font-size: 28px; }
    .match-court-header { font-size: 18px; }
}
</style>

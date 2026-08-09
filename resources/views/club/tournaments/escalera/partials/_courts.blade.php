{{-- resources/views/club/tournaments/escalera/partials/_courts.blade.php --}}
@php
    $matchPoints = (int) $tournament->escalera_match_points;
    $courtsTotal = (int) $tournament->courts_count;
    $lastRoundId = $tournament->escaleraRounds->last()?->id;
    $scoresLocked = $tournament->status === 'completed';
@endphp

<div class="esc-rounds mb-4">
    @foreach($tournament->escaleraRounds as $round)
        @php
            $isOpen = $round->id === $lastRoundId;
            $roundDone = $round->isCompleted();
        @endphp
        <div class="esc-round {{ $roundDone ? 'done' : 'live' }}">
            <div class="esc-round-header" onclick="toggleEscRound('esc-round-{{ $round->id }}')">
                <div class="esc-round-title">
                    @if($roundDone)
                        <i class="bi bi-check-circle-fill"></i>
                    @else
                        <i class="bi bi-play-circle-fill"></i>
                    @endif
                    Раунд {{ $round->round_number }}
                </div>
                <div class="esc-round-right">
                    <span class="esc-round-status">{{ $roundDone ? 'Закрыт' : 'Идёт' }}</span>
                    <i class="bi bi-chevron-down esc-collapse-icon {{ $isOpen ? '' : 'collapsed' }}"
                       id="icon-esc-round-{{ $round->id }}"></i>
                </div>
            </div>

            <div class="esc-round-body {{ $isOpen ? '' : 'collapsed' }}" id="esc-round-{{ $round->id }}">
                @if($roundDone && !$scoresLocked)
                    <div class="esc-round-hint">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Раунд закрыт. Правка счёта пересчитает места и таблицу, но игроков по кортам не двинет —
                        перемещения уже произошли.
                    </div>
                @endif

                @foreach($round->courts as $court)
                    @php
                        $filled = $court->matches->filter(fn ($m) => $m->isCompleted())->count();
                        $state = $filled === 0 ? 'empty' : ($filled < 3 ? 'partial' : 'done');
                        $courtNumber = (int) $court->court_number;
                        $seating = $court->playerIds();
                    @endphp
                    <div class="esc-court {{ $state }}">
                        <div class="esc-court-header">
                            <span class="esc-court-name">
                                Корт {{ $courtNumber }}
                                @if($courtNumber === 1)
                                    <span class="esc-court-note">верхний</span>
                                @elseif($courtNumber === $courtsTotal)
                                    <span class="esc-court-note">нижний</span>
                                @endif
                            </span>
                            <span class="esc-court-progress">{{ $filled }}/3 матчей</span>
                        </div>

                        <div class="esc-court-seating">
                            @foreach($seating as $i => $playerId)
                                <div class="esc-seat">
                                    <span class="esc-seat-num">{{ $i + 1 }}</span>
                                    <span class="esc-seat-name">{{ optional($users->get($playerId))->name ?? '—' }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="esc-matches">
                            @foreach($court->matches as $match)
                                @php
                                    $t1 = optional($users->get($match->team1_player1_id))->name ?? '—';
                                    $t2 = optional($users->get($match->team1_player2_id))->name ?? '—';
                                    $t3 = optional($users->get($match->team2_player1_id))->name ?? '—';
                                    $t4 = optional($users->get($match->team2_player2_id))->name ?? '—';
                                @endphp
                                <div class="esc-match {{ $match->isCompleted() ? 'filled' : '' }}">
                                    <div class="esc-match-num">Матч {{ $match->match_number }}</div>
                                    <div class="esc-match-team">{{ $t1 }} / {{ $t2 }}</div>

                                    @if($scoresLocked)
                                        <div class="esc-match-score-static">
                                            {{ $match->team1_score ?? '—' }} : {{ $match->team2_score ?? '—' }}
                                        </div>
                                    @else
                                        <form action="{{ route('club.escalera.saveScore', $match) }}" method="POST"
                                              class="esc-match-form">
                                            @csrf
                                            <input type="number" name="team1_score" class="form-control esc-score-input"
                                                   min="0" max="99" required
                                                   value="{{ $match->team1_score }}">
                                            <span class="esc-match-colon">:</span>
                                            <input type="number" name="team2_score" class="form-control esc-score-input"
                                                   min="0" max="99" required
                                                   value="{{ $match->team2_score }}">
                                            <button type="submit" class="btn-primary-custom esc-score-btn">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        </form>
                                    @endif

                                    <div class="esc-match-team text-end">{{ $t3 }} / {{ $t4 }}</div>
                                </div>
                            @endforeach
                        </div>

                        @if(!$scoresLocked)
                            <div class="esc-court-hint">Сумма очков двух команд в матче должна быть равна {{ $matchPoints }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>

<script>
function toggleEscRound(id) {
    const body = document.getElementById(id);
    const icon = document.getElementById('icon-' + id);
    if (body && icon) {
        body.classList.toggle('collapsed');
        icon.classList.toggle('collapsed');
    }
}
</script>

<style>
.esc-rounds { display: flex; flex-direction: column; gap: 16px; }
.esc-round {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
}
.esc-round.live { border-color: var(--accent); }
.esc-round-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: var(--bg-secondary);
    cursor: pointer;
    user-select: none;
}
.esc-round-title { font-weight: 600; font-size: 1.1rem; color: var(--text-primary); }
.esc-round.live .esc-round-title { color: var(--accent); }
.esc-round-right { display: flex; align-items: center; gap: 12px; }
.esc-round-status { color: var(--text-secondary); font-size: 0.9rem; }
.esc-collapse-icon { transition: transform 0.3s; color: var(--text-secondary); }
.esc-collapse-icon.collapsed { transform: rotate(-90deg); }
.esc-round-body {
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    overflow: hidden;
}
.esc-round-body.collapsed { display: none; }
.esc-round-hint { color: var(--esc-warn); font-size: 0.9rem; }

.esc-court {
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    background: var(--bg-secondary);
}
.esc-court.empty { border-color: var(--border); }
.esc-court.partial { border-color: var(--esc-warn); background: var(--esc-warn-bg); }
.esc-court.done { border-color: var(--accent); background: var(--accent-glow); }

.esc-court-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.esc-court-name { font-weight: 600; color: var(--text-primary); }
.esc-court-note { color: var(--text-secondary); font-weight: 400; font-size: 0.85rem; margin-left: 6px; }
.esc-court-progress { color: var(--text-secondary); font-size: 0.9rem; }

.esc-court-seating {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-bottom: 12px;
}
.esc-seat {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
}
.esc-seat-num {
    min-width: 22px;
    height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    background: var(--bg-secondary);
    color: var(--text-secondary);
    font-size: 0.8rem;
}
.esc-seat-name { color: var(--text-primary); }

.esc-matches { display: flex; flex-direction: column; gap: 8px; }
.esc-match {
    display: grid;
    grid-template-columns: 90px 1fr auto 1fr;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
}
.esc-match.filled { border-color: var(--accent); }
.esc-match-num { color: var(--text-secondary); font-size: 0.85rem; }
.esc-match-team { color: var(--text-primary); }
.esc-match-form { display: flex; align-items: center; gap: 6px; margin: 0; }
.esc-match-colon { color: var(--text-secondary); }
.esc-score-input { width: 64px; text-align: center; }
.esc-score-btn { padding: 6px 10px; }
.esc-match-score-static { font-weight: 600; color: var(--text-primary); min-width: 70px; text-align: center; }
.esc-court-hint { margin-top: 10px; color: var(--text-secondary); font-size: 0.85rem; }

@media (max-width: 800px) {
    .esc-court-seating { grid-template-columns: repeat(2, 1fr); }
    .esc-match { grid-template-columns: 1fr; text-align: center; }
    .esc-match-team.text-end { text-align: center !important; }
    .esc-match-form { justify-content: center; }
}
</style>

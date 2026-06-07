{{-- resources/views/club/tournaments/partials/_americano_playoff.blade.php --}}
@if($tournament->isAmericano() && $tournament->hasPlayoff() && $tournament->playoffMatches()->count() > 0)
<div class="playoff-container mb-4">
    <header class="playoff-header">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/>
            <path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/>
            <path d="M4 22h16"/>
            <path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/>
            <path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/>
            <path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>
        </svg>
        <h1>Плей-офф</h1>
    </header>

    @php
        $allMatches = $tournament->playoffMatches;
        $brackets = [];
        $upperMain = $allMatches->filter(fn($m) => ($m->bracket ?: 'upper') === 'upper' && !$m->is_bronze);
        $upperBronze = $allMatches->filter(fn($m) => ($m->bracket ?: 'upper') === 'upper' && $m->is_bronze);
        $lowerMain = $allMatches->filter(fn($m) => ($m->bracket ?: 'upper') === 'lower' && !$m->is_bronze);
        $lowerBronze = $allMatches->filter(fn($m) => ($m->bracket ?: 'upper') === 'lower' && $m->is_bronze);
        if ($upperMain->isNotEmpty()) $brackets[] = ['label' => 'Верхняя сетка', 'matches' => $upperMain, 'bronze' => $upperBronze];
        if ($lowerMain->isNotEmpty()) $brackets[] = ['label' => 'Нижняя сетка', 'matches' => $lowerMain, 'bronze' => $lowerBronze];
        $stageOrder = ['Полуфинал' => 'Полуфинал', 'Финал' => 'Финал'];
        $stageShortNames = ['Полуфинал' => 'ПФ', 'Финал' => 'Финал'];
    @endphp

    @foreach($brackets as $bracketIdx => $bracketInfo)
    @if(count($brackets) > 1)
    <div style="margin: {{ $bracketIdx === 0 ? '0 0 8px' : '20px 0 8px' }}; padding: 8px 14px; background: rgba(255,255,255,0.04); border-radius: 8px; font-size: 13px; font-weight: 700; color: #a0a0a0; letter-spacing: 0.5px; text-transform: uppercase;">
        {{ $bracketInfo['label'] }}
    </div>
    @endif
    @php $stages = $bracketInfo['matches']->groupBy('stage'); @endphp
    <div class="bracket-wrapper">
        @foreach($stageOrder as $stageKey => $stageName)
            @if(isset($stages[$stageKey]))
                @php
                    $matchCounter = 0;
                    $isFinal = $stageKey === 'Финал';
                @endphp

                <div class="bracket-round {{ $isFinal ? 'round-final' : '' }}">
                    <div class="round-header">{{ $stageName }}</div>
                    <div class="round-matches">
                        @foreach($stages[$stageKey] as $match)
                            @php
                                $matchCounter++;
                                $isCompleted = $match->status === 'completed';
                                $t1set = $match->team1_player1_id && $match->team1_player2_id;
                                $t2set = $match->team2_player1_id && $match->team2_player2_id;
                                $t1name = $t1set ? ($match->team1Player1->name . ' / ' . $match->team1Player2->name) : 'Ожидание…';
                                $t2name = $t2set ? ($match->team2Player1->name . ' / ' . $match->team2Player2->name) : 'Ожидание…';
                                $team1Wins = $isCompleted && $match->team1_score > $match->team2_score;
                                $team2Wins = $isCompleted && $match->team2_score > $match->team1_score;
                                $matchLabel = $isFinal ? 'Финал' : ($stageShortNames[$stageKey] . ' ' . $matchCounter);
                                $canEnter = !$isCompleted && $t1set && $t2set;
                            @endphp

                            <div>
                                @if($isFinal)
                                <div class="trophy-box">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6v5zm12 0h1.5a2.5 2.5 0 0 0 0-5H18v5zM4 22h16v-1H4v1zm6-7.34V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22h10c0-1.76-.85-3.25-2.03-3.79-.5-.23-.97-.66-.97-1.21v-2.34A6.96 6.96 0 0 0 18 9V2H6v7c0 2.76 1.61 5.15 4 6.66zM8 4h8v5a4 4 0 1 1-8 0V4z"/>
                                    </svg>
                                </div>
                                @endif

                                <div class="bracket-match {{ $isFinal ? 'final-match' : '' }}" data-match-id="{{ $match->id }}">
                                    <div class="match-head">
                                        <div class="match-num">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                                            {{ $matchLabel }}
                                        </div>
                                        @if($match->court_number)
                                        <div class="match-court has-court">{{ $tournament->getCourtName($match->court_number) }}</div>
                                        @endif
                                    </div>

                                    <div class="match-players">
                                        {{-- Пара 1 --}}
                                        <div class="player-row">
                                            <div class="player-data">
                                                <span class="player-label {{ $isCompleted ? ($team1Wins ? 'is-winner' : 'is-loser') : (!$t1set ? 'is-pending' : '') }}">{{ $t1name }}</span>
                                            </div>
                                            <span class="player-pts {{ $isCompleted ? ($team1Wins ? 'is-winner' : 'is-loser') : '' }}">{{ $isCompleted ? $match->team1_score : '-' }}</span>
                                        </div>

                                        {{-- Пара 2 --}}
                                        <div class="player-row">
                                            <div class="player-data">
                                                <span class="player-label {{ $isCompleted ? ($team2Wins ? 'is-winner' : 'is-loser') : (!$t2set ? 'is-pending' : '') }}">{{ $t2name }}</span>
                                            </div>
                                            <span class="player-pts {{ $isCompleted ? ($team2Wins ? 'is-winner' : 'is-loser') : '' }}">{{ $isCompleted ? $match->team2_score : '-' }}</span>
                                        </div>
                                    </div>

                                    <div class="match-foot">
                                        @if($isCompleted)
                                            <div class="match-state is-done">Завершён</div>
                                            @if($tournament->status !== 'completed')
                                            <button class="edit-score-btn" data-bs-toggle="modal" data-bs-target="#editAmericanoPlayoffModal{{ $match->id }}">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                            @endif
                                        @elseif($canEnter)
                                            <button class="enter-score-btn" data-bs-toggle="modal" data-bs-target="#americanoPlayoffModal{{ $match->id }}">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                Ввести счёт
                                            </button>
                                        @else
                                            <div class="match-state">Ожидание</div>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- Модалка ввода счёта --}}
                            @if($canEnter)
                            <div class="modal fade" id="americanoPlayoffModal{{ $match->id }}" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content modal-dark">
                                        <div class="modal-header border-0">
                                            <h5 class="modal-title">{{ $stageName }} — {{ $matchLabel }}</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form action="{{ route('club.americano.savePlayoffScore', $match) }}" method="POST">
                                            @csrf
                                            <div class="modal-body">
                                                <div class="score-input-grid">
                                                    <div class="score-team">
                                                        <div class="score-team-names">{{ $t1name }}</div>
                                                        <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required placeholder="0">
                                                    </div>
                                                    <div class="score-separator">:</div>
                                                    <div class="score-team">
                                                        <div class="score-team-names">{{ $t2name }}</div>
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
                            @if($isCompleted)
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
                                                        <div class="score-team-names">{{ $t1name }}</div>
                                                        <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required value="{{ $match->team1_score }}">
                                                    </div>
                                                    <div class="score-separator">:</div>
                                                    <div class="score-team">
                                                        <div class="score-team-names">{{ $t2name }}</div>
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

    {{-- Матч за 3-е место (опционально, по сетке) --}}
    @if($bracketInfo['bronze']->isNotEmpty())
    <div style="margin: 12px 0 0; padding: 10px 14px; background: rgba(234,179,78,0.08); border-left: 3px solid #eab34e; border-radius: 6px; font-size: 12px; font-weight: 700; color: #eab34e; letter-spacing: 0.5px; text-transform: uppercase;">
        Матч за 3-е место
    </div>
    <div class="bracket-wrapper">
        <div class="bracket-round">
            <div class="round-matches">
                @foreach($bracketInfo['bronze'] as $match)
                    @php
                        $isCompleted = $match->status === 'completed';
                        $t1set = $match->team1_player1_id && $match->team1_player2_id;
                        $t2set = $match->team2_player1_id && $match->team2_player2_id;
                        $t1name = $t1set ? ($match->team1Player1->name . ' / ' . $match->team1Player2->name) : 'Ожидание…';
                        $t2name = $t2set ? ($match->team2Player1->name . ' / ' . $match->team2Player2->name) : 'Ожидание…';
                        $team1Wins = $isCompleted && $match->team1_score > $match->team2_score;
                        $team2Wins = $isCompleted && $match->team2_score > $match->team1_score;
                        $canEnter = !$isCompleted && $t1set && $t2set;
                    @endphp
                    <div>
                        <div class="bracket-match" data-match-id="{{ $match->id }}">
                            <div class="match-head">
                                <div class="match-num"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>Бронза</div>
                                @if($match->court_number)
                                <div class="match-court has-court">{{ $tournament->getCourtName($match->court_number) }}</div>
                                @endif
                            </div>
                            <div class="match-players">
                                <div class="player-row">
                                    <div class="player-data">
                                        <span class="player-label {{ $isCompleted ? ($team1Wins ? 'is-winner' : 'is-loser') : (!$t1set ? 'is-pending' : '') }}">{{ $t1name }}</span>
                                    </div>
                                    <span class="player-pts {{ $isCompleted ? ($team1Wins ? 'is-winner' : 'is-loser') : '' }}">{{ $isCompleted ? $match->team1_score : '-' }}</span>
                                </div>
                                <div class="player-row">
                                    <div class="player-data">
                                        <span class="player-label {{ $isCompleted ? ($team2Wins ? 'is-winner' : 'is-loser') : (!$t2set ? 'is-pending' : '') }}">{{ $t2name }}</span>
                                    </div>
                                    <span class="player-pts {{ $isCompleted ? ($team2Wins ? 'is-winner' : 'is-loser') : '' }}">{{ $isCompleted ? $match->team2_score : '-' }}</span>
                                </div>
                            </div>
                            <div class="match-foot">
                                @if($isCompleted)
                                    <div class="match-state is-done">Завершён</div>
                                    @if($tournament->status !== 'completed')
                                    <button class="edit-score-btn" data-bs-toggle="modal" data-bs-target="#editAmericanoPlayoffModal{{ $match->id }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    @endif
                                @elseif($canEnter)
                                    <button class="enter-score-btn" data-bs-toggle="modal" data-bs-target="#americanoPlayoffModal{{ $match->id }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        Ввести счёт
                                    </button>
                                @else
                                    <div class="match-state">Ожидание</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if($canEnter)
                    <div class="modal fade" id="americanoPlayoffModal{{ $match->id }}" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content modal-dark">
                                <div class="modal-header border-0">
                                    <h5 class="modal-title">Матч за 3-е место ({{ $bracketInfo['label'] }})</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form action="{{ route('club.americano.savePlayoffScore', $match) }}" method="POST">
                                    @csrf
                                    <div class="modal-body">
                                        <div class="score-input-grid">
                                            <div class="score-team">
                                                <div class="score-team-names">{{ $t1name }}</div>
                                                <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required placeholder="0">
                                            </div>
                                            <div class="score-separator">:</div>
                                            <div class="score-team">
                                                <div class="score-team-names">{{ $t2name }}</div>
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

                    @if($isCompleted)
                    <div class="modal fade" id="editAmericanoPlayoffModal{{ $match->id }}" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content modal-dark">
                                <div class="modal-header border-0">
                                    <h5 class="modal-title">Редактировать — Матч за 3-е место</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form action="{{ route('club.americano.updatePlayoffScore', $match) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    <div class="modal-body">
                                        <div class="score-input-grid">
                                            <div class="score-team">
                                                <div class="score-team-names">{{ $t1name }}</div>
                                                <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required value="{{ $match->team1_score }}">
                                            </div>
                                            <div class="score-separator">:</div>
                                            <div class="score-team">
                                                <div class="score-team-names">{{ $t2name }}</div>
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
    </div>
    @endif
    @endforeach

    {{-- Победители турнира — победители финала ВЕРХНЕЙ сетки --}}
    @php
        $upperFinal = $tournament->playoffMatches
            ->first(fn($m) => $m->stage === 'Финал' && (($m->bracket ?: 'upper') === 'upper') && !$m->is_bronze);
    @endphp
    @if($upperFinal && $upperFinal->status === 'completed')
        @php
            $winnerP1 = $upperFinal->team1_score > $upperFinal->team2_score ? $upperFinal->team1Player1 : $upperFinal->team2Player1;
            $winnerP2 = $upperFinal->team1_score > $upperFinal->team2_score ? $upperFinal->team1Player2 : $upperFinal->team2Player2;
        @endphp
        <div class="winner-block mt-4">
            <div class="winner-trophy"><i class="bi bi-trophy-fill"></i></div>
            <div class="winner-title">Победители турнира</div>
            <div class="winner-name">{{ $winnerP1->name ?? '' }} / {{ $winnerP2->name ?? '' }}</div>
        </div>
    @endif
</div>

<style>
/* ===== PLAYOFF ПЕРЕМЕННЫЕ ===== */
:root {
    --playoff-bg: #0a0a0b;
    --playoff-bg-dark: #111113;
    --playoff-card: #18181b;
    --playoff-card-hover: #1f1f23;
    --playoff-border: #27272a;
    --playoff-border-light: #3f3f46;
    --playoff-accent: #22c55e;
    --playoff-accent-dark: #16a34a;
    --playoff-text: #fafafa;
    --playoff-text-dim: #a1a1aa;
    --playoff-text-muted: #71717a;
    --playoff-gold: #fbbf24;
    --playoff-yellow: #facc15;
    --playoff-red: #ef4444;
}

.playoff-container {
    width: 100%;
    padding: 32px 40px;
    overflow-x: auto;
}

.playoff-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 40px;
}

.playoff-header svg {
    width: 28px;
    height: 28px;
    color: var(--playoff-accent);
}

.playoff-header h1 {
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -0.5px;
}

.bracket-wrapper {
    display: flex;
    align-items: stretch;
    min-width: max-content;
    min-height: 700px;
    gap: 40px;
}

.bracket-round {
    display: flex;
    flex-direction: column;
}

.round-header {
    text-align: center;
    padding: 14px 50px;
    background: linear-gradient(135deg, var(--playoff-accent), var(--playoff-accent-dark));
    color: var(--playoff-bg);
    font-size: 14px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    border-radius: 10px;
    margin-bottom: 24px;
    flex-shrink: 0;
}

.round-matches {
    display: flex;
    flex-direction: column;
    justify-content: space-around;
    flex: 1;
}

.bracket-match {
    background: var(--playoff-card);
    border: 1px solid var(--playoff-border);
    border-radius: 12px;
    width: 500px;
    overflow: hidden;
    transition: all 0.2s ease;
}

.bracket-match:hover {
    border-color: var(--playoff-border-light);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
}

.bracket-match.final-match {
    border-color: var(--playoff-gold);
    box-shadow: 0 0 20px rgba(251, 191, 36, 0.15);
}

.bracket-match.final-match:hover {
    box-shadow: 0 0 30px rgba(251, 191, 36, 0.25);
}

.match-head {
    background: var(--playoff-bg-dark);
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--playoff-border);
}

.match-num {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 700;
    color: var(--playoff-accent);
}

.match-num svg {
    width: 16px;
    height: 16px;
}

.match-court {
    font-size: 12px;
    color: var(--playoff-text-muted);
    font-weight: 600;
}

.match-court.has-court {
    color: var(--playoff-yellow);
}

.match-players {
    padding: 4px 0;
}

.player-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid var(--playoff-border);
    transition: background 0.15s;
}

.player-row:last-child {
    border-bottom: none;
}

.player-row:hover {
    background: var(--playoff-card-hover);
}

.player-data {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
}

.player-label {
    font-size: 15px;
    font-weight: 600;
    color: var(--playoff-text);
    line-height: 1.3;
}

.player-label.is-winner {
    color: var(--playoff-accent);
}

.player-label.is-loser {
    color: var(--playoff-text-muted);
}

.player-label.is-pending {
    color: var(--playoff-text-muted);
    font-style: italic;
}

.player-pts {
    font-size: 18px;
    font-weight: 800;
    min-width: 28px;
    text-align: center;
}

.player-pts.is-winner {
    color: var(--playoff-accent);
}

.player-pts.is-loser {
    color: var(--playoff-red);
}

.match-foot {
    padding: 10px 14px;
    background: var(--playoff-bg-dark);
    border-top: 1px solid var(--playoff-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.enter-score-btn {
    width: 100%;
    background: var(--playoff-accent);
    color: var(--playoff-bg);
    border: none;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.enter-score-btn:hover {
    background: var(--playoff-accent-dark);
    transform: translateY(-1px);
}

.enter-score-btn svg {
    width: 16px;
    height: 16px;
}

.match-state {
    text-align: center;
    font-size: 12px;
    font-weight: 600;
    color: var(--playoff-text-muted);
}

.match-state.is-done {
    color: var(--playoff-accent);
}

.trophy-box {
    display: flex;
    justify-content: center;
    margin-bottom: 16px;
}

.trophy-box svg {
    width: 48px;
    height: 48px;
    color: var(--playoff-gold);
    filter: drop-shadow(0 0 12px rgba(251, 191, 36, 0.4));
}

.round-final .round-matches {
    justify-content: center;
}

.edit-score-btn {
    background: transparent;
    border: 1px solid var(--playoff-border);
    color: var(--playoff-text-muted);
    padding: 6px 10px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.edit-score-btn:hover {
    background: var(--playoff-card-hover);
    border-color: var(--playoff-accent);
    color: var(--playoff-accent);
}

.edit-score-btn svg {
    width: 14px;
    height: 14px;
}

@media (max-width: 1200px) {
    .playoff-container {
        padding: 24px 20px;
    }

    .bracket-match {
        width: 300px;
    }

    .round-header {
        padding: 12px 40px;
        font-size: 13px;
    }
}
</style>
@endif

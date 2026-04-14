{{-- resources/views/club/tournaments/partials/_team_playoff.blade.php --}}
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
        $stages = $tournament->playoffMatches->groupBy('stage');
        $stageOrder = ['quarter' => '1/4 финала', 'semi' => 'Полуфинал', 'final' => 'Финал'];
        $stageShortNames = ['quarter' => 'Матч', 'semi' => 'ПФ', 'final' => 'Финал'];
        $matchCounter = [];
    @endphp

    <div class="bracket-wrapper">
        @foreach($stageOrder as $stageKey => $stageName)
            @if(isset($stages[$stageKey]))
                @php 
                    $matchCounter[$stageKey] = 0;
                    $isFinal = $stageKey === 'final';
                @endphp
                
                <div class="bracket-round {{ $isFinal ? 'round-final' : '' }}">
                    <div class="round-header">{{ $stageName }}</div>
                    <div class="round-matches">
                        @foreach($stages[$stageKey] as $match)
                            @php
                                $matchCounter[$stageKey]++;
                                $isCompleted = $match->isCompleted();
                                $isInProgress = $match->status === 'in_progress';
                                $team1Wins = $match->winner_id === $match->team1_id;
                                $team2Wins = $match->winner_id === $match->team2_id;
                                $matchLabel = $stageShortNames[$stageKey] . ' ' . $matchCounter[$stageKey];
                                if ($isFinal) $matchLabel = 'Финал';
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
                                        <div class="match-court {{ $match->court_number ? 'has-court' : '' }}">
                                            {{ $match->court_number ? $tournament->getCourtName($match->court_number) : 'Корт TBD' }}
                                        </div>
                                    </div>
                                    
                                    <div class="match-players">
                                        {{-- Команда 1 --}}
                                        <div class="player-row">
                                            <div class="player-data">
                                                <span class="player-seed">{{ $match->team1 ? $match->team1->seed ?? '?' : 'W' }}</span>
                                                <span class="player-label {{ $isCompleted ? ($team1Wins ? 'is-winner' : 'is-loser') : (!$match->team1 ? 'is-pending' : '') }}">
                                                    {{ $match->team1 ? $match->team1->full_name : $match->team1_source }}
                                                </span>
                                            </div>
                                            <span class="player-pts {{ $isCompleted ? ($team1Wins ? 'is-winner' : 'is-loser') : '' }}">
                                                {{ $isCompleted ? $match->team1_score : '-' }}
                                            </span>
                                        </div>
                                        
                                        {{-- Команда 2 --}}
                                        <div class="player-row">
                                            <div class="player-data">
                                                <span class="player-seed">{{ $match->team2 ? $match->team2->seed ?? '?' : 'W' }}</span>
                                                <span class="player-label {{ $isCompleted ? ($team2Wins ? 'is-winner' : 'is-loser') : (!$match->team2 ? 'is-pending' : '') }}">
                                                    {{ $match->team2 ? $match->team2->full_name : $match->team2_source }}
                                                </span>
                                            </div>
                                            <span class="player-pts {{ $isCompleted ? ($team2Wins ? 'is-winner' : 'is-loser') : '' }}">
                                                {{ $isCompleted ? $match->team2_score : '-' }}
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="match-foot">
                                        @if($isCompleted)
                                            <div class="match-state is-done">Завершён</div>
                                            @if($tournament->status !== 'completed')
                                            <button class="edit-score-btn" data-bs-toggle="modal" data-bs-target="#editPlayoffModal{{ $match->id }}">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                            @endif
                                        @elseif($isInProgress && $match->team1_id && $match->team2_id)
                                            <button class="enter-score-btn" data-bs-toggle="modal" data-bs-target="#playoffModal{{ $match->id }}">
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
                            @if($isInProgress && $match->team1_id && $match->team2_id)
                            <div class="modal fade" id="playoffModal{{ $match->id }}" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content modal-dark">
                                        <div class="modal-header border-0">
                                            <h5 class="modal-title">{{ $stageName }} — {{ $matchLabel }}</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form action="{{ route('club.team.savePlayoffScore', $match) }}" method="POST">
                                            @csrf
                                            <div class="modal-body">
                                                <div class="score-input-grid">
                                                    <div class="score-team">
                                                        <div class="score-team-names">{{ $match->team1->full_name }}</div>
                                                        <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required placeholder="0">
                                                    </div>
                                                    <div class="score-separator">:</div>
                                                    <div class="score-team">
                                                        <div class="score-team-names">{{ $match->team2->full_name }}</div>
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
                                                        <div class="score-team-names">{{ $match->team1->full_name }}</div>
                                                        <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required value="{{ $match->team1_score }}">
                                                    </div>
                                                    <div class="score-separator">:</div>
                                                    <div class="score-team">
                                                        <div class="score-team-names">{{ $match->team2->full_name }}</div>
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

                {{-- Коннекторы между раундами (кроме финала) --}}
                @if($stageKey !== 'final' && isset($stageOrder[array_search($stageKey, array_keys($stageOrder)) + 1]))
                    <div class="bracket-connector">
                        @for($i = 0; $i < ceil(count($stages[$stageKey]) / 2); $i++)
                            <div class="connector-pair">
                                <div class="connector-line-top"></div>
                                <div class="connector-line-bottom"></div>
                            </div>
                        @endfor
                    </div>
                @endif
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

        /* Header */
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

        /* Bracket Layout */
        .bracket-wrapper {
            display: flex;
            align-items: stretch;
            min-width: max-content;
            min-height: 700px;
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

        /* Connectors */
        .bracket-connector {
            width: 50px;
            display: flex;
            flex-direction: column;
            padding-top: 62px; /* высота заголовка + margin */
        }

        .connector-pair {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }

        .connector-pair::before {
            content: '';
            position: absolute;
            top: 25%;
            bottom: 25%;
            right: 0;
            width: 2px;
            background: var(--playoff-border-light);
        }

        .connector-pair::after {
            content: '';
            position: absolute;
            top: 50%;
            right: 0;
            width: 25px;
            height: 2px;
            background: var(--playoff-border-light);
        }

        .connector-line-top,
        .connector-line-bottom {
            flex: 1;
            position: relative;
        }

        .connector-line-top::after {
            content: '';
            position: absolute;
            bottom: 50%;
            left: 0;
            width: 25px;
            height: 2px;
            background: var(--playoff-border-light);
        }

        .connector-line-bottom::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            width: 25px;
            height: 2px;
            background: var(--playoff-border-light);
        }

        /* Match Card */
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

        .player-seed {
            width: 26px;
            height: 26px;
            background: var(--playoff-border);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--playoff-text-dim);
            flex-shrink: 0;
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

        .match-state.is-live {
            color: var(--playoff-yellow);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .match-state.is-live::before {
            content: '';
            width: 8px;
            height: 8px;
            background: var(--playoff-yellow);
            border-radius: 50%;
            animation: pulse-dot 1.5s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        /* Trophy */
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

        /* Final round centering */
        .round-final .round-matches {
            justify-content: center;
        }

        /* Responsive */
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
		.bracket-wrapper {
    gap: 40px;
}
/* Кнопка редактирования счёта */
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

/* Футер матча — разместить статус и кнопку в ряд */
.match-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
    </style>
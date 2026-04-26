{{-- resources/views/club/tournaments/kingofcourt/partials/_rounds.blade.php --}}
<div>
    <h6 class="text-white mb-3"><i class="bi bi-layers-fill text-primary me-2"></i>Раунды</h6>
    <div class="rounds-grid">
        @foreach($tournament->kingOfCourtRounds as $round)
            @php
                $isActive = $round->isInProgress();
                $isCompleted = $round->isCompleted();
                $statusClass = $isActive ? 'active' : ($isCompleted ? 'completed' : 'pending');
            @endphp
            <div class="round-card {{ $statusClass }}" data-round-id="{{ $round->id }}">
                <div class="round-header" onclick="toggleKocRound('koc-round-{{ $round->id }}')" style="cursor: pointer;">
                    <div class="round-title">
                        @if($isCompleted)
                            <i class="bi bi-check-circle-fill text-success"></i>
                        @elseif($isActive)
                            <i class="bi bi-play-circle-fill text-primary"></i>
                        @else
                            <i class="bi bi-clock text-secondary"></i>
                        @endif
                        Раунд {{ $round->round_number }}
                    </div>
                    <div class="round-header-right">
                        <span class="round-status {{ $statusClass }}">
                            {{ $isCompleted ? 'Завершён' : ($isActive ? 'Идёт' : 'Ожидание') }}
                        </span>
                        <i class="bi bi-chevron-down collapse-icon {{ $isActive ? '' : 'collapsed' }}" id="icon-koc-round-{{ $round->id }}"></i>
                    </div>
                </div>
                <div class="round-matches collapsible-content {{ $isActive ? '' : 'collapsed' }}" id="koc-round-{{ $round->id }}">
                    @php $courtsTotal = $round->matches->count(); @endphp
                    @foreach($round->matches as $match)
                        @php
                            $courtIdx = $match->court_number;
                            if ($courtIdx === 1) {
                                $courtLabel = 'Корт 1 · Топ';
                                $courtBadgeClass = 'court-top';
                            } elseif ($courtIdx === $courtsTotal) {
                                $courtLabel = "Корт {$courtIdx} · Дно";
                                $courtBadgeClass = 'court-bottom';
                            } else {
                                $courtLabel = "Корт {$courtIdx}";
                                $courtBadgeClass = 'court-middle';
                            }
                        @endphp
                        <div class="match-card" data-match-id="{{ $match->id }}">
                            <div class="match-court-header {{ $courtBadgeClass }}">
                                <i class="bi bi-geo-alt"></i> {{ $courtLabel }}
                            </div>
                            <div class="match-teams">
                                <div class="match-team {{ $match->winning_team === 1 ? 'winner' : '' }}">
                                    <div class="team-players">
                                        <div class="player-line">{{ $match->team1Player1->name }} <span class="player-level">{{ $match->team1Player1->level }}</span></div>
                                        <div class="player-line">{{ $match->team1Player2->name }} <span class="player-level">{{ $match->team1Player2->level }}</span></div>
                                    </div>
                                    @if($match->isCompleted())
                                        <div class="team-score">{{ $match->team1_score }}</div>
                                    @endif
                                </div>

                                <div class="match-vs">
                                    @if($match->isCompleted() && $tournament->status !== 'completed')
                                        <button class="btn-score-edit"
                                                data-bs-toggle="modal"
                                                data-bs-target="#kocEditScoreModal{{ $match->id }}"
                                                title="Редактировать счёт">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    @elseif(!$match->isCompleted() && $round->isInProgress())
                                        <button class="btn-score"
                                                data-bs-toggle="modal"
                                                data-bs-target="#kocScoreModal{{ $match->id }}">
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
                                        <div class="player-line">{{ $match->team2Player1->name }} <span class="player-level">{{ $match->team2Player1->level }}</span></div>
                                        <div class="player-line">{{ $match->team2Player2->name }} <span class="player-level">{{ $match->team2Player2->level }}</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @if($round->isInProgress() && !$match->isCompleted())
                            <div class="modal fade" id="kocScoreModal{{ $match->id }}" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content modal-dark">
                                        <div class="modal-header border-0">
                                            <h5 class="modal-title">Ввести счёт · {{ $courtLabel }}</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form action="{{ route('club.kingofcourt.saveScore', $match) }}" method="POST">
                                            @csrf
                                            <div class="modal-body">
                                                <div class="score-input-grid">
                                                    <div class="score-team">
                                                        <div class="score-team-names">
                                                            {{ $match->team1Player1->name }} / {{ $match->team1Player2->name }}
                                                        </div>
                                                        <input type="number" name="team1_score" class="form-control form-control-lg text-center"
                                                               min="0" max="99" required>
                                                    </div>
                                                    <div class="score-separator">:</div>
                                                    <div class="score-team">
                                                        <div class="score-team-names">
                                                            {{ $match->team2Player1->name }} / {{ $match->team2Player2->name }}
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

                        @if($match->isCompleted())
                            <div class="modal fade" id="kocEditScoreModal{{ $match->id }}" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content modal-dark">
                                        <div class="modal-header border-0">
                                            <h5 class="modal-title">Редактировать · {{ $courtLabel }}</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form action="{{ route('club.kingofcourt.updateScore', $match) }}" method="POST">
                                            @csrf
                                            @method('PUT')
                                            <div class="modal-body">
                                                <div class="score-input-grid">
                                                    <div class="score-team">
                                                        <div class="score-team-names">
                                                            {{ $match->team1Player1->name }} / {{ $match->team1Player2->name }}
                                                        </div>
                                                        <input type="number" name="team1_score" class="form-control form-control-lg text-center"
                                                               min="0" max="99" required value="{{ $match->team1_score }}">
                                                    </div>
                                                    <div class="score-separator">:</div>
                                                    <div class="score-team">
                                                        <div class="score-team-names">
                                                            {{ $match->team2Player1->name }} / {{ $match->team2Player2->name }}
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

    @if($tournament->status === 'in_progress')
        @php
            $service = app(\App\Services\KingOfCourtService::class);
            $canGenerateNext = $service->canGenerateNextRound($tournament);
            $currentRoundNumber = $tournament->kingOfCourtRounds->max('round_number') ?? 0;
        @endphp

        @if($canGenerateNext)
            <div class="text-center mt-4">
                <form action="{{ route('club.kingofcourt.nextRound', $tournament) }}" method="POST"
                      onsubmit="return confirm('Сгенерировать раунд {{ $currentRoundNumber + 1 }}? Игроки переместятся по кортам, пары перемешаются.')">
                    @csrf
                    <button type="submit" class="btn-primary-custom btn-lg">
                        <i class="bi bi-plus-circle me-2"></i> Сгенерировать раунд {{ $currentRoundNumber + 1 }}
                    </button>
                </form>
                <div class="text-secondary mt-2">
                    <small>Нажмите «Завершить турнир» в шапке, когда наиграете достаточно раундов.</small>
                </div>
            </div>
        @endif
    @endif
</div>

<style>
.round-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--bg-secondary); user-select: none; transition: all 0.3s; }
.round-header:hover { background: rgba(255, 255, 255, 0.08); }
.round-header-right { display: flex; align-items: center; gap: 12px; }
.collapse-icon { transition: transform 0.3s; color: var(--text-secondary); }
.collapse-icon.collapsed { transform: rotate(-90deg); }
.collapsible-content { max-height: 5000px; overflow: hidden; transition: max-height 0.3s ease-out, opacity 0.3s, padding 0.3s; opacity: 1; padding: 12px; }
.collapsible-content.collapsed { max-height: 0; opacity: 0; padding: 0 12px; }

.round-card.active { border: 2px solid var(--accent); box-shadow: 0 0 20px rgba(34, 197, 94, 0.3); background: var(--bg-card); }
.round-card.active .round-header { background: rgba(34, 197, 94, 0.15); }
.round-card.active .round-title { color: var(--accent); font-size: 1.3rem; }
.round-card.completed { opacity: 0.6; }
.round-card.pending { opacity: 0.4; }
.round-card.completed:hover, .round-card.pending:hover { opacity: 1; }

.round-status.completed { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
.round-status.active { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
.round-status.pending { background: rgba(156, 163, 175, 0.15); color: #9ca3af; }

.match-card { display: flex; flex-direction: column; align-items: center; }
.match-teams { display: flex; align-items: center; width: 100%; justify-content: space-between; }

.match-court-header {
    text-align: center;
    font-size: 22px;
    padding: 6px 16px;
    border-radius: 20px;
    font-weight: 600;
    margin-bottom: 12px;
    display: inline-block;
    width: 100%;
}
.match-court-header.court-top { color: #fbbf24; background: rgba(251, 191, 36, 0.12); }
.match-court-header.court-middle { color: #0dcaf0; background: rgba(13, 202, 240, 0.10); }
.match-court-header.court-bottom { color: #f87171; background: rgba(248, 113, 113, 0.10); }

.rounds-grid { display: grid; grid-template-columns: repeat(1, 1fr); gap: 16px; }
.player-line { font-size: 30px; }
.team-score { font-size: 40px; }
.score-team-names { font-size: 22px; }
</style>

<script>
function toggleKocRound(id) {
    const content = document.getElementById(id);
    const icon = document.getElementById('icon-' + id);

    if (content && icon) {
        content.classList.toggle('collapsed');
        icon.classList.toggle('collapsed');
    }
}
</script>

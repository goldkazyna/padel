{{-- resources/views/club/tournaments/partials/_team_groups.blade.php --}}
<div class="mb-4">
    <div class="card-header-dark" style="margin-bottom:20px;">
        <h5 class="mb-0"><i class="bi bi-collection text-info me-2"></i>Групповой этап</h5>
    </div>
    
    <div class="groups-container">
        @foreach($tournament->teamGroups as $group)
            @php
                $sortedStandings = app(\App\Services\TeamTournamentService::class)->getSortedStandings($group);
                $matchesByRound = $group->matches->groupBy('round_number');
                $groupLetter = strtoupper(substr($group->name, -1));
            @endphp
            
            <div class="group-card">
                {{-- Заголовок группы --}}
                <div class="group-header">
                    <div class="group-title">
                        <div class="group-letter">{{ $groupLetter }}</div>
                        <span class="group-name">{{ $group->name }}</span>
                    </div>
                    <span class="status-badge {{ $group->isCompleted() ? 'completed' : '' }}">
                        {{ $group->isCompleted() ? 'Завершена' : 'В игре' }}
                    </span>
                </div>

                {{-- Таблица standings --}}
                <table class="standings-table">
                    <thead>
                        <tr>
                            <th># Пара</th>
                            <th class="stat">И</th>
                            <th class="stat">В</th>
                            <th class="stat">П</th>
                            <th class="stat">ЗМ</th>
                            <th class="stat">ПМ</th>
                            <th class="stat">+/-</th>
                            <th class="stat">О</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sortedStandings as $index => $standing)
                            @php 
                                $team = \App\Models\TournamentTeam::find($standing['team_id']);
                                $diff = $standing['points_for'] - $standing['points_against'];
                                $rankClass = match($index) {
                                    0 => 'first',
                                    1 => 'second',
                                    2 => 'third',
                                    default => ''
                                };
                                $diffClass = $diff > 0 ? 'positive' : ($diff < 0 ? 'negative' : '');
                            @endphp
                            <tr>
                                <td>
                                    <div class="rank-cell">
                                        <span class="rank-number {{ $rankClass }}">{{ $index + 1 }}</span>
                                        <span class="team-name">{{ $team->full_name }}</span>
                                    </div>
                                </td>
                                <td class="stat-cell">{{ $standing['played'] }}</td>
                                <td class="stat-cell wins">{{ $standing['won'] }}</td>
                                <td class="stat-cell losses">{{ $standing['lost'] }}</td>
                                <td class="stat-cell">{{ $standing['points_for'] }}</td>
                                <td class="stat-cell">{{ $standing['points_against'] }}</td>
                                <td class="stat-cell diff {{ $diffClass }}">{{ $diff > 0 ? '+' : '' }}{{ $diff }}</td>
                                <td class="stat-cell points">{{ $standing['points'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- Секция матчей --}}
                <div class="matches-section">
                    <h3 class="matches-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Матчи
                    </h3>

                    @foreach($matchesByRound as $roundNumber => $matches)
                        <div class="round">
                            <div class="round-label">Тур {{ $roundNumber }}</div>
                            
                            @foreach($matches as $match)
                                @php
                                    $isCompleted = $match->isCompleted();
                                    $isInProgress = $match->status === 'in_progress';
                                    $team1Wins = $match->winner_id === $match->team1_id;
                                    $team2Wins = $match->winner_id === $match->team2_id;
                                @endphp
                                
                                <div class="match-card" data-match-id="{{ $match->id }}">
                                    {{-- Заголовок с кортом --}}
                                    <div class="match-court-header">
                                        <div class="court-info">
                                            <svg class="court-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="12" y1="4" x2="12" y2="20"/><line x1="2" y1="12" x2="22" y2="12"/></svg>
                                            <span class="court-name">{{ $match->court_number ? $tournament->getCourtName($match->court_number) : 'Корт' }}</span>
                                        </div>
                                        <span class="match-status {{ $isCompleted ? 'finished' : ($isInProgress ? 'live' : '') }}">
                                            @if($isCompleted)
                                                Завершён
                                            @elseif($isInProgress)
                                                ● Идёт игра
                                            @else
                                                Ожидание
                                            @endif
                                        </span>
                                    </div>
                                    
                                    {{-- Контент матча --}}
                                    <div class="match-content">
                                        <div class="match-team left {{ $team1Wins ? 'winner' : ($team2Wins ? 'loser' : '') }}">
                                            {{ $match->team1->full_name }}
                                        </div>
                                        
                                        <div class="match-score">
                                            @if($isCompleted)
                                                <span class="score-left {{ $team1Wins ? 'winner' : 'loser' }}">{{ $match->team1_score }}</span>
                                                <span class="score-separator">:</span>
                                                <span class="score-right {{ $team2Wins ? 'winner' : 'loser' }}">{{ $match->team2_score }}</span>
                                                @if($tournament->status !== 'completed')
                                                <button class="btn-score-edit-sm" data-bs-toggle="modal" data-bs-target="#editGroupMatchModal{{ $match->id }}">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                @endif
                                            @elseif($isInProgress)
                                                <button class="score-btn" data-bs-toggle="modal" data-bs-target="#groupMatchModal{{ $match->id }}">
                                                    Ввести счёт
                                                </button>
                                            @else
                                                <span class="vs-badge">VS</span>
                                            @endif
                                        </div>
                                        
                                        <div class="match-team right {{ $team2Wins ? 'winner' : ($team1Wins ? 'loser' : '') }}">
                                            {{ $match->team2->full_name }}
                                        </div>
                                    </div>
                                </div>

                                {{-- Модалка ввода счёта --}}
                                @if($isInProgress && !$isCompleted)
                                <div class="modal fade" id="groupMatchModal{{ $match->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content modal-dark">
                                            <div class="modal-header border-0">
                                                <h5 class="modal-title">{{ $group->name }} — Тур {{ $roundNumber }}</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form action="{{ route('club.team.saveGroupMatchScore', $match) }}" method="POST">
                                                @csrf
                                                <div class="modal-body">
                                                    <div class="score-input-grid">
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $match->team1->full_name }}</div>
                                                            <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" max="99" required placeholder="0">
                                                        </div>
                                                        <div class="score-separator">:</div>
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $match->team2->full_name }}</div>
                                                            <input type="number" name="team2_score" class="form-control form-control-lg text-center" min="0" max="99" required placeholder="0">
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
                                <div class="modal fade" id="editGroupMatchModal{{ $match->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content modal-dark">
                                            <div class="modal-header border-0">
                                                <h5 class="modal-title">Редактировать — {{ $group->name }}</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form action="{{ route('club.team.updateGroupMatchScore', $match) }}" method="POST">
                                                @csrf
                                                @method('PUT')
                                                <div class="modal-body">
                                                    <div class="score-input-grid">
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $match->team1->full_name }}</div>
                                                            <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" max="99" required value="{{ $match->team1_score }}">
                                                        </div>
                                                        <div class="score-separator">:</div>
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $match->team2->full_name }}</div>
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
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    
    {{-- Кнопка генерации плей-офф --}}
    @php $groupStageCompleted = app(\App\Services\TeamTournamentService::class)->isGroupStageCompleted($tournament); @endphp
    @if($groupStageCompleted && $tournament->playoffMatches->count() === 0)
        <div class="text-center mt-4">
            <form action="{{ route('club.team.generatePlayoff', $tournament) }}" method="POST" onsubmit="return confirm('Сгенерировать сетку плей-офф?')">
                @csrf
                <button type="submit" class="btn-primary-custom btn-lg">
                    <i class="bi bi-diagram-3 me-2"></i> Сгенерировать плей-офф
                </button>
            </form>
        </div>
    @elseif(!$groupStageCompleted)
        <div class="text-center mt-3">
            <span class="text-secondary"><i class="bi bi-hourglass-split me-1"></i> Завершите все матчи группового этапа</span>
        </div>
    @endif
</div>
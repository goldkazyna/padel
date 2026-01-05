<div class="section-header">
    <h5><i class="bi bi-diagram-3"></i> Группы и раунды</h5>
</div>

<!-- Вкладки групп -->
<ul class="group-tabs mb-4" id="groupTabs" role="tablist">
    @foreach($tournament->groups as $groupIndex => $group)
        <li class="nav-item" role="presentation">
            <button class="group-tab {{ $groupIndex === 0 ? 'active' : '' }}" 
                    id="group{{ $group->id }}-tab"
                    data-bs-toggle="tab" 
                    data-bs-target="#group{{ $group->id }}"
                    type="button"
                    role="tab">
                {{ $group->name }}
                <span class="group-tab-count">{{ $group->players->count() }}</span>
            </button>
        </li>
    @endforeach
</ul>

<!-- Контент вкладок -->
<div class="tab-content" id="groupTabsContent">
    @foreach($tournament->groups as $groupIndex => $group)
        <div class="tab-pane fade {{ $groupIndex === 0 ? 'show active' : '' }}" 
             id="group{{ $group->id }}"
             role="tabpanel">
            
            <!-- Таблица лидеров группы -->
            <div class="section-subheader">
                <i class="bi bi-trophy"></i> Таблица лидеров
            </div>
            
            <div class="leaderboard-list mb-4">
                @foreach($group->players as $index => $player)
                    <div class="leaderboard-row {{ $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : '')) }}">
                        <div class="leaderboard-rank {{ $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : '')) }}">
                            {{ $index + 1 }}
                        </div>
                        <div class="leaderboard-avatar">
                            {{ strtoupper(substr($player->first_name, 0, 1) . substr($player->last_name, 0, 1)) }}
                        </div>
                        <div class="leaderboard-info">
                            <div class="leaderboard-name">{{ $player->full_name }}</div>
                            <div class="leaderboard-rating">Рейтинг: {{ $player->rating }}</div>
                        </div>
                        <div class="leaderboard-points">{{ $player->pivot->total_points }}</div>
                    </div>
                @endforeach
            </div>

            <!-- Раунды -->
            <div class="section-subheader">
                <i class="bi bi-calendar3"></i> Раунды
            </div>
            
            <div class="rounds-grid">
                @foreach($group->rounds as $round)
                    <div class="round-card" data-round-id="{{ $round->id }}">
                        <div class="round-header">
                            <div class="round-title">
                                @if($round->isCompleted())
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                @elseif($round->status === 'in_progress')
                                    <i class="bi bi-play-circle-fill text-primary"></i>
                                @else
                                    <i class="bi bi-circle text-secondary"></i>
                                @endif
                                Раунд {{ $round->round_number }}
                            </div>
                            <span class="round-status {{ $round->isCompleted() ? 'completed' : ($round->status === 'in_progress' ? 'active' : 'pending') }}">
                                {{ $round->isCompleted() ? 'Завершён' : ($round->status === 'in_progress' ? 'Идёт' : 'Ожидает') }}
                            </span>
                        </div>
                        
                        <div class="round-matches">
                            @foreach($round->matches as $match)
                                <div class="match-card" data-match-id="{{ $match->id }}">
                                    <div class="match-team {{ $match->winning_team === 1 ? 'winner' : '' }}">
                                        <div class="team-players">
                                            <div class="player-line">{{ $match->team1Player1->full_name }} <span class="player-level">{{ $match->team1Player1->level }}</span></div>
                                            <div class="player-line">{{ $match->team1Player2->full_name }} <span class="player-level">{{ $match->team1Player2->level }}</span></div>
                                        </div>
                                        @if($match->isCompleted())
                                            <div class="team-score">{{ $match->team1_score }}</div>
                                        @endif
                                    </div>
                                    
                                    <div class="match-vs">
                                        @if($match->isCompleted())
                                            <button class="btn-score-edit" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editScoreModal{{ $match->id }}"
                                                    title="Редактировать счёт">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        @elseif($round->status === 'in_progress')
                                            <button class="btn-score" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#scoreModal{{ $match->id }}">
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
                                            <div class="player-line">{{ $match->team2Player1->full_name }} <span class="player-level">{{ $match->team2Player1->level }}</span></div>
                                            <div class="player-line">{{ $match->team2Player2->full_name }} <span class="player-level">{{ $match->team2Player2->level }}</span></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Модалка ввода счёта -->
                                @if(!$match->isCompleted())
                                    @include('club.tournaments.partials._modal_score', ['match' => $match, 'modalId' => 'scoreModal'.$match->id, 'route' => 'club.americano.saveScore', 'group' => $group])
                                @endif

                                <!-- Модалка редактирования -->
                                @include('club.tournaments.partials._modal_edit_score', ['match' => $match, 'modalId' => 'editScoreModal'.$match->id, 'route' => 'club.americano.updateScore'])
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
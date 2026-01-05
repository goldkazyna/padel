<div class="card-dark mb-4">
    <div class="card-header-dark">
        <h5 class="mb-0"><i class="bi bi-trophy text-warning me-2"></i>Турнир Мексикано</h5>
    </div>
    <div class="card-body-dark">
        
        {{-- Информация о турнире --}}
        <div class="alert-info-custom mb-4">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Раундов:</strong> {{ $tournament->mexicanoRounds->count() }} / {{ $tournament->rounds_count }}
            &nbsp;|&nbsp;
            <strong>Сумма очков:</strong> {{ $tournament->points_to_win }}
        </div>

        <div class="row">
            {{-- Таблица лидеров --}}
            <div class="col-lg-4 mb-4">
                <h6 class="text-white mb-3"><i class="bi bi-bar-chart-fill text-success me-2"></i>Таблица лидеров</h6>
                <div class="leaderboard-list" id="mexicanoLeaderboard">
                    @foreach($tournament->mexicanoPlayers()->orderBy('total_points', 'desc')->with('user')->get() as $index => $player)
                        @php $rankClass = $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : '')); @endphp
                        <div class="leaderboard-row {{ $rankClass }}">
                            <div class="leaderboard-rank {{ $rankClass }}">{{ $index + 1 }}</div>
                            <div class="leaderboard-avatar">
                                {{ strtoupper(substr($player->user->first_name, 0, 1) . substr($player->user->last_name, 0, 1)) }}
                            </div>
                            <div class="leaderboard-info">
                                <div class="leaderboard-name">{{ $player->user->full_name }}</div>
                                <div class="leaderboard-rating">Рейтинг: {{ $player->user->rating }}</div>
                            </div>
                            <div class="leaderboard-points">{{ $player->total_points }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Раунды --}}
            <div class="col-lg-8">
                <h6 class="text-white mb-3"><i class="bi bi-layers-fill text-primary me-2"></i>Раунды</h6>
                <div class="rounds-grid">
                    @foreach($tournament->mexicanoRounds as $round)
                        <div class="round-card" data-round-id="{{ $round->id }}">
                            <div class="round-header">
                                <div class="round-title">
                                    @if($round->isCompleted())
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                    @elseif($round->isInProgress())
                                        <i class="bi bi-play-circle-fill text-primary"></i>
                                    @else
                                        <i class="bi bi-clock text-secondary"></i>
                                    @endif
                                    Раунд {{ $round->round_number }}
                                </div>
                                <span class="round-status {{ $round->status }}">
                                    {{ $round->isCompleted() ? 'Завершён' : ($round->isInProgress() ? 'Идёт' : 'Ожидание') }}
                                </span>
                            </div>
                            <div class="round-matches">
                                @foreach($round->matches as $match)
                                    <div class="match-card" data-match-id="{{ $match->id }}">
                                        <div class="match-team {{ $match->winning_team === 1 ? 'winner' : '' }}">
                                            <div class="team-players">
                                                <div class="player-badge">
                                                    <span class="player-name">{{ $match->team1Player1->first_name }}</span>
                                                    <span class="player-level">{{ $match->team1Player1->level }}</span>
                                                </div>
                                                <div class="player-badge">
                                                    <span class="player-name">{{ $match->team1Player2->first_name }}</span>
                                                    <span class="player-level">{{ $match->team1Player2->level }}</span>
                                                </div>
                                            </div>
                                            @if($match->isCompleted())
                                                <div class="team-score {{ $match->winning_team === 1 ? 'text-success' : '' }}">
                                                    {{ $match->team1_score }}
                                                </div>
                                            @endif
                                        </div>
                                        
                                        <div class="match-vs">
                                            @if($match->isCompleted())
                                                <button class="btn-score-edit" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editMexicanoScoreModal{{ $match->id }}">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            @elseif($round->isInProgress())
                                                <button class="btn-score" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#mexicanoScoreModal{{ $match->id }}">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                            @else
                                                <span class="text-secondary">VS</span>
                                            @endif
                                        </div>
                                        
                                        <div class="match-team {{ $match->winning_team === 2 ? 'winner' : '' }}">
                                            @if($match->isCompleted())
                                                <div class="team-score {{ $match->winning_team === 2 ? 'text-success' : '' }}">
                                                    {{ $match->team2_score }}
                                                </div>
                                            @endif
                                            <div class="team-players">
                                                <div class="player-badge">
                                                    <span class="player-name">{{ $match->team2Player1->first_name }}</span>
                                                    <span class="player-level">{{ $match->team2Player1->level }}</span>
                                                </div>
                                                <div class="player-badge">
                                                    <span class="player-name">{{ $match->team2Player2->first_name }}</span>
                                                    <span class="player-level">{{ $match->team2Player2->level }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Модалки --}}
                                    @if(!$match->isCompleted())
                                        @include('club.tournaments.partials._modal_mexicano', ['match' => $match, 'round' => $round, 'type' => 'input'])
                                    @endif
                                    @include('club.tournaments.partials._modal_mexicano', ['match' => $match, 'round' => $round, 'type' => 'edit'])
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Генерация следующего раунда --}}
                @php
                    $currentRoundNumber = $tournament->mexicanoRounds->count();
                    $lastRound = $tournament->mexicanoRounds->last();
                @endphp
                @if($lastRound && $lastRound->isCompleted() && $currentRoundNumber < $tournament->rounds_count)
                    <div class="text-center mt-4">
                        <form action="{{ route('club.mexicano.nextRound', $tournament) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn-primary-custom">
                                <i class="bi bi-plus-circle me-1"></i> Сгенерировать раунд {{ $currentRoundNumber + 1 }}
                            </button>
                        </form>
                        <div class="text-secondary mt-2">
                            <small>Осталось раундов: {{ $tournament->rounds_count - $currentRoundNumber }}</small>
                        </div>
                    </div>
                @elseif($currentRoundNumber >= $tournament->rounds_count)
                    <div class="text-center mt-4">
                        <div class="alert-success-custom">
                            <i class="bi bi-check-circle me-2"></i>
                            Все {{ $tournament->rounds_count }} раундов сыграны! Можно завершить турнир.
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
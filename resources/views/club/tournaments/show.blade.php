@extends('layouts.app')

@section('title', $tournament->name)

@section('content')
<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club->name }} · {{ $tournament->type_name }}</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
		@if($tournament->status === 'open')
			@if($tournament->participants->count() < $tournament->max_participants)
				<form action="{{ route('club.tournaments.addTestPlayers', $tournament) }}" method="POST">
					@csrf
					<button type="submit" class="btn-outline-custom">
						<i class="bi bi-people-fill"></i> +Тест игроки
					</button>
				</form>
			@endif
			
			@if(($tournament->isAmericano() || $tournament->isMexicano()) && $tournament->participants->count() === $tournament->max_participants)
				<form action="{{ route('club.tournaments.start', $tournament) }}" method="POST" 
					  onsubmit="return confirm('Начать турнир? Раунды будут сгенерированы автоматически.')">
					@csrf
					<button type="submit" class="btn-primary-custom">
						<i class="bi bi-play-fill"></i> Начать турнир
					</button>
				</form>
			@elseif($tournament->isTeamBased() && $tournament->teams->count() === $tournament->max_participants / 2)
				<form action="{{ route('club.tournaments.start', $tournament) }}" method="POST" 
					  onsubmit="return confirm('Начать турнир? Группы и матчи будут сгенерированы автоматически.')">
					@csrf
					<button type="submit" class="btn-primary-custom">
						<i class="bi bi-play-fill"></i> Начать турнир
					</button>
				</form>
			@endif
		@endif

		@if($tournament->status === 'in_progress')
			@if($tournament->isAmericano())
				@php
					$canFinish = app(\App\Services\AmericanoService::class)->canFinishTournament($tournament);
				@endphp
				@if($canFinish)
					<form action="{{ route('club.tournaments.finish', $tournament) }}" method="POST" 
						  onsubmit="return confirm('Завершить турнир и начислить рейтинг всем участникам?')">
						@csrf
						<button type="submit" class="btn-primary-custom">
							<i class="bi bi-trophy-fill"></i> Завершить турнир
						</button>
					</form>
				@else
					<span class="btn-outline-custom disabled" title="Сыграйте все матчи">
						<i class="bi bi-hourglass"></i> Не все матчи сыграны
					</span>
				@endif
			@elseif($tournament->isMexicano())
				@php
					$canFinish = app(\App\Services\MexicanoService::class)->canFinishTournament($tournament);
				@endphp
				@if($canFinish)
					<form action="{{ route('club.tournaments.finish', $tournament) }}" method="POST" 
						  onsubmit="return confirm('Завершить турнир и начислить рейтинг всем участникам?')">
						@csrf
						<button type="submit" class="btn-primary-custom">
							<i class="bi bi-trophy-fill"></i> Завершить турнир
						</button>
					</form>
				@else
					<span class="btn-outline-custom disabled" title="Сыграйте все раунды">
						<i class="bi bi-hourglass"></i> Не все раунды сыграны
					</span>
				@endif
			@elseif($tournament->isTeamBased())
				@php
					$canFinish = app(\App\Services\TeamTournamentService::class)->canFinishTournament($tournament);
				@endphp
				@if($canFinish)
					<form action="{{ route('club.tournaments.finish', $tournament) }}" method="POST" 
						  onsubmit="return confirm('Завершить турнир?')">
						@csrf
						<button type="submit" class="btn-primary-custom">
							<i class="bi bi-trophy-fill"></i> Завершить турнир
						</button>
					</form>
				@elseif($tournament->playoffMatches->count() > 0)
					<span class="btn-outline-custom disabled">
						<i class="bi bi-hourglass"></i> Сыграйте финал
					</span>
				@endif
			@endif
		@endif
		
		<a href="{{ route('club.tournaments.edit', $tournament) }}" class="btn-outline-custom">
			<i class="bi bi-pencil"></i> Редактировать
		</a>
		<a href="{{ route('club.tournaments.index') }}" class="btn-outline-custom">
			<i class="bi bi-arrow-left"></i> Назад
		</a>
	</div>
</div>

<!-- Информация о турнире -->
<div class="info-grid mb-4">
    <div class="info-card">
        <div class="info-icon"><i class="bi bi-calendar3"></i></div>
        <div class="info-content">
            <div class="info-label">Дата</div>
            <div class="info-value">{{ $tournament->start_date->format('d.m.Y H:i') }}</div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-icon"><i class="bi bi-hourglass-split"></i></div>
        <div class="info-content">
            <div class="info-label">Дедлайн</div>
            <div class="info-value">{{ $tournament->registration_deadline->format('d.m.Y H:i') }}</div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-icon"><i class="bi bi-bar-chart"></i></div>
        <div class="info-content">
            <div class="info-label">Уровень</div>
            <div class="info-value">{{ $tournament->min_level }} — {{ $tournament->max_level }}</div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-icon"><i class="bi bi-people-fill"></i></div>
        <div class="info-content">
            <div class="info-label">Участников</div>
            <div class="info-value">{{ $tournament->participants->count() }} / {{ $tournament->max_participants }}</div>
        </div>
    </div>
    <div class="info-card">
        <div class="info-icon"><i class="bi bi-cash"></i></div>
        <div class="info-content">
            <div class="info-label">Стоимость</div>
            <div class="info-value">{{ $tournament->price > 0 ? number_format($tournament->price, 0) . ' ₸' : 'Бесплатно' }}</div>
        </div>
    </div>
    @if($tournament->isAmericano())
        <div class="info-card">
            <div class="info-icon"><i class="bi bi-bullseye"></i></div>
            <div class="info-content">
                <div class="info-label">Очки для победы</div>
                <div class="info-value">{{ $tournament->points_to_win }}</div>
            </div>
        </div>
    @endif
    <div class="info-card status">
        <span class="badge-{{ $tournament->status_color }}-custom">{{ $tournament->status_name }}</span>
    </div>
</div>

<!-- Участники -->
<div class="section-header">
    <h5><i class="bi bi-people"></i> Участники ({{ $tournament->participants->count() }})</h5>
</div>

<div class="participants-list mb-4">
    @forelse($tournament->participants as $index => $participant)
        <div class="participant-row">
            <div class="participant-rank">{{ $index + 1 }}</div>
            <div class="participant-avatar">
                {{ strtoupper(substr($participant->first_name, 0, 1) . substr($participant->last_name, 0, 1)) }}
            </div>
            <div class="participant-info">
                <div class="participant-name">{{ $participant->full_name }}</div>
                <div class="participant-meta">
                    <span class="level-badge">{{ $participant->level }}</span>
                    <span class="text-secondary">{{ $participant->pivot->created_at->format('d.m.Y') }}</span>
                </div>
            </div>
            <div class="participant-rating">{{ $participant->rating }}</div>
            @if($tournament->status === 'open')
                <form action="{{ route('club.tournaments.participants.remove', [$tournament, $participant->id]) }}" 
                      method="POST" onsubmit="return confirm('Удалить участника?')">
                    @csrf
                    @method('DELETE')
                    <button class="btn-danger-custom btn-sm"><i class="bi bi-x"></i></button>
                </form>
            @endif
        </div>
    @empty
        <div class="empty-state">
            <i class="bi bi-people"></i>
            <p>Пока нет участников</p>
        </div>
    @endforelse
</div>

<!-- Американо: Группы и раунды -->
@if($tournament->isAmericano() && $tournament->groups->count() > 0)
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
						role="tab"
						aria-controls="group{{ $group->id }}"
						aria-selected="{{ $groupIndex === 0 ? 'true' : 'false' }}">
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
				 role="tabpanel"
				 aria-labelledby="group{{ $group->id }}-tab">
                
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
                                        <div class="modal fade" id="scoreModal{{ $match->id }}" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content modal-dark">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Ввод счёта</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form action="{{ route('club.americano.saveScore', $match) }}" method="POST" 
														  data-ajax-score data-match-id="{{ $match->id }}" data-group-id="{{ $group->id }}">
														@csrf
                                                        <div class="modal-body">
                                                            <div class="score-input-group">
                                                                <div class="score-team">
                                                                    <div class="score-team-name">
                                                                        {{ $match->team1Player1->full_name }} / {{ $match->team1Player2->full_name }}
                                                                    </div>
                                                                    <input type="number" name="team1_score" class="score-input" 
                                                                           min="0" max="{{ $tournament->points_to_win }}" placeholder="0" required>
                                                                </div>
                                                                <div class="score-separator">:</div>
                                                                <div class="score-team">
                                                                    <div class="score-team-name">
                                                                        {{ $match->team2Player1->full_name }} / {{ $match->team2Player2->full_name }}
                                                                    </div>
                                                                    <input type="number" name="team2_score" class="score-input" 
                                                                           min="0" max="{{ $tournament->points_to_win }}" placeholder="0" required>
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
                                    @endif

                                    <!-- Модалка редактирования счёта -->
                                    @if($match->isCompleted())
                                        <div class="modal fade" id="editScoreModal{{ $match->id }}" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content modal-dark">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Редактировать счёт</h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form action="{{ route('club.americano.updateScore', $match) }}" method="POST"
														  data-ajax-score data-match-id="{{ $match->id }}" data-group-id="{{ $group->id }}">
														@csrf
														@method('PUT')
                                                        <div class="modal-body">
                                                            <div class="score-input-group">
                                                                <div class="score-team">
                                                                    <div class="score-team-name">
                                                                        {{ $match->team1Player1->full_name }} / {{ $match->team1Player2->full_name }}
                                                                    </div>
                                                                    <input type="number" name="team1_score" class="score-input" 
                                                                           min="0" max="{{ $tournament->points_to_win }}" 
                                                                           value="{{ $match->team1_score }}" required>
                                                                </div>
                                                                <div class="score-separator">:</div>
                                                                <div class="score-team">
                                                                    <div class="score-team-name">
                                                                        {{ $match->team2Player1->full_name }} / {{ $match->team2Player2->full_name }}
                                                                    </div>
                                                                    <input type="number" name="team2_score" class="score-input" 
                                                                           min="0" max="{{ $tournament->points_to_win }}" 
                                                                           value="{{ $match->team2_score }}" required>
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
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

            </div>
        @endforeach
    </div>
@endif







{{-- Мексикано --}}
@if($tournament->isMexicano() && $tournament->mexicanoPlayers->count() > 0)
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
                        @php
                            $rankClass = $index === 0 ? 'gold' : ($index === 1 ? 'silver' : ($index === 2 ? 'bronze' : ''));
                        @endphp
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
                                    @if($round->isCompleted())
                                        Завершён
                                    @elseif($round->isInProgress())
                                        Идёт
                                    @else
                                        Ожидание
                                    @endif
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
                                                        data-bs-target="#editMexicanoScoreModal{{ $match->id }}"
                                                        title="Редактировать счёт">
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

                                    {{-- Модалка ввода счёта --}}
                                    @if(!$match->isCompleted())
                                    <div class="modal fade" id="mexicanoScoreModal{{ $match->id }}" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content modal-dark">
                                                <div class="modal-header border-0">
                                                    <h5 class="modal-title">Ввод счёта — Раунд {{ $round->round_number }}</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form action="{{ route('club.mexicano.saveScore', $match) }}" method="POST"
                                                      data-ajax-score data-match-id="{{ $match->id }}" data-mexicano="true">
                                                    @csrf
                                                    <div class="modal-body">
                                                        <div class="score-input-grid">
                                                            <div class="score-team">
                                                                <div class="score-team-names">
                                                                    {{ $match->team1Player1->first_name }} / {{ $match->team1Player2->first_name }}
                                                                </div>
                                                                <input type="number" name="team1_score" class="form-control form-control-lg text-center" 
                                                                       min="0" max="99" required placeholder="0">
                                                            </div>
                                                            <div class="score-separator">:</div>
                                                            <div class="score-team">
                                                                <div class="score-team-names">
                                                                    {{ $match->team2Player1->first_name }} / {{ $match->team2Player2->first_name }}
                                                                </div>
                                                                <input type="number" name="team2_score" class="form-control form-control-lg text-center" 
                                                                       min="0" max="99" required placeholder="0">
                                                            </div>
                                                        </div>
                                                        <div class="text-center text-secondary mt-2">
                                                            <small>Сумма должна быть {{ $tournament->points_to_win }}</small>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer border-0">
                                                        <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Отмена</button>
                                                        <button type="submit" class="btn-primary-custom">
                                                            <i class="bi bi-check-lg me-1"></i> Сохранить
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    @endif

                                    {{-- Модалка редактирования счёта --}}
                                    <div class="modal fade" id="editMexicanoScoreModal{{ $match->id }}" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content modal-dark">
                                                <div class="modal-header border-0">
                                                    <h5 class="modal-title">Редактировать счёт — Раунд {{ $round->round_number }}</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form action="{{ route('club.mexicano.updateScore', $match) }}" method="POST"
                                                      data-ajax-score data-match-id="{{ $match->id }}" data-mexicano="true">
                                                    @csrf
                                                    @method('PUT')
                                                    <div class="modal-body">
                                                        <div class="score-input-grid">
                                                            <div class="score-team">
                                                                <div class="score-team-names">
                                                                    {{ $match->team1Player1->first_name }} / {{ $match->team1Player2->first_name }}
                                                                </div>
                                                                <input type="number" name="team1_score" class="form-control form-control-lg text-center" 
                                                                       min="0" max="99" required value="{{ $match->team1_score }}">
                                                            </div>
                                                            <div class="score-separator">:</div>
                                                            <div class="score-team">
                                                                <div class="score-team-names">
                                                                    {{ $match->team2Player1->first_name }} / {{ $match->team2Player2->first_name }}
                                                                </div>
                                                                <input type="number" name="team2_score" class="form-control form-control-lg text-center" 
                                                                       min="0" max="99" required value="{{ $match->team2_score }}">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer border-0">
                                                        <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Отмена</button>
                                                        <button type="submit" class="btn-primary-custom">
                                                            <i class="bi bi-check-lg me-1"></i> Обновить
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
				{{-- Кнопка следующего раунда --}}
                @if($tournament->status === 'in_progress')
                    @php
                        $canGenerateNext = app(\App\Services\MexicanoService::class)->canGenerateNextRound($tournament);
                        $currentRoundNumber = $tournament->mexicanoRounds->max('round_number') ?? 0;
                    @endphp
                    
                    @if($canGenerateNext)
                        <div class="text-center mt-4">
                            <form action="{{ route('club.mexicano.nextRound', $tournament) }}" method="POST"
                                  onsubmit="return confirm('Сгенерировать раунд {{ $currentRoundNumber + 1 }}? Пары будут составлены по текущим очкам.')">
                                @csrf
                                <button type="submit" class="btn-primary-custom btn-lg">
                                    <i class="bi bi-plus-circle me-2"></i>
                                    Сгенерировать раунд {{ $currentRoundNumber + 1 }}
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
                @endif
				
				
            </div>
        </div>
    </div>
</div>
@endif


{{-- Групповой + Плей-офф --}}
@if($tournament->isTeamBased())
    <div class="card-dark mb-4">
        <div class="card-header-dark d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-people-fill text-primary me-2"></i>Зарегистрированные пары</h5>
            <span class="badge bg-primary">{{ $tournament->teams->count() }} / {{ $tournament->max_participants / 2 }} пар</span>
        </div>
        <div class="card-body-dark">
            @if($tournament->status === 'open')
                {{-- Форма добавления пары --}}
                <div class="mb-4 p-3" style="background: rgba(255,255,255,0.05); border-radius: 8px;">
                    <h6 class="text-white mb-3"><i class="bi bi-plus-circle me-2"></i>Добавить пару</h6>
                    <form action="{{ route('club.tournaments.addTeam', $tournament) }}" method="POST">
                        @csrf
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Игрок 1 *</label>
                                <input type="text" class="form-control" id="searchPlayer1" placeholder="Поиск по email или имени..." autocomplete="off">
                                <input type="hidden" name="player1_id" id="player1_id">
                                <div id="player1Results" class="search-results"></div>
                                <div id="player1Selected" class="selected-player mt-2" style="display: none;"></div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Игрок 2 *</label>
                                <input type="text" class="form-control" id="searchPlayer2" placeholder="Поиск по email или имени..." autocomplete="off">
                                <input type="hidden" name="player2_id" id="player2_id">
                                <div id="player2Results" class="search-results"></div>
                                <div id="player2Selected" class="selected-player mt-2" style="display: none;"></div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Название команды</label>
                                <input type="text" name="name" class="form-control" placeholder="Опционально">
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn-primary-custom">
								<i class="bi bi-plus-lg me-1"></i> Добавить пару
							</button>
							</form>

							<form action="{{ route('club.tournaments.addTestTeams', $tournament) }}" method="POST" class="d-inline mt-2">
								@csrf
								<button type="submit" class="btn-outline-custom">
									<i class="bi bi-lightning me-1"></i> +Тестовые пары
								</button>
							</form>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Список пар --}}
            @if($tournament->teams->count() > 0)
                <div class="teams-grid">
                    @foreach($tournament->teams()->orderBy('rating_avg', 'desc')->get() as $index => $team)
                        <div class="team-card">
                            <div class="team-rank">{{ $index + 1 }}</div>
                            <div class="team-info">
                                <div class="team-name">{{ $team->name }}</div>
                                <div class="team-players-names">
                                    {{ $team->player1->full_name }} / {{ $team->player2->full_name }}
                                </div>
                                <div class="team-rating">
                                    <i class="bi bi-star-fill text-warning"></i> {{ $team->rating_avg }}
                                </div>
                            </div>
                            @if($tournament->status === 'open')
                                <form action="{{ route('club.tournaments.removeTeam', [$tournament, $team]) }}" method="POST" 
                                      onsubmit="return confirm('Удалить пару?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-remove-team" title="Удалить">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center text-secondary py-4">
                    <i class="bi bi-people" style="font-size: 3rem;"></i>
                    <p class="mt-2">Пока нет зарегистрированных пар</p>
                </div>
            @endif
        </div>
    </div>
@endif
{{-- Групповой этап --}}
@if($tournament->isTeamBased() && $tournament->teamGroups->count() > 0)
<div class="card-dark mb-4">
    <div class="card-header-dark">
        <h5 class="mb-0"><i class="bi bi-collection text-info me-2"></i>Групповой этап</h5>
    </div>
    <div class="card-body-dark">
        <div class="row">
            @foreach($tournament->teamGroups as $group)
                <div class="col-lg-6 mb-4">
                    <div class="group-card">
                        <div class="group-header">
                            <h6 class="mb-0">{{ $group->name }}</h6>
                            @if($group->isCompleted())
                                <span class="badge bg-success">Завершена</span>
                            @else
                                <span class="badge bg-primary">В игре</span>
                            @endif
                        </div>
                        
                        {{-- Таблица группы --}}
                        <div class="table-responsive mb-3">
                            <table class="table table-dark table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 30px;">#</th>
                                        <th>Пара</th>
                                        <th class="text-center" title="Сыграно">И</th>
                                        <th class="text-center" title="Победы">В</th>
                                        <th class="text-center" title="Поражения">П</th>
                                        <th class="text-center" title="Забито">ЗМ</th>
                                        <th class="text-center" title="Пропущено">ПМ</th>
                                        <th class="text-center" title="Разница">+/-</th>
                                        <th class="text-center" title="Очки"><strong>О</strong></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($group->standings as $index => $standing)
                                        <tr class="{{ $index < $tournament->teams_advance ? 'table-success-custom' : '' }}">
                                            <td>{{ $index + 1 }}</td>
                                            <td>
                                                <span class="team-name-cell">{{ $standing->team->name }}</span>
                                            </td>
                                            <td class="text-center">{{ $standing->played }}</td>
                                            <td class="text-center">{{ $standing->won }}</td>
                                            <td class="text-center">{{ $standing->lost }}</td>
                                            <td class="text-center">{{ $standing->points_for }}</td>
                                            <td class="text-center">{{ $standing->points_against }}</td>
                                            <td class="text-center">
                                                @php $diff = $standing->points_diff; @endphp
                                                <span class="{{ $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : '') }}">
                                                    {{ $diff > 0 ? '+' : '' }}{{ $diff }}
                                                </span>
                                            </td>
                                            <td class="text-center"><strong>{{ $standing->points }}</strong></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        
                        {{-- Матчи группы --}}
                        <div class="group-matches">
                            <h6 class="text-secondary mb-2"><i class="bi bi-calendar3 me-1"></i>Матчи</h6>
                            @php
                                $matchesByRound = $group->matches->groupBy('round_number');
                            @endphp
                            @foreach($matchesByRound as $roundNumber => $matches)
                                <div class="round-block mb-2">
                                    <div class="round-label">Тур {{ $roundNumber }}</div>
                                    @foreach($matches as $match)
                                        <div class="group-match-card" data-match-id="{{ $match->id }}">
                                            <div class="match-team-name {{ $match->winner_id === $match->team1_id ? 'winner' : '' }}">
                                                {{ $match->team1->name }}
                                            </div>
                                            <div class="match-score-block">
                                                @if($match->isCompleted())
                                                    <span class="match-score">{{ $match->team1_score }} : {{ $match->team2_score }}</span>
                                                    <button class="btn-score-edit-sm" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editGroupMatchModal{{ $match->id }}">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                @elseif($match->status === 'in_progress')
                                                    <button class="btn-score-sm" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#groupMatchModal{{ $match->id }}">
                                                        <i class="bi bi-pencil-square"></i> Счёт
                                                    </button>
                                                @else
                                                    <span class="text-secondary">—</span>
                                                @endif
                                            </div>
                                            <div class="match-team-name {{ $match->winner_id === $match->team2_id ? 'winner' : '' }}">
                                                {{ $match->team2->name }}
                                            </div>
                                        </div>

                                        {{-- Модалка ввода счёта --}}
                                        @if($match->status === 'in_progress' && !$match->isCompleted())
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
                                                                    <div class="score-team-names">{{ $match->team1->name }}</div>
                                                                    <input type="number" name="team1_score" class="form-control form-control-lg text-center" 
                                                                           min="0" max="99" required placeholder="0">
                                                                </div>
                                                                <div class="score-separator">:</div>
                                                                <div class="score-team">
                                                                    <div class="score-team-names">{{ $match->team2->name }}</div>
                                                                    <input type="number" name="team2_score" class="form-control form-control-lg text-center" 
                                                                           min="0" max="99" required placeholder="0">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer border-0">
                                                            <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Отмена</button>
                                                            <button type="submit" class="btn-primary-custom">
                                                                <i class="bi bi-check-lg me-1"></i> Сохранить
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        @endif

                                        {{-- Модалка редактирования счёта --}}
                                        @if($match->isCompleted())
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
                                                                    <div class="score-team-names">{{ $match->team1->name }}</div>
                                                                    <input type="number" name="team1_score" class="form-control form-control-lg text-center" 
                                                                           min="0" max="99" required value="{{ $match->team1_score }}">
                                                                </div>
                                                                <div class="score-separator">:</div>
                                                                <div class="score-team">
                                                                    <div class="score-team-names">{{ $match->team2->name }}</div>
                                                                    <input type="number" name="team2_score" class="form-control form-control-lg text-center" 
                                                                           min="0" max="99" required value="{{ $match->team2_score }}">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer border-0">
                                                            <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Отмена</button>
                                                            <button type="submit" class="btn-primary-custom">
                                                                <i class="bi bi-check-lg me-1"></i> Обновить
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
                </div>
            @endforeach
        </div>
        
        {{-- Кнопка генерации плей-офф --}}
        @php
            $groupStageCompleted = app(\App\Services\TeamTournamentService::class)->isGroupStageCompleted($tournament);
        @endphp
        @if($groupStageCompleted && $tournament->playoffMatches->count() === 0)
            <div class="text-center mt-4">
                <form action="{{ route('club.team.generatePlayoff', $tournament) }}" method="POST"
                      onsubmit="return confirm('Сгенерировать сетку плей-офф? Топ-{{ $tournament->teams_advance }} из каждой группы выйдут в следующий этап.')">
                    @csrf
                    <button type="submit" class="btn-primary-custom btn-lg">
                        <i class="bi bi-diagram-3 me-2"></i>
                        Сгенерировать плей-офф
                    </button>
                </form>
            </div>
        @elseif(!$groupStageCompleted)
            <div class="text-center mt-3">
                <span class="text-secondary">
                    <i class="bi bi-hourglass-split me-1"></i>
                    Завершите все матчи группового этапа для генерации плей-офф
                </span>
            </div>
        @endif
    </div>
</div>
@endif
{{-- Плей-офф --}}
@if($tournament->isTeamBased() && $tournament->playoffMatches->count() > 0)
<div class="card-dark mb-4">
    <div class="card-header-dark">
        <h5 class="mb-0"><i class="bi bi-trophy text-warning me-2"></i>Плей-офф</h5>
    </div>
    <div class="card-body-dark">
        @php
            $stages = $tournament->playoffMatches->groupBy('stage');
            $stageOrder = ['quarter' => '1/4 финала', 'semi' => 'Полуфинал', 'final' => 'Финал'];
        @endphp
        
        <div class="playoff-bracket">
            @foreach($stageOrder as $stageKey => $stageName)
                @if(isset($stages[$stageKey]))
                    <div class="playoff-stage">
                        <div class="stage-title">{{ $stageName }}</div>
                        <div class="stage-matches">
                            @foreach($stages[$stageKey] as $match)
                                <div class="playoff-match-card {{ $match->isCompleted() ? 'completed' : '' }}">
                                    <div class="playoff-team {{ $match->winner_id === $match->team1_id ? 'winner' : '' }}">
                                        <span class="playoff-team-name">
                                            {{ $match->team1 ? $match->team1->name : $match->team1_source }}
                                        </span>
                                        @if($match->isCompleted())
                                            <span class="playoff-score">{{ $match->team1_score }}</span>
                                        @endif
                                    </div>
                                    <div class="playoff-team {{ $match->winner_id === $match->team2_id ? 'winner' : '' }}">
                                        <span class="playoff-team-name">
                                            {{ $match->team2 ? $match->team2->name : $match->team2_source }}
                                        </span>
                                        @if($match->isCompleted())
                                            <span class="playoff-score">{{ $match->team2_score }}</span>
                                        @endif
                                    </div>
                                    <div class="playoff-actions">
                                        @if($match->status === 'in_progress' && $match->team1_id && $match->team2_id)
                                            <button class="btn-score-sm" data-bs-toggle="modal" data-bs-target="#playoffModal{{ $match->id }}">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        @elseif($match->isCompleted())
                                            <button class="btn-score-edit-sm" data-bs-toggle="modal" data-bs-target="#editPlayoffModal{{ $match->id }}">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                {{-- Модалка ввода --}}
                                @if($match->status === 'in_progress' && $match->team1_id && $match->team2_id)
                                <div class="modal fade" id="playoffModal{{ $match->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content modal-dark">
                                            <div class="modal-header border-0">
                                                <h5 class="modal-title">{{ $stageName }}</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form action="{{ route('club.team.savePlayoffScore', $match) }}" method="POST">
                                                @csrf
                                                <div class="modal-body">
                                                    <div class="score-input-grid">
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $match->team1->name }}</div>
                                                            <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required placeholder="0">
                                                        </div>
                                                        <div class="score-separator">:</div>
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $match->team2->name }}</div>
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
                                @if($match->isCompleted())
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
                                                            <div class="score-team-names">{{ $match->team1->name }}</div>
                                                            <input type="number" name="team1_score" class="form-control form-control-lg text-center" min="0" required value="{{ $match->team1_score }}">
                                                        </div>
                                                        <div class="score-separator">:</div>
                                                        <div class="score-team">
                                                            <div class="score-team-names">{{ $match->team2->name }}</div>
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

        {{-- Победитель --}}
        @php
            $finalMatch = $tournament->playoffMatches->where('stage', 'final')->first();
        @endphp
        @if($finalMatch && $finalMatch->isCompleted())
            <div class="winner-block mt-4">
                <div class="winner-trophy"><i class="bi bi-trophy-fill"></i></div>
                <div class="winner-title">Победитель турнира</div>
                <div class="winner-name">{{ $finalMatch->winner->name }}</div>
                <div class="winner-players">{{ $finalMatch->winner->full_name }}</div>
            </div>
        @endif
    </div>
</div>
@endif

















<!-- Классические матчи (для не-Американо) -->
@if(!$tournament->isAmericano())
    <div class="section-header">
        <h5><i class="bi bi-controller"></i> Матчи</h5>
        <a href="{{ route('club.matches.create', $tournament) }}" class="btn-primary-custom btn-sm">
            <i class="bi bi-plus"></i> Добавить матч
        </a>
    </div>

    <div class="matches-list mb-4">
        @php $matches = $tournament->matches()->with(['player1', 'player2', 'winner'])->get(); @endphp
        @forelse($matches as $match)
            <div class="classic-match-row">
                <div class="classic-match-player {{ $match->winner_id === $match->player1_id ? 'winner' : '' }}">
                    {{ $match->player1->full_name }}
                </div>
                <div class="classic-match-score">
                    <span>{{ $match->score }}</span>
                    <small class="text-secondary">±{{ $match->rating_change }}</small>
                </div>
                <div class="classic-match-player {{ $match->winner_id === $match->player2_id ? 'winner' : '' }}">
                    {{ $match->player2->full_name }}
                </div>
                <form action="{{ route('club.matches.destroy', [$tournament, $match]) }}" method="POST" 
                      onsubmit="return confirm('Удалить матч?')">
                    @csrf
                    @method('DELETE')
                    <button class="btn-danger-custom btn-sm"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        @empty
            <div class="empty-state">
                <i class="bi bi-controller"></i>
                <p>Матчей пока нет</p>
            </div>
        @endforelse
    </div>
@endif

<style>
/* Playoff Styles */
.playoff-bracket {
    display: flex;
    gap: 24px;
    overflow-x: auto;
    padding: 16px 0;
}

.playoff-stage {
    min-width: 220px;
}

.stage-title {
    font-weight: 600;
    color: var(--accent);
    margin-bottom: 12px;
    text-align: center;
    padding: 8px;
    background: rgba(34, 197, 94, 0.1);
    border-radius: 6px;
}

.stage-matches {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.playoff-match-card {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 8px;
    overflow: hidden;
}

.playoff-match-card.completed {
    border-color: var(--accent);
}

.playoff-team {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}

.playoff-team:last-of-type {
    border-bottom: none;
}

.playoff-team.winner {
    background: rgba(34, 197, 94, 0.15);
}

.playoff-team.winner .playoff-team-name {
    color: var(--accent);
    font-weight: 600;
}

.playoff-team-name {
    font-size: 0.85rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 140px;
}

.playoff-score {
    font-weight: 700;
    font-size: 1.1rem;
}

.playoff-actions {
    display: flex;
    justify-content: center;
    padding: 6px;
    background: rgba(0,0,0,0.2);
}

.winner-block {
    text-align: center;
    padding: 24px;
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(234, 179, 8, 0.1));
    border: 2px solid var(--accent);
    border-radius: 12px;
}

.winner-trophy {
    font-size: 3rem;
    color: #eab308;
    margin-bottom: 8px;
}

.winner-title {
    font-size: 0.9rem;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.winner-name {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--accent);
    margin: 8px 0;
}

.winner-players {
    color: #fff;
}
/* Group Stage Styles */
.group-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    padding: 16px;
}

.group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.table-success-custom {
    background: rgba(34, 197, 94, 0.15) !important;
}

.team-name-cell {
    font-size: 0.85rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 150px;
    display: inline-block;
}

.group-matches {
    margin-top: 12px;
}

.round-block {
    margin-bottom: 8px;
}

.round-label {
    font-size: 0.75rem;
    color: #9ca3af;
    margin-bottom: 4px;
    font-weight: 600;
}

.group-match-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    background: rgba(255,255,255,0.05);
    border-radius: 6px;
    margin-bottom: 4px;
    font-size: 0.85rem;
}

.match-team-name {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.match-team-name:first-child {
    text-align: left;
}

.match-team-name:last-child {
    text-align: right;
}

.match-team-name.winner {
    color: var(--accent);
    font-weight: 600;
}

.match-score-block {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 12px;
}

.match-score {
    font-weight: 600;
    color: #fff;
}

.btn-score-sm {
    background: var(--accent);
    border: none;
    color: #000;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 0.75rem;
    cursor: pointer;
}

.btn-score-sm:hover {
    background: #16a34a;
}

.btn-score-edit-sm {
    background: none;
    border: none;
    color: #9ca3af;
    cursor: pointer;
    padding: 2px 6px;
}

.btn-score-edit-sm:hover {
    color: var(--accent);
}
/* Team Tournament Styles */
.teams-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}

.team-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: rgba(255,255,255,0.05);
    border-radius: 8px;
    transition: all 0.2s;
}

.team-card:hover {
    background: rgba(255,255,255,0.08);
}

.team-rank {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--accent);
    color: #000;
    font-weight: 700;
    border-radius: 50%;
    flex-shrink: 0;
}

.team-info {
    flex: 1;
    min-width: 0;
}

.team-name {
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.team-players-names {
    font-size: 0.85rem;
    color: #9ca3af;
}

.team-rating {
    font-size: 0.85rem;
    color: var(--accent);
}

.btn-remove-team {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s;
}

.btn-remove-team:hover {
    background: rgba(239, 68, 68, 0.2);
}

/* Search Results */
.search-results {
    position: absolute;
    z-index: 100;
    background: #1f2937;
    border: 1px solid #374151;
    border-radius: 8px;
    max-height: 200px;
    overflow-y: auto;
    width: 100%;
    display: none;
}

.search-results.show {
    display: block;
}

.search-result-item {
    padding: 10px 12px;
    cursor: pointer;
    border-bottom: 1px solid #374151;
}

.search-result-item:hover {
    background: rgba(34, 197, 94, 0.1);
}

.search-result-item:last-child {
    border-bottom: none;
}

.selected-player {
    padding: 8px 12px;
    background: rgba(34, 197, 94, 0.2);
    border: 1px solid var(--accent);
    border-radius: 8px;
    color: #fff;
}

.col-md-4 {
    position: relative;
}
.group-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    list-style: none;
    padding: 0;
    margin: 0;
}
/* Info Grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}

.info-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border);
}

.info-card.status {
    justify-content: center;
}

.info-icon {
    width: 42px;
    height: 42px;
    background: linear-gradient(135deg, var(--accent), #16a34a);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.info-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-bottom: 2px;
}

.info-value {
    font-weight: 600;
}

/* Section Headers */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.section-header h5 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-subheader {
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Participants List */
.participants-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.participant-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border);
    transition: all 0.2s;
}

.participant-row:hover {
    border-color: var(--accent);
    transform: translateX(4px);
}

.participant-rank {
    width: 28px;
    text-align: center;
    font-weight: 600;
    color: var(--text-secondary);
}

.participant-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--accent), #16a34a);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.85rem;
    flex-shrink: 0;
}

.participant-info {
    flex: 1;
    min-width: 0;
}

.participant-name {
    font-weight: 600;
    margin-bottom: 2px;
}

.participant-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
}

.level-badge {
    background: rgba(34, 197, 94, 0.15);
    color: var(--accent);
    padding: 2px 8px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.75rem;
}

.participant-rating {
    font-weight: 700;
    color: var(--accent);
    font-size: 1.1rem;
}

/* Group Tabs */
.group-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.group-tab {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text-secondary);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.group-tab:hover {
    border-color: var(--accent);
    color: var(--text-primary);
}

.group-tab.active {
    background: var(--accent);
    border-color: var(--accent);
    color: #000;
}

.group-tab-count {
    background: rgba(0,0,0,0.2);
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 0.8rem;
}

.group-tab.active .group-tab-count {
    background: rgba(0,0,0,0.3);
}

/* Leaderboard */
.leaderboard-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.leaderboard-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border);
    transition: all 0.2s;
}

.leaderboard-row:hover {
    transform: translateX(4px);
    border-color: var(--accent);
}

.leaderboard-row.gold {
    background: linear-gradient(90deg, rgba(234, 179, 8, 0.15) 0%, var(--bg-card) 50%);
    border-color: rgba(234, 179, 8, 0.3);
}

.leaderboard-row.silver {
    background: linear-gradient(90deg, rgba(156, 163, 175, 0.15) 0%, var(--bg-card) 50%);
    border-color: rgba(156, 163, 175, 0.3);
}

.leaderboard-row.bronze {
    background: linear-gradient(90deg, rgba(180, 83, 9, 0.15) 0%, var(--bg-card) 50%);
    border-color: rgba(180, 83, 9, 0.3);
}

.leaderboard-rank {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-weight: 700;
    background: var(--bg-secondary);
    color: var(--text-secondary);
}

.leaderboard-rank.gold { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #000; }
.leaderboard-rank.silver { background: linear-gradient(135deg, #d1d5db, #9ca3af); color: #000; }
.leaderboard-rank.bronze { background: linear-gradient(135deg, #d97706, #b45309); color: #fff; }

.leaderboard-avatar {
    width: 38px;
    height: 38px;
    background: linear-gradient(135deg, var(--accent), #16a34a);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.8rem;
    flex-shrink: 0;
}

.leaderboard-info {
    flex: 1;
}

.leaderboard-name {
    font-weight: 600;
}

.leaderboard-rating {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

.leaderboard-points {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--accent);
    min-width: 50px;
    text-align: right;
}

/* Rounds Grid */
.rounds-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
    gap: 16px;
}

.round-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border);
    overflow: hidden;
}

.round-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: var(--bg-secondary);
}

.round-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}

.round-status {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
}

.round-status.completed { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
.round-status.active { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
.round-status.pending { background: rgba(156, 163, 175, 0.15); color: #9ca3af; }

.round-matches {
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

/* Match Card */
.match-card {
    display: flex;
    align-items: center;
    padding: 12px;
    background: var(--bg-secondary);
    border-radius: 10px;
}

.match-team {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.match-team:last-child {
    justify-content: flex-end;
    text-align: right;
}

.match-team:last-child .team-players {
    align-items: flex-end;
}

.match-team.winner .team-players {
    color: var(--accent);
}

.match-team.winner .team-score {
    color: var(--accent);
}

.team-players {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.player-line {
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.player-level {
    background: rgba(34, 197, 94, 0.15);
    color: var(--accent);
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 600;
    flex-shrink: 0;
}

.team-score {
    font-size: 1.3rem;
    font-weight: 700;
    min-width: 28px;
    text-align: center;
    flex-shrink: 0;
}

.match-vs {
    padding: 0 12px;
    flex-shrink: 0;
}

.vs-pending {
    font-size: 0.7rem;
    color: var(--text-secondary);
    font-weight: 600;
}

.btn-score {
    background: var(--accent);
    color: #000;
    border: none;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-score:hover {
    transform: scale(1.1);
}

.btn-score-edit {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1px solid var(--border);
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-score-edit:hover {
    border-color: var(--accent);
    color: var(--accent);
}

/* Modal */
.modal-dark {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
}

.modal-dark .modal-header {
    border-bottom: 1px solid var(--border);
    padding: 16px 20px;
}

.modal-dark .modal-footer {
    border-top: 1px solid var(--border);
    padding: 16px 20px;
    gap: 8px;
}

.score-input-group {
    display: flex;
    align-items: center;
    gap: 16px;
}

.score-team {
    flex: 1;
    text-align: center;
}

.score-team-name {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 12px;
}

.score-input {
    width: 100%;
    padding: 16px;
    font-size: 2rem;
    font-weight: 700;
    text-align: center;
    background: var(--bg-secondary);
    border: 2px solid var(--border);
    border-radius: 12px;
    color: var(--text-primary);
}

.score-input:focus {
    outline: none;
    border-color: var(--accent);
}

.score-separator {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-secondary);
}

/* Classic Matches */
.matches-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.classic-match-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border);
}

.classic-match-player {
    flex: 1;
}

.classic-match-player.winner {
    color: var(--accent);
    font-weight: 600;
}

.classic-match-score {
    text-align: center;
    padding: 0 16px;
}

.classic-match-score span {
    font-weight: 700;
    font-size: 1.1rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 48px 20px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border);
}

.empty-state i {
    font-size: 3rem;
    color: var(--text-secondary);
    opacity: 0.5;
    margin-bottom: 12px;
}

.empty-state p {
    color: var(--text-secondary);
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .info-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .rounds-grid {
        grid-template-columns: 1fr;
    }
    
    .match-card {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
    
    .match-team {
        flex-direction: column;
        gap: 6px;
        width: 100%;
    }
    
    .match-team:last-child {
        flex-direction: column-reverse;
        justify-content: center;
        text-align: center;
    }
    
    .match-team:last-child .team-players {
        align-items: center;
    }
    
    .team-players {
        align-items: center;
    }
    
    .match-vs {
        padding: 8px 0;
    }
    
    .score-input-group {
        flex-direction: column;
    }
    
    .score-separator {
        transform: rotate(90deg);
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form[data-ajax-score]').forEach(form => {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const url = this.action;
            const matchId = this.dataset.matchId;
            const groupId = this.dataset.groupId;
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Сохранение...';
            submitBtn.disabled = true;
            
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    updateMatchCard(matchId, data.match);
                    updateLeaderboard(groupId, data.leaderboard);
                    
                    if (data.round) {
                        updateRoundStatus(data.round);
                    }
                    
                    // Активируем следующий раунд если текущий завершён
                    // if (data.nextRound) {
						//     activateNextRound(data.nextRound);
						// }
                    
                    const modal = bootstrap.Modal.getInstance(this.closest('.modal'));
                    if (modal) modal.hide();
                    
                    showToast(data.message, 'success');
					
					// Перезагружаем страницу если раунд завершён (для появления кнопки)
					if (data.round && data.round.status === 'completed') {
						setTimeout(() => {
							window.location.reload();
						}, 1000);
					}
                } else {
                    showToast('Ошибка сохранения', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Ошибка соединения', 'error');
            } finally {
                submitBtn.innerHTML = originalBtnText;
                submitBtn.disabled = false;
            }
        });
    });
});

function updateMatchCard(matchId, matchData) {
    const matchCard = document.querySelector(`[data-match-id="${matchId}"]`);
    if (!matchCard) return;
    
    const team1 = matchCard.querySelector('.match-team:first-child');
    const team2 = matchCard.querySelector('.match-team:last-child');
    const vsBlock = matchCard.querySelector('.match-vs');
    
    team1.classList.remove('winner');
    team2.classList.remove('winner');
    if (matchData.winning_team === 1) team1.classList.add('winner');
    if (matchData.winning_team === 2) team2.classList.add('winner');
    
    let score1 = team1.querySelector('.team-score');
    let score2 = team2.querySelector('.team-score');
    
    if (!score1) {
        score1 = document.createElement('div');
        score1.className = 'team-score';
        team1.appendChild(score1);
    }
    if (!score2) {
        score2 = document.createElement('div');
        score2.className = 'team-score';
        team2.insertBefore(score2, team2.firstChild);
    }
    
    score1.textContent = matchData.team1_score;
    score2.textContent = matchData.team2_score;
    score1.className = 'team-score' + (matchData.winning_team === 1 ? ' text-success' : '');
    score2.className = 'team-score' + (matchData.winning_team === 2 ? ' text-success' : '');
    
    vsBlock.innerHTML = `
        <button class="btn-score-edit" 
                data-bs-toggle="modal" 
                data-bs-target="#editScoreModal${matchId}"
                title="Редактировать счёт">
            <i class="bi bi-pencil"></i>
        </button>
    `;
}

function updateLeaderboard(groupId, leaderboard) {
    // Для Американо (с группами)
    const groupLeaderboard = document.querySelector(`#group${groupId} .leaderboard-list`);
    if (groupLeaderboard) {
        groupLeaderboard.innerHTML = leaderboard.map((player, index) => {
            const rankClass = index === 0 ? 'gold' : (index === 1 ? 'silver' : (index === 2 ? 'bronze' : ''));
            return `
                <div class="leaderboard-row ${rankClass}">
                    <div class="leaderboard-rank ${rankClass}">${index + 1}</div>
                    <div class="leaderboard-avatar">${player.initials}</div>
                    <div class="leaderboard-info">
                        <div class="leaderboard-name">${player.name}</div>
                        <div class="leaderboard-rating">Рейтинг: ${player.rating}</div>
                    </div>
                    <div class="leaderboard-points">${player.points}</div>
                </div>
            `;
        }).join('');
    }
    
    // Для Мексикано (без групп)
    const mexicanoLeaderboard = document.querySelector('#mexicanoLeaderboard');
    if (mexicanoLeaderboard) {
        mexicanoLeaderboard.innerHTML = leaderboard.map((player, index) => {
            const rankClass = index === 0 ? 'gold' : (index === 1 ? 'silver' : (index === 2 ? 'bronze' : ''));
            return `
                <div class="leaderboard-row ${rankClass}">
                    <div class="leaderboard-rank ${rankClass}">${index + 1}</div>
                    <div class="leaderboard-avatar">${player.initials}</div>
                    <div class="leaderboard-info">
                        <div class="leaderboard-name">${player.name}</div>
                        <div class="leaderboard-rating">Рейтинг: ${player.rating}</div>
                    </div>
                    <div class="leaderboard-points">${player.points}</div>
                </div>
            `;
        }).join('');
    }
}

function updateRoundStatus(roundData) {
    const roundCard = document.querySelector(`[data-round-id="${roundData.id}"]`);
    if (!roundCard) return;
    
    const statusBadge = roundCard.querySelector('.round-status');
    if (statusBadge && roundData.status === 'completed') {
        statusBadge.className = 'round-status completed';
        statusBadge.textContent = 'Завершён';
        
        const icon = roundCard.querySelector('.round-title i');
        if (icon) {
            icon.className = 'bi bi-check-circle-fill text-success';
        }
    }
}

function activateNextRound(nextRoundData) {
    const roundCard = document.querySelector(`[data-round-id="${nextRoundData.id}"]`);
    if (!roundCard) return;
    
    // Обновляем статус раунда
    const statusBadge = roundCard.querySelector('.round-status');
    if (statusBadge) {
        statusBadge.className = 'round-status active';
        statusBadge.textContent = 'Идёт';
    }
    
    const icon = roundCard.querySelector('.round-title i');
    if (icon) {
        icon.className = 'bi bi-play-circle-fill text-primary';
    }
    
    // Активируем кнопки "Счёт" для всех матчей этого раунда
    nextRoundData.matches.forEach(match => {
        const matchCard = document.querySelector(`[data-match-id="${match.id}"]`);
        if (!matchCard) return;
        
        const vsBlock = matchCard.querySelector('.match-vs');
        if (vsBlock && match.status === 'pending') {
            vsBlock.innerHTML = `
                <button class="btn-score" 
                        data-bs-toggle="modal" 
                        data-bs-target="#scoreModal${match.id}">
                    <i class="bi bi-pencil-square"></i>
                </button>
            `;
        }
    });
    
    showToast('Раунд ' + nextRoundData.round_number + ' активирован!', 'success');
}

function showToast(message, type = 'success') {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '1100';
        document.body.appendChild(container);
    }
    
    const toastId = 'toast-' + Date.now();
    const bgColor = type === 'success' ? 'var(--accent)' : '#ef4444';
    
    container.innerHTML += `
        <div id="${toastId}" class="toast align-items-center border-0" role="alert" 
             style="background: ${bgColor}; color: #000;">
            <div class="d-flex">
                <div class="toast-body fw-medium">
                    <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-dark me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;
    
    const toastEl = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
    toast.show();
    
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}
// Автозаполнение счёта для Мексикано
document.querySelectorAll('form[data-mexicano="true"]').forEach(form => {
    const inputs = form.querySelectorAll('input[type="number"]');
    if (inputs.length === 2) {
        const pointsTotal = {{ $tournament->points_to_win ?? 32 }};
        
        inputs[0].addEventListener('input', function() {
            const val = parseInt(this.value) || 0;
            if (val >= 0 && val <= pointsTotal) {
                inputs[1].value = pointsTotal - val;
            }
        });
        
        inputs[1].addEventListener('input', function() {
            const val = parseInt(this.value) || 0;
            if (val >= 0 && val <= pointsTotal) {
                inputs[0].value = pointsTotal - val;
            }
        });
    }
});
// Поиск игроков для групповых турниров
function setupPlayerSearch(inputId, resultsId, selectedId, hiddenId) {
    const input = document.getElementById(inputId);
    const results = document.getElementById(resultsId);
    const selected = document.getElementById(selectedId);
    const hidden = document.getElementById(hiddenId);

    if (!input) return;

    let timeout;
    input.addEventListener('input', function() {
        clearTimeout(timeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            results.classList.remove('show');
            return;
        }

        timeout = setTimeout(async () => {
            try {
                const response = await fetch(`{{ route('club.tournaments.searchPlayer') }}?q=${encodeURIComponent(query)}`);
                const players = await response.json();
                
                if (players.length > 0) {
                    results.innerHTML = players.map(p => `
                        <div class="search-result-item" data-id="${p.id}" data-name="${p.first_name} ${p.last_name}">
                            <div><strong>${p.first_name} ${p.last_name}</strong></div>
                            <div style="font-size: 0.8rem; color: #9ca3af;">
                                ${p.email} | Рейтинг: ${p.rating} | Уровень: ${p.level}
                            </div>
                        </div>
                    `).join('');
                    results.classList.add('show');
                    
                    results.querySelectorAll('.search-result-item').forEach(item => {
                        item.addEventListener('click', function() {
                            const id = this.dataset.id;
                            const name = this.dataset.name;
                            
                            hidden.value = id;
                            selected.innerHTML = `<i class="bi bi-check-circle me-2"></i>${name}`;
                            selected.style.display = 'block';
                            input.style.display = 'none';
                            results.classList.remove('show');
                        });
                    });
                } else {
                    results.innerHTML = '<div class="search-result-item">Ничего не найдено</div>';
                    results.classList.add('show');
                }
            } catch (error) {
                console.error('Search error:', error);
            }
        }, 300);
    });

    // Сброс при клике на выбранного
    selected.addEventListener('click', function() {
        hidden.value = '';
        selected.style.display = 'none';
        input.style.display = 'block';
        input.value = '';
        input.focus();
    });

    // Закрыть при клике вне
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !results.contains(e.target)) {
            results.classList.remove('show');
        }
    });
}

setupPlayerSearch('searchPlayer1', 'player1Results', 'player1Selected', 'player1_id');
setupPlayerSearch('searchPlayer2', 'player2Results', 'player2Selected', 'player2_id');
</script>
@endsection
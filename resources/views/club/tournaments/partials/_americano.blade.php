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

			@php
				// Собираем статистику по каждому игроку
				$playerStats = [];
				foreach ($group->players as $player) {
					$playerStats[$player->id] = [
						'player' => $player,
						'wins' => 0,
						'losses' => 0,
						'draws' => 0,
						'points_for' => 0,
						'points_against' => 0,
						'total_points' => $player->pivot->total_points,
					];
				}
				
				// Проходим по всем матчам группы
				foreach ($group->rounds as $round) {
					foreach ($round->matches as $match) {
						if (!$match->isCompleted()) continue;
						
						$team1Players = [$match->team1_player1_id, $match->team1_player2_id];
						$team2Players = [$match->team2_player1_id, $match->team2_player2_id];
						
						// Команда 1
						foreach ($team1Players as $pId) {
							if (isset($playerStats[$pId])) {
								$playerStats[$pId]['points_for'] += $match->team1_score;
								$playerStats[$pId]['points_against'] += $match->team2_score;
								
								if ($match->team1_score > $match->team2_score) {
									$playerStats[$pId]['wins']++;
								} elseif ($match->team1_score < $match->team2_score) {
									$playerStats[$pId]['losses']++;
								} else {
									$playerStats[$pId]['draws']++;
								}
							}
						}
						
						// Команда 2
						foreach ($team2Players as $pId) {
							if (isset($playerStats[$pId])) {
								$playerStats[$pId]['points_for'] += $match->team2_score;
								$playerStats[$pId]['points_against'] += $match->team1_score;
								
								if ($match->team2_score > $match->team1_score) {
									$playerStats[$pId]['wins']++;
								} elseif ($match->team2_score < $match->team1_score) {
									$playerStats[$pId]['losses']++;
								} else {
									$playerStats[$pId]['draws']++;
								}
							}
						}
					}
				}
				
				// Сортируем по очкам
				uasort($playerStats, fn($a, $b) => $b['total_points'] <=> $a['total_points']);
			@endphp

			<div class="leaderboard-table-wrapper mb-4">
				<table class="leaderboard-table">
					<thead>
						<tr>
							<th class="col-rank">#</th>
							<th class="col-player">Игрок</th>
							<th class="col-stat">В</th>
							<th class="col-stat">П</th>
							<th class="col-stat">З</th>
							<th class="col-stat">Пр</th>
							<th class="col-stat">%</th>
							<th class="col-points">Очки</th>
						</tr>
					</thead>
					<tbody>
						@foreach($playerStats as $index => $stats)
							@php
								$player = $stats['player'];
								$rank = $loop->iteration;
								$totalBalls = $stats['points_for'] + $stats['points_against'];
								$percentage = $totalBalls > 0 ? round(($stats['points_for'] / $totalBalls) * 100) : 0;
								$rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
							@endphp
							<tr class="{{ $rankClass }}">
								<td class="col-rank">
									<span class="rank-badge {{ $rankClass }}">{{ $rank }}</span>
								</td>
								<td class="col-player">
									<div class="player-info">
										<div class="player-avatar">
											{{ strtoupper(substr($player->first_name, 0, 1) . substr($player->last_name, 0, 1)) }}
										</div>
										<div class="player-details">
											<div class="player-name">{{ $player->full_name }}</div>
											<div class="player-rating">{{ $player->rating }}</div>
										</div>
									</div>
								</td>
								<td class="col-stat wins">{{ $stats['wins'] }}</td>
								<td class="col-stat losses">{{ $stats['losses'] }}</td>
								<td class="col-stat points-for">{{ $stats['points_for'] }}</td>
								<td class="col-stat points-against">{{ $stats['points_against'] }}</td>
								<td class="col-stat percentage">{{ $percentage }}%</td>
								<td class="col-points">{{ $stats['total_points'] }}</td>
							</tr>
						@endforeach
					</tbody>
				</table>
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
									@if($match->court_number)
										<div class="match-court-header">
											<i class="bi bi-geo-alt"></i> {{ $tournament->getCourtName($match->court_number) }}
										</div>
									@endif
									<div class="match-teams">
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


<style>
/* Таблица лидеров */
.leaderboard-table-wrapper {
    overflow-x: auto;
}

.leaderboard-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.leaderboard-table thead {
    background: rgba(255, 255, 255, 0.05);
}

.leaderboard-table th {
    padding: 12px 8px;
    text-align: center;
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border);
}

.leaderboard-table th.col-player {
    text-align: left;
}

.leaderboard-table td {
    padding: 12px 8px;
    text-align: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.leaderboard-table tr:hover {
    background: rgba(255, 255, 255, 0.03);
}

.leaderboard-table tr.gold {
    background: rgba(255, 215, 0, 0.08);
}

.leaderboard-table tr.silver {
    background: rgba(192, 192, 192, 0.08);
}

.leaderboard-table tr.bronze {
    background: rgba(205, 127, 50, 0.08);
}

.col-rank {
    width: 50px;
}

.col-player {
    text-align: left !important;
    min-width: 180px;
}

.col-stat {
    width: 50px;
}

.col-points {
    width: 70px;
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--accent);
}

.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    font-weight: 700;
    font-size: 0.85rem;
    background: rgba(255, 255, 255, 0.1);
}

.rank-badge.gold {
    background: linear-gradient(135deg, #ffd700, #ffb700);
    color: #000;
}

.rank-badge.silver {
    background: linear-gradient(135deg, #c0c0c0, #a8a8a8);
    color: #000;
}

.rank-badge.bronze {
    background: linear-gradient(135deg, #cd7f32, #a66528);
    color: #fff;
}

.player-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.player-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--accent);
    color: #000;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.75rem;
}

.player-details {
    display: flex;
    flex-direction: column;
}

.player-name {
    font-weight: 500;
}

.player-rating {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.col-stat.wins {
    color: #22c55e;
}

.col-stat.losses {
    color: #ef4444;
}

.col-stat.points-for {
    color: #22c55e;
}

.col-stat.points-against {
    color: #ef4444;
}

.col-stat.percentage {
    font-weight: 600;
    color: var(--text-primary);
}
/* Корт в матче */
.match-court {
    text-align: center;
    font-size: 0.75rem;
    color: #0dcaf0;
    background: rgba(13, 202, 240, 0.1);
    padding: 4px 12px;
    border-radius: 20px;
    margin-bottom: 8px;
    font-weight: 500;
}
/* Корт в матче - сверху по центру */
.match-court-header {
    text-align: center;
    font-size: 0.8rem;
    color: #0dcaf0;
    background: rgba(13, 202, 240, 0.1);
    padding: 6px 16px;
    border-radius: 20px;
    font-weight: 600;
    margin-bottom: 12px;
    display: inline-block;
    width: 100%;
}

.match-card {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.match-teams {
    display: flex;
    align-items: center;
    width: 100%;
    justify-content: space-between;
}
</style>
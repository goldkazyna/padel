{{-- resources/views/club/tournaments/mexicano/partials/_leaderboard.blade.php --}}
<div class="section-subheader">
    <i class="bi bi-trophy"></i> Таблица лидеров
</div>

@php
    // Собираем статистику по каждому игроку
    $playerStats = [];
    foreach ($tournament->mexicanoPlayers as $mp) {
        $playerStats[$mp->user_id] = [
            'player' => $mp->user,
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
            'points_for' => 0,
            'points_against' => 0,
            'total_points' => $mp->total_points,
        ];
    }
    
    // Проходим по всем матчам
    foreach ($tournament->mexicanoRounds as $round) {
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
    
    // Сортируем по очкам, при равенстве — по разнице мячей
	uasort($playerStats, function($a, $b) {
		// Сначала по очкам
		if ($a['total_points'] !== $b['total_points']) {
			return $b['total_points'] <=> $a['total_points'];
		}
		// При равных очках — по разнице мячей
		$diffA = $a['points_for'] - $a['points_against'];
		$diffB = $b['points_for'] - $b['points_against'];
		if ($diffA !== $diffB) {
			return $diffB <=> $diffA;
		}
		// При равной разнице — по проценту
		$totalA = $a['points_for'] + $a['points_against'];
		$totalB = $b['points_for'] + $b['points_against'];
		$pctA = $totalA > 0 ? $a['points_for'] / $totalA : 0;
		$pctB = $totalB > 0 ? $b['points_for'] / $totalB : 0;
		return $pctB <=> $pctA;
	});
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
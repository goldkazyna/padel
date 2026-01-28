{{-- Команды на модерации --}}
@php
    $pendingTeams = $tournament->teams()->where('status', 'pending')->orderBy('created_at', 'asc')->get();
    $approvedTeams = $tournament->teams()->where('status', 'approved')->orderBy('rating_avg', 'desc')->get();
@endphp

@if($tournament->status === 'open' && $pendingTeams->count() > 0)
<section class="pending-teams mb-4">
    <div class="d-flex justify-content-between align-items-center" style="margin-bottom: 16px;">
        <h2 class="section-title">
            <i class="bi bi-clock text-warning me-2"></i>Заявки на модерации
        </h2>
        <span class="badge bg-warning text-dark">{{ $pendingTeams->count() }} пар</span>
    </div>
    
    <div class="pairs-grid">
        @foreach($pendingTeams as $team)
			<div class="pair-card pending-card">
				<span class="pair-rank pending-rank"><i class="bi bi-clock"></i></span>
                <div class="pair-info-block">
                    <div class="pair-names">{{ $team->player1->name }} / {{ $team->player2->name }}</div>
                    <div class="pair-phones">
                        <small class="text-muted">
                            {{ $team->player1->phone ? '+' . preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $team->player1->phone) : '—' }}
                            / 
                            {{ $team->player2->phone ? '+' . preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $team->player2->phone) : '—' }}
                        </small>
                    </div>
                </div>
                <div class="pair-rating">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    {{ $team->rating_avg }}
                </div>
                <div class="pair-actions">
                    <form action="{{ route('club.tournaments.approveTeam', [$tournament, $team]) }}" method="POST" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-success btn-sm" title="Одобрить">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    </form>
                    <form action="{{ route('club.tournaments.rejectTeam', [$tournament, $team]) }}" method="POST" class="d-inline" onsubmit="return confirm('Отклонить заявку?')">
                        @csrf
                        <button type="submit" class="btn btn-danger btn-sm" title="Отклонить">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
</section>
@endif

{{-- Зарегистрированные пары --}}
<section class="registered-pairs mb-4">
    <div class="d-flex justify-content-between align-items-center" style="margin-bottom: 16px;">
        <h2 class="section-title">Зарегистрированные пары</h2>
        <span class="badge bg-primary">{{ $approvedTeams->count() }} / {{ $tournament->max_participants / 2 }} пар</span>
    </div>
    
    @if($tournament->status === 'open')
        @include('club.tournaments.partials._team_form')
    @endif
    
    @if($approvedTeams->count() > 0)
        <div class="pairs-grid">
            @foreach($approvedTeams as $index => $team)
                <div class="pair-card">
                    <span class="pair-rank">{{ $index + 1 }}</span>
                    <div class="pair-info-block">
                        <div class="pair-names">{{ $team->player1->name }} / {{ $team->player2->name }}</div>
                        <div class="pair-phones">
                            <small class="text-muted">
                                {{ $team->player1->phone ? '+' . preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $team->player1->phone) : '—' }}
                                / 
                                {{ $team->player2->phone ? '+' . preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $team->player2->phone) : '—' }}
                            </small>
                        </div>
                    </div>
                    <div class="pair-rating">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        {{ $team->rating_avg }}
                    </div>
                    @if($tournament->status === 'open')
						<div class="pair-actions">
							<form action="{{ route('club.tournaments.removeTeam', [$tournament, $team]) }}" method="POST" onsubmit="return confirm('Удалить пару?')" class="d-inline">
								@csrf
								@method('DELETE')
								<button type="submit" class="btn btn-danger btn-sm" title="Удалить">
									<i class="bi bi-x-lg"></i>
								</button>
							</form>
						</div>
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
</section>
<style>
        :root {
            --bg-primary: #0a0a0b;
            --bg-secondary: #111113;
            --bg-card: #18181b;
            --bg-hover: #1f1f23;
            --accent: #22c55e;
            --accent-dim: #16a34a;
            --accent-glow: rgba(34, 197, 94, 0.15);
            --text-primary: #f4f4f5;
            --text-secondary: #a1a1aa;
            --text-muted: #71717a;
            --border: #27272a;
            --border-light: #3f3f46;
            --yellow: #facc15;
            --red: #ef4444;
            --red-dim: rgba(239, 68, 68, 0.15);
            --green: #22c55e;
            --green-dim: rgba(34, 197, 94, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Manrope', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            width: 100%;
            padding: 32px 40px;
        }

        /* Header */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .badge {
            background: var(--accent);
            color: var(--bg-primary);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
        }

        .participants-count {
            color: var(--text-secondary);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .participants-count::before {
            content: '';
            width: 8px;
            height: 8px;
            background: var(--accent);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Registered Pairs */
        .registered-pairs {
            margin-bottom: 40px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 16px;
        }

        .pairs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 12px;
        }

        .pair-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .pair-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--accent);
            opacity: 0;
            transition: opacity 0.2s;
        }

        .pair-card:hover {
            background: var(--bg-hover);
            border-color: var(--border-light);
        }

        .pair-card:hover::before {
            opacity: 1;
        }

        .pair-rank {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 24px;
            height: 24px;
            background: var(--accent);
            color: var(--bg-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
        }

        .pair-names {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 8px;
            padding-right: 32px;
        }

        .pair-rating {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--yellow);
            font-size: 14px;
            font-weight: 600;
        }

        .pair-rating svg {
            width: 14px;
            height: 14px;
        }

        /* Groups Container */
        .groups-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 32px;
            margin-bottom: 40px;
        }

        @media (max-width: 1200px) {
            .groups-container {
                grid-template-columns: 1fr;
            }
        }

        /* Group Card */
        .group-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
        }

        .group-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .group-letter {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--accent), var(--accent-dim));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 800;
            color: var(--bg-primary);
        }

        .group-name {
            font-size: 18px;
            font-weight: 700;
        }

        .status-badge {
            background: var(--accent-glow);
            color: var(--accent);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid var(--accent);
        }

        /* Table Styles */
        .standings-table {
            width: 100%;
            border-collapse: collapse;
        }

        .standings-table th {
            padding: 16px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
        }

        .standings-table th:first-child {
            padding-left: 24px;
        }

        .standings-table th.stat {
            text-align: center;
            width: 50px;
        }

        .standings-table td {
            padding: 18px 16px;
            border-bottom: 1px solid var(--border);
            font-size: 15px;
        }

        .standings-table td:first-child {
            padding-left: 24px;
        }

        .standings-table tr:last-child td {
            border-bottom: none;
        }

        .standings-table tr {
            transition: background 0.15s;
        }

        .standings-table tbody tr:hover {
            background: var(--bg-hover);
        }

        .rank-cell {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .rank-number {
            width: 28px;
            height: 28px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-secondary);
        }

        .rank-number.first {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            border: none;
            color: var(--bg-primary);
        }

        .rank-number.second {
            background: linear-gradient(135deg, #94a3b8, #64748b);
            border: none;
            color: var(--bg-primary);
        }

        .rank-number.third {
            background: linear-gradient(135deg, #d97706, #b45309);
            border: none;
            color: var(--bg-primary);
        }

        .team-name {
            font-weight: 600;
            font-size: 15px;
            color: var(--text-primary);
        }

        .stat-cell {
            text-align: center;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            font-size: 15px;
        }

        .stat-cell.points {
            color: var(--accent);
            font-size: 17px;
            font-weight: 700;
        }

        .stat-cell.wins {
            color: var(--green);
            font-weight: 700;
        }

        .stat-cell.losses {
            color: var(--red);
            font-weight: 700;
        }

        .stat-cell.diff {
            color: var(--text-muted);
        }

        .stat-cell.diff.positive {
            color: var(--green);
        }

        .stat-cell.diff.negative {
            color: var(--red);
        }

        /* Matches Section */
        .matches-section {
            padding: 20px 24px;
            border-top: 1px solid var(--border);
        }

        .matches-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .matches-title svg {
            width: 18px;
            height: 18px;
            color: var(--accent);
        }

        .round {
            margin-bottom: 24px;
        }

        .round:last-child {
            margin-bottom: 0;
        }

        .round-label {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-secondary);
            margin-bottom: 12px;
            padding-left: 4px;
        }

        /* Match Card - новый дизайн */
        .match-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 10px;
            overflow: hidden;
            transition: all 0.2s;
        }

        .match-card:last-child {
            margin-bottom: 0;
        }

        .match-card:hover {
            border-color: var(--border-light);
        }

        .match-court-header {
            background: var(--bg-hover);
            padding: 10px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .court-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .court-icon {
            width: 20px;
            height: 20px;
            color: var(--accent);
        }

        .court-name {
            font-size: 14px;
            font-weight: 700;
            color: var(--accent);
        }

        .match-status {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .match-status.live {
            color: var(--yellow);
        }

        .match-status.finished {
            color: var(--text-secondary);
        }

        .match-content {
            padding: 16px 20px;
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 20px;
        }

        .match-team {
            font-size: 15px;
            font-weight: 600;
        }

        .match-team.left {
            text-align: right;
        }

        .match-team.right {
            text-align: left;
        }

        .match-team.winner {
            color: var(--green);
        }

        .match-team.loser {
            color: var(--text-muted);
        }

        .match-score {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 100px;
            justify-content: center;
        }

        .score-left, .score-right {
            font-size: 22px;
            font-weight: 800;
            min-width: 32px;
            text-align: center;
        }

        .score-left.winner, .score-right.winner {
            color: var(--green);
        }

        .score-left.loser, .score-right.loser {
            color: var(--red);
        }

        .score-separator {
            font-size: 18px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .score-btn {
            background: var(--accent);
            color: var(--bg-primary);
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }

        .score-btn:hover {
            background: var(--accent-dim);
            transform: translateY(-1px);
        }

        .vs-badge {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            padding: 6px 16px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 20px 16px;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .pairs-grid {
                grid-template-columns: 1fr 1fr;
            }

            .match-content {
                grid-template-columns: 1fr;
                gap: 12px;
                text-align: center;
            }

            .match-team.left,
            .match-team.right {
                text-align: center;
            }
        }

        @media (min-width: 1800px) {
            .container {
                padding: 40px 60px;
            }

            .pair-names {
                font-size: 16px;
            }

            .team-name {
                font-size: 16px;
            }

            .match-team {
                font-size: 16px;
            }
        }
		
		/* ===== КАРТОЧКА МАТЧА - ПОЛНЫЙ СБРОС ===== */
.match-card {
    background: var(--bg-card, #18181b) !important;
    border: 1px solid var(--border, #27272a) !important;
    border-radius: 12px !important;
    margin-bottom: 10px !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
    padding: 0 !important;
}

/* Заголовок с кортом - СВЕРХУ НА ВСЮ ШИРИНУ */
.match-court-header {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 10px 16px !important;
    background: var(--bg-hover, #1f1f23) !important;
    border-bottom: 1px solid var(--border, #27272a) !important;
    width: 100% !important;
    border-radius: 0 !important;
    margin: 0 !important;
}

.court-info {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}

.court-icon {
    width: 18px !important;
    height: 18px !important;
    color: #22c55e !important;
}

.court-name {
    font-size: 14px !important;
    font-weight: 700 !important;
    color: #22c55e !important;
}

.match-status {
    font-size: 12px !important;
    font-weight: 600 !important;
    color: #71717a !important;
}

.match-status.live {
    color: #facc15 !important;
}

.match-status.finished {
    color: #a1a1aa !important;
}

/* Контент матча - ПОД КОРТОМ */
.match-content {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 16px 20px !important;
    gap: 20px !important;
    width: 100% !important;
}

.match-team {
    flex: 1 !important;
    font-size: 15px !important;
    font-weight: 600 !important;
}

.match-team.left {
    text-align: right !important;
}

.match-team.right {
    text-align: left !important;
}

.match-team.winner {
    color: #22c55e !important;
}

.match-team.loser {
    color: #71717a !important;
}

/* Счёт */
.match-score {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    min-width: 120px !important;
    flex-shrink: 0 !important;
}

.score-left, .score-right {
    font-size: 22px !important;
    font-weight: 800 !important;
    min-width: 32px !important;
    text-align: center !important;
}

.score-left.winner, .score-right.winner {
    color: #22c55e !important;
}

.score-left.loser, .score-right.loser {
    color: #ef4444 !important;
}

.score-separator {
    font-size: 18px !important;
    color: #71717a !important;
    font-weight: 600 !important;
}

.score-btn {
    background: #22c55e !important;
    color: #000 !important;
    border: none !important;
    padding: 8px 20px !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
}

.btn-score-edit-sm {
    background: transparent !important;
    border: none !important;
    color: #71717a !important;
    cursor: pointer !important;
    padding: 4px !important;
    margin-left: 8px !important;
}

.vs-badge {
    background: #27272a !important;
    border: 1px solid #3f3f46 !important;
    padding: 6px 16px !important;
    border-radius: 6px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #71717a !important;
}

.pending-card {
    border: 1px solid rgba(234, 179, 8, 0.2);
    background: rgba(234, 179, 8, 0.05);
}

.pair-info-block {
    flex: 1;
}

.pair-phones {
    margin-top: 4px;
}

.pair-actions {
    display: flex;
    gap: 4px;
}
.pending-rank {
    background: transparent;
    color: #eab308;
    font-size: 1.2rem;
}
    </style>
{{-- Групповой этап --}}
@if($tournament->teamGroups->count() > 0)
    @include('club.tournaments.partials._team_groups')
@endif

{{-- Плей-офф --}}
@if($tournament->playoffMatches->count() > 0)
    @include('club.tournaments.partials._team_playoff')
@endif
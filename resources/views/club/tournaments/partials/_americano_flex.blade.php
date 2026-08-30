@php
    $flex = app(\App\Services\AmericanoFlexService::class);
    $rounds = $tournament->americanoFlexRounds()->orderBy('round_number')->get();
    $currentRound = $flex->getCurrentRound($tournament);
    // Порядок: среднее → % побед → личная встреча → рейтинг (AmericanoFlexRanking).
    $leaderboard = $flex->getLeaderboard($tournament); // Collection<AmericanoFlexPlayer>
    // Числа таблицы — оттуда же: хранимые в модели счётчики считают и 0:0,
    // которым отмечают несыгранный матч, и среднее из них врёт.
    $flexStats = $flex->getLeaderboardStats($tournament);
    $allMatchesCompleted = $currentRound ? $flex->isRoundCompleted($currentRound) : false;
    $matchCounts = collect($flexStats)->pluck('matches');
    $allPlayersEqual = $matchCounts->isNotEmpty()
        && $matchCounts->min() === $matchCounts->max();

    $registeredCount = \App\Models\TournamentParticipant::where('tournament_id', $tournament->id)
        ->where('status', 'registered')
        ->count();
    $minRequired = max(4, ($tournament->courts_count ?? 1) * 4);

    // Парный флекс: пары + лидерборд по парам.
    $isPaired = $tournament->isPairedFlex();
    $pairedLeaderboard = $isPaired ? $flex->getPairedLeaderboard($tournament) : [];
    $approvedTeams = $isPaired
        ? $tournament->teams()->where('status', 'approved')->orderBy('rating_avg', 'desc')->get()
        : collect();

    $canManage = auth()->user()->isSuperAdmin()
        || (method_exists(auth()->user(), 'hasTournamentsFullAccess')
            ? auth()->user()->hasTournamentsFullAccess($tournament->club)
            : true);


@endphp

<div class="section-header flex-section-header">
    <h5><i class="bi bi-arrow-repeat"></i> Americano Flex</h5>

    @if($canManage)
        <div class="flex-actions">
            @if($tournament->status === 'open' || $tournament->status === 'closed')
                @if($registeredCount >= $minRequired)
                    <form action="{{ route('club.tournaments.flex.start', $tournament) }}" method="POST"
                          onsubmit="return confirm('Запустить турнир? Будет сгенерирован первый раунд.')">
                        @csrf
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-play-fill"></i> Запустить турнир
                        </button>
                    </form>
                @else
                    <span class="btn-outline-custom disabled"
                          title="Минимум {{ $minRequired }} игроков для {{ $tournament->courts_count ?? 1 }} корта/кортов">
                        <i class="bi bi-exclamation-triangle"></i>
                        Игроков: {{ $registeredCount }} / нужно ≥ {{ $minRequired }}
                    </span>
                @endif
            @endif

            @if($tournament->status === 'in_progress' && $allMatchesCompleted)
                <form action="{{ route('club.tournaments.flex.nextRound', $tournament) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn-primary-custom">
                        <i class="bi bi-plus-circle"></i> Следующий раунд
                    </button>
                </form>
                <form action="{{ route('club.tournaments.flex.complete', $tournament) }}" method="POST"
                      onsubmit="return confirm('Завершить турнир? Рейтинги игроков обновятся.')">
                    @csrf
                    <button type="submit" class="btn-danger-custom">
                        <i class="bi bi-trophy-fill"></i> Завершить турнир
                    </button>
                </form>
            @endif
        </div>
    @endif
</div>

{{-- Парный флекс: сбор пар до старта — клик по игроку, клик по партнёру. --}}
@if($isPaired && $canManage && in_array($tournament->status, ['open', 'closed']))
    @include('club.tournaments.partials._pair_builder')
@endif

{{-- Сессионные сообщения --}}
@if(session('success'))
    <div class="alert-success-custom mb-3">
        <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="alert-danger-custom mb-3">
        <i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}
    </div>
@endif

@if($rounds->isEmpty())
    {{-- Турнир ещё не запущен --}}
    <div class="flex-empty">
        <i class="bi bi-hourglass"></i>
        <p>Турнир ещё не запущен. Зарегистрируйте игроков и нажмите «Запустить турнир».</p>
        <p class="flex-empty-count">
            Зарегистрировано игроков: <strong>{{ $registeredCount }}</strong>
            @if($registeredCount < $minRequired)
                <span class="text-warning">(нужно ≥ {{ $minRequired }})</span>
            @endif
        </p>
    </div>
@else

    {{-- Подсказка про равенство --}}
    @if($allPlayersEqual && $leaderboard->count() > 0 && $matchCounts->max() > 0)
        <div class="alert-info-custom mb-4">
            <i class="bi bi-info-circle me-2"></i>
            Каждый игрок сыграл по <strong>{{ $matchCounts->max() }}</strong> матчей —
            все на равных. Хороший момент завершить турнир.
        </div>
    @endif

    {{-- Таблица лидеров --}}
    <div class="section-subheader">
        <i class="bi bi-trophy"></i> Таблица лидеров
    </div>

    @if($isPaired)
        <div class="leaderboard-table-wrapper mb-4">
            <table class="leaderboard-table">
                <thead>
                    <tr>
                        <th class="col-rank">#</th>
                        <th class="col-player">Пара</th>
                        <th class="col-stat" title="Забито очков">Забито</th>
                        <th class="col-stat" title="Пропущено очков">Пропущено</th>
                        <th class="col-stat" title="Разница забито − пропущено">Разница</th>
                        <th class="col-stat">Матчей</th>
                        <th class="col-stat" title="Сколько раундов отдыхала пара">Отдых</th>
                        <th class="col-stat" title="Процент побед — второй критерий при равном среднем">% побед</th>
                        <th class="col-points" title="Среднее забитых очков за матч. Первый критерий таблицы">Среднее</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pairedLeaderboard as $i => $row)
                        @php
                            $rank = $i + 1;
                            $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                            // Агрегаты пары уже в строке: партнёры играют вместе.
                            $forPts = $row['points_for'];
                            $againstPts = $row['points_against'];
                            $diff = $forPts - $againstPts;
                        @endphp
                        <tr class="{{ $rankClass }}">
                            <td class="col-rank"><span class="rank-badge {{ $rankClass }}">{{ $rank }}</span></td>
                            <td class="col-player">
                                <div class="player-details">
                                    <div class="player-name">{{ $row['player1']->name ?? '—' }} <span style="color:#71717a;">+</span> {{ $row['player2']->name ?? '—' }}</div>
                                    @if($row['bye_streak'] > 0)
                                        <div class="player-rating">
                                            <span class="bye-streak-badge" title="Отдыхает подряд раундов"><i class="bi bi-moon"></i> {{ $row['bye_streak'] }}</span>
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td class="col-stat points-for">{{ $forPts }}</td>
                            <td class="col-stat points-against">{{ $againstPts }}</td>
                            <td class="col-stat {{ $diff > 0 ? 'points-for' : ($diff < 0 ? 'points-against' : '') }}">{{ $diff > 0 ? '+' : '' }}{{ $diff }}</td>
                            <td class="col-stat">{{ $row['matches_played'] }}</td>
                            <td class="col-stat">{{ $row['bye_count'] }}</td>
                            <td class="col-stat">{{ $row['win_percent'] }}%</td>
                            <td class="col-points">{{ number_format($row['avg_points'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
    <div class="leaderboard-table-wrapper mb-4">
        <table class="leaderboard-table">
            <thead>
                <tr>
                    <th class="col-rank">#</th>
                    <th class="col-player">Игрок</th>
                    <th class="col-stat" title="Забито очков">Забито</th>
                    <th class="col-stat" title="Пропущено очков">Пропущено</th>
                    <th class="col-stat" title="Разница забито − пропущено">Разница</th>
                    <th class="col-stat">Матчей</th>
                    <th class="col-stat" title="Процент побед — второй критерий при равном среднем">% побед</th>
                    <th class="col-points" title="Среднее забитых очков за матч = Забито ÷ Матчей. Первый критерий таблицы">Среднее</th>
                    <th class="col-rating">Рейтинг</th>
                </tr>
            </thead>
            <tbody>
                @foreach($leaderboard as $i => $player)
                    @php
                        $rank = $loop->iteration;
                        $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                        $u = $player->user;
                    @endphp
                    <tr class="{{ $rankClass }}">
                        <td class="col-rank">
                            <span class="rank-badge {{ $rankClass }}">{{ $rank }}</span>
                        </td>
                        <td class="col-player">
                            <div class="player-info">
                                <div class="player-avatar">
                                    {{ mb_strtoupper(mb_substr($u->first_name ?? $u->name, 0, 1) . mb_substr($u->last_name ?? '', 0, 1)) }}
                                </div>
                                <div class="player-details">
                                    <div class="player-name">{{ $u->name }}</div>
                                    <div class="player-rating">
                                        {{ $u->rating }}
                                        @if($player->bye_streak > 0)
                                            <span class="bye-streak-badge" title="Отдыхает подряд раундов">
                                                <i class="bi bi-moon"></i> {{ $player->bye_streak }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        @php
                            $st = $flexStats[$player->user_id] ?? \App\Support\AmericanoFlexRanking::row($player->user_id, []);
                            $forPts = $st['points_for'];
                            $againstPts = $st['points_against'];
                            $diff = $forPts - $againstPts;
                        @endphp
                        <td class="col-stat points-for">{{ $forPts }}</td>
                        <td class="col-stat points-against">{{ $againstPts }}</td>
                        <td class="col-stat {{ $diff > 0 ? 'points-for' : ($diff < 0 ? 'points-against' : '') }}">{{ $diff > 0 ? '+' : '' }}{{ $diff }}</td>
                        <td class="col-stat">{{ $st['matches'] }}</td>
                        <td class="col-stat">{{ $st['win_percent'] }}%</td>
                        <td class="col-points">{{ number_format($st['avg'], 2) }}</td>
                        <td class="col-rating">
                            {{ $player->rating_before ?? '—' }}
                            @if($player->rating_after !== null)
                                → {{ $player->rating_after }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Раунды --}}
    <div class="section-subheader collapsible" onclick="toggleSection('flex-rounds')">
        <div style="font-size:24px;">
            <i class="bi bi-calendar3"></i> Раунды
        </div>
        <i class="bi bi-chevron-down collapse-icon" id="icon-flex-rounds"></i>
    </div>
    <div class="collapsible-content" id="flex-rounds">
        <div class="rounds-grid">
            @foreach($rounds as $round)
                <div class="round-card {{ $round->status === 'in_progress' ? 'active' : ($round->isCompleted() ? 'completed' : 'pending') }}"
                     data-round-id="{{ $round->id }}">
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
                            @php
                                $winningTeam = null;
                                if ($match->isCompleted() && $match->team1_score !== null && $match->team2_score !== null) {
                                    $winningTeam = $match->team1_score > $match->team2_score ? 1
                                        : ($match->team2_score > $match->team1_score ? 2 : null);
                                }
                            @endphp
                            <div class="match-card" data-match-id="{{ $match->id }}">
                                @if($match->court_number)
                                    <div class="match-court-header">
                                        <i class="bi bi-geo-alt"></i> {{ $tournament->getCourtName($match->court_number) }}
                                    </div>
                                @endif
                                <div class="match-teams">
                                    <div class="match-team {{ $winningTeam === 1 ? 'winner' : '' }}">
                                        <div class="team-players">
                                            <div class="player-line">{{ $match->team1Player1->name }} <span class="player-level">{{ $match->team1Player1->level }}</span></div>
                                            <div class="player-line">{{ $match->team1Player2->name }} <span class="player-level">{{ $match->team1Player2->level }}</span></div>
                                        </div>
                                        @if($match->isCompleted())
                                            <div class="team-score">{{ $match->team1_score }}</div>
                                        @endif
                                    </div>

                                    <div class="match-vs">
                                        @if($match->isCompleted() && $tournament->status !== 'completed' && $canManage)
                                            <button class="btn-score-edit"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#flexEditScoreModal{{ $match->id }}"
                                                    title="Редактировать счёт">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        @elseif(!$match->isCompleted() && $round->status === 'in_progress' && $canManage)
                                            <button class="btn-score"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#flexScoreModal{{ $match->id }}">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                        @else
                                            <span class="vs-pending">VS</span>
                                        @endif
                                    </div>

                                    <div class="match-team {{ $winningTeam === 2 ? 'winner' : '' }}">
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

                            {{-- Модалки ввода/редактирования счёта (как в Американо, но без AJAX — контроллер делает redirect) --}}
                            @if($canManage && $tournament->status !== 'completed')
                                @if(!$match->isCompleted())
                                    @include('club.tournaments.partials._modal_score', [
                                        'match' => $match,
                                        'modalId' => 'flexScoreModal'.$match->id,
                                        'route' => 'club.tournaments.flex.matches.score',
                                        'ajax' => false,
                                    ])
                                @else
                                    @include('club.tournaments.partials._modal_edit_score', [
                                        'match' => $match,
                                        'modalId' => 'flexEditScoreModal'.$match->id,
                                        'route' => 'club.tournaments.flex.matches.updateScore',
                                    ])
                                @endif
                            @endif
                        @endforeach

                        {{-- Отдыхающие в раунде --}}
                        @if($round->byes->count() > 0)
                            <div class="flex-byes">
                                <div class="flex-byes-title">
                                    <i class="bi bi-moon-stars"></i> Отдыхают в этом раунде
                                </div>
                                <div class="flex-byes-list">
                                    @foreach($round->byes as $bye)
                                        <span class="flex-bye-badge">{{ $bye->user->name }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif


<style>
.flex-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.flex-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.flex-actions form {
    margin: 0;
}

.rounds-grid {
    display: grid;
    grid-template-columns: repeat(1, 1fr);
    gap: 16px;
}
.player-line {
    font-size: 35px;
}
.match-court-header {
    font-size: 24px;
}

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
    width: 80px;
    font-size: 24px;
}

.col-stat.points-for {
    color: #22c55e;
}

.col-stat.points-against {
    color: #ef4444;
}

.col-points {
    width: 90px;
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--accent);
}

.col-rating {
    width: 120px;
    font-size: 0.85rem;
    color: var(--text-secondary);
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
    flex-shrink: 0;
}

.player-details {
    display: flex;
    flex-direction: column;
    text-align: left;
}

.player-name {
    font-weight: 500;
    font-size: 24px;
}

.player-rating {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.bye-streak-badge {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    background: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
    border-radius: 8px;
    padding: 1px 6px;
    font-size: 0.7rem;
    margin-left: 4px;
}

/* Корт в матче - сверху по центру */
.match-court-header {
    text-align: center;
    font-size: 24px;
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

.team-score {
    font-size: 40px;
}

/* Кнопки ввода счёта в .match-vs (как в Американо) */
.score-team-name {
    font-size: 24px;
}

/* Убираем backdrop модалки (как в Американо) */
.modal-backdrop {
    display: none !important;
}
.modal {
    background: rgba(0, 0, 0, 0.5);
}

/* Отдыхающие */
.flex-byes {
    margin-top: 16px;
    padding: 14px 16px;
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    border-radius: 12px;
}
.flex-byes-title {
    font-size: 20px;
    font-weight: 600;
    color: #f59e0b;
    margin-bottom: 10px;
}
.flex-byes-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.flex-bye-badge {
    display: inline-block;
    background: rgba(255, 255, 255, 0.08);
    color: var(--text-primary, #fff);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 20px;
}

/* Пустое состояние */
.flex-empty {
    text-align: center;
    padding: 48px 16px;
    color: var(--text-secondary);
}
.flex-empty i {
    font-size: 3rem;
}
.flex-empty p {
    margin-top: 12px;
    margin-bottom: 4px;
    font-size: 1.1rem;
}
.flex-empty-count {
    font-size: 0.95rem !important;
}

/* ===== АКТИВНЫЙ РАУНД — ВЫДЕЛЕНИЕ ===== */
.round-card.active {
    border: 2px solid var(--accent);
    box-shadow: 0 0 20px rgba(34, 197, 94, 0.3);
    background: var(--bg-card);
}

.round-card.active .round-header {
    background: rgba(34, 197, 94, 0.15);
}

.round-card.active .round-title {
    color: var(--accent);
    font-size: 1.3rem;
}

/* Неактивные раунды — приглушённые */
.round-card.completed {
    opacity: 0.6;
}

.round-card.pending {
    opacity: 0.4;
}

.round-card.completed:hover,
.round-card.pending:hover {
    opacity: 1;
}

/* ===== СВОРАЧИВАЕМЫЕ СЕКЦИИ ===== */
.section-subheader.collapsible {
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    user-select: none;
    transition: all 0.3s;
}

.section-subheader.collapsible:hover {
    color: var(--accent);
}

.collapse-icon {
    transition: transform 0.3s;
}

.collapse-icon.collapsed {
    transform: rotate(-90deg);
}

.collapsible-content {
    max-height: 8000px;
    overflow: hidden;
    transition: max-height 0.3s ease-out, opacity 0.3s;
    opacity: 1;
}

.collapsible-content.collapsed {
    max-height: 0;
    opacity: 0;
}

.section-subheader div {
    font-size: 24px;
}

/* ===== АККОРДЕОН РАУНДОВ ===== */
.round-header {
    cursor: pointer;
    user-select: none;
    transition: background 0.2s;
}

.round-header:hover {
    background: rgba(255, 255, 255, 0.05);
}

.round-header .collapse-icon {
    transition: transform 0.3s ease;
    margin-left: auto;
}

.round-card.collapsed .round-header .collapse-icon {
    transform: rotate(-90deg);
}

.round-matches {
    transition: max-height 0.3s ease, opacity 0.3s ease;
    overflow: hidden;
    max-height: 4000px;
}

.round-card.collapsed .round-matches {
    max-height: 0;
    opacity: 0;
    padding: 0;
}

.round-card.collapsed {
    opacity: 0.7;
}

.round-card.collapsed:hover {
    opacity: 1;
}
</style>

<script>
function toggleSection(id) {
    const content = document.getElementById(id);
    const icon = document.getElementById('icon-' + id);

    if (content && icon) {
        content.classList.toggle('collapsed');
        icon.classList.toggle('collapsed');
        localStorage.setItem('section-' + id, content.classList.contains('collapsed') ? 'collapsed' : 'open');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.collapsible-content').forEach(function(el) {
        const saved = localStorage.getItem('section-' + el.id);
        const icon = document.getElementById('icon-' + el.id);

        if (saved === 'collapsed') {
            el.classList.add('collapsed');
            if (icon) icon.classList.add('collapsed');
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.round-card').forEach(card => {
        const header = card.querySelector('.round-header');
        if (!header) return;
        const isActive = card.classList.contains('active');

        if (!header.querySelector('.collapse-icon')) {
            header.insertAdjacentHTML('beforeend', '<i class="bi bi-chevron-down collapse-icon"></i>');
        }

        // Сворачиваем все кроме активного
        if (!isActive) {
            card.classList.add('collapsed');
        }

        // Клик по шапке сворачивает/разворачивает (но не по форме счёта внутри)
        header.addEventListener('click', () => card.classList.toggle('collapsed'));
    });
});
</script>

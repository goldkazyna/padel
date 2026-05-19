@php
    $flex = app(\App\Services\AmericanoFlexService::class);
    $currentRound = $flex->getCurrentRound($tournament);
    $leaderboard = $flex->getLeaderboard($tournament);
    $allMatchesCompleted = $currentRound ? $flex->isRoundCompleted($currentRound) : false;
    $allPlayersEqual = $leaderboard->count() > 0
        && $leaderboard->min('matches_played') === $leaderboard->max('matches_played');
    $registeredCount = \App\Models\TournamentParticipant::where('tournament_id', $tournament->id)
        ->where('status', 'registered')
        ->count();
    $minRequired = max(4, ($tournament->courts_count ?? 1) * 4);
@endphp

<div class="card-dark mb-4">
    <div class="card-header-dark d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">
            <i class="bi bi-arrow-repeat text-info me-2"></i>Americano Flex
        </h5>
        <div class="d-flex gap-2 flex-wrap">
            @if(auth()->user()->isSuperAdmin() || auth()->user()->hasTournamentsFullAccess($tournament->club))
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
                        <span class="btn-outline-custom disabled" title="Минимум {{ $minRequired }} игроков для {{ $tournament->courts_count ?? 1 }} корта/кортов">
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
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-trophy-fill"></i> Завершить турнир
                        </button>
                    </form>
                @endif
            @endif
        </div>
    </div>
    <div class="card-body-dark">

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

        {{-- Подсказка про равенство --}}
        @if($allPlayersEqual && $leaderboard->count() > 0 && $leaderboard->first()->matches_played > 0)
            <div class="alert-info-custom mb-4">
                <i class="bi bi-info-circle me-2"></i>
                Каждый игрок сыграл по <strong>{{ $leaderboard->first()->matches_played }}</strong> матчей —
                все на равных. Хороший момент завершить турнир.
            </div>
        @endif

        {{-- Общая информация --}}
        <div class="alert-info-custom mb-4">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Кортов:</strong> {{ $tournament->courts_count ?? 1 }}
            &nbsp;|&nbsp;
            <strong>Игроков:</strong> {{ $registeredCount }}
            @if($currentRound)
                &nbsp;|&nbsp;
                <strong>Текущий раунд:</strong> {{ $currentRound->round_number }}
            @endif
        </div>

        <div class="row">
            {{-- Лидерборд --}}
            <div class="col-lg-4 mb-4">
                <h6 class="text-white mb-3">
                    <i class="bi bi-bar-chart-fill text-success me-2"></i>Лидерборд
                </h6>
                @if($leaderboard->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-dark table-sm align-middle mb-0" style="background: transparent;">
                            <thead>
                                <tr style="font-size: 0.75rem; color: #9ca3af; text-transform: uppercase;">
                                    <th class="text-start">#</th>
                                    <th class="text-start">Игрок</th>
                                    <th class="text-end">Очки</th>
                                    <th class="text-end">Матчей</th>
                                    <th class="text-end" title="Среднее очков на матч">Ср.</th>
                                    <th class="text-end">Рейтинг</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($leaderboard as $i => $player)
                                    @php $rankClass = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : '')); @endphp
                                    <tr class="leaderboard-row {{ $rankClass }}">
                                        <td>
                                            <div class="leaderboard-rank {{ $rankClass }}">{{ $i + 1 }}</div>
                                        </td>
                                        <td>
                                            <div class="leaderboard-name">{{ $player->user->full_name }}</div>
                                            <div class="leaderboard-rating">
                                                @if($player->bye_streak > 0)
                                                    <span class="badge bg-warning text-dark" title="Отдыхает подряд">
                                                        <i class="bi bi-moon"></i> {{ $player->bye_streak }}
                                                    </span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="text-end">{{ $player->total_points }}</td>
                                        <td class="text-end">{{ $player->matches_played }}</td>
                                        <td class="text-end fw-bold">{{ number_format($player->average_score, 2) }}</td>
                                        <td class="text-end text-secondary" style="font-size: 0.8rem;">
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
                @else
                    <div class="text-secondary">
                        <small>Лидерборд появится после запуска турнира.</small>
                    </div>
                @endif
            </div>

            {{-- Текущий раунд --}}
            <div class="col-lg-8">
                @if($currentRound)
                    <h6 class="text-white mb-3">
                        <i class="bi bi-layers-fill text-primary me-2"></i>
                        Раунд {{ $currentRound->round_number }}
                        <span class="round-status {{ $currentRound->status }} ms-2">
                            {{ $currentRound->isCompleted() ? 'Завершён' : 'Идёт' }}
                        </span>
                    </h6>

                    <div class="rounds-grid">
                        <div class="round-card" data-round-id="{{ $currentRound->id }}">
                            <div class="round-matches">
                                @foreach($currentRound->matches as $match)
                                    @php
                                        $winningTeam = null;
                                        if ($match->status === 'completed' && $match->team1_score !== null && $match->team2_score !== null) {
                                            $winningTeam = $match->team1_score > $match->team2_score ? 1 : ($match->team2_score > $match->team1_score ? 2 : null);
                                        }
                                        $action = $match->status === 'completed'
                                            ? route('club.tournaments.flex.matches.updateScore', $match)
                                            : route('club.tournaments.flex.matches.score', $match);
                                    @endphp

                                    <div class="match-card mb-3" data-match-id="{{ $match->id }}">
                                        <div class="d-flex justify-content-between align-items-center mb-2 px-2">
                                            <small class="text-secondary">
                                                <i class="bi bi-geo-alt"></i> Корт {{ $match->court_number }}
                                            </small>
                                            <small class="text-secondary">
                                                @if($match->status === 'completed')
                                                    <i class="bi bi-check-circle text-success"></i> Завершён
                                                @else
                                                    <i class="bi bi-clock text-warning"></i> В игре
                                                @endif
                                            </small>
                                        </div>

                                        <form action="{{ $action }}" method="POST" class="m-0">
                                            @csrf
                                            @if($match->status === 'completed')
                                                @method('PUT')
                                            @endif

                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                {{-- Команда 1 --}}
                                                <div class="match-team {{ $winningTeam === 1 ? 'winner' : '' }}" style="flex: 1; min-width: 140px;">
                                                    <div class="team-players">
                                                        <div class="player-badge">
                                                            <span class="player-name">{{ $match->team1Player1->full_name }}</span>
                                                        </div>
                                                        <div class="player-badge">
                                                            <span class="player-name">{{ $match->team1Player2->full_name }}</span>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Счёт --}}
                                                <div class="d-flex align-items-center gap-1">
                                                    <input type="number" name="team1_score" min="0" required
                                                           value="{{ $match->team1_score }}"
                                                           class="form-control text-center"
                                                           style="width: 60px;">
                                                    <span class="text-secondary">:</span>
                                                    <input type="number" name="team2_score" min="0" required
                                                           value="{{ $match->team2_score }}"
                                                           class="form-control text-center"
                                                           style="width: 60px;">
                                                </div>

                                                {{-- Команда 2 --}}
                                                <div class="match-team {{ $winningTeam === 2 ? 'winner' : '' }}" style="flex: 1; min-width: 140px;">
                                                    <div class="team-players">
                                                        <div class="player-badge">
                                                            <span class="player-name">{{ $match->team2Player1->full_name }}</span>
                                                        </div>
                                                        <div class="player-badge">
                                                            <span class="player-name">{{ $match->team2Player2->full_name }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mt-2 text-end">
                                                <button type="submit" class="btn-outline-custom">
                                                    <i class="bi bi-{{ $match->status === 'completed' ? 'pencil' : 'check-lg' }}"></i>
                                                    {{ $match->status === 'completed' ? 'Обновить счёт' : 'Сохранить счёт' }}
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Отдыхающие --}}
                    @if($currentRound->byes->count() > 0)
                        <div class="alert-info-custom mt-3">
                            <div class="mb-2">
                                <i class="bi bi-moon-stars me-2"></i>
                                <strong>Отдыхают в этом раунде:</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach($currentRound->byes as $bye)
                                    <span class="badge bg-secondary" style="font-size: 0.85rem; padding: 0.5em 0.75em;">
                                        {{ $bye->user->full_name }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @else
                    <div class="text-secondary text-center py-4">
                        <i class="bi bi-hourglass" style="font-size: 2rem;"></i>
                        <p class="mt-2 mb-0">
                            Турнир ещё не запущен. Зарегистрируйте игроков и нажмите «Запустить турнир».
                        </p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

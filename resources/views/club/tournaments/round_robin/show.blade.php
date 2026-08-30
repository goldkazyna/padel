@extends('layouts.app')

@section('title', $tournament->name)

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tournament-show.css') }}?v={{ time() }}">
@endpush

@section('content')

{{-- Шапка --}}
<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club->name }} · Round Robin (индивидуальный)</p>
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

            @if($tournament->participants->count() === $tournament->max_participants)
                @if(!$tournament->hasReserveParticipants())
                    <form action="{{ route('club.tournaments.start', $tournament) }}" method="POST"
                          onsubmit="return confirm('Начать турнир? Первый раунд будет сгенерирован.')">
                        @csrf
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-play-fill"></i> Начать турнир
                        </button>
                    </form>
                @else
                    <span class="btn-outline-custom disabled">
                        <i class="bi bi-exclamation-triangle"></i> Замените резервы на игроков
                    </span>
                @endif
            @endif
        @endif

        @if($tournament->status === 'in_progress')
            @php $canFinish = app(\App\Services\RoundRobinService::class)->canFinishTournament($tournament); @endphp
            @if($canFinish)
                <form action="{{ route('club.tournaments.finish', $tournament) }}" method="POST"
                      onsubmit="return confirm('Завершить турнир? Итоговая таблица строится по сыгранным матчам.')">
                    @csrf
                    <button type="submit" class="btn-primary-custom">
                        <i class="bi bi-trophy-fill"></i> Завершить турнир
                    </button>
                </form>
            @else
                <span class="btn-outline-custom disabled" title="Сыграйте все матчи последнего раунда">
                    <i class="bi bi-hourglass"></i> Доиграйте раунд
                </span>
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

{{-- Информация о турнире --}}
@include('club.tournaments.partials._info')

{{-- Участники --}}
@include('club.tournaments.partials._participants')

{{-- Round Robin контент --}}
@if($tournament->roundRobinPlayers->count() > 0)
    <div class="card-body-dark">

        @php
            $roundsPlayed = $tournament->roundRobinRounds->count();
            $courtsCount = (int) ($tournament->roundRobinPlayers->count() / 4);
        @endphp
        <div class="alert-info-custom mb-4">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Сыграно раундов:</strong> {{ $roundsPlayed }}
            &nbsp;|&nbsp;
            <strong>Кортов:</strong> {{ $courtsCount }}
            &nbsp;|&nbsp;
            <strong>Игроков:</strong> {{ $tournament->roundRobinPlayers->count() }}
        </div>

        {{-- Таблица лидеров: победы → разница геймов → личная встреча --}}
        <div class="section-subheader">
            <i class="bi bi-trophy"></i> Таблица лидеров
        </div>
        <div class="leaderboard-table-wrapper mb-4">
            <table class="leaderboard-table">
                <thead>
                    <tr>
                        <th class="col-rank ttt">#</th>
                        <th class="col-player ttt">Игрок</th>
                        <th class="col-stat ttt" title="Победы">В</th>
                        <th class="col-stat ttt" title="Поражения">П</th>
                        <th class="col-stat ttt" title="Геймы забито">З</th>
                        <th class="col-stat ttt" title="Геймы пропущено">Пр</th>
                        <th class="col-stat ttt" title="Разница геймов">±</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($standings as $row)
                        @php
                            $player = $row['user'];
                            $rank = $loop->iteration;
                            $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                            $diff = $row['diff'];
                        @endphp
                        <tr class="{{ $rankClass }}">
                            <td class="col-rank ttt">
                                <span class="rank-badge {{ $rankClass }}">{{ $rank }}</span>
                            </td>
                            <td class="col-player ttt">
                                <div class="player-info">
                                    @include('club.tournaments.partials._player_avatar')
                                    <div class="player-details">
                                        <div class="player-name">{{ $player->name }}</div>
                                        <div class="player-rating">{{ $player->rating }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="col-stat wins ttt"><strong>{{ $row['wins'] }}</strong></td>
                            <td class="col-stat losses ttt">{{ $row['losses'] }}</td>
                            <td class="col-stat points-for ttt">{{ $row['points_for'] }}</td>
                            <td class="col-stat points-against ttt">{{ $row['points_against'] }}</td>
                            <td class="col-stat ttt">{{ $diff > 0 ? '+'.$diff : $diff }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="section-subheader">
            <i class="bi bi-calendar3"></i> Раунды
        </div>

        {{-- Раунды --}}
        <div>
            <div class="rounds-grid">
                @foreach($tournament->roundRobinRounds as $round)
                    @php
                        $isActive = $round->isInProgress();
                        $isCompleted = $round->isCompleted();
                        $statusClass = $isActive ? 'active' : ($isCompleted ? 'completed' : 'pending');
                    @endphp
                    <div class="round-card {{ $statusClass }}" data-round-id="{{ $round->id }}">
                        <div class="round-header" onclick="toggleRrRound('rr-round-{{ $round->id }}')" style="cursor: pointer;">
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
                                <i class="bi bi-chevron-down collapse-icon {{ $isActive ? '' : 'collapsed' }}" id="icon-rr-round-{{ $round->id }}"></i>
                            </div>
                        </div>
                        <div class="round-matches collapsible-content {{ $isActive ? '' : 'collapsed' }}" id="rr-round-{{ $round->id }}">
                            @foreach($round->matches as $match)
                                @php $courtLabel = "Корт {$match->court_number}"; @endphp
                                <div class="match-card" data-match-id="{{ $match->id }}">
                                    <div class="match-court-header court-middle">
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
                                                        data-bs-target="#rrEditScoreModal{{ $match->id }}"
                                                        title="Редактировать счёт">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            @elseif(!$match->isCompleted() && $round->isInProgress())
                                                <button class="btn-score"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#rrScoreModal{{ $match->id }}">
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
                                    <div class="modal fade" id="rrScoreModal{{ $match->id }}" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content modal-dark">
                                                <div class="modal-header border-0">
                                                    <h5 class="modal-title">Ввести счёт · {{ $courtLabel }}</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form action="{{ route('club.roundRobin.saveScore', $match) }}" method="POST">
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
                                                        <p class="text-secondary text-center mt-3 mb-0"><small>Ничьих нет — играйте до победы.</small></p>
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
                                    <div class="modal fade" id="rrEditScoreModal{{ $match->id }}" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content modal-dark">
                                                <div class="modal-header border-0">
                                                    <h5 class="modal-title">Редактировать · {{ $courtLabel }}</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form action="{{ route('club.roundRobin.updateScore', $match) }}" method="POST">
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
                    $service = app(\App\Services\RoundRobinService::class);
                    $canGenerateNext = $service->canGenerateNextRound($tournament);
                    $currentRoundNumber = $tournament->roundRobinRounds->max('round_number') ?? 0;
                @endphp

                @if($canGenerateNext)
                    <div class="text-center mt-4">
                        <form action="{{ route('club.roundRobin.nextRound', $tournament) }}" method="POST"
                              onsubmit="return confirm('Сгенерировать раунд {{ $currentRoundNumber + 1 }}?')">
                            @csrf
                            <button type="submit" class="btn-primary-custom btn-lg">
                                <i class="bi bi-plus-circle me-2"></i> Сгенерировать раунд {{ $currentRoundNumber + 1 }}
                            </button>
                        </form>
                        <div class="text-secondary mt-2">
                            <small>Нажмите «Завершить турнир» в шапке, когда сыграете достаточно раундов.</small>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
@endif

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
.match-court-header.court-middle { color: #0dcaf0; background: rgba(13, 202, 240, 0.10); }

.rounds-grid { display: grid; grid-template-columns: repeat(1, 1fr); gap: 16px; }
.player-line { font-size: 30px; }
.team-score { font-size: 40px; }
.score-team-names { font-size: 22px; }
.player-name { font-weight: 500; font-size: 24px; }

/* Аватар игрока в таблице: фото, если есть, иначе инициалы. */
.player-info { display: flex; align-items: center; gap: 12px; }
.player-avatar { width: 36px; height: 36px; flex: none; border-radius: 50%; display: flex;
    align-items: center; justify-content: center; background: var(--accent); color: #000;
    font-weight: 600; font-size: 0.75rem; }
.player-avatar-img { object-fit: cover; background: transparent; }
.player-details { display: flex; flex-direction: column; }
.player-rating { font-size: 0.75rem; color: var(--text-secondary); }
.ttt { font-size: 24px; }
</style>

<script>
function toggleRrRound(id) {
    const content = document.getElementById(id);
    const icon = document.getElementById('icon-' + id);
    if (content && icon) {
        content.classList.toggle('collapsed');
        icon.classList.toggle('collapsed');
    }
}
</script>

@endsection

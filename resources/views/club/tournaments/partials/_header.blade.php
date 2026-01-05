<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club->name }} · {{ $tournament->type_name }}</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if($tournament->status === 'open')
            @if($tournament->approvedParticipantsCount() < $tournament->max_participants)
                <form action="{{ route('club.tournaments.addTestPlayers', $tournament) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn-outline-custom">
                        <i class="bi bi-people-fill"></i> +Тест игроки
                    </button>
                </form>
            @endif
            
            @if(($tournament->isAmericano() || $tournament->isMexicano()) && $tournament->approvedParticipantsCount() === $tournament->max_participants)
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
                @php $canFinish = app(\App\Services\AmericanoService::class)->canFinishTournament($tournament); @endphp
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
                @php $canFinish = app(\App\Services\MexicanoService::class)->canFinishTournament($tournament); @endphp
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
                @php $canFinish = app(\App\Services\TeamTournamentService::class)->canFinishTournament($tournament); @endphp
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
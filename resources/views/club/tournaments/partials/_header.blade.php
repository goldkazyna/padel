<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>
            {{ $tournament->club->name }} · {{ $tournament->type_name }}
            @if($tournament->status === 'draft')
                <span class="badge bg-secondary ms-2">Черновик</span>
            @elseif($tournament->status === 'open')
                <span class="badge bg-success ms-2">Регистрация открыта</span>
            @elseif($tournament->status === 'in_progress')
                <span class="badge bg-primary ms-2">В процессе</span>
            @elseif($tournament->status === 'completed')
                <span class="badge bg-info ms-2">Завершён</span>
            @elseif($tournament->status === 'cancelled')
                <span class="badge bg-danger ms-2">Отменён</span>
            @endif
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if(auth()->user()->isSuperAdmin() || auth()->user()->hasTournamentsFullAccess($tournament->club))
            @if($tournament->status === 'open')
                @if($tournament->approvedParticipantsCount() < $tournament->max_participants)
                    <form action="{{ route('club.tournaments.addTestPlayers', $tournament) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn-outline-custom">
                            <i class="bi bi-people-fill"></i> +Тест игроки
                        </button>
                    </form>
                @endif
                
                @if($tournament->isAmericano())
                    @php
                        $hasGroups = $tournament->groups()->count() > 0;
                        $assignedPlayerIds = $tournament->groups()->with('players')->get()->pluck('players')->flatten()->pluck('id')->toArray();
                        $unassignedCount = $tournament->participants()
                            ->wherePivot('status', 'registered')
                            ->whereNotIn('users.id', $assignedPlayerIds)
                            ->count();
                        $allAssigned = $hasGroups && $unassignedCount === 0;
                    @endphp
                    
                    @if($hasGroups && $allAssigned && !$tournament->hasReserveParticipants())
                        {{-- Группы сформированы и все распределены --}}
                        <form action="{{ route('club.tournaments.start', $tournament) }}" method="POST" 
                              onsubmit="return confirm('Начать турнир? Раунды будут сгенерированы автоматически.')">
                            @csrf
                            <button type="submit" class="btn-primary-custom">
                                <i class="bi bi-play-fill"></i> Начать турнир
                            </button>
                        </form>
                    @elseif($hasGroups && !$allAssigned)
                        {{-- Группы есть, но не все распределены --}}
                        <span class="btn-outline-custom disabled" title="Распределите всех игроков по группам">
                            <i class="bi bi-exclamation-triangle"></i> Распределите игроков
                        </span>
                    @endif
                @elseif($tournament->isMexicano() && $tournament->approvedParticipantsCount() === $tournament->max_participants && !$tournament->hasReserveParticipants())
                    <form action="{{ route('club.tournaments.start', $tournament) }}" method="POST" 
                          onsubmit="return confirm('Начать турнир? Раунды будут сгенерированы автоматически.')">
                        @csrf
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-play-fill"></i> Начать турнир
                        </button>
                    </form>
                @elseif($tournament->isTeamBased() && $tournament->teams->where('status', 'approved')->count() >= (int) ($tournament->max_participants / 2))
                    <a href="{{ route('club.tournaments.distribute', $tournament) }}" class="btn-outline-custom" style="margin-right:8px;">
                        <i class="bi bi-grid-3x3-gap"></i> Распределить вручную
                    </a>
                    <form action="{{ route('club.tournaments.start', $tournament) }}" method="POST"
                          onsubmit="return confirm('Начать турнир? Группы и матчи будут сгенерированы автоматически (по рейтингу змейкой).')">
                        @csrf
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-play-fill"></i> Начать (авто)
                        </button>
                    </form>
                @endif
                
                @if($tournament->hasReserveParticipants())
                    <span class="btn-outline-custom disabled" title="Замените все резервы">
                        <i class="bi bi-exclamation-triangle"></i> Замените резервы
                    </span>
                @endif
            @endif

            @if($tournament->status === 'in_progress')
                @if($tournament->isAmericano())
                    @php
                        $canFinish = app(\App\Services\AmericanoService::class)->canFinishTournament($tournament);
                        $canGeneratePlayoff = app(\App\Services\AmericanoService::class)->canGeneratePlayoff($tournament);
                        $hasPlayoffMatches = $tournament->playoffMatches()->count() > 0;
                    @endphp

                    {{-- Кнопка генерации плей-офф --}}
                    @if($tournament->hasPlayoff() && $canGeneratePlayoff)
                        <form action="{{ route('club.americano.generatePlayoff', $tournament) }}" method="POST" 
                              onsubmit="return confirm('Сгенерировать плей-офф? Лучшие игроки из групп выйдут в финальную стадию.')">
                            @csrf
                            <button type="submit" class="btn-primary-custom">
                                <i class="bi bi-trophy"></i> Сгенерировать плей-офф
                            </button>
                        </form>
                    @endif
                    
                    {{-- Кнопка завершения --}}
                    @if($canFinish)
                        <form action="{{ route('club.tournaments.finish', $tournament) }}" method="POST" 
                              onsubmit="return confirm('Завершить турнир и начислить рейтинг всем участникам?')">
                            @csrf
                            <button type="submit" class="btn-primary-custom">
                                <i class="bi bi-trophy-fill"></i> Завершить турнир
                            </button>
                        </form>
                    @elseif($tournament->hasPlayoff() && $hasPlayoffMatches)
                        <span class="btn-outline-custom disabled" title="Сыграйте плей-офф">
                            <i class="bi bi-hourglass"></i> Сыграйте плей-офф
                        </span>
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
            
            {{-- Кнопка отмены турнира --}}
            @if(!in_array($tournament->status, ['completed', 'cancelled']))
                <form action="{{ route('club.tournaments.cancel', $tournament) }}" method="POST" 
                      onsubmit="return confirm('Отменить турнир? Это действие нельзя отменить.')">
                    @csrf
                    <button type="submit" class="btn-danger-custom">
                        <i class="bi bi-x-circle"></i> Отменить
                    </button>
                </form>
            @endif
        @endif
        
        <a href="{{ route('club.tournaments.index') }}" class="btn-outline-custom">
            <i class="bi bi-arrow-left"></i> Назад
        </a>
    </div>
</div>
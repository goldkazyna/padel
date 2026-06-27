{{-- resources/views/club/tournaments/kingofcourt/partials/_header.blade.php --}}
<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club->name }} · Король корта</p>
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
                    @php $pairsReady = !$tournament->isPairedKingOfCourt() || $tournament->kingOfCourtPairs()->exists(); @endphp
                    @if($tournament->isPairedKingOfCourt() && !$tournament->kingOfCourtPairs()->exists())
                        {{-- Фикс-пары: сначала создать пары --}}
                        <a href="{{ route('club.kingofcourt.pairs', $tournament) }}" class="btn-primary-custom">
                            <i class="bi bi-people-fill"></i> Создать пары
                        </a>
                    @else
                        <form action="{{ route('club.tournaments.start', $tournament) }}" method="POST"
                              onsubmit="return confirm('Начать турнир?')">
                            @csrf
                            <button type="submit" class="btn-primary-custom">
                                <i class="bi bi-play-fill"></i> Начать турнир
                            </button>
                        </form>
                    @endif
                @else
                    <span class="btn-outline-custom disabled">
                        <i class="bi bi-exclamation-triangle"></i> Замените резервы на игроков
                    </span>
                @endif
            @endif
        @endif

        @if($tournament->status === 'in_progress')
            @php $canFinish = app(\App\Services\KingOfCourtService::class)->canFinishTournament($tournament); @endphp
            @if($canFinish)
                <form action="{{ route('club.tournaments.finish', $tournament) }}" method="POST"
                      onsubmit="return confirm('Завершить турнир и зафиксировать итоговые места?')">
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

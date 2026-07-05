{{-- resources/views/club/tournaments/justpadelit/partials/_header.blade.php --}}
<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club->name }} · Just Padel It</p>
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
                    @php $pairsReady = !$tournament->isPairedJustPadelIt() || $tournament->justPadelItPairs()->exists(); @endphp
                    @if($tournament->isPairedJustPadelIt() && !$tournament->justPadelItPairs()->exists())
                        {{-- Фикс-пары: сначала создать пары --}}
                        <a href="{{ route('club.justpadelit.pairs', $tournament) }}" class="btn-primary-custom">
                            <i class="bi bi-people-fill"></i> Создать пары
                        </a>
                    @else
                        <a href="{{ route('club.justpadelit.seeding', $tournament) }}" class="btn-primary-custom">
                            <i class="bi bi-play-fill"></i> Посев и старт
                        </a>
                    @endif
                @else
                    <span class="btn-outline-custom disabled">
                        <i class="bi bi-exclamation-triangle"></i> Замените резервы на игроков
                    </span>
                @endif
            @endif
        @endif

        @if($tournament->status === 'in_progress')
            @php $canFinish = app(\App\Services\JustPadelItService::class)->canFinishTournament($tournament); @endphp
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

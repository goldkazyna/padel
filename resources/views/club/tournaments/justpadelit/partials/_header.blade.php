{{-- resources/views/club/tournaments/justpadelit/partials/_header.blade.php --}}
<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club->name }} · Just Padel It</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if($tournament->status === 'open')
            @if($tournament->participants->count() < $tournament->max_participants)
                {{-- При самостоятельной записи игрок один не приходит: заявка
                     всегда парная, поэтому и тестовые данные должны быть парами. --}}
                @if($tournament->usesSoloRegistration())
                    <form action="{{ route('club.tournaments.addTestPlayers', $tournament) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn-outline-custom">
                            <i class="bi bi-people-fill"></i> +Тест игроки
                        </button>
                    </form>
                @else
                    <form action="{{ route('club.tournaments.addTestTeams', $tournament) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn-outline-custom">
                            <i class="bi bi-lightning-fill"></i> +Тестовые пары
                        </button>
                    </form>
                @endif
            @endif

            @php
                // При самостоятельной записи участников до старта нет: пары
                // лежат в командах турнира и переезжают в участники при старте.
                $jpiReady = $tournament->isSelfPairing()
                    ? $tournament->approvedParticipantsCount() === (int) $tournament->max_participants
                    : ($tournament->isPairedJustPadelIt()
                        ? $tournament->participants->count() === (int) $tournament->max_participants
                        : $tournament->jpiSeedingReady());
            @endphp
            @if($jpiReady)
                @if(!$tournament->hasReserveParticipants())
                    @if($tournament->isPairedJustPadelIt() && !$tournament->isSelfPairing() && !$tournament->justPadelItPairs()->exists())
                        {{-- Пары собирает админ: сначала собрать, потом старт --}}
                        <a href="{{ route('club.justpadelit.pairs', $tournament) }}" class="btn-primary-custom">
                            <i class="bi bi-people-fill"></i> Создать пары
                        </a>
                    @elseif($tournament->isPairedJustPadelIt())
                        {{-- Фикс-пары уже созданы: старт напрямую с авто-посевом пар по рейтингу --}}
                        <form action="{{ route('club.justpadelit.start', $tournament) }}" method="POST"
                              onsubmit="return confirm('Начать турнир? Пары будут расставлены по кортам автоматически по рейтингу.')">
                            @csrf
                            <button type="submit" class="btn-primary-custom">
                                <i class="bi bi-play-fill"></i> Начать турнир
                            </button>
                        </form>
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

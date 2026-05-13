{{-- resources/views/club/tournaments/bali_koc/partials/_header.blade.php --}}
<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club->name }} · Король Корта (Bali Format)</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @php
            $service = app(\App\Services\BaliKocService::class);
            $pairsCreated = $service->arePairsCreated($tournament);
        @endphp

        @if($tournament->status === 'open')
            @if($tournament->participants->count() < $tournament->max_participants)
                <form action="{{ route('club.tournaments.addTestPlayers', $tournament) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn-outline-custom">
                        <i class="bi bi-people-fill"></i> +Тест игроки
                    </button>
                </form>
            @endif

            @if($tournament->participants->count() === $tournament->max_participants && !$tournament->hasReserveParticipants())
                @if(!$pairsCreated)
                    <a href="{{ route('club.bali-koc.pairs', $tournament) }}" class="btn-primary-custom">
                        <i class="bi bi-people"></i> Создать пары
                    </a>
                @else
                    <a href="{{ route('club.bali-koc.pairs', $tournament) }}" class="btn-outline-custom">
                        <i class="bi bi-people"></i> Пары
                    </a>
                    <form action="{{ route('club.tournaments.start', $tournament) }}" method="POST"
                          onsubmit="return confirm('Начать турнир? Первый раунд будет сгенерирован случайно.')">
                        @csrf
                        <button type="submit" class="btn-primary-custom">
                            <i class="bi bi-play-fill"></i> Начать турнир
                        </button>
                    </form>
                @endif
            @elseif($tournament->hasReserveParticipants())
                <span class="btn-outline-custom disabled">
                    <i class="bi bi-exclamation-triangle"></i> Замените резервы на игроков
                </span>
            @endif
        @endif

        @if($tournament->status === 'in_progress')
            @php $canFinish = $service->canFinishTournament($tournament); @endphp
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

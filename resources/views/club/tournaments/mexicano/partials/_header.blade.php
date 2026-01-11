{{-- resources/views/club/tournaments/mexicano/partials/_header.blade.php --}}
<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club->name }} · Мексикано</p>
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
                          onsubmit="return confirm('Начать турнир? Первый раунд будет сгенерирован автоматически.')">
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
			@if($tournament->hasPlayoff())
				<span class="btn-outline-custom disabled" title="Сыграйте финал плей-офф">
					<i class="bi bi-hourglass"></i> Сыграйте финал
				</span>
			@else
				<span class="btn-outline-custom disabled" title="Сыграйте все раунды">
					<i class="bi bi-hourglass"></i> Не все раунды сыграны
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
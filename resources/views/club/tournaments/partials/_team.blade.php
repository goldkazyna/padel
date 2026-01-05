{{-- Зарегистрированные пары --}}
<div class="card-dark mb-4">
    <div class="card-header-dark d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-people-fill text-primary me-2"></i>Зарегистрированные пары</h5>
        <span class="badge bg-primary">{{ $tournament->teams->count() }} / {{ $tournament->max_participants / 2 }} пар</span>
    </div>
    <div class="card-body-dark">
        @if($tournament->status === 'open')
            @include('club.tournaments.partials._team_form')
        @endif

        {{-- Список пар --}}
        @if($tournament->teams->count() > 0)
            <div class="teams-grid">
                @foreach($tournament->teams()->orderBy('rating_avg', 'desc')->get() as $index => $team)
                    <div class="team-card">
                        <div class="team-rank">{{ $index + 1 }}</div>
                        <div class="team-info">
                            <div class="team-name">{{ $team->name }}</div>
                            <div class="team-players-names">
                                {{ $team->player1->full_name }} / {{ $team->player2->full_name }}
                            </div>
                            <div class="team-rating">
                                <i class="bi bi-star-fill text-warning"></i> {{ $team->rating_avg }}
                            </div>
                        </div>
                        @if($tournament->status === 'open')
                            <form action="{{ route('club.tournaments.removeTeam', [$tournament, $team]) }}" method="POST" onsubmit="return confirm('Удалить пару?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-remove-team"><i class="bi bi-x-lg"></i></button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center text-secondary py-4">
                <i class="bi bi-people" style="font-size: 3rem;"></i>
                <p class="mt-2">Пока нет зарегистрированных пар</p>
            </div>
        @endif
    </div>
</div>

{{-- Групповой этап --}}
@if($tournament->teamGroups->count() > 0)
    @include('club.tournaments.partials._team_groups')
@endif

{{-- Плей-офф --}}
@if($tournament->playoffMatches->count() > 0)
    @include('club.tournaments.partials._team_playoff')
@endif
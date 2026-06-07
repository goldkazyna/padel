{{-- Ручной сбор пар (pairing_mode=admin): игроки записались поодиночке,
     админ собирает пары. Список пар и удаление — в основном _team.blade. --}}
@php
    $pairedIds = $approvedTeams->flatMap(fn($t) => [$t->player1_id, $t->player2_id])->all();
    $soloPlayers = $tournament->participants()
        ->wherePivot('status', 'registered')
        ->get()
        ->reject(fn($u) => in_array($u->id, $pairedIds))
        ->sortByDesc('rating')
        ->values();
    $maxPairs = (int) ($tournament->max_participants / 2);
    $canStart = $approvedTeams->count() === $maxPairs && $maxPairs > 0;
@endphp

<div class="admin-pairing mb-4" style="background:#18181b;border:1px solid #2a2a2a;border-radius:14px;padding:16px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <strong style="color:#fff;">Сбор пар — свободных игроков: {{ $soloPlayers->count() }}</strong>
        @if($soloPlayers->count() >= 2)
            <form action="{{ route('club.tournaments.pairing.auto', $tournament) }}" method="POST" class="d-inline m-0">
                @csrf
                <button class="btn btn-sm btn-outline-success">
                    <i class="bi bi-shuffle"></i> Случайные пары (баланс по рейтингу)
                </button>
            </form>
        @endif
    </div>

    @if($soloPlayers->count() >= 2)
        <form action="{{ route('club.tournaments.pairing.create', $tournament) }}" method="POST" class="row g-2 align-items-end">
            @csrf
            <div class="col">
                <select name="player1_id" class="form-select" required>
                    <option value="">Игрок 1</option>
                    @foreach($soloPlayers as $u)
                        <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->rating }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto" style="color:#a1a1aa;align-self:center;">+</div>
            <div class="col">
                <select name="player2_id" class="form-select" required>
                    <option value="">Игрок 2</option>
                    @foreach($soloPlayers as $u)
                        <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->rating }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary">Создать пару</button>
            </div>
        </form>
    @elseif($soloPlayers->count() === 1)
        <p class="text-warning mb-0">Остался 1 свободный игрок — нужна чётная запись.</p>
    @else
        <p class="text-success mb-0"><i class="bi bi-check-circle"></i> Все игроки разбиты на пары.</p>
    @endif

    @if($canStart)
        <form action="{{ route('club.tournaments.start', $tournament) }}" method="POST" class="mt-3" onsubmit="return confirm('Начать турнир? Регистрация закроется.')">
            @csrf
            <button class="btn btn-success w-100">Начать турнир</button>
        </form>
    @endif
</div>

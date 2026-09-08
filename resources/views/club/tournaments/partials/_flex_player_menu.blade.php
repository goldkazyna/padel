{{--
    Трёхточечное меню игрока в парном флексе.

    Организатор тасует состав руками: перекинуть между основным списком,
    модерацией и листом ожидания, пересадить на любое место — свободное,
    занятое (тогда игроки меняются местами) или в пустую пару, убрать совсем.
    Раньше для этого приходилось разбивать пару и собирать заново, а когда все
    пары были полными, пересадить было некуда вовсе.

    Ждёт: $tournament, $player, $current (registered|pending|waiting),
    $seatOptions — все места сетки, $canCreatePair — влезает ли ещё пара.
--}}
@php
    $menuPlayerId = (int) $player->id;
    $menuSeats = collect($seatOptions);
    $menuOwn = $menuSeats->first(fn ($s) => (int) $s->userId === $menuPlayerId);
    // Места своей же пары не предлагаем: пересадка внутри неё ничего не меняет.
    $menuSeats = $menuOwn
        ? $menuSeats->reject(fn ($s) => $s->teamId === $menuOwn->teamId)
        : $menuSeats;
    $menuAlone = $menuOwn && $menuOwn->soloPair;
@endphp

<div class="dropdown d-inline flexp-menu">
    <button class="flexp-dots" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport"
            aria-expanded="false" title="Действия">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
        <li class="dropdown-header">Состав</li>

        @if($current === 'pending')
            <li>
                <form action="{{ route('club.tournaments.participants.approve', [$tournament, $player->id]) }}" method="POST">
                    @csrf
                    <button type="submit" class="dropdown-item text-success">
                        <i class="bi bi-check-lg"></i> Одобрить заявку
                    </button>
                </form>
            </li>
        @else
            <li>
                <form action="{{ route('club.tournaments.participants.move', [$tournament, $player->id]) }}" method="POST">
                    @csrf
                    <input type="hidden" name="to" value="pending">
                    <button type="submit" class="dropdown-item">
                        <i class="bi bi-hourglass-split"></i> На модерацию
                    </button>
                </form>
            </li>
        @endif

        @if($current !== 'registered')
            <li>
                <form action="{{ route('club.tournaments.participants.move', [$tournament, $player->id]) }}" method="POST">
                    @csrf
                    <input type="hidden" name="to" value="registered">
                    <button type="submit" class="dropdown-item">
                        <i class="bi bi-person-check"></i> В основной список
                    </button>
                </form>
            </li>
        @endif

        @if($current !== 'waiting')
            <li>
                <form action="{{ route('club.tournaments.participants.move', [$tournament, $player->id]) }}" method="POST">
                    @csrf
                    <input type="hidden" name="to" value="waiting">
                    <button type="submit" class="dropdown-item">
                        <i class="bi bi-hourglass"></i> В лист ожидания
                        <span class="flexp-menu-note">освободит место в паре</span>
                    </button>
                </form>
            </li>
        @endif

        <li><hr class="dropdown-divider"></li>
        <li class="dropdown-header">Пересадить</li>

        @if($canCreatePair && !$menuAlone)
            <li>
                <form action="{{ route('club.tournaments.pairs.move', [$tournament, $player->id, 0, 2]) }}" method="POST">
                    @csrf
                    <button type="submit" class="dropdown-item">
                        <i class="bi bi-plus-square"></i> В пустую пару
                    </button>
                </form>
            </li>
        @endif

        @forelse($menuSeats as $slot)
            <li>
                <form action="{{ route('club.tournaments.pairs.move', [$tournament, $player->id, $slot->teamId, $slot->seat]) }}" method="POST">
                    @csrf
                    <button type="submit" class="dropdown-item">
                        <i class="bi bi-arrow-left-right"></i>
                        Пара {{ $slot->position }} —
                        @if($slot->userId)
                            вместо {{ $slot->name }}<span class="flexp-menu-note">меняются местами</span>
                        @else
                            свободное место<span class="flexp-menu-note">напарник: {{ $slot->name }}</span>
                        @endif
                    </button>
                </form>
            </li>
        @empty
            @unless($canCreatePair && !$menuAlone)
                <li><span class="dropdown-item-text flexp-menu-note">мест в сетке нет</span></li>
            @endunless
        @endforelse

        <li><hr class="dropdown-divider"></li>
        <li>
            <form action="{{ route('club.tournaments.participants.remove', [$tournament, $player->id]) }}"
                  method="POST" onsubmit="return confirm('Убрать {{ $player->name }} из турнира?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="dropdown-item text-danger">
                    <i class="bi bi-x-lg"></i> Убрать из турнира
                </button>
            </form>
        </li>
    </ul>
</div>

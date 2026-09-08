{{--
    Трёхточечное меню игрока в парном флексе.

    Организатор тасует состав руками: перекинуть между основным списком,
    модерацией и листом ожидания, пересадить в другую пару, убрать совсем.
    Раньше для этого приходилось разбивать пару и собирать заново.

    Ждёт: $tournament, $player, $current (registered|pending|waiting),
    $freePairs — пары со свободным местом, $currentPairId — где сидит сейчас.
--}}
@php
    $moveLabels = [
        'registered' => 'В основной список',
        'pending'    => 'На модерацию',
        'waiting'    => 'В лист ожидания',
    ];
@endphp

<div class="dropdown d-inline flexp-menu">
    <button class="flexp-dots" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Действия">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
        <li class="dropdown-header">Статус</li>
        @foreach($moveLabels as $to => $label)
            @if($to !== $current)
                <li>
                    <form action="{{ route('club.tournaments.participants.move', [$tournament, $player->id]) }}" method="POST">
                        @csrf
                        <input type="hidden" name="to" value="{{ $to }}">
                        <button type="submit" class="dropdown-item">
                            <i class="bi bi-arrow-right-short"></i> {{ $label }}
                        </button>
                    </form>
                </li>
            @endif
        @endforeach

        @if($freePairs->isNotEmpty() || $currentPairId)
            <li><hr class="dropdown-divider"></li>
            <li class="dropdown-header">Пересадить</li>
        @endif

        @foreach($freePairs as $index => $free)
            @continue($currentPairId === $free->id)
            <li>
                <form action="{{ route('club.tournaments.pairs.move', [$tournament, $player->id, $free->id]) }}" method="POST">
                    @csrf
                    <button type="submit" class="dropdown-item">
                        <i class="bi bi-arrow-left-right"></i>
                        Пара {{ $free->position }} — к {{ $free->partner }}
                    </button>
                </form>
            </li>
        @endforeach

        @if(!$currentPairId)
            <li>
                <form action="{{ route('club.tournaments.pairs.seat', [$tournament, $player->id]) }}" method="POST">
                    @csrf
                    <button type="submit" class="dropdown-item">
                        <i class="bi bi-plus-lg"></i> В первое свободное место
                    </button>
                </form>
            </li>
        @endif

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

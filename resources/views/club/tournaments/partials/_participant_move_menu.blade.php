{{-- Трёхточечное меню: перемещение участника между статусами.
     Ожидает: $tournament, $participant, $current (registered|pending|waiting). --}}
@php
    $moveLabels = [
        'registered' => 'В основной список',
        'pending'    => 'На модерацию',
        'waiting'    => 'В лист ожидания',
    ];
@endphp
<div class="dropdown d-inline">
    <button class="btn-outline-custom btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Переместить">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
        @foreach($moveLabels as $to => $label)
            @if($to !== $current)
                <li>
                    <form action="{{ route('club.tournaments.participants.move', [$tournament, $participant->id]) }}" method="POST">
                        @csrf
                        <input type="hidden" name="to" value="{{ $to }}">
                        <button type="submit" class="dropdown-item">
                            <i class="bi bi-arrow-right-short"></i> {{ $label }}
                        </button>
                    </form>
                </li>
            @endif
        @endforeach
    </ul>
</div>

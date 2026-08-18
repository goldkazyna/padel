{{--
    Фиксированные пары в «Just Padel It», когда их собирает организатор.

    Единица записи здесь — пара, а не игрок: форма заводит сразу обоих.
    Когда пары собирают сами игроки, блок не нужен — там пары приходят
    командами турнира и показываются выше.
--}}
@php
    $jpiPairs = $tournament->justPadelItPairs()->with(['player1:id,name', 'player2:id,name'])->get();
    $jpiPaired = $jpiPairs->count() * 2;
    $jpiTotal = $tournament->participants()->wherePivot('status', 'registered')->count();
@endphp

<div class="pair-add-section mt-4">
    <div class="add-participant-header">
        <i class="bi bi-people"></i>
        <span>Пары</span>
        <span class="pair-count-badge">{{ $jpiPairs->count() }}</span>
        @if($jpiTotal > $jpiPaired)
            <span class="pair-rest-badge">без пары: {{ $jpiTotal - $jpiPaired }}</span>
        @endif
    </div>

    @if($jpiPairs->isNotEmpty())
        <div class="pair-list">
            @foreach($jpiPairs as $i => $pair)
                <div class="pair-row">
                    <div class="pair-row-num">{{ $i + 1 }}</div>
                    <div class="pair-row-names">
                        {{ $pair->player1->name ?? '—' }} <span>/</span> {{ $pair->player2->name ?? '—' }}
                    </div>
                    @if($tournament->status === 'open')
                        <form action="{{ route('club.tournaments.pairs.remove', [$tournament, $pair]) }}"
                              method="POST" class="d-inline"
                              onsubmit="return confirm('Разбить пару? Игроки останутся в списке участников.')">
                            @csrf
                            @method('DELETE')
                            <button class="btn-danger-custom btn-sm" title="Разбить пару">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if($tournament->status === 'open')
        @include('club.tournaments.partials._pair_add_form', [
            'action' => route('club.tournaments.pairs.add', $tournament),
        ])
    @endif
</div>

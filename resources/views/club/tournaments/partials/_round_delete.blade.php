{{--
    Корзинка «удалить раунд» в шапке раунда.

    Ждёт $tournament и $round. Кнопка появляется только у последнего раунда,
    в котором ещё нет счёта: «следующий раунд» жмут на один раз больше, чем
    нужно, и пустой раунд не даёт завершить турнир. Середину вынимать нельзя —
    развалится нумерация и ротация, поэтому там кнопки нет вовсе.
--}}
@php
    // Правила решает сервис — тот же, что проверяет запрос на сервере.
    [$roundCanDelete] = app(\App\Services\RoundRemovalService::class)->check($tournament, $round);
@endphp

@if($roundCanDelete)
    <form method="POST" class="round-delete-form"
          action="{{ route('club.tournaments.rounds.remove', [$tournament, $round->id]) }}"
          onclick="event.stopPropagation()"
          onsubmit="return confirm('Удалить раунд {{ $round->round_number }}? Матчи раунда удалятся вместе с ним.')">
        @csrf
        @method('DELETE')
        <button class="round-delete-btn" title="Удалить лишний раунд">
            <i class="bi bi-trash"></i>
        </button>
    </form>
@endif

@once
    <style>
        .round-delete-form { display: inline-flex; margin: 0; }

        .round-delete-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            padding: 0;
            border: 1px solid transparent;
            border-radius: 8px;
            background: transparent;
            color: var(--text-muted);
            transition: all .2s;
        }

        .round-delete-btn:hover {
            background: rgba(239, 68, 68, .15);
            border-color: rgba(239, 68, 68, .3);
            color: #ef4444;
        }
    </style>
@endonce

{{-- Кнопка «Пересобрать раунд».

     Нужна, когда счёт предыдущего раунда исправили уже после генерации
     следующего: состав считается от результатов, и раунд оказывается собран
     по неверным данным. Кнопка удаляет последний раунд и строит его заново
     из актуальной таблицы — счёт, введённый в нём, теряется.

     Ждём во входных данных $tournament. Показываем только у идущих турниров
     тех форматов, где состав раунда зависит от результатов, и только начиная
     со второго раунда: первый строится посевом. --}}

@php
    $rebuildable = in_array(
        $tournament->type,
        \App\Http\Controllers\Club\TournamentRoundController::REBUILDABLE,
        true
    );

    $roundsPlayed = match ($tournament->type) {
        'mexicano' => $tournament->mexicanoRounds()->count(),
        'king_of_court' => $tournament->kingOfCourtRounds()->count(),
        'just_padel_it' => $tournament->justPadelItRounds()->count(),
        'bali_koc' => $tournament->baliKocRounds()->count(),
        'escalera' => $tournament->escaleraRounds()->count(),
        default => 0,
    };
@endphp

@if($rebuildable && $tournament->status === 'in_progress' && $roundsPlayed > 1)
    <div class="text-center mt-3">
        <form action="{{ route('club.tournaments.rebuildLastRound', $tournament) }}" method="POST"
              onsubmit="return confirm('Пересобрать раунд {{ $roundsPlayed }} по текущим результатам? Счёт, введённый в этом раунде, будет удалён, а составы пересчитаны заново.')">
            @csrf
            <button type="submit" class="btn-outline-custom">
                <i class="bi bi-arrow-repeat me-1"></i> Пересобрать раунд {{ $roundsPlayed }}
            </button>
        </form>
        <small class="text-secondary d-block mt-2">
            Если поправили счёт прошлого раунда — этот собран по старым данным.
        </small>
    </div>
@endif

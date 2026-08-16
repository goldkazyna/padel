@extends('layouts.app')

@section('title', $tournament->name)

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tournament-show.css') }}?v={{ time() }}">
@endpush

@section('content')
<div class="esc-scope">

{{-- Шапка --}}
<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club->name }} · Ladder</p>
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
            <a href="{{ route('club.escalera.seeding', $tournament) }}" class="btn-primary-custom">
                <i class="bi bi-play-fill"></i> Посев и старт
            </a>
        @endif

        {{-- Завершить можно и не закрывая раунд вручную: finish закроет сам, --}}
        {{-- если все счета внесены. --}}
        @if($tournament->status === 'in_progress' && ($canFinish || $canCloseRound))
            <form action="{{ route('club.escalera.finish', $tournament) }}" method="POST"
                  onsubmit="return confirm('Завершить турнир? Текущий раунд будет закрыт, рейтинг начислен по каждому короткому матчу, счета станет нельзя менять.')">
                @csrf
                <button type="submit" class="btn-primary-custom">
                    <i class="bi bi-trophy-fill"></i> Завершить турнир
                </button>
            </form>
        @endif

        <a href="{{ route('club.tournaments.edit', $tournament) }}" class="btn-outline-custom">
            <i class="bi bi-pencil"></i> Редактировать
        </a>
        <a href="{{ route('club.tournaments.index') }}" class="btn-outline-custom">
            <i class="bi bi-arrow-left"></i> Назад
        </a>
    </div>
</div>

{{-- Ошибки ввода счёта --}}
@if($errors->any())
    <div class="esc-warn mb-4">
        <i class="bi bi-exclamation-triangle me-2"></i>
        @foreach($errors->all() as $message)
            <div>{{ $message }}</div>
        @endforeach
    </div>
@endif

{{-- Информация о турнире --}}
@include('club.tournaments.partials._info')

{{-- Участники --}}
@include('club.tournaments.partials._participants')

@if($tournament->escaleraPlayers->count() > 0)
    @php
        $roundsPlayed = $tournament->escaleraRounds->count();
        $modeLabel = $tournament->escalera_standings_mode === 'raw_points'
            ? 'по сумме очков за матчи'
            : 'по баллам за позиции';
    @endphp

    <div class="esc-note mb-4">
        <i class="bi bi-info-circle me-2"></i>
        <strong>Раундов:</strong> {{ $roundsPlayed }}
        &nbsp;|&nbsp;
        <strong>Кортов:</strong> {{ (int) $tournament->courts_count }}
        &nbsp;|&nbsp;
        <strong>Игроков:</strong> {{ $tournament->escaleraPlayers->count() }}
        &nbsp;|&nbsp;
        <strong>Зачёт:</strong> {{ $modeLabel }}
    </div>

    {{-- Награды (после завершения) --}}
    @if($awards)
        @include('club.tournaments.escalera.partials._awards')
    @endif

    {{-- Итоговая таблица --}}
    @include('club.tournaments.escalera.partials._standings')

    {{-- Корты по раундам --}}
    <div class="section-subheader">
        <i class="bi bi-grid-3x3-gap"></i> Корты
    </div>
    @include('club.tournaments.escalera.partials._courts')

    {{-- Превью закрытия раунда --}}
    @if($canCloseRound && !empty($preview))
        <div class="section-subheader mt-5">
            <i class="bi bi-arrow-down-up"></i> Итоги раунда {{ $currentRound?->round_number }}
        </div>
        <div class="esc-preview">
            @foreach($preview as $courtPreview)
                <div class="esc-preview-court">
                    <div class="esc-preview-court-head">
                        <span class="esc-preview-court-title">Корт {{ $courtPreview['court_number'] }}</span>
                        @if($courtPreview['manual'] ?? false)
                            <span class="esc-manual-badge">места заданы вручную</span>
                            <form action="{{ route('club.escalera.resetCourtPlaces', $courtPreview['court_id']) }}" method="POST">
                                @csrf
                                <button type="submit" class="esc-reset-btn" title="Вернуть расчётный порядок">
                                    <i class="bi bi-arrow-counterclockwise"></i> сбросить
                                </button>
                            </form>
                        @endif
                    </div>
                    @foreach($courtPreview['places'] as $place)
                        <div class="esc-preview-row">
                            <span class="esc-preview-place">{{ $place['place'] }}</span>
                            <span class="esc-preview-name">{{ $place['user']->name ?? '—' }}</span>
                            <span class="esc-preview-points">{{ $place['court_points'] }} оч.</span>
                            <span class="esc-preview-reorder">
                                @foreach(['up' => ['bi-chevron-up', $place['place'] > 1, 'Поднять'], 'down' => ['bi-chevron-down', $place['place'] < 4, 'Опустить']] as $dir => [$icon, $enabled, $title])
                                    @if($enabled)
                                        <form action="{{ route('club.escalera.moveCourtPlace', $courtPreview['court_id']) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ $place['user_id'] }}">
                                            <input type="hidden" name="direction" value="{{ $dir }}">
                                            <button type="submit" class="esc-move-btn" title="{{ $title }}">
                                                <i class="bi {{ $icon }}"></i>
                                            </button>
                                        </form>
                                    @else
                                        <span class="esc-move-btn is-off"><i class="bi {{ $icon }}"></i></span>
                                    @endif
                                @endforeach
                            </span>
                            <span class="esc-preview-move esc-move-{{ $place['movement'] }}">
                                @if($place['movement'] === 'up')
                                    <i class="bi bi-arrow-up"></i> вверх
                                @elseif($place['movement'] === 'down')
                                    <i class="bi bi-arrow-down"></i> вниз
                                @else
                                    <i class="bi bi-dash"></i> остаётся
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        @php $nextRoundNumber = ($currentRound?->round_number ?? 0) + 1; @endphp
        <div class="d-flex gap-3 mt-3 mb-4 flex-wrap">
            <form action="{{ route('club.escalera.closeRound', $tournament) }}" method="POST"
                  onsubmit="return confirm('Сгенерировать раунд {{ $nextRoundNumber }}? Места и баллы текущего раунда будут записаны, игроки разъедутся по кортам.')">
                @csrf
                <button type="submit" class="btn-primary-custom">
                    <i class="bi bi-plus-circle"></i> Сгенерировать раунд {{ $nextRoundNumber }}
                </button>
            </form>
            <form action="{{ route('club.escalera.finish', $tournament) }}" method="POST"
                  onsubmit="return confirm('Завершить турнир? Текущий раунд будет закрыт, рейтинг начислен по каждому короткому матчу, счета станет нельзя менять.')">
                @csrf
                <button type="submit" class="btn-outline-custom">
                    <i class="bi bi-trophy"></i> Завершить турнир
                </button>
            </form>
        </div>
    @endif

    {{-- Действия после закрытия раунда --}}
    @if($canGenerateNext)
        <div class="d-flex gap-3 mt-3 mb-4 flex-wrap">
            <form action="{{ route('club.escalera.nextRound', $tournament) }}" method="POST">
                @csrf
                <button type="submit" class="btn-primary-custom">
                    <i class="bi bi-plus-circle"></i> Следующий раунд
                </button>
            </form>
            <form action="{{ route('club.escalera.finish', $tournament) }}" method="POST"
                  onsubmit="return confirm('Завершить турнир? Рейтинг будет начислен по каждому короткому матчу, счета станет нельзя менять.')">
                @csrf
                <button type="submit" class="btn-outline-custom">
                    <i class="bi bi-trophy"></i> Завершить турнир
                </button>
            </form>
        </div>
    @endif

@endif

</div>

<style>
/* Локальные цвета состояний: заданы отдельно для тёмной и светлой темы,
   чтобы всё оставалось читаемым в обеих. Остальное — переменные темы. */
.esc-scope {
    --esc-warn: #f59e0b;
    --esc-warn-bg: rgba(245, 158, 11, 0.12);
}
body.light-theme .esc-scope {
    --esc-warn: #b45309;
    --esc-warn-bg: rgba(180, 83, 9, 0.10);
}

.esc-note {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-left: 3px solid var(--accent);
    border-radius: 10px;
    padding: 14px 18px;
    color: var(--text-secondary);
}
.esc-warn {
    background: var(--esc-warn-bg);
    border: 1px solid var(--esc-warn);
    border-radius: 10px;
    padding: 14px 18px;
    color: var(--esc-warn);
}

.esc-preview { display: flex; flex-direction: column; gap: 12px; }
.esc-preview-court {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 16px;
}
.esc-preview-court-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}
.esc-preview-court-title { font-weight: 600; color: var(--accent); }
.esc-manual-badge {
    padding: 1px 8px;
    border-radius: 999px;
    background: rgba(250, 204, 21, 0.14);
    color: #facc15;
    font-size: 11px;
    font-weight: 600;
}
.esc-reset-btn {
    background: none;
    border: none;
    padding: 0;
    color: var(--text-secondary);
    font-size: 12px;
    cursor: pointer;
}
.esc-reset-btn:hover { color: var(--text-primary); }
/* Стрелки правки мест: нужны, когда ничью надо рассудить не по рейтингу */
.esc-preview-reorder { display: flex; gap: 2px; }
.esc-move-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 6px;
    background: none;
    color: var(--text-secondary);
    font-size: 12px;
    cursor: pointer;
}
.esc-move-btn:hover { color: var(--text-primary); border-color: rgba(255, 255, 255, 0.3); }
.esc-move-btn.is-off { opacity: 0.25; cursor: default; }
.esc-preview-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 6px 0;
    border-bottom: 1px solid var(--border);
}
.esc-preview-row:last-child { border-bottom: none; }
.esc-preview-place {
    min-width: 26px;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    background: var(--bg-secondary);
    color: var(--text-secondary);
    font-weight: 600;
}
.esc-preview-name { flex: 1; color: var(--text-primary); }
.esc-preview-points { color: var(--text-secondary); }
.esc-preview-move { min-width: 110px; text-align: right; color: var(--text-secondary); }
.esc-move-up { color: var(--accent); }
.esc-move-down { color: var(--esc-warn); }
</style>
@endsection

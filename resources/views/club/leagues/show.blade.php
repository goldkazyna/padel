@extends('layouts.app')

@section('title', $league->name)

@section('content')
@php
    $done = $summary['stages_done'];
    $total = $summary['stages_total'];
    $percent = $total > 0 ? round($done / $total * 100) : 0;
@endphp

<div class="page-header">
    <div>
        <h2>{{ $league->name }}</h2>
        <p>
            Лига · {{ $done }} из {{ $total }} этапов сыграно ·
            {{ $summary['players'] }} {{ trans_choice('участник|участника|участников', $summary['players']) }}
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('club.leagues.edit', $league) }}" class="btn-outline-custom">
            <i class="bi bi-pencil"></i> Изменить
        </a>
        <a href="{{ route('club.leagues.index') }}" class="btn-outline-custom">
            <i class="bi bi-arrow-left"></i> К списку
        </a>
    </div>
</div>

@if(session('success'))
    <div class="alert-success-custom mb-4"><i class="bi bi-check2-circle me-2"></i>{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert-danger-custom mb-4"><i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}</div>
@endif

<div class="league-tabs">
    <button class="league-tab active" data-tab="standings">Таблица</button>
    <button class="league-tab" data-tab="stages">Этапы <span class="tab-count">{{ $league->stages->count() }}</span></button>
    <button class="league-tab" data-tab="players">Состав <span class="tab-count">{{ $summary['players'] }}</span></button>
</div>

{{-- ── Таблица ─────────────────────────────────────────────────────── --}}
<div class="league-pane" id="pane-standings">
    @if(empty($standings))
        <div class="card-dark">
            <div class="card-body text-center py-5 text-secondary">
                <i class="bi bi-table fs-1 mb-3 d-block"></i>
                Таблица появится, когда завершится первый этап.
            </div>
        </div>
    @else
        <div class="card-dark">
            <div class="card-body table-responsive">
                <table class="table-dark-custom league-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Игрок</th>
                            <th title="Этапов сыграно">Э</th>
                            <th title="Побед">В</th>
                            <th title="Поражений">П</th>
                            <th title="Ничьих">Н</th>
                            <th title="Забито">З</th>
                            <th title="Пропущено">Пр</th>
                            <th title="Разница">±</th>
                            <th title="В среднем за матч">Ср</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($standings as $row)
                            <tr>
                                <td class="league-place">{{ $row['position'] }}</td>
                                <td>
                                    <div class="league-player">
                                        @if($row['avatar'])
                                            <img src="{{ $row['avatar'] }}" alt="" class="league-avatar">
                                        @else
                                            <div class="league-avatar league-avatar-empty">
                                                {{ mb_strtoupper(mb_substr($row['name'], 0, 1)) }}
                                            </div>
                                        @endif
                                        <div>
                                            <div class="league-player-name">{{ $row['name'] }}</div>
                                            @if($row['best_place'])
                                                <div class="league-player-meta">лучшее место на этапе: {{ $row['best_place'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $row['stages'] }}</td>
                                <td>{{ $row['wins'] }}</td>
                                <td>{{ $row['losses'] }}</td>
                                <td>{{ $row['draws'] }}</td>
                                <td class="league-points">{{ $row['points_for'] }}</td>
                                <td>{{ $row['points_against'] }}</td>
                                <td>{{ $row['diff'] > 0 ? '+' : '' }}{{ $row['diff'] }}</td>
                                <td>{{ number_format($row['average'], 2, '.', '') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="league-legend">
                    Э — этапов сыграно, В — победы, П — поражения, Н — ничьи,
                    З — забито, Пр — пропущено, ± — разница, Ср — в среднем за матч.
                    Места распределяются по сумме забитых за все этапы; при равенстве —
                    процент побед, затем личные встречи.
                </div>
            </div>
        </div>
    @endif
</div>

{{-- ── Этапы ───────────────────────────────────────────────────────── --}}
<div class="league-pane d-none" id="pane-stages">
    <div class="card-dark mb-3">
        <div class="card-body">
            <div class="league-progress mb-4">
                <div class="league-progress-bar"><span style="width: {{ $percent }}%"></span></div>
                <div class="league-progress-text">Сыграно {{ $done }} из {{ $total }}</div>
            </div>

            @if($league->stages->isEmpty())
                <p class="text-secondary mb-3">Этапов ещё нет. Создайте первый — состав лиги запишется в него сам.</p>
            @else
                <div class="stage-list mb-4">
                    @foreach($league->stages as $stage)
                        <a href="{{ route('club.tournaments.show', $stage) }}" class="stage-row">
                            <div class="stage-num">{{ $stage->league_stage }}</div>
                            <div class="stage-main">
                                <div class="stage-name">{{ $stage->name }}</div>
                                <div class="stage-meta">
                                    {{ $stage->start_date?->locale('ru')->translatedFormat('j MMM, HH:mm') }}
                                    · {{ $stage->status_name }}
                                </div>
                            </div>
                            <i class="bi bi-chevron-right text-secondary"></i>
                        </a>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('club.leagues.stages.add', $league) }}" class="stage-form">
                @csrf
                <div class="row align-items-end">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Название этапа</label>
                        <input type="text" name="name" class="form-control-custom"
                               placeholder="{{ $league->name }} — этап {{ $league->nextStageNumber() }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Дата и время *</label>
                        <input type="datetime-local" name="start_date" class="form-control-custom" required>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Мест *</label>
                        <input type="number" name="max_participants" class="form-control-custom"
                               min="4" max="64" value="{{ $league->max_players ?? 12 }}" required>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Кортов</label>
                        <input type="number" name="courts_count" class="form-control-custom" min="1" max="16" value="2">
                    </div>
                    <div class="col-md-1 mb-3">
                        <button type="submit" class="btn-primary-custom w-100">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                </div>
                <small class="text-secondary">
                    Создастся турнир Americano Flex, привязанный к лиге, а состав лиги запишется в него сразу.
                </small>
            </form>
        </div>
    </div>
</div>

{{-- ── Состав ──────────────────────────────────────────────────────── --}}
<div class="league-pane d-none" id="pane-players">
    <div class="card-dark">
        <div class="card-body">
            <form method="POST" action="{{ route('club.leagues.players.add', $league) }}" class="mb-4 player-add">
                @csrf
                <label class="form-label">Добавить игрока</label>
                <div class="player-search-wrap">
                    <input type="text" id="playerSearch" class="form-control-custom"
                           placeholder="Имя или телефон" autocomplete="off">
                    <input type="hidden" name="user_id" id="playerId">
                    <div class="search-results" id="playerResults"></div>
                </div>
                <button type="submit" class="btn-primary-custom mt-3" id="addPlayerBtn" disabled>
                    <i class="bi bi-person-plus"></i> Добавить в лигу
                </button>
            </form>

            @if($players->isEmpty())
                <p class="text-secondary mb-0">В лиге пока никого нет.</p>
            @else
                <div class="player-list">
                    @foreach($players as $row)
                        <div class="player-row {{ $row->status === 'left' ? 'player-left' : '' }}">
                            @if($row->user?->avatar)
                                <img src="{{ $row->user->avatar }}" alt="" class="league-avatar">
                            @else
                                <div class="league-avatar league-avatar-empty">
                                    {{ mb_strtoupper(mb_substr($row->user->name ?? '?', 0, 1)) }}
                                </div>
                            @endif
                            <div class="player-main">
                                <div class="league-player-name">{{ $row->user->name ?? 'Игрок' }}</div>
                                <div class="league-player-meta">
                                    @if($row->user?->level) L{{ number_format((float) $row->user->level, 2) }} · @endif
                                    {{ $row->user->rating ?? 0 }}
                                    @if($row->status === 'left') · выбыл @endif
                                </div>
                            </div>
                            @if($row->status !== 'left')
                                <form method="POST" action="{{ route('club.leagues.players.remove', [$league, $row->user_id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn-outline-custom btn-sm" title="Убрать из состава">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

<style>
.league-tabs { display: flex; gap: 8px; margin-bottom: 16px; }
.league-tab {
    background: var(--card-bg, #16161a); border: 1px solid rgba(255,255,255,.06);
    color: var(--text-secondary); border-radius: 10px; padding: 9px 16px; cursor: pointer; font-size: 14px;
}
.league-tab.active { background: rgba(34,197,94,.14); border-color: rgba(34,197,94,.35); color: #22c55e; font-weight: 600; }
.tab-count { opacity: .7; margin-left: 4px; }

.league-table th { font-size: 12px; color: var(--text-secondary); white-space: nowrap; }
.league-table td { vertical-align: middle; }
.league-place { font-weight: 700; }
.league-points { font-weight: 700; color: #22c55e; }
.league-player { display: flex; align-items: center; gap: 10px; }
.league-avatar { width: 32px; height: 32px; border-radius: 10px; object-fit: cover; flex: 0 0 auto; }
.league-avatar-empty {
    display: flex; align-items: center; justify-content: center;
    background: rgba(34,197,94,.14); color: #22c55e; font-weight: 700; font-size: 13px;
}
.league-player-name { font-weight: 600; color: #fff; }
.league-player-meta { font-size: 11.5px; color: var(--text-secondary); }
.league-legend { margin-top: 14px; font-size: 12px; color: var(--text-secondary); line-height: 1.5; }

.league-progress-bar { height: 6px; border-radius: 6px; background: rgba(255,255,255,.07); overflow: hidden; }
.league-progress-bar span { display: block; height: 100%; background: #22c55e; }
.league-progress-text { margin-top: 6px; font-size: 12px; color: var(--text-secondary); }

.stage-list { display: grid; gap: 8px; }
.stage-row {
    display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 12px;
    background: rgba(255,255,255,.03); text-decoration: none; color: inherit;
}
.stage-row:hover { background: rgba(34,197,94,.08); }
.stage-num {
    width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    background: rgba(34,197,94,.14); color: #22c55e; font-weight: 700; font-size: 13px; flex: 0 0 auto;
}
.stage-main { flex: 1; min-width: 0; }
.stage-name { font-weight: 600; color: #fff; }
.stage-meta { font-size: 12px; color: var(--text-secondary); }

.player-list { display: grid; gap: 8px; }
.player-row { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,.03); }
.player-left { opacity: .45; }
.player-main { flex: 1; min-width: 0; }
.player-search-wrap { position: relative; }
.search-results {
    display: none; position: absolute; z-index: 20; left: 0; right: 0; top: 100%;
    margin-top: 4px; background: #16161a; border: 1px solid rgba(255,255,255,.08);
    border-radius: 12px; overflow: hidden; max-height: 280px; overflow-y: auto;
}
.search-results.show { display: block; }
.search-result-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; cursor: pointer; }
.search-result-item:hover { background: rgba(34,197,94,.1); }
.search-result-meta { font-size: 12px; color: var(--text-secondary); }
</style>

<script>
// Вкладки: таблица, этапы, состав.
document.querySelectorAll('.league-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.league-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.league-pane').forEach(p => p.classList.add('d-none'));
        tab.classList.add('active');
        document.getElementById('pane-' + tab.dataset.tab).classList.remove('d-none');
    });
});

// Поиск игрока для состава — тот же эндпоинт, что и в остальных местах,
// он уже понимает и «Денис», и «Denis».
(function () {
    const input = document.getElementById('playerSearch');
    const hidden = document.getElementById('playerId');
    const results = document.getElementById('playerResults');
    const button = document.getElementById('addPlayerBtn');
    if (!input) return;

    let timer = null;

    input.addEventListener('input', function () {
        hidden.value = '';
        button.disabled = true;
        clearTimeout(timer);

        const q = input.value.trim();
        if (q.length < 2) { results.classList.remove('show'); return; }

        timer = setTimeout(function () {
            fetch(`{{ route('club.leagues.players.search', $league) }}?q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(data => {
                    const players = data.players || data || [];
                    if (!players.length) {
                        results.innerHTML = '<div class="search-result-item text-secondary">Никого не нашли</div>';
                    } else {
                        results.innerHTML = players.map(p => `
                            <div class="search-result-item" data-id="${p.id}" data-name="${p.name}">
                                ${p.avatar
                                    ? `<img class="league-avatar" src="${p.avatar}" alt="">`
                                    : `<div class="league-avatar league-avatar-empty">${(p.name || '?').charAt(0).toUpperCase()}</div>`}
                                <div>
                                    <div class="league-player-name">${p.name}</div>
                                    <div class="search-result-meta">${p.phone || ''}</div>
                                </div>
                            </div>`).join('');
                    }
                    results.classList.add('show');

                    results.querySelectorAll('.search-result-item[data-id]').forEach(item => {
                        item.addEventListener('click', function () {
                            hidden.value = this.dataset.id;
                            input.value = this.dataset.name;
                            results.classList.remove('show');
                            button.disabled = false;
                        });
                    });
                });
        }, 300);
    });

    document.addEventListener('click', function (e) {
        if (!results.contains(e.target) && e.target !== input) results.classList.remove('show');
    });
})();
</script>
@endsection

@extends('layouts.app')

@section('title', $league->name)

@section('content')
@php
    $done = $summary['stages_done'];
    $total = $summary['stages_total'];
    $percent = $total > 0 ? round($done / $total * 100) : 0;
@endphp

<div class="leagues-container">
    <div class="leagues-header">
        <div>
            <div class="leagues-title">{{ $league->name }}</div>
            <div class="leagues-sub">
                Лига · {{ $done }} из {{ $total }} этапов сыграно ·
                {{ $summary['players'] }} {{ trans_choice('участник|участника|участников', $summary['players']) }}
            </div>
        </div>
        <div class="lg-actions">
            <a href="{{ route('club.leagues.edit', $league) }}" class="btn-ghost">
                <i class="bi bi-pencil"></i> Изменить
            </a>
            <a href="{{ route('club.leagues.index') }}" class="btn-ghost">
                <i class="bi bi-arrow-left"></i> К списку
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif

    <div class="lg-progress-wide">
        <div class="lg-bar"><span style="width: {{ $percent }}%"></span></div>
    </div>

    <div class="league-tabs">
        <button class="tab-link tab-active" data-tab="standings">Таблица</button>
        <button class="tab-link" data-tab="stages">
            Этапы <span class="tab-count">{{ $league->stages->count() }}</span>
        </button>
        <button class="tab-link" data-tab="players">
            Состав <span class="tab-count">{{ $summary['players'] }}</span>
        </button>
    </div>

    {{-- ── Таблица ─────────────────────────────────────────────────────── --}}
    <div class="league-pane" id="pane-standings">
        @if(empty($standings))
            <div class="empty-state">
                <i class="bi bi-table"></i>
                <div class="empty-title">Таблица пока пустая</div>
                <div class="empty-text">Она появится, когда завершится первый этап лиги.</div>
            </div>
        @else
            <div class="lg-panel">
                <div class="leaderboard-table-wrapper">
                    <table class="leaderboard-table">
                        <thead>
                            <tr>
                                <th class="col-rank">#</th>
                                <th class="col-player">Игрок</th>
                                <th class="col-stat" title="Этапов сыграно">Этапов</th>
                                <th class="col-stat" title="Побед">Побед</th>
                                <th class="col-stat" title="Поражений">Пораж.</th>
                                <th class="col-stat" title="Ничьих">Ничьих</th>
                                <th class="col-stat" title="Пропущено очков">Пропущено</th>
                                <th class="col-stat" title="Разница забито − пропущено">Разница</th>
                                <th class="col-points" title="Сумма забитых очков за все этапы — первый критерий таблицы">Забито</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($standings as $row)
                                @php
                                    $rank = $row['position'];
                                    $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
                                @endphp
                                <tr class="{{ $rankClass }}">
                                    <td class="col-rank">
                                        <span class="rank-badge {{ $rankClass }}">{{ $rank }}</span>
                                    </td>
                                    <td class="col-player">
                                        <div class="player-info">
                                            @if($row['avatar'])
                                                <img src="{{ $row['avatar'] }}" alt="" class="player-avatar-img">
                                            @else
                                                <div class="player-avatar">
                                                    {{ mb_strtoupper(mb_substr($row['name'], 0, 1)) }}
                                                </div>
                                            @endif
                                            <div class="player-details">
                                                <div class="player-name">{{ $row['name'] }}</div>
                                                <div class="player-rating">
                                                    {{ $row['rating'] }}
                                                    @if($row['best_place'])
                                                        · лучшее место на этапе: {{ $row['best_place'] }}
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="col-stat">{{ $row['stages'] }}</td>
                                    <td class="col-stat">{{ $row['wins'] }}</td>
                                    <td class="col-stat">{{ $row['losses'] }}</td>
                                    <td class="col-stat">{{ $row['draws'] }}</td>
                                    <td class="col-stat points-against">{{ $row['points_against'] }}</td>
                                    <td class="col-stat {{ $row['diff'] > 0 ? 'points-for' : ($row['diff'] < 0 ? 'points-against' : '') }}">
                                        {{ $row['diff'] > 0 ? '+' : '' }}{{ $row['diff'] }}
                                    </td>
                                    <td class="col-points">{{ $row['points_for'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="lg-legend">
                    Места — по сумме забитых очков за все этапы. При равенстве выше тот,
                    у кого больше процент побед, затем — личные встречи по всем этапам.
                    Незавершённый этап в зачёт не идёт.
                </div>
            </div>
        @endif
    </div>

    {{-- ── Этапы ───────────────────────────────────────────────────────── --}}
    <div class="league-pane d-none" id="pane-stages">
        @if($league->stages->isEmpty())
            <div class="empty-state">
                <i class="bi bi-calendar-plus"></i>
                <div class="empty-title">Этапов ещё нет</div>
                <div class="empty-text">Создайте первый — состав лиги запишется в него автоматически.</div>
            </div>
        @else
            <div class="lg-list">
                @foreach($league->stages as $stage)
                    <a href="{{ route('club.tournaments.show', $stage) }}" class="lg-row">
                        <div class="lg-num {{ $stage->status === 'completed' ? 'done' : '' }}">
                            {{ $stage->league_stage }}
                        </div>
                        <div class="lg-row-main">
                            <div class="lg-row-name">{{ $stage->name }}</div>
                            <div class="lg-row-note">
                                {{ $stage->start_date?->locale('ru')->translatedFormat('j MMMM, HH:mm') }}
                                · {{ $stage->status_name }}
                            </div>
                        </div>
                        <i class="bi bi-chevron-right lg-chevron"></i>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="lg-panel mt-3">
            <div class="lg-panel-title">Новый этап</div>
            @php
                // Формат этапа собираем строкой заранее: инлайновые @if со
                // скобками внутри ломают Blade (проверено на проде).
                $courts = (int) ($league->courts_count ?? 2);
                $stageFormat = [
                    $league->is_paired ? 'Americano Flex (парный)' : 'Americano Flex',
                    $courts . ' ' . trans_choice('корт|корта|кортов', $courts),
                ];
                if ($league->points_to_win) {
                    $stageFormat[] = 'игра до ' . $league->points_to_win;
                }
                if ($league->duration_hours) {
                    $stageFormat[] = $league->duration_hours . ' ч';
                }
            @endphp
            <div class="lg-panel-note">
                Создастся турнир: {{ implode(' · ', $stageFormat) }}. Состав лиги
                запишется сразу. Формат меняется в
                <a href="{{ route('club.leagues.edit', $league) }}" class="lg-inline-link">настройках лиги</a>.
            </div>
            <form method="POST" action="{{ route('club.leagues.stages.add', $league) }}">
                @csrf
                <div class="row align-items-end">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Название этапа</label>
                        <input type="text" name="name" class="form-control"
                               placeholder="{{ $league->name }} — этап {{ $league->nextStageNumber() }}">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Дата и время *</label>
                        <input type="datetime-local" name="start_date" class="form-control" required>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Мест *</label>
                        <input type="number" name="max_participants" class="form-control"
                               min="4" max="64" value="{{ $league->max_players ?? 12 }}" required>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Кортов</label>
                        <input type="number" name="courts_count" class="form-control" min="1" max="16"
                               value="{{ $league->courts_count ?? 2 }}">
                    </div>
                    <div class="col-md-1 mb-3">
                        <button type="submit" class="btn-add w-100 justify-content-center">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ── Состав ──────────────────────────────────────────────────────── --}}
    <div class="league-pane d-none" id="pane-players">
        <div class="lg-panel mb-3">
            <div class="lg-panel-title">Добавить игрока</div>
            <form method="POST" action="{{ route('club.leagues.players.add', $league) }}">
                @csrf
                <div class="player-search-wrap">
                    <input type="text" id="playerSearch" class="form-control"
                           placeholder="Имя или телефон" autocomplete="off">
                    <input type="hidden" name="user_id" id="playerId">
                    <div class="search-results" id="playerResults"></div>
                </div>
                <button type="submit" class="btn-add mt-3" id="addPlayerBtn" disabled>
                    <i class="bi bi-person-plus"></i> Добавить в лигу
                </button>
            </form>

            {{-- Тестовые игроки: заполнить состав для проверки, как в турнире --}}
            <form method="POST" action="{{ route('club.leagues.players.test', $league) }}" class="lg-test-form">
                @csrf
                <button type="submit" class="btn-ghost">
                    <i class="bi bi-people"></i> Добавить тестовых игроков
                </button>
                <span class="lg-test-note">
                    Заполнит состав аккаунтами 1@gmail.com … 32@gmail.com — для проверки лиги.
                </span>
            </form>
        </div>

        @if($players->isEmpty())
            <div class="empty-state">
                <i class="bi bi-people"></i>
                <div class="empty-title">В лиге пока никого нет</div>
                <div class="empty-text">Добавьте игроков — они попадут в состав всех этапов.</div>
            </div>
        @else
            <div class="lg-list">
                @foreach($players as $row)
                    <div class="lg-row {{ $row->status === 'left' ? 'lg-left' : '' }}">
                        @if($row->user?->avatar)
                            <img src="{{ $row->user->avatar }}" alt="" class="player-avatar-img">
                        @else
                            <div class="player-avatar">
                                {{ mb_strtoupper(mb_substr($row->user->name ?? '?', 0, 1)) }}
                            </div>
                        @endif
                        <div class="lg-row-main">
                            <div class="lg-row-name">{{ $row->user->name ?? 'Игрок' }}</div>
                            <div class="lg-row-note">
                                @if($row->user?->level) L{{ number_format((float) $row->user->level, 2) }} · @endif
                                {{ $row->user->rating ?? 0 }}
                                @if($row->status === 'left') · выбыл @endif
                            </div>
                        </div>
                        @if($row->status !== 'left')
                            <form method="POST" action="{{ route('club.leagues.players.remove', [$league, $row->user_id]) }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn-icon" title="Убрать из состава">
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

<style>
.leagues-container { max-width: 1200px; margin: 0 auto; padding: 24px 16px 40px; }
.leagues-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 16px; }
.leagues-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
.leagues-sub { color: #71717a; font-size: 13px; margin-top: 4px; }
.lg-actions { display: flex; gap: 8px; }
.btn-add { display: inline-flex; align-items: center; gap: 8px; background: #22c55e; color: #0a0a0b; border: none; padding: 12px 22px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; text-decoration: none; transition: background 0.2s; }
.btn-add:hover { background: #16a34a; color: #0a0a0b; }
.btn-add:disabled { opacity: .5; cursor: not-allowed; }
.btn-ghost { display: inline-flex; align-items: center; gap: 7px; background: #15181A; border: 1px solid rgba(255,255,255,0.08); color: #a1a1aa; padding: 11px 16px; border-radius: 10px; font-size: 13.5px; text-decoration: none; }
.btn-ghost:hover { color: #f4f6f7; border-color: rgba(255,255,255,0.16); }
.btn-icon { background: transparent; border: 1px solid rgba(255,255,255,0.08); color: #7c848a; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; }
.btn-icon:hover { color: #ef4444; border-color: rgba(239,68,68,0.35); }

.flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
.flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
.flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

.lg-progress-wide { margin-bottom: 18px; }
.lg-bar { height: 6px; border-radius: 6px; background: rgba(255,255,255,0.07); overflow: hidden; }
.lg-bar span { display: block; height: 100%; background: #22c55e; }

.league-tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid #27272a; }
.tab-link { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: transparent; color: #71717a; border: none; border-bottom: 2px solid transparent; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.15s; margin-bottom: -1px; }
.tab-link:hover { color: #a1a1aa; }
.tab-link.tab-active { color: #22c55e; border-bottom-color: #22c55e; }
.tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 20px; padding: 0 6px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; font-size: 11px; font-weight: 700; color: #a1a1aa; }
.tab-link.tab-active .tab-count { background: rgba(34,197,94,0.15); border-color: rgba(34,197,94,0.3); color: #22c55e; }

.lg-panel { background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; padding: 18px; }
.lg-panel-title { font-size: 15px; font-weight: 700; color: #f4f6f7; margin-bottom: 4px; }
.lg-panel-note { font-size: 13px; color: #7c848a; margin-bottom: 14px; }
.lg-legend { margin-top: 14px; font-size: 12.5px; color: #7c848a; line-height: 1.5; }
.lg-inline-link { color: #22c55e; text-decoration: none; }

/* Таблица — как в турнирах Americano Flex */
.leaderboard-table-wrapper { overflow-x: auto; }
.leaderboard-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.leaderboard-table thead { background: rgba(255,255,255,0.05); }
.leaderboard-table th { padding: 12px 8px; text-align: center; font-weight: 600; color: #a1a1aa; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid rgba(255,255,255,0.08); }
.leaderboard-table th.col-player { text-align: left; }
.leaderboard-table td { padding: 12px 8px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); }
.leaderboard-table tr:hover { background: rgba(255,255,255,0.03); }
.leaderboard-table tr.gold { background: rgba(255,215,0,0.08); }
.leaderboard-table tr.silver { background: rgba(192,192,192,0.08); }
.leaderboard-table tr.bronze { background: rgba(205,127,50,0.08); }
.col-rank { width: 50px; }
.col-player { text-align: left !important; min-width: 200px; }
.col-stat { width: 84px; }
.col-points { width: 90px; font-weight: 800; color: #22c55e; }
.points-for { color: #22c55e; }
.points-against { color: #a1a1aa; }
.rank-badge { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; background: rgba(255,255,255,0.06); font-size: 12px; font-weight: 800; color: #a1a1aa; }
.rank-badge.gold { background: rgba(255,215,0,0.18); color: #ffd700; }
.rank-badge.silver { background: rgba(192,192,192,0.18); color: #c0c0c0; }
.rank-badge.bronze { background: rgba(205,127,50,0.18); color: #cd7f32; }
.player-info { display: flex; align-items: center; gap: 10px; }
.player-avatar, .player-avatar-img { width: 34px; height: 34px; border-radius: 10px; flex: 0 0 auto; }
.player-avatar { display: flex; align-items: center; justify-content: center; background: rgba(34,197,94,0.14); color: #22c55e; font-weight: 800; font-size: 13px; }
.player-avatar-img { object-fit: cover; }
.player-name { font-weight: 700; color: #f4f6f7; }
.player-rating { font-size: 11.5px; color: #7c848a; }

.lg-list { display: grid; gap: 8px; }
.lg-row { display: flex; align-items: center; gap: 12px; background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; padding: 12px 14px; text-decoration: none; color: inherit; transition: border-color 0.15s; }
a.lg-row:hover { border-color: rgba(34,197,94,0.35); }
.lg-left { opacity: .45; }
.lg-num { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.06); color: #a1a1aa; font-weight: 800; font-size: 13px; flex: 0 0 auto; }
.lg-num.done { background: rgba(34,197,94,0.14); color: #22c55e; }
.lg-row-main { flex: 1; min-width: 0; }
.lg-row-name { font-weight: 700; color: #f4f6f7; }
.lg-row-note { font-size: 12.5px; color: #7c848a; margin-top: 2px; }
.lg-chevron { color: #52525b; }

.empty-state { background: #15181A; border: 1px dashed rgba(255,255,255,0.10); border-radius: 16px; padding: 56px 24px; text-align: center; }
.empty-state i { font-size: 30px; color: #3f3f46; display: block; margin-bottom: 14px; }
.empty-title { font-size: 16px; font-weight: 700; color: #f4f6f7; }
.empty-text { font-size: 13.5px; color: #7c848a; margin: 8px auto 0; max-width: 460px; line-height: 1.5; }

.lg-test-form { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.06); }
.lg-test-note { font-size: 12.5px; color: #7c848a; }
.player-search-wrap { position: relative; }
.search-results { display: none; position: absolute; z-index: 20; left: 0; right: 0; top: 100%; margin-top: 4px; background: #15181A; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; overflow: hidden; max-height: 300px; overflow-y: auto; }
.search-results.show { display: block; }
.search-result-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; cursor: pointer; }
.search-result-item:hover { background: rgba(34,197,94,0.1); }
.search-result-meta { font-size: 12px; color: #7c848a; }
</style>

<script>
// Вкладки: таблица, этапы, состав.
document.querySelectorAll('.tab-link').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.tab-link').forEach(t => t.classList.remove('tab-active'));
        document.querySelectorAll('.league-pane').forEach(p => p.classList.add('d-none'));
        tab.classList.add('tab-active');
        document.getElementById('pane-' + tab.dataset.tab).classList.remove('d-none');
    });
});

// Поиск игрока для состава: тот же умный поиск, что и в остальных местах.
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
                    const players = data.players || [];
                    if (!players.length) {
                        results.innerHTML = '<div class="search-result-item search-result-meta">Никого не нашли</div>';
                    } else {
                        results.innerHTML = players.map(p => `
                            <div class="search-result-item" data-id="${p.id}" data-name="${p.name}">
                                ${p.avatar
                                    ? `<img class="player-avatar-img" src="${p.avatar}" alt="">`
                                    : `<div class="player-avatar">${(p.name || '?').charAt(0).toUpperCase()}</div>`}
                                <div>
                                    <div class="player-name">${p.name}</div>
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

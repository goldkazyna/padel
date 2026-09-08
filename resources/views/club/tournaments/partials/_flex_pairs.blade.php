{{--
    Состав парного Americano Flex: пары строками, ниже — те, кто в турнире, но
    места в паре пока не занял.

    Каждый игрок показан ровно один раз. Заявка на модерации в открытых парах
    держит место в сетке — значит её видно на этом месте с пометкой, а не
    отдельным списком-двойником. Лист ожидания — вне состава и вне пар.

    Ждёт $tournament.
--}}
@php
    $maxPairs = (int) floor($tournament->max_participants / 2);
    $pairs = $tournament->teams()
        ->whereIn('status', ['approved', 'pending'])
        ->with(['player1', 'player2'])
        ->orderBy('id')
        ->get();

    // Статус каждого участника: показываем его прямо на месте в паре.
    $statusOf = $tournament->participants()
        ->wherePivotIn('status', ['registered', 'pending', 'waiting'])
        ->get()
        ->mapWithKeys(fn ($u) => [(int) $u->id => $u->pivot->status]);

    $pairedIds = $pairs->flatMap(fn ($p) => [$p->player1_id, $p->player2_id])
        ->filter()->map(fn ($id) => (int) $id)->unique();

    $poolOf = fn (string $status) => $tournament->participants()
        ->wherePivot('status', $status)
        ->orderBy('tournament_participants.created_at')
        ->get()
        ->reject(fn ($u) => $pairedIds->contains((int) $u->id));

    $unpaired = $poolOf('registered');
    $pendingPool = $poolOf('pending');
    $waitingPool = $poolOf('waiting');

    $pendingTotal = $statusOf->filter(fn ($s) => $s === 'pending')->count();
    $waitingTotal = $statusOf->filter(fn ($s) => $s === 'waiting')->count();
    $playersIn = $statusOf->filter(fn ($s) => $s !== 'waiting')->count();

    $emptyRows = max(0, $maxPairs - $pairs->count());
    $canEdit = $tournament->status === 'open';
    $canCreatePair = $canEdit && $pairs->count() < $maxPairs;

    // Все места сетки для меню «Пересадить»: занятые (обмен) и свободные.
    $seatOptions = collect();
    foreach ($pairs as $index => $pair) {
        $seatOptions->push((object) [
            'teamId' => (int) $pair->id,
            'seat' => 1,
            'position' => $index + 1,
            'userId' => (int) $pair->player1_id,
            'name' => $pair->player1->name ?? '—',
            'soloPair' => $pair->player2_id === null,
        ]);
        $seatOptions->push((object) [
            'teamId' => (int) $pair->id,
            'seat' => 2,
            'position' => $index + 1,
            'userId' => $pair->player2_id ? (int) $pair->player2_id : null,
            'name' => $pair->player2->name ?? ($pair->player1->name ?? '—'),
            'soloPair' => $pair->player2_id === null,
        ]);
    }

    $menuArgs = fn ($player) => [
        'tournament' => $tournament,
        'player' => $player,
        'current' => $statusOf[(int) $player->id] ?? 'registered',
        'seatOptions' => $seatOptions,
        'canCreatePair' => $canCreatePair,
    ];
@endphp

<div class="flexp">
    <div class="flexp-bar">
        <h3><i class="bi bi-people"></i> Состав</h3>
        <span class="flexp-chip ok">пар {{ $pairs->count() }} / {{ $maxPairs }}</span>
        <span class="flexp-chip">игроков {{ $playersIn }} / {{ $tournament->max_participants }}</span>
        @if($pendingTotal > 0)
            <span class="flexp-chip warn">{{ $pendingTotal }} на модерации</span>
        @endif
        @if($waitingTotal > 0)
            <span class="flexp-chip blue">{{ $waitingTotal }} в листе ожидания</span>
        @endif
        <span class="flexp-spacer"></span>
        @if($canEdit && $unpaired->count() + $pendingPool->count() >= 2)
            <form action="{{ route('club.tournaments.pairing.auto', $tournament) }}" method="POST" class="d-inline m-0">
                @csrf
                <button class="btn-outline-custom btn-sm"><i class="bi bi-shuffle"></i> Авто-пары</button>
            </form>
        @endif
    </div>

    {{-- Пары --}}
    @foreach($pairs as $i => $pair)
        <div class="flexp-row">
            <div class="flexp-no">{{ $i + 1 }}</div>

            @foreach([$pair->player1, $pair->player2] as $seatPlayer)
                @if($seatPlayer)
                    @php $seatStatus = $statusOf[(int) $seatPlayer->id] ?? 'registered'; @endphp
                    <div class="flexp-seat flexp-seat-{{ $seatStatus }}">
                        @include('club.tournaments.partials._player_avatar', ['player' => $seatPlayer])
                        <div class="flexp-seat-info">
                            <div class="flexp-name">
                                {{ $seatPlayer->name }}
                                @if($seatStatus === 'pending')
                                    <span class="flexp-tag warn">на модерации</span>
                                @elseif($seatStatus === 'waiting')
                                    <span class="flexp-tag blue">лист ожидания</span>
                                @endif
                            </div>
                            <div class="flexp-meta">@phoneFmt($seatPlayer->phone ?? '') · {{ $seatPlayer->level }}</div>
                        </div>
                        <div class="flexp-rating">{{ $seatPlayer->rating }}</div>
                        @if($canEdit)
                            @include('club.tournaments.partials._flex_player_menu', $menuArgs($seatPlayer))
                        @endif
                    </div>
                @elseif($canEdit)
                    <button type="button" class="flexp-seat flexp-free" onclick="togglePairFill({{ $pair->id }})">
                        <span class="flexp-plus">+</span> Посадить второго
                    </button>
                @else
                    <div class="flexp-seat flexp-empty">Место свободно</div>
                @endif
            @endforeach

            <div class="flexp-tail">
                <span class="flexp-avg">{{ $pair->player2 ? 'ср. ' . $pair->rating_avg : '—' }}</span>
                @if($canEdit)
                    <form action="{{ route('club.tournaments.rejectTeam', [$tournament, $pair]) }}" method="POST"
                          class="m-0" onsubmit="return confirm('Убрать пару из турнира?')">
                        @csrf
                        <button class="flexp-x" title="Распустить пару"><i class="bi bi-x-lg"></i></button>
                    </form>
                @endif
            </div>
        </div>

        @if($canEdit && !$pair->player2)
            <div class="pair-fill" id="pairFill{{ $pair->id }}" style="display: none;">
                <form action="{{ route('club.tournaments.pairs.fill', [$tournament, $pair]) }}" method="POST">
                    @csrf
                    <div class="search-wrapper">
                        <input type="text" class="form-control player-search-input"
                               data-target="pairFillP{{ $pair->id }}" data-mode="pair"
                               placeholder="Кого посадить: имя или телефон…" autocomplete="off">
                        <input type="hidden" name="player_id" id="pairFillP{{ $pair->id }}PlayerId">
                        <div class="search-results" id="pairFillP{{ $pair->id }}Results"></div>
                    </div>
                    <div class="selected-player mt-2" id="pairFillP{{ $pair->id }}Selected" style="display: none;"></div>
                    <button type="submit" class="btn-primary-custom btn-sm mt-2">
                        <i class="bi bi-check-lg me-1"></i> Посадить в пару
                    </button>
                </form>
            </div>
        @endif
    @endforeach

    {{-- Пустые пары: турнир видно целиком, а не только начатую часть --}}
    @for($i = 0; $i < $emptyRows; $i++)
        <div class="flexp-row flexp-row-empty">
            <div class="flexp-no">{{ $pairs->count() + $i + 1 }}</div>
            <div class="flexp-seat flexp-empty">Свободно</div>
            <div class="flexp-seat flexp-empty">Свободно</div>
            <div class="flexp-tail"><span class="flexp-avg">—</span></div>
        </div>
    @endfor

    {{-- Без пары: в составе есть, места в сетке не заняли --}}
    @if($unpaired->count() > 0)
        <div class="flexp-pool flexp-pool-blue">
            <div class="flexp-pool-head">
                <i class="bi bi-person-exclamation"></i>
                <span>Без пары</span>
                <span class="flexp-pool-count">{{ $unpaired->count() }}</span>
                <span class="flexp-pool-hint">в составе, но места в сетке нет — посадите к кому-то или в пустую пару</span>
            </div>
            <div class="flexp-chips">
                @foreach($unpaired as $player)
                    <div class="flexp-chip-player">
                        @include('club.tournaments.partials._player_avatar', ['player' => $player])
                        <div class="flexp-seat-info">
                            <div class="flexp-name">{{ $player->name }}</div>
                            <div class="flexp-meta">{{ $player->level }} · {{ $player->rating }}</div>
                        </div>
                        @if($canEdit)
                            <form action="{{ route('club.tournaments.pairs.seat', [$tournament, $player->id]) }}" method="POST" class="m-0">
                                @csrf
                                <button class="flexp-put" title="Посадить в первое свободное место">посадить</button>
                            </form>
                            @include('club.tournaments.partials._flex_player_menu', $menuArgs($player))
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- На модерации без места: заявка есть, а сетка занята --}}
    @if($pendingPool->count() > 0)
        <div class="flexp-pool flexp-pool-amber">
            <div class="flexp-pool-head">
                <i class="bi bi-hourglass-split"></i>
                <span>На модерации, без пары</span>
                <span class="flexp-pool-count">{{ $pendingPool->count() }}</span>
                <span class="flexp-pool-hint">заявка ждёт решения, места в сетке пока нет</span>
                <span class="flexp-spacer"></span>
                @if($canEdit)
                    <form action="{{ route('club.tournaments.participants.approveAll', $tournament) }}" method="POST" class="m-0"
                          onsubmit="return confirm('Одобрить все заявки?')">
                        @csrf
                        <button class="btn-outline-custom btn-sm"><i class="bi bi-check-all"></i> Одобрить все</button>
                    </form>
                @endif
            </div>
            <div class="flexp-chips">
                @foreach($pendingPool as $player)
                    <div class="flexp-chip-player">
                        @include('club.tournaments.partials._player_avatar', ['player' => $player])
                        <div class="flexp-seat-info">
                            <div class="flexp-name">{{ $player->name }}</div>
                            <div class="flexp-meta">{{ $player->level }} · {{ $player->rating }}</div>
                        </div>
                        @if($canEdit)
                            <form action="{{ route('club.tournaments.participants.approve', [$tournament, $player->id]) }}" method="POST" class="m-0">
                                @csrf
                                <button class="flexp-ok" title="Одобрить"><i class="bi bi-check-lg"></i></button>
                            </form>
                            @include('club.tournaments.partials._flex_player_menu', $menuArgs($player))
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Лист ожидания: вне состава и вне пар --}}
    @if($waitingPool->count() > 0)
        <div class="flexp-pool flexp-pool-grey">
            <div class="flexp-pool-head">
                <i class="bi bi-hourglass"></i>
                <span>Лист ожидания</span>
                <span class="flexp-pool-count">{{ $waitingPool->count() }}</span>
                <span class="flexp-pool-hint">вне состава — посадите в пару, когда освободится место</span>
            </div>
            <div class="flexp-chips">
                @foreach($waitingPool as $player)
                    <div class="flexp-chip-player">
                        @include('club.tournaments.partials._player_avatar', ['player' => $player])
                        <div class="flexp-seat-info">
                            <div class="flexp-name">{{ $player->name }}</div>
                            <div class="flexp-meta">{{ $player->level }} · {{ $player->rating }}</div>
                        </div>
                        @if($canEdit)
                            <form action="{{ route('club.tournaments.pairs.seat', [$tournament, $player->id]) }}" method="POST" class="m-0">
                                @csrf
                                <button class="flexp-put" title="Посадить в первое свободное место">посадить</button>
                            </form>
                            @include('club.tournaments.partials._flex_player_menu', $menuArgs($player))
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Добавить игрока --}}
    @if($canEdit)
        <div class="flexp-add">
            <div class="flexp-add-title"><i class="bi bi-person-plus"></i> Добавить игрока</div>
            <form action="{{ route('club.tournaments.participants.add', $tournament) }}" method="POST">
                @csrf
                <div class="search-wrapper">
                    <input type="text" class="form-control player-search-input"
                           data-target="flexAdd" placeholder="Имя или телефон…" autocomplete="off">
                    <input type="hidden" name="user_id" id="flexAddPlayerId">
                    <div class="search-results" id="flexAddResults"></div>
                </div>
                <div class="selected-player mt-2" id="flexAddSelected" style="display: none;"></div>
                <button type="submit" class="btn-primary-custom btn-sm mt-2">
                    <i class="bi bi-plus-lg me-1"></i> Добавить
                </button>
            </form>
            <div class="flexp-add-hint">Игрок попадёт в «Без пары» — оттуда посадите его в пару.</div>
        </div>
    @endif
</div>

<style>
/* Крупнее прежнего в полтора раза: состав читают через весь экран, а не
   носом в монитор. */
.flexp{margin-bottom:18px}
.flexp-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.flexp-bar h3{font-size:19px;font-weight:700;color:#fff;margin:0}
.flexp-spacer{flex:1}
.flexp-chip{font-size:14px;font-weight:700;border-radius:999px;padding:4px 12px;
    background:rgba(255,255,255,.06);color:#9aa1a9}
.flexp-chip.ok{background:rgba(34,197,94,.15);color:#22C55E}
.flexp-chip.warn{background:rgba(234,179,78,.15);color:#EAB34E}
.flexp-chip.blue{background:rgba(91,155,255,.15);color:#8FBBFF}

.flexp-row{display:grid;grid-template-columns:34px minmax(0,1fr) minmax(0,1fr) 150px;
    gap:12px;align-items:center;background:#171A1E;border:1px solid var(--border);
    border-radius:12px;padding:10px 12px;margin-bottom:8px}
.flexp-row-empty{opacity:.5}
.flexp-no{color:#6a7178;font-size:16px;font-weight:800;text-align:center}

.flexp-seat{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:10px;
    background:#1E2227;min-height:60px;width:100%;text-align:left;min-width:0}
.flexp-seat-pending{box-shadow:inset 3px 0 0 #EAB34E}
.flexp-seat-waiting{box-shadow:inset 3px 0 0 #8FBBFF}
.flexp-seat .player-avatar{width:40px;height:40px;font-size:15px;flex:0 0 auto}
.flexp-seat-info{min-width:0;flex:1}
.flexp-name{font-size:16px;font-weight:600;color:#EDEFF2;white-space:nowrap;
    overflow:hidden;text-overflow:ellipsis}
.flexp-meta{font-size:13.5px;color:#9aa1a9;margin-top:2px}
.flexp-rating{font-size:16px;font-weight:800;color:#9aa1a9;flex:0 0 auto}
.flexp-tag{font-size:11.5px;font-weight:700;border-radius:999px;padding:1px 8px;
    margin-left:6px;vertical-align:middle}
.flexp-tag.warn{background:rgba(234,179,78,.16);color:#EAB34E}
.flexp-tag.blue{background:rgba(91,155,255,.16);color:#8FBBFF}

.flexp-tail{display:flex;align-items:center;justify-content:flex-end;gap:10px}
.flexp-avg{color:#9aa1a9;font-size:14px;white-space:nowrap}

.flexp-free{background:transparent;border:1px dashed rgba(34,197,94,.45);color:#22C55E;
    font-size:15px;font-weight:600;cursor:pointer}
.flexp-free:hover{background:rgba(34,197,94,.08)}
.flexp-plus{width:32px;height:32px;border-radius:50%;background:rgba(34,197,94,.16);
    display:grid;place-items:center;font-size:18px;font-weight:700;flex:0 0 auto}
.flexp-empty{border:1px dashed rgba(255,255,255,.12);background:transparent;
    color:#6a7178;font-size:15px}

.flexp-dots{border:1px solid var(--border);background:transparent;color:#9aa1a9;
    border-radius:8px;width:32px;height:32px;display:grid;place-items:center;flex:0 0 auto}
.flexp-dots:hover{color:#fff;border-color:rgba(255,255,255,.25)}
.flexp-menu .dropdown-menu{min-width:300px;max-height:60vh;overflow-y:auto}
.flexp-menu .dropdown-item{font-size:14px;display:flex;align-items:center;gap:8px}
.flexp-menu .dropdown-header{font-size:11.5px;letter-spacing:.6px;text-transform:uppercase}
.flexp-menu-note{font-size:12px;color:#6a7178;margin-left:auto;padding-left:10px}

.flexp-pool{border-radius:12px;padding:14px 16px;margin:14px 0}
.flexp-pool-blue{background:rgba(91,155,255,.08);border:1px solid rgba(91,155,255,.35)}
.flexp-pool-amber{background:rgba(234,179,78,.08);border:1px solid rgba(234,179,78,.35)}
.flexp-pool-grey{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.14)}
.flexp-pool-head{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:12px;
    font-weight:700;font-size:16px}
.flexp-pool-blue .flexp-pool-head{color:#8FBBFF}
.flexp-pool-amber .flexp-pool-head{color:#EAB34E}
.flexp-pool-grey .flexp-pool-head{color:#c9cfd6}
.flexp-pool-count{font-size:13.5px;font-weight:800;border-radius:999px;padding:2px 10px;
    background:rgba(255,255,255,.1)}
.flexp-pool-hint{font-weight:400;font-size:13.5px;color:#9aa1a9}

.flexp-chips{display:flex;flex-wrap:wrap;gap:10px}
.flexp-chip-player{display:flex;align-items:center;gap:10px;background:#1E2227;
    border:1px solid var(--border);border-radius:999px;padding:7px 10px 7px 7px;min-width:260px}
.flexp-chip-player .player-avatar{width:34px;height:34px;font-size:13px;flex:0 0 auto}
.flexp-put{border:none;background:rgba(34,197,94,.16);color:#22C55E;border-radius:999px;
    font-size:13.5px;font-weight:700;padding:6px 14px}
.flexp-put:hover{background:rgba(34,197,94,.28)}
.flexp-ok{border:none;background:rgba(34,197,94,.16);color:#22C55E;border-radius:8px;
    width:32px;height:32px;display:grid;place-items:center;font-size:15px}
.flexp-x{border:none;background:rgba(240,85,77,.14);color:#F0554D;border-radius:8px;
    width:32px;height:32px;display:grid;place-items:center;font-size:15px}

.flexp-add{background:rgba(255,255,255,.03);border:1px solid var(--border);
    border-radius:12px;padding:14px 16px;margin-top:14px}
.flexp-add-title{font-size:15px;font-weight:700;color:#22C55E;margin-bottom:10px}
.flexp-add-hint{font-size:13px;color:#6a7178;margin-top:8px}

@media (max-width: 1100px){
    .flexp-row{grid-template-columns:28px minmax(0,1fr) minmax(0,1fr) 110px;gap:8px}
    .flexp-name{font-size:15px}
}
@media (max-width: 860px){
    .flexp-row{grid-template-columns:28px minmax(0,1fr);grid-auto-rows:auto}
    .flexp-tail{grid-column:1 / -1;justify-content:space-between}
    .flexp-chip-player{min-width:0;width:100%}
}
</style>

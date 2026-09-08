{{--
    Состав парного Americano Flex: пары строками, ниже — пул тех, кому пары
    ещё нет, и заявки на модерации.

    Раньше здесь был плоский список участников плюс четыре формы подряд:
    организатор не видел, кто с кем, а плюс у неполной пары терялся в общем
    ряду кнопок справа. Теперь свободное место — само по себе кнопка.

    Ждёт $tournament.
--}}
@php
    $maxPairs = (int) floor($tournament->max_participants / 2);
    $pairs = $tournament->teams()
        ->whereIn('status', ['approved', 'pending'])
        ->with(['player1', 'player2'])
        ->orderBy('id')
        ->get();

    $pairedIds = $pairs->flatMap(fn ($p) => [$p->player1_id, $p->player2_id])->filter()->unique();
    $unpaired = $tournament->approvedParticipants->reject(fn ($u) => $pairedIds->contains($u->id));
    $pending = $tournament->pendingParticipants;

    $playersIn = $pairedIds->count() + $unpaired->count();
    $emptyRows = max(0, $maxPairs - $pairs->count());
    $canEdit = $tournament->status === 'open';
@endphp

<div class="flexp">
    <div class="flexp-bar">
        <h3><i class="bi bi-people"></i> Состав</h3>
        <span class="flexp-chip ok">пар {{ $pairs->count() }} / {{ $maxPairs }}</span>
        <span class="flexp-chip">игроков {{ $playersIn }} / {{ $tournament->max_participants }}</span>
        @if($pending->count() > 0)
            <span class="flexp-chip warn">{{ $pending->count() }} на модерации</span>
        @endif
        <span class="flexp-spacer"></span>
        @if($canEdit && $unpaired->count() >= 2)
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

            <div class="flexp-seat">
                @include('club.tournaments.partials._player_avatar', ['user' => $pair->player1, 'size' => 30])
                <div class="flexp-seat-info">
                    <div class="flexp-name">{{ $pair->player1->name ?? '—' }}</div>
                    <div class="flexp-meta">@phoneFmt($pair->player1->phone ?? '') · {{ $pair->player1->level }}</div>
                </div>
                <div class="flexp-rating">{{ $pair->player1->rating }}</div>
            </div>

            @if($pair->player2)
                <div class="flexp-seat">
                    @include('club.tournaments.partials._player_avatar', ['user' => $pair->player2, 'size' => 30])
                    <div class="flexp-seat-info">
                        <div class="flexp-name">{{ $pair->player2->name }}</div>
                        <div class="flexp-meta">@phoneFmt($pair->player2->phone ?? '') · {{ $pair->player2->level }}</div>
                    </div>
                    <div class="flexp-rating">{{ $pair->player2->rating }}</div>
                </div>
            @elseif($canEdit)
                <button type="button" class="flexp-seat flexp-free" onclick="togglePairFill({{ $pair->id }})">
                    <span class="flexp-plus">+</span> Посадить второго
                </button>
            @else
                <div class="flexp-seat flexp-empty">Место свободно</div>
            @endif

            <div class="flexp-avg">
                {{ $pair->player2 ? 'ср. ' . $pair->rating_avg : '—' }}
            </div>

            @if($canEdit)
                <form action="{{ route('club.tournaments.rejectTeam', [$tournament, $pair]) }}" method="POST"
                      class="flexp-del" onsubmit="return confirm('Убрать пару из турнира?')">
                    @csrf
                    <button class="btn-danger-custom btn-sm" title="Убрать пару"><i class="bi bi-x"></i></button>
                </form>
            @endif
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
            <div class="flexp-avg">—</div>
            <div></div>
        </div>
    @endfor

    {{-- Без пары: подсвечено синим — это не проблема, но и не готовый состав --}}
    @if($unpaired->count() > 0)
        <div class="flexp-pool flexp-pool-blue">
            <div class="flexp-pool-head">
                <i class="bi bi-person-exclamation"></i>
                <span>Без пары</span>
                <span class="flexp-pool-count">{{ $unpaired->count() }}</span>
                <span class="flexp-pool-hint">записаны, но пары ещё нет — посадите их к кому-то или в пустую</span>
            </div>
            <div class="flexp-chips">
                @foreach($unpaired as $player)
                    <div class="flexp-chip-player">
                        @include('club.tournaments.partials._player_avatar', ['user' => $player, 'size' => 24])
                        <div>
                            <div class="flexp-name">{{ $player->name }}</div>
                            <div class="flexp-meta">{{ $player->level }} · {{ $player->rating }}</div>
                        </div>
                        @if($canEdit)
                            <form action="{{ route('club.tournaments.pairs.seat', [$tournament, $player->id]) }}" method="POST" class="m-0">
                                @csrf
                                <button class="flexp-put" title="Посадить в первое свободное место">посадить</button>
                            </form>
                            <form action="{{ route('club.tournaments.participants.remove', [$tournament, $player->id]) }}"
                                  method="POST" class="m-0" onsubmit="return confirm('Убрать из турнира?')">
                                @csrf
                                @method('DELETE')
                                <button class="flexp-x" title="Убрать из турнира"><i class="bi bi-x"></i></button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- На модерации: жёлтым, тут ждут решения организатора --}}
    @if($pending->count() > 0)
        <div class="flexp-pool flexp-pool-amber">
            <div class="flexp-pool-head">
                <i class="bi bi-hourglass-split"></i>
                <span>На модерации</span>
                <span class="flexp-pool-count">{{ $pending->count() }}</span>
                <span class="flexp-pool-hint">ждут вашего решения — одобренные попадут в «Без пары»</span>
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
                @foreach($pending as $player)
                    <div class="flexp-chip-player">
                        @include('club.tournaments.partials._player_avatar', ['user' => $player, 'size' => 24])
                        <div>
                            <div class="flexp-name">{{ $player->name }}</div>
                            <div class="flexp-meta">{{ $player->level }} · {{ $player->rating }}</div>
                        </div>
                        @if($canEdit)
                            <form action="{{ route('club.tournaments.participants.approve', [$tournament, $player->id]) }}" method="POST" class="m-0">
                                @csrf
                                <button class="flexp-ok" title="Одобрить"><i class="bi bi-check-lg"></i></button>
                            </form>
                            <form action="{{ route('club.tournaments.participants.reject', [$tournament, $player->id]) }}" method="POST" class="m-0"
                                  onsubmit="return confirm('Отклонить заявку?')">
                                @csrf
                                <button class="flexp-x" title="Отклонить"><i class="bi bi-x"></i></button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Добавить игрока / пару целиком --}}
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
.flexp{margin-bottom:18px}
.flexp-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.flexp-bar h3{font-size:15px;font-weight:700;color:#fff;margin:0}
.flexp-spacer{flex:1}
.flexp-chip{font-size:11.5px;font-weight:700;border-radius:999px;padding:3px 9px;
    background:rgba(255,255,255,.06);color:#9aa1a9}
.flexp-chip.ok{background:rgba(34,197,94,.15);color:#22C55E}
.flexp-chip.warn{background:rgba(234,179,78,.15);color:#EAB34E}

.flexp-row{display:grid;grid-template-columns:26px 1fr 1fr 90px 40px;gap:10px;align-items:center;
    background:#171A1E;border:1px solid var(--border);border-radius:12px;padding:8px 10px;margin-bottom:8px}
.flexp-row-empty{opacity:.55}
.flexp-no{color:#6a7178;font-size:12px;font-weight:800;text-align:center}
.flexp-seat{display:flex;align-items:center;gap:9px;padding:7px 10px;border-radius:10px;
    background:#1E2227;min-height:46px;width:100%;text-align:left}
.flexp-seat-info{min-width:0}
.flexp-name{font-size:13px;font-weight:600;color:#EDEFF2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.flexp-meta{font-size:11px;color:#9aa1a9;margin-top:2px}
.flexp-rating{margin-left:auto;font-size:13px;font-weight:800;color:#9aa1a9}
.flexp-avg{text-align:right;color:#9aa1a9;font-size:12px}
.flexp-del{margin:0;text-align:right}

.flexp-free{background:transparent;border:1px dashed rgba(34,197,94,.45);color:#22C55E;
    font-size:12.5px;font-weight:600;cursor:pointer}
.flexp-free:hover{background:rgba(34,197,94,.08)}
.flexp-plus{width:26px;height:26px;border-radius:50%;background:rgba(34,197,94,.16);
    display:grid;place-items:center;font-size:15px;font-weight:700;flex:0 0 auto}
.flexp-empty{border:1px dashed rgba(255,255,255,.12);background:transparent;color:#6a7178;font-size:12.5px}

.flexp-pool{border-radius:12px;padding:12px 14px;margin:14px 0}
.flexp-pool-blue{background:rgba(91,155,255,.08);border:1px solid rgba(91,155,255,.35)}
.flexp-pool-amber{background:rgba(234,179,78,.08);border:1px solid rgba(234,179,78,.35)}
.flexp-pool-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;font-weight:700;font-size:13.5px}
.flexp-pool-blue .flexp-pool-head{color:#8FBBFF}
.flexp-pool-amber .flexp-pool-head{color:#EAB34E}
.flexp-pool-count{font-size:11.5px;font-weight:800;border-radius:999px;padding:1px 8px;
    background:rgba(255,255,255,.1)}
.flexp-pool-hint{font-weight:400;font-size:12px;color:#9aa1a9}

.flexp-chips{display:flex;flex-wrap:wrap;gap:8px}
.flexp-chip-player{display:flex;align-items:center;gap:8px;background:#1E2227;
    border:1px solid var(--border);border-radius:999px;padding:5px 8px 5px 6px}
.flexp-put{border:none;background:rgba(34,197,94,.16);color:#22C55E;border-radius:999px;
    font-size:11.5px;font-weight:700;padding:4px 10px}
.flexp-put:hover{background:rgba(34,197,94,.28)}
.flexp-ok{border:none;background:rgba(34,197,94,.16);color:#22C55E;border-radius:8px;
    width:26px;height:26px;display:grid;place-items:center}
.flexp-x{border:none;background:rgba(240,85,77,.14);color:#F0554D;border-radius:8px;
    width:26px;height:26px;display:grid;place-items:center}

.flexp-add{background:rgba(255,255,255,.03);border:1px solid var(--border);
    border-radius:12px;padding:12px 14px;margin-top:14px}
.flexp-add-title{font-size:13px;font-weight:700;color:#22C55E;margin-bottom:10px}
.flexp-add-hint{font-size:11.5px;color:#6a7178;margin-top:8px}

@media (max-width: 900px){
    .flexp-row{grid-template-columns:22px 1fr 40px;grid-auto-rows:auto}
    .flexp-row .flexp-seat:nth-of-type(2){grid-column:2 / 3}
    .flexp-avg{display:none}
}
</style>

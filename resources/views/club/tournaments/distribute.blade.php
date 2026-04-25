@extends('layouts.app')

@section('title', 'Распределение пар по группам — ' . $tournament->name)

@section('content')

<style>
    .dist-page {
        --d-bg: #0a0a0b;
        --d-card: #111113;
        --d-card-alt: #16161a;
        --d-border: #27272a;
        --d-border-light: #1c1c21;
        --d-text: #f4f4f5;
        --d-text-dim: #a1a1aa;
        --d-text-muted: #71717a;
        --d-accent: #22c55e;
        --d-accent-dark: #16a34a;
        --d-blue: #3b82f6;
        --d-purple: #a78bfa;
        --d-amber: #fb923c;
        --d-red: #ef4444;

        background: var(--d-bg);
        color: var(--d-text);
        min-height: 100vh;
        padding: 24px;
        font-size: 13px;
    }

    /* Header */
    .dist-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 14px; gap: 16px; flex-wrap: wrap;
    }
    .dist-header h1 { font-size: 20px; font-weight: 700; margin: 0; }
    .dist-header h1 .tname { color: var(--d-text-dim); font-weight: 400; }
    .dist-back {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 14px; background: var(--d-card);
        border: 1px solid var(--d-border); border-radius: 10px;
        color: var(--d-text); text-decoration: none; font-size: 13px;
    }
    .dist-back:hover { border-color: var(--d-accent); color: var(--d-accent); }

    /* Toolbar */
    .dist-toolbar {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 14px; gap: 12px; flex-wrap: wrap;
        padding: 12px 16px;
        background: var(--d-card); border: 1px solid var(--d-border);
        border-radius: 12px;
    }
    .dist-status { font-size: 13px; color: var(--d-text-dim); display: flex; align-items: center; gap: 8px; }
    .dist-status .ok { color: var(--d-accent); font-weight: 700; }
    .dist-status .pending { color: var(--d-amber); font-weight: 700; }
    .dist-actions { display: flex; gap: 8px; }
    .dist-btn {
        padding: 8px 14px;
        background: var(--d-card-alt); border: 1px solid var(--d-border);
        border-radius: 8px; color: var(--d-text);
        font-size: 12px; font-weight: 600; cursor: pointer;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .dist-btn:hover { border-color: var(--d-accent); color: var(--d-accent); }
    .dist-btn.primary {
        background: var(--d-accent); color: var(--d-bg); border-color: var(--d-accent);
    }
    .dist-btn.primary:hover:not(:disabled) { background: var(--d-accent-dark); color: var(--d-bg); }
    .dist-btn:disabled { opacity: 0.4; cursor: not-allowed; }

    .icon { width: 14px; height: 14px; stroke: currentColor; stroke-width: 1.8; fill: none; stroke-linecap: round; stroke-linejoin: round; }

    /* Flash */
    .dist-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }
    .dist-flash.success { background: rgba(34,197,94,0.10); color: var(--d-accent); border: 1px solid rgba(34,197,94,0.25); }
    .dist-flash.error { background: rgba(239,68,68,0.10); color: var(--d-red); border: 1px solid rgba(239,68,68,0.25); }

    /* Grid */
    .dist-grid {
        display: grid;
        grid-template-columns: 2fr repeat({{ $groupsCount }}, 1fr);
        gap: 12px;
    }
    @media (max-width: 1100px) {
        .dist-grid { grid-template-columns: 1fr; }
    }

    .dist-col {
        background: var(--d-card);
        border: 1px solid var(--d-border);
        border-radius: 12px;
        padding: 10px;
        min-height: 240px;
    }
    .dist-col.unassigned { border-color: rgba(251,146,60,0.20); }
    .dist-col.gA { background: rgba(59,130,246,0.06); border-color: rgba(59,130,246,0.25); }
    .dist-col.gB { background: rgba(167,139,250,0.06); border-color: rgba(167,139,250,0.25); }
    .dist-col.gC { background: rgba(251,146,60,0.06); border-color: rgba(251,146,60,0.25); }
    .dist-col.gD { background: rgba(34,197,94,0.06); border-color: rgba(34,197,94,0.25); }

    .dist-col-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 4px 4px 8px;
        border-bottom: 1px solid var(--d-border);
        margin-bottom: 6px;
    }
    .dist-col-head .title {
        font-size: 12px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.6px;
    }
    .dist-col.unassigned .dist-col-head .title { color: var(--d-amber); }
    .dist-col.gA .dist-col-head .title { color: #93b9fa; }
    .dist-col.gB .dist-col-head .title { color: #c4b1f8; }
    .dist-col.gC .dist-col-head .title { color: #fbbb84; }
    .dist-col.gD .dist-col-head .title { color: #6ee5a3; }
    .dist-col-head .count {
        font-size: 11px; font-weight: 700;
        padding: 2px 8px; border-radius: 10px;
        background: rgba(255,255,255,0.04); color: var(--d-text-dim);
    }
    .dist-col-head .count.full { background: rgba(34,197,94,0.18); color: var(--d-accent); }
    .dist-col-head .count.over { background: rgba(239,68,68,0.18); color: var(--d-red); }

    /* Row */
    .pair-row {
        display: flex; align-items: center; gap: 10px;
        padding: 8px 8px;
        border-bottom: 1px solid var(--d-border-light);
    }
    .pair-row:last-child { border-bottom: 0; }
    .pair-row:hover { background: rgba(255,255,255,0.02); }

    .pair-row .seed {
        flex-shrink: 0;
        width: 24px; height: 24px;
        border-radius: 50%;
        background: rgba(255,255,255,0.05);
        color: var(--d-text-dim);
        font-size: 10px; font-weight: 800;
        display: flex; align-items: center; justify-content: center;
    }
    .pair-row .info { flex: 1; min-width: 0; }
    .pair-row .name {
        font-size: 12px; font-weight: 600; color: var(--d-text);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        line-height: 1.25;
    }
    .pair-row .rating { font-size: 10px; color: var(--d-text-muted); line-height: 1.1; }

    /* Группа-кнопки в "Не распределено" — всегда видны */
    .group-btns { display: flex; gap: 3px; flex-shrink: 0; }
    .group-btns button {
        width: 26px; height: 26px;
        border-radius: 6px;
        background: transparent;
        border: 1px solid var(--d-border);
        color: var(--d-text-muted);
        font-size: 11px; font-weight: 800;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
    }
    .group-btns button:hover {
        border-color: var(--d-text-dim); color: var(--d-text);
        background: rgba(255,255,255,0.03);
    }
    .group-btns button[data-target="0"]:hover { border-color: var(--d-blue); color: var(--d-blue); }
    .group-btns button[data-target="1"]:hover { border-color: var(--d-purple); color: var(--d-purple); }
    .group-btns button[data-target="2"]:hover { border-color: var(--d-amber); color: var(--d-amber); }
    .group-btns button[data-target="3"]:hover { border-color: var(--d-accent); color: var(--d-accent); }

    /* В группах — крестик чтобы вернуть в "Не распределено" */
    .pair-row .unassign-btn {
        flex-shrink: 0;
        width: 22px; height: 22px;
        border-radius: 6px;
        background: transparent;
        border: 1px solid transparent;
        color: var(--d-text-muted);
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        opacity: 0;
        transition: opacity .12s;
    }
    .pair-row:hover .unassign-btn { opacity: 1; }
    .pair-row .unassign-btn:hover {
        border-color: var(--d-amber); color: var(--d-amber);
        opacity: 1;
    }
    /* В колонках групп показываем seed и unassign-btn, но скрываем рейтинг (компактно) */
    .dist-col:not(.unassigned) .pair-row .rating { display: none; }
    .dist-col:not(.unassigned) .pair-row { padding: 6px 6px; }

    .empty-hint {
        text-align: center;
        padding: 30px 10px;
        color: var(--d-text-muted);
        font-size: 12px;
        font-style: italic;
    }
</style>

<div class="dist-page">

    <div class="dist-header">
        <h1>Распределение пар <span class="tname">— {{ $tournament->name }}</span></h1>
        <a href="{{ route('club.tournaments.show', $tournament) }}" class="dist-back">
            <svg class="icon" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
            Назад к турниру
        </a>
    </div>

    @if(session('error'))
        <div class="dist-flash error">{{ session('error') }}</div>
    @endif
    @if(session('success'))
        <div class="dist-flash success">{{ session('success') }}</div>
    @endif

    <div class="dist-toolbar">
        <div class="dist-status">
            <svg class="icon" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
            Распределено
            <span id="distCount" class="pending">0</span> / {{ $teams->count() }}
            · в каждой группе должно быть <span class="ok">{{ (int) $perGroup }}</span> пар
        </div>
        <div class="dist-actions">
            <button type="button" class="dist-btn" onclick="autoDistribute()">
                <svg class="icon" viewBox="0 0 24 24"><path d="M13 3L4 14h7l-1 7 9-11h-7l1-7z"/></svg>
                Авто (по рейтингу)
            </button>
            <button type="button" class="dist-btn" onclick="resetAll()">
                <svg class="icon" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                Сбросить
            </button>
            <form id="startForm" action="{{ route('club.tournaments.startWithGroups', $tournament) }}" method="POST" style="display:inline;">
                @csrf
                <div id="assignmentsHidden"></div>
                <button type="button" id="startBtn" class="dist-btn primary" disabled onclick="confirmStart()">
                    <svg class="icon" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M5 4l14 8-14 8z"/></svg>
                    Начать турнир
                </button>
            </form>
        </div>
    </div>

    <div class="dist-grid" id="distGrid">

        <!-- Не распределено -->
        <div class="dist-col unassigned" data-group-index="-1">
            <div class="dist-col-head">
                <span class="title">Не распределено</span>
                <span class="count" data-count-for="-1">{{ $teams->count() }}</span>
            </div>
            <div class="col-body" id="col-unassigned">
                @foreach($teams as $idx => $team)
                    @php
                        $name = ($team->player1->full_name ?? $team->player1->name ?? '—')
                              . ' / '
                              . ($team->player2->full_name ?? $team->player2->name ?? '—');
                    @endphp
                    <div class="pair-row" data-team-id="{{ $team->id }}" data-group-index="-1">
                        <div class="seed">{{ $idx + 1 }}</div>
                        <div class="info">
                            <div class="name">{{ $name }}</div>
                            <div class="rating">{{ (int) $team->rating_avg }}</div>
                        </div>
                        <div class="group-btns">
                            @for($g = 0; $g < $groupsCount; $g++)
                                <button type="button" data-target="{{ $g }}" onclick="moveTeam({{ $team->id }}, {{ $g }})">{{ chr(65 + $g) }}</button>
                            @endfor
                        </div>
                        <button type="button" class="unassign-btn" title="Вернуть" onclick="moveTeam({{ $team->id }}, -1)" style="display:none;">
                            <svg class="icon" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Группы -->
        @for($g = 0; $g < $groupsCount; $g++)
            <div class="dist-col g{{ chr(65 + $g) }}" data-group-index="{{ $g }}">
                <div class="dist-col-head">
                    <span class="title">{{ $groupNames[$g] }}</span>
                    <span class="count" data-count-for="{{ $g }}">0</span>
                </div>
                <div class="col-body" id="col-group-{{ $g }}"></div>
            </div>
        @endfor

    </div>

</div>

<script>
    const TOTAL_TEAMS = {{ $teams->count() }};
    const GROUPS_COUNT = {{ (int) $groupsCount }};
    const PER_GROUP = {{ (int) $perGroup }};
    const TEAM_IDS_BY_RATING = @json($teams->pluck('id'));

    function getColBody(groupIndex) {
        return groupIndex === -1
            ? document.getElementById('col-unassigned')
            : document.getElementById('col-group-' + groupIndex);
    }

    function moveTeam(teamId, groupIndex) {
        const row = document.querySelector(`.pair-row[data-team-id="${teamId}"]`);
        if (!row) return;
        const target = getColBody(groupIndex);
        if (!target) return;
        target.appendChild(row);
        row.dataset.groupIndex = String(groupIndex);

        // В колонке "Не распределено" — показываем A/B/C, скрываем крестик
        // В колонках групп — наоборот
        const groupBtns = row.querySelector('.group-btns');
        const unassignBtn = row.querySelector('.unassign-btn');
        if (groupIndex === -1) {
            if (groupBtns) groupBtns.style.display = '';
            if (unassignBtn) unassignBtn.style.display = 'none';
        } else {
            if (groupBtns) groupBtns.style.display = 'none';
            if (unassignBtn) unassignBtn.style.display = '';
        }

        recount();
    }

    function recount() {
        const counts = { '-1': 0 };
        for (let g = 0; g < GROUPS_COUNT; g++) counts[g] = 0;

        document.querySelectorAll('.pair-row').forEach(c => {
            const gi = parseInt(c.dataset.groupIndex);
            counts[gi] = (counts[gi] || 0) + 1;
        });

        Object.keys(counts).forEach(gi => {
            const el = document.querySelector(`[data-count-for="${gi}"]`);
            if (!el) return;
            el.textContent = counts[gi];
            el.classList.remove('full', 'over');
            if (gi === '-1') {
                if (counts[gi] === 0) el.classList.add('full');
            } else {
                if (counts[gi] === PER_GROUP) el.classList.add('full');
                else if (counts[gi] > PER_GROUP) el.classList.add('over');
            }
        });

        const distributed = TOTAL_TEAMS - (counts['-1'] || 0);
        const distCount = document.getElementById('distCount');
        distCount.textContent = distributed;
        distCount.className = (distributed === TOTAL_TEAMS) ? 'ok' : 'pending';

        let allOk = (counts['-1'] === 0);
        for (let g = 0; g < GROUPS_COUNT; g++) {
            if (counts[g] !== PER_GROUP) { allOk = false; break; }
        }
        document.getElementById('startBtn').disabled = !allOk;
    }

    function autoDistribute() {
        let direction = 1;
        let groupIndex = 0;
        TEAM_IDS_BY_RATING.forEach((teamId) => {
            moveTeam(teamId, groupIndex);
            groupIndex += direction;
            if (groupIndex >= GROUPS_COUNT) { groupIndex = GROUPS_COUNT - 1; direction = -1; }
            else if (groupIndex < 0) { groupIndex = 0; direction = 1; }
        });
    }

    function resetAll() {
        document.querySelectorAll('.pair-row').forEach(c => {
            const teamId = parseInt(c.dataset.teamId);
            moveTeam(teamId, -1);
        });
    }

    function confirmStart() {
        if (!confirm('Начать турнир с ручным распределением? Группы и матчи будут созданы.')) return;
        const hidden = document.getElementById('assignmentsHidden');
        hidden.innerHTML = '';
        document.querySelectorAll('.pair-row').forEach(c => {
            const teamId = c.dataset.teamId;
            const gi = c.dataset.groupIndex;
            if (parseInt(gi) >= 0) {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = `assignments[${teamId}]`;
                inp.value = gi;
                hidden.appendChild(inp);
            }
        });
        document.getElementById('startForm').submit();
    }

    recount();
</script>

@endsection

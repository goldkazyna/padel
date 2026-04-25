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
        --d-red: #ef4444;
        --d-amber: #fb923c;

        background: var(--d-bg);
        color: var(--d-text);
        min-height: 100vh;
        padding: 24px;
    }

    .dist-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 16px;
        gap: 16px;
        flex-wrap: wrap;
    }
    .dist-header h1 {
        font-size: 22px;
        font-weight: 700;
        margin: 0;
    }
    .dist-header h1 .tname { color: var(--d-text-dim); font-weight: 400; }

    .dist-back {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 14px;
        background: var(--d-card); border: 1px solid var(--d-border);
        border-radius: 10px; color: var(--d-text); text-decoration: none;
        font-size: 13px;
    }
    .dist-back:hover { border-color: var(--d-accent); color: var(--d-accent); }

    .dist-toolbar {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 18px; gap: 16px; flex-wrap: wrap;
    }
    .dist-status {
        font-size: 14px;
        color: var(--d-text-dim);
    }
    .dist-status .ok { color: var(--d-accent); font-weight: 700; }
    .dist-status .pending { color: var(--d-amber); font-weight: 700; }

    .dist-actions { display: flex; gap: 10px; }
    .dist-btn {
        padding: 10px 18px;
        border-radius: 10px;
        border: 1px solid var(--d-border);
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        background: var(--d-card-alt);
        color: var(--d-text);
    }
    .dist-btn:hover { border-color: var(--d-accent); color: var(--d-accent); }
    .dist-btn.primary {
        background: var(--d-accent);
        color: #0a0a0b;
        border-color: var(--d-accent);
    }
    .dist-btn.primary:hover:not(:disabled) { background: var(--d-accent-dark); }
    .dist-btn.primary:disabled, .dist-btn:disabled {
        opacity: 0.4; cursor: not-allowed;
    }

    .dist-flash {
        padding: 12px 16px;
        border-radius: 10px;
        margin-bottom: 14px;
        font-size: 14px;
    }
    .dist-flash.success { background: rgba(34,197,94,0.10); color: var(--d-accent); border: 1px solid rgba(34,197,94,0.25); }
    .dist-flash.error { background: rgba(239,68,68,0.10); color: var(--d-red); border: 1px solid rgba(239,68,68,0.25); }

    /* === Grid === */
    .dist-grid {
        display: grid;
        grid-template-columns: 1fr repeat({{ $groupsCount }}, 1fr);
        gap: 14px;
    }
    @media (max-width: 1100px) {
        .dist-grid { grid-template-columns: 1fr; }
    }

    .dist-col {
        background: var(--d-card);
        border: 1px solid var(--d-border);
        border-radius: 14px;
        padding: 14px;
        min-height: 280px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .dist-col-head {
        display: flex; align-items: center; justify-content: space-between;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--d-border);
        margin-bottom: 6px;
    }
    .dist-col-head .title {
        font-size: 14px; font-weight: 700; color: var(--d-text);
    }
    .dist-col-head .count {
        font-size: 12px; font-weight: 700;
        padding: 3px 8px; border-radius: 6px;
        background: var(--d-card-alt); color: var(--d-text-dim);
    }
    .dist-col-head .count.ok {
        background: rgba(34,197,94,0.15); color: var(--d-accent);
    }
    .dist-col-head .count.over {
        background: rgba(239,68,68,0.15); color: var(--d-red);
    }

    .dist-col.unassigned .dist-col-head .title { color: var(--d-amber); }
    .dist-col.group-A { border-color: rgba(59,130,246,0.30); }
    .dist-col.group-B { border-color: rgba(168,85,247,0.30); }
    .dist-col.group-C { border-color: rgba(251,146,60,0.30); }
    .dist-col.group-D { border-color: rgba(34,197,94,0.30); }

    /* === Team card === */
    .team-card {
        position: relative;
        background: var(--d-card-alt);
        border: 1px solid var(--d-border);
        border-radius: 10px;
        padding: 10px 12px;
        cursor: pointer;
        transition: border-color .12s, transform .08s;
    }
    .team-card:hover { border-color: var(--d-accent); }
    .team-card.selected { border-color: var(--d-accent); background: rgba(34,197,94,0.07); }

    .team-card .row {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
    }
    .team-card .name {
        font-size: 13px; font-weight: 600; color: var(--d-text);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .team-card .seed {
        font-size: 11px; font-weight: 700;
        padding: 2px 6px; border-radius: 5px;
        background: rgba(255,255,255,0.06);
        color: var(--d-text-dim);
        flex-shrink: 0;
    }
    .team-card .rating {
        font-size: 11px; color: var(--d-text-muted);
        margin-top: 2px;
    }

    /* Группы для перемещения — popover поверх карточки */
    .move-targets {
        display: none;
        margin-top: 8px;
        gap: 6px;
        flex-wrap: wrap;
    }
    .team-card.selected .move-targets { display: flex; }
    .move-targets button {
        flex: 1;
        min-width: 36px;
        padding: 6px 8px;
        background: var(--d-card);
        border: 1px solid var(--d-border);
        border-radius: 6px;
        color: var(--d-text-dim);
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }
    .move-targets button:hover {
        border-color: var(--d-accent);
        color: var(--d-accent);
    }
    .move-targets button.current {
        background: var(--d-accent);
        color: var(--d-bg);
        border-color: var(--d-accent);
    }
    .move-targets button.unassign {
        border-color: rgba(251,146,60,0.4);
        color: var(--d-amber);
    }
    .move-targets button.unassign:hover { background: rgba(251,146,60,0.15); }

    .empty-hint {
        text-align: center;
        padding: 30px 10px;
        color: var(--d-text-muted);
        font-size: 13px;
        font-style: italic;
    }
</style>

<div class="dist-page">

    <div class="dist-header">
        <h1>Распределение пар <span class="tname">— {{ $tournament->name }}</span></h1>
        <a href="{{ route('club.tournaments.show', $tournament) }}" class="dist-back">← Назад к турниру</a>
    </div>

    @if(session('error'))
        <div class="dist-flash error">{{ session('error') }}</div>
    @endif
    @if(session('success'))
        <div class="dist-flash success">{{ session('success') }}</div>
    @endif

    <div class="dist-toolbar">
        <div class="dist-status">
            Распределено
            <span id="distCount" class="pending">0</span> / {{ $teams->count() }}
            · в каждой группе должно быть <span class="ok">{{ (int) $perGroup }}</span> пар
        </div>
        <div class="dist-actions">
            <button type="button" class="dist-btn" onclick="autoDistribute()">
                ⚡ Распределить автоматически (по рейтингу)
            </button>
            <button type="button" class="dist-btn" onclick="resetAll()">
                ↻ Сбросить
            </button>
            <form id="startForm" action="{{ route('club.tournaments.startWithGroups', $tournament) }}" method="POST" style="display:inline;">
                @csrf
                <div id="assignmentsHidden"></div>
                <button type="button" id="startBtn" class="dist-btn primary" disabled onclick="confirmStart()">
                    ▶ Начать турнир
                </button>
            </form>
        </div>
    </div>

    <div class="dist-grid" id="distGrid">
        <div class="dist-col unassigned" data-group-index="-1">
            <div class="dist-col-head">
                <span class="title">Не распределено</span>
                <span class="count" data-count-for="-1">{{ $teams->count() }}</span>
            </div>
            <div class="col-body" id="col-unassigned">
                @foreach($teams as $idx => $team)
                    <div class="team-card" data-team-id="{{ $team->id }}" data-group-index="-1" onclick="toggleSelect(this)">
                        <div class="row">
                            <span class="name">
                                {{ $team->player1->full_name ?? $team->player1->name ?? '—' }} / {{ $team->player2->full_name ?? $team->player2->name ?? '—' }}
                            </span>
                            <span class="seed">#{{ $idx + 1 }}</span>
                        </div>
                        <div class="rating">Рейтинг: {{ (int) $team->rating_avg }}</div>
                        <div class="move-targets">
                            @for($g = 0; $g < $groupsCount; $g++)
                                <button type="button" onclick="event.stopPropagation(); moveTeam({{ $team->id }}, {{ $g }})">{{ chr(65 + $g) }}</button>
                            @endfor
                            <button type="button" class="unassign" onclick="event.stopPropagation(); moveTeam({{ $team->id }}, -1)">Убрать</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        @for($g = 0; $g < $groupsCount; $g++)
            <div class="dist-col group-{{ chr(65 + $g) }}" data-group-index="{{ $g }}">
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
    // Команды отсортированы по рейтингу (desc) — для авто-змейки
    const TEAM_IDS_BY_RATING = @json($teams->pluck('id'));

    function getColBody(groupIndex) {
        return groupIndex === -1
            ? document.getElementById('col-unassigned')
            : document.getElementById('col-group-' + groupIndex);
    }

    function moveTeam(teamId, groupIndex) {
        const card = document.querySelector(`.team-card[data-team-id="${teamId}"]`);
        if (!card) return;
        const target = getColBody(groupIndex);
        if (!target) return;
        target.appendChild(card);
        card.dataset.groupIndex = String(groupIndex);
        card.classList.remove('selected');
        // Подсветим текущую кнопку в карточке
        const buttons = card.querySelectorAll('.move-targets button');
        buttons.forEach(b => b.classList.remove('current'));
        if (groupIndex >= 0 && buttons[groupIndex]) buttons[groupIndex].classList.add('current');
        recount();
    }

    function toggleSelect(card) {
        // Закрыть остальные
        document.querySelectorAll('.team-card.selected').forEach(c => {
            if (c !== card) c.classList.remove('selected');
        });
        card.classList.toggle('selected');
    }

    function recount() {
        const counts = { '-1': 0 };
        for (let g = 0; g < GROUPS_COUNT; g++) counts[g] = 0;

        document.querySelectorAll('.team-card').forEach(c => {
            const gi = parseInt(c.dataset.groupIndex);
            counts[gi] = (counts[gi] || 0) + 1;
        });

        // Обновить счётчики в шапках
        Object.keys(counts).forEach(gi => {
            const el = document.querySelector(`[data-count-for="${gi}"]`);
            if (!el) return;
            el.textContent = counts[gi];
            el.classList.remove('ok', 'over');
            if (gi === '-1') {
                if (counts[gi] === 0) el.classList.add('ok');
            } else {
                if (counts[gi] === PER_GROUP) el.classList.add('ok');
                else if (counts[gi] > PER_GROUP) el.classList.add('over');
            }
        });

        // Сводный статус
        const distributed = TOTAL_TEAMS - (counts['-1'] || 0);
        const distCount = document.getElementById('distCount');
        distCount.textContent = distributed;
        distCount.className = (distributed === TOTAL_TEAMS) ? 'ok' : 'pending';

        // Кнопка «Начать»
        let allOk = (counts['-1'] === 0);
        for (let g = 0; g < GROUPS_COUNT; g++) {
            if (counts[g] !== PER_GROUP) { allOk = false; break; }
        }
        document.getElementById('startBtn').disabled = !allOk;
    }

    function autoDistribute() {
        // Змейка: 0,1,2,...N-1, N-1,...,1,0, 0,1,...
        let direction = 1;
        let groupIndex = 0;
        TEAM_IDS_BY_RATING.forEach((teamId, idx) => {
            moveTeam(teamId, groupIndex);
            groupIndex += direction;
            if (groupIndex >= GROUPS_COUNT) { groupIndex = GROUPS_COUNT - 1; direction = -1; }
            else if (groupIndex < 0) { groupIndex = 0; direction = 1; }
        });
    }

    function resetAll() {
        document.querySelectorAll('.team-card').forEach(c => {
            const teamId = parseInt(c.dataset.teamId);
            moveTeam(teamId, -1);
        });
    }

    function confirmStart() {
        if (!confirm('Начать турнир с ручным распределением? Группы и матчи будут созданы.')) return;
        // Соберём hidden inputs assignments[teamId]=groupIndex
        const hidden = document.getElementById('assignmentsHidden');
        hidden.innerHTML = '';
        document.querySelectorAll('.team-card').forEach(c => {
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

    // Закрыть выделение при клике вне карточки
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.team-card')) {
            document.querySelectorAll('.team-card.selected').forEach(c => c.classList.remove('selected'));
        }
    });

    recount();
</script>

@endsection

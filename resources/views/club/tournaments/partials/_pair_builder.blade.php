{{--
    Сбор пар в два клика.

    Ждёт $tournament (парный флекс) и $approvedTeams — уже собранные пары.

    Раньше на каждую пару отправлялась форма с двумя выпадающими списками и
    перезагрузкой страницы: на 16 игроков это восемь кругов ожидания. Здесь
    расклад собирается в браузере — клик по игроку, клик по партнёру — и
    уезжает на сервер одним запросом по кнопке «Сохранить».
--}}
@php
    $pairPlayers = $tournament->participants()
        ->wherePivot('status', 'registered')
        ->get()
        ->sortByDesc('rating')
        ->map(fn ($u) => [
            'id' => (int) $u->id,
            'name' => $u->name,
            'avatar' => $u->avatar,
            'level' => $u->level !== null ? number_format((float) $u->level, 2, '.', '') : null,
            'rating' => (int) $u->rating,
        ])->values();

    $existingPairs = $approvedTeams->map(fn ($t) => [(int) $t->player1_id, (int) $t->player2_id])->values();
    $maxPairs = (int) ($tournament->max_participants / 2);
    $rosterReady = $tournament->approvedParticipantsCount() >= (int) $tournament->max_participants;
@endphp

<div class="pb" id="pairBuilder"
     data-players='@json($pairPlayers)'
     data-pairs='@json($existingPairs)'
     data-max="{{ $maxPairs }}"
     data-url="{{ route('club.tournaments.pairing.save', $tournament) }}">

    <div class="pb-head">
        <strong class="pb-title"><i class="bi bi-people"></i> Сбор пар</strong>
        <span class="pb-badge" id="pbCount">0 из {{ $maxPairs }}</span>
        <span class="pb-spacer"></span>

        @if($rosterReady)
            <div class="pb-menu-wrap">
                <button type="button" class="btn-outline-custom" id="pbAutoBtn">
                    <i class="bi bi-magic"></i> Авто-пары
                </button>
                <div class="pb-menu" id="pbMenu">
                    <button type="button" data-auto="balance">Сильный + слабый
                        <small>первый с последним по рейтингу — пары ровные</small></button>
                    <button type="button" data-auto="near">Близкие по рейтингу
                        <small>1+2, 3+4 — сильные играют против сильных</small></button>
                    <button type="button" data-auto="random">Случайно
                        <small>жеребьёвка</small></button>
                </div>
            </div>
            <button type="button" class="btn-outline-custom" id="pbReset">Сбросить</button>
        @endif
    </div>

    @unless($rosterReady)
        <p class="pb-note">
            Собрать пары можно при полном составе. Подтверждено
            {{ $tournament->approvedParticipantsCount() }} из {{ $tournament->max_participants }} —
            сначала подтвердите всех участников.
        </p>
    @else
        <div class="pb-pairs" id="pbPairs"></div>

        <div class="pb-sub">
            <span>Без пары</span>
            <span class="pb-badge pb-badge-warn" id="pbLeft">0</span>
            <span class="pb-hint" id="pbHint"></span>
        </div>
        <div class="pb-pool" id="pbPool"></div>

        <div class="pb-foot">
            <button type="button" class="btn-primary-custom" id="pbSave" disabled>
                <i class="bi bi-check-lg"></i> Сохранить пары
            </button>
            <span class="pb-status" id="pbStatus"></span>
        </div>
    @endunless
</div>

<style>
.pb {
    background: #18181b;
    border: 1px solid #2a2a2a;
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 24px;
}
.pb-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
.pb-title { color: #fff; font-size: 15px; }
.pb-spacer { flex: 1; }
.pb-badge {
    font-size: 12px; font-weight: 700; padding: 2px 10px; border-radius: 20px;
    background: rgba(34, 197, 94, .15); color: #22c55e;
}
.pb-badge-warn { background: rgba(245, 158, 11, .15); color: #f59e0b; }
.pb-note { color: #a1a1aa; margin: 0; font-size: 13.5px; }

.pb-pairs { margin-bottom: 16px; }
.pb-pair {
    display: flex; align-items: center; gap: 10px; padding: 9px 12px; margin-bottom: 8px;
    background: #111113; border: 1px solid #2a2a2a; border-radius: 12px;
}
.pb-num {
    width: 26px; height: 26px; flex: none; display: grid; place-items: center; border-radius: 50%;
    background: rgba(255, 255, 255, .06); color: #a1a1aa; font-size: 12px; font-weight: 700;
}
.pb-pair-names { flex: 1; min-width: 0; color: #fff; font-size: 14px; font-weight: 600; overflow-wrap: anywhere; }
.pb-pair-names em { color: #71717a; font-style: normal; margin: 0 6px; }
.pb-avg { color: #71717a; font-size: 12px; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pb-x {
    border: 0; background: none; color: #71717a; cursor: pointer; padding: 4px 6px;
    border-radius: 8px; line-height: 1;
}
.pb-x:hover { color: #ef4444; background: rgba(239, 68, 68, .1); }
.pb-empty { color: #71717a; font-size: 13px; text-align: center; padding: 18px 10px; }

.pb-sub { display: flex; align-items: center; gap: 10px; color: #a1a1aa; font-size: 13px; margin-bottom: 10px; }
.pb-hint { color: #22c55e; font-weight: 600; }
.pb-pool { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 8px; }
.pb-chip {
    display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; cursor: pointer;
    padding: 8px 10px; background: #111113; border: 1px solid #2a2a2a; border-radius: 12px;
    color: #fff; font: inherit; font-size: 13.5px;
}
.pb-chip:hover { border-color: #3f4548; background: #16161a; }
.pb-chip.on { border-color: #22c55e; background: rgba(34, 197, 94, .1); box-shadow: inset 0 0 0 1px #22c55e; }
/* Имя показываем целиком: «Тестовый1…» не даёт понять, кого ставишь в пару. */
.pb-chip-name { flex: 1; min-width: 0; font-weight: 600; white-space: normal; overflow-wrap: anywhere; line-height: 1.25; }
.pb-ava {
    width: 32px; height: 32px; flex: none; border-radius: 50%; object-fit: cover;
    display: grid; place-items: center; background: #26262b; color: #b9c2c6; font-size: 12px; font-weight: 700;
}
.pb-lvl {
    font-size: 11.5px; font-weight: 700; color: #9fe8bc; background: rgba(34, 197, 94, .12);
    border-radius: 6px; padding: 1px 6px; font-variant-numeric: tabular-nums;
}
.pb-rating { color: #71717a; font-size: 12.5px; font-variant-numeric: tabular-nums; }

.pb-foot { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
.pb-status { color: #a1a1aa; font-size: 13px; }
.pb-status.err { color: #ef4444; }
.pb-status.ok { color: #22c55e; }

.pb-menu-wrap { position: relative; }
.pb-menu {
    position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; min-width: 250px; display: none;
    background: #1b1b1f; border: 1px solid #2a2a2a; border-radius: 12px; padding: 6px;
    box-shadow: 0 18px 40px rgba(0, 0, 0, .5);
}
.pb-menu.on { display: block; }
.pb-menu button {
    display: block; width: 100%; text-align: left; background: none; border: 0; color: #fff;
    font: inherit; font-size: 13.5px; padding: 9px 10px; border-radius: 8px; cursor: pointer;
}
.pb-menu button:hover { background: rgba(255, 255, 255, .06); }
.pb-menu small { display: block; color: #71717a; font-size: 11.5px; margin-top: 1px; }
</style>

<script>
(function () {
    const root = document.getElementById('pairBuilder');
    if (!root || !document.getElementById('pbPool')) return;

    const PLAYERS = JSON.parse(root.dataset.players);
    const MAX = parseInt(root.dataset.max, 10);
    const URL = root.dataset.url;
    const TOKEN = document.querySelector('meta[name="csrf-token"]')?.content;

    let pairs = JSON.parse(root.dataset.pairs);
    let picked = null;
    let saving = false;

    // Слепок сохранённого расклада: пока текущий от него отличается, кнопка
    // старта не должна работать — турнир ушёл бы со старыми парами.
    let savedSnapshot = snapshot(pairs);
    const startBtn = document.getElementById('flexStartBtn');

    const byId = id => PLAYERS.find(p => p.id === id);
    // Порядок пар и игроков внутри пары значения не имеет — сравниваем по составу.
    const snapshot = list => JSON.stringify(list.map(p => [p[0], p[1]].sort((a, b) => a - b)).sort((a, b) => a[0] - b[0]));
    const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const initials = name => name.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();
    const avg = (a, b) => Math.round((byId(a).rating + byId(b).rating) / 2);

    const avatar = p => p.avatar
        ? `<img class="pb-ava" src="${esc(p.avatar)}" alt="" loading="lazy">`
        : `<span class="pb-ava">${esc(initials(p.name))}</span>`;

    // «Сильный + слабый»: список отсортирован по рейтингу, берём с краёв —
    // так средний рейтинг пар получается ровнее.
    function autoBalance(ids) {
        const rest = ids.slice().sort((a, b) => byId(b).rating - byId(a).rating);
        const out = [];
        while (rest.length > 1) out.push([rest.shift(), rest.pop()]);
        return out;
    }
    function autoNear(ids) {
        const rest = ids.slice().sort((a, b) => byId(b).rating - byId(a).rating);
        const out = [];
        while (rest.length > 1) out.push([rest.shift(), rest.shift()]);
        return out;
    }
    function autoRandom(ids) {
        const rest = ids.slice();
        for (let i = rest.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [rest[i], rest[j]] = [rest[j], rest[i]];
        }
        const out = [];
        while (rest.length > 1) out.push([rest.shift(), rest.shift()]);
        return out;
    }

    function render() {
        const taken = new Set(pairs.flat());
        const pool = PLAYERS.filter(p => !taken.has(p.id));

        document.getElementById('pbPairs').innerHTML = pairs.length ? pairs.map((pair, i) => `
            <div class="pb-pair">
                <span class="pb-num">${i + 1}</span>
                <span class="pb-pair-names">${esc(byId(pair[0]).name)}<em>+</em>${esc(byId(pair[1]).name)}</span>
                <span class="pb-avg">средний ${avg(pair[0], pair[1])}</span>
                <button type="button" class="pb-x" data-break="${i}" title="Разбить пару">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>`).join('') : '<div class="pb-empty">Пар пока нет — выберите двоих игроков ниже</div>';

        document.getElementById('pbPool').innerHTML = pool.map(p => `
            <button type="button" class="pb-chip ${picked === p.id ? 'on' : ''}" data-pick="${p.id}">
                ${avatar(p)}
                <span class="pb-chip-name">${esc(p.name)}</span>
                ${p.level ? `<span class="pb-lvl">${esc(p.level)}</span>` : ''}
                <span class="pb-rating">${p.rating}</span>
            </button>`).join('') || '<div class="pb-empty">Все игроки разобраны по парам</div>';

        document.getElementById('pbCount').textContent = `${pairs.length} из ${MAX}`;
        document.getElementById('pbLeft').textContent = pool.length;
        document.getElementById('pbHint').textContent = picked
            ? `${byId(picked).name} — выберите ему партнёра`
            : '';

        const dirty = snapshot(pairs) !== savedSnapshot;
        const status = document.getElementById('pbStatus');
        if (!status.classList.contains('ok') && !status.classList.contains('err')) {
            if (dirty) {
                status.textContent = pool.length
                    ? `Без пары ещё ${pool.length}. Изменения не сохранены — нажмите «Сохранить пары»`
                    : 'Все пары собраны. Нажмите «Сохранить пары», иначе турнир не запустится';
            } else {
                status.textContent = pool.length
                    ? `Без пары ещё ${pool.length} — турнир не стартует, пока кто-то без пары`
                    : 'Пары сохранены — можно запускать турнир';
            }
        }
        document.getElementById('pbSave').disabled = saving;

        if (startBtn) {
            startBtn.disabled = dirty || pairs.length < MAX;
            startBtn.title = dirty
                ? 'Сначала нажмите «Сохранить пары»'
                : (pairs.length < MAX ? 'Соберите все пары' : '');
        }

        document.querySelectorAll('#pbPool [data-pick]').forEach(el => {
            el.onclick = () => {
                const id = parseInt(el.dataset.pick, 10);
                if (picked === null) picked = id;
                else if (picked === id) picked = null;
                else { pairs.push([picked, id]); picked = null; }
                clearStatus();
                render();
            };
        });
        document.querySelectorAll('#pbPairs [data-break]').forEach(el => {
            el.onclick = () => { pairs.splice(parseInt(el.dataset.break, 10), 1); clearStatus(); render(); };
        });
    }

    function clearStatus() {
        const s = document.getElementById('pbStatus');
        s.classList.remove('ok', 'err');
        s.textContent = '';
    }

    const menu = document.getElementById('pbMenu');
    document.getElementById('pbAutoBtn').onclick = e => { e.stopPropagation(); menu.classList.toggle('on'); };
    document.addEventListener('click', () => menu.classList.remove('on'));

    menu.querySelectorAll('[data-auto]').forEach(b => {
        b.onclick = () => {
            const taken = new Set(pairs.flat());
            const free = PLAYERS.map(p => p.id).filter(id => !taken.has(id));
            const fn = { balance: autoBalance, near: autoNear, random: autoRandom }[b.dataset.auto];
            pairs = pairs.concat(fn(free));
            picked = null;
            clearStatus();
            render();
        };
    });

    document.getElementById('pbReset').onclick = () => { pairs = []; picked = null; clearStatus(); render(); };

    document.getElementById('pbSave').onclick = async () => {
        // Кнопку блокируем на время запроса: двойной клик сохранял бы дважды.
        saving = true;
        render();

        const status = document.getElementById('pbStatus');
        status.classList.remove('ok', 'err');
        status.textContent = 'Сохраняем…';

        try {
            const res = await fetch(URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': TOKEN,
                },
                body: JSON.stringify({ pairs }),
            });
            const data = await res.json().catch(() => ({}));

            if (res.ok && data.success) {
                savedSnapshot = snapshot(pairs);
                status.classList.add('ok');
                status.textContent = data.message || 'Сохранено';
                // Перезагружаем: ниже на странице есть состав и кнопка старта,
                // которые считаются на сервере.
                setTimeout(() => window.location.reload(), 500);
                return;
            }

            status.classList.add('err');
            status.textContent = data.message || 'Не удалось сохранить пары';
        } catch (e) {
            status.classList.add('err');
            status.textContent = 'Сеть не ответила — попробуйте ещё раз';
        } finally {
            saving = false;
            document.getElementById('pbSave').disabled = false;
        }
    };

    render();
})();
</script>

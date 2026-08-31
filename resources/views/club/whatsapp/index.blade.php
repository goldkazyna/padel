{{-- Список диалогов WhatsApp и переписка рядом. Мягкая интеграция: только
     чтение того, что принёс вебхук Whapi.Cloud, отвечаем из мессенджера. --}}
@extends('layouts.app')

@section('title', 'WhatsApp')

@section('content')
@php
    $tz = config('app.schedule_timezone', 'Asia/Almaty');

    // Вкладки над списком: экран открывают с вопросом «кому ответить»,
    // а не «покажи все переписки подряд».
    $tabs = [
        'waiting' => ['Ждут ответа', $counts['waiting']],
        'all'     => ['Все диалоги', $counts['all']],
        'today'   => ['Сегодня', $counts['today']],
        'new'     => ['Новые люди', $counts['new']],
    ];
@endphp

<div class="wa-wrap">

    <div class="wa-head">
        <i class="bi bi-whatsapp wa-icon"></i>
        <div>
            <h1>WhatsApp</h1>
            <div class="wa-sub">
                {{ $club->name }} · {{ $counts['all'] }} {{ trans_choice('диалог|диалога|диалогов', $counts['all']) }}
            </div>
        </div>
        <a href="{{ route('club.whatsapp.analysis') }}" class="wa-btn">
            <i class="bi bi-stars"></i> Разбор дня
        </a>
    </div>

    <div class="wa-split">
        <div class="wa-side">
            <div class="wa-tabs">
                @foreach($tabs as $key => [$label, $count])
                    <a href="{{ route('club.whatsapp.index', array_filter(['filter' => $key === 'all' ? null : $key, 'search' => $search ?: null])) }}"
                       class="wa-tab {{ $filter === $key ? 'on' : '' }}">
                        {{ $label }}
                        <span class="wa-tab-count {{ $key === 'waiting' && $count ? 'hot' : '' }}">{{ $count }}</span>
                    </a>
                @endforeach
            </div>

            <form method="GET" class="wa-search">
                <input type="hidden" name="filter" value="{{ $filter }}">
                <i class="bi bi-search"></i>
                <input type="text" name="search" value="{{ $search }}"
                       placeholder="Имя, номер или текст" autocomplete="off">
                @if($search !== '')
                    <a href="{{ route('club.whatsapp.index', ['filter' => $filter]) }}" class="wa-clear">Сбросить</a>
                @endif
            </form>

            <div class="wa-body">
                @if($chats->isEmpty())
                    <div class="wa-empty">
                        <i class="bi bi-chat-dots"></i>
                        @if($search !== '')
                            <p>Ничего не нашли по запросу «{{ $search }}»</p>
                        @elseif($filter === 'waiting')
                            <p>Никто не ждёт ответа</p>
                            <span>Все обращения закрыты — так и должно быть.</span>
                        @elseif($total === 0)
                            <p>Сообщений пока нет</p>
                            <span>Как только на номер клуба напишут в WhatsApp, переписка появится здесь.</span>
                        @else
                            <p>Здесь пусто</p>
                        @endif
                    </div>
                @else
                    <div class="wa-list">
                        @foreach($chats as $chat)
                            @php
                                $client = $clients[substr($chat['phone'], -10)] ?? null;
                                $last = $chat['last'];
                                // Красная полоса — просрочено, жёлтая — ждут, но
                                // в пределах нормы. Отвеченные без полосы.
                                $mark = $chat['waited'] === null ? '' : ($chat['overdue'] ? 'over' : 'soon');
                            @endphp
                            <button type="button" class="wa-item {{ $mark }}"
                                    data-phone="{{ $chat['phone'] }}"
                                    data-url="{{ route('club.whatsapp.panel', $chat['phone']) }}">
                                <div class="wa-ava">{{ mb_strtoupper(mb_substr($client->name ?? $chat['name'] ?? '?', 0, 1)) }}</div>

                                <div class="wa-item-main">
                                    <div class="wa-item-top">
                                        <span class="wa-name">{{ $client->name ?? $chat['name'] ?? 'Без имени' }}</span>
                                        @if($client)<span class="wa-tag">клиент</span>@endif
                                        @if(!$chat['ever_answered'])<span class="wa-tag new">впервые</span>@endif
                                    </div>

                                    <div class="wa-preview">
                                        @if($last->from_me)<span class="wa-me">Вы:</span>@endif
                                        {{ \Illuminate\Support\Str::limit($last->preview(), 80) }}
                                    </div>

                                    <div class="wa-phone">@phoneFmt($chat['phone'])</div>
                                </div>

                                <div class="wa-item-right">
                                    <span class="wa-time">{{ $last->sent_at->timezone($tz)->locale('ru')->translatedFormat('j M, H:i') }}</span>
                                    @if($chat['waited'] === null)
                                        <span class="wa-wait ok">ответили</span>
                                    @else
                                        <span class="wa-wait {{ $chat['overdue'] ? 'red' : 'amber' }}">
                                            ждёт {{ \App\Support\WhatsappSla::humanMinutes((int) $chat['waited']) }}
                                        </span>
                                    @endif
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="wa-panel" id="waPanel">
            <div class="wa-panel-empty">
                <i class="bi bi-chat-left-text"></i>
                <p>Выберите диалог слева</p>
                <span>Переписка откроется здесь — страница не перезагружается.</span>
            </div>
        </div>
    </div>
</div>

<style>
.wa-wrap{max-width:1200px;margin:0 auto;padding:20px 16px 40px;color:#f4f4f5;
  --card:#16161a;--card2:#1e1e24;--line:#27272a;--wa:#25d366;--t2:#a1a1aa;--t3:#71717a;}
.wa-head{display:flex;align-items:center;gap:14px;margin-bottom:16px;}
.wa-head h1{font-size:22px;font-weight:800;margin:0;}
.wa-icon{font-size:26px;color:var(--wa);}
.wa-sub{color:var(--t3);font-size:13px;margin-top:2px;}
.wa-btn{margin-left:auto;display:inline-flex;align-items:center;gap:8px;text-decoration:none;
  background:var(--card);border:1px solid var(--line);border-radius:10px;padding:9px 14px;
  color:var(--t2);font-size:13px;font-weight:600;}
.wa-btn:hover{border-color:#3d3d3d;color:#fff;}

/* ── две колонки: список и переписка ─────────────────────────────────── */
.wa-split{display:grid;grid-template-columns:minmax(330px,420px) 1fr;gap:14px;align-items:start;}
.wa-side{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;}

.wa-tabs{display:flex;gap:2px;padding:6px 8px 0;border-bottom:1px solid var(--line);overflow-x:auto;}
.wa-tab{display:flex;align-items:center;gap:6px;padding:9px 12px;border-bottom:2px solid transparent;
  color:var(--t3);font-size:12.5px;font-weight:700;text-decoration:none;white-space:nowrap;}
.wa-tab.on{color:var(--wa);border-bottom-color:var(--wa);}
.wa-tab-count{font-size:11px;padding:1px 7px;border-radius:20px;background:rgba(255,255,255,.06);}
.wa-tab.on .wa-tab-count{background:rgba(37,211,102,.16);color:var(--wa);}
.wa-tab-count.hot{background:rgba(239,68,68,.16);color:#f87171;}

.wa-search{display:flex;align-items:center;gap:9px;margin:10px;padding:9px 12px;
  background:var(--card2);border:1px solid var(--line);border-radius:10px;}
.wa-search i{color:var(--t3);}
.wa-search input[type=text]{flex:1;background:none;border:none;color:#f3f3f5;font-size:13.5px;outline:none;}
.wa-clear{color:var(--t2);font-size:12px;text-decoration:none;white-space:nowrap;}

.wa-body{max-height:70vh;overflow-y:auto;}
.wa-list{padding:0 6px 8px;}
.wa-item{display:flex;align-items:center;gap:11px;width:100%;text-align:left;cursor:pointer;
  padding:9px 10px;border:0;border-left:3px solid transparent;border-radius:10px;
  background:none;color:inherit;font:inherit;}
.wa-item:hover{background:var(--card2);}
.wa-item.active{background:var(--card2);border-left-color:var(--wa);}
.wa-item.over{border-left-color:#ef4444;}
.wa-item.soon{border-left-color:#eab308;}

.wa-ava{width:36px;height:36px;border-radius:50%;flex:0 0 auto;background:rgba(37,211,102,.14);
  color:var(--wa);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;}
.wa-item-main{flex:1;min-width:0;}
.wa-item-top{display:flex;align-items:center;gap:7px;}
.wa-name{font-size:13.5px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.wa-tag{font-size:9px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;
  padding:2px 6px;border-radius:5px;background:rgba(37,211,102,.14);color:var(--wa);white-space:nowrap;}
.wa-tag.new{background:rgba(59,130,246,.16);color:#7fb0ff;}
.wa-preview{font-size:12.5px;color:var(--t2);margin-top:2px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.wa-me{color:var(--t3);}
.wa-phone{font-size:11px;color:#4b5563;margin-top:1px;font-variant-numeric:tabular-nums;}
.wa-item-right{display:flex;flex-direction:column;align-items:flex-end;gap:4px;white-space:nowrap;}
.wa-time{font-size:11px;color:var(--t3);}
.wa-wait{font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;}
.wa-wait.red{background:rgba(239,68,68,.14);color:#f87171;}
.wa-wait.amber{background:rgba(234,179,8,.14);color:#e5c158;}
.wa-wait.ok{background:rgba(37,211,102,.12);color:var(--wa);}

/* ── правая колонка ──────────────────────────────────────────────────── */
.wa-panel{background:var(--card);border:1px solid var(--line);border-radius:14px;
  min-height:520px;display:flex;flex-direction:column;overflow:hidden;}
.wa-panel-empty{margin:auto;text-align:center;color:var(--t3);padding:40px 20px;}
.wa-panel-empty i{font-size:30px;display:block;margin-bottom:12px;opacity:.5;}
.wa-panel-empty p{margin:0 0 6px;font-size:15px;font-weight:600;color:#f3f3f5;}
.wa-panel-empty span{font-size:13px;}

.wap-head{display:flex;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid var(--line);}
.wap-ava{width:38px;height:38px;border-radius:50%;flex:0 0 auto;background:rgba(37,211,102,.14);
  color:var(--wa);display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:800;}
.wap-who{flex:1;min-width:0;}
.wap-name{font-size:15px;font-weight:700;color:#fff;}
.wap-sub{font-size:12px;color:var(--t3);margin-top:1px;}
.wap-wait{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:20px;
  background:rgba(239,68,68,.14);color:#f87171;white-space:nowrap;}
.wap-open{color:var(--t3);text-decoration:none;padding:6px;border-radius:8px;}
.wap-open:hover{color:#fff;background:var(--card2);}

.wap-chat{flex:1;overflow-y:auto;max-height:60vh;padding:16px;background:#111114;}
.wap-day{display:flex;justify-content:center;margin:12px 0 10px;}
.wap-day:first-child{margin-top:0;}
.wap-day span{font-size:11px;font-weight:700;color:var(--t3);background:var(--card2);
  border-radius:20px;padding:4px 12px;}
.wap-msg{display:flex;margin-bottom:7px;}
.wap-msg.out{justify-content:flex-end;}
.wap-bubble{max-width:76%;padding:8px 11px;border-radius:12px;background:var(--card2);}
.wap-msg.out .wap-bubble{background:rgba(37,211,102,.14);border-top-right-radius:4px;}
.wap-msg.in .wap-bubble{border-top-left-radius:4px;}
.wap-text{font-size:13.5px;line-height:1.4;white-space:pre-wrap;word-break:break-word;}
.wap-other{color:var(--t3);font-style:italic;}
.wap-meta{font-size:10.5px;color:var(--t3);text-align:right;margin-top:3px;}

.wap-foot{display:flex;align-items:center;gap:12px;padding:12px 16px;border-top:1px solid var(--line);}
.wap-hint{flex:1;font-size:12px;color:var(--t3);}
.wap-reply{display:inline-flex;align-items:center;gap:7px;background:var(--wa);color:#04240f;
  border-radius:10px;padding:9px 16px;font-size:13.5px;font-weight:700;text-decoration:none;}
.wap-reply:hover{filter:brightness(1.06);color:#04240f;}

.wa-empty{text-align:center;padding:50px 20px;color:var(--t3);}
.wa-empty i{font-size:28px;display:block;margin-bottom:10px;opacity:.5;}
.wa-empty p{margin:0 0 6px;font-size:15px;font-weight:600;color:#f3f3f5;}
.wa-empty span{font-size:13px;}

@media (max-width: 900px){
  .wa-split{grid-template-columns:1fr;}
  .wa-panel{min-height:auto;}
  .wa-body{max-height:none;}
}
</style>

<script>
// Клик по диалогу подгружает переписку в правую колонку: перезагружать
// страницу ради одной переписки незачем, а очередь разбирают подряд.
(function () {
    const panel = document.getElementById('waPanel');
    const body = document.querySelector('.wa-body');
    if (!panel || !body) return;

    let active = null;

    async function openChat(item) {
        document.querySelectorAll('.wa-item.active').forEach(el => el.classList.remove('active'));
        item.classList.add('active');
        active = item.dataset.url;

        panel.innerHTML = '<div class="wa-panel-empty"><p>Загружаем переписку…</p></div>';

        try {
            const res = await fetch(item.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error('нет ответа');

            panel.innerHTML = await res.text();

            // Открываем на последнем сообщении, как в самом мессенджере.
            const chat = panel.querySelector('.wap-chat');
            if (chat) chat.scrollTop = chat.scrollHeight;
        } catch (e) {
            panel.innerHTML = '<div class="wa-panel-empty"><p>Не удалось загрузить переписку</p>'
                + '<span>Попробуйте ещё раз</span></div>';
        }
    }

    body.addEventListener('click', function (e) {
        const item = e.target.closest('.wa-item');
        if (item) openChat(item);
    });

    // Раз в 12 секунд спрашиваем id последнего сообщения. Пока он тот же —
    // не трогаем страницу: поиск, прокрутка и открытый диалог должны
    // переживать ожидание.
    const url = @json(route('club.whatsapp.updates'));
    let lastId = @json($lastId);

    async function poll() {
        if (document.hidden) return;
        try {
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) return;

            const data = await res.json();
            if (!data.last_id || data.last_id === lastId) return;
            lastId = data.last_id;

            const page = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!page.ok) return;

            const fresh = new DOMParser().parseFromString(await page.text(), 'text/html');
            const list = fresh.querySelector('.wa-body');
            if (list) {
                body.innerHTML = list.innerHTML;
                // Возвращаем подсветку открытому диалогу.
                if (active) {
                    const again = body.querySelector('[data-url="' + active + '"]');
                    if (again) again.classList.add('active');
                }
            }

            const tabs = fresh.querySelector('.wa-tabs');
            if (tabs) document.querySelector('.wa-tabs').innerHTML = tabs.innerHTML;
        } catch (e) { /* сеть моргнула — вернёмся через круг */ }
    }

    setInterval(poll, 12000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
</script>
@endsection

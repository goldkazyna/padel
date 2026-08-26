{{-- Список диалогов WhatsApp. Мягкая интеграция: только чтение того,
     что принёс вебхук Whapi.Cloud. --}}
@extends('layouts.app')

@section('title', 'WhatsApp')

@section('content')
<div class="wa-wrap">

    <div class="wa-head">
        <i class="bi bi-whatsapp wa-icon"></i>
        <div>
            <h1>WhatsApp</h1>
            <div class="wa-sub">{{ $club->name }} · входящие сообщения</div>
        </div>
        <div class="wa-count">
            <b>{{ $chats->count() }}</b>
            <span>{{ trans_choice('диалог|диалога|диалогов', $chats->count()) }}</span>
        </div>
    </div>

    <form method="GET" class="wa-search">
        <i class="bi bi-search"></i>
        <input type="text" name="search" value="{{ $search }}"
               placeholder="Имя, номер или текст сообщения" autocomplete="off">
        @if($search !== '')
            <a href="{{ route('club.whatsapp.index') }}" class="wa-clear">Сбросить</a>
        @endif
    </form>

    <div class="wa-body">
    @if($chats->isEmpty())
        <div class="wa-empty">
            <i class="bi bi-chat-dots"></i>
            @if($search !== '')
                <p>Ничего не нашли по запросу «{{ $search }}»</p>
            @elseif($total === 0)
                <p>Сообщений пока нет</p>
                <span>Как только на номер клуба напишут в WhatsApp, переписка появится здесь.</span>
            @else
                <p>Ничего не нашли</p>
            @endif
        </div>
    @else
        <div class="wa-list">
            @foreach($chats as $chat)
                @php
                    $client = $clients[substr($chat['phone'], -10)] ?? null;
                    $last = $chat['last'];
                @endphp
                <a href="{{ route('club.whatsapp.show', $chat['phone']) }}" class="wa-item">
                    <div class="wa-ava">{{ mb_strtoupper(mb_substr($client->name ?? $chat['name'] ?? '?', 0, 1)) }}</div>
                    <div class="wa-item-main">
                        <div class="wa-item-top">
                            <span class="wa-name">{{ $client->name ?? $chat['name'] ?? 'Без имени' }}</span>
                            @if($client)<span class="wa-tag">клиент</span>@endif
                            <span class="wa-time">{{ $last->sent_at->locale('ru')->translatedFormat('j M, H:i') }}</span>
                        </div>
                        <div class="wa-item-bottom">
                            <span class="wa-preview">
                                @if($last->from_me)<i class="bi bi-reply-fill"></i>@endif
                                {{ \Illuminate\Support\Str::limit($last->preview(), 90) }}
                            </span>
                            <span class="wa-phone">@phoneFmt($chat['phone'])</span>
                        </div>
                    </div>
                    <div class="wa-item-count">{{ $chat['total'] }}</div>
                </a>
            @endforeach
        </div>
    @endif
    </div>
</div>

<style>
.wa-wrap{max-width:900px;margin:0 auto;padding:20px 16px 40px;color:#f4f4f5;
  --card:#16161a;--card2:#1e1e24;--line:#27272a;--wa:#25d366;--t2:#a1a1aa;--t3:#71717a;}
.wa-head{display:flex;align-items:center;gap:14px;margin-bottom:18px;}
.wa-head h1{font-size:22px;font-weight:800;margin:0;}
.wa-icon{font-size:26px;color:var(--wa);}
.wa-sub{color:var(--t3);font-size:13px;margin-top:2px;}
.wa-count{margin-left:auto;text-align:right;}
.wa-count b{display:block;font-size:20px;font-weight:800;color:var(--wa);}
.wa-count span{font-size:11px;color:var(--t3);}

.wa-search{display:flex;align-items:center;gap:9px;background:var(--card);border:1px solid var(--line);
  border-radius:12px;padding:10px 14px;margin-bottom:16px;}
.wa-search i{color:var(--t3);}
.wa-search input{flex:1;background:none;border:none;color:#f3f3f5;font-size:14px;outline:none;}
.wa-clear{color:var(--t2);font-size:12.5px;text-decoration:none;white-space:nowrap;}

.wa-list{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:8px;}
.wa-item{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:10px;
  text-decoration:none;color:inherit;}
.wa-item:hover{background:var(--card2);}
.wa-ava{width:40px;height:40px;border-radius:12px;flex:0 0 auto;background:rgba(37,211,102,.14);
  color:var(--wa);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;}
.wa-item-main{flex:1;min-width:0;}
.wa-item-top{display:flex;align-items:center;gap:8px;}
.wa-name{font-size:14.5px;font-weight:700;color:#fff;}
.wa-tag{font-size:9.5px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;
  padding:2px 7px;border-radius:6px;background:rgba(37,211,102,.14);color:var(--wa);}
.wa-time{margin-left:auto;font-size:11.5px;color:var(--t3);white-space:nowrap;}
.wa-item-bottom{display:flex;align-items:baseline;gap:10px;margin-top:3px;}
.wa-preview{flex:1;min-width:0;font-size:13px;color:var(--t2);overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap;}
.wa-preview i{font-size:10px;color:var(--t3);margin-right:3px;}
.wa-phone{font-size:11.5px;color:var(--t3);white-space:nowrap;font-variant-numeric:tabular-nums;}
.wa-item-count{font-size:12px;font-weight:800;color:var(--t3);background:var(--card2);
  border-radius:8px;padding:3px 9px;}

.wa-empty{text-align:center;padding:60px 20px;background:var(--card);
  border:1px dashed var(--line);border-radius:14px;color:var(--t3);}
.wa-empty i{font-size:30px;display:block;margin-bottom:12px;opacity:.5;}
.wa-empty p{margin:0 0 6px;font-size:15px;font-weight:600;color:#f3f3f5;}
.wa-empty span{font-size:13px;}
</style>

<script>
// Раз в 12 секунд спрашиваем id последнего сообщения. Пока он тот же —
// не трогаем страницу: поиск и прокрутка должны переживать ожидание.
(function () {
    const body = document.querySelector('.wa-body');
    if (!body) return;

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

            // Список перерисовываем тем же адресом — вместе с поиском в нём.
            const page = await fetch(location.href, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!page.ok) return;

            const fresh = new DOMParser().parseFromString(await page.text(), 'text/html');
            const list = fresh.querySelector('.wa-body');
            const count = fresh.querySelector('.wa-count');
            if (list) body.innerHTML = list.innerHTML;
            if (count) document.querySelector('.wa-count').innerHTML = count.innerHTML;
        } catch (e) { /* сеть моргнула — вернёмся через круг */ }
    }

    setInterval(poll, 12000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
</script>
@endsection

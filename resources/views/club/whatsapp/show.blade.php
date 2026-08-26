{{-- Один диалог WhatsApp: сообщения по дням, как в самом мессенджере. --}}
@extends('layouts.app')

@section('title', 'WhatsApp — ' . ($client->name ?? $name ?? $phone))

@section('content')
@php $tz = config('app.schedule_timezone', 'Asia/Almaty'); @endphp
<div class="wa-wrap">

    <div class="wa-head">
        <a href="{{ route('club.whatsapp.index') }}" class="wa-back"><i class="bi bi-arrow-left"></i></a>
        <div class="wa-ava wa-ava-lg">{{ mb_strtoupper(mb_substr($client->name ?? $name ?? '?', 0, 1)) }}</div>
        <div>
            <h1>{{ $client->name ?? $name ?? 'Без имени' }}</h1>
            <div class="wa-sub">
                @phoneFmt($phone)
                @if($client)
                    · <a href="{{ route('club.clients.show', $client) }}" class="wa-link">карточка клиента</a>
                @else
                    · нет в клиентах
                @endif
            </div>
        </div>
        <div class="wa-count">
            <b>{{ $messages->count() }}</b>
            <span>{{ trans_choice('сообщение|сообщения|сообщений', $messages->count()) }}</span>
        </div>
    </div>

    <div class="wa-chat">
        @foreach($days as $date => $dayMessages)
            <div class="wa-day" data-date="{{ $date }}">
                <span>{{ \Carbon\Carbon::parse($date)->locale('ru')->translatedFormat('j F Y') }}</span>
            </div>
            @foreach($dayMessages as $m)
                <div class="wa-msg {{ $m->from_me ? 'out' : 'in' }}" data-id="{{ $m->id }}">
                    <div class="wa-bubble">
                        @if($m->body)
                            <div class="wa-text">{{ $m->body }}</div>
                        @else
                            <div class="wa-text wa-other">{{ $m->preview() }}</div>
                        @endif
                        <div class="wa-meta">
                            {{ $m->sent_at->timezone($tz)->format('H:i') }}
                            @if($m->from_me)<i class="bi bi-check2-all"></i>@endif
                        </div>
                    </div>
                </div>
            @endforeach
        @endforeach
    </div>

    <div class="wa-note">
        <i class="bi bi-info-circle"></i>
        Пока только чтение: сообщения приходят из WhatsApp, отвечать нужно из самого мессенджера.
    </div>
</div>

<style>
.wa-wrap{max-width:900px;margin:0 auto;padding:20px 16px 40px;color:#f4f4f5;
  --card:#16161a;--card2:#1e1e24;--line:#27272a;--wa:#25d366;--t2:#a1a1aa;--t3:#71717a;}
.wa-head{display:flex;align-items:center;gap:14px;margin-bottom:18px;}
.wa-head h1{font-size:20px;font-weight:800;margin:0;}
.wa-back{width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;
  background:var(--card);border:1px solid var(--line);border-radius:10px;color:var(--t2);text-decoration:none;}
.wa-ava{width:40px;height:40px;border-radius:12px;flex:0 0 auto;background:rgba(37,211,102,.14);
  color:var(--wa);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;}
.wa-sub{color:var(--t3);font-size:13px;margin-top:2px;}
.wa-link{color:var(--wa);text-decoration:none;}
.wa-count{margin-left:auto;text-align:right;}
.wa-count b{display:block;font-size:20px;font-weight:800;color:var(--wa);}
.wa-count span{font-size:11px;color:var(--t3);}

.wa-chat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;}
.wa-day{display:flex;justify-content:center;margin:14px 0 10px;}
.wa-day:first-child{margin-top:0;}
.wa-day span{font-size:11px;font-weight:700;letter-spacing:.4px;color:var(--t3);
  background:var(--card2);border-radius:20px;padding:4px 12px;text-transform:lowercase;}
.wa-msg{display:flex;margin-bottom:7px;}
.wa-msg.out{justify-content:flex-end;}
.wa-bubble{max-width:74%;padding:8px 11px 6px;border-radius:12px;background:var(--card2);}
.wa-msg.out .wa-bubble{background:rgba(37,211,102,.14);}
.wa-text{font-size:13.5px;line-height:1.45;white-space:pre-wrap;word-break:break-word;}
.wa-other{color:var(--t2);font-style:italic;}
.wa-meta{margin-top:3px;text-align:right;font-size:10.5px;color:var(--t3);
  font-variant-numeric:tabular-nums;}
.wa-meta i{font-size:11px;margin-left:3px;color:var(--wa);}

.wa-note{display:flex;align-items:center;gap:8px;margin-top:14px;color:var(--t3);font-size:12.5px;}

/* Пришло, пока страница открыта. */
.wa-new .wa-bubble{animation:wa-pop .35s ease-out;}
@keyframes wa-pop{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:none;}}
@media (prefers-reduced-motion:reduce){.wa-new .wa-bubble{animation:none;}}
</style>

<script>
// Новые сообщения дорисовываем сами: перезагрузка страницы сбрасывала бы
// прокрутку, а переписка читается сверху вниз.
(function () {
    const chat = document.querySelector('.wa-chat');
    if (!chat) return;

    const url = @json(route('club.whatsapp.chat-updates', $phone));
    let lastId = Math.max(0, ...[...chat.querySelectorAll('.wa-msg')].map(el => +el.dataset.id || 0));
    let lastDate = [...chat.querySelectorAll('.wa-day')].pop()?.dataset.date || '';

    const atBottom = () =>
        window.innerHeight + window.scrollY >= document.body.offsetHeight - 120;

    function draw(m) {
        if (m.date !== lastDate) {
            const day = document.createElement('div');
            day.className = 'wa-day';
            day.dataset.date = m.date;
            day.innerHTML = '<span></span>';
            day.firstChild.textContent = m.day;
            chat.appendChild(day);
            lastDate = m.date;
        }

        const row = document.createElement('div');
        row.className = 'wa-msg wa-new ' + (m.from_me ? 'out' : 'in');
        row.dataset.id = m.id;

        const bubble = document.createElement('div');
        bubble.className = 'wa-bubble';

        const text = document.createElement('div');
        text.className = m.plain ? 'wa-text' : 'wa-text wa-other';
        text.textContent = m.text;

        const meta = document.createElement('div');
        meta.className = 'wa-meta';
        meta.textContent = m.time;
        if (m.from_me) meta.innerHTML += ' <i class="bi bi-check2-all"></i>';

        bubble.append(text, meta);
        row.appendChild(bubble);
        chat.appendChild(row);
    }

    async function poll() {
        if (document.hidden) return;
        try {
            const res = await fetch(url + '?after=' + lastId, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) return;

            const data = await res.json();
            if (!data.messages || !data.messages.length) return;

            const stick = atBottom();
            data.messages.forEach(m => { draw(m); lastId = Math.max(lastId, m.id); });
            if (stick) window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
        } catch (e) { /* сеть моргнула — попробуем на следующем круге */ }
    }

    setInterval(poll, 12000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
</script>
@endsection

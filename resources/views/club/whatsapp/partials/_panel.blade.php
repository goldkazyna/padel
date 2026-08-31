{{--
    Переписка в правой колонке списка диалогов.

    Ждёт $phone, $name, $client, $days (сообщения по дням), $total, $waited.
    Отдаётся отдельным запросом при клике по диалогу: перезагружать всю
    страницу ради одной переписки незачем.
--}}
@php $tz = config('app.schedule_timezone', 'Asia/Almaty'); @endphp

<div class="wap-head">
    <div class="wap-ava">{{ mb_strtoupper(mb_substr($client->name ?? $name ?? '?', 0, 1)) }}</div>
    <div class="wap-who">
        <div class="wap-name">{{ $client->name ?? $name ?? 'Без имени' }}</div>
        <div class="wap-sub">
            @phoneFmt($phone) · {{ $total }} {{ trans_choice('сообщение|сообщения|сообщений', $total) }}
            @if($client) · клиент клуба @endif
        </div>
    </div>

    @if($waited !== null)
        <span class="wap-wait">ждёт {{ \App\Support\WhatsappSla::humanMinutes((int) $waited) }}</span>
    @endif

    <a href="{{ route('club.whatsapp.show', $phone) }}" class="wap-open" title="Открыть отдельной страницей">
        <i class="bi bi-box-arrow-up-right"></i>
    </a>
</div>

<div class="wap-chat" id="waPanelChat">
    @foreach($days as $date => $dayMessages)
        <div class="wap-day"><span>{{ \Carbon\Carbon::parse($date)->locale('ru')->translatedFormat('j F Y') }}</span></div>

        @foreach($dayMessages as $m)
            <div class="wap-msg {{ $m->from_me ? 'out' : 'in' }}">
                <div class="wap-bubble">
                    {{-- Тип без текста (голосовое, служебное событие) подписываем --}}
                    <div class="wap-text {{ $m->body ? '' : 'wap-other' }}">{{ $m->body ?: $m->preview() }}</div>
                    <div class="wap-meta">{{ $m->sent_at->timezone($tz)->format('H:i') }}</div>
                </div>
            </div>
        @endforeach
    @endforeach
</div>

<div class="wap-foot">
    <span class="wap-hint">
        <i class="bi bi-info-circle"></i>
        CRM только собирает переписку — отвечаем из WhatsApp
    </span>
    <a class="wap-reply" target="_blank" rel="noopener"
       href="https://wa.me/{{ preg_replace('/\D/', '', $phone) }}">
        <i class="bi bi-whatsapp"></i> Ответить
    </a>
</div>

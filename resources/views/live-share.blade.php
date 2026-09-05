<!DOCTYPE html>
<html lang="ru">
<head>
    {{-- Иконка сайта: ракетка из иконки приложения Padel KZ --}}
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon-180.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Смотреть «{{ $tournament->name }}» — Padel KZ</title>

    @php
        $clubName = $tournament->club?->name ?? ($tournament->creator?->name ?? 'Padel KZ');
        $isLive = $tournament->status === 'in_progress';
        $ogTitle = $isLive
            ? 'Прямой эфир: «' . $tournament->name . '»'
            : 'Результаты: «' . $tournament->name . '»';
        $ogDescription = $isLive
            ? "{$clubName} · {$tournament->type_name}\nСчёт и таблица обновляются по ходу игры"
            : "{$clubName} · {$tournament->type_name}\nРаунды, счета и итоговая таблица";
    @endphp

    <meta property="og:site_name" content="Padel KZ">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/live/'.$tournament->id) }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            background: #0A0A0D;
            color: #fff;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            text-align: center;
        }
        .logo {
            width: 84px;
            height: 84px;
            object-fit: contain;
            margin-bottom: 24px;
        }
        .live {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 12px;
            border-radius: 999px;
            background: rgba(239, 68, 68, 0.14);
            color: #F87171;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        .live .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #EF4444;
            animation: pulse 1.4s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.25; }
        }
        @media (prefers-reduced-motion: reduce) {
            .live .dot { animation: none; }
        }
        h1 {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 8px;
            line-height: 1.2;
            max-width: 360px;
        }
        .meta {
            color: #A1A1AA;
            font-size: 14px;
            margin-bottom: 32px;
            max-width: 360px;
        }
        .btn {
            display: inline-block;
            padding: 14px 28px;
            border-radius: 12px;
            background: #22C55E;
            color: #0A0A0D;
            font-weight: 700;
            text-decoration: none;
            font-size: 15px;
            margin-bottom: 16px;
            min-width: 240px;
        }
        .btn-secondary {
            background: transparent;
            color: #A1A1AA;
            border: 1px solid #27272A;
        }
        .hint {
            color: #71717A;
            font-size: 12px;
            margin-top: 16px;
            max-width: 320px;
        }
    </style>
</head>
<body>
    <img src="/favicon-512.png" alt="Padel KZ" class="logo">

    @if($tournament->status === 'in_progress')
        <div class="live"><span class="dot"></span>Идёт сейчас</div>
    @endif

    <h1>{{ $tournament->name }}</h1>
    <div class="meta">
        {{ $tournament->club?->name ?? 'Padel-турнир' }}<br>
        {{ $tournament->type_name }}
    </div>

    <a href="padelp://live/{{ $tournament->id }}" class="btn" id="open-app">
        Смотреть в приложении
    </a>

    <a href="{{ $storeUrl }}" class="btn btn-secondary">
        Скачать Padel KZ
    </a>

    <div class="hint">
        Ссылка откроет трансляцию турнира в приложении Padel KZ.<br>
        Если приложение не установлено — попадёте в магазин.
    </div>

    <script>
        (function () {
            var ua = navigator.userAgent || navigator.vendor || window.opera;
            var isAndroid = /android/i.test(ua);
            var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
            var storeUrl = {!! json_encode($storeUrl) !!};
            var deepLink = 'padelp://live/{{ $tournament->id }}';

            // Только на мобилках пробуем deep link
            if (!isAndroid && !isIOS) return;

            var fallbackTimer = setTimeout(function () {
                window.location.href = storeUrl;
            }, 1800);

            // Если страница ушла в фон (= приложение открылось) — не ходим в стор
            window.addEventListener('blur', function () {
                clearTimeout(fallbackTimer);
            });
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) clearTimeout(fallbackTimer);
            });

            window.location.href = deepLink;
        })();
    </script>
</body>
</html>

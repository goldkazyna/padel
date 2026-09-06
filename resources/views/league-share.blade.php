<!DOCTYPE html>
<html lang="ru">
<head>
    {{-- Иконка сайта: ракетка из иконки приложения Padel KZ --}}
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon-180.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Лига «{{ $league->name }}» — Padel KZ</title>

    @php
        $clubName = $league->club?->name ?? 'Padel KZ';
        $period = $league->start_date
            ? $league->start_date->format('d.m.Y')
                . ($league->end_date ? ' — ' . $league->end_date->format('d.m.Y') : '')
            : null;
        $ogTitle = 'Лига «' . $league->name . '»';
        $ogDescription = $league->status === 'open'
            ? "{$clubName} · идёт запись\n" . ($period ?: 'Этапы, таблица и результаты — в приложении')
            : "{$clubName} · {$league->status_name}\n" . ($period ?: 'Этапы, таблица и результаты — в приложении');
    @endphp

    <meta property="og:site_name" content="Padel KZ">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/l/'.$league->id) }}">
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
            background: rgba(34, 197, 94, 0.14);
            color: #22C55E;
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
            background: #22C55E;
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

    @if($league->status === 'open')
        <div class="live"><span class="dot"></span>Идёт запись</div>
    @elseif($league->status === 'in_progress')
        <div class="live"><span class="dot"></span>Лига идёт</div>
    @endif

    <h1>{{ $league->name }}</h1>
    <div class="meta">
        {{ $league->club?->name ?? 'Padel-лига' }}<br>
        {{ $period ?: $league->status_name }}
    </div>

    <a href="padelp://league/{{ $league->id }}" class="btn" id="open-app">
        Открыть в приложении
    </a>

    <a href="{{ $storeUrl }}" class="btn btn-secondary">
        Скачать Padel KZ
    </a>

    <div class="hint">
        Ссылка откроет лигу в приложении Padel KZ.<br>
        Если приложение не установлено — попадёте в магазин.
    </div>

    <script>
        (function () {
            var ua = navigator.userAgent || navigator.vendor || window.opera;
            var isAndroid = /android/i.test(ua);
            var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
            var storeUrl = {!! json_encode($storeUrl) !!};
            var deepLink = 'padelp://league/{{ $league->id }}';

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

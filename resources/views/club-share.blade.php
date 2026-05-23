<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $club->name }}{{ $club->city ? ' — ' . $club->city : '' }} — Padel KZ</title>

    @php
        $ogDescription = $club->city ?? ($club->description ? \Illuminate\Support\Str::limit($club->description, 120) : 'Padel KZ');
    @endphp

    <meta property="og:site_name" content="Padel KZ">
    <meta property="og:title" content="{{ $club->name }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/c/'.$club->id) }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $club->name }}">
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
            width: 64px; height: 64px;
            border-radius: 16px;
            background: linear-gradient(135deg, #22C55E, #16A34A);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 24px;
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
    <div class="logo">🏟️</div>
    <h1>{{ $club->name }}</h1>
    <div class="meta">
        @if($club->city){{ $club->city }}@endif
    </div>

    <a href="padelp://club/{{ $club->id }}" class="btn" id="open-app">
        Открыть в приложении
    </a>

    <a href="{{ $storeUrl }}" class="btn btn-secondary">
        Скачать Padel KZ
    </a>

    <div class="hint">
        Ссылка откроет клуб в приложении Padel KZ.<br>
        Если приложение не установлено — попадёте в магазин.
    </div>

    <script>
        (function () {
            var ua = navigator.userAgent || navigator.vendor || window.opera;
            var isAndroid = /android/i.test(ua);
            var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
            var storeUrl = {!! json_encode($storeUrl) !!};
            var deepLink = 'padelp://club/{{ $club->id }}';

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

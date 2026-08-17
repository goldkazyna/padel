<!DOCTYPE html>
<html lang="ru">
<head>
    {{-- Иконка сайта: ракетка из иконки приложения Padel KZ --}}
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon-180.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отказ от ответственности — {{ $club->name }}</title>

    <meta property="og:site_name" content="Padel KZ">
    <meta property="og:title" content="Отказ от ответственности — {{ $club->name }}">
    <meta property="og:description" content="Подписывается в приложении Padel KZ">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/w/'.$club->id) }}">

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
    <h1>{{ $club->name }}</h1>

    @if($club->collectsWaiver())
        <div class="meta">
            Отказ от ответственности
        </div>

        <a href="padelp://waiver/{{ $club->id }}" class="btn" id="open-app">
            Открыть в приложении
        </a>

        <a href="{{ $storeUrl }}" class="btn btn-secondary">
            Скачать Padel KZ
        </a>

        <div class="hint">
            Ссылка откроет отказ в приложении Padel KZ.<br>
            Если приложения нет — установите его и отсканируйте код ещё раз.
        </div>

        <script>
            (function () {
                var ua = navigator.userAgent || navigator.vendor || window.opera;
                var isAndroid = /android/i.test(ua);
                var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
                var downloadUrl = {!! json_encode(url('/download')) !!};
                var deepLink = 'padelp://waiver/{{ $club->id }}';

                // Только на мобилках пробуем deep link
                if (!isAndroid && !isIOS) return;

                var fallbackTimer = setTimeout(function () {
                    window.location.href = downloadUrl;
                }, 1800);

                // Если страница ушла в фон (= приложение открылось) — не уводим
                window.addEventListener('blur', function () {
                    clearTimeout(fallbackTimer);
                });
                document.addEventListener('visibilitychange', function () {
                    if (document.hidden) clearTimeout(fallbackTimer);
                });

                window.location.href = deepLink;
            })();
        </script>
    @else
        <div class="meta">
            Этот клуб не собирает отказ от ответственности.
        </div>
    @endif
</body>
</html>

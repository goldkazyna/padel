<!DOCTYPE html>
<html lang="ru">
<head>
    {{-- Иконка сайта: ракетка из иконки приложения Padel KZ --}}
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon-180.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    @php
        $clubName = $game->club?->name ?? 'Padel KZ';
        // Время игры лежит в часах клуба (Алматы) при app.timezone=UTC —
        // печатаем как есть, ничего не сдвигая. Правило — в SYSTEM_RULES.
        $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
            'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $when = $game->starts_at
            ? $game->starts_at->day . ' ' . $months[(int) $game->starts_at->month]
                . ', ' . $game->starts_at->format('H:i')
            : null;
        $free = count($game->getAvailablePositions());
        $ogTitle = 'Игра в «' . $clubName . '»';
        $ogDescription = trim(
            ($when ? $when . ' · ' : '')
            . $game->format_name
            . ($free > 0 ? ' · свободно мест: ' . $free : ' · мест нет')
        );
    @endphp

    <title>{{ $ogTitle }} — Padel KZ</title>

    <meta property="og:site_name" content="Padel KZ">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/g/'.$game->id) }}">
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
            gap: 8px;
            background: rgba(34, 197, 94, .14);
            color: #22C55E;
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 14px;
        }
        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #22C55E;
        }
        h1 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 10px;
            max-width: 420px;
        }
        .meta {
            color: #A1A1AA;
            font-size: 15px;
            line-height: 1.5;
            margin-bottom: 28px;
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

    @if($free > 0)
        <div class="live"><span class="dot"></span>Свободно мест: {{ $free }}</div>
    @endif

    <h1>Игра в «{{ $clubName }}»</h1>
    <div class="meta">
        {{ $when ?: 'Дата уточняется' }}<br>
        {{ $game->format_name }}{{ $game->price ? ' · ' . number_format($game->price, 0, '.', ' ') . ' ₸' : '' }}
    </div>

    <a href="padelp://game/{{ $game->id }}" class="btn" id="open-app">
        Открыть в приложении
    </a>

    <a href="{{ $storeUrl }}" class="btn btn-secondary">
        Скачать Padel KZ
    </a>

    <div class="hint">
        Ссылка откроет игру в приложении Padel KZ.<br>
        Если приложение не установлено — попадёте в магазин.
    </div>

    <script>
        (function () {
            var ua = navigator.userAgent || navigator.vendor || window.opera;
            var isAndroid = /android/i.test(ua);
            var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
            var storeUrl = {!! json_encode($storeUrl) !!};
            var deepLink = 'padelp://game/{{ $game->id }}';

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

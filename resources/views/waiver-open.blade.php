<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Отказ от ответственности — {{ $club->name }}</title>
    <style>
        body {
            margin: 0; background: #0c0e0f; color: #fff; min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
            display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .box { max-width: 400px; width: 100%; text-align: center; }
        .logo { width: 72px; height: 72px; border-radius: 20px; object-fit: cover; margin-bottom: 18px; }
        h1 { font-size: 22px; font-weight: 800; margin: 0 0 8px; }
        p { color: #9ca3af; font-size: 15px; line-height: 1.5; margin: 0 0 22px; }
        .btn {
            display: block; text-decoration: none; font-weight: 700; font-size: 15px;
            padding: 15px; border-radius: 13px; margin-bottom: 10px;
            background: #22c55e; color: #08130c;
        }
        .btn-sec { background: transparent; color: #9ca3af; border: 1px solid #2a2d2e; }
        .note { color: #6b7280; font-size: 13px; line-height: 1.5; margin-top: 18px; }
        .note b { color: #9ca3af; }
    </style>
</head>
<body>
<div class="box">
    @if($club->logo_url)
        <img class="logo" src="{{ $club->logo_url }}" alt="{{ $club->name }}">
    @endif
    <h1>{{ $club->name }}</h1>

    @if($club->collectsWaiver())
        <p>Отказ от ответственности подписывается в приложении Padel KZ.</p>
        <a class="btn" href="padelp://waiver/{{ $club->id }}">Открыть в приложении</a>
        <a class="btn btn-sec" href="{{ $storeUrl }}">Установить приложение</a>
        <div class="note">
            Если приложения ещё нет — установите его и <b>отсканируйте код ещё раз</b>:
            ссылка сама после установки не откроется.
        </div>
    @else
        <p>Этот клуб не собирает отказ от ответственности.</p>
    @endif
</div>

@if($club->collectsWaiver())
    <script>
        (function () {
            var ua = navigator.userAgent || navigator.vendor || window.opera;
            var isAndroid = /android/i.test(ua);
            var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
            var storeUrl = {!! json_encode($storeUrl) !!};
            var deepLink = 'padelp://waiver/{{ $club->id }}';

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
@endif
</body>
</html>

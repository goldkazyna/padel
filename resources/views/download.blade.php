<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Скачать приложение Padel KZ</title>

    <meta property="og:site_name" content="Padel KZ">
    <meta property="og:title" content="Скачать приложение Padel KZ">
    <meta property="og:description" content="Турниры, рейтинг, бронирование кортов — всё в одном приложении.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/download') }}">
    <meta property="og:image" content="{{ asset('logos/add-padel-almaty.jpg') }}">

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            background: radial-gradient(1200px 600px at 50% -10%, #11251a 0%, #0A0A0D 55%);
            color: #fff;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100%;
            padding: 40px 24px;
            text-align: center;
        }
        .wrap { width: 100%; max-width: 380px; }
        .logo {
            width: 84px; height: 84px;
            border-radius: 22px;
            background: linear-gradient(135deg, #22C55E, #16A34A);
            display: flex; align-items: center; justify-content: center;
            font-size: 42px;
            margin: 0 auto 22px;
            box-shadow: 0 12px 40px rgba(34,197,94,0.35);
        }
        h1 { font-size: 26px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 8px; }
        .subtitle { color: #A1A1AA; font-size: 15px; line-height: 1.5; margin-bottom: 32px; }

        .stores { display: flex; flex-direction: column; gap: 14px; }
        .store-btn {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 20px;
            border-radius: 16px;
            background: #16161A;
            border: 1px solid #27272A;
            text-decoration: none;
            color: #fff;
            transition: transform 0.15s, border-color 0.2s, background 0.2s;
        }
        .store-btn:hover { transform: translateY(-2px); border-color: #3F3F46; background: #1C1C21; }
        .store-btn svg { width: 28px; height: 28px; flex-shrink: 0; fill: #fff; }
        .store-btn .txt { text-align: left; line-height: 1.25; }
        .store-btn .txt .small { font-size: 11px; color: #A1A1AA; text-transform: uppercase; letter-spacing: 0.4px; }
        .store-btn .txt .big { font-size: 17px; font-weight: 700; }

        /* Рекомендованный магазин под платформу устройства */
        .store-btn.recommended {
            background: linear-gradient(135deg, rgba(34,197,94,0.18), rgba(22,163,74,0.10));
            border-color: rgba(34,197,94,0.55);
            box-shadow: 0 6px 24px rgba(34,197,94,0.18);
        }
        .reco-tag {
            display: inline-block;
            margin-bottom: 10px;
            font-size: 11px; font-weight: 700;
            color: #22C55E;
            background: rgba(34,197,94,0.12);
            border: 1px solid rgba(34,197,94,0.3);
            padding: 5px 12px; border-radius: 999px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        .hint { color: #52525B; font-size: 12px; margin-top: 28px; line-height: 1.5; }
        .features { display: flex; justify-content: center; gap: 18px; margin-bottom: 30px; flex-wrap: wrap; }
        .feature { color: #71717A; font-size: 12px; display: flex; align-items: center; gap: 6px; }
        .feature .dot { width: 6px; height: 6px; border-radius: 50%; background: #22C55E; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="logo">🎾</div>
        <h1>Padel KZ</h1>
        <div class="subtitle">Турниры, рейтинг и бронирование кортов —<br>всё в одном приложении</div>

        <div class="features">
            <span class="feature"><span class="dot"></span>Турниры</span>
            <span class="feature"><span class="dot"></span>Рейтинг</span>
            <span class="feature"><span class="dot"></span>Корты</span>
        </div>

        @php
            // App Store блок
            $appStore = '<a href="' . e($iosUrl) . '" class="store-btn STORE_CLASS_IOS" id="btn-ios">'
                . '<svg viewBox="0 0 384 512"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>'
                . '<span class="txt"><span class="small">Загрузить в</span><span class="big">App Store</span></span></a>';

            // Google Play блок
            $googlePlay = '<a href="' . e($androidUrl) . '" class="store-btn STORE_CLASS_ANDROID" id="btn-android">'
                . '<svg viewBox="0 0 512 512"><path d="M325.3 234.3L104.6 13l280.8 161.2-60.1 60.1zM47 0C34 6.8 25.3 19.2 25.3 35.3v441.3c0 16.1 8.7 28.5 21.7 35.3l256.6-256L47 0zm425.2 225.6l-58.9-34.1-65.7 64.5 65.7 64.5 60.1-34.1c18-14.3 18-46.5-1.2-60.8zM104.6 499l280.8-161.2-60.1-60.1L104.6 499z"/></svg>'
                . '<span class="txt"><span class="small">Доступно в</span><span class="big">Google Play</span></span></a>';

            $appStore   = str_replace('STORE_CLASS_IOS', $platform === 'ios' ? 'recommended' : '', $appStore);
            $googlePlay = str_replace('STORE_CLASS_ANDROID', $platform === 'android' ? 'recommended' : '', $googlePlay);
        @endphp

        @if($platform !== 'other')
            <div class="reco-tag">Рекомендуем для вашего устройства</div>
        @endif

        <div class="stores">
            @if($platform === 'android')
                {!! $googlePlay !!}
                {!! $appStore !!}
            @else
                {!! $appStore !!}
                {!! $googlePlay !!}
            @endif
        </div>

        <div class="hint">Отсканируйте — выберите свой магазин.<br>Приложение бесплатное.</div>
    </div>
</body>
</html>

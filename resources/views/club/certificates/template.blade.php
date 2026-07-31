<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сертификат {{ $certificate->number }}</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Georgia', 'Times New Roman', serif;
            background:#3f3f46;
            display:flex; flex-direction:column; align-items:center;
            padding:24px; min-height:100vh;
        }
        .toolbar { margin-bottom:18px; display:flex; gap:10px; }
        .toolbar button {
            font-family:-apple-system,Segoe UI,Roboto,sans-serif;
            padding:10px 22px; border:none; border-radius:8px;
            background:#22C55E; color:#062e15; font-weight:700; cursor:pointer; font-size:.95rem;
        }
        .toolbar a {
            font-family:-apple-system,Segoe UI,Roboto,sans-serif;
            padding:10px 22px; border-radius:8px; background:#27272a; color:#e4e4e7;
            text-decoration:none; font-size:.95rem;
        }

        .cert {
            width: 1000px; max-width:100%;
            aspect-ratio: 1.414 / 1;               /* A4 альбомная */
            background: #fbfaf6;
            position:relative;
            box-shadow:0 10px 40px rgba(0,0,0,.35);
            display:flex; align-items:center; justify-content:center;
        }
        /* Двойная декоративная рамка */
        .cert::before {
            content:''; position:absolute; inset:24px;
            border:3px solid #1f6b3b;
        }
        .cert::after {
            content:''; position:absolute; inset:32px;
            border:1px solid #c9a24b;
        }
        .cert-inner {
            position:relative; z-index:1; text-align:center;
            padding:70px 90px; width:100%;
        }
        .cert-club {
            font-family:-apple-system,Segoe UI,Roboto,sans-serif;
            text-transform:uppercase; letter-spacing:3px;
            color:#1f6b3b; font-weight:700; font-size:1rem; margin-bottom:26px;
        }
        .cert-title {
            font-size:3.4rem; letter-spacing:6px; color:#14532d;
            text-transform:uppercase; font-weight:700;
        }
        .cert-rule { width:120px; height:3px; background:#c9a24b; margin:18px auto 30px; }
        .cert-sub {
            font-family:-apple-system,Segoe UI,Roboto,sans-serif;
            color:#57534e; font-size:1.05rem; margin-bottom:14px;
        }
        .cert-name {
            font-size:2.4rem; color:#111; font-weight:700;
            border-bottom:2px solid #c9a24b; display:inline-block;
            padding:2px 26px 10px; margin-bottom:24px; min-width:340px;
        }
        .cert-name.generic { border-bottom:2px dashed #a8a29e; color:#78716c; min-width:420px; }
        .cert-reason {
            font-family:-apple-system,Segoe UI,Roboto,sans-serif;
            color:#44403c; font-size:1.15rem; max-width:640px; margin:0 auto 40px;
        }
        .cert-foot {
            display:flex; justify-content:space-between; align-items:flex-end;
            font-family:-apple-system,Segoe UI,Roboto,sans-serif;
            margin-top:24px; color:#57534e; font-size:.9rem;
        }
        .cert-foot .lbl { color:#a8a29e; font-size:.72rem; text-transform:uppercase; letter-spacing:1px; }
        .cert-num { font-family:monospace; font-size:1rem; color:#1f6b3b; font-weight:700; }

        @media print {
            body { background:#fff; padding:0; }
            .toolbar { display:none; }
            .cert { box-shadow:none; width:100%; }
            @page { size: A4 landscape; margin:0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">🖨 Печать / PDF</button>
        <a href="{{ route('club.certificates.index') }}">← К списку</a>
    </div>

    <div class="cert">
        <div class="cert-inner">
            <div class="cert-club">{{ $club->name }}</div>
            <div class="cert-title">Сертификат</div>
            <div class="cert-rule"></div>

            @if($certificate->isNamed())
                <div class="cert-sub">Настоящим удостоверяется, что</div>
                <div class="cert-name">{{ $certificate->recipient_name }}</div>
            @else
                <div class="cert-sub">Настоящий сертификат выдан</div>
                <div class="cert-name generic">Предъявителю</div>
            @endif

            <div class="cert-reason">
                {{ $certificate->title ?: 'подтверждает право на получение услуг клуба.' }}
            </div>

            <div class="cert-foot">
                <div>
                    <div class="lbl">Номер сертификата</div>
                    <div class="cert-num">{{ $certificate->number }}</div>
                </div>
                <div style="text-align:right">
                    <div class="lbl">Дата выдачи</div>
                    <div>{{ $certificate->created_at->format('d.m.Y') }}</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

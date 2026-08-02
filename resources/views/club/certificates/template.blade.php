<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сертификат {{ $certificate->number }}</title>
    @php
        $bg = $template->background_color ?? '#fbfaf6';
        $accent = $template->accent_color ?? '#c9a24b';
        $border = $template->border_color ?? '#1f6b3b';
        $textColor = $template->text_color ?? '#14532d';
        $portrait = ($template->orientation ?? 'landscape') === 'portrait';
        $ratio = $portrait ? '0.707 / 1' : '1.414 / 1';
        $pageSize = $portrait ? 'A4 portrait' : 'A4 landscape';
    @endphp
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Georgia','Times New Roman',serif;
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
            width:{{ $portrait ? '720px' : '1000px' }}; max-width:100%;
            aspect-ratio:{{ $ratio }};
            background:{{ $bg }};
            position:relative;
            box-shadow:0 10px 40px rgba(0,0,0,.35);
            display:flex; align-items:center; justify-content:center;
        }
        .cert::before { content:''; position:absolute; inset:24px; border:3px solid {{ $border }}; }
        .cert::after { content:''; position:absolute; inset:32px; border:1px solid {{ $accent }}; }
        .cert-inner { position:relative; z-index:1; text-align:center; padding:64px 80px; width:100%; }
        .cert-logo { max-height:80px; max-width:220px; margin:0 auto 20px; display:block; object-fit:contain; }
        .cert-club {
            font-family:-apple-system,Segoe UI,Roboto,sans-serif;
            text-transform:uppercase; letter-spacing:3px;
            color:{{ $border }}; font-weight:700; font-size:1rem; margin-bottom:22px;
        }
        .cert-title { font-size:3.2rem; letter-spacing:6px; color:{{ $textColor }}; text-transform:uppercase; font-weight:700; }
        .cert-rule { width:120px; height:3px; background:{{ $accent }}; margin:18px auto 28px; }
        .cert-sub { font-family:-apple-system,Segoe UI,Roboto,sans-serif; color:#57534e; font-size:1.05rem; margin-bottom:14px; }
        .cert-name {
            font-size:2.3rem; color:#111; font-weight:700;
            border-bottom:2px solid {{ $accent }}; display:inline-block;
            padding:2px 26px 10px; margin-bottom:22px; min-width:340px;
        }
        .cert-name.generic { border-bottom:2px dashed #a8a29e; color:#78716c; min-width:420px; }
        .cert-nominal { font-size:2rem; font-weight:700; color:{{ $border }}; margin:0 auto 20px; letter-spacing:1px; }
        .cert-reason { font-family:-apple-system,Segoe UI,Roboto,sans-serif; color:#44403c; font-size:1.12rem; max-width:640px; margin:0 auto 38px; }
        .cert-foot {
            display:flex; justify-content:space-between; align-items:flex-end;
            font-family:-apple-system,Segoe UI,Roboto,sans-serif;
            margin-top:22px; color:#57534e; font-size:.9rem;
        }
        .cert-foot .lbl { color:#a8a29e; font-size:.72rem; text-transform:uppercase; letter-spacing:1px; }
        .cert-num { font-family:monospace; font-size:1rem; color:{{ $border }}; font-weight:700; }
        /* ===== Режим v2: картинка-фон + поля поверх ===== */
        .certimg {
            position:relative; width:min(1000px, 96vw); max-width:100%;
            container-type:inline-size; box-shadow:0 12px 40px rgba(0,0,0,.35);
            background:#fff; line-height:1;
        }
        .certimg img { display:block; width:100%; height:auto; }
        .cf {
            position:absolute; transform:translateY(-50%);
            font-family:-apple-system,'Segoe UI',Roboto,sans-serif; font-weight:700;
            white-space:nowrap; pointer-events:none;
        }
        @media print {
            body { background:#fff; padding:0; }
            .toolbar { display:none; }
            .cert { box-shadow:none; width:100%; }
            .certimg { width:100%; box-shadow:none; }
            @page { size:{{ $pageSize }}; margin:0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">🖨 Печать / PDF</button>
        <a href="{{ route('club.certificates.index') }}">← К списку</a>
    </div>

    @if($template->hasBackgroundImage())
        @php
            $L = $template->fieldLayout();
            $tf = fn($a) => $a === 'center' ? 'translate(-50%,-50%)' : ($a === 'right' ? 'translate(-100%,-50%)' : 'translateY(-50%)');
            $fields = [
                'name' => $certificate->isNamed() ? $certificate->recipient_name : null,
                'value' => $certificate->title ?: $certificate->valueLabel(),
                'number' => $certificate->number,
            ];
        @endphp
        <div class="certimg">
            <img src="{{ $template->backgroundImageUrl() }}" alt="Сертификат">
            @foreach($fields as $key => $text)
                @continue($text === null)
                @php $f = $L[$key]; @endphp
                <div class="cf" style="left:{{ $f['x'] }}%;top:{{ $f['y'] }}%;font-size:{{ $f['size'] / 10 }}cqw;color:{{ $f['color'] }};text-align:{{ $f['align'] }};transform:{{ $tf($f['align']) }};">{{ $text }}</div>
            @endforeach
        </div>
    @else
    <div class="cert">
        <div class="cert-inner">
            @if($template->logoUrl())
                <img src="{{ $template->logoUrl() }}" alt="logo" class="cert-logo">
            @endif
            <div class="cert-club">{{ $club->name }}</div>
            <div class="cert-title">{{ $template->heading ?? 'Сертификат' }}</div>
            <div class="cert-rule"></div>

            @if($certificate->isNamed())
                <div class="cert-sub">{{ $template->subtitle_named }}</div>
                <div class="cert-name">{{ $certificate->recipient_name }}</div>
            @else
                <div class="cert-sub">{{ $template->subtitle_generic }}</div>
                <div class="cert-name generic">Предъявителю</div>
            @endif

            <div class="cert-nominal">{{ $certificate->valueLabel() }}</div>

            <div class="cert-reason">
                {{ $certificate->title ?: ($template->body_text ?: 'подтверждает право на получение услуг клуба.') }}
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
    @endif
</body>
</html>

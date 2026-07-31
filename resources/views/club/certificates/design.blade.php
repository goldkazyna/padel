@extends('layouts.app')
@section('title', 'Конструктор сертификата')

@section('content')
@include('club.cards._cards_shared_css')
<div class="cc-page">
    <div class="cc-head">
        <h1 class="cc-title">Конструктор сертификата <span class="cc-club">— {{ $club->name }}</span></h1>
        <span class="cc-spacer"></span>
        <a href="{{ route('club.certificates.index') }}" class="cc-btn cc-ghost">← К сертификатам</a>
    </div>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="flash-message flash-error">@foreach($errors->all() as $e){{ $e }}<br>@endforeach</div>@endif

    <form method="POST" action="{{ route('club.certificates.design.update') }}" enctype="multipart/form-data" class="crtc-wrap">
        @csrf
        <div class="crtc-form">
            <div class="crtc-group">
                <div class="crtc-gt">Номер сертификата</div>
                <label>Префикс</label>
                <input type="text" name="number_prefix" id="f_prefix" value="{{ old('number_prefix', $template->number_prefix) }}" maxlength="30" placeholder="напр. padelhills">
                <div class="crtc-numprev">Пример номера: <b id="numPreview">{{ ($template->number_prefix ?: 'CERT') }}-{{ $club->id }}-{{ now()->format('Y') }}-XXXXXX</b></div>
                <div class="crtc-numhint">Разрешены буквы, цифры, «-» и «_». Дальше — id клуба, год и уникальный код (не повторяется).</div>
            </div>
            <div class="crtc-group">
                <div class="crtc-gt">Тексты</div>
                <label>Заголовок</label>
                <input type="text" name="heading" id="f_heading" value="{{ old('heading', $template->heading) }}" maxlength="120">
                <label>Подзаголовок (именной)</label>
                <input type="text" name="subtitle_named" id="f_sn" value="{{ old('subtitle_named', $template->subtitle_named) }}" maxlength="200">
                <label>Подзаголовок (обычный)</label>
                <input type="text" name="subtitle_generic" id="f_sg" value="{{ old('subtitle_generic', $template->subtitle_generic) }}" maxlength="200">
                <label>Текст по умолчанию (если у сертификата не задан свой)</label>
                <textarea name="body_text" id="f_body" rows="2" maxlength="500">{{ old('body_text', $template->body_text) }}</textarea>
            </div>

            <div class="crtc-group">
                <div class="crtc-gt">Цвета</div>
                <div class="crtc-colors">
                    <label>Фон<input type="color" name="background_color" id="f_bg" value="{{ old('background_color', $template->background_color) }}"></label>
                    <label>Акцент<input type="color" name="accent_color" id="f_accent" value="{{ old('accent_color', $template->accent_color) }}"></label>
                    <label>Рамка<input type="color" name="border_color" id="f_border" value="{{ old('border_color', $template->border_color) }}"></label>
                    <label>Заголовок<input type="color" name="text_color" id="f_text" value="{{ old('text_color', $template->text_color) }}"></label>
                </div>
            </div>

            <div class="crtc-group">
                <div class="crtc-gt">Логотип и формат</div>
                <label>Логотип (PNG/JPG, до 2 МБ)</label>
                <input type="file" name="logo" id="f_logo" accept="image/*">
                @if($template->logoUrl())
                    <label class="crtc-check"><input type="checkbox" name="remove_logo" value="1"> Удалить текущий логотип</label>
                @endif
                <label>Ориентация</label>
                <select name="orientation" id="f_orient">
                    <option value="landscape" {{ $template->orientation === 'landscape' ? 'selected' : '' }}>Альбомная</option>
                    <option value="portrait" {{ $template->orientation === 'portrait' ? 'selected' : '' }}>Книжная</option>
                </select>
            </div>

            <button type="submit" class="cc-btn cc-green" style="width:100%">Сохранить шаблон</button>
        </div>

        {{-- Живой превью --}}
        <div class="crtc-preview-col">
            <div class="crtc-preview-hint">Превью</div>
            <div id="pv" class="pv">
                <div class="pv-inner">
                    <img id="pv_logo" class="pv-logo" src="{{ $template->logoUrl() ?? '' }}" style="{{ $template->logoUrl() ? '' : 'display:none' }}" alt="">
                    <div class="pv-club">{{ $club->name }}</div>
                    <div id="pv_heading" class="pv-title">{{ $template->heading }}</div>
                    <div class="pv-rule"></div>
                    <div id="pv_sub" class="pv-sub">{{ $template->subtitle_named }}</div>
                    <div class="pv-name">Иванов Иван Иванович</div>
                    <div id="pv_body" class="pv-reason">{{ $template->body_text ?: 'подтверждает право на получение услуг клуба.' }}</div>
                    <div class="pv-foot">
                        <div><div class="pv-lbl">Номер</div><div class="pv-num">CERT-…</div></div>
                        <div style="text-align:right"><div class="pv-lbl">Дата</div><div>{{ now()->format('d.m.Y') }}</div></div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
.crtc-wrap { display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap; }
.crtc-form { flex:1; min-width:320px; max-width:440px; }
.crtc-group { background:#18181b; border:1px solid #27272a; border-radius:12px; padding:16px; margin-bottom:16px; }
.crtc-gt { color:#a1a1aa; font-weight:600; margin-bottom:12px; font-size:.9rem; }
.crtc-form label { display:block; color:#d4d4d8; font-size:.85rem; margin:10px 0 4px; }
.crtc-form input[type=text], .crtc-form textarea, .crtc-form select {
    width:100%; background:#0f0f11; border:1px solid #27272a; border-radius:8px;
    padding:9px 12px; color:#e4e4e7; font-size:.9rem;
}
.crtc-colors { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.crtc-colors label { display:flex; align-items:center; justify-content:space-between; gap:10px; margin:0; }
.crtc-colors input[type=color] { width:48px; height:34px; border:none; background:none; cursor:pointer; }
.crtc-check { display:flex !important; align-items:center; gap:8px; }
.crtc-check input { width:auto !important; }
.crtc-numprev { margin-top:10px; color:#a1a1aa; font-size:.82rem; }
.crtc-numprev b { color:#22C55E; font-family:monospace; letter-spacing:.5px; }
.crtc-numhint { color:#71717a; font-size:.76rem; margin-top:6px; }

.crtc-preview-col { flex:1.3; min-width:360px; position:sticky; top:16px; }
.crtc-preview-hint { color:#71717a; font-size:.8rem; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; }
.pv { width:100%; aspect-ratio:1.414 / 1; background:#fbfaf6; position:relative; border-radius:6px; box-shadow:0 8px 30px rgba(0,0,0,.3); font-family:Georgia,serif; }
.pv.portrait { aspect-ratio:0.707 / 1; }
.pv::before { content:''; position:absolute; inset:3%; border:3px solid #1f6b3b; }
.pv::after { content:''; position:absolute; inset:4.2%; border:1px solid #c9a24b; }
.pv-inner { position:relative; z-index:1; text-align:center; padding:7% 9%; height:100%; display:flex; flex-direction:column; justify-content:center; }
.pv-logo { max-height:9%; max-width:40%; margin:0 auto 3%; object-fit:contain; }
.pv-club { font-family:sans-serif; text-transform:uppercase; letter-spacing:2px; color:#1f6b3b; font-weight:700; font-size:.85rem; margin-bottom:3%; }
.pv-title { font-size:2rem; letter-spacing:4px; color:#14532d; text-transform:uppercase; font-weight:700; }
.pv-rule { width:80px; height:3px; background:#c9a24b; margin:2% auto 3%; }
.pv-sub { font-family:sans-serif; color:#57534e; font-size:.8rem; margin-bottom:2%; }
.pv-name { font-size:1.5rem; color:#111; font-weight:700; border-bottom:2px solid #c9a24b; display:inline-block; padding:0 20px 6px; margin:0 auto 3%; }
.pv-reason { font-family:sans-serif; color:#44403c; font-size:.82rem; max-width:80%; margin:0 auto 4%; }
.pv-foot { display:flex; justify-content:space-between; align-items:flex-end; font-family:sans-serif; color:#57534e; font-size:.72rem; margin-top:auto; }
.pv-lbl { color:#a8a29e; font-size:.6rem; text-transform:uppercase; letter-spacing:1px; }
.pv-num { font-family:monospace; color:#1f6b3b; font-weight:700; }
@media (max-width:900px){ .crtc-preview-col { position:static; } }
</style>

<script>
(function(){
    var pv = document.getElementById('pv');
    var bind = function(id, fn){ var el = document.getElementById(id); if(el) el.addEventListener('input', fn); };

    bind('f_heading', function(e){ document.getElementById('pv_heading').textContent = e.target.value || 'Сертификат'; });
    bind('f_sn', function(e){ document.getElementById('pv_sub').textContent = e.target.value; });
    bind('f_body', function(e){ document.getElementById('pv_body').textContent = e.target.value || 'подтверждает право на получение услуг клуба.'; });

    bind('f_bg', function(e){ pv.style.background = e.target.value; });
    bind('f_accent', function(e){
        pv.style.setProperty('--accent', e.target.value);
        pv.querySelector('.pv-rule').style.background = e.target.value;
        pv.querySelector('.pv-name').style.borderBottomColor = e.target.value;
        pv.querySelector('.pv::after');
    });
    bind('f_border', function(e){
        pv.querySelectorAll('.pv-club, .pv-num').forEach(function(n){ n.style.color = e.target.value; });
    });
    bind('f_text', function(e){ pv.querySelector('.pv-title').style.color = e.target.value; });

    // Рамки через inline <style> перекрываем — проще пересобрать псевдоэлементы через data-атрибуты не выйдет,
    // поэтому для рамок/акцента дополнительно ставим CSS-переменные и правило ниже.
    var styleEl = document.createElement('style');
    document.head.appendChild(styleEl);
    var applyBorders = function(){
        var acc = document.getElementById('f_accent').value;
        var bor = document.getElementById('f_border').value;
        styleEl.textContent = '#pv::before{border-color:'+bor+'}#pv::after{border-color:'+acc+'}';
    };
    document.getElementById('f_accent').addEventListener('input', applyBorders);
    document.getElementById('f_border').addEventListener('input', applyBorders);
    applyBorders();

    var orient = document.getElementById('f_orient');
    if(orient) orient.addEventListener('change', function(e){ pv.classList.toggle('portrait', e.target.value === 'portrait'); });
    if(orient && orient.value === 'portrait') pv.classList.add('portrait');

    var pfx = document.getElementById('f_prefix');
    if (pfx) pfx.addEventListener('input', function (e) {
        var v = (e.target.value.trim() || 'CERT');
        document.getElementById('numPreview').textContent = v + '-{{ $club->id }}-{{ now()->format('Y') }}-XXXXXX';
    });

    var logo = document.getElementById('f_logo');
    if(logo) logo.addEventListener('change', function(e){
        var f = e.target.files[0]; if(!f) return;
        var img = document.getElementById('pv_logo');
        var r = new FileReader();
        r.onload = function(ev){ img.src = ev.target.result; img.style.display = 'block'; };
        r.readAsDataURL(f);
    });
})();
</script>
@endsection

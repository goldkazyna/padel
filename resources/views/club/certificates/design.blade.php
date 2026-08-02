@extends('layouts.app')
@section('title', 'Конструктор сертификата')

@section('content')
@include('club.cards._cards_shared_css')
@php
    $layout = $template->fieldLayout();
    $bgUrl = $template->backgroundImageUrl();
    $numExample = ($template->number_prefix ?: 'CERT') . '-' . now()->format('dm') . '-' . now()->format('Y') . '-8JMCPF';
@endphp
<div class="cc-head">
    <div>
        <h1 class="cc-title">Конструктор сертификата</h1>
        <p class="cc-sub">Загрузите картинку-фон и расставьте поля: ФИО, номинал, номер.</p>
    </div>
    <a href="{{ route('club.certificates.index') }}" class="cc-btn cc-ghost">← К сертификатам</a>
</div>

@if(session('success'))<div class="cc-alert cc-ok">{{ session('success') }}</div>@endif

<div class="dz-tabs">
    <button type="button" class="dz-tab active" data-mode="image" onclick="dzMode('image')">По картинке</button>
    <button type="button" class="dz-tab" data-mode="classic" onclick="dzMode('classic')">Классический</button>
</div>

{{-- ==================== РЕЖИМ ПО КАРТИНКЕ ==================== --}}
<form method="POST" action="{{ route('club.certificates.design.update') }}" enctype="multipart/form-data" id="imgForm" class="dz-wrap">
    @csrf
    <input type="hidden" name="layout" id="layoutInput">

    <div class="dz-editor">
        <div class="dz-canvas-col">
            <div class="dz-canvas" id="dzCanvas">
                <img id="bgImg" src="{{ $bgUrl ?? '' }}" alt="" style="{{ $bgUrl ? '' : 'display:none' }}">
                <div id="dzEmpty" class="dz-empty" style="{{ $bgUrl ? 'display:none' : '' }}">
                    <div class="dz-empty-ic">🖼️</div>
                    <div>Загрузите картинку сертификата</div>
                    <div class="dz-empty-sub">PNG/JPG, до 6 МБ. Дизайн со статичным текстом (заголовок, «№ сертификата»).</div>
                </div>

                @foreach(['name' => 'Иванов Иван', 'value' => '1 час игры в падел', 'number' => $numExample] as $k => $sample)
                    @php $f = $layout[$k]; @endphp
                    <div class="efield {{ $f['align'] !== 'left' ? $f['align'] : '' }}" data-field="{{ $k }}"
                         style="left:{{ $f['x'] }}%;top:{{ $f['y'] }}%;font-size:{{ $f['size']/10 }}cqw;color:{{ $f['color'] }};text-align:{{ $f['align'] }};{{ $bgUrl ? '' : 'display:none' }}">
                        <span class="efield-tag">{{ ['name'=>'ФИО','value'=>'Номинал','number'=>'Номер'][$k] }}</span>
                        <span class="efield-text">{{ $sample }}</span>
                    </div>
                @endforeach
            </div>
            <label class="dz-upload">
                <input type="file" name="background_image" id="bgInput" accept="image/*" hidden>
                <span>{{ $bgUrl ? 'Заменить картинку' : 'Загрузить картинку' }}</span>
            </label>
            @if($bgUrl)
                <label class="dz-check"><input type="checkbox" name="remove_background" value="1"> Убрать картинку (вернуться к классическому)</label>
            @endif
        </div>

        <div class="dz-props">
            <div class="dz-block">
                <div class="dz-plabel">Префикс номера</div>
                <input type="text" name="number_prefix" value="{{ old('number_prefix', $template->number_prefix) }}" maxlength="30" placeholder="напр. padelhills" class="dz-in">
            </div>

            <div class="dz-block">
                <div class="dz-plabel">Поля поверх картинки</div>
                <div class="dz-fieldlist">
                    <button type="button" class="dz-fitem active" data-field="name" onclick="dzSelect('name')"><span class="dz-dot"></span>ФИО<span class="dz-mut">именной</span></button>
                    <button type="button" class="dz-fitem" data-field="value" onclick="dzSelect('value')"><span class="dz-dot"></span>Номинал</button>
                    <button type="button" class="dz-fitem" data-field="number" onclick="dzSelect('number')"><span class="dz-dot"></span>Номер</button>
                </div>
            </div>

            <div class="dz-block">
                <div class="dz-plabel">Свойства · <span id="dzSelName">ФИО</span></div>
                <div class="dz-prow"><span>Позиция X</span><b><span id="dzX">0</span>%</b></div>
                <div class="dz-prow"><span>Позиция Y</span><b><span id="dzY">0</span>%</b></div>
                <div class="dz-hint">Перетаскивайте поле мышкой прямо на картинке.</div>

                <div class="dz-prow" style="margin-top:10px"><span>Размер</span><b id="dzSizeVal">30</b></div>
                <input type="range" min="10" max="90" value="30" id="dzSize" class="dz-range" oninput="dzSetSize(this.value)">

                <div class="dz-prow" style="margin-top:8px"><span>Цвет</span></div>
                <div class="dz-swatches" id="dzSwatches">
                    @foreach(['#1e2a44','#334155','#111827','#c99b3f','#b23b3b','#ffffff'] as $sw)
                        <button type="button" class="dz-sw" style="background:{{ $sw }}" data-color="{{ $sw }}" onclick="dzSetColor('{{ $sw }}')"></button>
                    @endforeach
                    <input type="color" id="dzColorPick" value="#1e2a44" onchange="dzSetColor(this.value)" title="Свой цвет">
                </div>

                <div class="dz-prow" style="margin-top:10px"><span>Выравнивание</span></div>
                <div class="dz-align">
                    <button type="button" data-align="left" onclick="dzSetAlign('left')" class="active">Лево</button>
                    <button type="button" data-align="center" onclick="dzSetAlign('center')">Центр</button>
                    <button type="button" data-align="right" onclick="dzSetAlign('right')">Право</button>
                </div>
            </div>

            <button type="submit" class="cc-btn cc-green" style="width:100%">Сохранить</button>
        </div>
    </div>
</form>

{{-- ==================== КЛАССИЧЕСКИЙ РЕЖИМ ==================== --}}
<form method="POST" action="{{ route('club.certificates.design.update') }}" enctype="multipart/form-data" id="classicForm" style="display:none">
    @csrf
    <div class="dz-props" style="max-width:520px">
        <div class="dz-block"><div class="dz-plabel">Заголовок</div>
            <input class="dz-in" type="text" name="heading" value="{{ old('heading', $template->heading ?: 'Сертификат') }}" maxlength="120"></div>
        <div class="dz-block"><div class="dz-plabel">Подзаголовок (именной)</div>
            <input class="dz-in" type="text" name="subtitle_named" value="{{ old('subtitle_named', $template->subtitle_named ?: 'Настоящий сертификат выдан') }}" maxlength="200"></div>
        <div class="dz-block"><div class="dz-plabel">Подзаголовок (на предъявителя)</div>
            <input class="dz-in" type="text" name="subtitle_generic" value="{{ old('subtitle_generic', $template->subtitle_generic ?: 'Сертификат на предъявителя') }}" maxlength="200"></div>
        <div class="dz-block"><div class="dz-plabel">Текст-основание</div>
            <input class="dz-in" type="text" name="body_text" value="{{ old('body_text', $template->body_text) }}" maxlength="500" placeholder="подтверждает право на получение услуг клуба."></div>
        <div class="dz-block"><div class="dz-plabel">Цвета (фон / акцент / рамка / текст)</div>
            <div style="display:flex;gap:10px">
                <input type="color" name="background_color" value="{{ $template->background_color ?: '#fbfaf6' }}">
                <input type="color" name="accent_color" value="{{ $template->accent_color ?: '#c9a24b' }}">
                <input type="color" name="border_color" value="{{ $template->border_color ?: '#1f6b3b' }}">
                <input type="color" name="text_color" value="{{ $template->text_color ?: '#14532d' }}">
            </div></div>
        <div class="dz-block"><div class="dz-plabel">Логотип</div>
            <input type="file" name="logo" accept="image/*">
            @if($template->logoUrl())<label class="dz-check"><input type="checkbox" name="remove_logo" value="1"> Удалить логотип</label>@endif</div>
        <div class="dz-block"><div class="dz-plabel">Ориентация</div>
            <select class="dz-in" name="orientation">
                <option value="landscape" {{ $template->orientation === 'landscape' ? 'selected' : '' }}>Альбомная</option>
                <option value="portrait" {{ $template->orientation === 'portrait' ? 'selected' : '' }}>Книжная</option>
            </select></div>
        <button type="submit" class="cc-btn cc-green" style="width:100%">Сохранить классический</button>
    </div>
</form>

<style>
.cc-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:14px; }
.dz-tabs { display:flex; gap:8px; margin-bottom:18px; }
.dz-tab { background:#17211d; border:1px solid #293630; color:#8b98a2; font-weight:700; font-size:.9rem; padding:9px 16px; border-radius:10px; cursor:pointer; }
.dz-tab.active { background:rgba(34,197,94,.14); border-color:rgba(34,197,94,.4); color:#22C55E; }
.dz-editor { display:grid; grid-template-columns:1fr 300px; gap:20px; align-items:start; }
.dz-canvas-col { display:flex; flex-direction:column; gap:12px; }
.dz-canvas { position:relative; container-type:inline-size; background:#0f1418; border:1px solid #293630; border-radius:12px; overflow:hidden; min-height:280px; display:flex; align-items:center; justify-content:center; }
.dz-canvas img { display:block; width:100%; height:auto; }
.dz-empty { text-align:center; color:#8b98a2; padding:60px 24px; }
.dz-empty-ic { font-size:38px; margin-bottom:10px; }
.dz-empty-sub { font-size:.8rem; color:#5f6c76; margin-top:6px; max-width:320px; }
.efield { position:absolute; transform:translateY(-50%); font-family:-apple-system,'Segoe UI',Roboto,sans-serif; font-weight:700; white-space:nowrap; cursor:move; user-select:none; padding:1px 3px; }
.efield.sel { outline:1.5px dashed #22C55E; outline-offset:2px; background:rgba(34,197,94,.06); }
.efield-tag { position:absolute; top:-15px; left:0; font-size:9px; font-weight:800; color:#22C55E; background:#0a0e11; padding:1px 5px; border-radius:4px; display:none; }
.efield.sel .efield-tag { display:block; }
.efield.center { transform:translate(-50%,-50%); }
.efield.right { transform:translate(-100%,-50%); }
.dz-upload { display:inline-flex; align-items:center; justify-content:center; gap:7px; background:rgba(34,197,94,.14); border:1px solid rgba(34,197,94,.35); color:#22C55E; font-weight:700; font-size:.85rem; padding:10px 14px; border-radius:10px; cursor:pointer; }
.dz-check { display:flex; align-items:center; gap:7px; color:#8b98a2; font-size:.8rem; }
.dz-props { background:#17211d; border:1px solid #293630; border-radius:12px; padding:16px; display:flex; flex-direction:column; gap:16px; }
.dz-block { display:flex; flex-direction:column; gap:9px; }
.dz-plabel { font-size:.72rem; letter-spacing:.08em; text-transform:uppercase; color:#5f6c76; font-weight:800; }
.dz-in { background:#1c252b; border:1px solid #293630; border-radius:8px; padding:8px 10px; color:#e8edf0; font-size:.85rem; }
.dz-fieldlist { display:flex; flex-direction:column; gap:7px; }
.dz-fitem { display:flex; align-items:center; gap:9px; background:#1c252b; border:1px solid #293630; border-radius:10px; padding:9px 11px; color:#e8edf0; font-size:.85rem; font-weight:600; cursor:pointer; text-align:left; }
.dz-fitem.active { border-color:#22C55E; background:rgba(34,197,94,.12); color:#22C55E; }
.dz-dot { width:8px; height:8px; border-radius:50%; background:#22C55E; }
.dz-mut { margin-left:auto; color:#5f6c76; font-size:.72rem; font-weight:600; }
.dz-prow { display:flex; justify-content:space-between; align-items:center; font-size:.82rem; color:#8b98a2; }
.dz-prow b { color:#e8edf0; }
.dz-hint { font-size:.72rem; color:#5f6c76; }
.dz-range { -webkit-appearance:none; height:4px; border-radius:3px; background:#293630; width:100%; }
.dz-range::-webkit-slider-thumb { -webkit-appearance:none; width:15px; height:15px; border-radius:50%; background:#22C55E; cursor:pointer; }
.dz-swatches { display:flex; gap:7px; flex-wrap:wrap; align-items:center; }
.dz-sw { width:26px; height:26px; border-radius:7px; border:2px solid transparent; cursor:pointer; }
.dz-sw.on { border-color:#fff; }
#dzColorPick { width:30px; height:28px; border:none; background:none; padding:0; cursor:pointer; }
.dz-align { display:flex; gap:6px; }
.dz-align button { flex:1; background:#1c252b; border:1px solid #293630; border-radius:8px; color:#8b98a2; font-size:.8rem; font-weight:700; padding:6px 0; cursor:pointer; }
.dz-align button.active { border-color:#22C55E; color:#22C55E; background:rgba(34,197,94,.12); }
@media (max-width:900px){ .dz-editor { grid-template-columns:1fr; } }
</style>

<script>
(function(){
    var layout = @json($layout);
    var sel = 'name';
    var canvas = document.getElementById('dzCanvas');
    function fieldEl(k){ return canvas.querySelector('.efield[data-field="'+k+'"]'); }
    function allFields(){ return canvas.querySelectorAll('.efield'); }

    function applyField(k){
        var f = layout[k], el = fieldEl(k);
        if(!el) return;
        el.style.left = f.x + '%';
        el.style.top = f.y + '%';
        el.style.fontSize = (f.size/10) + 'cqw';
        el.style.color = f.color;
        el.style.textAlign = f.align;
        el.classList.remove('center','right');
        if(f.align !== 'left') el.classList.add(f.align);
    }

    window.dzSelect = function(k){
        sel = k;
        document.querySelectorAll('.dz-fitem').forEach(function(b){ b.classList.toggle('active', b.dataset.field===k); });
        allFields().forEach(function(el){ el.classList.toggle('sel', el.dataset.field===k); });
        var names = {name:'ФИО', value:'Номинал', number:'Номер'};
        document.getElementById('dzSelName').textContent = names[k];
        var f = layout[k];
        document.getElementById('dzX').textContent = Math.round(f.x);
        document.getElementById('dzY').textContent = Math.round(f.y);
        document.getElementById('dzSize').value = f.size;
        document.getElementById('dzSizeVal').textContent = f.size;
        document.getElementById('dzColorPick').value = /^#[0-9a-f]{6}$/i.test(f.color) ? f.color : '#1e2a44';
        document.querySelectorAll('.dz-sw').forEach(function(s){ s.classList.toggle('on', s.dataset.color.toLowerCase()===String(f.color).toLowerCase()); });
        document.querySelectorAll('.dz-align button').forEach(function(b){ b.classList.toggle('active', b.dataset.align===f.align); });
    };
    window.dzSetSize = function(v){ layout[sel].size = parseInt(v); document.getElementById('dzSizeVal').textContent = v; applyField(sel); };
    window.dzSetColor = function(c){ layout[sel].color = c; applyField(sel); dzSelect(sel); };
    window.dzSetAlign = function(a){ layout[sel].align = a; applyField(sel); dzSelect(sel); };

    var drag = null;
    allFields().forEach(function(el){
        el.addEventListener('mousedown', function(e){ e.preventDefault(); dzSelect(el.dataset.field); drag = el.dataset.field; });
    });
    document.addEventListener('mousemove', function(e){
        if(!drag) return;
        var r = canvas.getBoundingClientRect();
        var x = Math.max(0, Math.min(100, (e.clientX - r.left) / r.width * 100));
        var y = Math.max(0, Math.min(100, (e.clientY - r.top) / r.height * 100));
        layout[drag].x = Math.round(x*10)/10;
        layout[drag].y = Math.round(y*10)/10;
        applyField(drag);
        document.getElementById('dzX').textContent = Math.round(x);
        document.getElementById('dzY').textContent = Math.round(y);
    });
    document.addEventListener('mouseup', function(){ drag = null; });

    document.getElementById('bgInput').addEventListener('change', function(e){
        var file = e.target.files && e.target.files[0];
        if(!file) return;
        var url = URL.createObjectURL(file);
        var img = document.getElementById('bgImg');
        img.src = url; img.style.display = '';
        document.getElementById('dzEmpty').style.display = 'none';
        allFields().forEach(function(el){ el.style.display = ''; });
    });

    document.getElementById('imgForm').addEventListener('submit', function(){
        document.getElementById('layoutInput').value = JSON.stringify(layout);
    });

    window.dzMode = function(m){
        document.querySelectorAll('.dz-tab').forEach(function(t){ t.classList.toggle('active', t.dataset.mode===m); });
        document.getElementById('imgForm').style.display = m==='image' ? '' : 'none';
        document.getElementById('classicForm').style.display = m==='classic' ? '' : 'none';
    };

    ['name','value','number'].forEach(applyField);
    dzSelect('name');
})();
</script>
@endsection

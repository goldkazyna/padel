@extends('layouts.app')
@section('title', 'Клубные карты')

@section('content')
<div class="cc-page">
    <div class="cc-head">
        <h1 class="cc-title">Клубные карты <span class="cc-club">— {{ $club->name }}</span></h1>
        <span class="cc-stat"><b>{{ $issuedCount }}</b><span>Выпущено</span></span>
        <span class="cc-stat"><b class="g">{{ $actualCount }}</b><span>Актуально</span></span>
        <span class="cc-stat"><b>{{ $types->count() }}</b><span>Типа карт</span></span>
        <span class="cc-spacer"></span>
        <a href="{{ route('club.cards.journal') }}" class="cc-btn cc-ghost">Журнал</a>
        <a href="{{ route('club.cards.pending') }}" class="cc-btn cc-ghost" style="position:relative">К списанию
            @if($pendingChargeCount > 0)<span class="cc-badge">{{ $pendingChargeCount }}</span>@endif
        </a>
        <a href="{{ route('club.cards.unlinked') }}" class="cc-btn cc-ghost" style="position:relative">Не выставлены карты
            @if($unlinkedCount > 0)<span class="cc-badge">{{ $unlinkedCount }}</span>@endif
        </a>
        <button class="cc-btn cc-green" onclick="openCardTypeModal()">+ Создать тип карты</button>
    </div>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash-message flash-error">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="flash-message flash-error">@foreach($errors->all() as $e){{ $e }}<br>@endforeach</div>@endif

    {{-- Типы карт --}}
    @if($types->isEmpty())
        <div class="cc-empty">Типов карт пока нет. Создайте первый.</div>
    @else
    <div class="cc-types">
        @foreach($types as $t)
        <div class="cc-type {{ $t->ui_cls }}">
            <div class="cc-ti">
                <div class="cc-tname">{{ $t->name }}</div>
                <div class="cc-tprice">
                    @if($t->price){{ number_format($t->price, 0, '', ' ') }} ₸@else<span class="cc-tsub">Без цены</span>@endif
                    @if($t->default_expires_at)<span class="cc-tsub">· до {{ $t->default_expires_at->format('d.m.Y') }}</span>
                    @elseif($t->default_validity_days)<span class="cc-tsub">· срок {{ $t->default_validity_days }} дн.</span>
                    @else<span class="cc-tsub">· бессрочно</span>@endif
                </div>
            </div>
            @if($t->isCounter())
                <span class="cc-tbadge {{ $t->ui_cls }}">{{ $t->nominal }}ч</span>
            @else
                <span class="cc-tbadge cc-disc">−{{ $t->discount_percent }}%</span>
            @endif
            <span class="cc-tcount">{{ $t->ui_count }}</span>
            <div class="cc-tact">
                @if($t->ui_count > 0)
                    <span class="cc-ic cc-ic-locked"
                          title="Нельзя редактировать: по типу уже выпущено карт — {{ $t->ui_count }}. Создайте новый тип."><i class="bi bi-lock"></i></span>
                    <button class="cc-ic" type="button" title="Просмотреть тип карты"
                            onclick='openCardTypeModal(@json($t), true)'><i class="bi bi-eye"></i></button>
                @else
                    <button class="cc-ic" title="Редактировать" onclick='openCardTypeModal(@json($t))'><i class="bi bi-pencil"></i></button>
                @endif
                <form action="{{ route('club.cardTypes.destroy', $t) }}" method="POST" class="d-inline"
                      onsubmit="return confirm('Удалить тип карты «{{ $t->name }}»?')">
                    @csrf @method('DELETE')
                    <button class="cc-ic cc-ic-del" title="Удалить"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Тулбар --}}
    <div class="cc-toolbar">
        <div class="cc-search"><span class="cc-sico"><i class="bi bi-search"></i></span>
            <input id="ccSearch" placeholder="Поиск по имени или коду...">
        </div>
        <div class="cc-filters" id="ccFilters">
            <span class="cc-fpill active" data-f="all">Все <span class="c">{{ $counts['all'] }}</span></span>
            <span class="cc-fpill" data-f="active">Активные <span class="c">{{ $counts['active'] }}</span></span>
            <span class="cc-fpill" data-f="soon">Истекают <span class="c">{{ $counts['soon'] }}</span></span>
            <span class="cc-fpill" data-f="perp">Бессрочные <span class="c">{{ $counts['perp'] }}</span></span>
            <span class="cc-fpill" data-f="used">Использованные <span class="c">{{ $counts['used'] }}</span></span>
            <span class="cc-fpill" data-f="inactive">Не активна <span class="c">{{ $counts['inactive'] }}</span></span>
        </div>
        <div class="cc-sorts" id="ccSorts">
            <span class="cc-sort active" data-s="date">По сроку</span>
            <span class="cc-sort" data-s="name">По имени</span>
            <span class="cc-sort" data-s="rem">По остатку</span>
        </div>
    </div>

    <div class="cc-seclabel">Выпущенные карты · <span id="ccTotal">{{ $counts['all'] }}</span></div>
    <div id="ccGroups"></div>
</div>

{{-- скрытая форма удаления выпущенной карты --}}
<form id="ccDeleteForm" method="POST" style="display:none">@csrf @method('DELETE')</form>

@include('club.cards._type_modal')

<style>
.cc-page{max-width:1600px}
.cc-head{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.cc-title{font-size:21px;font-weight:800;margin:0;letter-spacing:-.3px;color:var(--text-primary)}
.cc-title .cc-club{color:var(--text-muted);font-weight:500}
.cc-stat{display:inline-flex;align-items:center;gap:6px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:6px 11px;font-size:13px;color:var(--text-secondary)}
.cc-stat b{font-size:15px;font-weight:800;color:var(--text-primary)} .cc-stat b.g{color:var(--accent)}
.cc-spacer{flex:1}
.cc-btn{border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;padding:9px 15px;text-decoration:none;display:inline-flex;align-items:center}
.cc-ghost{background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary)}
.cc-green{background:var(--accent);color:#06210f}
.cc-badge{display:inline-block;min-width:18px;padding:0 5px;margin-left:6px;border-radius:9px;background:#ef4444;color:#fff;font-size:12px;font-weight:700;text-align:center;line-height:18px}

.cc-types{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;margin-bottom:16px}
.cc-type{position:relative;background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px;overflow:hidden}
.cc-type::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px}
.cc-type.t-blue::before{background:#3b82f6}.cc-type.t-amber::before{background:#f59e0b}.cc-type.t-purple::before{background:#8b5cf6}
.cc-type.t-green::before{background:#22c55e}.cc-type.t-pink::before{background:#ec4899}.cc-type.t-cyan::before{background:#06b6d4}
.cc-ti{flex:1;min-width:0}
.cc-tname{font-weight:700;font-size:14px;color:var(--text-primary)}
.cc-tprice{font-weight:800;font-size:14px;margin-top:3px;color:var(--text-primary)}
.cc-type.t-blue .cc-tprice{color:#60a5fa}.cc-type.t-amber .cc-tprice{color:#fbbf24}.cc-type.t-purple .cc-tprice{color:#a78bfa}
.cc-tsub{color:var(--text-muted);font-weight:500;font-size:12px}
.cc-tbadge{font-size:11px;font-weight:800;padding:3px 8px;border-radius:6px;background:rgba(139,92,246,.18);color:#a78bfa}
.cc-tbadge.t-blue{background:rgba(59,130,246,.18);color:#60a5fa}.cc-tbadge.t-amber{background:rgba(245,158,11,.18);color:#fbbf24}
.cc-tbadge.t-green{background:rgba(34,197,94,.18);color:#4ade80}.cc-tbadge.t-pink{background:rgba(236,72,153,.18);color:#f472b6}.cc-tbadge.t-cyan{background:rgba(6,182,212,.18);color:#22d3ee}
.cc-tbadge.cc-disc{background:rgba(245,132,70,.18);color:#f08446}
.cc-tcount{color:var(--text-secondary);font-size:13px;font-weight:700;min-width:24px;text-align:center}
.cc-tact{display:flex;gap:4px}
.cc-tact form{margin:0}
.cc-ic{width:28px;height:28px;border-radius:7px;background:var(--bg-card-hover);border:1px solid var(--border);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px}
.cc-ic:hover{color:var(--text-primary)} .cc-ic-del:hover{color:#ef4444}
.cc-ic-locked{opacity:.45;cursor:not-allowed}
.cc-ic-locked:hover{color:var(--text-secondary)}

.cc-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.cc-search{position:relative;flex:0 0 280px}
.cc-search input{width:100%;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;color:var(--text-primary);padding:9px 12px 9px 34px;font-size:13px;outline:none}
.cc-search input:focus{border-color:var(--border-light)}
.cc-sico{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted)}
.cc-filters{display:flex;gap:7px;flex-wrap:wrap}
.cc-fpill{background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary);border-radius:9px;padding:7px 12px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
.cc-fpill .c{color:var(--text-muted);font-weight:700}
.cc-fpill:hover{background:var(--bg-card-hover)}
.cc-fpill.active{background:#fff;color:#0a0a0a;border-color:#fff}
.cc-fpill.active .c{color:#0a0a0a}
.cc-sorts{margin-left:auto;display:flex;gap:6px}
.cc-sort{background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary);border-radius:9px;padding:7px 11px;font-size:13px;font-weight:600;cursor:pointer}
.cc-sort.active{background:var(--bg-card-hover);color:var(--text-primary);border-color:var(--border-light)}
.cc-seclabel{color:var(--text-muted);font-size:12px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;margin:6px 2px 10px}

.cc-group{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;margin-bottom:14px;overflow:hidden}
.cc-group.collapsed .cc-list,.cc-group.collapsed .cc-more{display:none}
.cc-ghead{position:relative;display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer}
.cc-ghead::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px}
.cc-group.t-blue .cc-ghead::before{background:#3b82f6}.cc-group.t-amber .cc-ghead::before{background:#f59e0b}.cc-group.t-purple .cc-ghead::before{background:#8b5cf6}
.cc-group.t-green .cc-ghead::before{background:#22c55e}.cc-group.t-pink .cc-ghead::before{background:#ec4899}.cc-group.t-cyan .cc-ghead::before{background:#06b6d4}
.cc-gtag{font-size:11px;font-weight:800;padding:4px 9px;border-radius:7px}
.t-blue .cc-gtag{background:rgba(59,130,246,.18);color:#60a5fa}.t-amber .cc-gtag{background:rgba(245,158,11,.18);color:#fbbf24}.t-purple .cc-gtag{background:rgba(139,92,246,.18);color:#a78bfa}
.t-green .cc-gtag{background:rgba(34,197,94,.18);color:#4ade80}.t-pink .cc-gtag{background:rgba(236,72,153,.18);color:#f472b6}.t-cyan .cc-gtag{background:rgba(6,182,212,.18);color:#22d3ee}
.cc-gname{font-weight:700;font-size:14.5px;color:var(--text-primary)}
.cc-gname .gh{color:var(--text-secondary);font-weight:600;margin-left:6px;font-size:13px}
.cc-gsub{color:var(--text-muted);font-size:12px;margin-top:2px}
.cc-gchev{margin-left:auto;color:var(--text-secondary);transition:transform .2s;font-size:16px}
.cc-group.collapsed .cc-gchev{transform:rotate(-90deg)}

.cc-row{display:flex;align-items:center;gap:14px;padding:11px 16px;border-top:1px solid var(--border);cursor:pointer}
.cc-row:hover{background:rgba(255,255,255,.02)}
.cc-ring{position:relative;width:38px;height:38px;flex-shrink:0}
.cc-ring svg{transform:rotate(-90deg)}
.cc-ring .rt{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:11px;font-weight:800;line-height:1}
.cc-ring .rt .small{font-size:8px;color:var(--text-muted);font-weight:600}
.cc-discbox{width:38px;height:38px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:rgba(245,132,70,.16);color:#f08446;font-weight:800;font-size:12px}
.cc-rname{flex:1;min-width:0}
.cc-rname .n{font-weight:700;font-size:14px;color:var(--text-primary)}
.cc-rname .code{color:var(--text-muted);font-size:11px;font-family:ui-monospace,monospace;margin-top:2px;letter-spacing:.3px}
.cc-rrem{width:160px;text-align:right;font-size:13px;color:var(--text-secondary)}
.cc-rrem b{color:var(--text-primary);font-weight:800}
.cc-rrem .malo{color:var(--amber,#f59e0b);font-weight:700;font-size:12px;margin-left:6px}
.cc-rrem .ended{color:#ef4444;font-weight:700;font-size:12px;margin-left:6px}
.cc-rdate{width:148px;text-align:right;color:var(--text-secondary);font-size:12px;line-height:1.45}
.cc-dline{white-space:nowrap}
.cc-dexp{color:var(--text-muted);font-size:11px;margin-top:1px}
.cc-dlbl{color:var(--text-muted);font-weight:600;font-size:10px;text-transform:uppercase;letter-spacing:.3px}
.cc-rstatus{width:112px;display:flex;justify-content:flex-end}
.cc-spill{font-size:12px;font-weight:700;padding:4px 11px;border-radius:100px;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.cc-spill::before{content:'';width:6px;height:6px;border-radius:50%}
.cc-spill.s-active{background:rgba(34,197,94,.14);color:#22c55e}.cc-spill.s-active::before{background:#22c55e}
.cc-spill.s-soon{background:rgba(245,158,11,.14);color:#f59e0b}.cc-spill.s-soon::before{background:#f59e0b}
.cc-spill.s-inactive{background:rgba(239,68,68,.14);color:#ef4444}.cc-spill.s-inactive::before{background:#ef4444}
.cc-spill.s-perp{background:rgba(139,92,246,.16);color:#a78bfa}.cc-spill.s-perp::before{background:#a78bfa}
.cc-spill.s-used{background:rgba(113,113,122,.18);color:#a1a1aa}.cc-spill.s-used::before{background:#a1a1aa}
.cc-ract{width:58px;display:flex;gap:4px;justify-content:flex-end;opacity:0;transition:opacity .15s}
.cc-row:hover .cc-ract{opacity:1}
.cc-more{text-align:center;padding:11px;border-top:1px dashed var(--border-light);color:var(--text-secondary);font-size:13px;cursor:pointer}
.cc-more:hover{color:var(--text-primary)}
.cc-empty{color:var(--text-secondary);padding:24px;text-align:center;background:var(--bg-card);border:1px solid var(--border);border-radius:12px}
.cc-hidden{display:none!important}
@media(max-width:820px){
    .cc-rrem,.cc-rdate{width:auto}
    .cc-row{flex-wrap:wrap}
}
</style>

<script>
(function(){
  const CC_TYPES = @json($typesData);
  const CC_CARDS = @json($cardsData);
  let fFilter='all', fSort='date', fQuery='';
  const PER=8;

  function ringColor(st,bal){ if(st==='inactive'||bal<=0) return '#6b7280'; if(st==='soon') return '#f59e0b'; return '#22c55e'; }
  function ring(c){
    if(!c.counter){ return `<div class="cc-discbox">−${c.discount}%</div>`; }
    const pct=c.init>0?Math.max(0,Math.min(1,c.bal/c.init)):0, C=2*Math.PI*16, off=C*(1-pct), col=ringColor(c.st,c.bal);
    return `<div class="cc-ring"><svg width="38" height="38"><circle cx="19" cy="19" r="16" fill="none" stroke="#2d2d2d" stroke-width="3"/>
      <circle cx="19" cy="19" r="16" fill="none" stroke="${col}" stroke-width="3" stroke-linecap="round" stroke-dasharray="${C}" stroke-dashoffset="${off}"/></svg>
      <div class="rt" style="color:${col}">${c.bal}<span class="small">из ${c.init}</span></div></div>`;
  }
  function pill(st){ return ({active:['s-active','Активна'],soon:['s-soon','Истекает'],inactive:['s-inactive','Не активна'],perp:['s-perp','Бессрочно'],used:['s-used','Использована']})[st]; }
  function esc(s){ return String(s).replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }
  function matches(c){
    if(fQuery){ const q=fQuery.toLowerCase(); if(!(c.name.toLowerCase().includes(q)||c.code.toLowerCase().includes(q))) return false; }
    return fFilter==='all' || c.st===fFilter;
  }
  function sortCards(a){
    const r=[...a];
    if(fSort==='name') r.sort((x,y)=>x.name.localeCompare(y.name,'ru'));
    else if(fSort==='rem') r.sort((x,y)=>x.bal-y.bal);
    else r.sort((x,y)=>x.exp-y.exp);
    return r;
  }
  function rowHtml(c,i){
    const [cls,label]=pill(c.st);
    const tag = c.st==='used' ? '<span class="ended">кончилась</span>' : (c.low ? '<span class="malo">мало</span>' : '');
    const rem = c.counter ? `<b>${c.bal}</b> из ${c.init} ч${tag}` : `Скидка ${c.discount}%`;
    const del = c.del ? `<span class="cc-ic cc-ic-del" title="Удалить" onclick="event.stopPropagation();ccDelete('${c.del}','${esc(c.name)}')"><i class="bi bi-trash"></i></span>` : '';
    const onclick = c.url ? `onclick="location.href='${c.url}'"` : '';
    return `<div class="cc-row ${i>=PER?'cc-hidden':''}" ${onclick}>
      ${ring(c)}
      <div class="cc-rname"><div class="n">${esc(c.name)}</div><div class="code">${esc(c.code)}</div></div>
      <div class="cc-rrem">${rem}</div>
      <div class="cc-rdate">
        <div class="cc-dline"><span class="cc-dlbl">Выдана:</span> ${esc(c.issued)}</div>
        <div class="cc-dline cc-dexp">${c.date==='бессрочно' ? '<span class="cc-dlbl">Срок:</span> бессрочно' : '<span class="cc-dlbl">До:</span> '+esc(c.date)}</div>
      </div>
      <div class="cc-rstatus"><span class="cc-spill ${cls}">${label}</span></div>
      <div class="cc-ract">${del}</div>
    </div>`;
  }
  function render(){
    let total=0; const wrap=document.getElementById('ccGroups'); wrap.innerHTML='';
    CC_TYPES.forEach(tp=>{
      const cards=sortCards(CC_CARDS.filter(c=>c.type_id===tp.id && matches(c)));
      if(!cards.length) return;
      total+=cards.length;
      const g=document.createElement('div'); g.className='cc-group '+tp.cls;
      const hours = tp.hours!=null ? `<span class="gh">${tp.hours} ч</span>` : '';
      g.innerHTML=`<div class="cc-ghead" onclick="this.parentNode.classList.toggle('collapsed')">
          <span class="cc-gtag">${esc(tp.tag)}</span>
          <div><div class="cc-gname">${esc(tp.name)}${hours}</div>
            <div class="cc-gsub">${tp.total} карт · ${tp.active} активных · ${tp.oborot} ч в обороте</div></div>
          <span class="cc-gchev"><i class="bi bi-chevron-down"></i></span></div>
        <div class="cc-list">${cards.map(rowHtml).join('')}</div>
        ${cards.length>PER?`<div class="cc-more" onclick="this.previousElementSibling.querySelectorAll('.cc-row.cc-hidden').forEach(r=>r.classList.remove('cc-hidden'));this.remove()">Показать ещё ${cards.length-PER}</div>`:''}`;
      wrap.appendChild(g);
    });
    document.getElementById('ccTotal').textContent=total;
    if(!total) wrap.innerHTML='<div class="cc-empty">Ничего не найдено</div>';
  }
  window.ccDelete=function(url,name){
    if(!confirm('Отвязать карту клиента '+name+'?')) return;
    const f=document.getElementById('ccDeleteForm'); f.action=url; f.submit();
  };
  document.getElementById('ccFilters').addEventListener('click',e=>{const p=e.target.closest('.cc-fpill');if(!p)return;
    document.querySelectorAll('.cc-fpill').forEach(x=>x.classList.remove('active'));p.classList.add('active');fFilter=p.dataset.f;render();});
  document.getElementById('ccSorts').addEventListener('click',e=>{const s=e.target.closest('.cc-sort');if(!s)return;
    document.querySelectorAll('.cc-sort').forEach(x=>x.classList.remove('active'));s.classList.add('active');fSort=s.dataset.s;render();});
  document.getElementById('ccSearch').addEventListener('input',e=>{fQuery=e.target.value.trim();render();});
  render();
})();
</script>
@endsection

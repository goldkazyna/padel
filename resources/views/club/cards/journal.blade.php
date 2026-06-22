@extends('layouts.app')
@section('title', 'Журнал клубных карт')

@section('content')
<div class="cc-page">
    <div class="cc-head">
        <h1 class="cc-title">Журнал клубных карт <span class="cc-club">— {{ $club->name }}</span></h1>
        <span class="cc-spacer"></span>
        <a href="{{ route('club.cards.index') }}" class="cc-btn cc-ghost">‹ К картам</a>
    </div>
    <p class="cc-sub">История списаний часов с клубных карт-счётчиков.</p>

    <div class="cc-toolbar">
        <div class="cc-search"><span class="cc-sico"><i class="bi bi-search"></i></span>
            <input id="jSearch" placeholder="Поиск по имени или коду...">
        </div>
        <div class="cc-filters" id="jCourts">
            <span class="cc-fpill active" data-court="all">Все корты</span>
            @foreach($courts as $c)
                <span class="cc-fpill" data-court="{{ $c->id }}">{{ $c->name }}</span>
            @endforeach
        </div>
        <div class="cc-period-right">Списано за период: <b id="jSum">0</b> ч</div>
    </div>

    <div class="cc-toolbar">
        <span class="cc-plabel"><i class="bi bi-calendar3"></i> Период:</span>
        <div class="cc-filters" id="jPeriod">
            <span class="cc-fpill active" data-p="all">Всё</span>
            <span class="cc-fpill" data-p="today">Сегодня</span>
            <span class="cc-fpill" data-p="7">7 дней</span>
            <span class="cc-fpill" data-p="30">30 дней</span>
        </div>
        <input type="date" id="jFrom" class="cc-date"> <span class="cc-dash">—</span> <input type="date" id="jTo" class="cc-date">
        <div class="cc-period-right">Записей: <b id="jCount">0</b></div>
    </div>

    <div class="cc-jtable">
        <div class="cc-jhead">
            <span>Дата</span><span>Клиент / карта</span><span>Бронь</span><span class="r">Списание</span><span class="r">Остаток</span>
        </div>
        <div id="jBody"></div>
        <div class="cc-empty cc-hidden" id="jEmpty">Списаний за выбранный период нет.</div>
    </div>
</div>

@include('club.cards._cards_shared_css')
<style>
.cc-sub{color:var(--text-secondary);font-size:13px;margin:2px 0 16px}
.cc-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap}
.cc-search{position:relative;flex:0 0 260px}
.cc-search input{width:100%;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;color:var(--text-primary);padding:9px 12px 9px 34px;font-size:13px;outline:none}
.cc-sico{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted)}
.cc-filters{display:flex;gap:7px;flex-wrap:wrap}
.cc-fpill{background:var(--bg-card);border:1px solid var(--border);color:var(--text-secondary);border-radius:9px;padding:7px 12px;font-size:13px;font-weight:600;cursor:pointer}
.cc-fpill:hover{background:var(--bg-card-hover)}
.cc-fpill.active{background:#fff;color:#0a0a0a;border-color:#fff}
.cc-period-right{margin-left:auto;color:var(--text-secondary);font-size:13px}
.cc-period-right b{color:#f59e0b;font-weight:800}
.cc-plabel{color:var(--text-secondary);font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px}
.cc-date{background:var(--bg-card);border:1px solid var(--border);border-radius:9px;color:var(--text-primary);padding:7px 10px;font-size:13px;color-scheme:dark;outline:none}
.cc-dash{color:var(--text-muted)}
.cc-jtable{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.cc-jhead,.cc-jrow{display:grid;grid-template-columns:150px 1fr 230px 110px 90px;gap:14px;align-items:center;padding:12px 16px}
.cc-jhead{background:#16161a;border-bottom:1px solid var(--border);color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:.4px}
.cc-jhead .r,.cc-jrow .r{text-align:right}
.cc-jrow{border-bottom:1px solid var(--border)}
.cc-jrow:last-child{border-bottom:none}
.cc-jrow:hover{background:rgba(255,255,255,.02)}
.cc-jdate{color:var(--text-secondary);font-size:12.5px}
.cc-jclient{display:flex;align-items:center;gap:10px;min-width:0}
.cc-av{width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff}
.cc-jname{font-weight:700;font-size:14px;color:var(--text-primary)}
.cc-jmeta{margin-top:2px;display:flex;gap:7px;align-items:center}
.cc-jmeta .code{font-family:ui-monospace,monospace;font-size:11px;color:var(--text-muted)}
.cc-jbook .court{font-weight:700;font-size:13px;color:var(--text-primary)}
.cc-jbook .bt{color:var(--text-muted);font-size:12px;margin-top:2px}
.cc-jamt{text-align:right}
.cc-hb{background:rgba(245,158,11,.16);color:#f59e0b;font-weight:800;font-size:13px;padding:5px 10px;border-radius:8px}
.cc-jbal{text-align:right;font-weight:800;font-size:15px}
.cc-jbal.g{color:#22c55e} .cc-jbal.r{color:#ef4444}
.cc-empty{color:var(--text-secondary);padding:30px;text-align:center}
.cc-hidden{display:none!important}
</style>

<script>
(function(){
  const ROWS = @json($rows);
  const AVP=['#0ea5b7','#3b82f6','#8b5cf6','#f59e0b','#22c55e','#ec4899','#ef4444','#14b8a6'];
  let q='', court='all', period='all';
  function hash(s){let h=0;for(let i=0;i<s.length;i++)h=(h*31+s.charCodeAt(i))>>>0;return h;}
  function initials(n){const p=String(n).trim().split(/\s+/).filter(Boolean);if(!p.length)return '?';return (p[0][0]+(p[1]?p[1][0]:'')).toUpperCase();}
  function esc(s){return String(s).replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));}
  function range(){
    const f=document.getElementById('jFrom').value, t=document.getElementById('jTo').value;
    if(f||t){ const from=f?new Date(f+'T00:00:00').getTime()/1000:0; const to=t?new Date(t+'T23:59:59').getTime()/1000:Infinity; return [from,to]; }
    const now=Date.now()/1000;
    if(period==='today'){const d=new Date();d.setHours(0,0,0,0);return [d.getTime()/1000,Infinity];}
    if(period==='7')return [now-7*86400,Infinity];
    if(period==='30')return [now-30*86400,Infinity];
    return [0,Infinity];
  }
  function matches(r,from,to){
    if(q){const s=q.toLowerCase();if(!(r.name.toLowerCase().includes(s)||r.code.toLowerCase().includes(s)))return false;}
    if(court!=='all' && String(r.court_id)!==String(court))return false;
    if(r.ts<from||r.ts>to)return false;
    return true;
  }
  function render(){
    const [from,to]=range();
    const rows=ROWS.filter(r=>matches(r,from,to));
    const body=document.getElementById('jBody');
    body.innerHTML=rows.map(r=>{
      const col=AVP[hash(r.name)%AVP.length];
      const tag=r.tag?`<span class="cc-tagdot ${r.cls}">${esc(r.tag)}</span>`:'';
      const balCls=r.after<=0?'r':'g';
      return `<div class="cc-jrow">
        <span class="cc-jdate">${esc(r.date)}</span>
        <div class="cc-jclient"><div class="cc-av" style="background:${col}">${initials(r.name)}</div>
          <div><div class="cc-jname">${esc(r.name)}</div><div class="cc-jmeta">${tag}<span class="code">${esc(r.code)}</span></div></div></div>
        <div class="cc-jbook"><div class="court">${esc(r.court)}</div><div class="bt">${esc(r.booking)}</div></div>
        <div class="cc-jamt"><span class="cc-hb">−${r.hours}ч</span></div>
        <div class="cc-jbal ${balCls}">${r.after} ч</div>
      </div>`;
    }).join('');
    document.getElementById('jCount').textContent=rows.length;
    document.getElementById('jSum').textContent=rows.reduce((s,r)=>s+r.hours,0);
    document.getElementById('jEmpty').classList.toggle('cc-hidden',rows.length>0);
  }
  document.getElementById('jSearch').addEventListener('input',e=>{q=e.target.value.trim();render();});
  document.getElementById('jCourts').addEventListener('click',e=>{const p=e.target.closest('.cc-fpill');if(!p)return;
    document.querySelectorAll('#jCourts .cc-fpill').forEach(x=>x.classList.remove('active'));p.classList.add('active');court=p.dataset.court;render();});
  document.getElementById('jPeriod').addEventListener('click',e=>{const p=e.target.closest('.cc-fpill');if(!p)return;
    document.querySelectorAll('#jPeriod .cc-fpill').forEach(x=>x.classList.remove('active'));p.classList.add('active');period=p.dataset.p;
    document.getElementById('jFrom').value='';document.getElementById('jTo').value='';render();});
  function dateChanged(){document.querySelectorAll('#jPeriod .cc-fpill').forEach(x=>x.classList.remove('active'));render();}
  document.getElementById('jFrom').addEventListener('change',dateChanged);
  document.getElementById('jTo').addEventListener('change',dateChanged);
  render();
})();
</script>
@endsection

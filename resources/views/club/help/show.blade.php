@extends('layouts.app')
@section('title', $article['title'] . ' — Помощь')

@section('content')
<div class="ha-wrap">
    <div class="ha-crumbs">
        <a href="{{ route('club.help.index') }}">Помощь</a>
        <span class="ha-sep">/</span>
        <span class="ha-cat">{{ $section['title'] }}</span>
    </div>

    <h1 class="ha-title">{{ $article['title'] }}</h1>
    @if(!empty($article['excerpt']))<p class="ha-lede">{{ $article['excerpt'] }}</p>@endif

    <article class="ha-body">
        @include($articleView)
    </article>

    <a href="{{ route('club.help.index') }}" class="ha-back"><i class="bi bi-arrow-left"></i> Все инструкции</a>
</div>

<style>
.ha-wrap{max-width:1200px;margin:0 auto;color:var(--text-primary)}
.ha-crumbs{font-size:13px;color:var(--text-muted);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.ha-crumbs a{color:var(--text-secondary);text-decoration:none}
.ha-crumbs a:hover{color:var(--accent)}
.ha-sep{opacity:.5}
.ha-title{font-size:27px;font-weight:800;margin:0;letter-spacing:-.4px;line-height:1.15}
.ha-lede{font-size:15.5px;color:var(--text-secondary);margin:10px 0 0;max-width:70ch}

/* Общие стили содержимого статьи */
.ha-body{margin-top:30px}
.ha-note{display:flex;gap:12px;background:rgba(34,197,94,.07);border:1px solid rgba(34,197,94,.22);border-radius:12px;padding:14px 16px;margin:22px 0;font-size:14px;color:#bfe6cd}
.ha-note .ic{flex:none;width:22px;height:22px;border-radius:6px;background:rgba(34,197,94,.18);display:flex;align-items:center;justify-content:center;color:var(--accent);font-weight:900;font-size:13px}
.ha-step{margin:34px 0;padding-top:24px;border-top:1px solid var(--border)}
.ha-step:first-child{border-top:none;padding-top:0;margin-top:20px}
.ha-step-h{display:flex;align-items:center;gap:13px;margin-bottom:8px}
.ha-step-n{flex:none;width:32px;height:32px;border-radius:50%;background:var(--accent);color:#06210f;font-weight:800;font-size:15px;display:flex;align-items:center;justify-content:center}
.ha-step-t{font-size:19px;font-weight:800;margin:0}
.ha-step p{color:var(--text-secondary);font-size:15px;margin:0 0 14px 45px}
.ha-step p b{color:var(--text-primary)}
.ha-shot{margin:6px 0 0 45px;border:1px solid var(--border);border-radius:12px;overflow:hidden;background:var(--bg-card);max-width:900px}
.ha-shot img{display:block;width:100%;height:auto}
.ha-cap{font-size:12.5px;color:var(--text-muted);margin:8px 0 0 45px}
ul.ha-list{margin:14px 0 0 45px;padding:0;list-style:none}
ul.ha-list li{position:relative;padding:5px 0 5px 20px;font-size:14.5px;color:var(--text-secondary)}
ul.ha-list li:before{content:"";position:absolute;left:2px;top:12px;width:6px;height:6px;border-radius:50%;background:var(--accent)}
ul.ha-list li b{color:var(--text-primary)}
.ha-kbd{background:rgba(255,255,255,.06);border:1px solid var(--border-light,rgba(255,255,255,.14));border-radius:6px;padding:1px 7px;font-size:13px;font-weight:700;color:var(--text-primary);white-space:nowrap}
.ha-back{display:inline-flex;align-items:center;gap:8px;margin-top:40px;padding:10px 16px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;color:var(--text-secondary);font-size:13px;font-weight:700;text-decoration:none}
.ha-back:hover{border-color:var(--border-light);color:var(--text-primary)}
@media(max-width:820px){
  .ha-step p,.ha-shot,.ha-cap,ul.ha-list{margin-left:0}
  .ha-step-h{gap:10px}
}
</style>
@endsection

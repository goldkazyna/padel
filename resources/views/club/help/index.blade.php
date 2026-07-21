@extends('layouts.app')
@section('title', 'Помощь')

@section('content')
<div class="help-wrap">
    <div class="help-head">
        <h1 class="help-title">Помощь</h1>
        <p class="help-sub">Пошаговые инструкции по работе с CRM. Раздел пополняется.</p>
    </div>

    <div class="help-grid">
        @foreach($sections as $sec)
        <div class="help-cat">
            <div class="help-cat-h">
                <span class="help-cat-ic"><i class="bi {{ $sec['icon'] }}"></i></span>
                <span class="help-cat-t">{{ $sec['title'] }}</span>
                <span class="help-cat-n">{{ count($sec['articles']) }}</span>
            </div>
            @if(count($sec['articles']))
                <div class="help-arts">
                    @foreach($sec['articles'] as $a)
                    <a href="{{ route('club.help.show', $a['slug']) }}" class="help-art">
                        <div class="help-art-main">
                            <div class="help-art-t">{{ $a['title'] }}</div>
                            <div class="help-art-e">{{ $a['excerpt'] }}</div>
                        </div>
                        <i class="bi bi-chevron-right help-art-arr"></i>
                    </a>
                    @endforeach
                </div>
            @else
                <div class="help-soon">Скоро</div>
            @endif
        </div>
        @endforeach
    </div>
</div>

<style>
.help-wrap{max-width:1200px;margin:0 auto;color:var(--text-primary)}
.help-head{margin-bottom:26px}
.help-title{font-size:24px;font-weight:800;margin:0;letter-spacing:-.3px}
.help-sub{color:var(--text-secondary);font-size:14px;margin:6px 0 0}
.help-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.help-cat{background:var(--bg-card);border:1px solid var(--border);border-radius:16px;padding:18px}
.help-cat-h{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.help-cat-ic{width:38px;height:38px;flex:none;border-radius:10px;background:rgba(255,255,255,.05);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:17px;color:var(--text-secondary)}
.help-cat-t{font-size:16px;font-weight:800}
.help-cat-n{margin-left:auto;font-size:12px;font-weight:800;color:var(--text-muted);background:rgba(255,255,255,.05);border-radius:100px;min-width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;padding:0 7px}
.help-arts{display:flex;flex-direction:column;gap:8px}
.help-art{display:flex;align-items:center;gap:12px;padding:13px 14px;background:var(--bg-primary,#0f1113);border:1px solid var(--border);border-radius:11px;text-decoration:none;transition:border-color .15s,background .15s}
.help-art:hover{border-color:var(--accent);background:rgba(34,197,94,.05)}
.help-art-main{flex:1;min-width:0}
.help-art-t{font-size:14.5px;font-weight:700;color:var(--text-primary)}
.help-art-e{font-size:12.5px;color:var(--text-secondary);margin-top:3px}
.help-art-arr{color:var(--text-muted);font-size:14px;flex:none}
.help-soon{font-size:13px;color:var(--text-muted);padding:10px 2px;font-style:italic}
@media(max-width:820px){.help-grid{grid-template-columns:1fr}}
</style>
@endsection

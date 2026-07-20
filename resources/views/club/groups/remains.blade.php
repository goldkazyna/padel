@extends('layouts.app')
@section('title', 'Остатки занятий')

@section('content')

@php
    $plZan = function ($n) { $n = abs((int) $n); $d100 = $n % 100; $d10 = $n % 10; if ($d100 >= 11 && $d100 <= 14) return 'занятий'; if ($d10 === 1) return 'занятие'; if ($d10 >= 2 && $d10 <= 4) return 'занятия'; return 'занятий'; };
@endphp

<div class="rem-container">
    <div class="rem-head">
        <a href="{{ route('club.groups.index') }}" class="rem-back">&#8592;</a>
        <div>
            <h1 class="rem-title">Остатки занятий</h1>
            <div class="rem-subtitle">Кому пора продлить абонемент — по всем активным группам</div>
        </div>
    </div>

    <div class="rem-stats">
        <div class="rem-stat zero">
            <div class="rem-stat-num">{{ count($remEnded) }}</div>
            <div class="rem-stat-label">Закончились</div>
        </div>
        <div class="rem-stat low">
            <div class="rem-stat-num">{{ count($remEnding) }}</div>
            <div class="rem-stat-label">Заканчиваются</div>
        </div>
    </div>

    @if(!count($remEnded) && !count($remEnding))
        <div class="rem-empty">
            <div class="rem-empty-icon">&#128077;</div>
            <p>У всех участников достаточный запас занятий.</p>
        </div>
    @endif

    @if(count($remEnded))
        <div class="rem-sec">
            <div class="rem-sec-title"><span class="rem-sec-dot zero"></span> Закончились <span class="rem-sec-count">{{ count($remEnded) }}</span></div>
            @foreach($remEnded as $r)
                <a href="{{ route('club.groups.show', $r['group_id']) }}" class="rem-row">
                    <span class="rem-dot zero"></span>
                    <div class="rem-info">
                        <span class="rem-name">{{ $r['name'] }}</span>
                        <span class="rem-group">{{ $r['group'] }}@if($r['coach']) · {{ $r['coach'] }}@endif</span>
                    </div>
                    <span class="rem-badge zero">{{ $r['rem'] }} {{ $plZan($r['rem']) }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @if(count($remEnding))
        <div class="rem-sec">
            <div class="rem-sec-title"><span class="rem-sec-dot low"></span> Заканчиваются <span class="rem-sec-count">{{ count($remEnding) }}</span></div>
            @foreach($remEnding as $r)
                <a href="{{ route('club.groups.show', $r['group_id']) }}" class="rem-row">
                    <span class="rem-dot low"></span>
                    <div class="rem-info">
                        <span class="rem-name">{{ $r['name'] }}</span>
                        <span class="rem-group">{{ $r['group'] }}@if($r['coach']) · {{ $r['coach'] }}@endif</span>
                    </div>
                    <span class="rem-badge low">{{ $r['rem'] }} {{ $plZan($r['rem']) }}</span>
                </a>
            @endforeach
        </div>
    @endif
</div>

<style>
    .rem-container { max-width: 1200px; margin: 0 auto; padding: 24px 16px 40px; }

    .rem-head { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; }
    .rem-back { width: 38px; height: 38px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; background: #15181A; border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; color: #d4d7da; font-size: 18px; text-decoration: none; transition: border-color 0.15s; }
    .rem-back:hover { border-color: rgba(255,255,255,0.2); }
    .rem-title { font-size: 22px; font-weight: 800; color: #f4f6f7; margin: 0; line-height: 1.2; }
    .rem-subtitle { font-size: 13px; color: #7c848a; margin-top: 3px; }

    .rem-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 26px; }
    .rem-stat { padding: 16px 18px; border-radius: 14px; border: 1px solid; }
    .rem-stat.zero { background: rgba(229,86,78,0.09); border-color: rgba(229,86,78,0.28); }
    .rem-stat.low { background: rgba(234,179,8,0.09); border-color: rgba(234,179,8,0.28); }
    .rem-stat-num { font-size: 30px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
    .rem-stat.zero .rem-stat-num { color: #ef7a73; }
    .rem-stat.low .rem-stat-num { color: #eab308; }
    .rem-stat-label { font-size: 12.5px; font-weight: 700; color: #9aa1a7; margin-top: 8px; text-transform: uppercase; letter-spacing: 0.4px; }

    .rem-sec { margin-bottom: 26px; }
    .rem-sec:last-child { margin-bottom: 0; }
    .rem-sec-title { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #a1a1aa; margin-bottom: 12px; }
    .rem-sec-dot { width: 8px; height: 8px; border-radius: 50%; }
    .rem-sec-dot.zero { background: #e5564e; }
    .rem-sec-dot.low { background: #eab308; }
    .rem-sec-count { margin-left: auto; font-size: 12px; font-weight: 800; color: #71717a; }

    .rem-row { display: flex; align-items: center; gap: 12px; padding: 13px 15px; background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; margin-bottom: 8px; text-decoration: none; transition: border-color 0.15s, background 0.15s; }
    .rem-row:hover { border-color: rgba(255,255,255,0.16); background: #171a1e; }
    .rem-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
    .rem-dot.zero { background: #e5564e; }
    .rem-dot.low { background: #eab308; }
    .rem-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
    .rem-name { font-size: 15px; font-weight: 700; color: #f4f6f7; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .rem-group { font-size: 12.5px; color: #8b9298; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .rem-badge { flex-shrink: 0; font-size: 12px; font-weight: 800; padding: 5px 11px; border-radius: 9px; font-variant-numeric: tabular-nums; }
    .rem-badge.zero { background: rgba(229,86,78,0.14); color: #ef7a73; }
    .rem-badge.low { background: rgba(234,179,8,0.15); color: #eab308; }

    .rem-empty { text-align: center; padding: 60px 20px; color: #71717a; }
    .rem-empty-icon { font-size: 40px; margin-bottom: 12px; }
    .rem-empty p { font-size: 15px; }

    @media (max-width: 560px) {
        .rem-stats { grid-template-columns: 1fr; }
    }
</style>

@endsection

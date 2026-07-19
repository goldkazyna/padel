@extends('layouts.app')
@section('title', 'К списанию')

@section('content')
@php
    $avPalette = ['#0ea5b7','#3b82f6','#8b5cf6','#f59e0b','#22c55e','#ec4899','#ef4444','#14b8a6'];
@endphp
<div class="cc-page">
    <div class="cc-head">
        <h1 class="cc-title">К списанию <span class="cc-club">— {{ $club->name }}</span>
            @if($bookings->isNotEmpty())<span class="cc-cnt">● {{ $bookings->count() }}</span>@endif
        </h1>
        <span class="cc-spacer"></span>
        <a href="{{ route('club.cards.journal') }}" class="cc-btn cc-ghost">‹ Журнал</a>
    </div>
    <a href="{{ route('club.cards.index') }}" class="cc-back">← Клубные карты</a>
    <p class="cc-sub">Завершённые брони, оплаченные клубной картой-счётчиком. Подтвердите списание часов с карты или пропустите бронь, если списывать не нужно.</p>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash-message flash-error">{{ session('error') }}</div>@endif

    @if($bookings->isEmpty())
        <div class="cc-empty"><div style="font-size:38px;color:var(--accent);margin-bottom:8px">✓</div>Нет броней к списанию. Всё обработано.</div>
    @else
        @foreach($bookings as $b)
            @php
                $card = $b->clubCard;
                $name = $card?->client?->name ?? $b->client_name ?? '—';
                $parts = preg_split('/\s+/', trim($name));
                $initials = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
                $avColor = $avPalette[abs(crc32($name)) % count($avPalette)];
                $tname = $card?->type?->name;
                $tag = $tname ? mb_substr(trim(explode(' ', $tname)[0]), 0, 14) : '';
                $cls = $typeCls[$card?->club_card_type_id] ?? 't-purple';
                $bal = (int) ($card?->balance ?? 0);
                $hStart = \Carbon\Carbon::parse(substr($b->start_time, 0, 5));
                $hEnd = \Carbon\Carbon::parse(substr($b->end_time, 0, 5));
                if ($hEnd->lessThanOrEqualTo($hStart)) $hEnd->addDay(); // бронь через полночь (22:00–00:00)
                $hours = (int) round($hStart->diffInMinutes($hEnd) / 60);
            @endphp
            <div class="cc-prow">
                <div class="cc-av" style="background:{{ $avColor }}">{{ $initials ?: '?' }}</div>
                <div class="cc-pinfo">
                    <div class="n">{{ $name }}</div>
                    <div class="meta">
                        @if($tag)<span class="cc-tagdot {{ $cls }}">{{ $tag }}</span>@endif
                        <span class="code">{{ $card?->code }}</span>
                        <span>· остаток <b class="{{ $bal <= 0 ? 'r' : ($bal <= 2 ? 'a' : 'g') }}">{{ $bal }} ч</b></span>
                    </div>
                </div>
                <div class="cc-pwhen">
                    <div class="court">{{ $b->court?->name }}</div>
                    <div class="bt">{{ \Carbon\Carbon::parse($b->date)->format('d.m.Y') }}, {{ substr($b->start_time,0,5) }}–{{ substr($b->end_time,0,5) }}</div>
                </div>
                <span class="cc-hbadge">−{{ $hours }}ч</span>
                <form action="{{ route('club.cards.pending.charge', $b) }}" method="POST" style="margin:0">
                    @csrf
                    <button type="submit" class="cc-btn cc-green" onclick="return confirm('Списать {{ $hours }} ч с карты {{ $card?->code }}?')">✓ Списать</button>
                </form>
                <form action="{{ route('club.cards.pending.skip', $b) }}" method="POST" style="margin:0">
                    @csrf
                    <button type="submit" class="cc-btn cc-ghost" onclick="return confirm('Пометить бронь без списания?')">Не списывать</button>
                </form>
            </div>
        @endforeach
    @endif
</div>

@include('club.cards._cards_shared_css')
<style>
.cc-cnt{display:inline-flex;align-items:center;gap:6px;background:var(--accent-glow);color:var(--accent);font-size:13px;font-weight:700;padding:4px 11px;border-radius:100px;margin-left:8px;vertical-align:middle}
.cc-back{display:inline-block;color:#a78bfa;font-size:13px;font-weight:600;text-decoration:none;margin-bottom:6px}
.cc-back:hover{text-decoration:underline}
.cc-sub{color:var(--text-secondary);font-size:13px;line-height:1.5;max-width:560px;margin:0 0 18px}
.cc-prow{display:flex;align-items:center;gap:14px;background:var(--bg-card);border:1px solid var(--border);border-radius:14px;padding:13px 16px;margin-bottom:10px}
.cc-prow:hover{background:var(--bg-card-hover)}
.cc-av{width:40px;height:40px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff}
.cc-pinfo{flex:1;min-width:0}
.cc-pinfo .n{font-weight:700;font-size:15px;color:var(--text-primary)}
.cc-pinfo .meta{color:var(--text-secondary);font-size:12.5px;margin-top:3px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.cc-pinfo .code{font-family:ui-monospace,monospace;font-size:11px;color:var(--text-muted)}
.cc-pinfo .meta b.g{color:var(--accent)} .cc-pinfo .meta b.a{color:#f59e0b} .cc-pinfo .meta b.r{color:#ef4444}
.cc-pwhen{text-align:right;min-width:150px}
.cc-pwhen .court{font-weight:700;font-size:13px;color:var(--text-primary)}
.cc-pwhen .bt{color:var(--text-muted);font-size:12px;margin-top:2px}
.cc-hbadge{background:rgba(245,158,11,.16);color:#f59e0b;font-weight:800;font-size:13px;padding:6px 10px;border-radius:8px;white-space:nowrap}
.cc-empty{color:var(--text-secondary);padding:40px;text-align:center;background:var(--bg-card);border:1px dashed var(--border-light);border-radius:14px}
@media(max-width:820px){.cc-prow{flex-wrap:wrap}.cc-pwhen{text-align:left;min-width:0}}
</style>
@endsection

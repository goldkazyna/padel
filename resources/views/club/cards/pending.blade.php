@extends('layouts.app')
@section('title', 'К списанию')

@section('content')
<div class="cards-page">
    <div class="cards-header">
        <h1 class="cards-title">
            К списанию <span class="cards-title-club">— {{ $club->name }}</span>
            @if($bookings->isNotEmpty())
                <span class="pc-count">● {{ $bookings->count() }}</span>
            @endif
        </h1>
        <div class="cards-header-actions">
            <a href="{{ route('club.cards.index') }}" class="btn-journal">← Клубные карты</a>
        </div>
    </div>

    <p class="pc-sub">Завершённые брони, оплаченные клубной картой-счётчиком. Подтвердите списание часов с карты или пропустите бронь, если списывать не нужно.</p>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash-message flash-error">{{ session('error') }}</div>@endif

    @if($bookings->isEmpty())
        <div class="pc-empty"><div class="pc-empty-ico">✓</div>Нет броней к списанию. Всё обработано.</div>
    @else
        @foreach($bookings as $b)
            @php
                $card = $b->clubCard;
                $name = $card?->client?->name ?? $b->client_name ?? '—';
                $hours = (int) round(max(0, \Carbon\Carbon::parse(substr($b->start_time,0,5))
                    ->diffInMinutes(\Carbon\Carbon::parse(substr($b->end_time,0,5)))) / 60);
                $bal = (int) ($card?->balance ?? 0);
                $balClass = $bal <= 0 ? 'pc-zero' : ($bal <= 2 ? 'pc-low' : 'pc-ok');
                $parts = preg_split('/\s+/', trim($name));
                $initials = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
            @endphp
            <div class="pc-row">
                <div class="pc-avatar">{{ $initials ?: '?' }}</div>
                <div class="pc-main">
                    <div class="pc-name">{{ $name }}</div>
                    <div class="pc-meta">
                        @if($card?->code)<span class="pc-chip">{{ $card->code }}</span>@endif
                        <span>{{ $card?->type?->name }}</span>
                        <span>· остаток <b class="{{ $balClass }}">{{ $bal }} ч</b></span>
                    </div>
                </div>
                <div class="pc-when">
                    <div class="pc-when-d">{{ \Carbon\Carbon::parse($b->date)->format('d.m.Y') }}</div>
                    <div class="pc-when-t">{{ substr($b->start_time,0,5) }}–{{ substr($b->end_time,0,5) }}</div>
                </div>
                <div class="pc-hours">−{{ $hours }} ч</div>
                <div class="pc-act">
                    <form action="{{ route('club.cards.pending.charge', $b) }}" method="POST">
                        @csrf
                        <button type="submit" class="pc-btn pc-btn-charge"
                            onclick="return confirm('Списать {{ $hours }} ч с карты {{ $card?->code }}?')">Списать</button>
                    </form>
                    <form action="{{ route('club.cards.pending.skip', $b) }}" method="POST">
                        @csrf
                        <button type="submit" class="pc-btn pc-btn-skip"
                            onclick="return confirm('Пометить бронь без списания?')">Не списывать</button>
                    </form>
                </div>
            </div>
        @endforeach
    @endif
</div>

<style>
.pc-count{display:inline-flex;align-items:center;gap:6px;background:var(--accent-glow);color:var(--accent);
    font-size:12px;font-weight:700;padding:4px 10px;border-radius:100px;margin-left:8px;vertical-align:middle}
.pc-sub{color:var(--text-secondary);font-size:13px;line-height:1.5;max-width:580px;margin:0 0 20px}
.pc-row{display:flex;align-items:center;gap:16px;background:var(--bg-card);border:1px solid var(--border);
    border-radius:14px;padding:14px 16px;margin-bottom:10px;transition:background .15s}
.pc-row:hover{background:var(--bg-card-hover)}
.pc-avatar{width:38px;height:38px;border-radius:50%;background:#0ea5b7;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff}
.pc-main{flex:1;min-width:0}
.pc-name{font-weight:700;font-size:15px;color:var(--text-primary)}
.pc-meta{color:var(--text-secondary);font-size:12.5px;margin-top:3px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.pc-chip{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:var(--text-secondary);
    background:var(--bg-card-hover);padding:2px 7px;border-radius:6px}
.pc-ok{color:var(--accent)} .pc-low{color:var(--amber,#f59e0b)} .pc-zero{color:var(--danger,#ef4444)}
.pc-when{text-align:right;min-width:104px}
.pc-when-d{font-size:13px;font-weight:600;color:var(--text-primary)}
.pc-when-t{color:var(--text-muted);font-size:12px}
.pc-hours{display:inline-flex;align-items:center;background:rgba(245,158,11,.14);color:var(--amber,#f59e0b);
    font-weight:800;font-size:13px;padding:6px 10px;border-radius:8px;white-space:nowrap}
.pc-act{display:flex;gap:8px}
.pc-act form{margin:0}
.pc-btn{border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;padding:9px 16px;white-space:nowrap}
.pc-btn-charge{background:var(--accent);color:#06210f}
.pc-btn-charge:hover{background:var(--accent-dark)}
.pc-btn-skip{background:transparent;border:1px solid var(--border-light);color:var(--text-secondary);padding:8px 14px}
.pc-btn-skip:hover{color:var(--text-primary);border-color:var(--text-muted)}
.pc-empty{background:var(--bg-card);border:1px dashed var(--border-light);border-radius:14px;
    padding:40px;text-align:center;color:var(--text-secondary)}
.pc-empty-ico{font-size:40px;margin-bottom:10px;color:var(--accent)}
@media(max-width:720px){
    .pc-row{flex-wrap:wrap}
    .pc-when{text-align:left;min-width:0}
    .pc-act{width:100%}
    .pc-act form{flex:1}
    .pc-btn{width:100%}
}
</style>
@endsection

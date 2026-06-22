@extends('layouts.app')
@section('title', 'Не выставлены карты')

@section('content')
<div class="cc-page">
    <div class="cc-head">
        <h1 class="cc-title">Не выставлены карты <span class="cc-club">— {{ $club->name }}</span>
            @if($bookings->isNotEmpty())<span class="cc-cnt">● {{ $bookings->count() }}</span>@endif
        </h1>
        <span class="cc-spacer"></span>
        <a href="{{ route('club.cards.index') }}" class="cc-btn cc-ghost">‹ К картам</a>
    </div>
    <p class="cc-sub">Брони, где выбран способ оплаты «клубная карта», но сама карта не привязана (с 15 июня). Откройте бронь в расписании, выберите карту клиента и сохраните — после окончания брони она попадёт в «К списанию».</p>

    @if($bookings->isEmpty())
        <div class="cc-empty"><div style="font-size:38px;color:var(--accent);margin-bottom:8px">✓</div>Все карты выставлены — броней без карты нет.</div>
    @else
    <div class="cc-utable">
        <div class="cc-uhead">
            <span>Дата</span><span>Клиент</span><span>Корт</span><span>Время</span><span class="r">Цена</span><span></span>
        </div>
        @foreach($bookings as $b)
        <a class="cc-urow" href="{{ route('club.courts.schedule', ['date' => \Carbon\Carbon::parse($b->date)->format('Y-m-d')]) }}">
            <span class="cc-udate">{{ \Carbon\Carbon::parse($b->date)->format('d.m.Y') }}</span>
            <span class="cc-uclient"><b>{{ $b->client_name }}</b><span class="ph">{{ $b->client_phone }}</span></span>
            <span class="cc-ucourt">{{ $b->court?->name }}</span>
            <span class="cc-utime">{{ substr($b->start_time,0,5) }}–{{ substr($b->end_time,0,5) }}</span>
            <span class="r cc-uprice">{{ number_format($b->price, 0, '', ' ') }} ₸</span>
            <span class="r cc-uopen">Открыть ›</span>
        </a>
        @endforeach
    </div>
    @endif
</div>

@include('club.cards._cards_shared_css')
<style>
.cc-cnt{display:inline-flex;align-items:center;gap:6px;background:rgba(239,68,68,.16);color:#ef4444;font-size:13px;font-weight:700;padding:4px 11px;border-radius:100px;margin-left:8px;vertical-align:middle}
.cc-sub{color:var(--text-secondary);font-size:13px;line-height:1.5;max-width:620px;margin:6px 0 18px}
.cc-utable{background:var(--bg-card);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.cc-uhead,.cc-urow{display:grid;grid-template-columns:120px 1fr 110px 130px 120px 110px;gap:14px;align-items:center;padding:13px 16px}
.cc-uhead{background:#16161a;border-bottom:1px solid var(--border);color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:.4px}
.cc-uhead .r,.cc-urow .r{text-align:right}
.cc-urow{border-bottom:1px solid var(--border);text-decoration:none;color:var(--text-primary)}
.cc-urow:last-child{border-bottom:none}
.cc-urow:hover{background:rgba(255,255,255,.02)}
.cc-udate{color:var(--text-secondary);font-size:13px}
.cc-uclient b{font-weight:700;font-size:14px}
.cc-uclient .ph{display:block;color:var(--text-muted);font-size:12px;margin-top:2px}
.cc-ucourt{color:var(--text-secondary);font-size:13px}
.cc-utime{color:var(--text-primary);font-size:13px;font-weight:600}
.cc-uprice{color:var(--text-secondary);font-size:13px}
.cc-uopen{color:var(--accent);font-weight:700;font-size:13px}
.cc-empty{color:var(--text-secondary);padding:40px;text-align:center;background:var(--bg-card);border:1px dashed var(--border-light);border-radius:14px}
@media(max-width:820px){.cc-uhead,.cc-urow{grid-template-columns:1fr 1fr;gap:6px}.cc-uhead{display:none}}
</style>
@endsection

@extends('layouts.app')
@section('title', 'Журнал клубных карт')

@section('content')
<div class="cards-page">
    <div class="cards-header">
        <h1 class="cards-title">Журнал клубных карт <span class="cards-title-club">— {{ $club->name }}</span></h1>
        <a href="{{ route('club.cards.index') }}" class="btn-back-cards">← К картам</a>
    </div>

    @if($transactions->isEmpty())
        <div class="cards-empty">Списаний пока нет. Часы списываются автоматически после завершения брони.</div>
    @else
    <div class="journal-list">
        <div class="journal-row journal-head">
            <span class="jr-date">Дата</span>
            <span class="jr-client">Клиент / карта</span>
            <span class="jr-booking">Бронь</span>
            <span class="jr-amount">Списание</span>
            <span class="jr-balance">Остаток</span>
        </div>
        @foreach($transactions as $tx)
        <div class="journal-row">
            <span class="jr-date">{{ $tx->created_at->format('d.m.Y H:i') }}</span>
            <span class="jr-client">
                <span class="jr-name">{{ $tx->card?->client?->name ?? '—' }}</span>
                <span class="jr-card">{{ $tx->card?->type?->name }} · <span class="jr-code">{{ $tx->card?->code }}</span></span>
            </span>
            <span class="jr-booking">
                @if($tx->booking)
                    {{ $tx->booking->court?->name }}<br>
                    <span class="jr-bdate">{{ \Illuminate\Support\Carbon::parse($tx->booking->date)->format('d.m.Y') }}, {{ substr($tx->booking->start_time,0,5) }}–{{ substr($tx->booking->end_time,0,5) }}</span>
                @else
                    —
                @endif
            </span>
            <span class="jr-amount {{ $tx->amount < 0 ? 'jr-minus' : 'jr-plus' }}">{{ $tx->amount > 0 ? '+' : '' }}{{ $tx->amount }} ч</span>
            <span class="jr-balance">{{ $tx->balance_after }} ч</span>
        </div>
        @endforeach
    </div>
    <div class="journal-pagination">{{ $transactions->links() }}</div>
    @endif
</div>

<style>
.cards-page { max-width: 980px; }
.cards-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
.cards-title { font-size:24px; font-weight:800; color:#fff; margin:0; }
.cards-title-club { color:#71717a; font-weight:500; font-size:16px; }
.btn-back-cards { background:#27272a; color:#d4d4d8; border:none; border-radius:10px; padding:10px 16px; font-weight:700; text-decoration:none; }
.cards-empty { color:#71717a; padding:24px; text-align:center; background:#18181b; border:1px solid #27272a; border-radius:12px; }
.journal-list { display:flex; flex-direction:column; gap:6px; }
.journal-row { display:grid; grid-template-columns:130px 1fr 1fr 90px 80px; gap:12px; align-items:center; background:#18181b; border:1px solid #27272a; border-radius:10px; padding:11px 14px; }
.journal-head { background:transparent; border:none; padding:4px 14px; color:#71717a; font-size:11px; text-transform:uppercase; letter-spacing:.5px; }
.jr-date { color:#a1a1aa; font-size:12px; }
.jr-name { display:block; color:#fff; font-weight:600; font-size:14px; }
.jr-card { color:#a1a1aa; font-size:12px; }
.jr-code { font-family:monospace; letter-spacing:1px; color:#71717a; }
.jr-booking { color:#d4d4d8; font-size:13px; }
.jr-bdate { color:#71717a; font-size:11px; }
.jr-amount { font-weight:800; font-size:15px; text-align:right; }
.jr-minus { color:#f08446; }
.jr-plus { color:#22c55e; }
.jr-balance { color:#fff; font-weight:700; text-align:right; }
.journal-pagination { margin-top:16px; }
</style>
@endsection

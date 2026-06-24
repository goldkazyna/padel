@extends($__layout ?? 'layouts.app')
@section('title', 'Карта ' . $card->code)

@section('content')
@php $bare = (($__layout ?? '') === 'layouts.bare'); @endphp
<div class="ch-page">
    <div class="ch-card-head">
        <div class="ch-title">{{ $card->type?->name ?? 'Карта' }}</div>
        <div class="ch-code">{{ $card->code }}</div>
        <div class="ch-client">{{ $card->client?->name }}</div>
        <div class="ch-stats">
            @if($card->isCounter())
                <span class="ch-balance">{{ (int) $card->balance }}<span class="ch-of">/{{ (int) $card->initial_balance }} ч</span></span>
            @else
                <span class="ch-balance ch-discount">−{{ $card->type?->discount_percent }}%</span>
            @endif
            @if($card->expires_at)<span class="ch-exp">до {{ $card->expires_at->format('d.m.Y') }}</span>@else<span class="ch-exp">бессрочно</span>@endif
            <span class="ch-status {{ $card->isActual() ? 'ok' : 'dead' }}">{{ $card->isActual() ? 'активна' : 'не активна' }}</span>
        </div>
    </div>

    <div class="ch-section-title">История списаний</div>
    @if($card->transactions->isEmpty())
        <div class="ch-empty">Списаний пока нет. Часы списываются автоматически после завершения брони.</div>
    @else
    <div class="ch-list">
        @foreach($card->transactions as $tx)
        <div class="ch-row">
            <div class="ch-row-main">
                <div class="ch-row-date">{{ $tx->created_at->timezone(config('app.schedule_timezone', 'Asia/Almaty'))->format('d.m.Y H:i') }}</div>
                <div class="ch-row-sub">
                    @if($tx->booking)
                        {{ $tx->booking->court?->name }} · {{ \Illuminate\Support\Carbon::parse($tx->booking->date)->format('d.m.Y') }}, {{ substr($tx->booking->start_time,0,5) }}–{{ substr($tx->booking->end_time,0,5) }}
                    @else
                        {{ $tx->note ?? 'Операция' }}
                    @endif
                </div>
            </div>
            <div class="ch-row-amt {{ $tx->amount < 0 ? 'minus' : 'plus' }}">{{ $tx->amount > 0 ? '+' : '' }}{{ $tx->amount }} ч</div>
            <div class="ch-row-bal">ост. {{ $tx->balance_after }} ч</div>
        </div>
        @endforeach
    </div>
    @endif
</div>

<style>
.ch-page { max-width: 760px; margin: 0 auto; padding: 22px 20px; color: #f3f3f5; }
.ch-card-head { background: #18181b; border: 1px solid #27272a; border-radius: 14px; padding: 18px 20px; margin-bottom: 20px; }
.ch-title { font-size: 19px; font-weight: 800; color: #fff; }
.ch-code { font-family: monospace; letter-spacing: 1px; color: #a78bfa; margin-top: 2px; }
.ch-client { color: #a1a1aa; font-size: 14px; margin-top: 6px; }
.ch-stats { display: flex; align-items: center; gap: 12px; margin-top: 12px; flex-wrap: wrap; }
.ch-balance { font-size: 22px; font-weight: 800; color: #22c55e; }
.ch-balance.ch-discount { color: #f08446; }
.ch-of { color: #71717a; font-weight: 500; font-size: 14px; }
.ch-exp { color: #a1a1aa; font-size: 13px; }
.ch-status { font-size: 12px; font-weight: 700; padding: 2px 10px; border-radius: 999px; }
.ch-status.ok { background: rgba(34,197,94,.14); color: #22c55e; }
.ch-status.dead { background: rgba(239,68,68,.14); color: #ef4444; }
.ch-section-title { color: #a1a1aa; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin: 8px 0 12px; }
.ch-empty { color: #71717a; padding: 24px; text-align: center; background: #18181b; border: 1px solid #27272a; border-radius: 12px; }
.ch-list { display: flex; flex-direction: column; gap: 8px; }
.ch-row { display: grid; grid-template-columns: 1fr 80px 90px; gap: 12px; align-items: center; background: #18181b; border: 1px solid #27272a; border-radius: 10px; padding: 11px 14px; }
.ch-row-date { color: #f4f4f5; font-weight: 600; font-size: 14px; }
.ch-row-sub { color: #a1a1aa; font-size: 12px; margin-top: 2px; }
.ch-row-amt { font-weight: 800; font-size: 16px; text-align: right; }
.ch-row-amt.minus { color: #f08446; }
.ch-row-amt.plus { color: #22c55e; }
.ch-row-bal { color: #71717a; font-size: 12px; text-align: right; }
</style>
@endsection

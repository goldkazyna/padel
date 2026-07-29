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
            <span class="ch-exp">@if($card->expires_at)до {{ $card->expires_at->format('d.m.Y') }}@else бессрочно @endif</span>
            <span class="ch-status {{ $card->isActual() ? 'ok' : 'dead' }}">{{ $card->isActual() ? 'активна' : 'не активна' }}</span>
            <button type="button" class="ch-edit-btn" onclick="chToggleEdit(true)">Редактировать</button>
        </div>

        <form method="POST" action="{{ route('club.cards.updateExpiry', $card) }}" id="ch-edit-form" class="ch-edit-form" style="display:none;">
            @csrf
            @method('PUT')
            @if($card->isCounter())
            <div class="ch-edit-grid">
                <div class="ch-edit-field">
                    <label>Остаток часов</label>
                    <input type="number" name="balance" min="0" max="100000" value="{{ (int) $card->balance }}">
                </div>
                <div class="ch-edit-field">
                    <label>Всего часов</label>
                    <input type="number" name="initial_balance" min="1" max="100000" value="{{ (int) $card->initial_balance }}">
                </div>
            </div>
            @endif
            <div class="ch-edit-field">
                <label>Действует до</label>
                <input type="date" name="expires_at" value="{{ $card->expires_at?->format('Y-m-d') }}">
                <small class="ch-edit-hint">Пусто — бессрочно</small>
            </div>
            <div class="ch-edit-actions">
                <button type="submit" class="ch-exp-save">Сохранить</button>
                <button type="button" class="ch-exp-cancel" onclick="chToggleEdit(false)">Отмена</button>
            </div>
        </form>
    </div>

    <div class="ch-section-title">История операций</div>
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
.ch-edit-btn { margin-left: auto; background: rgba(167,139,250,.14); border: 1px solid rgba(167,139,250,.35); color: #c4b5fd; border-radius: 8px; padding: 5px 14px; font-size: 13px; font-weight: 700; cursor: pointer; }
.ch-edit-btn:hover { background: rgba(167,139,250,.24); }
.ch-edit-form { margin-top: 16px; padding-top: 16px; border-top: 1px solid #27272a; }
.ch-edit-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
.ch-edit-field { display: flex; flex-direction: column; gap: 5px; }
.ch-edit-field label { color: #a1a1aa; font-size: 12px; font-weight: 600; }
.ch-edit-field input { background: #0f0f11; border: 1px solid #3f3f46; border-radius: 8px; color: #f3f3f5; padding: 8px 10px; font-size: 14px; }
.ch-edit-field input:focus { outline: none; border-color: #a78bfa; }
.ch-edit-hint { color: #71717a; font-size: 11px; }
.ch-edit-actions { display: flex; gap: 8px; margin-top: 14px; }
.ch-exp-save { background: #22c55e; border: none; color: #062c14; font-weight: 700; border-radius: 8px; padding: 8px 18px; font-size: 13px; cursor: pointer; }
.ch-exp-cancel { background: #27272a; border: 1px solid #3f3f46; color: #a1a1aa; border-radius: 8px; padding: 8px 18px; font-size: 13px; cursor: pointer; }
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

<script>
function chToggleEdit(open) {
    document.getElementById('ch-edit-form').style.display = open ? 'block' : 'none';
}
</script>
@endsection

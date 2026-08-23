{{-- Строка брони в детальной карточке клиента. Ждём во входных данных $b. --}}
<div class="cd-row">
    <div class="cd-row-main">
        <div class="cd-row-t">
            {{ $b->date ? $b->date->format('d.m.Y') : '—' }},
            {{ \Carbon\Carbon::parse($b->start_time)->format('H:i') }}–{{ \Carbon\Carbon::parse($b->end_time)->format('H:i') }}
        </div>
        <div class="cd-row-s">
            {{ $b->court?->name ?? 'Корт' }}
            @if($b->coach) · тренер {{ $b->coach->first_name ?? $b->coach->name }} @endif
        </div>
    </div>
    <div class="cd-row-right">
        <span class="cd-bal">{{ number_format((float) $b->price, 0, '', ' ') }} ₸</span>
        @unless($b->is_paid)
            <span class="cd-paid no">не оплачено</span>
        @endunless
    </div>
</div>

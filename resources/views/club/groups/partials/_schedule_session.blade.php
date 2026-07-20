@php
    use Illuminate\Support\Carbon;
    $months = $months ?? [1=>'янв',2=>'фев',3=>'мар',4=>'апр',5=>'мая',6=>'июн',7=>'июл',8=>'авг',9=>'сен',10=>'окт',11=>'ноя',12=>'дек'];
    $weekdays = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
    $d = $s->date instanceof Carbon ? $s->date : Carbon::parse($s->date);
    $wd = $weekdays[$d->dayOfWeekIso - 1] ?? '';
    $t = substr((string) $s->start_time, 0, 5) . ' – ' . substr((string) $s->end_time, 0, 5);

    $statusMap = [
        'held'      => ['pill-held', 'Проведено'],
        'planned'   => ['pill-planned', 'Запланировано'],
        'cancelled' => ['pill-cancelled', 'Отменено'],
    ];
    [$pillCls, $pillTxt] = $statusMap[$s->status] ?? ['pill-planned', $s->status];

    // Пробные гости (без членства в группе).
    $guests = $s->attendance->filter(fn($a) => $a->group_member_id === null && $a->client_id !== null);

    // Строки участников + точки-сводка.
    $rows = [];
    foreach ($activeMembers as $m) {
        $frozen = $isFrozenOn($m, $d);
        $name = optional($m->client)->name ?? '—';
        if ($s->status === 'held') {
            $att = $s->attendance->firstWhere('group_member_id', $m->id);
            $came = $att && $att->attended;
            if ($came) {
                if ($att->is_trial)    { $tagCls = 'tag-trial';   $tagTxt = 'пробное'; }
                elseif ($att->charged) { $tagCls = 'tag-charged'; $tagTxt = 'списано'; }
                elseif ($frozen)       { $tagCls = 'tag-frozen';  $tagTxt = 'заморозка'; }
                else                   { $tagCls = 'tag-free';    $tagTxt = 'бесплатно'; }
            } else { $tagCls = 'tag-absent'; $tagTxt = 'не был'; }
            $dotDetail = $came ? 'dot-came' : 'dot-absent';
            $dotSum = $frozen ? 'gd-frozen' : ($came ? 'gd-came' : 'gd-absent');
        } else {
            $tagCls = $frozen ? 'tag-frozen' : 'tag-plain';
            $tagTxt = $frozen ? 'заморозка' : 'в группе';
            $dotDetail = $frozen ? 'dot-frozen' : 'dot-planned';
            $dotSum = $frozen ? 'gd-frozen' : 'gd-plan';
        }
        $rows[] = compact('name', 'tagCls', 'tagTxt', 'dotDetail', 'dotSum');
    }
    $collapsible = $s->status !== 'cancelled';
@endphp

<div class="gsch-card">
    @if($collapsible)
        <button type="button" class="gsch-head-btn" onclick="gschToggle(this)">
    @endif
        <div class="gsch-card-top">
            <div class="gsch-date">
                <div class="d">{{ $d->format('d') }}</div>
                <div class="m">{{ $months[(int) $d->format('n')] }}</div>
            </div>
            <div class="gsch-meta">
                <div class="r1">{{ $wd }}, {{ $t }}</div>
                <div class="r2">
                    {{ optional($s->court)->name ?? 'Корт' }}@if($s->coach) · {{ $s->coach->full_name }}@endif
                </div>
            </div>
            <span class="gsch-pill {{ $pillCls }}">{{ $pillTxt }}</span>
            @if($collapsible)<i class="bi bi-chevron-down gsch-chev"></i>@endif
        </div>

        @if($collapsible && (count($rows) || $guests->count()))
            <div class="gsch-dots">
                @foreach($rows as $r)<span class="gsch-d {{ $r['dotSum'] }}"></span>@endforeach
                @foreach($guests as $g)<span class="gsch-d gd-trial"></span>@endforeach
            </div>
        @endif
    @if($collapsible)
        </button>
    @endif

    @if($collapsible)
        <div class="gsch-members" style="display:none;">
            @foreach($rows as $r)
                <div class="gsch-mrow">
                    <span class="gsch-dot {{ $r['dotDetail'] }}"></span>
                    <span class="gsch-mname">{{ $r['name'] }}</span>
                    <span class="gsch-mtag {{ $r['tagCls'] }}">{{ $r['tagTxt'] }}</span>
                </div>
            @endforeach

            @foreach($guests as $g)
                <div class="gsch-mrow">
                    <span class="gsch-dot dot-came"></span>
                    <span class="gsch-mname">{{ optional($g->client)->name ?? 'Гость' }}</span>
                    <span class="gsch-mtag tag-trial">пробный@if($g->trial_amount) · {{ number_format($g->trial_amount, 0, '', ' ') }} ₸@endif</span>
                </div>
            @endforeach
        </div>
    @endif
</div>

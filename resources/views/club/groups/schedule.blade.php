@extends('layouts.app')
@section('title', 'Расписание — ' . $group->name)

@push('styles')
<style>
    .gsch-wrap { max-width: 1000px; margin: 0 auto; padding: 8px 4px 40px; }

    /* Шапка */
    .gsch-head { display: flex; align-items: center; gap: 14px; margin: 8px 0 20px; }
    .gsch-back {
        width: 40px; height: 40px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
        border-radius: 12px; background: #15181A; border: 1px solid rgba(255,255,255,0.07);
        color: #cfd3d6; font-size: 18px; text-decoration: none; transition: .15s;
    }
    .gsch-back:hover { border-color: #2f3439; color: #fff; }
    .gsch-title { font-size: 22px; font-weight: 800; color: #f4f6f7; line-height: 1.1; }
    .gsch-sub { font-size: 13px; color: #8b9298; margin-top: 3px; }

    /* Сводка */
    .gsch-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 18px; }
    .gsch-stat { background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; padding: 14px 16px; }
    .gsch-stat .v { font-size: 22px; font-weight: 800; color: #f4f6f7; line-height: 1; }
    .gsch-stat .l { font-size: 11px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: #6b7278; margin-top: 6px; }
    .gsch-stat.green .v { color: #22c55e; }

    /* Секции */
    .gsch-sec-title { font-size: 12px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #6b7278; margin: 22px 0 10px 4px; }

    /* Карточка занятия */
    .gsch-card { background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; padding: 0; margin-bottom: 12px; overflow: hidden; }
    .gsch-card-top { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,0.05); }
    .gsch-date { flex-shrink: 0; text-align: center; width: 52px; }
    .gsch-date .d { font-size: 22px; font-weight: 800; color: #f4f6f7; line-height: 1; }
    .gsch-date .m { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #7c848a; margin-top: 3px; }
    .gsch-meta { flex: 1; min-width: 0; }
    .gsch-meta .r1 { font-size: 15px; font-weight: 700; color: #eef1f2; }
    .gsch-meta .r2 { font-size: 12.5px; color: #8b9298; margin-top: 3px; }
    .gsch-pill { flex-shrink: 0; font-size: 11px; font-weight: 800; letter-spacing: .4px; text-transform: uppercase; padding: 5px 11px; border-radius: 999px; }
    .pill-held { background: rgba(34,197,94,0.14); color: #34d17f; }
    .pill-planned { background: rgba(77,143,240,0.14); color: #6aa4f5; }
    .pill-cancelled { background: rgba(229,86,78,0.14); color: #ef7a73; }

    /* Участники в карточке */
    .gsch-members { padding: 6px 16px 14px; display: flex; flex-direction: column; gap: 2px; }
    .gsch-mrow { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.035); }
    .gsch-mrow:last-child { border-bottom: none; }
    .gsch-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .dot-came { background: #22c55e; }
    .dot-absent { background: #4b5157; }
    .dot-frozen { background: #eab34e; }
    .dot-planned { background: #4d8ff0; }
    .dot-rem-ok { background: #22c55e; }
    .dot-rem-low { background: #eab308; }
    .dot-rem-zero { background: #e5564e; }
    .gsch-mname { flex: 1; min-width: 0; font-size: 14px; color: #e6e9eb; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .gsch-mtag { flex-shrink: 0; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 8px; }
    .tag-charged { background: rgba(34,197,94,0.13); color: #34d17f; }
    .tag-free { background: rgba(77,143,240,0.13); color: #6aa4f5; }
    .tag-frozen { background: rgba(234,179,78,0.13); color: #edbf63; }
    .tag-absent { background: rgba(255,255,255,0.05); color: #7c848a; }
    .tag-rem-ok { background: rgba(34,197,94,0.13); color: #34d17f; }
    .tag-rem-low { background: rgba(234,179,8,0.15); color: #eab308; }
    .tag-rem-zero { background: rgba(229,86,78,0.14); color: #ef7a73; }
    .tag-trial { background: rgba(168,139,250,0.15); color: #b9a3fb; }
    .tag-plain { background: rgba(255,255,255,0.05); color: #9aa1a7; }

    .gsch-empty { text-align: center; color: #6b7278; font-size: 14px; padding: 30px 0; }

    /* Сворачиваемая карточка + точки-сводка */
    .gsch-head-btn { display: block; width: 100%; text-align: left; background: none; border: none; padding: 0; cursor: pointer; }
    .gsch-card-top { border-bottom: none; }
    .gsch-chev { flex-shrink: 0; color: #5d646a; font-size: 13px; transition: transform .2s; margin-left: 2px; }
    .gsch-card.open .gsch-chev { transform: rotate(180deg); }
    .gsch-dots { display: flex; flex-wrap: wrap; gap: 5px; padding: 0 16px 14px 82px; }
    .gsch-d { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
    .gd-came { background: #22c55e; }
    .gd-absent { background: #e5564e; }
    .gd-frozen { background: #4d8ff0; }
    .gd-plan { background: #4b5157; }
    .gd-trial { background: #a88bfa; }
    .gsch-card.open .gsch-dots { display: none; }
    .gsch-card.open .gsch-card-top { border-bottom: 1px solid rgba(255,255,255,0.05); }

    /* Легенда */
    .gsch-legend { display: flex; flex-wrap: wrap; gap: 14px; padding: 12px 16px; background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; margin-bottom: 6px; }
    .gsch-leg { display: flex; align-items: center; gap: 7px; font-size: 12px; color: #9aa1a7; }

    @media (max-width: 560px) {
        .gsch-stats { grid-template-columns: repeat(2, 1fr); }
        .gsch-dots { padding-left: 16px; }
    }
</style>
@endpush

@section('content')
@php
    use Illuminate\Support\Carbon;
    $today = Carbon::today();
    $months = [1=>'янв',2=>'фев',3=>'мар',4=>'апр',5=>'мая',6=>'июн',7=>'июл',8=>'авг',9=>'сен',10=>'окт',11=>'ноя',12=>'дек'];
    $activeMembers = $group->members;

    // $sessions уже desc (дата/время) — предстоящие показываем по возрастанию.
    $upcoming = $sessions->where('status', 'planned')
        ->sortBy(fn($s) => ($s->date instanceof \Carbon\Carbon ? $s->date->format('Y-m-d') : (string) $s->date) . ' ' . $s->start_time);
    $history  = $sessions->whereIn('status', ['held','cancelled']);

    $heldCount = $sessions->where('status','held')->count();
    $planCount = $sessions->where('status','planned')->count();
    $cancCount = $sessions->where('status','cancelled')->count();

    // Заморожен ли участник на дату занятия.
    $isFrozenOn = function ($member, $date) {
        return $member->freezes->contains(fn($f) => $f->freeze_from->lte($date) && $f->freeze_until->gte($date));
    };
    $plZan = function ($n) {
        $n = abs((int) $n); $d100 = $n % 100; $d10 = $n % 10;
        if ($d100 >= 11 && $d100 <= 14) return 'занятий';
        if ($d10 === 1) return 'занятие';
        if ($d10 >= 2 && $d10 <= 4) return 'занятия';
        return 'занятий';
    };
@endphp

<div class="gsch-wrap">
    <div class="gsch-head">
        <a href="{{ route('club.groups.show', $group) }}" class="gsch-back">&#8592;</a>
        <div>
            <div class="gsch-title">{{ $group->name }}</div>
            <div class="gsch-sub">
                Расписание группы@if($group->coach) · тренер {{ $group->coach->full_name }}@endif
            </div>
        </div>
    </div>

    <div class="gsch-stats">
        <div class="gsch-stat green"><div class="v">{{ $heldCount }}</div><div class="l">Проведено</div></div>
        <div class="gsch-stat"><div class="v">{{ $planCount }}</div><div class="l">Впереди</div></div>
        <div class="gsch-stat"><div class="v">{{ $cancCount }}</div><div class="l">Отменено</div></div>
        <div class="gsch-stat"><div class="v">{{ $activeMembers->count() }}</div><div class="l">Участников</div></div>
    </div>

    {{-- Остаток занятий у участников --}}
    @if($activeMembers->isNotEmpty())
        <div class="gsch-card">
            <div class="gsch-card-top" style="border-bottom:none;">
                <div class="gsch-meta"><div class="r1">Участники и остаток пакета</div></div>
            </div>
            <div class="gsch-members" style="padding-top:0;">
                @foreach($activeMembers as $m)
                    @php
                        $bought = (int) $m->enrollments->sum('sessions');
                        $used = (int) $m->attendance->where('charged', true)->count();
                        $rem = $bought - $used;
                        $frozenNow = $isFrozenOn($m, $today);
                    @endphp
                    <div class="gsch-mrow">
                        <span class="gsch-dot {{ $rem <= 0 ? 'dot-rem-zero' : ($rem <= 2 ? 'dot-rem-low' : 'dot-rem-ok') }}"></span>
                        <span class="gsch-mname">{{ optional($m->client)->name ?? '—' }}</span>
                        @if($frozenNow)<span class="gsch-mtag tag-frozen">заморозка</span>@endif
                        <span class="gsch-mtag {{ $rem <= 0 ? 'tag-rem-zero' : ($rem <= 2 ? 'tag-rem-low' : 'tag-rem-ok') }}">{{ $rem }} {{ $plZan($rem) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Легенда точек --}}
    <div class="gsch-sec-title" style="margin-top:26px;">Занятия</div>
    <div class="gsch-legend">
        <span class="gsch-leg"><span class="gsch-d gd-came"></span> был</span>
        <span class="gsch-leg"><span class="gsch-d gd-absent"></span> не был</span>
        <span class="gsch-leg"><span class="gsch-d gd-frozen"></span> заморозка</span>
        <span class="gsch-leg"><span class="gsch-d gd-trial"></span> пробный</span>
        <span class="gsch-leg"><span class="gsch-d gd-plan"></span> запланировано</span>
    </div>

    {{-- Предстоящие --}}
    <div class="gsch-sec-title">Предстоящие</div>
    @if($upcoming->isEmpty())
        <div class="gsch-card"><div class="gsch-empty">Запланированных занятий нет</div></div>
    @else
        @foreach($upcoming as $s)
            @include('club.groups.partials._schedule_session', ['s' => $s, 'activeMembers' => $activeMembers, 'isFrozenOn' => $isFrozenOn, 'months' => $months])
        @endforeach
    @endif

    {{-- Прошедшие --}}
    <div class="gsch-sec-title">Прошедшие</div>
    @if($history->isEmpty())
        <div class="gsch-card"><div class="gsch-empty">Проведённых занятий пока нет</div></div>
    @else
        @foreach($history as $s)
            @include('club.groups.partials._schedule_session', ['s' => $s, 'activeMembers' => $activeMembers, 'isFrozenOn' => $isFrozenOn, 'months' => $months])
        @endforeach
    @endif
</div>

<script>
    function gschToggle(btn) {
        const card = btn.closest('.gsch-card');
        if (!card) return;
        card.classList.toggle('open');
        const body = card.querySelector('.gsch-members');
        if (body) body.style.display = card.classList.contains('open') ? '' : 'none';
    }
</script>
@endsection

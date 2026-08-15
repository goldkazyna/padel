@extends('layouts.app')
@section('title', $group->name)

@push('styles')
<style>
    .gsch-wrap { max-width: 1000px; margin: 0 auto; padding: 8px 4px 40px; }

    .field-hint { font-size: 11.5px; color: var(--text-muted); line-height: 1.45; margin-top: 5px; }
    /* Выбор вида группы: абонемент или пробная */
    .gtype-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .gtype { cursor: pointer; }
    .gtype input { position: absolute; opacity: 0; pointer-events: none; }
    .gtype-box {
        display: flex; flex-direction: column; gap: 4px; height: 100%;
        border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px;
        background: var(--bg-card); transition: .15s;
    }
    .gtype:hover .gtype-box { border-color: var(--border-light); }
    .gtype input:checked + .gtype-box { border-color: var(--accent); background: var(--accent-glow); }
    .gtype-name { font-size: 13.5px; font-weight: 700; color: var(--text-primary); }
    .gtype input:checked + .gtype-box .gtype-name { color: var(--accent); }
    .gtype-hint { font-size: 11.5px; color: var(--text-muted); line-height: 1.4; }
    .gsch-type { font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 7px;
        background: rgba(168,85,247,.16); color: #c084fc; }

    /* Шапка */
    .gsch-head { display: flex; align-items: center; gap: 14px; margin: 8px 0 20px; }
    .gsch-back { width: 40px; height: 40px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 12px; background: #15181A; border: 1px solid rgba(255,255,255,0.07); color: #cfd3d6; font-size: 18px; text-decoration: none; transition: .15s; }
    .gsch-back:hover { border-color: #2f3439; color: #fff; }
    .gsch-title { font-size: 22px; font-weight: 800; color: #f4f6f7; line-height: 1.1; }
    .gsch-sub { font-size: 13px; color: #8b9298; margin-top: 3px; display: flex; align-items: center; flex-wrap: wrap; gap: 5px; }
    .gsch-sub .meta-price { color: #34d17f; font-weight: 600; }

    .badge-active { display: inline-flex; align-items: center; padding: 3px 10px; background: rgba(34,197,94,0.14); color: #34d17f; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: .3px; }
    .badge-archived { display: inline-flex; align-items: center; padding: 3px 10px; background: rgba(139,146,152,0.14); color: #8b9298; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: .3px; }

    .gs-head-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto; }
    .gs-mbtn { display: inline-flex; align-items: center; gap: 7px; background: #15181A; color: #cfd3d6; border: 1px solid rgba(255,255,255,0.08); padding: 9px 14px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; transition: .15s; }
    .gs-mbtn.edit:hover { border-color: #4d8ff0; color: #6aa4f5; }
    .gs-mbtn.arch:hover { border-color: #eab34e; color: #edbf63; }
    .gs-mbtn.unarch:hover { border-color: #22c55e; color: #34d17f; }
    .gs-mbtn.del:hover { border-color: #e5564e; color: #ef7a73; }

    .flash-message { padding: 13px 18px; border-radius: 12px; font-size: 14px; font-weight: 600; margin-bottom: 16px; }
    .flash-success { background: rgba(34,197,94,0.14); color: #34d17f; }
    .flash-error { background: rgba(229,86,78,0.14); color: #ef7a73; }
    .note-card { display: flex; align-items: flex-start; gap: 12px; background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 14px 18px; margin-bottom: 16px; }
    .note-icon { font-size: 17px; color: #34d17f; flex-shrink: 0; }
    .note-text { font-size: 14px; color: #9aa1a7; font-weight: 500; line-height: 1.5; }

    /* Сводка */
    .gsch-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 18px; }
    .gsch-stat { background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; padding: 14px 16px; }
    .gsch-stat .v { font-size: 22px; font-weight: 800; color: #f4f6f7; line-height: 1; }
    .gsch-stat .l { font-size: 11px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: #6b7278; margin-top: 6px; }
    .gsch-stat.green .v { color: #22c55e; }

    .gsch-sec-title { font-size: 12px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #6b7278; margin: 22px 0 10px 4px; }

    /* Карточка занятия */
    .gsch-card { background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; padding: 0; margin-bottom: 12px; overflow: hidden; }
    .gsch-card-top { display: flex; align-items: center; gap: 14px; padding: 14px 16px; }
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
    .gsch-mrow { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px solid rgba(255,255,255,0.035); }
    .gsch-mrow:last-child { border-bottom: none; }
    .gsch-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .dot-came { background: #22c55e; }
    .dot-absent { background: #4b5157; }
    .dot-frozen { background: #eab34e; }
    .dot-planned { background: #4d8ff0; }
    .dot-rem-ok { background: #22c55e; }   /* 3+ занятий */
    .dot-rem-low { background: #eab308; }  /* 1–2 занятия */
    .dot-rem-zero { background: #e5564e; } /* закончилось */
    .gsch-mname { flex: 1; min-width: 0; font-size: 14px; color: #e6e9eb; display: flex; flex-direction: column; gap: 2px; }
    .gsch-mname .msub { font-size: 11.5px; }
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

    /* Кнопки действий участника */
    .mrow-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .action-btn { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; cursor: pointer; color: #9aa1a7; font-size: 13px; transition: all 0.15s; font-weight: 700; }
    .action-renew:hover { border-color: #22c55e; color: #34d17f; }
    .action-remove:hover { border-color: #e5564e; color: #ef7a73; }
    .action-freeze:hover { border-color: #4d8ff0; color: #6aa4f5; }
    .action-edit:hover { border-color: #eab34e; color: #edbf63; }
    .mrow-chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 0 8px 18px; }
    .freeze-chip { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; color: #93c5fd; background: rgba(56,189,248,.08); border: 1px solid rgba(56,189,248,.25); border-radius: 999px; padding: 2px 4px 2px 10px; }
    .freeze-chip-x { background: none; border: none; color: #71717a; cursor: pointer; font-size: 11px; padding: 0 4px; }
    .freeze-chip-x:hover { color: #ef4444; }

    .btn-add-small { background: rgba(34,197,94,0.14); color: #34d17f; border: 1px solid rgba(34,197,94,0.30); padding: 7px 13px; border-radius: 9px; font-size: 12.5px; font-weight: 700; cursor: pointer; }
    .btn-add-small:hover { background: rgba(34,197,94,0.22); }

    .gsch-empty { text-align: center; color: #6b7278; font-size: 14px; padding: 24px 0; }

    /* Сворачиваемая карточка + точки-сводка */
    .gsch-head-btn { display: block; width: 100%; text-align: left; background: none; border: none; padding: 0; cursor: pointer; }
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

    .gsch-legend { display: flex; flex-wrap: wrap; gap: 14px; padding: 12px 16px; background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; margin-bottom: 6px; }
    .gsch-leg { display: flex; align-items: center; gap: 7px; font-size: 12px; color: #9aa1a7; }

    /* Модалки (тёмная тема) */
    .modal-card { background: #15181A; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; margin: 20px; }
    .modal-header-row { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px; border-bottom: 1px solid rgba(255,255,255,0.06); }
    .modal-title-text { font-size: 17px; font-weight: 700; color: #f4f6f7; margin: 0; }
    .modal-close-btn { background: none; border: none; color: #71717a; font-size: 16px; cursor: pointer; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; }
    .modal-close-btn:hover { color: #ef4444; }
    .modal-body-area { padding: 22px; }
    .modal-footer-row { display: flex; gap: 12px; padding: 18px 22px; border-top: 1px solid rgba(255,255,255,0.06); }
    .form-group { margin-bottom: 18px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #9aa1a7; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f6f7; font-weight: 500; font-family: inherit; box-sizing: border-box; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-input::placeholder { color: #52525b; }
    .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .form-check-row { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
    .form-check-box { width: 18px; height: 18px; accent-color: #22c55e; cursor: pointer; }
    .form-check-label { font-size: 14px; font-weight: 600; color: #a1a1aa; cursor: pointer; }
    .btn-cancel { flex: 1; padding: 14px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }
    .pm-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
    .pm-chip { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 8px 4px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; color: #a1a1aa; font-size: 10.5px; line-height: 1.15; text-align: center; cursor: pointer; transition: border-color .15s, color .15s, background .15s; }
    .pm-chip i { font-size: 15px; }
    .pm-chip:hover { border-color: #3f3f46; color: #d4d4d8; }
    .pm-chip.active { border-color: #22c55e; color: #22c55e; background: rgba(34,197,94,0.08); }

    @media (max-width: 560px) {
        .gsch-stats { grid-template-columns: repeat(2, 1fr); }
        .gsch-dots { padding-left: 16px; }
        .form-row-2 { grid-template-columns: 1fr; }
    }
</style>
@endpush

@section('content')
@php
    use Illuminate\Support\Carbon;
    $today = Carbon::today();
    $months = [1=>'янв',2=>'фев',3=>'мар',4=>'апр',5=>'мая',6=>'июн',7=>'июл',8=>'авг',9=>'сен',10=>'окт',11=>'ноя',12=>'дек'];
    $activeMembers = $group->members->where('status', 'active');

    $upcoming = $sessions->where('status', 'planned')
        ->sortBy(fn($s) => ($s->date instanceof Carbon ? $s->date->format('Y-m-d') : (string) $s->date) . ' ' . $s->start_time);
    $history  = $sessions->whereIn('status', ['held','cancelled']);

    $heldCount = $sessions->where('status','held')->count();
    $planCount = $sessions->where('status','planned')->count();
    $cancCount = $sessions->where('status','cancelled')->count();

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
    {{-- Шапка + управление --}}
    <div class="gsch-head">
        <a href="{{ route('club.groups.index') }}" class="gsch-back" title="Назад"
           onclick="if (window.history.length > 1) { window.history.back(); return false; } return true;">&#8592;</a>
        <div style="min-width:0;">
            <div class="gsch-title">{{ $group->name }}</div>
            <div class="gsch-sub">
                @if($group->isTrial())<span class="gsch-type">Пробная</span> · @endif
                @if($group->coach)<span>тренер {{ $group->coach->full_name }}</span> · @endif
                @if($group->price_per_session > 0)<span class="meta-price">{{ number_format($group->price_per_session, 0, '.', ' ') }} ₸/занятие</span> · @endif
                @if($group->status === 'active')<span class="badge-active">Активна</span>@else<span class="badge-archived">Архив</span>@endif
            </div>
        </div>
        <div class="gs-head-actions">
            <a href="{{ route('club.groups.journal', ['group' => $group->id]) }}" class="gs-mbtn"><i class="bi bi-clock-history"></i> Журнал</a>
            <button class="gs-mbtn edit" onclick="document.getElementById('editGroupModal').style.display='flex'">&#9998; Редактировать</button>
            @if($group->status === 'active')
                <form method="POST" action="{{ route('club.groups.archive', $group) }}" onsubmit="return confirm('Перенести «{{ $group->name }}» в архив? Будущие занятия будут отменены, корты освободятся. История сохранится.')" style="display:inline;">
                    @csrf
                    <button type="submit" class="gs-mbtn arch">&#128193; В архив</button>
                </form>
            @else
                <form method="POST" action="{{ route('club.groups.unarchive', $group) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="gs-mbtn unarch">&#8617; Вернуть</button>
                </form>
            @endif
            <form method="POST" action="{{ route('club.groups.destroy', $group) }}" onsubmit="return confirm('Удалить группу «{{ $group->name }}» и всю её историю? Будущие занятия будут отменены, корты освободятся.')" style="display:inline;">
                @csrf
                @method('DELETE')
                <button type="submit" class="gs-mbtn del">&#10005; Удалить</button>
            </form>
        </div>
    </div>

    @if(session('success'))<div class="flash-message flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash-message flash-error">{{ session('error') }}</div>@endif
    @if($errors->any())
        <div class="flash-message flash-error">@foreach($errors->all() as $err)<div>{{ $err }}</div>@endforeach</div>
    @endif

    @if($group->note)
        <div class="note-card"><span class="note-icon">&#8505;</span><span class="note-text">{{ $group->note }}</span></div>
    @endif

    {{-- Сводка --}}
    <div class="gsch-stats">
        <div class="gsch-stat green"><div class="v">{{ $heldCount }}</div><div class="l">Проведено</div></div>
        <div class="gsch-stat"><div class="v">{{ $planCount }}</div><div class="l">Впереди</div></div>
        <div class="gsch-stat"><div class="v">{{ $cancCount }}</div><div class="l">Отменено</div></div>
        <div class="gsch-stat"><div class="v">{{ $activeMembers->count() }}</div><div class="l">Участников</div></div>
    </div>

    {{-- Участники + управление --}}
    <div class="gsch-card">
        <div class="gsch-card-top" style="border-bottom:1px solid rgba(255,255,255,0.05);justify-content:space-between;">
            <div class="gsch-meta"><div class="r1">Участники и остаток пакета</div></div>
            <button class="btn-add-small" onclick="document.getElementById('addMemberModal').style.display='flex'">+ Добавить</button>
        </div>
        <div class="gsch-members" style="padding-top:2px;">
            @forelse($activeMembers as $m)
                @php
                    $bought = (int) $m->enrollments->sum('sessions');
                    $used = (int) $m->attendance->where('charged', true)->count();
                    $rem = $bought - $used;
                    $frozenNow = $isFrozenOn($m, $today);
                @endphp
                <div class="gsch-mrow">
                    <span class="gsch-dot {{ $rem <= 0 ? 'dot-rem-zero' : ($rem <= 2 ? 'dot-rem-low' : 'dot-rem-ok') }}"></span>
                    <span class="gsch-mname">
                        <span>{{ optional($m->client)->name ?? '—' }}</span>
                        @if($m->subscription_ends_at)
                            <span class="msub" style="color:{{ $m->subscription_ends_at->lt($today) ? '#ef7a73' : '#6b7278' }};">Абонемент до {{ $m->subscription_ends_at->format('d.m.Y') }}{{ $m->subscription_ends_at->lt($today) ? ' · истёк' : '' }}</span>
                        @endif
                        @if($m->note)
                            <span class="msub" style="color:#8b9298;"><i class="bi bi-chat-square-text" style="font-size:11px;"></i> {{ $m->note }}</span>
                        @endif
                    </span>
                    @if($frozenNow)<span class="gsch-mtag tag-frozen">заморозка</span>@endif
                    @if($m->starts_at && $m->starts_at->gt($today))<span class="gsch-mtag" style="background:rgba(106,164,245,.14);color:#6aa4f5;">начнёт {{ $m->starts_at->format('d.m') }}</span>@endif
                    <span class="gsch-mtag {{ $rem <= 0 ? 'tag-rem-zero' : ($rem <= 2 ? 'tag-rem-low' : 'tag-rem-ok') }}">{{ $rem }} {{ $plZan($rem) }}</span>
                    <div class="mrow-actions">
                        <button class="action-btn action-freeze" onclick="openFreezeModal({{ $m->id }})" title="Заморозить">❄</button>
                        <button class="action-btn action-renew" onclick="openEnrollModal({{ $m->id }})" title="Продлить">+</button>
                        <button class="action-btn action-edit" onclick="openEditMemberModal({{ $m->id }})" title="Абонемент">✎</button>
                        <form method="POST" action="{{ route('club.groups.members.destroy', [$group, $m]) }}" onsubmit="return removeMemberSubmit(this, {{ $rem }})" style="display:inline;">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="zero_balance" value="0">
                            <button type="submit" class="action-btn action-remove" title="Убрать">&#10005;</button>
                        </form>
                    </div>
                </div>
                @if($m->freezes->isNotEmpty())
                    <div class="mrow-chips">
                        @foreach($m->freezes->sortByDesc('freeze_from') as $f)
                            <span class="freeze-chip">
                                {{ $f->freeze_from->format('d.m.y') }}–{{ $f->freeze_until->format('d.m.y') }}@if($f->note) · {{ $f->note }}@endif
                                <form method="POST" action="{{ route('club.groups.members.unfreeze', [$group, $m, $f]) }}" onsubmit="return confirm('Снять заморозку?')" style="display:inline;">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="freeze-chip-x" title="Снять">&#10005;</button>
                                </form>
                            </span>
                        @endforeach
                    </div>
                @endif
            @empty
                <div class="gsch-empty">Участников пока нет</div>
            @endforelse
        </div>
    </div>

    {{-- Легенда --}}
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

<!-- Модал добавления участника -->
<div id="addMemberModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Добавить участника</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('addMemberModal').style.display='none'">&#10005;</button>
        </div>
        <form method="POST" action="{{ route('club.groups.members.store', $group) }}" onsubmit="return groupMemberValid()">
            @csrf
            <div class="modal-body-area">
                <div class="form-group" style="position:relative;">
                    <label class="form-label">Клиент <span style="color:#ef4444">*</span></label>
                    <input type="text" id="memberClientSearch" class="form-input" autocomplete="off"
                           placeholder="Поиск по имени или телефону…" oninput="searchGroupClients(this.value)">
                    <div id="memberClientResults"
                         style="position:absolute;left:0;right:0;top:100%;z-index:10;background:#16161a;border:1px solid #27272a;border-radius:10px;margin-top:4px;max-height:220px;overflow-y:auto;display:none;"></div>
                    <input type="hidden" name="client_id" id="memberClientId">
                    <div id="memberClientSelected"
                         style="display:none;align-items:center;justify-content:space-between;margin-top:8px;padding:10px 12px;background:#16161a;border:1px solid #22c55e;border-radius:10px;">
                        <span id="memberClientSelectedName" style="color:#22c55e;font-weight:700;font-size:14px;"></span>
                        <button type="button" onclick="clearGroupClient()"
                                style="background:none;border:none;color:#71717a;cursor:pointer;font-size:14px;">&#10005;</button>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Занятий в пакете <span style="color:#ef4444">*</span></label>
                        <input type="number" name="sessions" class="form-input" min="1" max="200" required
                               value="{{ old('sessions', 8) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Сумма (₸)</label>
                        <input type="number" name="amount" class="form-input" min="0" step="100"
                               value="{{ old('amount', $group->price_per_session * 8) }}">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Начинает ходить с</label>
                    <input type="date" name="starts_at" class="form-input" value="{{ old('starts_at') }}">
                    <small style="color:#71717a;font-size:12px;">Необязательно. До этой даты занимает место, но занятия не списываются.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Комментарий</label>
                    <textarea name="note" class="form-input" rows="2" placeholder="Заметка по участнику (необязательно)">{{ old('note') }}</textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Дата окончания абонемента</label>
                    <input type="date" name="subscription_ends_at" class="form-input" value="{{ old('subscription_ends_at') }}">
                    <small style="color:#71717a;font-size:12px;">Необязательно</small>
                </div>
                @php
                    $pmOptions = [
                        ['cash', 'Наличные', 'bi-cash-stack'],
                        ['card', 'Карта', 'bi-credit-card-2-front'],
                        ['kaspi', 'Kaspi', 'bi-qr-code'],
                        ['certificate', 'Сертификат', 'bi-award'],
                        ['club_card', 'Клубная карта', 'bi-person-vcard'],
                        ['deposit', 'Депозит', 'bi-wallet2'],
                        ['cashback', 'Кешбэк', 'bi-arrow-repeat'],
                        ['cashless', 'Безналичный', 'bi-bank'],
                        ['free', 'Бесплатно', 'bi-gift'],
                    ];
                @endphp
                <div class="form-group">
                    <label class="form-label">Способ оплаты</label>
                    <input type="hidden" name="payment_method" id="addPayMethod" value="">
                    <div class="pm-grid" id="addPayGrid">
                        @foreach($pmOptions as [$pv, $pl, $pi])
                        <button type="button" class="pm-chip" data-v="{{ $pv }}" onclick="pmPick('add', this)">
                            <i class="bi {{ $pi }}"></i><span>{{ $pl }}</span>
                        </button>
                        @endforeach
                    </div>
                </div>
                <div class="form-check-row">
                    <input type="checkbox" name="is_paid" value="1" id="addIsPaid" class="form-check-box" checked>
                    <label class="form-check-label" for="addIsPaid">Оплачено</label>
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('addMemberModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Добавить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модал продления пакета -->
<div id="enrollModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Добавить пакет занятий</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('enrollModal').style.display='none'">&#10005;</button>
        </div>
        <form id="enrollForm" method="POST" action="">
            @csrf
            <div class="modal-body-area">
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Занятий <span style="color:#ef4444">*</span></label>
                        <input type="number" name="sessions" class="form-input" min="1" max="200" required value="8">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Сумма (₸)</label>
                        <input type="number" name="amount" class="form-input" min="0" step="100" value="0">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Способ оплаты</label>
                    <input type="hidden" name="payment_method" id="enrollPayMethod" value="">
                    <div class="pm-grid" id="enrollPayGrid">
                        @foreach($pmOptions as [$pv, $pl, $pi])
                        <button type="button" class="pm-chip" data-v="{{ $pv }}" onclick="pmPick('enroll', this)">
                            <i class="bi {{ $pi }}"></i><span>{{ $pl }}</span>
                        </button>
                        @endforeach
                    </div>
                </div>
                <div class="form-check-row">
                    <input type="checkbox" name="is_paid" value="1" id="enrollIsPaid" class="form-check-box" checked>
                    <label class="form-check-label" for="enrollIsPaid">Оплачено</label>
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('enrollModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модал заморозки участника -->
<div id="freezeModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Заморозить участника</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('freezeModal').style.display='none'">&#10005;</button>
        </div>
        <form id="freezeForm" method="POST" action="">
            @csrf
            <div class="modal-body-area">
                <p style="color:#a1a1aa;font-size:13px;margin:0 0 12px;">В период заморозки занятия участнику не списываются.</p>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">С <span style="color:#ef4444">*</span></label>
                        <input type="date" name="freeze_from" id="freezeFrom" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">По <span style="color:#ef4444">*</span></label>
                        <input type="date" name="freeze_until" id="freezeUntil" class="form-input" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Заметка</label>
                    <input type="text" name="note" class="form-input" maxlength="255" placeholder="Напр.: отпуск, травма">
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('freezeModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Заморозить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модал редактирования абонемента участника -->
<div id="editMemberModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Абонемент участника</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('editMemberModal').style.display='none'">&#10005;</button>
        </div>
        <form id="editMemberForm" method="POST" action="">
            @csrf
            @method('PUT')
            <div class="modal-body-area">
                <div class="form-group">
                    <label class="form-label">Начинает ходить с</label>
                    <input type="date" name="starts_at" id="editMemberStartsAt" class="form-input">
                    <small style="color:#71717a;font-size:12px;display:block;margin-bottom:14px;">До этой даты занятия не списываются.</small>
                    <label class="form-label">Дата окончания абонемента</label>
                    <input type="date" name="subscription_ends_at" id="editMemberEndsAt" class="form-input">
                    <label class="form-label" style="margin-top:14px;">Комментарий</label>
                    <textarea name="note" id="editMemberNote" class="form-input" rows="2" placeholder="Заметка по участнику"></textarea>
                    <small style="color:#71717a;font-size:12px;">Необязательно. Оставьте пустым, чтобы убрать дату.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Способ оплаты</label>
                    <input type="hidden" name="payment_method" id="editMemberPayMethod" value="">
                    <div class="pm-grid" id="editMemberPayGrid">
                        @foreach($pmOptions as [$pv, $pl, $pi])
                        <button type="button" class="pm-chip" data-v="{{ $pv }}" onclick="pmPick('editMember', this)">
                            <i class="bi {{ $pi }}"></i><span>{{ $pl }}</span>
                        </button>
                        @endforeach
                    </div>
                    <small style="color:#71717a;font-size:12px;">Метод последнего пакета участника.</small>
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('editMemberModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модал редактирования группы -->
<div id="editGroupModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Редактировать группу</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('editGroupModal').style.display='none'">&#10005;</button>
        </div>
        <form method="POST" action="{{ route('club.groups.update', $group) }}">
            @csrf
            @method('PUT')
            <div class="modal-body-area">
                <div class="form-group">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" name="name" class="form-input" required value="{{ old('name', $group->name) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Вид группы</label>
                    <div class="gtype-row">
                        @foreach(\App\Models\ClubGroup::types() as $value => $label)
                            <label class="gtype">
                                <input type="radio" name="type" value="{{ $value }}"
                                       {{ old('type', $group->type) === $value ? 'checked' : '' }}>
                                <span class="gtype-box">
                                    <span class="gtype-name">{{ $label }}</span>
                                    <span class="gtype-hint">
                                        {{ $value === \App\Models\ClubGroup::TYPE_TRIAL
                                            ? 'Пришли разово попробовать, платят за посещение'
                                            : 'Ходят постоянно, занятия списываются с пакета' }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Тренер</label>
                    <select name="coach_id" class="form-input">
                        <option value="">— без тренера —</option>
                        @foreach($coaches as $coach)
                            <option value="{{ $coach->user_id }}" {{ old('coach_id', $group->coach_id) == $coach->user_id ? 'selected' : '' }}>
                                {{ $coach->user->name ?? 'Тренер #'.$coach->user_id }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Цена занятия для клиента (₸)</label>
                        <input type="number" name="price_per_session" class="form-input" min="0" step="1" value="{{ old('price_per_session', $group->price_per_session) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Макс. участников</label>
                        <input type="number" name="capacity" class="form-input" min="1" max="100" value="{{ old('capacity', $group->capacity) }}" placeholder="Не ограничено">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Цена тренеру за клиента (₸)</label>
                    <input type="number" name="coach_price_per_client" class="form-input" min="0" step="1"
                           placeholder="Не задана"
                           value="{{ old('coach_price_per_client', $group->coach_price_per_client) }}">
                    <div class="field-hint">
                        Сколько тренер получит за каждого пришедшего. Пусто — платим по его
                        часовой групповой ставке, как раньше.
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Статус</label>
                    <select name="status" class="form-input">
                        <option value="active" {{ old('status', $group->status) === 'active' ? 'selected' : '' }}>Активна</option>
                        <option value="archived" {{ old('status', $group->status) === 'archived' ? 'selected' : '' }}>Архив</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Заметка</label>
                    <textarea name="note" class="form-input" rows="3" placeholder="Дополнительная информация о группе...">{{ old('note', $group->note) }}</textarea>
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('editGroupModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
    var enrollRoutes = {
        @foreach($activeMembers as $member)
        {{ $member->id }}: "{{ route('club.groups.members.enroll', [$group, $member]) }}",
        @endforeach
    };
    var freezeRoutes = {
        @foreach($activeMembers as $member)
        {{ $member->id }}: "{{ route('club.groups.members.freeze', [$group, $member]) }}",
        @endforeach
    };
    function openFreezeModal(memberId) {
        document.getElementById('freezeForm').action = freezeRoutes[memberId] || '';
        document.getElementById('freezeModal').style.display = 'flex';
    }
    // Удаление участника: сначала подтверждаем удаление, затем — если есть
    // остаток пакета — спрашиваем, обнулить ли его. Ответ уходит в zero_balance.
    function removeMemberSubmit(form, rem) {
        if (!confirm('Убрать участника из группы?')) return false;
        var zero = form.querySelector('input[name="zero_balance"]');
        if (rem > 0) {
            zero.value = confirm('У участника осталось ' + rem + ' зан. по пакету.\n\nОК — обнулить остаток\nОтмена — оставить остаток') ? '1' : '0';
        } else {
            zero.value = '0';
        }
        return true;
    }
    function openEnrollModal(memberId) {
        document.getElementById('enrollForm').action = enrollRoutes[memberId] || '';
        pmReset('enroll');
        document.getElementById('enrollModal').style.display = 'flex';
    }

    function pmPick(prefix, el) {
        var grid = document.getElementById(prefix + 'PayGrid');
        if (grid) grid.querySelectorAll('.pm-chip').forEach(function (b) { b.classList.remove('active'); });
        el.classList.add('active');
        var inp = document.getElementById(prefix + 'PayMethod');
        if (inp) inp.value = el.getAttribute('data-v');
    }
    function pmReset(prefix) {
        var grid = document.getElementById(prefix + 'PayGrid');
        if (grid) grid.querySelectorAll('.pm-chip').forEach(function (b) { b.classList.remove('active'); });
        var inp = document.getElementById(prefix + 'PayMethod');
        if (inp) inp.value = '';
    }
    function pmSet(prefix, value) {
        var grid = document.getElementById(prefix + 'PayGrid');
        if (grid) grid.querySelectorAll('.pm-chip').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-v') === value);
        });
        var inp = document.getElementById(prefix + 'PayMethod');
        if (inp) inp.value = value || '';
    }

    var memberEditData = {
        @foreach($activeMembers as $member)
        {{ $member->id }}: {
            url: "{{ route('club.groups.members.update', [$group, $member]) }}",
            date: "{{ $member->subscription_ends_at ? $member->subscription_ends_at->format('Y-m-d') : '' }}",
            starts: "{{ $member->starts_at ? $member->starts_at->format('Y-m-d') : '' }}",
            note: @json($member->note ?? ''),
            pm: "{{ optional($member->enrollments->sortByDesc('id')->first())->payment_method ?? '' }}"
        },
        @endforeach
    };
    function openEditMemberModal(memberId) {
        var d = memberEditData[memberId] || {};
        document.getElementById('editMemberForm').action = d.url || '';
        document.getElementById('editMemberEndsAt').value = d.date || '';
        document.getElementById('editMemberStartsAt').value = d.starts || '';
        document.getElementById('editMemberNote').value = d.note || '';
        pmSet('editMember', d.pm || '');
        document.getElementById('editMemberModal').style.display = 'flex';
    }

    var groupClientTimer;
    function searchGroupClients(q) {
        clearTimeout(groupClientTimer);
        var box = document.getElementById('memberClientResults');
        q = (q || '').trim();
        if (q.length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
        var field = /\d/.test(q) ? 'phone' : 'name';
        groupClientTimer = setTimeout(function () {
            fetch('{{ route("club.clients.search") }}?field=' + field + '&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    box.innerHTML = '';
                    if (!list.length) {
                        var empty = document.createElement('div');
                        empty.style.cssText = 'padding:12px;color:#71717a;font-size:13px;';
                        empty.textContent = 'Ничего не найдено';
                        box.appendChild(empty);
                        box.style.display = 'block';
                        return;
                    }
                    list.forEach(function (c) {
                        var item = document.createElement('div');
                        item.style.cssText = 'padding:10px 12px;cursor:pointer;border-bottom:1px solid #27272a;display:flex;justify-content:space-between;gap:8px;';
                        var nm = document.createElement('span');
                        nm.style.cssText = 'color:#f4f4f5;font-size:14px;';
                        nm.textContent = c.name || '';
                        var ph = document.createElement('span');
                        ph.style.cssText = 'color:#71717a;font-size:13px;';
                        ph.textContent = c.phone || '';
                        item.appendChild(nm); item.appendChild(ph);
                        item.addEventListener('mouseenter', function () { item.style.background = '#1a1a1e'; });
                        item.addEventListener('mouseleave', function () { item.style.background = 'transparent'; });
                        item.addEventListener('click', function () { selectGroupClient(c.id, c.name || ''); });
                        box.appendChild(item);
                    });
                    box.style.display = 'block';
                });
        }, 250);
    }
    function selectGroupClient(id, name) {
        document.getElementById('memberClientId').value = id;
        document.getElementById('memberClientSelectedName').textContent = name;
        document.getElementById('memberClientSelected').style.display = 'flex';
        document.getElementById('memberClientResults').style.display = 'none';
        document.getElementById('memberClientSearch').value = '';
    }
    function clearGroupClient() {
        document.getElementById('memberClientId').value = '';
        document.getElementById('memberClientSelected').style.display = 'none';
    }
    function groupMemberValid() {
        if (!document.getElementById('memberClientId').value) {
            alert('Выберите клиента (поиск по имени или телефону)');
            return false;
        }
        return true;
    }
</script>
@endsection

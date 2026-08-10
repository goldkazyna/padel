@extends('layouts.app')

@section('title', 'Журнал смен')

@section('content')
@php
    $totalMinutes = $shifts->sum(fn ($s) => $s->durationMinutes() ?? 0);
    $missed = $shifts->sum(fn ($s) => $s->results->where('is_done', false)->count());
    // Смены уже отсортированы по убыванию — группировка сохраняет порядок.
    $byDay = $shifts->groupBy(fn ($s) => $s->openedAtLocal()->format('Y-m-d'));
    $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
               'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    $weekdays = ['понедельник', 'вторник', 'среда', 'четверг',
                 'пятница', 'суббота', 'воскресенье'];
@endphp

<div class="page-header">
    <div>
        <h2>Журнал смен</h2>
        <p>{{ $club->name }} · кто работал и что заметил</p>
    </div>
    <a href="{{ route('club.shiftChecklists.index') }}" class="btn-outline-custom">
        <i class="bi bi-list-check"></i> Чек-листы
    </a>
</div>

<div class="jr-wrap">
    {{-- Период --}}
    <form method="GET" class="jr-filter">
        <div class="jr-dates">
            <input type="date" name="from" value="{{ $from }}" class="jr-date">
            <span class="jr-dash">—</span>
            <input type="date" name="to" value="{{ $to }}" class="jr-date">
        </div>
        <button type="submit" class="jr-apply">
            <i class="bi bi-search"></i> Показать
        </button>
        <div class="jr-quick">
            <a href="{{ route('club.shifts.index', ['from' => now()->format('Y-m-d'), 'to' => now()->format('Y-m-d')]) }}">Сегодня</a>
            <a href="{{ route('club.shifts.index', ['from' => now()->subDays(6)->format('Y-m-d'), 'to' => now()->format('Y-m-d')]) }}">7 дней</a>
            <a href="{{ route('club.shifts.index', ['from' => now()->subDays(29)->format('Y-m-d'), 'to' => now()->format('Y-m-d')]) }}">30 дней</a>
        </div>
    </form>

    {{-- Сводка за период --}}
    <div class="jr-stats">
        <div class="jr-stat">
            <div class="jr-stat-icon"><i class="bi bi-clock-history"></i></div>
            <div>
                <b>{{ $shifts->count() }}</b>
                <span>смен за период</span>
            </div>
        </div>
        <div class="jr-stat">
            <div class="jr-stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <b>{{ intdiv($totalMinutes, 60) }} ч</b>
                <span>отработано</span>
            </div>
        </div>
        <div class="jr-stat {{ $comments->isNotEmpty() ? 'warn' : '' }}">
            <div class="jr-stat-icon"><i class="bi bi-chat-left-text"></i></div>
            <div>
                <b>{{ $comments->count() }}</b>
                <span>замечаний</span>
            </div>
        </div>
        <div class="jr-stat {{ $missed > 0 ? 'warn' : '' }}">
            <div class="jr-stat-icon"><i class="bi bi-x-circle"></i></div>
            <div>
                <b>{{ $missed }}</b>
                <span>пунктов не отмечено</span>
            </div>
        </div>
    </div>

    {{-- Замечания: ради них журнал и открывают --}}
    @if($comments->isNotEmpty())
        <div class="jr-section">
            <i class="bi bi-exclamation-triangle"></i> Замечания
            <span class="jr-section-count">{{ $comments->count() }}</span>
        </div>
        <div class="jr-comments">
            @foreach($comments as $c)
                <div class="jr-comment">
                    <div class="jr-comment-head">
                        <span class="jr-who">{{ $c->shift?->user?->name ?? '—' }}</span>
                        <span class="jr-chip">{{ $c->type === 'opening' ? 'открытие' : 'закрытие' }}</span>
                        <span class="jr-when">
                            {{ $c->created_at->timezone(\App\Models\Shift::TZ)->format('d.m.Y, H:i') }}
                        </span>
                    </div>
                    <div class="jr-item-title">{{ $c->title_snapshot }}</div>
                    <div class="jr-comment-text">{{ $c->comment }}</div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Смены --}}
    <div class="jr-section"><i class="bi bi-calendar3"></i> Смены</div>

    @if($shifts->isEmpty())
        <div class="jr-empty">
            <i class="bi bi-calendar-x"></i>
            <div>
                <b>За выбранный период смен не было</b>
                <span>Попробуйте расширить даты выше.</span>
            </div>
        </div>
    @else
        @foreach($byDay as $day => $dayShifts)
            @php $date = \Carbon\Carbon::parse($day); @endphp
            <div class="jr-day">
                <span class="jr-day-num">{{ $date->day }} {{ $months[$date->month] }}</span>
                <span class="jr-day-name">{{ $weekdays[$date->dayOfWeekIso - 1] }}</span>
                <span class="jr-day-line"></span>
            </div>

            <div class="jr-shifts">
                @foreach($dayShifts as $shift)
                    @php
                        $done = $shift->results->where('is_done', true)->count();
                        $total = $shift->results->count();
                        $withComment = $shift->results->filter(fn ($r) => filled($r->comment))->count();
                        $minutes = $shift->durationMinutes();
                        $name = $shift->user?->name ?? '—';
                        $parts = preg_split('/\s+/', trim($name));
                        $initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1)
                            . mb_substr($parts[1] ?? '', 0, 1));
                    @endphp
                    <a href="{{ route('club.shifts.show', $shift) }}"
                       class="jr-shift {{ $shift->isOpen() ? 'live' : '' }}">
                        <div class="jr-ava">{{ $initials !== '' ? $initials : '—' }}</div>

                        <div class="jr-shift-main">
                            <div class="jr-shift-name">
                                {{ $name }}
                                @if($shift->isOpen())
                                    <span class="jr-live">смена идёт</span>
                                @endif
                            </div>
                            <div class="jr-shift-time">
                                <i class="bi bi-clock"></i>
                                {{ $shift->openedAtLocal()->format('H:i') }}
                                @if($shift->closed_at)
                                    — {{ $shift->closedAtLocal()->format('H:i') }}
                                    <span class="jr-dot">·</span>
                                    {{ intdiv($minutes, 60) }} ч {{ $minutes % 60 }} мин
                                @endif
                            </div>
                        </div>

                        <div class="jr-shift-marks">
                            <b class="{{ $total > 0 && $done < $total ? 'miss' : '' }}">{{ $done }}/{{ $total }}</b>
                            <span>отмечено</span>
                        </div>

                        @if($withComment > 0)
                            <div class="jr-badge" title="Замечаний: {{ $withComment }}">
                                <i class="bi bi-chat-left-text"></i> {{ $withComment }}
                            </div>
                        @endif

                        <i class="bi bi-chevron-right jr-go"></i>
                    </a>
                @endforeach
            </div>
        @endforeach
    @endif
</div>

<style>
.jr-wrap { max-width: 1000px; }

/* ---- фильтр ---- */
.jr-filter {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px 18px;
    margin-bottom: 18px;
}
.jr-dates { display: flex; align-items: center; gap: 10px; }
.jr-date {
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 9px 12px;
    color: var(--text-primary);
    font-size: .9rem;
    color-scheme: dark;
}
.jr-date:focus { outline: none; border-color: var(--accent); }
.jr-dash { color: var(--text-secondary); }
.jr-apply {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--accent); color: #000;
    border: none; border-radius: 10px;
    padding: 10px 20px;
    font-size: .9rem; font-weight: 600;
    cursor: pointer;
}
.jr-quick { display: flex; gap: 8px; margin-left: auto; flex-wrap: wrap; }
.jr-quick a {
    color: var(--text-secondary); text-decoration: none;
    border: 1px solid var(--border); border-radius: 8px;
    padding: 7px 13px; font-size: .84rem;
}
.jr-quick a:hover { color: var(--accent); border-color: var(--accent); }

/* ---- сводка ---- */
.jr-stats {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
    margin-bottom: 26px;
}
.jr-stat {
    display: flex; align-items: center; gap: 14px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 16px 18px;
}
.jr-stat-icon {
    width: 40px; height: 40px; flex-shrink: 0;
    border-radius: 11px;
    display: grid; place-items: center;
    background: var(--accent-glow);
    color: var(--accent);
    font-size: 1.05rem;
}
.jr-stat.warn .jr-stat-icon { background: rgba(245, 158, 11, .13); color: #f59e0b; }
.jr-stat b { display: block; font-size: 1.32rem; color: var(--text-primary); line-height: 1.15; }
.jr-stat span { font-size: .8rem; color: var(--text-secondary); }

/* ---- заголовки блоков ---- */
.jr-section {
    display: flex; align-items: center; gap: 9px;
    color: var(--text-secondary);
    font-size: .8rem; font-weight: 600;
    letter-spacing: .09em; text-transform: uppercase;
    margin: 0 0 14px;
}
.jr-section-count {
    background: rgba(245, 158, 11, .15); color: #f59e0b;
    border-radius: 6px; padding: 1px 8px;
    font-size: .8rem; letter-spacing: 0;
}

/* ---- замечания ---- */
.jr-comments { display: flex; flex-direction: column; gap: 10px; margin-bottom: 28px; }
.jr-comment {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-left: 3px solid #f59e0b;
    border-radius: 12px;
    padding: 14px 18px;
}
.jr-comment-head { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 6px; }
.jr-who { color: var(--text-primary); font-weight: 600; }
.jr-chip {
    background: var(--bg-primary); border: 1px solid var(--border);
    border-radius: 6px; padding: 1px 8px;
    font-size: .76rem; color: var(--text-secondary);
}
.jr-when { color: var(--text-secondary); font-size: .82rem; margin-left: auto; }
.jr-item-title { color: var(--text-secondary); font-size: .87rem; margin-bottom: 3px; }
.jr-comment-text { color: #f59e0b; line-height: 1.45; }

/* ---- дни ---- */
.jr-day { display: flex; align-items: center; gap: 10px; margin: 22px 0 12px; }
.jr-day:first-of-type { margin-top: 0; }
.jr-day-num { color: var(--text-primary); font-weight: 600; font-size: .95rem; }
.jr-day-name { color: var(--text-secondary); font-size: .85rem; }
.jr-day-line { flex: 1; height: 1px; background: var(--border); }

/* ---- карточка смены ---- */
.jr-shifts { display: flex; flex-direction: column; gap: 10px; }
.jr-shift {
    display: flex; align-items: center; gap: 16px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 15px 18px;
    text-decoration: none;
    transition: border-color .15s, background .15s;
}
.jr-shift:hover { border-color: var(--border-light); background: var(--bg-card-hover); }
.jr-shift.live { border-color: var(--accent); background: var(--accent-glow); }
.jr-ava {
    width: 42px; height: 42px; flex-shrink: 0;
    border-radius: 12px;
    display: grid; place-items: center;
    background: var(--accent); color: #000;
    font-weight: 700; font-size: .95rem;
}
.jr-shift-main { flex: 1; min-width: 0; }
.jr-shift-name {
    color: var(--text-primary); font-weight: 600; font-size: 1rem;
    display: flex; align-items: center; gap: 9px; flex-wrap: wrap;
}
.jr-live {
    background: var(--accent); color: #000;
    border-radius: 6px; padding: 1px 8px;
    font-size: .72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em;
}
.jr-shift-time {
    color: var(--text-secondary); font-size: .87rem; margin-top: 3px;
    display: flex; align-items: center; gap: 6px;
}
.jr-dot { opacity: .5; }
.jr-shift-marks { text-align: right; flex-shrink: 0; }
.jr-shift-marks b { display: block; color: var(--text-primary); font-size: 1.02rem; }
.jr-shift-marks b.miss { color: #f59e0b; }
.jr-shift-marks span { font-size: .76rem; color: var(--text-secondary); }
.jr-badge {
    display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0;
    background: rgba(245, 158, 11, .15); color: #f59e0b;
    border-radius: 8px; padding: 5px 10px;
    font-size: .85rem; font-weight: 600;
}
.jr-go { color: var(--text-secondary); flex-shrink: 0; }

/* ---- пусто ---- */
.jr-empty {
    display: flex; align-items: center; gap: 14px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 24px 22px;
    color: var(--text-secondary);
}
.jr-empty i { font-size: 1.6rem; color: var(--border-light); }
.jr-empty b { display: block; color: var(--text-primary); font-size: .98rem; margin-bottom: 3px; }
.jr-empty span { font-size: .88rem; }

@media (max-width: 820px) {
    .jr-stats { grid-template-columns: repeat(2, 1fr); }
    .jr-quick { margin-left: 0; }
    .jr-shift-marks { display: none; }
}
</style>
@endsection

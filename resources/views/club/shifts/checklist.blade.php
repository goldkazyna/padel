@extends('layouts.app')

@section('title', $type === 'opening' ? 'Открытие смены' : 'Закрытие смены')

@section('content')
@php
    $isOpening = $type === 'opening';
    $tz = 'Asia/Almaty';
    $now = now($tz);
    // Отработанное время показываем только при закрытии — менеджеру полезно
    // видеть, сколько он на смене, а админ увидит то же самое в журнале.
    $worked = null;
    if (!$isOpening && isset($shift)) {
        $minutes = (int) $shift->opened_at->diffInMinutes(now());
        $worked = intdiv($minutes, 60) . ' ч ' . ($minutes % 60) . ' мин';
    }
@endphp

@if(session('error'))
    <div class="shift-alert mb-3">
        <i class="bi bi-exclamation-triangle"></i>
        <div>{{ session('error') }}</div>
    </div>
@endif

@if(!$isOpening && isset($shift) && $shift->isStale())
    <div class="shift-alert mb-3">
        <i class="bi bi-clock-history"></i>
        <div>
            Смена от {{ $shift->opened_at->timezone($tz)->format('d.m.Y, H:i') }} осталась незакрытой.
            Закройте её, чтобы начать новую.
        </div>
    </div>
@endif

<form method="POST" action="{{ $isOpening ? route('club.shift.open') : route('club.shift.close') }}"
      id="shiftForm">
    @csrf

    <div class="shift-grid">
        {{-- Список пунктов --}}
        <div class="shift-main">
            <div class="shift-main-head">
                <div class="shift-eyebrow">
                    <i class="bi {{ $isOpening ? 'bi-sunrise' : 'bi-moon-stars' }}"></i>
                    {{ $isOpening ? 'Открытие смены' : 'Закрытие смены' }}
                </div>
                <h1>{{ $isOpening ? 'Проверка перед началом работы' : 'Проверка перед уходом' }}</h1>
                <p>
                    Галочка означает «проверил», а не «всё хорошо». Если что-то не в порядке —
                    всё равно отметьте и напишите замечание: администратор увидит его в журнале.
                </p>
            </div>

            @if($items->isEmpty())
                <div class="shift-empty">
                    <i class="bi bi-list-check"></i>
                    <div>
                        <b>Пунктов пока нет</b>
                        <span>Обратитесь к администратору клуба, чтобы он заполнил чек-лист.</span>
                    </div>
                </div>
            @endif

            <div id="shiftItems">
                @foreach($items as $item)
                    @php
                        $old = old('items.' . $item->id, []);
                        $checked = !empty($old['done']) && $old['done'] !== '0';
                    @endphp
                    <div class="shift-item {{ $checked ? 'done' : '' }}">
                        <input type="hidden" name="items[{{ $item->id }}][done]" value="0">
                        <input type="checkbox" name="items[{{ $item->id }}][done]" value="1"
                               id="item{{ $item->id }}" class="shift-real-check"
                               {{ $checked ? 'checked' : '' }}>
                        <label for="item{{ $item->id }}" class="shift-box">
                            <i class="bi bi-check-lg"></i>
                        </label>
                        <div class="shift-body">
                            <label for="item{{ $item->id }}" class="shift-title">{{ $item->title }}</label>
                            <div class="shift-note">
                                <input type="text" name="items[{{ $item->id }}][comment]"
                                       maxlength="1000"
                                       value="{{ $old['comment'] ?? '' }}"
                                       placeholder="Замечание, если есть">
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Сводка --}}
        <div class="shift-side">
            <div class="shift-card">
                <div class="shift-ring">
                    <svg width="120" height="120" viewBox="0 0 120 120">
                        <circle cx="60" cy="60" r="52" stroke="var(--border)" stroke-width="8" fill="none"/>
                        <circle id="shiftRing" cx="60" cy="60" r="52" stroke="var(--accent)" stroke-width="8"
                                fill="none" stroke-linecap="round"
                                stroke-dasharray="327" stroke-dashoffset="327"
                                transform="rotate(-90 60 60)"/>
                    </svg>
                    <div class="shift-ring-text">
                        <b id="shiftCount">0/{{ $items->count() }}</b>
                        <span>проверено</span>
                    </div>
                </div>

                <div class="shift-info">
                    <span>Клуб</span>
                    <div>{{ $club->name }}</div>
                </div>
                <div class="shift-info">
                    <span>Менеджер</span>
                    <div>{{ auth()->user()->name }}</div>
                </div>
                @if($isOpening)
                    <div class="shift-info">
                        <span>Сейчас</span>
                        <div>{{ $now->format('d.m, H:i') }}</div>
                    </div>
                @else
                    <div class="shift-info">
                        <span>Смена открыта</span>
                        <div>{{ $shift->opened_at->timezone($tz)->format('d.m, H:i') }}</div>
                    </div>
                    <div class="shift-info">
                        <span>Отработано</span>
                        <div>{{ $worked }}</div>
                    </div>
                @endif
            </div>

            <button type="submit" class="shift-submit" id="shiftSubmit"
                    {{ $items->isEmpty() ? '' : 'disabled' }}>
                <i class="bi {{ $isOpening ? 'bi-play-fill' : 'bi-box-arrow-right' }}"></i>
                {{ $isOpening ? 'Открыть смену' : 'Закрыть смену и выйти' }}
            </button>
            <div class="shift-left" id="shiftLeft"></div>

            @if(!$isOpening)
                <a href="{{ route('club.dashboard') }}" class="shift-back">
                    <i class="bi bi-arrow-left"></i> Вернуться к работе
                </a>
            @endif
        </div>
    </div>
</form>

<style>
.shift-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 22px;
    align-items: start;
    max-width: 1080px;
}

/* ---- список ---- */
.shift-main {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    overflow: hidden;
}
.shift-main-head {
    padding: 24px 28px 22px;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(180deg, var(--accent-glow), transparent);
}
.shift-eyebrow {
    display: flex; align-items: center; gap: 8px;
    color: var(--accent);
    font-size: .76rem; font-weight: 700;
    letter-spacing: .1em; text-transform: uppercase;
    margin-bottom: 10px;
}
.shift-main-head h1 { margin: 0 0 8px; font-size: 1.4rem; color: var(--text-primary); }
.shift-main-head p { margin: 0; color: var(--text-secondary); font-size: .9rem; line-height: 1.5; }

.shift-item {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 17px 28px;
    border-bottom: 1px solid var(--border);
    transition: background .15s;
}
.shift-item:last-child { border-bottom: none; }
.shift-item.done { background: var(--accent-glow); }
/* Настоящий чекбокс прячем: кликается стилизованный квадрат-label. */
.shift-real-check { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
.shift-box {
    flex-shrink: 0;
    width: 26px; height: 26px;
    border: 2px solid var(--border-light);
    border-radius: 8px;
    display: grid; place-items: center;
    cursor: pointer;
    margin-top: 1px;
    transition: all .15s;
}
.shift-box i { opacity: 0; color: #000; font-size: .95rem; line-height: 1; }
.shift-item.done .shift-box { background: var(--accent); border-color: var(--accent); }
.shift-item.done .shift-box i { opacity: 1; }
.shift-body { flex: 1; min-width: 0; }
.shift-title {
    display: block;
    font-size: 1.02rem; line-height: 1.45;
    color: var(--text-primary);
    cursor: pointer;
    margin: 0;
}
.shift-item.done .shift-title { color: var(--text-secondary); }
/* Поле замечания появляется только у отмеченного пункта — пустых полей на
   экране не висит. */
.shift-note { margin-top: 11px; display: none; }
.shift-item.done .shift-note { display: block; }
.shift-note input {
    width: 100%; max-width: 480px;
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 9px 13px;
    color: var(--text-primary);
    font-size: .88rem;
}
.shift-note input::placeholder { color: var(--text-secondary); opacity: .6; }
.shift-note input:focus { outline: none; border-color: var(--accent); }

.shift-empty {
    display: flex; align-items: center; gap: 14px;
    padding: 26px 28px;
    color: var(--text-secondary);
}
.shift-empty i { font-size: 1.6rem; color: var(--border-light); }
.shift-empty b { display: block; color: var(--text-primary); font-size: .98rem; margin-bottom: 3px; }
.shift-empty span { font-size: .88rem; }

/* ---- сводка ---- */
.shift-side {
    position: sticky; top: 20px;
    display: flex; flex-direction: column; gap: 14px;
}
.shift-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 22px;
}
.shift-ring { position: relative; display: grid; place-items: center; margin-bottom: 18px; }
.shift-ring circle { transition: stroke-dashoffset .3s ease; }
.shift-ring-text { position: absolute; text-align: center; }
.shift-ring-text b { display: block; font-size: 1.45rem; color: var(--text-primary); }
.shift-ring-text span { font-size: .74rem; color: var(--text-secondary); }
.shift-info {
    display: flex; justify-content: space-between; gap: 12px;
    padding: 9px 0;
    font-size: .87rem;
    border-bottom: 1px solid var(--border);
}
.shift-info:last-child { border-bottom: none; padding-bottom: 0; }
.shift-info span { color: var(--text-secondary); flex-shrink: 0; }
.shift-info div { color: var(--text-primary); text-align: right; }

.shift-submit {
    width: 100%;
    display: flex; align-items: center; justify-content: center; gap: 9px;
    background: var(--accent);
    color: #000;
    border: none; border-radius: 12px;
    padding: 14px 20px;
    font-size: 1rem; font-weight: 600;
    cursor: pointer;
    transition: opacity .15s;
}
.shift-submit:disabled { opacity: .35; cursor: not-allowed; }
.shift-left { text-align: center; font-size: .84rem; color: var(--text-secondary); }
.shift-back {
    text-align: center; font-size: .88rem;
    color: var(--text-secondary); text-decoration: none;
    padding: 6px;
}
.shift-back:hover { color: var(--text-primary); }

.shift-alert {
    display: flex; align-items: center; gap: 12px;
    background: rgba(245, 158, 11, .12);
    border: 1px solid #f59e0b;
    border-radius: 12px;
    padding: 13px 18px;
    color: #f59e0b;
    max-width: 1080px;
}

@media (max-width: 900px) {
    .shift-grid { grid-template-columns: 1fr; }
    .shift-side { position: static; }
    .shift-main-head, .shift-item { padding-left: 20px; padding-right: 20px; }
}
</style>

<script>
(function () {
    var items = Array.prototype.slice.call(document.querySelectorAll('#shiftItems .shift-item'));
    var ring = document.getElementById('shiftRing');
    var count = document.getElementById('shiftCount');
    var submit = document.getElementById('shiftSubmit');
    var left = document.getElementById('shiftLeft');
    var CIRCUMFERENCE = 327; // 2πr при r = 52

    function refresh() {
        var done = items.filter(function (i) { return i.classList.contains('done'); }).length;
        var total = items.length;

        count.textContent = done + '/' + total;
        ring.style.strokeDashoffset = total
            ? CIRCUMFERENCE - (CIRCUMFERENCE * done / total)
            : CIRCUMFERENCE;
        submit.disabled = done < total;
        left.textContent = done < total
            ? 'Осталось отметить: ' + (total - done)
            : 'Всё проверено';
    }

    items.forEach(function (item) {
        var check = item.querySelector('.shift-real-check');
        check.addEventListener('change', function () {
            item.classList.toggle('done', check.checked);
            refresh();
        });
    });

    refresh();
})();
</script>
@endsection

@extends('layouts.app')

@section('title', 'Посев — ' . $tournament->name)

@section('content')
<div class="page-header">
    <div>
        <h2>Стартовая расстановка</h2>
        <p>{{ $tournament->name }} · Ladder</p>
    </div>
    <a href="{{ route('club.tournaments.show', $tournament) }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i> Назад
    </a>
</div>

<div class="esc-scope">

@if($ready)
    <div class="esc-note mb-4">
        <i class="bi bi-info-circle me-2"></i>
        Игроки разложены по рейтингу: сильнейшие — на корт 1, дальше вниз. Игроки без рейтинга стоят ниже всех.
        Выберите игрока в любом окошке, чтобы поменять его местами с тем, кто там стоял.
    </div>
@else
    <div class="esc-warn mb-4">
        <i class="bi bi-exclamation-triangle me-2"></i>
        @if($courtsCount < 2)
            У турнира указано меньше двух кортов — эскалера начинается с двух.
            Поправьте количество кортов в
            <a href="{{ route('club.tournaments.edit', $tournament) }}">настройках турнира</a>.
        @else
            Кортов {{ $courtsCount }}, значит игроков должно быть ровно {{ $needed }}.
            Сейчас зарегистрировано {{ $participants->count() }} —
            @if($participants->count() < $needed)
                не хватает {{ $needed - $participants->count() }}.
            @else
                лишних {{ $participants->count() - $needed }}.
            @endif
            Расстановка станет доступна, когда число сойдётся: добавьте или снимите игроков либо
            измените количество кортов в
            <a href="{{ route('club.tournaments.edit', $tournament) }}">настройках турнира</a>
            (участников пересчитает автоматически).
        @endif
    </div>
@endif

<div class="card-dark">
    <div class="card-body">
        @if($ready)
            <form action="{{ route('club.escalera.start', $tournament) }}" method="POST" id="escSeedForm">
                @csrf

                <div class="esc-seed-list">
                    @for($c = 0; $c < $courtsCount; $c++)
                        <div class="esc-seed-court">
                            <div class="esc-seed-court-title">
                                Корт {{ $c + 1 }}
                                @if($c === 0)
                                    <span class="esc-seed-court-note">верхний</span>
                                @elseif($c === $courtsCount - 1)
                                    <span class="esc-seed-court-note">нижний</span>
                                @endif
                            </div>
                            <div class="esc-seed-slots">
                                @for($s = 0; $s < 4; $s++)
                                    @php $idx = $c * 4 + $s; @endphp
                                    <select name="order[]" class="form-select esc-seed-select">
                                        @foreach($participants as $p)
                                            <option value="{{ $p->id }}" {{ $participants[$idx]->id === $p->id ? 'selected' : '' }}>
                                                {{ $p->name }} ({{ $p->rating ?? '—' }})
                                            </option>
                                        @endforeach
                                    </select>
                                @endfor
                            </div>
                        </div>
                    @endfor
                </div>

                <div class="d-flex gap-3 mt-4 flex-wrap">
                    <button type="submit" class="btn-primary-custom"
                            onclick="return confirm('Начать турнир? Расстановка зафиксируется, будет создан первый раунд.')">
                        <i class="bi bi-play-fill"></i> Начать турнир
                    </button>
                    <button type="submit" class="btn-outline-custom"
                            formaction="{{ route('club.escalera.saveSeeding', $tournament) }}"
                            title="Расстановка запомнится только в этом браузере и только до старта">
                        <i class="bi bi-save"></i> Запомнить расстановку
                    </button>
                    <a href="{{ route('club.tournaments.show', $tournament) }}" class="btn-outline-custom">Отмена</a>
                </div>
                <small class="text-secondary d-block mt-2">
                    «Запомнить расстановку» сохраняет порядок только в этом браузере и только до старта турнира.
                </small>
            </form>
        @else
            <div class="esc-seed-preview">
                @foreach($participants as $p)
                    <div class="esc-seed-preview-row">
                        <span class="esc-seed-preview-num">{{ $loop->iteration }}</span>
                        <span>{{ $p->name }}</span>
                        <span class="esc-seed-preview-rating">{{ $p->rating ?? '—' }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

</div>

@if($ready)
<script>
// Свап: выбор игрока меняет его местами со слотом, где он сейчас стоит.
// При полной раскладке каждый игрок всегда остаётся ровно в одном слоте.
(function () {
    const selects = Array.from(document.querySelectorAll('.esc-seed-select'));
    selects.forEach(s => { s.dataset.prev = s.value; });
    selects.forEach(sel => {
        sel.addEventListener('change', function () {
            const newVal = this.value;
            const prevVal = this.dataset.prev;
            if (newVal === prevVal) return;
            const other = selects.find(s => s !== this && s.value === newVal);
            if (other) {
                other.value = prevVal;
                other.dataset.prev = prevVal;
            }
            this.dataset.prev = newVal;
        });
    });
})();
</script>
@endif

<style>
/* Локальные цвета предупреждения: заданы отдельно для тёмной и светлой темы,
   чтобы текст оставался читаемым в обеих. Остальное — переменные темы. */
.esc-scope { --esc-warn: #f59e0b; --esc-warn-bg: rgba(245, 158, 11, 0.12); }
body.light-theme .esc-scope { --esc-warn: #b45309; --esc-warn-bg: rgba(180, 83, 9, 0.10); }

.esc-note {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-left: 3px solid var(--accent);
    border-radius: 10px;
    padding: 14px 18px;
    color: var(--text-secondary);
}
.esc-warn {
    background: var(--esc-warn-bg);
    border: 1px solid var(--esc-warn);
    border-radius: 10px;
    padding: 14px 18px;
    color: var(--esc-warn);
}
.esc-seed-list { display: flex; flex-direction: column; gap: 14px; }
.esc-seed-court {
    padding: 12px 16px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
}
.esc-seed-court-title { font-weight: 600; color: var(--accent); margin-bottom: 10px; }
.esc-seed-court-note { color: var(--text-secondary); font-weight: 400; font-size: 0.85rem; margin-left: 6px; }
.esc-seed-slots { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.esc-seed-preview { display: flex; flex-direction: column; gap: 6px; }
.esc-seed-preview-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
}
.esc-seed-preview-num { color: var(--text-secondary); min-width: 28px; }
.esc-seed-preview-rating { margin-left: auto; color: var(--text-secondary); }
@media (max-width: 800px) {
    .esc-seed-slots { grid-template-columns: repeat(2, 1fr); }
}
</style>
@endsection

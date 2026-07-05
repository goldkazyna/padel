@extends('layouts.app')

@section('title', 'Посев — ' . $tournament->name)

@section('content')
<div class="page-header">
    <div>
        <h2>Посев</h2>
        <p>{{ $tournament->name }} · Just Padel It</p>
    </div>
    <a href="{{ route('club.tournaments.show', $tournament) }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i> Назад
    </a>
</div>

<div class="alert-info-custom mb-4">
    <i class="bi bi-info-circle me-2"></i>
    Игроки распределены по рейтингу (сильные — корт 1). Можно поменять вручную.
</div>

<div class="card-dark">
    <div class="card-body">
        <form action="{{ route('club.justpadelit.start', $tournament) }}" method="POST" id="seedForm">
            @csrf

            <div class="seed-courts-list">
                @for($c = 0; $c < $courtsCount; $c++)
                    <div class="seed-court-block">
                        <div class="seed-court-title">Корт {{ $c + 1 }}</div>
                        <div class="seed-court-slots">
                            @for($s = 0; $s < 4; $s++)
                                @php $idx = $c * 4 + $s; @endphp
                                <select name="order[]" class="form-select seed-select">
                                    @foreach($participants as $p)
                                        <option value="{{ $p->id }}" {{ $participants[$idx]->id === $p->id ? 'selected' : '' }}>
                                            {{ $p->name }} ({{ $p->rating }})
                                        </option>
                                    @endforeach
                                </select>
                            @endfor
                        </div>
                    </div>
                @endfor
            </div>

            <div class="d-flex gap-3 mt-4">
                <button type="submit" class="btn-primary-custom">
                    <i class="bi bi-play-fill"></i> Начать турнир
                </button>
                <a href="{{ route('club.tournaments.show', $tournament) }}" class="btn-outline-custom">Отмена</a>
            </div>
        </form>
    </div>
</div>

<script>
function refreshDisabledOptions() {
    const selects = Array.from(document.querySelectorAll('.seed-select'));
    const chosen = selects.map(s => s.value);
    selects.forEach(sel => {
        Array.from(sel.options).forEach(o => {
            o.disabled = chosen.includes(o.value) && sel.value !== o.value;
        });
    });
}
document.querySelectorAll('.seed-select').forEach(s => s.addEventListener('change', refreshDisabledOptions));
refreshDisabledOptions();
</script>

<style>
.seed-courts-list { display: flex; flex-direction: column; gap: 14px; }
.seed-court-block {
    padding: 12px 16px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
}
.seed-court-title { font-weight: 600; color: var(--accent); margin-bottom: 10px; }
.seed-court-slots { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
@media (max-width: 800px) {
    .seed-court-slots { grid-template-columns: repeat(2, 1fr); }
}
</style>
@endsection

@extends('layouts.app')

@section('title', 'Создать пары — ' . $tournament->name)

@section('content')
<div class="page-header">
    <div>
        <h2>Создать пары</h2>
        <p>{{ $tournament->name }} · Король корта (фикс-пары)</p>
    </div>
    <a href="{{ route('club.tournaments.show', $tournament) }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i> Назад
    </a>
</div>

@if($existingPairs->isNotEmpty())
    <div class="alert-info-custom mb-4">
        <i class="bi bi-info-circle me-2"></i>
        Пары уже созданы. После старта турнира их менять нельзя.
    </div>

    <div class="card-dark">
        <div class="card-body">
            <h5 class="mb-3"><i class="bi bi-people-fill"></i> Пары ({{ $existingPairs->count() }})</h5>
            <div class="pairs-list">
                @foreach($existingPairs as $idx => $pair)
                    <div class="pair-row">
                        <div class="pair-num">Пара {{ $idx + 1 }}</div>
                        <div class="pair-players">
                            <span>{{ $pair->player1->name ?? '?' }}</span>
                            <span class="pair-plus">+</span>
                            <span>{{ $pair->player2->name ?? '?' }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@else
    @php
        $expectedPairs = (int) ($participants->count() / 2);
        $canCreate = $participants->count() >= 8 && $participants->count() % 4 === 0;
    @endphp

    <div class="alert-info-custom mb-4">
        <i class="bi bi-info-circle me-2"></i>
        Зарегистрировано <strong>{{ $participants->count() }}</strong> игроков →
        нужно создать <strong>{{ $expectedPairs }}</strong> пар.
        @if(!$canCreate)
            <div class="mt-2 text-warning">
                <i class="bi bi-exclamation-triangle"></i>
                Игроков должно быть минимум 8 и кратно 4.
            </div>
        @endif
    </div>

    @if($errors->any())
        <div class="alert-danger-custom mb-4">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card-dark">
        <div class="card-body">
            <form action="{{ route('club.kingofcourt.storePairs', $tournament) }}" method="POST" id="pairsForm">
                @csrf

                <div class="pairs-form-list">
                    @for($i = 0; $i < $expectedPairs; $i++)
                        <div class="pair-form-row">
                            <div class="pair-num">Пара {{ $i + 1 }}</div>
                            <select name="pairs[{{ $i }}][0]" class="form-select pair-select" data-pair="{{ $i }}" data-slot="0" required>
                                <option value="">— игрок 1 —</option>
                                @foreach($participants as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }} (L{{ $p->level }} · {{ $p->rating }})</option>
                                @endforeach
                            </select>
                            <span class="pair-plus">+</span>
                            <select name="pairs[{{ $i }}][1]" class="form-select pair-select" data-pair="{{ $i }}" data-slot="1" required>
                                <option value="">— игрок 2 —</option>
                                @foreach($participants as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }} (L{{ $p->level }} · {{ $p->rating }})</option>
                                @endforeach
                            </select>
                        </div>
                    @endfor
                </div>

                <div class="d-flex gap-3 mt-4">
                    <button type="submit" class="btn-primary-custom" {{ $canCreate ? '' : 'disabled' }}>
                        <i class="bi bi-check-lg"></i> Сохранить пары
                    </button>
                    <button type="button" class="btn-outline-custom" onclick="autoAssignByRating()">
                        <i class="bi bi-shuffle"></i> Авто (сильный + слабый)
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const players = @json($participants->map(fn($p) => ['id' => $p->id, 'rating' => (int) $p->rating])->values());

    function refreshDisabledOptions() {
        const selects = document.querySelectorAll('.pair-select');
        const taken = new Set();
        selects.forEach(s => { if (s.value) taken.add(s.value); });
        selects.forEach(s => {
            const current = s.value;
            Array.from(s.options).forEach(opt => {
                if (!opt.value) return;
                opt.disabled = taken.has(opt.value) && opt.value !== current;
            });
        });
    }

    document.querySelectorAll('.pair-select').forEach(s => {
        s.addEventListener('change', refreshDisabledOptions);
    });
    refreshDisabledOptions();

    function autoAssignByRating() {
        const sorted = [...players].sort((a, b) => b.rating - a.rating);
        const n = sorted.length;
        const pairs = [];
        for (let i = 0; i < n / 2; i++) {
            pairs.push([sorted[i].id, sorted[n - 1 - i].id]);
        }
        pairs.forEach((pair, idx) => {
            const s0 = document.querySelector(`.pair-select[data-pair="${idx}"][data-slot="0"]`);
            const s1 = document.querySelector(`.pair-select[data-pair="${idx}"][data-slot="1"]`);
            if (s0) s0.value = pair[0];
            if (s1) s1.value = pair[1];
        });
        refreshDisabledOptions();
    }
    </script>
@endif

<style>
.pairs-list, .pairs-form-list { display: flex; flex-direction: column; gap: 10px; }
.pair-row, .pair-form-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
}
.pair-num { min-width: 80px; font-weight: 600; color: var(--accent); }
.pair-players { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pair-plus { color: var(--text-secondary); font-weight: 700; }
.pair-form-row .form-select { flex: 1; min-width: 0; }
@media (max-width: 600px) {
    .pair-form-row { flex-wrap: wrap; }
    .pair-num { width: 100%; }
}
</style>
@endsection

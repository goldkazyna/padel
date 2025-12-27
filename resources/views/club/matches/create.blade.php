@extends('layouts.app')

@section('title', 'Добавить матч')

@section('content')
<div class="page-header">
    <div>
        <h2>Добавить матч</h2>
        <p>{{ $tournament->name }}</p>
    </div>
    <a href="{{ route('club.tournaments.show', $tournament) }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i> Назад
    </a>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card-dark">
            <div class="card-body">
                @if($tournament->participants->count() < 2)
                    <div class="text-center py-5 text-secondary">
                        <i class="bi bi-people fs-1 mb-3"></i>
                        <p>Нужно минимум 2 участника для создания матча</p>
                    </div>
                @else
                    <form action="{{ route('club.matches.store', $tournament) }}" method="POST">
                        @csrf

                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label class="form-label">Игрок 1 *</label>
                                <select name="player1_id" id="player1" class="form-select" required>
                                    <option value="">— Выберите —</option>
                                    @foreach($tournament->participants as $p)
                                        <option value="{{ $p->id }}" data-rating="{{ $p->rating }}">
                                            {{ $p->full_name }} ({{ $p->rating }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('player1_id')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6 mb-4">
                                <label class="form-label">Игрок 2 *</label>
                                <select name="player2_id" id="player2" class="form-select" required>
                                    <option value="">— Выберите —</option>
                                    @foreach($tournament->participants as $p)
                                        <option value="{{ $p->id }}" data-rating="{{ $p->rating }}">
                                            {{ $p->full_name }} ({{ $p->rating }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('player2_id')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Счёт *</label>
                            <input type="text" name="score" class="form-control" placeholder="6:4, 6:3" required>
                            <small class="text-secondary">Формат: 6:4, 6:3 или 6:4, 4:6, 10:8</small>
                            @error('score')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Победитель *</label>
                            <select name="winner_id" id="winner" class="form-select" required>
                                <option value="">— Сначала выберите игроков —</option>
                            </select>
                            @error('winner_id')<div class="text-danger mt-2 small">{{ $message }}</div>@enderror
                        </div>

                        <div class="d-flex gap-3">
                            <button type="submit" class="btn-primary-custom">
                                <i class="bi bi-check-lg"></i> Сохранить матч
                            </button>
                            <a href="{{ route('club.tournaments.show', $tournament) }}" class="btn-outline-custom">Отмена</a>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const player1 = document.getElementById('player1');
    const player2 = document.getElementById('player2');
    const winner = document.getElementById('winner');

    function updateWinner() {
        winner.innerHTML = '<option value="">— Выберите победителя —</option>';
        
        if (player1.value && player2.value) {
            const p1 = player1.options[player1.selectedIndex];
            const p2 = player2.options[player2.selectedIndex];
            
            winner.innerHTML += `<option value="${p1.value}">${p1.text}</option>`;
            winner.innerHTML += `<option value="${p2.value}">${p2.text}</option>`;
        }
    }

    player1.addEventListener('change', updateWinner);
    player2.addEventListener('change', updateWinner);
});
</script>
@endsection
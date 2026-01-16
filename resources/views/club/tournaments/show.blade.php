@extends('layouts.app')

@section('title', $tournament->name)

@push('styles')
<link rel="stylesheet" href="{{ asset('css/tournament-show.css') }}?v={{ time() }}">
@endpush

@section('content')
{{-- Шапка --}}
@include('club.tournaments.partials._header')

{{-- Информация о турнире --}}
@include('club.tournaments.partials._info')

{{-- Участники --}}
@include('club.tournaments.partials._participants')

{{-- Американо --}}
@if($tournament->isAmericano() && $tournament->groups->count() > 0)
    @include('club.tournaments.partials._americano')
	@include('club.tournaments.partials._americano_playoff')
@endif

{{-- Мексикано --}}
@if($tournament->isMexicano() && $tournament->mexicanoPlayers->count() > 0)
    @include('club.tournaments.partials._mexicano')
@endif

{{-- Командный турнир --}}
@if($tournament->isTeamBased())
    @include('club.tournaments.partials._team')
@endif



<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form[data-ajax-score]').forEach(form => {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const url = this.action;
            const matchId = this.dataset.matchId;
            const groupId = this.dataset.groupId;
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Сохранение...';
            submitBtn.disabled = true;
            
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    updateMatchCard(matchId, data.match);
                    updateLeaderboard(groupId, data.leaderboard);
                    
                    if (data.round) {
                        updateRoundStatus(data.round);
                    }
                    
                    // Активируем следующий раунд если текущий завершён
                    // if (data.nextRound) {
						//     activateNextRound(data.nextRound);
						// }
                    
                    const modal = bootstrap.Modal.getInstance(this.closest('.modal'));
                    if (modal) modal.hide();
                    
                    showToast(data.message, 'success');
					
					// Перезагружаем страницу если раунд завершён (для появления кнопки)
					if (data.round && data.round.status === 'completed') {
						setTimeout(() => {
							window.location.reload();
						}, 1000);
					}
                } else {
                    showToast('Ошибка сохранения', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Ошибка соединения', 'error');
            } finally {
                submitBtn.innerHTML = originalBtnText;
                submitBtn.disabled = false;
            }
        });
    });
});

function updateMatchCard(matchId, matchData) {
    const matchCard = document.querySelector(`[data-match-id="${matchId}"]`);
    if (!matchCard) return;
    
    const team1 = matchCard.querySelector('.match-team:first-child');
    const team2 = matchCard.querySelector('.match-team:last-child');
    const vsBlock = matchCard.querySelector('.match-vs');
    
    team1.classList.remove('winner');
    team2.classList.remove('winner');
    if (matchData.winning_team === 1) team1.classList.add('winner');
    if (matchData.winning_team === 2) team2.classList.add('winner');
    
    let score1 = team1.querySelector('.team-score');
    let score2 = team2.querySelector('.team-score');
    
    if (!score1) {
        score1 = document.createElement('div');
        score1.className = 'team-score';
        team1.appendChild(score1);
    }
    if (!score2) {
        score2 = document.createElement('div');
        score2.className = 'team-score';
        team2.insertBefore(score2, team2.firstChild);
    }
    
    score1.textContent = matchData.team1_score;
    score2.textContent = matchData.team2_score;
    score1.className = 'team-score' + (matchData.winning_team === 1 ? ' text-success' : '');
    score2.className = 'team-score' + (matchData.winning_team === 2 ? ' text-success' : '');
    
    vsBlock.innerHTML = `
        <button class="btn-score-edit" 
                data-bs-toggle="modal" 
                data-bs-target="#editScoreModal${matchId}"
                title="Редактировать счёт">
            <i class="bi bi-pencil"></i>
        </button>
    `;
}

function updateLeaderboard(groupId, leaderboard) {
    // Для Американо (с группами)
    const groupLeaderboard = document.querySelector(`#group${groupId} .leaderboard-list`);
    if (groupLeaderboard) {
        groupLeaderboard.innerHTML = leaderboard.map((player, index) => {
            const rankClass = index === 0 ? 'gold' : (index === 1 ? 'silver' : (index === 2 ? 'bronze' : ''));
            return `
                <div class="leaderboard-row ${rankClass}">
                    <div class="leaderboard-rank ${rankClass}">${index + 1}</div>
                    <div class="leaderboard-avatar">${player.initials}</div>
                    <div class="leaderboard-info">
                        <div class="leaderboard-name">${player.name}</div>
                        <div class="leaderboard-rating">Рейтинг: ${player.rating}</div>
                    </div>
                    <div class="leaderboard-points">${player.points}</div>
                </div>
            `;
        }).join('');
    }
    
    // Для Мексикано (без групп)
    const mexicanoLeaderboard = document.querySelector('#mexicanoLeaderboard');
    if (mexicanoLeaderboard) {
        mexicanoLeaderboard.innerHTML = leaderboard.map((player, index) => {
            const rankClass = index === 0 ? 'gold' : (index === 1 ? 'silver' : (index === 2 ? 'bronze' : ''));
            return `
                <div class="leaderboard-row ${rankClass}">
                    <div class="leaderboard-rank ${rankClass}">${index + 1}</div>
                    <div class="leaderboard-avatar">${player.initials}</div>
                    <div class="leaderboard-info">
                        <div class="leaderboard-name">${player.name}</div>
                        <div class="leaderboard-rating">Рейтинг: ${player.rating}</div>
                    </div>
                    <div class="leaderboard-points">${player.points}</div>
                </div>
            `;
        }).join('');
    }
}

function updateRoundStatus(roundData) {
    const roundCard = document.querySelector(`[data-round-id="${roundData.id}"]`);
    if (!roundCard) return;
    
    const statusBadge = roundCard.querySelector('.round-status');
    if (statusBadge && roundData.status === 'completed') {
        statusBadge.className = 'round-status completed';
        statusBadge.textContent = 'Завершён';
        
        const icon = roundCard.querySelector('.round-title i');
        if (icon) {
            icon.className = 'bi bi-check-circle-fill text-success';
        }
    }
}

function activateNextRound(nextRoundData) {
    const roundCard = document.querySelector(`[data-round-id="${nextRoundData.id}"]`);
    if (!roundCard) return;
    
    // Обновляем статус раунда
    const statusBadge = roundCard.querySelector('.round-status');
    if (statusBadge) {
        statusBadge.className = 'round-status active';
        statusBadge.textContent = 'Идёт';
    }
    
    const icon = roundCard.querySelector('.round-title i');
    if (icon) {
        icon.className = 'bi bi-play-circle-fill text-primary';
    }
    
    // Активируем кнопки "Счёт" для всех матчей этого раунда
    nextRoundData.matches.forEach(match => {
        const matchCard = document.querySelector(`[data-match-id="${match.id}"]`);
        if (!matchCard) return;
        
        const vsBlock = matchCard.querySelector('.match-vs');
        if (vsBlock && match.status === 'pending') {
            vsBlock.innerHTML = `
                <button class="btn-score" 
                        data-bs-toggle="modal" 
                        data-bs-target="#scoreModal${match.id}">
                    <i class="bi bi-pencil-square"></i>
                </button>
            `;
        }
    });
    
    showToast('Раунд ' + nextRoundData.round_number + ' активирован!', 'success');
}

function showToast(message, type = 'success') {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '1100';
        document.body.appendChild(container);
    }
    
    const toastId = 'toast-' + Date.now();
    const bgColor = type === 'success' ? 'var(--accent)' : '#ef4444';
    
    container.innerHTML += `
        <div id="${toastId}" class="toast align-items-center border-0" role="alert" 
             style="background: ${bgColor}; color: #000;">
            <div class="d-flex">
                <div class="toast-body fw-medium">
                    <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-dark me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;
    
    const toastEl = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
    toast.show();
    
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}
// Автозаполнение счёта для Мексикано
document.querySelectorAll('form[data-mexicano="true"]').forEach(form => {
    const inputs = form.querySelectorAll('input[type="number"]');
    if (inputs.length === 2) {
        const pointsTotal = {{ $tournament->points_to_win ?? 32 }};
        
        inputs[0].addEventListener('input', function() {
            const val = parseInt(this.value) || 0;
            if (val >= 0 && val <= pointsTotal) {
                inputs[1].value = pointsTotal - val;
            }
        });
        
        inputs[1].addEventListener('input', function() {
            const val = parseInt(this.value) || 0;
            if (val >= 0 && val <= pointsTotal) {
                inputs[0].value = pointsTotal - val;
            }
        });
    }
});
// Поиск игроков для групповых турниров
function setupPlayerSearch(inputId, resultsId, selectedId, hiddenId) {
    const input = document.getElementById(inputId);
    const results = document.getElementById(resultsId);
    const selected = document.getElementById(selectedId);
    const hidden = document.getElementById(hiddenId);

    if (!input) return;

    let timeout;
    input.addEventListener('input', function() {
        clearTimeout(timeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            results.classList.remove('show');
            return;
        }

        timeout = setTimeout(async () => {
            try {
                const response = await fetch(`{{ route('club.tournaments.searchPlayer') }}?q=${encodeURIComponent(query)}`);
                const players = await response.json();
                
                if (players.length > 0) {
                    results.innerHTML = players.map(p => `
                        <div class="search-result-item" data-id="${p.id}" data-name="${p.first_name} ${p.last_name}">
                            <div><strong>${p.first_name} ${p.last_name}</strong></div>
                            <div style="font-size: 0.8rem; color: #9ca3af;">
                                ${p.phone} | Рейтинг: ${p.rating} | Уровень: ${p.level}
                            </div>
                        </div>
                    `).join('');
                    results.classList.add('show');
                    
                    results.querySelectorAll('.search-result-item').forEach(item => {
                        item.addEventListener('click', function() {
                            const id = this.dataset.id;
                            const name = this.dataset.name;
                            
                            hidden.value = id;
                            selected.innerHTML = `<i class="bi bi-check-circle me-2"></i>${name}`;
                            selected.style.display = 'block';
                            input.style.display = 'none';
                            results.classList.remove('show');
                        });
                    });
                } else {
                    results.innerHTML = '<div class="search-result-item">Ничего не найдено</div>';
                    results.classList.add('show');
                }
            } catch (error) {
                console.error('Search error:', error);
            }
        }, 300);
    });

    // Сброс при клике на выбранного
    selected.addEventListener('click', function() {
        hidden.value = '';
        selected.style.display = 'none';
        input.style.display = 'block';
        input.value = '';
        input.focus();
    });

    // Закрыть при клике вне
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !results.contains(e.target)) {
            results.classList.remove('show');
        }
    });
}

setupPlayerSearch('searchPlayer1', 'player1Results', 'player1Selected', 'player1_id');
setupPlayerSearch('searchPlayer2', 'player2Results', 'player2Selected', 'player2_id');
</script>
@endsection
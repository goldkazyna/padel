<div class="section-header" style="cursor: pointer;" onclick="toggleParticipants()">
    <h5>
        <i class="bi bi-people"></i> 
        Участники ({{ $tournament->approvedParticipantsCount() }}/{{ $tournament->max_participants }})
        @if($tournament->pendingParticipantsCount() > 0)
            <span class="pending-badge">+{{ $tournament->pendingParticipantsCount() }} на модерации</span>
        @endif
        @if($tournament->status === 'in_progress' || $tournament->status === 'completed')
            <i class="bi bi-chevron-down toggle-icon" id="toggleIcon"></i>
        @endif
    </h5>
    @if($tournament->status === 'open' && $tournament->pendingParticipantsCount() > 0)
        <form action="{{ route('club.tournaments.participants.approveAll', $tournament) }}" method="POST" class="d-inline" onclick="event.stopPropagation()">
            @csrf
            <button type="submit" class="btn-outline-custom btn-sm" onclick="return confirm('Одобрить все заявки?')">
                <i class="bi bi-check-all"></i> Одобрить все
            </button>
        </form>
    @endif
</div>

{{-- Контент участников (сворачиваемый) --}}
<div class="participants-content" id="participantsContent" 
     style="{{ in_array($tournament->status, ['in_progress', 'completed']) ? 'display: none;' : '' }}">

    {{-- Заявки на модерации --}}
    @if($tournament->pendingParticipantsCount() > 0)
    <div class="pending-section mb-4">
        <div class="pending-header">
            <i class="bi bi-hourglass-split text-warning"></i>
            <span>Заявки на модерации ({{ $tournament->pendingParticipantsCount() }})</span>
        </div>
        <div class="participants-list">
            @foreach($tournament->pendingParticipants as $participant)
                <div class="participant-row pending">
                    <div class="participant-status-indicator pending">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div class="participant-avatar">
                        {{ mb_strtoupper(mb_substr($participant->first_name, 0, 1) . mb_substr($participant->last_name, 0, 1)) }}
                    </div>
                    <div class="participant-info">
                        <div class="participant-name">{{ $participant->name }}</div>
						<small class="text-muted">{{ $participant->phone ? preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $participant->phone) : '' }}</small>
                        <div class="participant-meta">
                            <span class="level-badge">{{ $participant->level }}</span>
                            <span class="text-warning">На модерации</span>
                        </div>
                    </div>
                    <div class="participant-rating">{{ $participant->rating }}</div>
                    @if($tournament->status === 'open')
                        <div class="participant-actions">
                            <form action="{{ route('club.tournaments.participants.approve', [$tournament, $participant->id]) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn-success-custom btn-sm" title="Одобрить">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                            <form action="{{ route('club.tournaments.participants.reject', [$tournament, $participant->id]) }}" method="POST" class="d-inline" onsubmit="return confirm('Отклонить заявку?')">
                                @csrf
                                <button type="submit" class="btn-danger-custom btn-sm" title="Отклонить">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif
	
	
	
    {{-- Одобренные участники --}}
    <div class="participants-list">
        @forelse($tournament->approvedParticipants as $index => $participant)
            <div class="participant-row approved">
                <div class="participant-status-indicator approved">
                    <i class="bi bi-check"></i>
                </div>
                <div class="participant-rank">{{ $index + 1 }}</div>
                <div class="participant-avatar">
                    {{ mb_strtoupper(mb_substr($participant->first_name, 0, 1) . mb_substr($participant->last_name, 0, 1)) }}
                </div>
                <div class="participant-info">
                    <div class="participant-name">{{ $participant->name }}</div>
					<small class="text-muted">{{ $participant->phone ? preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $participant->phone) : '' }}</small>
                    <div class="participant-meta">
                        <span class="level-badge">{{ $participant->level }}</span>
                        <span class="text-success">Одобрен</span>
                    </div>
                </div>
                <div class="participant-rating">{{ $participant->rating }}</div>
                @if($tournament->status === 'open')
                    <div class="participant-actions">
                        <button type="button" class="btn-outline-custom btn-sm" 
                                data-bs-toggle="modal" 
                                data-bs-target="#replaceModal{{ $participant->id }}"
                                title="Заменить">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form action="{{ route('club.tournaments.participants.remove', [$tournament, $participant->id]) }}" method="POST" class="d-inline" onsubmit="return confirm('Удалить участника?')">
                            @csrf
                            @method('DELETE')
                            <button class="btn-danger-custom btn-sm" title="Удалить"><i class="bi bi-x"></i></button>
                        </form>
                    </div>
                @endif
            </div>

            {{-- Модалка замены участника --}}
            @if($tournament->status === 'open')
            <div class="modal fade" id="replaceModal{{ $participant->id }}" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content modal-dark">
                        <div class="modal-header border-0">
                            <h5 class="modal-title">Заменить участника</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form action="{{ route('club.tournaments.participants.replace', [$tournament, $participant->id]) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <div class="modal-body">
                                <div class="current-player-info mb-3">
                                    <label class="form-label text-secondary">Текущий участник</label>
                                    <div class="current-player-card">
                                        <div class="participant-avatar">
                                            {{ mb_strtoupper(mb_substr($participant->first_name, 0, 1) . mb_substr($participant->last_name, 0, 1)) }}
                                        </div>
                                        <div>
                                            <div class="fw-bold">{{ $participant->full_name }}</div>
                                            <div class="text-secondary small">{{ $participant->phone }} • Рейтинг: {{ $participant->rating }}</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Новый участник</label>
                                    <input type="text" 
                                           class="form-control player-search-input" 
                                           data-target="replace{{ $participant->id }}"
                                           placeholder="Введите телефон или имя..." 
                                           autocomplete="off">
                                    <input type="hidden" name="new_user_id" id="replace{{ $participant->id }}PlayerId">
									<div class="search-results" id="replace{{ $participant->id }}Results"></div>
									<div class="selected-player mt-2" id="replace{{ $participant->id }}Selected" style="display: none;"></div>
                                </div>
                            </div>
                            <div class="modal-footer border-0">
                                <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Отмена</button>
                                <button type="submit" class="btn-primary-custom">
                                    <i class="bi bi-arrow-repeat me-1"></i> Заменить
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            @endif
        @empty
            <div class="empty-state">
                <i class="bi bi-people"></i>
                <p>Пока нет участников</p>
            </div>
        @endforelse
    </div>

    {{-- Форма добавления участника --}}
    @if($tournament->status === 'open' && $tournament->approvedParticipantsCount() < $tournament->max_participants)
    <div class="add-participant-section mt-4">
        <div class="add-participant-header">
            <i class="bi bi-person-plus"></i>
            <span>Добавить участника</span>
        </div>
        <form action="{{ route('club.tournaments.participants.add', $tournament) }}" method="POST" class="add-participant-form">
            @csrf
            <div class="search-wrapper">
                <input type="text" 
                       class="form-control player-search-input" 
                       data-target="addNew"
                       placeholder="Введите телефон или имя игрока..." 
                       autocomplete="off">
                <input type="hidden" name="user_id" id="addNewPlayerId">
                <div class="search-results" id="addNewResults"></div>
            </div>
            <div class="selected-player mt-2" id="addNewSelected" style="display: none;"></div>
            <button type="submit" class="btn-primary-custom mt-3">
                <i class="bi bi-plus-lg me-1"></i> Добавить
            </button>
        </form>
    </div>
    @endif
</div>

<style>
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.section-header h5 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.toggle-icon {
    transition: transform 0.3s;
    margin-left: 8px;
}

.toggle-icon.rotated {
    transform: rotate(180deg);
}

.participants-content {
    transition: all 0.3s ease;
}

.pending-badge {
    background: rgba(234, 179, 8, 0.2);
    color: #eab308;
    font-size: 0.75rem;
    padding: 4px 10px;
    border-radius: 20px;
    margin-left: 8px;
    font-weight: 600;
}

.pending-section {
    background: rgba(234, 179, 8, 0.05);
    border: 1px solid rgba(234, 179, 8, 0.2);
    border-radius: 12px;
    padding: 16px;
}

.pending-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    margin-bottom: 12px;
}

.participant-status-indicator {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.participant-status-indicator.pending {
    background: rgba(234, 179, 8, 0.2);
    color: #eab308;
}

.participant-status-indicator.approved {
    background: rgba(34, 197, 94, 0.2);
    color: #22c55e;
}

.participant-row.pending {
    border-left: 3px solid #eab308;
}

.participant-row.approved {
    border-left: 3px solid #22c55e;
}

.participant-actions {
    display: flex;
    gap: 8px;
}

.btn-success-custom {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
    border: 1px solid rgba(34, 197, 94, 0.3);
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.2s;
}

.btn-success-custom:hover {
    background: #22c55e;
    color: #000;
}

/* Модалка */
.modal-dark {
    background: var(--bg-card);
    border: 1px solid var(--border);
}

.current-player-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
}

/* Поиск игроков */
.search-wrapper {
    position: relative;
}

.search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
    max-height: 250px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}

.search-results.show {
    display: block;
}

.search-result-item {
    padding: 12px;
    cursor: pointer;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    transition: background 0.2s;
}

.search-result-item:hover {
    background: rgba(34, 197, 94, 0.1);
}

.search-result-item:last-child {
    border-bottom: none;
}

.search-result-name {
    font-weight: 500;
}

.search-result-meta {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

.selected-player {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    border-radius: 8px;
}

.selected-player .remove-selected {
    margin-left: auto;
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    padding: 4px 8px;
}

/* Форма добавления */
.add-participant-section {
    background: rgba(34, 197, 94, 0.05);
    border: 1px dashed rgba(34, 197, 94, 0.3);
    border-radius: 12px;
    padding: 16px;
}

.add-participant-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    margin-bottom: 12px;
    color: var(--accent);
}

.add-participant-form {
    position: relative;
}
.text-muted{
	color:#16a34a !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInputs = document.querySelectorAll('.player-search-input');
    
    searchInputs.forEach(input => {
        const target = input.dataset.target;
        const resultsDiv = document.getElementById(target + 'Results');
        const selectedDiv = document.getElementById(target + 'Selected');
        const hiddenInput = document.getElementById(target + 'PlayerId');
        
        let searchTimeout;
        
        input.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length < 3) {
                resultsDiv.classList.remove('show');
                return;
            }
            
            searchTimeout = setTimeout(() => {
                fetch(`{{ route('club.tournaments.searchPlayers', $tournament) }}?q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(players => {
                        if (players.length === 0) {
                            resultsDiv.innerHTML = '<div class="search-result-item text-secondary">Ничего не найдено</div>';
                        } else {
                            resultsDiv.innerHTML = players.map(player => `
                                <div class="search-result-item" 
                                     data-id="${player.id}" 
                                     data-name="${player.name}"
                                     data-phone="${player.phone}"
                                     data-rating="${player.rating}"
                                     data-level="${player.level}">
                                    <div class="search-result-name">${player.name}</div>
                                    <div class="search-result-meta">${player.phone} • Уровень: ${player.level} • Рейтинг: ${player.rating}</div>
                                </div>
                            `).join('');
                        }
                        resultsDiv.classList.add('show');
                        
                        // Клик по результату
                        resultsDiv.querySelectorAll('.search-result-item[data-id]').forEach(item => {
                            item.addEventListener('click', function() {
                                const id = this.dataset.id;
                                const name = this.dataset.name;
                                const phone = this.dataset.phone;
                                const rating = this.dataset.rating;
                                const level = this.dataset.level;
                                
                                hiddenInput.value = id;
                                input.value = '';
                                resultsDiv.classList.remove('show');
                                
                                selectedDiv.innerHTML = `
                                    <div class="participant-avatar">${name.split(' ').map(n => n[0]).join('').toUpperCase()}</div>
                                    <div>
                                        <div class="fw-bold">${name}</div>
                                        <div class="text-secondary small">${phone} • Уровень: ${level} • Рейтинг: ${rating}</div>
                                    </div>
                                    <button type="button" class="remove-selected" onclick="clearSelection('${target}')">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                `;
                                selectedDiv.style.display = 'flex';
                            });
                        });
                    });
            }, 300);
        });
        
        // Скрываем результаты при клике вне
        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !resultsDiv.contains(e.target)) {
                resultsDiv.classList.remove('show');
            }
        });
    });
});

function clearSelection(target) {
    document.getElementById(target + 'PlayerId').value = '';
    document.getElementById(target + 'Selected').style.display = 'none';
}

function toggleParticipants() {
    const content = document.getElementById('participantsContent');
    const icon = document.getElementById('toggleIcon');
    
    if (!icon) return;
    
    if (content.style.display === 'none') {
        content.style.display = 'block';
        icon.classList.add('rotated');
    } else {
        content.style.display = 'none';
        icon.classList.remove('rotated');
    }
}
</script>
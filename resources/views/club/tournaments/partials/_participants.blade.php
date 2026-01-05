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
                        {{ strtoupper(substr($participant->first_name, 0, 1) . substr($participant->last_name, 0, 1)) }}
                    </div>
                    <div class="participant-info">
                        <div class="participant-name">{{ $participant->full_name }}</div>
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
                    {{ strtoupper(substr($participant->first_name, 0, 1) . substr($participant->last_name, 0, 1)) }}
                </div>
                <div class="participant-info">
                    <div class="participant-name">{{ $participant->full_name }}</div>
                    <div class="participant-meta">
                        <span class="level-badge">{{ $participant->level }}</span>
                        <span class="text-success">Одобрен</span>
                    </div>
                </div>
                <div class="participant-rating">{{ $participant->rating }}</div>
                @if($tournament->status === 'open')
                    <form action="{{ route('club.tournaments.participants.remove', [$tournament, $participant->id]) }}" method="POST" onsubmit="return confirm('Удалить участника?')">
                        @csrf
                        @method('DELETE')
                        <button class="btn-danger-custom btn-sm"><i class="bi bi-x"></i></button>
                    </form>
                @endif
            </div>
        @empty
            <div class="empty-state">
                <i class="bi bi-people"></i>
                <p>Пока нет участников</p>
            </div>
        @endforelse
    </div>
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
</style>

<script>
function toggleParticipants() {
    const content = document.getElementById('participantsContent');
    const icon = document.getElementById('toggleIcon');
    
    if (!icon) return; // Если турнир ещё не начался, иконки нет
    
    if (content.style.display === 'none') {
        content.style.display = 'block';
        icon.classList.add('rotated');
    } else {
        content.style.display = 'none';
        icon.classList.remove('rotated');
    }
}
</script>
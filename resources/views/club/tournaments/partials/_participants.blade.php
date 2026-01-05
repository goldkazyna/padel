<div class="section-header">
    <h5><i class="bi bi-people"></i> Участники ({{ $tournament->participants->count() }})</h5>
</div>

<div class="participants-list mb-4">
    @forelse($tournament->participants as $index => $participant)
        <div class="participant-row">
            <div class="participant-rank">{{ $index + 1 }}</div>
            <div class="participant-avatar">
                {{ strtoupper(substr($participant->first_name, 0, 1) . substr($participant->last_name, 0, 1)) }}
            </div>
            <div class="participant-info">
                <div class="participant-name">{{ $participant->full_name }}</div>
                <div class="participant-meta">
                    <span class="level-badge">{{ $participant->level }}</span>
                    <span class="text-secondary">{{ $participant->pivot->created_at->format('d.m.Y') }}</span>
                </div>
            </div>
            <div class="participant-rating">{{ $participant->rating }}</div>
            @if($tournament->status === 'open')
                <form action="{{ route('club.tournaments.participants.remove', [$tournament, $participant->id]) }}" 
                      method="POST" onsubmit="return confirm('Удалить участника?')">
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
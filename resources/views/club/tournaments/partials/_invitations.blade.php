{{-- Приглашения игроков на турнир (как в мобильной админке).
     Только для индивидуальных турниров (team — пары, приглашения не применимы). --}}
@if($tournament->type !== 'team')
@php
    $invitations = \App\Models\TournamentInvitation::where('tournament_id', $tournament->id)
        ->with('user')
        ->orderByDesc('created_at')
        ->get()
        ->filter(fn($inv) => $inv->user)
        ->values();
    $inviteLimit = 10;
    $canInvite = $tournament->status === 'open';
@endphp

<div class="invitations-block mt-4">
    <div class="add-participant-header" style="color:#60a5fa;">
        <i class="bi bi-send"></i>
        <span>Приглашения ({{ $invitations->count() }}/{{ $inviteLimit }})</span>
    </div>

    {{-- Список приглашённых --}}
    @if($invitations->count() > 0)
        <div class="participants-list mb-3">
            @foreach($invitations as $inv)
                @php
                    $u = $inv->user;
                    [$stLabel, $stClass] = match($inv->status) {
                        'accepted' => ['Принято', 'text-success'],
                        'declined' => ['Отклонено', 'text-danger'],
                        default    => ['Ожидает ответа', 'text-info'],
                    };
                @endphp
                <div class="participant-row">
                    <div class="participant-avatar">
                        {{ mb_strtoupper(mb_substr($u->first_name, 0, 1) . mb_substr($u->last_name, 0, 1)) }}
                    </div>
                    <div class="participant-info">
                        <div class="participant-name">{{ $u->name }}</div>
                        <small class="text-muted">@phoneFmt($u->phone)</small>
                        <div class="participant-meta">
                            <span class="level-badge">{{ $u->level }}</span>
                            <span class="{{ $stClass }}">{{ $stLabel }}</span>
                        </div>
                    </div>
                    <div class="participant-rating">{{ $u->rating }}</div>
                    <div class="participant-actions">
                        <form action="{{ route('club.tournaments.invitations.cancel', [$tournament, $inv->id]) }}" method="POST" class="d-inline" onsubmit="return confirm('Убрать приглашение?')">
                            @csrf
                            @method('DELETE')
                            <button class="btn-danger-custom btn-sm" title="Убрать"><i class="bi bi-x"></i></button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Форма приглашения --}}
    @if($canInvite && $invitations->count() < $inviteLimit)
        <div class="invite-section">
            <form action="{{ route('club.tournaments.invite', $tournament) }}" method="POST" class="add-participant-form">
                @csrf
                <div class="search-wrapper">
                    <input type="text"
                           class="form-control player-search-input"
                           data-target="inviteNew"
                           placeholder="Введите телефон или имя игрока..."
                           autocomplete="off">
                    <input type="hidden" name="user_id" id="inviteNewPlayerId">
                    <div class="search-results" id="inviteNewResults"></div>
                </div>
                <div class="selected-player mt-2" id="inviteNewSelected" style="display: none;"></div>
                <button type="submit" class="btn-primary-custom mt-3" style="background: rgba(59,130,246,0.15); color:#60a5fa; border-color: rgba(59,130,246,0.3);">
                    <i class="bi bi-send me-1"></i> Пригласить
                </button>
            </form>
            <small class="text-secondary d-block mt-2">Игроку придёт пуш-уведомление и приглашение в приложении — он сможет принять и записаться.</small>
        </div>
    @elseif(!$canInvite)
        <small class="text-secondary">Приглашать можно только пока турнир открыт для записи.</small>
    @endif
</div>

<style>
.invitations-block {
    background: rgba(59, 130, 246, 0.05);
    border: 1px dashed rgba(59, 130, 246, 0.3);
    border-radius: 12px;
    padding: 16px;
}
</style>
@endif

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
    $canInvite = $tournament->status === 'open';
    // Заготовка текста — та же, что уходит по умолчанию из сервиса.
    $inviteDefaults = app(\App\Services\TournamentInvitationService::class);
    $inviteTitle = old('invite_title', $inviteDefaults->defaultTitle());
    $inviteBody = old('invite_body', $inviteDefaults->defaultBody($tournament));
@endphp

<div class="invitations-block mt-4">
    <div class="add-participant-header" style="color:#60a5fa;">
        <i class="bi bi-send"></i>
        <span>Приглашения ({{ $invitations->count() }})</span>
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
    @if($canInvite)
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

                {{-- Текст приглашения: заготовка подставлена, организатор правит --}}
                <div class="invite-text mt-3">
                    <label class="invite-label" for="inviteTitle">Заголовок</label>
                    <input type="text" name="invite_title" id="inviteTitle" class="form-control invite-input"
                           maxlength="100" value="{{ $inviteTitle }}" required>
                    <div class="invite-counter" id="inviteTitleLeft"></div>

                    <label class="invite-label" for="inviteBody">Текст</label>
                    <textarea name="invite_body" id="inviteBody" class="form-control invite-input invite-area"
                              maxlength="250" required>{{ $inviteBody }}</textarea>
                    <div class="invite-counter" id="inviteBodyLeft"></div>

                    {{-- На телефоне пуш обрезается: длинный текст увидят не полностью --}}
                    <div class="invite-preview">
                        <div class="invite-preview-label">Как увидит игрок</div>
                        <div class="invite-phone">
                            <div class="invite-phone-app">Padel KZ · сейчас</div>
                            <div class="invite-phone-title" id="invitePreviewTitle"></div>
                            <div class="invite-phone-body" id="invitePreviewBody"></div>
                        </div>
                    </div>

                    <button type="button" class="invite-reset" onclick="resetInviteText()">
                        Вернуть заготовку
                    </button>
                </div>

                <button type="submit" class="btn-primary-custom mt-3" style="background: rgba(59,130,246,0.15); color:#60a5fa; border-color: rgba(59,130,246,0.3);">
                    <i class="bi bi-send me-1"></i> Пригласить
                </button>
            </form>
            <small class="text-secondary d-block mt-2">Игроку придёт пуш-уведомление и приглашение в приложении — он сможет принять и записаться.</small>
        </div>
    @else
        <small class="text-secondary">Приглашать можно только пока турнир открыт для записи.</small>
    @endif
</div>

<script>
(function () {
    var defaults = {
        title: @json($inviteDefaults->defaultTitle()),
        body: @json($inviteDefaults->defaultBody($tournament)),
    };

    function refresh() {
        var title = document.getElementById('inviteTitle');
        var body = document.getElementById('inviteBody');
        if (!title || !body) return;

        document.getElementById('invitePreviewTitle').textContent = title.value || '—';
        document.getElementById('invitePreviewBody').textContent = body.value || '—';
        document.getElementById('inviteTitleLeft').textContent =
            title.value.length + ' / ' + title.maxLength;
        document.getElementById('inviteBodyLeft').textContent =
            body.value.length + ' / ' + body.maxLength;
    }

    window.resetInviteText = function () {
        document.getElementById('inviteTitle').value = defaults.title;
        document.getElementById('inviteBody').value = defaults.body;
        refresh();
    };

    document.addEventListener('DOMContentLoaded', function () {
        ['inviteTitle', 'inviteBody'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('input', refresh);
        });
        refresh();
    });
})();
</script>

<style>
.invitations-block {
    background: rgba(59, 130, 246, 0.05);
    border: 1px dashed rgba(59, 130, 246, 0.3);
    border-radius: 12px;
    padding: 16px;
}
.invite-text {
    border-top: 1px dashed rgba(59, 130, 246, 0.25);
    padding-top: 14px;
}
.invite-label {
    display: block;
    margin-bottom: 5px;
    color: var(--text-secondary, #9aa0a6);
    font-size: 12px;
    font-weight: 600;
}
.invite-input { margin-bottom: 2px; }
.invite-area { min-height: 72px; resize: vertical; }
.invite-counter {
    margin-bottom: 12px;
    color: var(--text-secondary, #9aa0a6);
    font-size: 11px;
    text-align: right;
}
.invite-preview-label {
    margin-bottom: 6px;
    color: var(--text-secondary, #9aa0a6);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.invite-phone {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 10px 12px;
}
.invite-phone-app {
    color: var(--text-secondary, #9aa0a6);
    font-size: 11px;
    margin-bottom: 4px;
}
.invite-phone-title { font-weight: 600; margin-bottom: 2px; }
.invite-phone-body { font-size: 13px; line-height: 1.4; }
.invite-reset {
    margin-top: 10px;
    background: none;
    border: none;
    padding: 0;
    color: var(--text-secondary, #9aa0a6);
    font-size: 12px;
    cursor: pointer;
}
.invite-reset:hover { color: var(--text-primary, #fff); }
</style>
@endif

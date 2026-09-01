@php
    $hasGroups = $tournament->isAmericano() && $tournament->groups()->count() > 0;

    // Кто оплатил участие онлайн. Один платёж может закрывать двоих —
    // тот, кто платил, и записанный им друг.
    $paidOnline = [];
    foreach (\App\Models\TournamentPayment::where('tournament_id', $tournament->id)
        ->where('status', \App\Models\TournamentPayment::STATUS_PAID)->get() as $payment) {
        $perPlayer = $payment->players_count > 0 ? $payment->amount / $payment->players_count : $payment->amount;
        $paidOnline[$payment->user_id] = $perPlayer;
        if ($payment->friend_user_id) {
            $paidOnline[$payment->friend_user_id] = $perPlayer;
        }
    }

    // Оплатил и всё же отменился — клубу нужно вернуть деньги руками.
    $refundOwed = $paidOnline
        ? $tournament->participants()->wherePivot('status', 'cancelled')
            ->whereIn('users.id', array_keys($paidOnline))->get()
        : collect();

    $waitlistParticipants = $tournament->participants()
        ->wherePivot('status', 'waiting')
        ->orderBy('tournament_participants.created_at')
        ->get();
@endphp

<div class="section-header" style="cursor: pointer;" onclick="toggleParticipants()">
    <h5>
        <i class="bi bi-people"></i> 
        Участники ({{ $tournament->approvedParticipantsCount() }}/{{ $tournament->max_participants }})
        @if($tournament->pendingParticipantsCount() > 0)
            <span class="pending-badge">+{{ $tournament->pendingParticipantsCount() }} на модерации</span>
        @endif
        @if($waitlistParticipants->count() > 0)
            <span class="waitlist-badge">+{{ $waitlistParticipants->count() }} в листе ожидания</span>
        @endif
        @if($tournament->status === 'in_progress' || $tournament->status === 'completed' || $hasGroups)
            <i class="bi bi-chevron-down toggle-icon" id="toggleIcon"></i>
        @endif
    </h5>
    @if($tournament->status === 'open' && $tournament->pendingParticipantsCount() > 0 && !$hasGroups)
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
     style="{{ (in_array($tournament->status, ['in_progress', 'completed']) || $hasGroups) ? 'display: none;' : '' }}">

    {{-- Этап лиги: состав копируется из лиги один раз, при создании этапа.
         Кого добавили в лигу позже — досыпаем этой кнопкой. --}}
    @if($tournament->league)
        <div class="stage-league mb-3">
            <div class="stage-league-text">
                <i class="bi bi-trophy"></i>
                <span>
                    Этап {{ $tournament->league_stage }} лиги
                    <a href="{{ route('club.leagues.show', $tournament->league) }}">{{ $tournament->league->name }}</a>
                </span>
            </div>
            @if($tournament->status === 'open')
                <form method="POST" action="{{ route('club.tournaments.league.refill', $tournament) }}">
                    @csrf
                    <button class="btn-outline-custom btn-sm"
                            title="Добавить тех, кто есть в лиге, но не в этом этапе">
                        <i class="bi bi-arrow-repeat"></i> Обновить состав из лиги
                    </button>
                </form>
            @endif
        </div>
    @endif

    {{-- Оплатили онлайн и отменились: деньги вернуть может только клуб,
         поэтому такие записи не должны потеряться из виду. --}}
    @if($refundOwed->isNotEmpty())
        <div class="refund-alert mb-3">
            <i class="bi bi-arrow-counterclockwise"></i>
            <div>
                <b>Оплатили онлайн, но отменили запись — нужен возврат</b>
                <div class="refund-list">
                    @foreach($refundOwed as $player)
                        <span>{{ $player->name }} · @phoneFmt($player->phone) ·
                            {{ number_format($paidOnline[$player->id], 0, ',', ' ') }} ₸</span>
                    @endforeach
                </div>
                <small>Возврат делается в личном кабинете Plexy — CRM деньгами не распоряжается.</small>
            </div>
        </div>
    @endif

    {{-- Предупреждение о блокировке --}}
	@if($hasGroups && $tournament->status === 'open')
		<div class="ge-alert ge-alert-success mb-3">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width: 20px; height: 20px; flex-shrink: 0;">
				<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
				<polyline points="22 4 12 14.01 9 11.01"/>
			</svg>
			<span>Группы сформированы. Редактирование участников заблокировано. Используйте <strong>Редактор групп</strong> ниже.</span>
		</div>
	@endif

    {{-- Пары записываются сами: заявка приходит парой, а не игроком, и живёт
         в командах турнира. Список участников заполнится только при старте,
         поэтому здесь показываем пары — иначе организатору нечего одобрять. --}}
    @if(!$tournament->usesSoloRegistration())
        @php
            $pendingPairs = $tournament->teams()->where('status', 'pending')
                ->with(['player1', 'player2'])->orderBy('created_at')->get();
            $approvedPairs = $tournament->teams()->where('status', 'approved')
                ->with(['player1', 'player2'])->orderByDesc('rating_avg')->get();
        @endphp

        @if($pendingPairs->count() > 0)
        <div class="pending-section mb-4">
            <div class="pending-header">
                <i class="bi bi-hourglass-split text-warning"></i>
                <span>Пары на модерации ({{ $pendingPairs->count() }})</span>
            </div>
            <div class="participants-list">
                @foreach($pendingPairs as $pair)
                    <div class="participant-row pending">
                        <div class="participant-status-indicator pending">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div class="participant-info">
                            <div class="participant-name">
                                {{ $pair->player1->name ?? '—' }} / {{ $pair->player2->name ?? '—' }}
                            </div>
                            <small class="text-muted">
                                @phoneFmt($pair->player1->phone ?? '') / @phoneFmt($pair->player2->phone ?? '')
                            </small>
                            <div class="participant-meta">
                                <span class="text-warning">На модерации</span>
                            </div>
                        </div>
                        <div class="participant-rating">{{ $pair->rating_avg }}</div>
                        @if($tournament->status === 'open')
                            <div class="participant-actions">
                                <form action="{{ route('club.tournaments.approveTeam', [$tournament, $pair]) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn-success-custom btn-sm" title="Одобрить">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <form action="{{ route('club.tournaments.rejectTeam', [$tournament, $pair]) }}" method="POST" class="d-inline" onsubmit="return confirm('Отклонить заявку пары?')">
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

        <div class="participants-list">
            @forelse($approvedPairs as $index => $pair)
                <div class="participant-row approved">
                    <div class="participant-status-indicator approved">
                        <i class="bi bi-check"></i>
                    </div>
                    <div class="participant-rank">{{ $index + 1 }}</div>
                    <div class="participant-info">
                        <div class="participant-name">
                            {{ $pair->player1->name ?? '—' }} / {{ $pair->player2->name ?? '—' }}
                        </div>
                        <small class="text-muted">
                            @phoneFmt($pair->player1->phone ?? '') / @phoneFmt($pair->player2->phone ?? '')
                        </small>
                        <div class="participant-meta">
                            <span class="text-success">Пара записана</span>
                        </div>
                    </div>
                    <div class="participant-rating">{{ $pair->rating_avg }}</div>
                    @if($tournament->status === 'open')
                        <div class="participant-actions">
                            <form action="{{ route('club.tournaments.rejectTeam', [$tournament, $pair]) }}" method="POST" class="d-inline" onsubmit="return confirm('Убрать пару из турнира?')">
                                @csrf
                                <button class="btn-danger-custom btn-sm" title="Убрать"><i class="bi bi-x"></i></button>
                            </form>
                        </div>
                    @endif
                </div>
            @empty
                <div class="empty-participants">
                    <i class="bi bi-people"></i>
                    <p>Пока нет записавшихся пар</p>
                </div>
            @endforelse
        </div>

        {{--
            Записанные, но не попавшие ни в одну пару.

            Без этого блока их не видно и не убрать: список участников для
            парных турниров не показывается, а старт с непарными не пустит.
        --}}
        @php
            $pairedIds = $approvedPairs->flatMap(fn ($p) => [$p->player1_id, $p->player2_id])
                ->merge($tournament->teams()->pluck('player1_id'))
                ->merge($tournament->teams()->pluck('player2_id'))
                ->unique();
            $unpaired = $tournament->approvedParticipants->reject(fn ($u) => $pairedIds->contains($u->id));
        @endphp
        @if($unpaired->isNotEmpty())
            <div class="pair-add-section mt-4">
                <div class="add-participant-header">
                    <i class="bi bi-person-exclamation"></i>
                    <span>Без пары</span>
                    <span class="pair-rest-badge">{{ $unpaired->count() }}</span>
                </div>
                <div class="pair-list">
                    @foreach($unpaired as $participant)
                        <div class="pair-row">
                            <div class="pair-row-names">
                                {{ $participant->name }}
                                <small class="text-muted d-block">@phoneFmt($participant->phone)</small>
                            </div>
                            @if($tournament->status === 'open')
                                <form action="{{ route('club.tournaments.participants.remove', [$tournament, $participant->id]) }}"
                                      method="POST" class="d-inline"
                                      onsubmit="return confirm('Убрать из турнира?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn-danger-custom btn-sm" title="Убрать из турнира">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="text-secondary small">
                    Пока эти игроки не в парах, турнир не стартует: по кортам раскладываются пары.
                    Соберите им пару выше или уберите из турнира.
                </div>
            </div>
        @endif

        {{-- Организатор может завести пару сам, не дожидаясь записи --}}
        @if($tournament->status === 'open')
            <div class="pair-add-section mt-4">
                <div class="add-participant-header">
                    <i class="bi bi-person-plus"></i>
                    <span>Добавить пару</span>
                </div>
                @include('club.tournaments.partials._pair_add_form', [
                    'action' => route('club.tournaments.pairs.add', $tournament),
                ])
            </div>
        @endif
    @endif

    {{-- Заявки на модерации --}}
    @if($tournament->usesSoloRegistration() && $tournament->pendingParticipantsCount() > 0)
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
                    @if($participant->avatar)
                        <img class="participant-avatar participant-avatar-img"
                             src="{{ $participant->avatar }}" alt="{{ $participant->name }}" loading="lazy">
                    @else
                        <div class="participant-avatar">
                            {{ mb_strtoupper(mb_substr($participant->first_name, 0, 1) . mb_substr($participant->last_name, 0, 1)) }}
                        </div>
                    @endif
                    <div class="participant-info">
                        <div class="participant-name">
                            {{ $participant->name }}
                            @if($participant->level_verified)
                                <i class="bi bi-patch-check-fill verified-tick" title="Уровень подтверждён"></i>
                            @endif
                        </div>
                        <small class="text-muted">@phoneFmt($participant->phone)</small>
                        <div class="participant-meta">
                            <span class="level-badge">{{ $participant->level }}</span>
                            @if($participant->pivot->moderation_deadline)
                                <span class="text-warning">
                                    <i class="bi bi-hourglass-split"></i>
                                    оплата:
                                    <span class="mod-countdown fw-bold" data-deadline="{{ \Carbon\Carbon::parse($participant->pivot->moderation_deadline)->toIso8601String() }}">…</span>
                                </span>
                            @else
                                <span class="text-warning">На модерации</span>
                            @endif
                        </div>
                    </div>
                    <div class="participant-rating">{{ $participant->rating }}</div>
                    @if($tournament->status === 'open' && !$hasGroups)
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
                            @include('club.tournaments.partials._participant_move_menu', ['current' => 'pending'])
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif
	
    {{-- Одобренные участники --}}
    @if($tournament->usesSoloRegistration())
    <div class="participants-list">
        @forelse($tournament->approvedParticipants as $index => $participant)
            <div class="participant-row approved">
                {{-- Отметка «пришёл»: сохраняется сразу, переживает перезагрузку --}}
                <label class="attend-box" title="Отметить, что игрок пришёл">
                    <input type="checkbox" class="attend-check"
                           data-url="{{ route('club.tournaments.participants.attendance', [$tournament, $participant->id]) }}"
                           {{ $participant->pivot->attended_at ? 'checked' : '' }}>
                    <span></span>
                </label>
                <div class="participant-rank">{{ $index + 1 }}</div>
                @if($participant->avatar)
                    <img class="participant-avatar participant-avatar-img"
                         src="{{ $participant->avatar }}" alt="{{ $participant->name }}" loading="lazy">
                @else
                    <div class="participant-avatar">
                        {{ mb_strtoupper(mb_substr($participant->first_name, 0, 1) . mb_substr($participant->last_name, 0, 1)) }}
                    </div>
                @endif
                <div class="participant-info">
                    <div class="participant-name">
                        {{ $participant->name }}
                        @if($participant->level_verified)
                            <i class="bi bi-patch-check-fill verified-tick" title="Уровень подтверждён"></i>
                        @endif
                    </div>
                    <small class="text-muted">@phoneFmt($participant->phone)</small>
                    <div class="participant-meta">
                        <span class="level-badge">{{ $participant->level }}</span>
                        <span class="text-success">Одобрен</span>
                        @isset($paidOnline[$participant->id])
                            <span class="paid-badge" title="Участие оплачено онлайн через Plexy">
                                <i class="bi bi-credit-card-2-front"></i>
                                оплачено {{ number_format($paidOnline[$participant->id], 0, ',', ' ') }} ₸
                            </span>
                        @endisset
                    </div>
                </div>
                <div class="participant-rating">{{ $participant->rating }}</div>
                @if($tournament->status === 'open' && !$hasGroups)
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
                        @include('club.tournaments.partials._participant_move_menu', ['current' => 'registered'])
                    </div>
                @endif
            </div>

            {{-- Модалка замены участника --}}
            @if($tournament->status === 'open' && !$hasGroups)
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
                                            <div class="text-secondary small">@phone($participant->phone) • Рейтинг: {{ $participant->rating }}</div>
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
    @endif

    {{-- Лист ожидания --}}
    @if($waitlistParticipants->count() > 0)
    <div class="waitlist-section mb-4 mt-4">
        <div class="waitlist-header">
            <i class="bi bi-hourglass-split"></i>
            <span>Лист ожидания ({{ $waitlistParticipants->count() }}{{ $tournament->waitlist_size ? '/'.$tournament->waitlist_size : '' }})</span>
        </div>
        <div class="participants-list">
            @foreach($waitlistParticipants as $i => $participant)
                <div class="participant-row waiting">
                    <div class="participant-status-indicator waiting">
                        <i class="bi bi-hourglass"></i>
                    </div>
                    <div class="participant-rank">{{ $i + 1 }}</div>
                    @if($participant->avatar)
                        <img class="participant-avatar participant-avatar-img"
                             src="{{ $participant->avatar }}" alt="{{ $participant->name }}" loading="lazy">
                    @else
                        <div class="participant-avatar">
                            {{ mb_strtoupper(mb_substr($participant->first_name, 0, 1) . mb_substr($participant->last_name, 0, 1)) }}
                        </div>
                    @endif
                    <div class="participant-info">
                        <div class="participant-name">
                            {{ $participant->name }}
                            @if($participant->level_verified)
                                <i class="bi bi-patch-check-fill verified-tick" title="Уровень подтверждён"></i>
                            @endif
                        </div>
                        <small class="text-muted">@phoneFmt($participant->phone)</small>
                        <div class="participant-meta">
                            <span class="level-badge">{{ $participant->level }}</span>
                            <span class="text-info">В очереди</span>
                        </div>
                    </div>
                    <div class="participant-rating">{{ $participant->rating }}</div>
                    @if($tournament->status === 'open' && !$hasGroups)
                        <div class="participant-actions">
                            <form action="{{ route('club.tournaments.participants.remove', [$tournament, $participant->id]) }}" method="POST" class="d-inline" onsubmit="return confirm('Удалить из листа ожидания?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn-danger-custom btn-sm" title="Удалить"><i class="bi bi-x"></i></button>
                            </form>
                            @include('club.tournaments.partials._participant_move_menu', ['current' => 'waiting'])
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Фиксированные пары JPI: организатор заводит пару целиком --}}
    @if($tournament->isPairedJustPadelIt() && !$tournament->isSelfPairing())
        @include('club.tournaments.partials._jpi_pairs')
    @endif

    {{-- Форма добавления участника: только при записи поодиночке --}}
    @if($tournament->usesSoloRegistration() && $tournament->status === 'open' && $tournament->approvedParticipantsCount() < $tournament->max_participants && !$hasGroups)
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

    {{-- Приглашения игроков (как в мобильной админке) --}}
    @include('club.tournaments.partials._invitations')
</div>

<style>
/* Отметка «пришёл» */
.attend-box { display: inline-flex; align-items: center; cursor: pointer; flex: none; }
.attend-box input { position: absolute; opacity: 0; width: 0; height: 0; }
.attend-box span {
    width: 22px; height: 22px; border-radius: 7px;
    border: 2px solid var(--border-light, #3f3f46);
    display: grid; place-items: center; transition: background .15s, border-color .15s;
}
.attend-box span::after {
    content: ''; width: 10px; height: 6px;
    border-left: 2px solid #08130c; border-bottom: 2px solid #08130c;
    transform: rotate(-45deg) scale(0); transition: transform .15s;
}
.attend-box input:checked + span { background: #22c55e; border-color: #22c55e; }
.attend-box input:checked + span::after { transform: rotate(-45deg) scale(1); }
.attend-box.is-saving span { opacity: .5; }
.participant-row.attended { background: rgba(34,197,94,.06); }

/* Аватар картинкой и синяя галочка верификации */
.participant-avatar-img { object-fit: cover; }
.verified-tick { color: #3b82f6; font-size: 13px; margin-left: 5px; vertical-align: baseline; }

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

/* Оплата участия онлайн: пометка у игрока и напоминание о возврате. */
.paid-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: rgba(37, 211, 102, 0.14);
    color: #25d366;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 9px;
    border-radius: 20px;
}

.refund-alert {
    display: flex;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 12px;
    background: rgba(234, 179, 8, 0.08);
    border: 1px solid rgba(234, 179, 8, 0.3);
    color: #e4e4e7;
    font-size: 0.9rem;
}
.refund-alert > i { color: #eab308; font-size: 1.15rem; }
.refund-alert b { display: block; margin-bottom: 6px; }
.refund-alert small { color: #a1a1aa; font-size: 0.78rem; }
.refund-list {
    display: flex;
    flex-direction: column;
    gap: 3px;
    margin-bottom: 6px;
    color: #a1a1aa;
    font-size: 0.85rem;
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

.waitlist-badge {
    background: rgba(59, 130, 246, 0.18);
    color: #60a5fa;
    font-size: 0.75rem;
    padding: 4px 10px;
    border-radius: 20px;
    margin-left: 8px;
    font-weight: 600;
}

.waitlist-section {
    background: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 12px;
    padding: 16px;
}

.waitlist-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    margin-bottom: 12px;
    color: #60a5fa;
}

.participant-status-indicator.waiting {
    background: rgba(59, 130, 246, 0.2);
    color: #60a5fa;
}

.participant-row.waiting {
    border-left: 3px solid #60a5fa;
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
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    cursor: pointer;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    transition: background 0.2s;
}

.search-result-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    flex: 0 0 auto;
}

.search-result-avatar-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
    font-size: 13px;
    font-weight: 700;
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
	color:#ffffff !important;
	background-color:#16a34a;
	border-radius:5px;
	padding: 3px;
    font-size: 12px;
}
</style>

<script>
// Отметка «пришёл» сохраняется сразу — организатор перезагрузит список,
// и она останется.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.attend-check').forEach(function (input) {
        var box = input.closest('.attend-box');
        var row = input.closest('.participant-row');

        function paint() {
            if (row) row.classList.toggle('attended', input.checked);
        }
        paint();

        input.addEventListener('change', function () {
            box.classList.add('is-saving');
            paint();

            fetch(input.dataset.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({ attended: input.checked })
            })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
                .catch(function () {
                    // Не сохранилось — возвращаем галочку, чтобы не обманывать.
                    input.checked = !input.checked;
                    paint();
                    alert('Не удалось сохранить отметку');
                })
                .finally(function () { box.classList.remove('is-saving'); });
        });
    });
});
</script>

<script>
    // Аватар в результатах поиска: фото, если есть, иначе инициал.
    function playerAvatar(player) {
        if (player.avatar) {
            return `<img class="search-result-avatar" src="${player.avatar}" alt="">`;
        }
        const letter = (player.name || '?').trim().charAt(0).toUpperCase();
        return `<div class="search-result-avatar search-result-avatar-empty">${letter}</div>`;
    }
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
                // Форма пары ищет иначе: там не исключают уже записанных.
                const mode = input.dataset.mode ? `&for=${input.dataset.mode}` : '';
                fetch(`{{ route('club.tournaments.searchPlayers', $tournament) }}?q=${encodeURIComponent(query)}${mode}`)
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
                                    ${playerAvatar(player)}
                                    <div>
                                        <div class="search-result-name">${player.name}</div>
                                        <div class="search-result-meta">${player.phone} • Уровень: ${player.level} • Рейтинг: ${player.rating}</div>
                                    </div>
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
{{-- Live-отсчёт таймера модерации --}}
<script>
(function () {
    function fmt(ms) {
        if (ms <= 0) return 'время вышло';
        var s = Math.floor(ms / 1000);
        var d = Math.floor(s / 86400); s %= 86400;
        var h = Math.floor(s / 3600); s %= 3600;
        var m = Math.floor(s / 60); s %= 60;
        if (d > 0) return d + 'д ' + h + 'ч ' + m + 'м';
        if (h > 0) return h + 'ч ' + m + 'м ' + s + 'с';
        return m + 'м ' + s + 'с';
    }
    function tick() {
        document.querySelectorAll('.mod-countdown').forEach(function (el) {
            var dl = new Date(el.dataset.deadline).getTime();
            var left = dl - Date.now();
            el.textContent = fmt(left);
            el.style.color = left <= 0 ? '#f0554d' : (left < 3 * 3600 * 1000 ? '#eab34e' : '');
        });
    }
    tick();
    setInterval(tick, 1000);
})();
</script>

<style>
.stage-league {
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    background: rgba(34, 197, 94, .07); border: 1px solid rgba(34, 197, 94, .22);
    border-radius: 12px; padding: 10px 14px;
}
.stage-league-text { display: flex; align-items: center; gap: 8px; color: #a1a1aa; font-size: 13.5px; }
.stage-league-text i { color: #22c55e; }
.stage-league-text a { color: #22c55e; text-decoration: none; font-weight: 600; }
.stage-league-text a:hover { text-decoration: underline; }
</style>

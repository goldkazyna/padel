{{-- Редактор групп (до начала турнира) --}}
@if($tournament->type === 'americano' && $tournament->status === 'open')
    
    @php
        $groups = $tournament->groups()->with('players')->get();
        $hasGroups = $groups->count() > 0;
        
        // Игроки в группах
        $assignedPlayerIds = $groups->pluck('players')->flatten()->pluck('id')->toArray();
        
        // Свободные игроки (зарегистрированы, но не в группах)
        $unassignedPlayers = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->whereNotIn('users.id', $assignedPlayerIds)
            ->get();
        
        $approvedCount = $tournament->approvedParticipantsCount();
        $maxParticipants = $tournament->max_participants;
        $canGenerateGroups = $approvedCount === $maxParticipants;
    @endphp

    @if(!$hasGroups)
        {{-- Группы ещё не сформированы --}}
        <section class="groups-editor mb-4">
            <div class="card-dark">
                <div class="card-header">
                    <h5><i class="bi bi-grid-3x3-gap"></i> Формирование групп</h5>
                </div>
                <div class="card-body text-center py-5">
                    <i class="bi bi-diagram-3" style="font-size: 4rem; color: var(--text-muted);"></i>
                    <p class="mt-3 text-secondary">Группы ещё не сформированы</p>
                    
                    @if($canGenerateGroups)
                        <p class="text-muted">Все участники одобрены. Можете сформировать группы.</p>
                        <form action="{{ route('club.tournaments.generateGroups', $tournament) }}" method="POST" class="mt-4">
                            @csrf
                            <button type="submit" class="btn-primary-custom">
                                <i class="bi bi-shuffle"></i> Сформировать группы
                            </button>
                        </form>
                    @else
                        <p class="text-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            Одобрено {{ $approvedCount }}/{{ $maxParticipants }} участников. 
                            Одобрите всех для формирования групп.
                        </p>
                        <button type="button" class="btn-outline-custom mt-4" disabled>
                            <i class="bi bi-shuffle"></i> Сформировать группы
                        </button>
                    @endif
                </div>
            </div>
        </section>
    @else
        {{-- Группы сформированы — показываем редактор --}}
        <div class="ge-container">
            <!-- Header -->
            <header class="ge-header">
                <div class="ge-title-block">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7" rx="1"/>
                        <rect x="14" y="3" width="7" height="7" rx="1"/>
                        <rect x="3" y="14" width="7" height="7" rx="1"/>
                        <rect x="14" y="14" width="7" height="7" rx="1"/>
                    </svg>
                    <h1 class="ge-title">Редактор групп</h1>
                </div>
                <form action="{{ route('club.tournaments.resetGroups', $tournament) }}" method="POST" 
                      onsubmit="return confirm('Сбросить все группы? Это действие нельзя отменить.')">
                    @csrf
                    <button type="submit" class="ge-reset-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                            <path d="M3 3v5h5"/>
                        </svg>
                        Сбросить
                    </button>
                </form>
            </header>

            <!-- Unassigned Players -->
            @if($unassignedPlayers->count() > 0)
            <div class="ge-unassigned">
                <div class="ge-unassigned-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <span>Не распределены ({{ $unassignedPlayers->count() }})</span>
                </div>
                <div class="ge-unassigned-list">
                    @foreach($unassignedPlayers as $player)
                    <div class="ge-unassigned-item" data-player-id="{{ $player->id }}">
                        <span class="ge-item-name">{{ $player->name }}</span>
                        <span class="ge-item-stats">Ур. {{ $player->level }} • {{ $player->rating }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            <!-- Groups Grid -->
            <div class="ge-groups-grid">
                @foreach($groups as $group)
                @php
                    $playersPerGroup = $tournament->max_participants / $tournament->groups_count;
                    $isGroupFull = $group->players->count() >= $playersPerGroup;
                    $countClass = $group->players->count() < $playersPerGroup ? 'warning' : '';
                @endphp
                <div class="ge-group-card" data-group-id="{{ $group->id }}">
                    <div class="ge-group-header">
                        <span class="ge-group-name">{{ $group->name }}</span>
                        <span class="ge-group-count {{ $countClass }}">{{ $group->players->count() }} чел.</span>
                    </div>
                    <div class="ge-players-list">
                        @foreach($group->players as $player)
                        <div class="ge-player-item">
                            <div class="ge-player-info">
                                <div class="ge-player-avatar">
                                    {{ mb_strtoupper(mb_substr($player->first_name, 0, 1) . mb_substr($player->last_name, 0, 1)) }}
                                </div>
                                <div class="ge-player-details">
                                    <span class="ge-player-name">{{ $player->name }}</span>
                                    <div class="ge-player-stats">
                                        <span class="ge-player-level">Ур. {{ $player->level }}</span>
                                        <span>•</span>
                                        <span class="ge-player-rating">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                                            {{ $player->rating }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="ge-player-remove" onclick="removeFromGroup({{ $tournament->id }}, {{ $group->id }}, {{ $player->id }})">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        @endforeach
                    </div>
                    
                    @if($unassignedPlayers->count() > 0)
                    <div class="ge-add-player">
                        <select class="ge-add-input" id="add-player-{{ $group->id }}">
                            <option value="">Добавить игрока...</option>
                            @foreach($unassignedPlayers as $player)
                                <option value="{{ $player->id }}">{{ $player->name }} (Ур. {{ $player->level }})</option>
                            @endforeach
                        </select>
                        <button type="button" class="ge-add-btn" onclick="addToGroup({{ $tournament->id }}, {{ $group->id }})">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                        </button>
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
            
            {{-- Статус --}}
            @if($unassignedPlayers->count() > 0)
                <div class="ge-alert ge-alert-warning">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <span>Есть нераспределённые игроки. Распределите всех перед началом турнира.</span>
                </div>
            @else
                <div class="ge-alert ge-alert-success">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <span>Все игроки распределены по группам. Можете начинать турнир!</span>
                </div>
            @endif
        </div>
    @endif
@endif
 <style>
        :root {
            --ge-bg: #0a0a0b;
            --ge-bg-secondary: #111113;
            --ge-card: #161619;
            --ge-card-hover: #1c1c20;
            --ge-accent: #22c55e;
            --ge-accent-dark: #16a34a;
            --ge-accent-glow: rgba(34, 197, 94, 0.12);
            --ge-text: #f4f4f5;
            --ge-text-dim: #a1a1aa;
            --ge-text-muted: #71717a;
            --ge-border: #27272a;
            --ge-border-light: #3f3f46;
            --ge-red: #ef4444;
            --ge-red-dim: rgba(239, 68, 68, 0.15);
            --ge-yellow: #facc15;
            --ge-yellow-dim: rgba(250, 204, 21, 0.1);
        }


        .ge-container {
            width: 100%;
            padding: 20px 0px;
        }
		ge-alert {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    border-radius: 10px;
    font-size: 14px;
}

.ge-alert svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}

.ge-alert-warning {
    background: rgba(234, 179, 8, 0.1);
    border: 1px solid rgba(234, 179, 8, 0.3);
    color: #eab308;
	padding:20px;
	border-radius:10px;
}

.ge-alert-success {
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    color: #22c55e;
		padding:20px;
	border-radius:10px;
}
        /* Header */
        .ge-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
        }

        .ge-title-block {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .ge-title-block svg {
            width: 26px;
            height: 26px;
            color: var(--ge-accent);
        }

        .ge-title {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .ge-reset-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            background: transparent;
            border: 1px solid var(--ge-border);
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            color: var(--ge-text-dim);
            cursor: pointer;
            transition: all 0.2s;
        }

        .ge-reset-btn:hover {
            border-color: var(--ge-red);
            color: var(--ge-red);
            background: var(--ge-red-dim);
        }

        .ge-reset-btn svg {
            width: 18px;
            height: 18px;
        }

        /* Unassigned Section */
        .ge-unassigned {
            margin-bottom: 28px;
        }

        .ge-unassigned-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }

        .ge-unassigned-header svg {
            width: 18px;
            height: 18px;
            color: var(--ge-yellow);
        }

        .ge-unassigned-header span {
            font-size: 14px;
            font-weight: 600;
            color: var(--ge-yellow);
        }

        .ge-unassigned-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .ge-unassigned-item {
            background: var(--ge-card);
            border: 1px solid var(--ge-yellow);
            border-radius: 10px;
            padding: 12px 16px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .ge-unassigned-item .ge-item-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--ge-text);
        }

        .ge-unassigned-item .ge-item-stats {
            font-size: 12px;
            color: var(--ge-text-muted);
        }

        /* Success Message */
        .ge-success-message {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--ge-accent-glow);
            border: 1px solid var(--ge-accent);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 28px;
        }

        .ge-success-message svg {
            width: 22px;
            height: 22px;
            color: var(--ge-accent);
            flex-shrink: 0;
        }

        .ge-success-message span {
            font-size: 15px;
            font-weight: 600;
            color: var(--ge-accent);
        }

        /* Groups Grid */
        .ge-groups-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 24px;
        }

        /* Group Card */
        .ge-group-card {
            background: var(--ge-bg-secondary);
            border: 1px solid var(--ge-border);
            border-radius: 16px;
            overflow: hidden;
        }

        .ge-group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 20px;
            background: var(--ge-card);
            border-bottom: 1px solid var(--ge-border);
        }

        .ge-group-name {
            font-size: 16px;
            font-weight: 700;
        }

        .ge-group-count {
            background: var(--ge-accent);
            color: var(--ge-bg);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
        }

        .ge-group-count.warning {
            background: var(--ge-yellow);
        }

        /* Players List */
        .ge-players-list {
            padding: 8px;
        }

        .ge-player-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--ge-card);
            border: 1px solid var(--ge-border);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 8px;
            transition: all 0.2s;
        }

        .ge-player-item:last-child {
            margin-bottom: 0;
        }

        .ge-player-item:hover {
            border-color: var(--ge-border-light);
            background: var(--ge-card-hover);
        }

        .ge-player-info {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1;
        }

        .ge-player-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--ge-accent), var(--ge-accent-dark));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            color: var(--ge-bg);
            flex-shrink: 0;
        }

        .ge-player-details {
            display: flex;
            flex-direction: column;
        }

        .ge-player-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--ge-text);
            margin-bottom: 2px;
        }

        .ge-player-stats {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--ge-text-muted);
        }

        .ge-player-level {
            color: var(--ge-accent);
            font-weight: 600;
        }

        .ge-player-rating {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .ge-player-rating svg {
            width: 12px;
            height: 12px;
            color: var(--ge-yellow);
        }

        .ge-player-remove {
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: 1px solid var(--ge-red);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .ge-player-remove svg {
            width: 16px;
            height: 16px;
            color: var(--ge-red);
            transition: color 0.2s;
        }

        .ge-player-remove:hover {
            background: var(--ge-red);
        }

        .ge-player-remove:hover svg {
            color: white;
        }

        /* Add Player Input */
        .ge-add-player {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            border-top: 1px solid var(--ge-border);
        }

        .ge-add-input {
            flex: 1;
            background: var(--ge-card);
            border: 1px solid var(--ge-border);
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 500;
            color: var(--ge-text);
            transition: all 0.2s;
        }

        .ge-add-input::placeholder {
            color: var(--ge-text-muted);
        }

        .ge-add-input:focus {
            outline: none;
            border-color: var(--ge-accent);
        }

        .ge-add-btn {
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--ge-accent);
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .ge-add-btn svg {
            width: 20px;
            height: 20px;
            color: var(--ge-bg);
        }

        .ge-add-btn:hover {
            background: var(--ge-accent-dark);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .ge-container {
                padding: 24px 20px;
            }

            .ge-groups-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@push('scripts')
<script>
    async function removeFromGroup(tournamentId, groupId, playerId) {
        if (!confirm('Удалить игрока из группы?')) return;
        
        try {
            const response = await fetch(`/club/tournaments/${tournamentId}/groups/${groupId}/players/${playerId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Content-Type': 'application/json',
                }
            });
            
            const result = await response.json();
            
            if (result.success) {
                location.reload();
            } else {
                alert(result.error || 'Ошибка');
            }
        } catch (error) {
            alert('Ошибка: ' + error.message);
        }
    }
    
    async function addToGroup(tournamentId, groupId) {
        const select = document.getElementById(`add-player-${groupId}`);
        const playerId = select.value;
        
        if (!playerId) {
            alert('Выберите игрока');
            return;
        }
        
        try {
            const response = await fetch(`/club/tournaments/${tournamentId}/groups/${groupId}/players`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ player_id: playerId })
            });
            
            const result = await response.json();
            
            if (result.success) {
                location.reload();
            } else {
                alert(result.error || 'Ошибка');
            }
        } catch (error) {
            alert('Ошибка: ' + error.message);
        }
    }
</script>
@endpush
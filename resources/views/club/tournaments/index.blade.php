@extends('layouts.app')

@section('title', 'Управление турнирами')

@section('content')
<div class="page-header">
    <div>
        <h2>Турниры {{ $club ? '— ' . $club->name : '' }}</h2>
        <p>Управление турнирами клуба</p>
    </div>
    @if(auth()->user()->isSuperAdmin() || ($club && auth()->user()->hasTournamentsFullAccess($club)))
    <a href="{{ route('club.tournaments.create') }}" class="btn-primary-custom">
        <i class="bi bi-plus-circle"></i>
        <span>Создать турнир</span>
    </a>
    @endif
</div>

<div class="tournaments-list">
    @if($groupedTournaments->isEmpty())
        <div class="card-dark">
            <div class="card-body text-center py-5">
                <i class="bi bi-trophy fs-1 text-secondary mb-3"></i>
                <p class="text-secondary mb-3">Турниров пока нет</p>
                @if(auth()->user()->isSuperAdmin() || ($club && auth()->user()->hasTournamentsFullAccess($club)))
                <a href="{{ route('club.tournaments.create') }}" class="btn-primary-custom">
                    <i class="bi bi-plus-circle"></i> Создать первый турнир
                </a>
                @endif
            </div>
        </div>
    @else
        @foreach($groupedTournaments as $monthKey => $tournaments)
            @php
                $isCurrentMonth = $monthKey === now()->format('Y-m');
            @endphp
            <div class="month-group">
                <div class="month-header {{ $isCurrentMonth ? 'open' : '' }}" onclick="toggleMonth(this)">
                    <div class="month-header-left">
                        <i class="bi bi-chevron-right month-arrow"></i>
                        <span class="month-title">{{ $monthKey === 'no-date' ? 'Без даты' : \Carbon\Carbon::parse($monthKey . '-01')->translatedFormat('F Y') }}</span>
                        <span class="month-count">{{ $tournaments->count() }}</span>
                    </div>
                </div>
                <div class="month-body" @if(!$isCurrentMonth) style="display: none;" @endif>
                    @foreach($tournaments as $tournament)
                        <div class="tournament-row {{ $tournament->status }}">
                            <div class="tournament-date-box">
                                <div class="tournament-day">{{ $tournament->start_date?->format('d') ?? '—' }}</div>
                                <div class="tournament-month">{{ $tournament->start_date?->translatedFormat('M') ?? '' }}</div>
                            </div>
                            <div class="tournament-info">
                                <div class="tournament-name">
                                    {{ $tournament->name }}
                                    @if($tournament->verified_only)
                                        <i class="bi bi-patch-check-fill text-success" title="Только для верифицированных игроков"></i>
                                    @endif
                                </div>
                                <div class="tournament-meta">
                                    @if(!$club)
                                        <span><i class="bi bi-building"></i> {{ $tournament->club->name }}</span>
                                    @endif
                                    @if($tournament->start_date)
                                    <span><i class="bi bi-clock"></i> {{ $tournament->start_date->format('H:i') }}</span>
                                    @endif
                                    <span><i class="bi bi-bar-chart"></i> {{ $tournament->min_level }}–{{ $tournament->max_level }}</span>
                                    @if($tournament->verified_only)
                                        <span class="text-success"><i class="bi bi-patch-check"></i> Только верифицированные</span>
                                    @endif
                                </div>
                            </div>
                            <div class="tournament-stats d-none d-lg-flex">
                                <div class="tournament-stat">
                                    <div class="tournament-stat-value">{{ $tournament->totalParticipantsCount() }}/{{ $tournament->max_participants }}</div>
                                    <div class="tournament-stat-label">Участников</div>
                                </div>
                                <div class="tournament-stat">
                                    <div class="tournament-stat-value">{{ $tournament->matches()->count() }}</div>
                                    <div class="tournament-stat-label">Матчей</div>
                                </div>
                            </div>
                            <div class="tournament-status">
                                <span class="badge-{{ $tournament->status_color }}-custom">{{ $tournament->status_name }}</span>
                            </div>
                            <div class="tournament-actions">
                                <a href="{{ route('club.tournaments.show', $tournament) }}" class="btn-outline-custom btn-sm" title="Просмотр">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @if(auth()->user()->isSuperAdmin() || ($club && auth()->user()->hasTournamentsFullAccess($club)))
                                <a href="{{ route('club.tournaments.edit', $tournament) }}" class="btn-outline-custom btn-sm" title="Редактировать">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                @if($tournament->status === 'open')
                                    @php
                                        $pushService = app(\App\Services\TournamentPushService::class);
                                    @endphp
                                    <button type="button" class="btn-outline-custom btn-sm btn-push" title="Отправить Push"
                                            onclick="openPushModal(this)"
                                            data-action="{{ route('club.tournaments.sendPush', $tournament) }}"
                                            data-tournament="{{ $tournament->name }}"
                                            data-title="{{ $pushService->defaultTitle() }}"
                                            data-body="{{ $pushService->defaultBody($tournament) }}">
                                        <i class="bi bi-bell"></i>
                                    </button>
                                @endif
                                @if($tournament->status === 'draft')
                                    <form action="{{ route('club.tournaments.destroy', $tournament) }}" method="POST" class="d-inline"
                                          onsubmit="return confirm('Удалить турнир?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn-danger-custom btn-sm" title="Удалить">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                @endif
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</div>

{{-- Окно отправки push: текст заполняется заготовкой, организатор правит --}}
<div class="push-overlay" id="pushOverlay" onclick="if(event.target === this) closePushModal()">
    <form method="POST" id="pushForm" class="push-modal">
        @csrf
        <div class="push-head">
            <div>
                <div class="push-eyebrow"><i class="bi bi-bell"></i> Push-уведомление</div>
                <div class="push-tournament" id="pushTournament"></div>
            </div>
            <button type="button" class="push-close" onclick="closePushModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        @php $pushTestPhones = app(\App\Services\TournamentPushService::class)->testPhones(); @endphp
        @if($pushTestPhones)
            <div class="push-testmode">
                <i class="bi bi-cone-striped"></i>
                <div>
                    <b>Тестовый режим</b>
                    Уведомление уйдёт только на {{ implode(', ', $pushTestPhones) }} —
                    остальные игроки ничего не получат. Снимается строкой
                    <code>PUSH_TEST_PHONES</code> в <code>.env</code>.
                </div>
            </div>
        @endif

        <label class="push-label">Заголовок</label>
        <input type="text" name="push_title" id="pushTitle" class="push-input"
               maxlength="100" required>
        <div class="push-counter"><span id="pushTitleLeft"></span></div>

        <label class="push-label">Текст</label>
        <textarea name="push_body" id="pushBody" class="push-input push-area"
                  maxlength="250" required></textarea>
        <div class="push-counter"><span id="pushBodyLeft"></span></div>

        {{-- На телефоне пуш обрезается: длинный текст увидят не полностью --}}
        <div class="push-preview">
            <div class="push-preview-label">Как увидит игрок</div>
            <div class="push-phone">
                <div class="push-phone-app">Padel KZ · сейчас</div>
                <div class="push-phone-title" id="pushPreviewTitle"></div>
                <div class="push-phone-body" id="pushPreviewBody"></div>
            </div>
        </div>

        <div class="push-actions">
            <button type="button" class="push-btn-ghost" onclick="resetPushText()">
                Вернуть заготовку
            </button>
            <button type="submit" class="push-btn" id="pushSubmit">
                <span class="push-spinner"></span>
                <span class="push-btn-text"><i class="bi bi-send"></i> Отправить</span>
            </button>
        </div>
    </form>
</div>

<script>
let pushDefaults = { title: '', body: '' };

function openPushModal(btn) {
    pushDefaults = { title: btn.dataset.title, body: btn.dataset.body };

    document.getElementById('pushForm').action = btn.dataset.action;
    document.getElementById('pushTournament').textContent = btn.dataset.tournament;
    document.getElementById('pushTitle').value = btn.dataset.title;
    document.getElementById('pushBody').value = btn.dataset.body;

    refreshPushPreview();
    document.getElementById('pushOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closePushModal() {
    document.getElementById('pushOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

function resetPushText() {
    document.getElementById('pushTitle').value = pushDefaults.title;
    document.getElementById('pushBody').value = pushDefaults.body;
    refreshPushPreview();
}

function refreshPushPreview() {
    const title = document.getElementById('pushTitle');
    const body = document.getElementById('pushBody');

    document.getElementById('pushPreviewTitle').textContent = title.value || '—';
    document.getElementById('pushPreviewBody').textContent = body.value || '—';
    document.getElementById('pushTitleLeft').textContent =
        title.value.length + ' / ' + title.maxLength;
    document.getElementById('pushBodyLeft').textContent =
        body.value.length + ' / ' + body.maxLength;
}

document.addEventListener('DOMContentLoaded', function () {
    ['pushTitle', 'pushBody'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', refreshPushPreview);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePushModal();
    });

    // Рассылка идёт несколько секунд. Без блокировки нетерпеливый клик
    // отправляет push повторно — игроки получают его дважды.
    document.getElementById('pushForm').addEventListener('submit', function (e) {
        var btn = document.getElementById('pushSubmit');

        if (btn.disabled) {
            e.preventDefault();
            return;
        }

        // Форма уже собрала данные — кнопку можно гасить.
        btn.disabled = true;
        btn.classList.add('sending');
        btn.querySelector('.push-btn-text').textContent = 'Отправляем…';
        document.querySelector('.push-btn-ghost').disabled = true;
        document.querySelector('.push-close').disabled = true;
    });
});
</script>

<style>
/* ---- окно отправки push ---- */
.push-overlay {
    display: none;
    position: fixed; inset: 0; z-index: 1000;
    background: rgba(0, 0, 0, .6);
    backdrop-filter: blur(2px);
    padding: 20px;
    overflow-y: auto;
}
.push-overlay.open { display: flex; align-items: center; justify-content: center; }
.push-modal {
    width: 100%; max-width: 480px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 22px 24px;
}
.push-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 18px; }
.push-eyebrow {
    display: flex; align-items: center; gap: 7px;
    color: var(--accent);
    font-size: .74rem; font-weight: 700;
    letter-spacing: .1em; text-transform: uppercase;
    margin-bottom: 5px;
}
.push-tournament { color: var(--text-primary); font-size: 1.08rem; font-weight: 600; }
.push-close {
    margin-left: auto; flex-shrink: 0;
    background: transparent; border: none;
    color: var(--text-secondary); cursor: pointer;
    font-size: 1rem; padding: 4px;
}
.push-close:hover { color: var(--text-primary); }
.push-testmode {
    display: flex; gap: 11px; align-items: flex-start;
    background: rgba(245, 158, 11, .12);
    border: 1px solid #f59e0b;
    border-radius: 11px;
    padding: 12px 14px;
    margin-bottom: 18px;
    color: #f59e0b;
    font-size: .85rem; line-height: 1.45;
}
.push-testmode i { font-size: 1.05rem; flex-shrink: 0; margin-top: 1px; }
.push-testmode b { display: block; margin-bottom: 2px; }
.push-testmode code {
    background: rgba(0, 0, 0, .25);
    border-radius: 4px; padding: 1px 5px;
    font-size: .82rem;
}
.push-label {
    display: block;
    color: var(--text-secondary);
    font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;
    margin-bottom: 6px;
}
.push-input {
    width: 100%;
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 11px 14px;
    color: var(--text-primary);
    font-size: .95rem;
    font-family: inherit;
}
.push-input:focus { outline: none; border-color: var(--accent); }
.push-area { min-height: 76px; resize: vertical; }
.push-counter {
    text-align: right;
    color: var(--text-secondary);
    font-size: .74rem;
    margin: 4px 0 14px;
}
.push-preview { margin-bottom: 18px; }
.push-preview-label {
    color: var(--text-secondary);
    font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;
    margin-bottom: 8px;
}
.push-phone {
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
}
.push-phone-app { color: var(--text-secondary); font-size: .72rem; margin-bottom: 5px; }
.push-phone-title {
    color: var(--text-primary); font-weight: 600; font-size: .92rem;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.push-phone-body {
    color: var(--text-secondary); font-size: .88rem; line-height: 1.35;
    margin-top: 2px;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    overflow: hidden;
}
.push-actions { display: flex; gap: 10px; }
.push-btn {
    flex: 1;
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    background: var(--accent); color: #000;
    border: none; border-radius: 10px;
    padding: 12px 20px;
    font-size: .95rem; font-weight: 600;
    cursor: pointer;
}
.push-btn-ghost {
    background: transparent; color: var(--text-secondary);
    border: 1px solid var(--border); border-radius: 10px;
    padding: 12px 16px;
    font-size: .9rem; cursor: pointer;
}
.push-btn-ghost:hover { color: var(--text-primary); border-color: var(--border-light); }
.push-btn:disabled, .push-btn-ghost:disabled { opacity: .55; cursor: not-allowed; }
.push-close:disabled { opacity: .35; cursor: not-allowed; }

/* Спиннер появляется только на время отправки */
.push-spinner { display: none; }
.push-btn.sending .push-spinner {
    display: inline-block;
    width: 15px; height: 15px;
    border: 2px solid rgba(0, 0, 0, .25);
    border-top-color: #000;
    border-radius: 50%;
    animation: push-spin .7s linear infinite;
}
@keyframes push-spin { to { transform: rotate(360deg); } }

.tournaments-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.tournament-row {
    display: flex;
    align-items: center;
    padding: 16px 20px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border);
    gap: 16px;
    transition: all 0.2s;
}

.tournament-row:hover {
    border-color: var(--accent);
    transform: translateX(4px);
}

.tournament-row.open {
    border-left: 3px solid #22c55e;
}

.tournament-row.in_progress {
    border-left: 3px solid #3b82f6;
}

.tournament-row.completed {
    border-left: 3px solid #06b6d4;
}

.tournament-row.cancelled {
    border-left: 3px solid #ef4444;
    opacity: 0.7;
}

.tournament-date-box {
    width: 54px;
    height: 54px;
    background: linear-gradient(135deg, var(--accent) 0%, #16a34a 100%);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.tournament-day {
    font-size: 1.3rem;
    font-weight: 700;
    line-height: 1;
}

.tournament-month {
    font-size: 0.7rem;
    text-transform: uppercase;
    opacity: 0.9;
}

.tournament-info {
    flex: 1;
    min-width: 0;
}

.tournament-name {
    font-weight: 600;
    margin-bottom: 4px;
}

.tournament-meta {
    display: flex;
    gap: 16px;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.tournament-meta i {
    margin-right: 4px;
}

.tournament-stats {
    display: flex;
    gap: 24px;
}

.tournament-stat {
    text-align: center;
}

.tournament-stat-value {
    font-weight: 700;
    font-size: 1.1rem;
}

.tournament-stat-label {
    font-size: 0.7rem;
    color: var(--text-secondary);
}

.tournament-status {
    min-width: 140px;
    text-align: center;
}

.tournament-actions {
    display: flex;
    gap: 8px;
}

@media (max-width: 768px) {
    .tournament-row {
        flex-wrap: wrap;
        padding: 14px 16px;
    }
    
    .tournament-date-box {
        width: 48px;
        height: 48px;
    }
    
    .tournament-day {
        font-size: 1.1rem;
    }
    
    .tournament-info {
        flex: 1;
        min-width: calc(100% - 140px);
    }
    
    .tournament-meta {
        flex-direction: column;
        gap: 4px;
    }
    
    .tournament-status {
        order: 3;
        min-width: auto;
    }
    
    .tournament-actions {
        order: 4;
        margin-left: auto;
    }
}
.btn-telegram {
    color: #229ED9;
    border-color: #229ED9;
}

.btn-telegram:hover {
    background: #229ED9;
    color: white;
}

.btn-push {
    color: #f59e0b;
    border-color: #f59e0b;
}

.btn-push:hover {
    background: #f59e0b;
    color: white;
}

/* Month groups */
.month-group {
    margin-bottom: 4px;
}

.month-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px;
    cursor: pointer;
    border-radius: 8px;
    user-select: none;
    transition: background 0.15s;
}

.month-header:hover {
    background: var(--bg-card);
}

.month-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}

.month-arrow {
    font-size: 0.85rem;
    color: var(--text-secondary);
    transition: transform 0.2s;
}

.month-header.open .month-arrow {
    transform: rotate(90deg);
}

.month-title {
    font-weight: 600;
    font-size: 0.95rem;
    text-transform: capitalize;
}

.month-count {
    background: var(--bg-card);
    border: 1px solid var(--border);
    color: var(--text-secondary);
    font-size: 0.75rem;
    font-weight: 600;
    padding: 1px 8px;
    border-radius: 10px;
}

.month-body {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-top: 4px;
}
</style>

<script>
function toggleMonth(header) {
    const body = header.nextElementSibling;
    const isOpen = header.classList.contains('open');

    if (isOpen) {
        body.style.display = 'none';
        header.classList.remove('open');
    } else {
        body.style.display = '';
        header.classList.add('open');
    }
}
</script>
@endsection
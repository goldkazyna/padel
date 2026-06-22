@extends('layouts.app')
@section('title', 'Тренеры')
@section('content')

@php
    $dayShort = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    $todayIso = now()->dayOfWeekIso;       // 1=Пн .. 7=Вс
    $nowTime  = now()->format('H:i');
    // Палитра аватаров (детерминированно по user_id)
    $avPalette = ['#3b82f6', '#7c3aed', '#22c55e', '#ec4899', '#f59e0b', '#06b6d4', '#ef4444', '#14b8a6'];
@endphp

<div class="coaches-container">
    <div class="coaches-header">
        <h1 class="coaches-title">Тренеры <span class="coaches-title-sep">·</span> <span class="coaches-title-club">{{ $club->name ?? '' }}</span></h1>
        <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addCoachModal">
            <span class="btn-add-plus">+</span> Добавить тренера
        </button>
    </div>

    <p class="coaches-sub">Часы работы и статус на сегодня, полная неделя — компактными пилюлями справа.</p>

    @if(session('success'))
        <div class="flash-message flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash-message flash-error">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="flash-message flash-error">
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    @forelse($clubCoaches as $cc)
        @php
            $schByDay   = $cc->schedules->keyBy('day_of_week');
            $today      = $schByDay->get($todayIso);
            $todayStart = $today ? \Carbon\Carbon::parse($today->start_time)->format('H:i') : null;
            $todayEnd   = $today ? \Carbon\Carbon::parse($today->end_time)->format('H:i') : null;
            $working    = $today && $nowTime >= $todayStart && $nowTime <= $todayEnd;
            $beforeStart= $today && $nowTime < $todayStart;

            $fullName = $cc->user->full_name;
            $initials = collect(preg_split('/\s+/', trim($fullName)))
                ->filter()->take(2)
                ->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))
                ->implode('');
            $avColor = $avPalette[$cc->user_id % count($avPalette)];
        @endphp

        <div class="coach-card {{ $working ? 'coach-card--live' : '' }}">
            {{-- Левая зона: аватар, имя, контакты, бейдж --}}
            <div class="coach-identity">
                <div class="coach-photo" style="background: {{ $cc->photo ? 'transparent' : $avColor }};">
                    @if($cc->photo)
                        <img src="{{ $cc->photo }}" alt="{{ $fullName }}">
                    @else
                        <span class="coach-photo-initials">{{ $initials ?: '?' }}</span>
                    @endif
                </div>
                <div class="coach-info">
                    <div class="coach-name">{{ $fullName }}</div>
                    <div class="coach-contacts">
                        @if($cc->user->phone)
                            <span class="coach-contact coach-contact--phone">@phone($cc->user->phone)</span>
                        @endif
                        @if($cc->user->email)
                            <span class="coach-contact-dot">·</span>
                            <span class="coach-contact">{{ $cc->user->email }}</span>
                        @endif
                    </div>
                    @if($cc->specialization)
                        <span class="coach-badge">{{ $cc->specialization }}</span>
                    @endif
                </div>
            </div>

            {{-- Сегодня --}}
            <div class="today-block {{ $working ? 'today-block--on' : '' }}">
                <div class="today-label">Сегодня <span class="today-label-day">· {{ $dayShort[$todayIso] }}</span></div>
                <div class="today-hours {{ $today ? '' : 'today-hours--off' }}">
                    {{ $today ? $todayStart.'–'.$todayEnd : 'Выходной' }}
                </div>
                <div class="today-status">
                    @if($working)
                        <span class="st-dot st-dot--on"></span>
                        <span class="st-text st-text--on">Работает · до {{ $todayEnd }}</span>
                    @elseif($beforeStart)
                        <span class="st-dot st-dot--wait"></span>
                        <span class="st-text st-text--wait">Начнёт в {{ $todayStart }}</span>
                    @elseif($today)
                        <span class="st-dot st-dot--off"></span>
                        <span class="st-text st-text--off">Завершил · {{ $todayEnd }}</span>
                    @else
                        <span class="st-dot st-dot--off"></span>
                        <span class="st-text st-text--off">Не работает</span>
                    @endif
                </div>
            </div>

            {{-- Неделя --}}
            <div class="week-block">
                <div class="week-label">Неделя</div>
                <div class="week-grid">
                    @foreach(range(1, 7) as $d)
                        @php $s = $schByDay->get($d); @endphp
                        <div class="wk-pill {{ $d === $todayIso ? 'wk-pill--today' : '' }} {{ $s ? '' : 'wk-pill--off' }}">
                            <span class="wk-day">{{ $dayShort[$d] }}</span>
                            <span class="wk-hrs">
                                {{ $s
                                    ? \Carbon\Carbon::parse($s->start_time)->format('H').'–'.\Carbon\Carbon::parse($s->end_time)->format('H')
                                    : 'вых' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Ставка --}}
            <div class="rate-block">
                <div class="rate-label">Ставка · 1 ч</div>
                @if($cc->hourly_rate)
                    <div class="rate-value">{{ number_format($cc->hourly_rate, 0, '', ' ') }} <span class="rate-cur">&#8376;</span></div>
                    @php $extraRates = $cc->rates->sortBy('hours'); @endphp
                    @if($extraRates->count())
                        <div class="rate-extra">
                            @foreach($extraRates as $rate)
                                <span class="rate-extra-item">{{ $rate->hours }}ч: {{ number_format($rate->rate, 0, '', ' ') }} &#8376;</span>
                            @endforeach
                        </div>
                    @endif
                @else
                    <div class="rate-value rate-value--empty">—</div>
                @endif
            </div>

            {{-- Действия --}}
            <div class="coach-actions">
                <button type="button" class="act-btn act-btn--key" title="Логин и пароль" data-bs-toggle="modal" data-bs-target="#credModal{{ $cc->user_id }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 19 4m-3 0 3 3m-5 2 2.5 2.5"/></svg>
                </button>
                <a href="{{ route('club.coaches.schedule', $cc->user_id) }}" class="act-btn act-btn--cal" title="Расписание">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2.5"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
                </a>
                <a href="{{ route('club.coaches.edit', $cc->user_id) }}" class="act-btn act-btn--edit" title="Редактировать">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </a>
                <form action="{{ route('club.coaches.destroy', $cc->user_id) }}" method="POST" style="display:inline;" onsubmit="return confirm('Удалить тренера {{ $fullName }}?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="act-btn act-btn--del" title="Удалить">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </form>
            </div>
        </div>

        <!-- Модалка: логин и пароль тренера -->
        <div class="modal fade" id="credModal{{ $cc->user_id }}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
                    <div class="modal-header" style="border-bottom: 1px solid #27272a; padding: 20px 24px;">
                        <h5 class="modal-title" style="font-weight: 700;">Логин и пароль — {{ $fullName }}</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="{{ route('club.coaches.credentials', $cc->user_id) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div class="modal-body" style="padding: 24px;">
                            <div class="form-group">
                                <label class="form-label">Телефон</label>
                                <input type="text" class="form-input form-input-readonly" value="@phoneFmt($cc->user->phone)" readonly>
                                <small class="form-hint">Телефон менять нельзя — это логин в систему</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-input" value="{{ $cc->user->email }}" placeholder="email@example.com" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Новый пароль</label>
                                <input type="text" name="password" class="form-input" placeholder="Оставьте пустым, чтобы не менять" autocomplete="new-password">
                                <small class="form-hint">Минимум 6 символов. По нему тренер сможет войти.</small>
                            </div>
                        </div>
                        <div class="modal-footer" style="border-top: 1px solid #27272a; padding: 20px 24px;">
                            <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                            <button type="submit" class="btn-save">Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="empty-state">
            <p>Тренеры не найдены. Добавьте первого тренера.</p>
            <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addCoachModal"><span class="btn-add-plus">+</span> Добавить тренера</button>
        </div>
    @endforelse
</div>

<!-- Модалка добавления тренера -->
<div class="modal fade" id="addCoachModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="modal-header" style="border-bottom: 1px solid #27272a; padding: 20px 24px;">
                <h5 class="modal-title" style="font-weight: 700;">Добавить тренера</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('club.coaches.store') }}" method="POST">
                @csrf
                <div class="modal-body" style="padding: 24px;">
                    <div class="form-group">
                        <label class="form-label">Поиск пользователя</label>
                        <input type="text" id="searchInput" class="form-input" placeholder="Введите имя или телефон..." oninput="searchUsers(this.value)" autocomplete="off">
                        <div id="searchResults" class="search-results"></div>
                    </div>
                    <div id="selectedUserBlock" class="selected-user-block" style="display: none;">
                        <span class="detail-label">Выбранный пользователь</span>
                        <div class="selected-user-info">
                            <span id="selectedUserName" class="selected-user-name"></span>
                            <button type="button" class="remove-selected-btn" onclick="clearSelectedUser()">&#10005;</button>
                        </div>
                        <input type="hidden" name="user_id" id="selectedUserId" value="">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Специализация</label>
                        <input type="text" name="specialization" class="form-input" placeholder="Например: Профессионал, Продвинутый">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ставка за час (базовая)</label>
                        <input type="number" name="hourly_rate" class="form-input" placeholder="&#8376;/час" min="0" step="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ставка за групповое (&#8376;/час)</label>
                        <input type="number" name="rate_group" class="form-input" placeholder="&#8376;/час" min="0" step="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ставка за индивидуальное (&#8376;/час)</label>
                        <input type="number" name="rate_individual" class="form-input" placeholder="&#8376;/час" min="0" step="100">
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #27272a; padding: 20px 24px;">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn-save">Добавить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let searchTimeout;
function searchUsers(query) {
    clearTimeout(searchTimeout);
    if (query.length < 2) { document.getElementById('searchResults').innerHTML = ''; return; }
    searchTimeout = setTimeout(() => {
        fetch('{{ route("club.coaches.searchUsers") }}?q=' + encodeURIComponent(query))
            .then(r => r.json())
            .then(users => {
                const container = document.getElementById('searchResults');
                container.innerHTML = users.map(u =>
                    `<div class="search-result-item" onclick="selectUser(${u.id}, '${u.name}')">
                        <span class="search-result-name">${u.name}</span>
                        <span class="search-result-phone">${u.phone || u.email || ''}</span>
                    </div>`
                ).join('');
            });
    }, 300);
}

function selectUser(id, name) {
    document.getElementById('selectedUserId').value = id;
    document.getElementById('selectedUserName').textContent = name;
    document.getElementById('searchResults').innerHTML = '';
    document.getElementById('searchInput').value = '';
    document.getElementById('selectedUserBlock').style.display = 'flex';
}

function clearSelectedUser() {
    document.getElementById('selectedUserId').value = '';
    document.getElementById('selectedUserName').textContent = '';
    document.getElementById('selectedUserBlock').style.display = 'none';
}
</script>

<style>
    .coaches-container { max-width: 1180px; margin: 0 auto; padding: 32px 24px 48px; }
    .coaches-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; flex-wrap: wrap; gap: 16px; }
    .coaches-title { font-size: 26px; font-weight: 800; letter-spacing: -0.6px; color: #fafafa; margin: 0; }
    .coaches-title-sep { color: #3f3f46; font-weight: 600; margin: 0 2px; }
    .coaches-title-club { color: #71717a; font-weight: 800; }
    .coaches-sub { color: #71717a; font-size: 13.5px; margin: 0 0 26px; }

    .btn-add { display: inline-flex; align-items: center; gap: 8px; background: #22c55e; color: #06210f; border: none; padding: 12px 22px; border-radius: 11px; font-size: 14px; font-weight: 800; cursor: pointer; transition: all 0.18s; box-shadow: 0 6px 18px -8px rgba(34,197,94,0.7); }
    .btn-add:hover { background: #1eb653; transform: translateY(-1px); }
    .btn-add-plus { font-size: 17px; line-height: 1; font-weight: 700; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    /* ===== Card ===== */
    .coach-card {
        display: grid;
        grid-template-columns: minmax(220px, 1.4fr) auto auto auto auto;
        align-items: center;
        gap: 28px;
        background: #131316;
        border: 1px solid #232328;
        border-radius: 18px;
        padding: 18px 22px;
        margin-bottom: 14px;
        transition: border-color 0.18s, background 0.18s;
    }
    .coach-card:hover { border-color: #34343b; background: #15151a; }
    .coach-card--live { border-color: rgba(34,197,94,0.28); }

    /* Identity */
    .coach-identity { display: flex; align-items: center; gap: 16px; min-width: 0; }
    .coach-photo { width: 56px; height: 56px; border-radius: 50%; overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .coach-photo img { width: 100%; height: 100%; object-fit: cover; }
    .coach-photo-initials { font-size: 21px; font-weight: 800; color: #fff; letter-spacing: 0.5px; }
    .coach-info { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
    .coach-name { font-size: 17px; font-weight: 700; color: #f4f4f5; letter-spacing: -0.2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .coach-contacts { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
    .coach-contact { font-size: 12.5px; color: #71717a; font-weight: 500; }
    .coach-contact--phone { color: #a1a1aa; }
    .coach-contact-dot { color: #3f3f46; font-size: 12px; }
    .coach-badge { align-self: flex-start; margin-top: 3px; display: inline-flex; align-items: center; padding: 4px 11px; background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.35); border-radius: 100px; font-size: 12px; font-weight: 700; color: #34d77a; }

    /* Today */
    .today-block { min-width: 190px; padding: 12px 16px; background: #0e0e11; border: 1px solid #232328; border-radius: 13px; }
    .today-block--on { border-color: rgba(34,197,94,0.3); background: rgba(34,197,94,0.05); }
    .today-label { font-size: 10px; font-weight: 800; letter-spacing: 0.6px; text-transform: uppercase; color: #71717a; margin-bottom: 6px; }
    .today-label-day { color: #52525b; }
    .today-hours { font-size: 23px; font-weight: 800; color: #fafafa; letter-spacing: -0.5px; line-height: 1; font-variant-numeric: tabular-nums; }
    .today-hours--off { font-size: 19px; color: #52525b; }
    .today-status { display: flex; align-items: center; gap: 7px; margin-top: 9px; }
    .st-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
    .st-dot--on { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.18); }
    .st-dot--wait { background: #f59e0b; }
    .st-dot--off { background: #52525b; }
    .st-text { font-size: 12.5px; font-weight: 700; }
    .st-text--on { color: #34d77a; }
    .st-text--wait { color: #f59e0b; }
    .st-text--off { color: #71717a; }

    /* Week */
    .week-block { display: flex; flex-direction: column; gap: 8px; }
    .week-label { font-size: 10px; font-weight: 800; letter-spacing: 0.6px; text-transform: uppercase; color: #71717a; }
    .week-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
    .wk-pill { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; min-width: 56px; padding: 7px 8px; background: #0e0e11; border: 1px solid #232328; border-radius: 9px; }
    .wk-day { font-size: 11px; font-weight: 700; color: #a1a1aa; }
    .wk-hrs { font-size: 11px; font-weight: 600; color: #71717a; font-variant-numeric: tabular-nums; }
    .wk-pill--today { background: rgba(34,197,94,0.1); border-color: rgba(34,197,94,0.5); }
    .wk-pill--today .wk-day { color: #34d77a; }
    .wk-pill--today .wk-hrs { color: #34d77a; }
    .wk-pill--off { opacity: 0.55; }
    .wk-pill--off .wk-day { color: #71717a; }
    .wk-pill--off .wk-hrs { color: #52525b; }

    /* Rate */
    .rate-block { text-align: right; min-width: 96px; }
    .rate-label { font-size: 10px; font-weight: 800; letter-spacing: 0.6px; text-transform: uppercase; color: #71717a; margin-bottom: 6px; }
    .rate-value { font-size: 21px; font-weight: 800; color: #22c55e; letter-spacing: -0.5px; line-height: 1; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .rate-value--empty { color: #3f3f46; }
    .rate-cur { font-size: 16px; font-weight: 700; }
    .rate-extra { display: flex; flex-direction: column; gap: 2px; margin-top: 7px; }
    .rate-extra-item { font-size: 11px; font-weight: 600; color: #71717a; }

    /* Actions */
    .coach-actions { display: flex; align-items: center; gap: 7px; }
    .act-btn { width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; background: #18181c; border: 1px solid #2a2a30; border-radius: 9px; cursor: pointer; color: #a1a1aa; transition: all 0.16s; text-decoration: none; padding: 0; }
    .act-btn svg { width: 17px; height: 17px; }
    .act-btn:hover { background: #1f1f25; }
    .act-btn--key:hover { border-color: #f59e0b; color: #f59e0b; }
    .act-btn--cal:hover { border-color: #22c55e; color: #22c55e; }
    .act-btn--edit:hover { border-color: #3b82f6; color: #3b82f6; }
    .act-btn--del:hover { border-color: #ef4444; color: #ef4444; }

    .empty-state { text-align: center; padding: 60px 20px; color: #71717a; background: #131316; border: 1px solid #232328; border-radius: 18px; }
    .empty-state p { font-size: 16px; margin-bottom: 20px; }

    /* ===== Form inside modals (без изменений) ===== */
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .detail-label { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-input::placeholder { color: #52525b; }
    .form-input-readonly { background: #0e0e10; color: #71717a; cursor: not-allowed; }
    .form-input-readonly:focus { border-color: #27272a; box-shadow: none; }
    .form-hint { color: #52525b; font-size: 11px; display: block; margin-top: 6px; }
    .btn-cancel { flex: 1; padding: 14px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }

    .search-results { max-height: 200px; overflow-y: auto; }
    .search-result-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; margin-top: 8px; cursor: pointer; transition: all 0.2s; }
    .search-result-item:hover { border-color: #22c55e; background: #1a1a1e; }
    .search-result-name { font-size: 14px; font-weight: 600; color: #f4f4f5; }
    .search-result-phone { font-size: 13px; color: #71717a; }

    .selected-user-block { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; padding: 14px 16px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; }
    .selected-user-info { display: flex; align-items: center; justify-content: space-between; }
    .selected-user-name { font-size: 15px; font-weight: 700; color: #22c55e; }
    .remove-selected-btn { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: transparent; border: 1px solid #27272a; border-radius: 6px; cursor: pointer; color: #71717a; font-size: 14px; transition: all 0.2s; }
    .remove-selected-btn:hover { border-color: #ef4444; color: #ef4444; }

    .modal-footer { display: flex; gap: 12px; }

    /* ===== Responsive ===== */
    @media (max-width: 1100px) {
        .coach-card { grid-template-columns: 1fr 1fr; grid-template-areas: "identity rate" "today week" "actions actions"; gap: 18px; }
        .coach-identity { grid-area: identity; }
        .today-block { grid-area: today; }
        .week-block { grid-area: week; }
        .rate-block { grid-area: rate; }
        .coach-actions { grid-area: actions; justify-content: flex-end; }
    }
    @media (max-width: 640px) {
        .coaches-container { padding: 24px 14px 40px; }
        .coach-card { grid-template-columns: 1fr; grid-template-areas: "identity" "today" "week" "rate" "actions"; gap: 16px; padding: 18px; }
        .rate-block { text-align: left; }
        .coach-actions { justify-content: flex-start; }
        .coaches-header { flex-direction: column; align-items: flex-start; }
    }
</style>
@endsection

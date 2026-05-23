@extends('layouts.app')
@section('title', 'Пользователи')
@section('content')

<div class="users-container">
    <!-- Header -->
    <header class="users-header">
        <div class="users-title-block">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            <h1 class="users-title">Пользователи{{ $clubCity ? ' — ' . $clubCity : '' }}</h1>
            <span class="users-count">{{ $users->total() }}</span>
        </div>
    </header>

    <!-- Toolbar -->
    <div class="users-toolbar">
        <form method="GET" class="search-box">
            @if(request('level'))
                <input type="hidden" name="level" value="{{ request('level') }}">
            @endif
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" name="search" class="search-input" placeholder="Поиск по имени или ID..." value="{{ request('search') }}">
            @if(request('search'))
                <a href="{{ route('club.users.index', request('level') ? ['level' => request('level')] : []) }}" class="search-clear">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </a>
            @endif
        </form>
    </div>

    <!-- Date Filter -->
    <div class="date-filter">
        <form method="GET" class="date-filter-form">
            @if(request('search'))<input type="hidden" name="search" value="{{ request('search') }}">@endif
            @if(request('level'))<input type="hidden" name="level" value="{{ request('level') }}">@endif
            @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
            @if(request('dir'))<input type="hidden" name="dir" value="{{ request('dir') }}">@endif
            @if(request('verified'))<input type="hidden" name="verified" value="{{ request('verified') }}">@endif
            <label class="date-label">Дата регистрации:</label>
            <input type="date" name="date_from" class="date-input" value="{{ request('date_from') }}" placeholder="От">
            <span class="date-sep">—</span>
            <input type="date" name="date_to" class="date-input" value="{{ request('date_to') }}" placeholder="До">
            <button type="submit" class="date-btn">Показать</button>
            @if(request('date_from') || request('date_to'))
                <a href="{{ route('club.users.index', array_filter([
                    'search' => request('search'),
                    'level' => request('level'),
                    'sort' => request('sort'),
                    'dir' => request('dir'),
                    'verified' => request('verified'),
                ])) }}" class="date-btn date-btn-clear">Сбросить</a>
            @endif
        </form>
    </div>

    <!-- Verification Filter -->
    @php
        $vCurrent = request('verified');
        $vBase = array_filter([
            'search' => request('search'),
            'level' => request('level'),
            'exact_level' => request('exact_level'),
            'sort' => request('sort'),
            'dir' => request('dir'),
            'date_from' => request('date_from'),
            'date_to' => request('date_to'),
        ]);
    @endphp
    <div class="verified-filter">
        <span class="verified-filter-label">Верификация уровня:</span>
        <a href="{{ route('club.users.index', $vBase) }}"
           class="verified-pill {{ !$vCurrent ? 'active' : '' }}">Все</a>
        <a href="{{ route('club.users.index', array_merge($vBase, ['verified' => 'verified'])) }}"
           class="verified-pill verified-pill-ok {{ $vCurrent === 'verified' ? 'active' : '' }}">
            ✓ Верифицирован
        </a>
        <a href="{{ route('club.users.index', array_merge($vBase, ['verified' => 'unverified'])) }}"
           class="verified-pill verified-pill-warn {{ $vCurrent === 'unverified' ? 'active' : '' }}">
            ⚠ Не верифицирован
        </a>
    </div>

    <!-- Level Stats -->
    @php
        $activeLevel = request('level');
        $activeExact = request('exact_level');
        $baseParams = array_filter([
            'search' => request('search'),
            'sort' => request('sort'),
            'dir' => request('dir'),
            'date_from' => request('date_from'),
            'date_to' => request('date_to'),
            'verified' => request('verified'),
        ]);
    @endphp
    <div class="level-stats">
        @foreach([
            ['key' => '1', 'range' => '1 — 1.75', 'label' => 'Начинающий', 'value' => (int) $levelStats->level_1, 'class' => 'level-stat-1', 'subs' => ['1.00', '1.25', '1.50', '1.75']],
            ['key' => '2', 'range' => '2 — 2.75', 'label' => 'Любитель', 'value' => (int) $levelStats->level_2, 'class' => 'level-stat-2', 'subs' => ['2.00', '2.25', '2.50', '2.75']],
            ['key' => '3', 'range' => '3 — 3.75', 'label' => 'Средний', 'value' => (int) $levelStats->level_3, 'class' => 'level-stat-3', 'subs' => ['3.00', '3.25', '3.50', '3.75']],
            ['key' => '4', 'range' => '4 — 4.75', 'label' => 'Продвинутый', 'value' => (int) $levelStats->level_4, 'class' => 'level-stat-4', 'subs' => ['4.00', '4.25', '4.50', '4.75']],
            ['key' => '5', 'range' => '5 — 5.75', 'label' => 'Про', 'value' => (int) $levelStats->level_5, 'class' => 'level-stat-5', 'subs' => ['5.00', '5.25', '5.50', '5.75']],
        ] as $stat)
            <div class="level-stat-wrapper">
                <a href="{{ route('club.users.index', $activeLevel == $stat['key'] && !$activeExact ? $baseParams : array_merge($baseParams, ['level' => $stat['key']])) }}"
                   class="level-stat-card {{ $stat['class'] }} {{ $activeLevel == $stat['key'] && !$activeExact ? 'active' : '' }}">
                    <div class="level-stat-range">{{ $stat['range'] }}</div>
                    <div class="level-stat-label">{{ $stat['label'] }}</div>
                    <div class="level-stat-value">{{ $stat['value'] }}</div>
                </a>
                <div class="level-subs">
                    @foreach($stat['subs'] as $sub)
                        <a href="{{ route('club.users.index', $activeExact == $sub ? $baseParams : array_merge($baseParams, ['exact_level' => $sub])) }}"
                           class="level-sub-btn {{ $stat['class'] }} {{ $activeExact == $sub ? 'active' : '' }}">
                            {{ $sub }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <!-- Table -->
    <div class="users-table-wrap">
        <table class="users-table">
            <thead>
                <tr>
                    @php
                        $currentSort = request('sort', 'name');
                        $currentDir = request('dir', 'asc');
                        $params = request()->except(['sort', 'dir', 'page']);
                    @endphp
                    <th>
                        <a href="{{ route('club.users.index', array_merge($params, ['sort' => 'name', 'dir' => $currentSort === 'name' && $currentDir === 'asc' ? 'desc' : 'asc'])) }}" class="sort-link {{ $currentSort === 'name' ? 'active' : '' }}">
                            Имя {!! $currentSort === 'name' ? ($currentDir === 'asc' ? '↑' : '↓') : '' !!}
                        </a>
                    </th>
                    <th>Город</th>
					<th>
                        <a href="{{ route('club.users.index', array_merge($params, ['sort' => 'level', 'dir' => $currentSort === 'level' && $currentDir === 'asc' ? 'desc' : 'asc'])) }}" class="sort-link {{ $currentSort === 'level' ? 'active' : '' }}">
                            Уровень {!! $currentSort === 'level' ? ($currentDir === 'asc' ? '↑' : '↓') : '' !!}
                        </a>
                    </th>
                    <th>
                        <a href="{{ route('club.users.index', array_merge($params, ['sort' => 'rating', 'dir' => $currentSort === 'rating' && $currentDir === 'desc' ? 'asc' : 'desc'])) }}" class="sort-link {{ $currentSort === 'rating' ? 'active' : '' }}">
                            Рейтинг {!! $currentSort === 'rating' ? ($currentDir === 'asc' ? '↑' : '↓') : '' !!}
                        </a>
                    </th>
                    <th>Верификация</th>
                    <th>
                        <a href="{{ route('club.users.index', array_merge($params, ['sort' => 'created_at', 'dir' => $currentSort === 'created_at' && $currentDir === 'desc' ? 'asc' : 'desc'])) }}" class="sort-link {{ $currentSort === 'created_at' ? 'active' : '' }}">
                            Регистрация {!! $currentSort === 'created_at' ? ($currentDir === 'asc' ? '↑' : '↓') : '' !!}
                        </a>
                    </th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td>
                            <div class="user-name-cell">
                                <div class="user-avatar">{{ mb_strtoupper(mb_substr($user->first_name, 0, 1) . mb_substr($user->last_name, 0, 1)) }}</div>
                                <div class="user-name-info">
                                    <span class="user-fullname">{{ $user->name }}</span>
                                    <span class="user-id">ID: {{ $user->id }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="user-city">{{ $user->city ?: '—' }}</span>
                        </td>
						<td>
							<span class="user-level">{{ $user->level }}</span>
						</td>
                        <td>
                            <span class="rating-value">{{ $user->rating }}</span>
                        </td>
                        <td>
                            @if($user->level_verified)
                                <span style="display:inline-flex;align-items:center;gap:4px;color:#22c47a;font-size:12px;font-weight:600;background:rgba(34,196,122,0.1);padding:3px 8px;border-radius:6px;" title="Уровень подтверждён">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="#22c47a"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                                    Верифицирован
                                </span>
                            @else
                                <span style="color:#71717a;font-size:12px;">Не верифицирован</span>
                            @endif
                        </td>
                        <td>
                            <span class="user-date">{{ $user->created_at ? $user->created_at->format('d.m.Y') : '—' }}</span>
                        </td>
                        <td>
                            <div class="user-actions">
                                <button class="action-btn edit" title="Редактировать" onclick="openModal({{ $user->id }})">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #71717a;">
                            Пользователи не найдены
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- Pagination -->
        @if($users->hasPages())
        <div class="users-pagination">
            <div class="pagination-info">
                Показано {{ $users->firstItem() }}–{{ $users->lastItem() }} из {{ $users->total() }} пользователей
            </div>
            <div class="pagination-controls">
                {{-- Назад --}}
                @if($users->onFirstPage())
                    <button class="page-btn" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                @else
                    <a href="{{ $users->previousPageUrl() }}" class="page-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                    </a>
                @endif

                {{-- Номера страниц --}}
                @foreach($users->getUrlRange(1, $users->lastPage()) as $page => $url)
                    @if($page == $users->currentPage())
                        <button class="page-btn active">{{ $page }}</button>
                    @elseif($page == 1 || $page == $users->lastPage() || abs($page - $users->currentPage()) <= 2)
                        <a href="{{ $url }}" class="page-btn">{{ $page }}</a>
                    @elseif($page == 2 || $page == $users->lastPage() - 1)
                        <span class="page-btn">...</span>
                    @endif
                @endforeach

                {{-- Вперёд --}}
                @if($users->hasMorePages())
                    <a href="{{ $users->nextPageUrl() }}" class="page-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                @else
                    <button class="page-btn" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>

<!-- Edit Modals -->
@foreach($users as $user)
<div class="modal-overlay" id="editModal{{ $user->id }}">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Редактировать пользователя</h2>
            <button class="modal-close" onclick="closeModal({{ $user->id }})">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form action="{{ route('club.users.update', $user) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Имя</label>
                    <input type="text" name="name" class="form-input" value="{{ $user->name }}" placeholder="Введите имя" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Уровень</label>
                    <input type="number" name="level" class="form-input" value="{{ $user->level }}"
                           min="1" max="5.75" step="0.25">
                    <small style="color:#888;font-size:11px;">Рейтинг пересчитается автоматически (уровень × 1000 + 125).</small>
                </div>
                <div class="form-group">
                    @if($user->avatar)
                        <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#0f2d1f;border:1px solid #22c55e44;border-radius:6px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                            <span style="color:#22c55e;font-size:12px;font-weight:600;">Уровень будет автоматически отмечен как верифицированный</span>
                        </div>
                        <small style="color:#888;font-size:11px;display:block;margin-top:6px;">Раз вы вручную меняете уровень — значит подтверждаете его.</small>
                    @else
                        <div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#2d1f0f;border:1px solid #eab34e44;border-radius:6px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#eab34e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="10"/></svg>
                            <span style="color:#eab34e;font-size:12px;font-weight:600;">У игрока нет фото — уровень НЕ будет верифицирован</span>
                        </div>
                        <small style="color:#888;font-size:11px;display:block;margin-top:6px;">Верификация включится автоматически, когда игрок добавит аватарку.</small>
                    @endif
                </div>
                <div class="form-info">
                    <span>Рейтинг: {{ $user->rating }}</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal({{ $user->id }})">Отмена</button>
                <button type="submit" class="btn-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>
@endforeach

<script>
    function openModal(id) {
        document.getElementById('editModal' + id).classList.add('active');
    }

    function closeModal(id) {
        document.getElementById('editModal' + id).classList.remove('active');
    }

    // Close modal on overlay click
    document.querySelectorAll('.modal-overlay').forEach(function(modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(function(modal) {
                modal.classList.remove('active');
            });
        }
    });
</script>
    <style>
        :root {
            --users-bg: #0a0a0b;
            --users-bg-secondary: #111113;
            --users-card: #16161a;
            --users-card-hover: #1c1c21;
            --users-accent: #22c55e;
            --users-accent-dark: #16a34a;
            --users-blue: #3b82f6;
            --users-blue-dark: #2563eb;
            --users-text: #f4f4f5;
            --users-text-dim: #a1a1aa;
            --users-text-muted: #71717a;
            --users-border: #27272a;
            --users-border-light: #3f3f46;
            --users-yellow: #facc15;
            --users-red: #ef4444;
            --users-orange: #f97316;
        }

        .users-container {
            width: 100%;
            padding: 32px 40px;
        }

        /* Header */
        .users-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
        }

        .users-title-block {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .users-title-block svg {
            width: 28px;
            height: 28px;
            color: var(--users-accent);
        }

        .users-title {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .users-count {
            background: var(--users-accent);
            color: var(--users-bg);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            margin-left: 12px;
        }

        .users-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-add-user {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--users-accent);
            color: var(--users-bg);
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-add-user:hover {
            background: var(--users-accent-dark);
            transform: translateY(-1px);
        }

        .btn-add-user svg {
            width: 18px;
            height: 18px;
        }

        /* Search & Filters */
        .users-toolbar {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        .search-box {
            flex: 1;
            max-width: 400px;
            position: relative;
        }

        .search-box svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            color: var(--users-text-muted);
        }

        .search-input {
            width: 100%;
            background: var(--users-card);
            border: 1px solid var(--users-border);
            border-radius: 10px;
            padding: 12px 14px 12px 44px;
            font-size: 14px;
            font-weight: 500;
            color: var(--users-text);
            transition: all 0.2s;
        }

        .search-input::placeholder {
            color: var(--users-text-muted);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--users-accent);
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
        }

        .search-clear {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
        }
        .search-clear svg {
            width: 18px;
            height: 18px;
            color: var(--users-text-muted);
        }

        /* Verification Filter */
        .verified-filter {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .verified-filter-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--users-text-dim);
            margin-right: 4px;
        }
        .verified-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 14px;
            background: var(--users-card);
            border: 1px solid var(--users-border);
            border-radius: 100px;
            color: var(--users-text-dim);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s;
        }
        .verified-pill:hover {
            border-color: var(--users-text);
            color: var(--users-text);
        }
        .verified-pill.active {
            background: rgba(34, 196, 122, 0.14);
            border-color: rgba(34, 196, 122, 0.40);
            color: #22c47a;
        }
        .verified-pill-ok.active {
            background: rgba(34, 196, 122, 0.14);
            border-color: rgba(34, 196, 122, 0.40);
            color: #22c47a;
        }
        .verified-pill-warn.active {
            background: rgba(234, 179, 78, 0.14);
            border-color: rgba(234, 179, 78, 0.40);
            color: #eab34e;
        }

        /* Date Filter */
        .date-filter {
            margin-bottom: 24px;
        }
        .date-filter-form {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .date-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--users-text-dim);
        }
        .date-input {
            background: var(--users-card);
            border: 1px solid var(--users-border);
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 14px;
            color: var(--users-text);
            color-scheme: dark;
        }
        .date-input:focus {
            outline: none;
            border-color: var(--users-accent);
        }
        .date-sep {
            color: var(--users-text-muted);
            font-size: 14px;
        }
        .date-btn {
            background: var(--users-accent);
            color: var(--users-bg);
            border: none;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: all .2s;
        }
        .date-btn:hover { background: var(--users-accent-dark); }
        .date-btn-clear {
            background: var(--users-card);
            color: var(--users-text-dim);
            border: 1px solid var(--users-border);
        }
        .date-btn-clear:hover {
            border-color: var(--users-border-light);
            color: var(--users-text);
        }

        /* Sort Links */
        .sort-link {
            color: var(--users-text-muted);
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: color .2s;
        }
        .sort-link:hover { color: var(--users-text); }
        .sort-link.active { color: var(--users-accent); }

        /* User Date */
        .user-date {
            font-size: 14px;
            color: var(--users-text-dim);
            font-variant-numeric: tabular-nums;
        }
        .user-city {
            font-size: 14px;
            color: var(--users-text-dim);
        }

        .filter-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--users-card);
            border: 1px solid var(--users-border);
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            color: var(--users-text-dim);
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-btn:hover {
            border-color: var(--users-border-light);
            color: var(--users-text);
        }

        .filter-btn svg {
            width: 18px;
            height: 18px;
        }

        /* Level Stats */
        .level-stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        .level-stat-wrapper {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .level-stat-card {
            display: block;
            background: var(--users-bg-secondary);
            border: 1px solid var(--users-border);
            border-radius: 12px;
            padding: 16px 20px;
            text-align: center;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            transition: all 0.2s;
        }

        .level-stat-card:hover {
            border-color: var(--users-border-light);
            background: var(--users-card-hover);
            transform: translateY(-2px);
        }

        .level-stat-card.active {
            background: var(--users-card-hover);
        }

        .level-subs {
            display: flex;
            gap: 4px;
        }

        .level-sub-btn {
            flex: 1;
            text-align: center;
            padding: 5px 0;
            font-size: 12px;
            font-weight: 700;
            color: var(--users-text-muted);
            background: var(--users-bg-secondary);
            border: 1px solid var(--users-border);
            border-radius: 6px;
            text-decoration: none;
            transition: all .2s;
            cursor: pointer;
        }
        .level-sub-btn:hover {
            border-color: var(--users-border-light);
            color: var(--users-text-dim);
        }
        .level-sub-btn.active {
            background: var(--users-card-hover);
        }
        .level-sub-btn.level-stat-1.active { border-color: var(--users-accent); color: var(--users-accent); }
        .level-sub-btn.level-stat-2.active { border-color: var(--users-blue); color: var(--users-blue); }
        .level-sub-btn.level-stat-3.active { border-color: var(--users-orange); color: var(--users-orange); }
        .level-sub-btn.level-stat-4.active { border-color: var(--users-red); color: var(--users-red); }
        .level-sub-btn.level-stat-5.active { border-color: var(--users-yellow); color: var(--users-yellow); }

        .level-stat-range {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .level-stat-label {
            font-size: 12px;
            color: var(--users-text-muted);
            margin-bottom: 10px;
        }

        .level-stat-value {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .level-stat-1 .level-stat-range,
        .level-stat-1 .level-stat-value { color: var(--users-accent); }
        .level-stat-1 { border-color: rgba(34, 197, 94, 0.2); }
        .level-stat-1.active { box-shadow: 0 0 0 2px var(--users-accent); }

        .level-stat-2 .level-stat-range,
        .level-stat-2 .level-stat-value { color: var(--users-blue); }
        .level-stat-2 { border-color: rgba(59, 130, 246, 0.2); }
        .level-stat-2.active { box-shadow: 0 0 0 2px var(--users-blue); }

        .level-stat-3 .level-stat-range,
        .level-stat-3 .level-stat-value { color: var(--users-orange); }
        .level-stat-3 { border-color: rgba(249, 115, 22, 0.2); }
        .level-stat-3.active { box-shadow: 0 0 0 2px var(--users-orange); }

        .level-stat-4 .level-stat-range,
        .level-stat-4 .level-stat-value { color: var(--users-red); }
        .level-stat-4 { border-color: rgba(239, 68, 68, 0.2); }
        .level-stat-4.active { box-shadow: 0 0 0 2px var(--users-red); }

        .level-stat-5 .level-stat-range,
        .level-stat-5 .level-stat-value { color: var(--users-yellow); }
        .level-stat-5 { border-color: rgba(250, 204, 21, 0.2); }
        .level-stat-5.active { box-shadow: 0 0 0 2px var(--users-yellow); }

        @media (max-width: 768px) {
            .level-stats {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 480px) {
            .level-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Table */
        .users-table-wrap {
            background: var(--users-bg-secondary);
            border: 1px solid var(--users-border);
            border-radius: 16px;
            overflow: hidden;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th {
            padding: 16px 20px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            color: var(--users-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: var(--users-card);
            border-bottom: 1px solid var(--users-border);
        }

        .users-table th:first-child {
            padding-left: 24px;
        }

        .users-table th:last-child {
            padding-right: 24px;
            text-align: center;
        }

        .users-table th.sortable {
            cursor: pointer;
            user-select: none;
            transition: color 0.2s;
        }

        .users-table th.sortable:hover {
            color: var(--users-text);
        }

        .users-table th.sortable svg {
            width: 14px;
            height: 14px;
            margin-left: 6px;
            vertical-align: middle;
            opacity: 0.5;
        }

        .users-table th.sorted {
            color: var(--users-accent);
        }

        .users-table th.sorted svg {
            opacity: 1;
            color: var(--users-accent);
        }

        .users-table td {
            padding: 18px 20px;
            font-size: 15px;
            border-bottom: 1px solid var(--users-border);
            vertical-align: middle;
        }

        .users-table td:first-child {
            padding-left: 24px;
        }

        .users-table td:last-child {
            padding-right: 24px;
            text-align: center;
        }

        .users-table tbody tr {
            transition: background 0.15s;
        }

        .users-table tbody tr:hover {
            background: var(--users-card-hover);
        }

        .users-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* User Name Cell */
        .user-name-cell {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--users-accent), var(--users-accent-dark));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            color: var(--users-bg);
            flex-shrink: 0;
        }

        .user-name-info {
            display: flex;
            flex-direction: column;
        }

        .user-fullname {
            font-size: 15px;
            font-weight: 600;
            color: var(--users-text);
        }

        .user-id {
            font-size: 12px;
            color: var(--users-text-muted);
        }

        /* Level Cell */
        .user-level {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
        }

        .user-level.level-beginner {
            background: rgba(34, 197, 94, 0.15);
            color: var(--users-accent);
        }

        .user-level.level-intermediate {
            background: rgba(59, 130, 246, 0.15);
            color: var(--users-blue);
        }

        .user-level.level-advanced {
            background: rgba(249, 115, 22, 0.15);
            color: var(--users-orange);
        }

        .user-level.level-pro {
            background: rgba(250, 204, 21, 0.15);
            color: var(--users-yellow);
        }

        /* Rating Cell */
        .user-rating {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rating-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--users-text);
        }

        .rating-stars {
            display: flex;
            gap: 2px;
        }

        .rating-stars svg {
            width: 14px;
            height: 14px;
            color: var(--users-yellow);
        }

        .rating-stars svg.empty {
            color: var(--users-border-light);
        }

        /* Actions */
        .user-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .action-btn {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--users-card);
            border: 1px solid var(--users-border);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .action-btn svg {
            width: 18px;
            height: 18px;
            color: var(--users-text-dim);
            transition: color 0.2s;
        }

        .action-btn:hover {
            border-color: var(--users-border-light);
            background: var(--users-card-hover);
        }

        .action-btn:hover svg {
            color: var(--users-text);
        }

        .action-btn.edit:hover {
            border-color: var(--users-blue);
        }

        .action-btn.edit:hover svg {
            color: var(--users-blue);
        }

        .action-btn.delete:hover {
            border-color: var(--users-red);
        }

        .action-btn.delete:hover svg {
            color: var(--users-red);
        }

        /* Pagination */
        .users-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            background: var(--users-card);
            border-top: 1px solid var(--users-border);
        }

        .pagination-info {
            font-size: 14px;
            color: var(--users-text-muted);
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-btn {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--users-bg-secondary);
            border: 1px solid var(--users-border);
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--users-text-dim);
            cursor: pointer;
            transition: all 0.2s;
        }

        .page-btn:hover {
            border-color: var(--users-border-light);
            color: var(--users-text);
        }

        .page-btn.active {
            background: var(--users-accent);
            border-color: var(--users-accent);
            color: var(--users-bg);
        }

        .page-btn svg {
            width: 18px;
            height: 18px;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: var(--users-bg-secondary);
            border: 1px solid var(--users-border);
            border-radius: 16px;
            width: 100%;
            max-width: 480px;
            transform: translateY(20px);
            transition: transform 0.3s;
        }

        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 1px solid var(--users-border);
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
        }

        .modal-close {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--users-card);
            border: 1px solid var(--users-border);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .modal-close svg {
            width: 20px;
            height: 20px;
            color: var(--users-text-dim);
        }

        .modal-close:hover {
            border-color: var(--users-red);
        }

        .modal-close:hover svg {
            color: var(--users-red);
        }

        .modal-body {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--users-text-dim);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-input {
            width: 100%;
            background: var(--users-card);
            border: 1px solid var(--users-border);
            border-radius: 10px;
            padding: 14px 16px;
            font-size: 15px;
            font-weight: 500;
            color: var(--users-text);
            transition: all 0.2s;
        }

        .form-input::placeholder {
            color: var(--users-text-muted);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--users-accent);
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            padding: 20px 24px;
            border-top: 1px solid var(--users-border);
        }

        .btn-cancel {
            padding: 12px 20px;
            background: var(--users-card);
            border: 1px solid var(--users-border);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            color: var(--users-text-dim);
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-cancel:hover {
            border-color: var(--users-border-light);
            color: var(--users-text);
        }

        .btn-save {
            padding: 12px 24px;
            background: var(--users-accent);
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            color: var(--users-bg);
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-save:hover {
            background: var(--users-accent-dark);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .users-container {
                padding: 24px 20px;
            }

            .users-table th,
            .users-table td {
                padding: 14px 16px;
            }
        }

        @media (max-width: 768px) {
            .users-toolbar {
                flex-wrap: wrap;
            }

            .search-box {
                max-width: none;
                order: 1;
                width: 100%;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endsection
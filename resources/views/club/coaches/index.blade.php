@extends('layouts.app')
@section('title', 'Тренеры')
@section('content')

@php
    $dayShort = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
@endphp

<div class="coaches-container">
    <div class="coaches-header">
        <h1 class="coaches-title">Тренеры — {{ $club->name ?? '' }}</h1>
        <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addCoachModal">+ Добавить тренера</button>
    </div>

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
        <div class="coach-card">
            <div class="coach-card-main">
                <div class="coach-info">
                    <div class="coach-name">{{ $cc->user->full_name }}</div>
                    <div class="coach-contacts">
                        @if($cc->user->phone)
                            <span class="coach-contact">@phone($cc->user->phone)</span>
                        @endif
                        @if($cc->user->email)
                            <span class="coach-contact">{{ $cc->user->email }}</span>
                        @endif
                    </div>
                </div>
                <div class="coach-details">
                    @if($cc->specialization)
                        <div class="detail-group">
                            <span class="detail-label">Специализация</span>
                            <span class="detail-value">{{ $cc->specialization }}</span>
                        </div>
                    @endif
                    @if($cc->hourly_rate)
                        <div class="detail-group">
                            <span class="detail-label">Ставки</span>
                            <span class="detail-value rate-value">1ч: {{ number_format($cc->hourly_rate, 0, '', ' ') }} &#8376;</span>
                            @foreach($cc->rates->sortBy('hours') as $rate)
                                <span class="detail-value rate-value">{{ $rate->hours }}ч: {{ number_format($rate->rate, 0, '', ' ') }} &#8376;</span>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="coach-card-actions">
                    <a href="{{ route('club.coaches.schedule', $cc->user_id) }}" class="action-btn schedule" title="Расписание">&#128197;</a>
                    <button class="action-btn edit" title="Редактировать" data-bs-toggle="modal" data-bs-target="#editModal{{ $cc->id }}">&#9998;</button>
                    <form action="{{ route('club.coaches.destroy', $cc->user_id) }}" method="POST" style="display:inline;" onsubmit="return confirm('Удалить тренера {{ $cc->user->full_name }}?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="action-btn delete" title="Удалить">&#10005;</button>
                    </form>
                </div>
            </div>
            @if($cc->schedules && $cc->schedules->count())
                <div class="coach-schedule">
                    <span class="detail-label">Расписание</span>
                    <div class="schedule-tags">
                        @foreach($cc->schedules->sortBy('day_of_week') as $s)
                            <span class="schedule-tag">{{ $dayShort[$s->day_of_week] }} {{ \Carbon\Carbon::parse($s->start_time)->format('H:i') }}&ndash;{{ \Carbon\Carbon::parse($s->end_time)->format('H:i') }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @empty
        <div class="empty-state">
            <p>Тренеры не найдены. Добавьте первого тренера.</p>
            <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addCoachModal">+ Добавить тренера</button>
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
                        <input type="text" name="specialization" class="form-input" placeholder="Например: Начинающие, Продвинутые">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ставка за час</label>
                        <input type="number" name="hourly_rate" class="form-input" placeholder="&#8376;/час" min="0" step="100">
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

<!-- Модалки редактирования -->
@foreach($clubCoaches as $cc)
<div class="modal fade" id="editModal{{ $cc->id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #111113; border: 1px solid #27272a; border-radius: 16px;">
            <div class="modal-header" style="border-bottom: 1px solid #27272a; padding: 20px 24px;">
                <h5 class="modal-title" style="font-weight: 700;">Редактировать — {{ $cc->user->full_name }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('club.coaches.update', $cc->user_id) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                <div class="modal-body" style="padding: 24px;">
                    <div class="form-group">
                        <label class="form-label">Фотография тренера</label>
                        <div style="display:flex;align-items:center;gap:14px;">
                            <div style="width:64px;height:64px;border-radius:12px;overflow:hidden;background:#16161a;border:1px solid #27272a;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                                @if($cc->photo)
                                    <img src="{{ $cc->photo }}" alt="фото" style="width:100%;height:100%;object-fit:cover;">
                                @else
                                    <i class="bi bi-person" style="font-size:28px;color:#52525b;"></i>
                                @endif
                            </div>
                            <div style="flex:1;">
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-input" style="padding:8px;">
                                <small style="color:#52525b;font-size:11px;display:block;margin-top:4px;">JPG/PNG/WebP. Размер изображения от 500×500 до 2000×2000 пикселей, до 4 МБ.</small>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Специализация</label>
                        <input type="text" name="specialization" class="form-input" value="{{ $cc->specialization }}" placeholder="Например: Начинающие, Продвинутые">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ставка за 1 час (базовая)</label>
                        <input type="number" name="hourly_rate" class="form-input" value="{{ $cc->hourly_rate ? intval($cc->hourly_rate) : '' }}" placeholder="&#8376;/час" min="0" step="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ставки по длительности</label>
                        <div class="rates-grid">
                            @for($h = 2; $h <= 6; $h++)
                                @php $existingRate = $cc->rates->firstWhere('hours', $h); @endphp
                                <div class="rate-row">
                                    <span class="rate-label">{{ $h }} {{ $h <= 4 ? 'часа' : 'часов' }}</span>
                                    <input type="hidden" name="rates[{{ $h - 2 }}][hours]" value="{{ $h }}">
                                    <input type="number" name="rates[{{ $h - 2 }}][rate]" class="form-input rate-input" value="{{ $existingRate ? intval($existingRate->rate) : '' }}" placeholder="&#8376;" min="0" step="100">
                                </div>
                            @endfor
                        </div>
                        <small style="color: #52525b; font-size: 11px; margin-top: 6px; display: block;">Если не указано — считается по базовой ставке * часы</small>
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
@endforeach

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
    .coaches-container { max-width: 1000px; margin: 0 auto; padding: 32px 24px; }
    .coaches-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; flex-wrap: wrap; gap: 16px; }
    .coaches-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    .btn-add { display: flex; align-items: center; gap: 8px; background: #22c55e; color: #0a0a0b; border: none; padding: 12px 22px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
    .btn-add:hover { background: #16a34a; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 24px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .coach-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; margin-bottom: 16px; overflow: hidden; transition: border-color 0.2s; }
    .coach-card:hover { border-color: #3f3f46; }
    .coach-card-main { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; gap: 24px; flex-wrap: wrap; }
    .coach-info { display: flex; flex-direction: column; gap: 6px; min-width: 200px; }
    .coach-name { font-size: 18px; font-weight: 700; color: #f4f4f5; }
    .coach-contacts { display: flex; gap: 16px; flex-wrap: wrap; }
    .coach-contact { font-size: 13px; color: #71717a; font-weight: 500; }
    .coach-details { display: flex; gap: 32px; flex-wrap: wrap; flex: 1; }
    .detail-group { display: flex; flex-direction: column; gap: 4px; }
    .detail-label { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; }
    .detail-value { font-size: 14px; font-weight: 600; color: #a1a1aa; }
    .rate-value { color: #22c55e; }
    .coach-card-actions { display: flex; gap: 8px; }
    .action-btn { width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 8px; cursor: pointer; color: #a1a1aa; font-size: 16px; transition: all 0.2s; text-decoration: none; }
    .action-btn.schedule:hover { border-color: #3b82f6; color: #3b82f6; }
    .action-btn.edit:hover { border-color: #3b82f6; color: #3b82f6; }
    .action-btn.delete:hover { border-color: #ef4444; color: #ef4444; }

    .coach-schedule { padding: 0 24px 20px; display: flex; flex-direction: column; gap: 8px; }
    .schedule-tags { display: flex; gap: 8px; flex-wrap: wrap; }
    .schedule-tag { display: inline-flex; align-items: center; padding: 5px 12px; background: #16161a; border: 1px solid #27272a; border-radius: 8px; font-size: 13px; font-weight: 600; color: #a1a1aa; }

    .empty-state { text-align: center; padding: 60px 20px; color: #71717a; }
    .empty-state p { font-size: 16px; margin-bottom: 20px; }

    /* Form inside modals */
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-input::placeholder { color: #52525b; }
    .rates-grid { display: flex; flex-direction: column; gap: 6px; }
    .rate-row { display: flex; align-items: center; gap: 10px; }
    .rate-label { font-size: 13px; font-weight: 600; color: #a1a1aa; min-width: 70px; }
    .rate-input { width: 140px !important; padding: 8px 12px !important; font-size: 14px !important; }
    .btn-cancel { flex: 1; padding: 14px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }

    /* Search results */
    .search-results { max-height: 200px; overflow-y: auto; }
    .search-result-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; margin-top: 8px; cursor: pointer; transition: all 0.2s; }
    .search-result-item:hover { border-color: #22c55e; background: #1a1a1e; }
    .search-result-name { font-size: 14px; font-weight: 600; color: #f4f4f5; }
    .search-result-phone { font-size: 13px; color: #71717a; }

    /* Selected user */
    .selected-user-block { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; padding: 14px 16px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; }
    .selected-user-info { display: flex; align-items: center; justify-content: space-between; }
    .selected-user-name { font-size: 15px; font-weight: 700; color: #22c55e; }
    .remove-selected-btn { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: transparent; border: 1px solid #27272a; border-radius: 6px; cursor: pointer; color: #71717a; font-size: 14px; transition: all 0.2s; }
    .remove-selected-btn:hover { border-color: #ef4444; color: #ef4444; }

    .modal-footer { display: flex; gap: 12px; }

    @media (max-width: 768px) {
        .coaches-header { flex-direction: column; align-items: flex-start; }
        .coach-card-main { flex-direction: column; align-items: flex-start; }
        .coach-details { gap: 16px; }
    }
</style>
@endsection

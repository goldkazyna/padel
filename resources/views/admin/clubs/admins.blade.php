@extends('layouts.app')

@section('title', 'Админы клуба')

@section('content')
<div class="page-header">
    <div>
        <h2>Управление клубом</h2>
        <p>{{ $club->name }}</p>
    </div>
    <a href="{{ route('admin.clubs.index') }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i>
        <span>Назад к клубам</span>
    </a>
</div>

{{-- АДМИНЫ --}}
<h4 class="mb-3"><i class="bi bi-person-badge me-2"></i>Админы</h4>
<div class="row g-4 mb-5">
    <!-- Current admins -->
    <div class="col-lg-6">
        <div class="card-dark h-100">
            <div class="card-header">
                <h5><i class="bi bi-people"></i> Текущие админы</h5>
            </div>
            <div class="card-body">
                @if($club->admins->count() > 0)
                    @foreach($club->admins as $admin)
                        <div class="d-flex align-items-center justify-content-between p-3 rounded-3 mb-3" 
                             style="background: var(--bg-secondary);">
                            <div class="d-flex align-items-center gap-3">
                                <div class="user-avatar">
                                    {{ mb_strtoupper(mb_substr($admin->first_name, 0, 1) . mb_substr($admin->last_name, 0, 1)) }}
                                </div>
                                <div>
                                    <div class="fw-medium">{{ $admin->full_name }}</div>
                                    <small class="text-secondary">{{ $admin->email }}</small>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <form action="{{ route('admin.clubs.admins.password', [$club, $admin]) }}" method="POST"
                                      class="d-flex align-items-center gap-2">
                                    @csrf
                                    <input type="text" name="password" class="form-control form-control-sm"
                                           style="width: 150px;" placeholder="новый пароль"
                                           minlength="6" required autocomplete="new-password">
                                    <button type="submit" class="btn-primary-custom btn-sm" title="Сменить пароль">
                                        <i class="bi bi-key"></i>
                                    </button>
                                </form>
                                <form action="{{ route('admin.clubs.admins.remove', [$club, $admin]) }}" method="POST"
                                      onsubmit="return confirm('Удалить админа?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-danger-custom btn-sm">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                @else
                    <div class="text-center text-secondary py-5">
                        <i class="bi bi-people fs-1 d-block mb-3 opacity-50"></i>
                        Нет назначенных админов
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Add admin -->
    <div class="col-lg-6">
        <div class="card-dark h-100">
            <div class="card-header">
                <h5><i class="bi bi-person-plus"></i> Добавить админа</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Email, телефон или имя</label>
                    <div class="input-group">
                        <input type="text" id="searchAdminEmail" class="form-control" placeholder="email, +7 777…, или имя">
                        <button type="button" class="btn-primary-custom" onclick="searchAdmin()">
                            <i class="bi bi-search"></i> Найти
                        </button>
                    </div>
                </div>
                <div id="searchAdminResult"></div>
            </div>
        </div>
    </div>
</div>

{{-- МОДЕРАТОРЫ --}}
<h4 class="mb-3"><i class="bi bi-shield-check me-2"></i>Модераторы</h4>
<div class="row g-4">
    <!-- Current moderators -->
    <div class="col-lg-6">
        <div class="card-dark h-100">
            <div class="card-header">
                <h5><i class="bi bi-people"></i> Текущие модераторы</h5>
            </div>
            <div class="card-body">
                @if($club->moderators->count() > 0)
                    @foreach($club->moderators as $moderator)
                        <div class="d-flex align-items-center justify-content-between p-3 rounded-3 mb-3" 
                             style="background: var(--bg-secondary);">
                            <div class="d-flex align-items-center gap-3">
                                <div class="user-avatar">
                                    {{ mb_strtoupper(mb_substr($moderator->first_name, 0, 1) . mb_substr($moderator->last_name, 0, 1)) }}
                                </div>
                                <div>
                                    <div class="fw-medium">{{ $moderator->full_name }}</div>
                                    <small class="text-secondary">{{ $moderator->email }}</small>
                                </div>
                            </div>
                            <form action="{{ route('admin.clubs.moderators.remove', [$club, $moderator]) }}" method="POST"
                                  onsubmit="return confirm('Удалить модератора?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger-custom btn-sm">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </form>
                        </div>
                    @endforeach
                @else
                    <div class="text-center text-secondary py-5">
                        <i class="bi bi-shield fs-1 d-block mb-3 opacity-50"></i>
                        Нет назначенных модераторов
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Add moderator -->
    <div class="col-lg-6">
        <div class="card-dark h-100">
            <div class="card-header">
                <h5><i class="bi bi-person-plus"></i> Добавить модератора</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Email, телефон или имя</label>
                    <div class="input-group">
                        <input type="text" id="searchModeratorEmail" class="form-control" placeholder="email, +7 777…, или имя">
                        <button type="button" class="btn-primary-custom" onclick="searchModerator()">
                            <i class="bi bi-search"></i> Найти
                        </button>
                    </div>
                </div>
                <div id="searchModeratorResult"></div>
            </div>
        </div>
    </div>
</div>

<script>
const ADD_ADMIN_URL = "{{ route('admin.clubs.admins.add', $club) }}";
const ADD_MODERATOR_URL = "{{ route('admin.clubs.moderators.add', $club) }}";
const CSRF = "{{ csrf_token() }}";
const SEARCH_URL = "{{ route('admin.players.search') }}";

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function formatPhone(p) {
    const d = (p || '').replace(/\D/g, '');
    if (d.length === 11) return `+${d[0]} ${d.slice(1,4)} ${d.slice(4,7)} ${d.slice(7,9)} ${d.slice(9,11)}`;
    return p || '';
}

function initials(name) {
    return (name || '?').split(' ').filter(Boolean).map(n => n[0]).join('').slice(0,2).toUpperCase();
}

// Универсальный поиск + рендер списка
function runSearch(inputId, resultId, actionUrl, btnLabel) {
    const q = document.getElementById(inputId).value.trim();
    const resultDiv = document.getElementById(resultId);

    if (q.length < 2) {
        resultDiv.innerHTML = '<div class="alert-danger-custom">Введите минимум 2 символа</div>';
        return;
    }

    resultDiv.innerHTML = '<div class="text-secondary py-2">Поиск…</div>';

    fetch(`${SEARCH_URL}?q=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(data => {
            const players = data.players || [];
            if (players.length === 0) {
                resultDiv.innerHTML = '<div class="alert-danger-custom">Игрок не найден</div>';
                return;
            }
            resultDiv.innerHTML = players.map(p => `
                <form action="${actionUrl}" method="POST" class="p-3 rounded-3 mb-2" style="background: var(--bg-secondary);">
                    <input type="hidden" name="_token" value="${CSRF}">
                    <input type="hidden" name="user_id" value="${p.id}">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="user-avatar">${initials(p.name)}</div>
                        <div>
                            <div class="fw-medium">${escapeHtml(p.name)}</div>
                            <small class="text-secondary d-block">${escapeHtml(p.email || '—')}</small>
                            <small class="text-secondary">${escapeHtml(formatPhone(p.phone))}</small>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-secondary">Пароль (необязательно, мин. 6 симв.)</label>
                        <input type="text" name="password" class="form-control"
                               placeholder="Оставьте пустым, чтобы не менять"
                               autocomplete="new-password" minlength="6">
                    </div>
                    <button type="submit" class="btn-primary-custom btn-sm">
                        <i class="bi bi-plus"></i> ${btnLabel}
                    </button>
                </form>
            `).join('');
        })
        .catch(() => {
            resultDiv.innerHTML = '<div class="alert-danger-custom">Ошибка поиска</div>';
        });
}

function searchAdmin() {
    runSearch('searchAdminEmail', 'searchAdminResult', ADD_ADMIN_URL, 'Назначить админом');
}

function searchModerator() {
    runSearch('searchModeratorEmail', 'searchModeratorResult', ADD_MODERATOR_URL, 'Назначить модератором');
}

document.getElementById('searchAdminEmail').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); searchAdmin(); }
});
document.getElementById('searchModeratorEmail').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); searchModerator(); }
});
</script>
@endsection
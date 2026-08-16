@extends('layouts.app')

@section('title', 'Клубы')

@section('content')
<div class="page-header">
    <div>
        <h2>Клубы</h2>
        <p>Управление клубами платформы</p>
    </div>
    <a href="{{ route('admin.clubs.create') }}" class="btn-primary-custom">
        <i class="bi bi-plus-circle"></i>
        <span>Добавить клуб</span>
    </a>
</div>

{{-- Вкладки: обычные клубы и комьюнити --}}
<div class="clubs-tabs">
    <button type="button" class="clubs-tab is-active" data-tab="clubs">
        Клубы <span class="clubs-tab-count">{{ $clubs->count() }}</span>
    </button>
    <button type="button" class="clubs-tab" data-tab="communities">
        Комьюнити <span class="clubs-tab-count">{{ $communities->count() }}</span>
    </button>
</div>

@foreach([['clubs', $clubs, 'Клубов пока нет'], ['communities', $communities, 'Комьюнити пока нет']] as [$key, $list, $emptyText])
    <div class="clubs-pane" data-pane="{{ $key }}" @if($key !== 'clubs') hidden @endif>
        @if($list->isEmpty())
            <div class="clubs-empty">
                <i class="bi bi-buildings"></i>
                <p>{{ $emptyText }}</p>
            </div>
        @else
            <div class="clubs-grid">
                @foreach($list as $club)
                    <div class="club-card">
                        <div class="club-card-top">
                            @if($club->logo_url)
                                <img class="club-logo" src="{{ $club->logo_url }}" alt="{{ $club->name }}">
                            @else
                                <div class="club-logo club-logo-text">
                                    {{ mb_strtoupper(mb_substr($club->name, 0, 2)) }}
                                </div>
                            @endif
                            <div class="club-card-title">
                                <div class="club-name">{{ $club->name }}</div>
                                <div class="club-sub">
                                    {{ $club->city ?: $club->address }}@if($club->phone) · @phoneFmt($club->phone)@endif
                                </div>
                            </div>
                            <div class="club-flags">
                                @if($club->is_test)
                                    <span class="club-badge club-badge-test">Тест</span>
                                @endif
                                @if($club->coming_soon)
                                    <span class="club-badge club-badge-soon">Скоро</span>
                                @elseif($club->is_active)
                                    <span class="club-badge club-badge-on">Активен</span>
                                @else
                                    <span class="club-badge club-badge-off">Неактивен</span>
                                @endif
                            </div>
                        </div>

                        <div class="club-admins">
                            <div class="club-admins-label">Администраторы · {{ $club->admins_count }}</div>
                            @forelse($club->admins as $admin)
                                <div class="club-admin">
                                    <div class="club-admin-av">
                                        {{ mb_strtoupper(mb_substr($admin->name ?: '?', 0, 1)) }}
                                    </div>
                                    <div class="club-admin-info">
                                        <div class="club-admin-name">{{ $admin->name }}</div>
                                        @if($admin->email)
                                            <button type="button" class="club-admin-mail" data-copy="{{ $admin->email }}"
                                                    title="Скопировать почту">
                                                <span>{{ $admin->email }}</span>
                                                <i class="bi bi-copy"></i>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div class="club-admins-none">Пока никого не назначили</div>
                            @endforelse
                        </div>

                        <div class="club-card-foot">
                            <span class="club-metric">Турниров <b>{{ $club->tournaments_count ?? 0 }}</b></span>
                            <div class="club-card-actions">
                                <a href="{{ route('admin.clubs.admins', $club) }}" class="btn-outline-custom btn-sm" title="Админы">
                                    <i class="bi bi-people"></i>
                                </a>
                                <a href="{{ route('admin.clubs.edit', $club) }}" class="btn-outline-custom btn-sm" title="Редактировать">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form action="{{ route('admin.clubs.destroy', $club) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Удалить клуб «{{ $club->name }}»?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-danger-custom btn-sm" title="Удалить">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endforeach

<script>
document.addEventListener('DOMContentLoaded', function () {
    var tabs = document.querySelectorAll('.clubs-tab');
    var panes = document.querySelectorAll('.clubs-pane');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); });
            panes.forEach(function (pane) {
                pane.hidden = pane.dataset.pane !== tab.dataset.tab;
            });
        });
    });

    // Почта админа копируется в один клик — обычно она и нужна из этого списка.
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(btn.dataset.copy).then(function () {
                btn.classList.add('is-copied');
                setTimeout(function () { btn.classList.remove('is-copied'); }, 1200);
            });
        });
    });
});
</script>

<style>
/* ---- вкладки ---- */
.clubs-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 18px;
    border-bottom: 1px solid var(--border);
}
.clubs-tab {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 10px 16px;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}
.clubs-tab:hover { color: var(--text-primary); }
.clubs-tab.is-active { color: var(--accent); border-bottom-color: var(--accent); }
.clubs-tab-count {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 99px;
    padding: 1px 8px;
    font-size: 11px;
    font-weight: 700;
}
.clubs-tab.is-active .clubs-tab-count {
    background: var(--accent-glow);
    border-color: transparent;
    color: var(--accent);
}

/* ---- сетка карточек ---- */
.clubs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
    gap: 14px;
}
.club-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 16px;
    transition: border-color .15s;
    /* Колонка с прижатым низом: у клубов разное число админов, и без этого
       подвалы соседних карточек в ряду скакали по высоте. */
    display: flex;
    flex-direction: column;
}
.club-card:hover { border-color: var(--border-light); }

.club-card-top {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 14px;
}
.club-logo {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    object-fit: cover;
    flex: none;
    background: var(--bg-secondary);
}
.club-logo-text {
    display: grid;
    place-items: center;
    background: var(--accent-glow);
    color: var(--accent);
    font-weight: 800;
    font-size: 16px;
}
.club-card-title { min-width: 0; }
.club-name {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    word-break: break-word;
}
.club-sub {
    color: var(--text-muted);
    font-size: 12px;
    margin-top: 2px;
}
.club-flags {
    margin-left: auto;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 5px;
}
.club-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 99px;
    white-space: nowrap;
}
.club-badge-on { background: rgba(34,197,94,.14); color: var(--accent); }
.club-badge-off { background: rgba(156,163,175,.12); color: var(--text-muted); }
.club-badge-soon { background: rgba(234,179,8,.14); color: #eab308; }
.club-badge-test { background: rgba(96,165,250,.14); color: #60a5fa; }

/* ---- админы ---- */
.club-admins {
    background: var(--bg-secondary);
    border-radius: 10px;
    padding: 11px 12px;
    margin-bottom: 13px;
}
.club-admins-label {
    font-size: 10px;
    letter-spacing: .09em;
    text-transform: uppercase;
    color: var(--text-muted);
    font-weight: 700;
    margin-bottom: 8px;
}
.club-admins-none {
    color: var(--text-muted);
    font-size: 12.5px;
    padding: 4px 0;
}
.club-admin {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 5px 0;
}
.club-admin-av {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--bg-card-hover);
    color: var(--text-secondary);
    display: grid;
    place-items: center;
    font-size: 11px;
    font-weight: 700;
    flex: none;
}
.club-admin-info { min-width: 0; }
.club-admin-name {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-primary);
    line-height: 1.3;
}
.club-admin-mail {
    background: none;
    border: none;
    padding: 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text-secondary);
    font-size: 12px;
    cursor: pointer;
    max-width: 100%;
}
.club-admin-mail span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.club-admin-mail i { color: var(--text-muted); font-size: 11px; flex: none; }
.club-admin-mail:hover, .club-admin-mail:hover i { color: var(--accent); }
.club-admin-mail.is-copied, .club-admin-mail.is-copied i { color: var(--accent); }

/* ---- подвал карточки ---- */
.club-card-foot {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-top: auto;
    padding-top: 13px;
    border-top: 1px solid var(--border);
}
.club-metric { font-size: 12px; color: var(--text-secondary); }
.club-metric b { color: var(--text-primary); font-weight: 700; }
.club-card-actions { margin-left: auto; display: flex; gap: 6px; }

.clubs-empty {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 46px 20px;
    text-align: center;
    color: var(--text-muted);
}
.clubs-empty i { font-size: 30px; display: block; margin-bottom: 10px; }
.clubs-empty p { margin: 0; font-size: 14px; }
</style>
@endsection

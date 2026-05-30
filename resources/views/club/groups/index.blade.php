@extends('layouts.app')
@section('title', 'Группы')

@section('content')

<div class="groups-container">
    <div class="groups-header">
        <h1 class="groups-title">Группы <span class="groups-title-club">— {{ $club->name }}</span></h1>
        <button class="btn-add" onclick="document.getElementById('createGroupModal').style.display='flex'">+ Создать группу</button>
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

    <div class="groups-tabs">
        <a href="{{ route('club.groups.index') }}"
           class="tab-link {{ $tab === 'active' ? 'tab-active' : '' }}">
            Активные <span class="tab-count">{{ $activeCount }}</span>
        </a>
        <a href="{{ route('club.groups.index', ['tab' => 'archived']) }}"
           class="tab-link {{ $tab === 'archived' ? 'tab-active' : '' }}">
            Архивные <span class="tab-count">{{ $archivedCount }}</span>
        </a>
    </div>

    @forelse($groups as $group)
        @php
            $full = $group->capacity && $group->active_members_count >= $group->capacity;
            $cm = $group->coach_id ? ($coachMeta[$group->coach_id] ?? null) : null;
        @endphp
        <a href="{{ route('club.groups.show', $group) }}" class="group-card">
            {{-- Название --}}
            <div class="gc-name-block">
                <div class="gc-name">{{ $group->name }}</div>
                @if($group->note)
                    <div class="gc-sub">{{ $group->note }}</div>
                @elseif($group->capacity)
                    <div class="gc-sub">макс. {{ $group->capacity }} участников</div>
                @endif
            </div>

            {{-- Тренер --}}
            <div class="gc-col">
                <span class="gc-label">Тренер</span>
                @if($cm)
                    <span class="gc-coach">
                        <span class="gc-avatar" @if(!$cm['photo']) style="background:{{ $cm['color'] }}" @endif>
                            @if($cm['photo'])<img src="{{ $cm['photo'] }}" alt="">@else{{ $cm['initials'] }}@endif
                        </span>
                        <span class="gc-coach-name">{{ $cm['name'] }}</span>
                    </span>
                @else
                    <span class="gc-value gc-muted">— без тренера —</span>
                @endif
            </div>

            {{-- Участников --}}
            <div class="gc-col">
                <span class="gc-label">Участников</span>
                <span class="gc-value">
                    <span class="{{ $full ? 'gc-full' : '' }}">{{ $group->active_members_count }}</span>@if($group->capacity)<span class="gc-muted"> / {{ $group->capacity }}</span>@endif
                </span>
            </div>

            {{-- Цена --}}
            <div class="gc-col">
                <span class="gc-label">Цена / занятие</span>
                <span class="gc-value {{ $group->price_per_session > 0 ? 'gc-price' : 'gc-muted' }}">
                    @if($group->price_per_session > 0)
                        {{ number_format($group->price_per_session, 0, '.', ' ') }} ₸
                    @else
                        —
                    @endif
                </span>
            </div>

            {{-- Статус --}}
            <div class="gc-col">
                <span class="gc-label">Статус</span>
                @if($group->status === 'active')
                    <span class="badge-active"><span class="badge-dot"></span> Активна</span>
                @else
                    <span class="badge-archived">Архив</span>
                @endif
            </div>

            {{-- Стрелка --}}
            <span class="gc-arrow">&#8594;</span>
        </a>
    @empty
        <div class="empty-state">
            <p>Групп пока нет. Создайте первую группу.</p>
            <button class="btn-add" onclick="document.getElementById('createGroupModal').style.display='flex'">+ Создать группу</button>
        </div>
    @endforelse
</div>

<!-- Модал создания группы -->
<div id="createGroupModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Создать группу</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('createGroupModal').style.display='none'">&#10005;</button>
        </div>
        <form method="POST" action="{{ route('club.groups.store') }}">
            @csrf
            <div class="modal-body-area">
                <div class="form-group">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" name="name" class="form-input" required
                           placeholder="Например: Утренняя группа" value="{{ old('name') }}">
                </div>

                <div class="form-group">
                    <label class="form-label">Тренер</label>
                    <select name="coach_id" class="form-input">
                        <option value="">— без тренера —</option>
                        @foreach($coaches as $coach)
                            <option value="{{ $coach->user_id }}" {{ old('coach_id') == $coach->user_id ? 'selected' : '' }}>
                                {{ $coach->user->name ?? 'Тренер #'.$coach->user_id }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Цена за занятие (₸)</label>
                        <input type="number" name="price_per_session" class="form-input" min="0" step="100"
                               placeholder="0" value="{{ old('price_per_session') }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Макс. участников</label>
                        <input type="number" name="capacity" class="form-input" min="1" max="100"
                               placeholder="Не ограничено" value="{{ old('capacity') }}">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Заметка</label>
                    <textarea name="note" class="form-input" rows="3"
                              placeholder="Дополнительная информация о группе...">{{ old('note') }}</textarea>
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('createGroupModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Создать</button>
            </div>
        </form>
    </div>
</div>

<style>
    .groups-container { max-width: 1200px; margin: 0 auto; padding: 32px 24px; }
    .groups-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; flex-wrap: wrap; gap: 16px; }
    .groups-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    .groups-title-club { color: #71717a; font-weight: 500; }
    .btn-add { display: flex; align-items: center; gap: 8px; background: #22c55e; color: #0a0a0b; border: none; padding: 12px 22px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; text-decoration: none; }
    .btn-add:hover { background: #16a34a; }

    .groups-tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid #27272a; padding-bottom: 0; }
    .tab-link { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: transparent; color: #71717a; border: none; border-bottom: 2px solid transparent; font-size: 14px; font-weight: 700; cursor: pointer; text-decoration: none; transition: all 0.15s; margin-bottom: -1px; }
    .tab-link:hover { color: #a1a1aa; }
    .tab-link.tab-active { color: #22c55e; border-bottom-color: #22c55e; }
    .tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 20px; padding: 0 6px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; font-size: 11px; font-weight: 700; color: #a1a1aa; }
    .tab-link.tab-active .tab-count { background: rgba(34,197,94,0.15); border-color: rgba(34,197,94,0.3); color: #22c55e; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 24px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .group-card { display: grid; grid-template-columns: minmax(200px, 1.7fr) 1.5fr 0.9fr 1fr 0.95fr 44px; align-items: center; gap: 20px; background: #111113; border: 1px solid #27272a; border-radius: 16px; margin-bottom: 14px; padding: 18px 24px; transition: border-color 0.2s, background 0.2s; text-decoration: none; }
    .group-card:hover { border-color: #3f3f46; background: #141416; }

    .gc-name-block { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .gc-name { font-size: 16px; font-weight: 700; color: #f4f4f5; line-height: 1.35; overflow-wrap: anywhere; }
    .gc-sub { font-size: 13px; color: #71717a; font-weight: 500; overflow-wrap: anywhere; }

    .gc-col { display: flex; flex-direction: column; gap: 7px; min-width: 0; }
    .gc-label { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; }
    .gc-value { font-size: 14px; font-weight: 700; color: #f4f4f5; }
    .gc-muted { color: #71717a; font-weight: 500; }
    .gc-price { color: #22c55e; }
    .gc-full { color: #eab308; }

    .gc-coach { display: inline-flex; align-items: center; gap: 9px; min-width: 0; }
    .gc-avatar { width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; color: #fff; overflow: hidden; }
    .gc-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .gc-coach-name { font-size: 14px; font-weight: 600; color: #e4e4e7; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .badge-active { display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; background: rgba(34,197,94,0.12); color: #22c55e; border: 1px solid rgba(34,197,94,0.28); border-radius: 7px; font-size: 12px; font-weight: 700; width: fit-content; }
    .badge-dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; }
    .badge-archived { display: inline-flex; align-items: center; padding: 5px 11px; background: rgba(113,113,122,0.15); color: #71717a; border: 1px solid rgba(113,113,122,0.3); border-radius: 7px; font-size: 12px; font-weight: 700; width: fit-content; }

    .gc-arrow { width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 9px; color: #a1a1aa; font-size: 18px; transition: all 0.2s; }
    .group-card:hover .gc-arrow { border-color: #22c55e; color: #22c55e; }

    .empty-state { text-align: center; padding: 60px 20px; color: #71717a; }
    .empty-state p { font-size: 16px; margin-bottom: 20px; }

    /* Modal */
    .modal-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; margin: 20px; }
    .modal-header-row { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid #27272a; }
    .modal-title-text { font-size: 17px; font-weight: 700; color: #f4f4f5; margin: 0; }
    .modal-close-btn { background: none; border: none; color: #71717a; font-size: 16px; cursor: pointer; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; }
    .modal-close-btn:hover { color: #ef4444; }
    .modal-body-area { padding: 24px; }
    .modal-footer-row { display: flex; gap: 12px; padding: 20px 24px; border-top: 1px solid #27272a; }
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-size: 12px; font-weight: 700; color: #a1a1aa; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-input { width: 100%; background: #16161a; border: 1px solid #27272a; border-radius: 10px; padding: 12px 16px; font-size: 15px; color: #f4f4f5; font-weight: 500; font-family: inherit; box-sizing: border-box; }
    .form-input:focus { outline: none; border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.15); }
    .form-input::placeholder { color: #52525b; }
    .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .btn-cancel { flex: 1; padding: 14px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }

    @media (max-width: 900px) {
        .groups-header { flex-direction: column; align-items: flex-start; }
        .group-card { grid-template-columns: 1fr 1fr; gap: 16px 20px; position: relative; }
        .gc-name-block { grid-column: 1 / -1; }
        .gc-arrow { position: absolute; top: 18px; right: 18px; }
        .form-row-2 { grid-template-columns: 1fr; }
    }
    @media (max-width: 520px) {
        .group-card { grid-template-columns: 1fr; }
    }
</style>

@endsection

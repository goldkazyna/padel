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

    <div class="groups-grid">
    @forelse($groups as $group)
        @php
            $full = $group->capacity && $group->active_members_count >= $group->capacity;
            $cm = $group->coach_id ? ($coachMeta[$group->coach_id] ?? null) : null;
        @endphp
        <a href="{{ route('club.groups.show', $group) }}" class="group-card">
            {{-- Название + статус --}}
            <div class="gc-top">
                <div class="gc-name-wrap">
                    <div class="gc-name">{{ $group->name }}</div>
                    @if($group->note)<div class="gc-note">{{ $group->note }}</div>@endif
                </div>
                @if($group->status === 'active')
                    <span class="gc-status active"><span class="gc-live"></span> Активна</span>
                @else
                    <span class="gc-status archived"><i class="bi bi-archive"></i> Архив</span>
                @endif
            </div>

            {{-- Тренер · цена  ····  заполненность (красным если полная) --}}
            <div class="gc-mid">
                <div class="gc-info">
                    @if($cm)<span class="gc-coach-name">{{ $cm['name'] }}</span>@else<span class="gc-muted">без тренера</span>@endif
                    @if($group->price_per_session > 0)<span class="gc-sep">·</span><span class="gc-muted">{{ number_format($group->price_per_session, 0, '.', ' ') }} ₸</span>@endif
                </div>
                <div class="gc-count {{ $full ? 'full' : '' }}">{{ $group->active_members_count }}@if($group->capacity)/{{ $group->capacity }}@endif</div>
            </div>

            {{-- Остаток занятий у участников --}}
            @if($group->members->isNotEmpty())
                <div class="gc-dots" title="Остаток занятий: зелёный — 3+, красный — 1–2, серый — 0">
                    @foreach($group->members as $m)
                        @php $rem = (int) $m->enrollments->sum('sessions') - $m->attendance->where('charged', true)->count(); @endphp
                        <span class="gc-dot {{ $rem <= 0 ? 'gcd-zero' : ($rem <= 2 ? 'gcd-low' : 'gcd-ok') }}"></span>
                    @endforeach
                </div>
            @endif
        </a>
    @empty
        <div class="empty-state">
            <p>Групп пока нет. Создайте первую группу.</p>
            <button class="btn-add" onclick="document.getElementById('createGroupModal').style.display='flex'">+ Создать группу</button>
        </div>
    @endforelse
    </div>
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
                        <label class="form-label">Цена занятия для клиента (₸)</label>
                        <input type="number" name="price_per_session" class="form-input" min="0" step="1"
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
    .groups-container { max-width: 1200px; margin: 0 auto; padding: 24px 16px 40px; }
    .groups-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: start; }
    .groups-grid .empty-state { grid-column: 1 / -1; }
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

    .group-card { display: block; background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; padding: 16px 18px; transition: border-color 0.15s, background 0.15s; text-decoration: none; }
    .group-card:hover { border-color: rgba(255,255,255,0.14); background: #171a1e; }

    .gc-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
    .gc-name { font-size: 17px; font-weight: 800; color: #f4f6f7; line-height: 1.25; overflow-wrap: anywhere; }
    .gc-note { font-size: 13px; color: #7c848a; margin-top: 5px; overflow-wrap: anywhere; }
    .gc-muted { color: #8b9298; }

    .gc-status { flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: .3px; }
    .gc-status.active { background: rgba(34,197,94,0.14); color: #34d17f; }
    .gc-status.archived { background: rgba(139,146,152,0.14); color: #8b9298; }
    .gc-status i { font-size: 11px; }
    .gc-live { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 0 rgba(34,197,94,0.45); animation: gcpulse 2s infinite; }
    @keyframes gcpulse { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,0.45); } 70% { box-shadow: 0 0 0 6px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
    @media (prefers-reduced-motion: reduce) { .gc-live { animation: none; } }

    .gc-name-wrap { min-width: 0; }
    .gc-mid { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 13px; }
    .gc-info { min-width: 0; display: flex; align-items: center; gap: 7px; font-size: 13.5px; color: #8b9298; overflow: hidden; white-space: nowrap; }
    .gc-coach-name { font-weight: 600; color: #b3babf; overflow: hidden; text-overflow: ellipsis; }
    .gc-sep { color: #3a4045; }
    .gc-count { flex-shrink: 0; font-size: 16px; font-weight: 800; color: #d4d7da; font-variant-numeric: tabular-nums; letter-spacing: -0.2px; }
    .gc-count.full { color: #ef7a73; }

    .gc-dots { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 12px; }
    .gc-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .gcd-ok { background: #22c55e; }
    .gcd-low { background: #e5564e; }
    .gcd-zero { background: #4b5157; }

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

    @media (max-width: 720px) {
        .groups-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 900px) {
        .groups-header { flex-direction: column; align-items: flex-start; }
        .form-row-2 { grid-template-columns: 1fr; }
    }
</style>

@endsection

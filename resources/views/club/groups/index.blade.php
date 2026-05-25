@extends('layouts.app')
@section('title', 'Группы')

@section('content')

<div class="groups-container">
    <div class="groups-header">
        <h1 class="groups-title">Группы — {{ $club->name }}</h1>
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

    @forelse($groups as $group)
        <div class="group-card">
            <div class="group-card-main">
                <div class="group-info">
                    <div class="group-name">
                        <a href="{{ route('club.groups.show', $group) }}" class="group-name-link">{{ $group->name }}</a>
                        @if($group->capacity)
                            <span class="group-capacity">(макс. {{ $group->capacity }})</span>
                        @endif
                    </div>
                </div>
                <div class="group-details">
                    <div class="detail-group">
                        <span class="detail-label">Тренер</span>
                        <span class="detail-value">{{ optional($group->coach)->name ?? '—' }}</span>
                    </div>
                    <div class="detail-group">
                        <span class="detail-label">Участников</span>
                        <span class="detail-value">
                            {{ $group->active_members_count }}@if($group->capacity)<span class="detail-muted"> / {{ $group->capacity }}</span>@endif
                        </span>
                    </div>
                    <div class="detail-group">
                        <span class="detail-label">Цена / занятие</span>
                        <span class="detail-value {{ $group->price_per_session > 0 ? 'price-value' : '' }}">
                            @if($group->price_per_session > 0)
                                {{ number_format($group->price_per_session, 0, '.', ' ') }} ₸
                            @else
                                —
                            @endif
                        </span>
                    </div>
                    <div class="detail-group">
                        <span class="detail-label">Статус</span>
                        @if($group->status === 'active')
                            <span class="badge-active">Активна</span>
                        @else
                            <span class="badge-archived">Архив</span>
                        @endif
                    </div>
                </div>
                <div class="group-card-actions">
                    <a href="{{ route('club.groups.show', $group) }}" class="action-btn open" title="Открыть">&#8594;</a>
                </div>
            </div>
        </div>
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
    .groups-container { max-width: 1000px; margin: 0 auto; padding: 32px 24px; }
    .groups-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; flex-wrap: wrap; gap: 16px; }
    .groups-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    .btn-add { display: flex; align-items: center; gap: 8px; background: #22c55e; color: #0a0a0b; border: none; padding: 12px 22px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; text-decoration: none; }
    .btn-add:hover { background: #16a34a; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 24px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .group-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; margin-bottom: 16px; overflow: hidden; transition: border-color 0.2s; }
    .group-card:hover { border-color: #3f3f46; }
    .group-card-main { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; gap: 24px; flex-wrap: wrap; }
    .group-info { display: flex; flex-direction: column; gap: 4px; min-width: 180px; flex: 1; }
    .group-name { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
    .group-name-link { font-size: 18px; font-weight: 700; color: #f4f4f5; text-decoration: none; }
    .group-name-link:hover { color: #22c55e; }
    .group-capacity { font-size: 13px; color: #71717a; font-weight: 500; }
    .group-details { display: flex; gap: 32px; flex-wrap: wrap; flex: 2; }
    .detail-group { display: flex; flex-direction: column; gap: 4px; }
    .detail-label { font-size: 11px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: 0.5px; }
    .detail-value { font-size: 14px; font-weight: 600; color: #a1a1aa; }
    .detail-muted { color: #71717a; font-weight: 500; }
    .price-value { color: #22c55e; }
    .badge-active { display: inline-flex; align-items: center; padding: 4px 10px; background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); border-radius: 6px; font-size: 12px; font-weight: 700; }
    .badge-archived { display: inline-flex; align-items: center; padding: 4px 10px; background: rgba(113,113,122,0.15); color: #71717a; border: 1px solid rgba(113,113,122,0.3); border-radius: 6px; font-size: 12px; font-weight: 700; }
    .group-card-actions { display: flex; gap: 8px; }
    .action-btn { width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 8px; cursor: pointer; color: #a1a1aa; font-size: 18px; transition: all 0.2s; text-decoration: none; }
    .action-btn.open:hover { border-color: #22c55e; color: #22c55e; }

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

    @media (max-width: 768px) {
        .groups-header { flex-direction: column; align-items: flex-start; }
        .group-card-main { flex-direction: column; align-items: flex-start; }
        .group-details { gap: 16px; }
        .form-row-2 { grid-template-columns: 1fr; }
    }
</style>

@endsection

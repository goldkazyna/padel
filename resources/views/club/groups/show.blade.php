@extends('layouts.app')
@section('title', $group->name)

@section('content')

<div class="group-show-container">

    <div class="group-show-header">
        <div class="group-show-title-block">
            <a href="{{ route('club.groups.index') }}" class="back-link">&#8592; Группы</a>
            <h1 class="group-show-title">{{ $group->name }}</h1>
            <div class="group-show-meta">
                @if($group->coach)
                    <span class="meta-item">{{ $group->coach->name }}</span>
                    <span class="meta-sep">&nbsp;·&nbsp;</span>
                @endif
                @if($group->price_per_session > 0)
                    <span class="meta-item meta-price">{{ number_format($group->price_per_session, 0, '.', ' ') }} ₸/занятие</span>
                    <span class="meta-sep">&nbsp;·&nbsp;</span>
                @endif
                @if($group->status === 'active')
                    <span class="badge-active">Активна</span>
                @else
                    <span class="badge-archived">Архив</span>
                @endif
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <button class="btn-edit" onclick="document.getElementById('editGroupModal').style.display='flex'">&#9998; Редактировать</button>
            @if($group->status === 'active')
                <form method="POST" action="{{ route('club.groups.archive', $group) }}"
                      onsubmit="return confirm('Перенести «{{ $group->name }}» в архив? Будущие занятия будут отменены, корты освободятся. История сохранится.')"
                      style="display:inline;">
                    @csrf
                    <button type="submit" class="btn-archive-group">&#128193; В архив</button>
                </form>
            @else
                <form method="POST" action="{{ route('club.groups.unarchive', $group) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn-unarchive-group">&#8617; Вернуть из архива</button>
                </form>
            @endif
            <form method="POST" action="{{ route('club.groups.destroy', $group) }}"
                  onsubmit="return confirm('Удалить группу «{{ $group->name }}» и всю её историю? Будущие занятия будут отменены, корты освободятся.')"
                  style="display:inline;">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-delete-group">&#10005; Удалить</button>
            </form>
        </div>
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

    @if($group->note)
        <div class="note-card">
            <span class="note-icon">&#8505;</span>
            <span class="note-text">{{ $group->note }}</span>
        </div>
    @endif

    <div class="two-col-grid">

        <!-- Участники -->
        <div class="section-card">
            <div class="section-card-header">
                <h2 class="section-title">Участники</h2>
                <button class="btn-add-small" onclick="document.getElementById('addMemberModal').style.display='flex'">+ Добавить</button>
            </div>
            @php $activeMembers = $group->members->where('status', 'active'); @endphp
            @if($activeMembers->isEmpty())
                <div class="empty-state-small">
                    <p>Участников пока нет.</p>
                </div>
            @else
                @foreach($activeMembers as $member)
                    <div class="member-row">
                        <div class="member-name">{{ optional($member->client)->name ?? '—' }}</div>
                        <div class="member-right">
                            @php $rem = $member->remaining; @endphp
                            <span class="rem-badge {{ $rem > 0 ? 'rem-ok' : 'rem-low' }}">{{ $rem }}</span>
                            <button class="action-btn action-renew" onclick="openEnrollModal({{ $member->id }})" title="Продлить">+</button>
                            <form method="POST"
                                  action="{{ route('club.groups.members.destroy', [$group, $member]) }}"
                                  onsubmit="return confirm('Убрать участника из группы?')"
                                  style="display:inline;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="action-btn action-remove" title="Убрать">&#10005;</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

        <!-- Занятия группы -->
        <div class="section-card">
            <div class="section-card-header">
                <h2 class="section-title">Занятия группы</h2>
                <span class="sessions-count">{{ $sessions->count() }}</span>
            </div>
            @if($sessions->isEmpty())
                <div class="empty-state-small">
                    <p>Занятий пока нет.</p>
                </div>
            @else
                @foreach($sessions as $s)
                    <a href="{{ route('club.groupSessions.show', $s) }}" class="session-row">
                        <div class="session-date">{{ $s->date ? $s->date->format('d.m.Y') : '—' }}</div>
                        <div class="session-time">
                            {{ $s->start_time ? substr($s->start_time, 0, 5) : '—' }}
                            @if($s->end_time)– {{ substr($s->end_time, 0, 5) }}@endif
                        </div>
                        <div class="session-court">{{ optional($s->court)->name ?? '—' }}</div>
                        <div>
                            @if($s->status === 'held')
                                <span class="badge-held">Проведено</span>
                            @elseif($s->status === 'cancelled')
                                <span class="badge-cancelled">Отменено</span>
                            @else
                                <span class="badge-planned">Запланировано</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            @endif
        </div>

    </div>
</div>

<!-- Модал добавления участника -->
<div id="addMemberModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Добавить участника</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('addMemberModal').style.display='none'">&#10005;</button>
        </div>
        <form method="POST" action="{{ route('club.groups.members.store', $group) }}" onsubmit="return groupMemberValid()">
            @csrf
            <div class="modal-body-area">
                <div class="form-group" style="position:relative;">
                    <label class="form-label">Клиент <span style="color:#ef4444">*</span></label>
                    <input type="text" id="memberClientSearch" class="form-input" autocomplete="off"
                           placeholder="Поиск по имени или телефону…" oninput="searchGroupClients(this.value)">
                    <div id="memberClientResults"
                         style="position:absolute;left:0;right:0;top:100%;z-index:10;background:#16161a;border:1px solid #27272a;border-radius:10px;margin-top:4px;max-height:220px;overflow-y:auto;display:none;"></div>
                    <input type="hidden" name="client_id" id="memberClientId">
                    <div id="memberClientSelected"
                         style="display:none;align-items:center;justify-content:space-between;margin-top:8px;padding:10px 12px;background:#16161a;border:1px solid #22c55e;border-radius:10px;">
                        <span id="memberClientSelectedName" style="color:#22c55e;font-weight:700;font-size:14px;"></span>
                        <button type="button" onclick="clearGroupClient()"
                                style="background:none;border:none;color:#71717a;cursor:pointer;font-size:14px;">&#10005;</button>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Занятий в пакете <span style="color:#ef4444">*</span></label>
                        <input type="number" name="sessions" class="form-input" min="1" max="200" required
                               value="{{ old('sessions', 8) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Сумма (₸)</label>
                        <input type="number" name="amount" class="form-input" min="0" step="100"
                               value="{{ old('amount', $group->price_per_session * 8) }}">
                    </div>
                </div>
                <div class="form-check-row">
                    <input type="checkbox" name="is_paid" value="1" id="addIsPaid" class="form-check-box" checked>
                    <label class="form-check-label" for="addIsPaid">Оплачено</label>
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('addMemberModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Добавить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модал продления пакета -->
<div id="enrollModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Добавить пакет занятий</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('enrollModal').style.display='none'">&#10005;</button>
        </div>
        <form id="enrollForm" method="POST" action="">
            @csrf
            <div class="modal-body-area">
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Занятий <span style="color:#ef4444">*</span></label>
                        <input type="number" name="sessions" class="form-input" min="1" max="200" required value="8">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Сумма (₸)</label>
                        <input type="number" name="amount" class="form-input" min="0" step="100" value="0">
                    </div>
                </div>
                <div class="form-check-row">
                    <input type="checkbox" name="is_paid" value="1" id="enrollIsPaid" class="form-check-box" checked>
                    <label class="form-check-label" for="enrollIsPaid">Оплачено</label>
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('enrollModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модал редактирования группы -->
<div id="editGroupModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Редактировать группу</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('editGroupModal').style.display='none'">&#10005;</button>
        </div>
        <form method="POST" action="{{ route('club.groups.update', $group) }}">
            @csrf
            @method('PUT')
            <div class="modal-body-area">
                <div class="form-group">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" name="name" class="form-input" required
                           value="{{ old('name', $group->name) }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Тренер</label>
                    <select name="coach_id" class="form-input">
                        <option value="">— без тренера —</option>
                        @foreach($coaches as $coach)
                            <option value="{{ $coach->user_id }}"
                                {{ old('coach_id', $group->coach_id) == $coach->user_id ? 'selected' : '' }}>
                                {{ $coach->user->name ?? 'Тренер #'.$coach->user_id }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Цена за занятие (₸)</label>
                        <input type="number" name="price_per_session" class="form-input" min="0" step="100"
                               value="{{ old('price_per_session', $group->price_per_session) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Макс. участников</label>
                        <input type="number" name="capacity" class="form-input" min="1" max="100"
                               value="{{ old('capacity', $group->capacity) }}" placeholder="Не ограничено">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Статус</label>
                    <select name="status" class="form-input">
                        <option value="active" {{ old('status', $group->status) === 'active' ? 'selected' : '' }}>Активна</option>
                        <option value="archived" {{ old('status', $group->status) === 'archived' ? 'selected' : '' }}>Архив</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Заметка</label>
                    <textarea name="note" class="form-input" rows="3"
                              placeholder="Дополнительная информация о группе...">{{ old('note', $group->note) }}</textarea>
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('editGroupModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
    var enrollRoutes = {
        @foreach($group->members->where('status', 'active') as $member)
        {{ $member->id }}: "{{ route('club.groups.members.enroll', [$group, $member]) }}",
        @endforeach
    };

    function openEnrollModal(memberId) {
        var form = document.getElementById('enrollForm');
        form.action = enrollRoutes[memberId] || '';
        document.getElementById('enrollModal').style.display = 'flex';
    }

    // Динамический поиск клиента (по имени или телефону) для добавления в группу
    var groupClientTimer;
    function searchGroupClients(q) {
        clearTimeout(groupClientTimer);
        var box = document.getElementById('memberClientResults');
        q = (q || '').trim();
        if (q.length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
        var field = /\d/.test(q) ? 'phone' : 'name';
        groupClientTimer = setTimeout(function () {
            fetch('{{ route("club.clients.search") }}?field=' + field + '&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    box.innerHTML = '';
                    if (!list.length) {
                        var empty = document.createElement('div');
                        empty.style.cssText = 'padding:12px;color:#71717a;font-size:13px;';
                        empty.textContent = 'Ничего не найдено';
                        box.appendChild(empty);
                        box.style.display = 'block';
                        return;
                    }
                    list.forEach(function (c) {
                        var item = document.createElement('div');
                        item.style.cssText = 'padding:10px 12px;cursor:pointer;border-bottom:1px solid #27272a;display:flex;justify-content:space-between;gap:8px;';
                        var nm = document.createElement('span');
                        nm.style.cssText = 'color:#f4f4f5;font-size:14px;';
                        nm.textContent = c.name || '';
                        var ph = document.createElement('span');
                        ph.style.cssText = 'color:#71717a;font-size:13px;';
                        ph.textContent = c.phone || '';
                        item.appendChild(nm); item.appendChild(ph);
                        item.addEventListener('mouseenter', function () { item.style.background = '#1a1a1e'; });
                        item.addEventListener('mouseleave', function () { item.style.background = 'transparent'; });
                        item.addEventListener('click', function () { selectGroupClient(c.id, c.name || ''); });
                        box.appendChild(item);
                    });
                    box.style.display = 'block';
                });
        }, 250);
    }
    function selectGroupClient(id, name) {
        document.getElementById('memberClientId').value = id;
        document.getElementById('memberClientSelectedName').textContent = name;
        document.getElementById('memberClientSelected').style.display = 'flex';
        document.getElementById('memberClientResults').style.display = 'none';
        document.getElementById('memberClientSearch').value = '';
    }
    function clearGroupClient() {
        document.getElementById('memberClientId').value = '';
        document.getElementById('memberClientSelected').style.display = 'none';
    }
    function groupMemberValid() {
        if (!document.getElementById('memberClientId').value) {
            alert('Выберите клиента (поиск по имени или телефону)');
            return false;
        }
        return true;
    }
</script>

<style>
    .group-show-container { max-width: 1000px; margin: 0 auto; padding: 32px 24px; }
    .group-show-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 32px; flex-wrap: wrap; gap: 16px; }
    .group-show-title-block { display: flex; flex-direction: column; gap: 6px; }
    .back-link { font-size: 13px; color: #71717a; text-decoration: none; font-weight: 600; }
    .back-link:hover { color: #a1a1aa; }
    .group-show-title { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; color: #f4f4f5; margin: 0; }
    .group-show-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 4px; font-size: 14px; }
    .meta-item { color: #a1a1aa; font-weight: 500; }
    .meta-price { color: #22c55e; font-weight: 600; }
    .meta-sep { color: #52525b; }
    .badge-active { display: inline-flex; align-items: center; padding: 3px 10px; background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); border-radius: 6px; font-size: 12px; font-weight: 700; }
    .badge-archived { display: inline-flex; align-items: center; padding: 3px 10px; background: rgba(113,113,122,0.15); color: #71717a; border: 1px solid rgba(113,113,122,0.3); border-radius: 6px; font-size: 12px; font-weight: 700; }
    .btn-edit { display: flex; align-items: center; gap: 8px; background: #16161a; color: #a1a1aa; border: 1px solid #27272a; padding: 10px 18px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
    .btn-edit:hover { border-color: #3b82f6; color: #3b82f6; }
    .btn-delete-group { display: inline-flex; align-items: center; gap: 8px; background: #16161a; color: #a1a1aa; border: 1px solid #27272a; padding: 10px 18px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
    .btn-delete-group:hover { border-color: #ef4444; color: #ef4444; }
    .btn-archive-group { display: inline-flex; align-items: center; gap: 8px; background: #16161a; color: #a1a1aa; border: 1px solid #27272a; padding: 10px 18px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
    .btn-archive-group:hover { border-color: #eab308; color: #eab308; }
    .btn-unarchive-group { display: inline-flex; align-items: center; gap: 8px; background: #16161a; color: #a1a1aa; border: 1px solid #27272a; padding: 10px 18px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
    .btn-unarchive-group:hover { border-color: #22c55e; color: #22c55e; }

    .flash-message { padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 24px; }
    .flash-success { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .flash-error { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }

    .note-card { display: flex; align-items: flex-start; gap: 12px; background: #111113; border: 1px solid #27272a; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; }
    .note-icon { font-size: 18px; color: #22c55e; flex-shrink: 0; }
    .note-text { font-size: 14px; color: #a1a1aa; font-weight: 500; line-height: 1.5; }

    .two-col-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    .section-card { background: #111113; border: 1px solid #27272a; border-radius: 16px; overflow: hidden; }
    .section-card-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px; border-bottom: 1px solid #27272a; }
    .section-title { font-size: 15px; font-weight: 700; color: #f4f4f5; margin: 0; }
    .sessions-count { font-size: 13px; color: #71717a; font-weight: 600; background: #16161a; border: 1px solid #27272a; border-radius: 6px; padding: 3px 10px; }
    .btn-add-small { background: #22c55e; color: #0a0a0b; border: none; padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; }
    .btn-add-small:hover { background: #16a34a; }

    .member-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; border-bottom: 1px solid #1c1c1f; transition: background 0.15s; }
    .member-row:last-child { border-bottom: none; }
    .member-row:hover { background: #16161a; }
    .member-name { font-size: 14px; font-weight: 600; color: #f4f4f5; }
    .member-right { display: flex; align-items: center; gap: 8px; }
    .rem-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; padding: 3px 8px; border-radius: 6px; font-size: 13px; font-weight: 700; }
    .rem-ok { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
    .rem-low { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
    .action-btn { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; background: #16161a; border: 1px solid #27272a; border-radius: 7px; cursor: pointer; color: #a1a1aa; font-size: 14px; transition: all 0.2s; font-weight: 700; }
    .action-renew:hover { border-color: #22c55e; color: #22c55e; }
    .action-remove:hover { border-color: #ef4444; color: #ef4444; }

    .session-row { display: flex; align-items: center; gap: 12px; padding: 12px 20px; border-bottom: 1px solid #1c1c1f; text-decoration: none; transition: background 0.15s; }
    .session-row:last-child { border-bottom: none; }
    .session-row:hover { background: #16161a; }
    .session-date { font-size: 14px; font-weight: 600; color: #f4f4f5; min-width: 80px; }
    .session-time { font-size: 13px; color: #71717a; font-weight: 500; min-width: 90px; }
    .session-court { font-size: 13px; color: #a1a1aa; flex: 1; }
    .badge-held { display: inline-flex; align-items: center; padding: 3px 8px; background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); border-radius: 5px; font-size: 11px; font-weight: 700; }
    .badge-cancelled { display: inline-flex; align-items: center; padding: 3px 8px; background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); border-radius: 5px; font-size: 11px; font-weight: 700; }
    .badge-planned { display: inline-flex; align-items: center; padding: 3px 8px; background: rgba(113,113,122,0.15); color: #71717a; border: 1px solid rgba(113,113,122,0.3); border-radius: 5px; font-size: 11px; font-weight: 700; }

    .empty-state-small { padding: 32px 20px; text-align: center; color: #71717a; font-size: 14px; }

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
    .form-check-row { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
    .form-check-box { width: 18px; height: 18px; accent-color: #22c55e; cursor: pointer; }
    .form-check-label { font-size: 14px; font-weight: 600; color: #a1a1aa; cursor: pointer; }
    .btn-cancel { flex: 1; padding: 14px; background: #16161a; border: 1px solid #27272a; border-radius: 10px; color: #a1a1aa; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-save { flex: 2; padding: 14px; background: #22c55e; border: none; border-radius: 10px; color: #0a0a0b; font-size: 14px; font-weight: 800; cursor: pointer; }
    .btn-save:hover { background: #16a34a; }

    @media (max-width: 768px) {
        .group-show-header { flex-direction: column; align-items: flex-start; }
        .two-col-grid { grid-template-columns: 1fr; }
        .form-row-2 { grid-template-columns: 1fr; }
    }
</style>

@endsection

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
                @php $today = \Carbon\Carbon::today(); @endphp
                @foreach($activeMembers as $member)
                    @php
                        $rem = $member->remaining;
                        $activeFreeze = $member->freezes->first(fn($f) => $f->freeze_from->lte($today) && $f->freeze_until->gte($today));
                    @endphp
                    <div class="member-row">
                        <div class="member-name">
                            {{ optional($member->client)->name ?? '—' }}
                            @if($activeFreeze)<span class="freeze-badge" title="Заморожен">❄ до {{ $activeFreeze->freeze_until->format('d.m.y') }}</span>@endif
                            @if($member->subscription_ends_at)
                                <span style="display:block;font-size:12px;margin-top:2px;color:{{ $member->subscription_ends_at->lt($today) ? '#ef4444' : '#71717a' }};">
                                    Абонемент до {{ $member->subscription_ends_at->format('d.m.Y') }}{{ $member->subscription_ends_at->lt($today) ? ' · истёк' : '' }}
                                </span>
                            @endif
                        </div>
                        <div class="member-right">
                            <span class="rem-badge {{ $rem > 0 ? 'rem-ok' : 'rem-low' }}">{{ $rem }}</span>
                            <button class="action-btn action-freeze" onclick="openFreezeModal({{ $member->id }})" title="Заморозить">❄</button>
                            <button class="action-btn action-renew" onclick="openEnrollModal({{ $member->id }})" title="Продлить">+</button>
                            <button class="action-btn action-edit" onclick="openEditMemberModal({{ $member->id }})" title="Абонемент">✎</button>
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
                    @if($member->freezes->isNotEmpty())
                        <div class="member-freezes">
                            @foreach($member->freezes->sortByDesc('freeze_from') as $f)
                                <span class="freeze-chip">
                                    {{ $f->freeze_from->format('d.m.y') }}–{{ $f->freeze_until->format('d.m.y') }}@if($f->note) · {{ $f->note }}@endif
                                    <form method="POST" action="{{ route('club.groups.members.unfreeze', [$group, $member, $f]) }}"
                                          onsubmit="return confirm('Снять заморозку?')" style="display:inline;">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="freeze-chip-x" title="Снять">&#10005;</button>
                                    </form>
                                </span>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            @endif
        </div>

        <!-- Занятия группы -->
        <div class="section-card">
            <div class="section-card-header">
                <h2 class="section-title">Занятия <span class="sessions-count">{{ $sessions->count() }}</span></h2>
                <a href="{{ route('club.groups.schedule', $group) }}" class="btn-schedule-link">Всё расписание →</a>
            </div>
            @if($sessions->isEmpty())
                <div class="empty-state-small"><p>Занятий пока нет.</p></div>
            @else
                @php
                    $gmonths = [1=>'янв',2=>'фев',3=>'мар',4=>'апр',5=>'мая',6=>'июн',7=>'июл',8=>'авг',9=>'сен',10=>'окт',11=>'ноя',12=>'дек'];
                    $gwd = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
                @endphp
                @foreach($sessions as $s)
                    @php $sd = $s->date instanceof \Carbon\Carbon ? $s->date : \Carbon\Carbon::parse($s->date); @endphp
                    <a href="{{ route('club.groupSessions.show', $s) }}" class="session-row">
                        <div class="s-date">
                            <span class="d">{{ $sd->format('d') }}</span>
                            <span class="m">{{ $gmonths[(int) $sd->format('n')] }}</span>
                        </div>
                        <div class="s-info">
                            <div class="s-r1">{{ $gwd[$sd->dayOfWeekIso - 1] ?? '' }}, {{ substr((string) $s->start_time, 0, 5) }}@if($s->end_time)–{{ substr((string) $s->end_time, 0, 5) }}@endif</div>
                            <div class="s-r2">{{ optional($s->court)->name ?? '—' }}</div>
                        </div>
                        @if($s->status === 'held')
                            <span class="s-pill pill-held">Проведено</span>
                        @elseif($s->status === 'cancelled')
                            <span class="s-pill pill-cancelled">Отменено</span>
                        @else
                            <span class="s-pill pill-planned">Запланировано</span>
                        @endif
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
                <div class="form-group">
                    <label class="form-label">Дата окончания абонемента</label>
                    <input type="date" name="subscription_ends_at" class="form-input" value="{{ old('subscription_ends_at') }}">
                    <small style="color:#71717a;font-size:12px;">Необязательно</small>
                </div>
                @php
                    $pmOptions = [
                        ['cash', 'Наличные', 'bi-cash-stack'],
                        ['card', 'Карта', 'bi-credit-card-2-front'],
                        ['kaspi', 'Kaspi', 'bi-qr-code'],
                        ['certificate', 'Сертификат', 'bi-award'],
                        ['club_card', 'Клубная карта', 'bi-person-vcard'],
                        ['deposit', 'Депозит', 'bi-wallet2'],
                        ['cashback', 'Кешбэк', 'bi-arrow-repeat'],
                        ['cashless', 'Безналичный', 'bi-bank'],
                        ['free', 'Бесплатно', 'bi-gift'],
                    ];
                @endphp
                <div class="form-group">
                    <label class="form-label">Способ оплаты</label>
                    <input type="hidden" name="payment_method" id="addPayMethod" value="">
                    <div class="pm-grid" id="addPayGrid">
                        @foreach($pmOptions as [$pv, $pl, $pi])
                        <button type="button" class="pm-chip" data-v="{{ $pv }}" onclick="pmPick('add', this)">
                            <i class="bi {{ $pi }}"></i><span>{{ $pl }}</span>
                        </button>
                        @endforeach
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
                <div class="form-group">
                    <label class="form-label">Способ оплаты</label>
                    <input type="hidden" name="payment_method" id="enrollPayMethod" value="">
                    <div class="pm-grid" id="enrollPayGrid">
                        @foreach($pmOptions as [$pv, $pl, $pi])
                        <button type="button" class="pm-chip" data-v="{{ $pv }}" onclick="pmPick('enroll', this)">
                            <i class="bi {{ $pi }}"></i><span>{{ $pl }}</span>
                        </button>
                        @endforeach
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

<!-- Модал заморозки участника -->
<div id="freezeModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Заморозить участника</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('freezeModal').style.display='none'">&#10005;</button>
        </div>
        <form id="freezeForm" method="POST" action="">
            @csrf
            <div class="modal-body-area">
                <p style="color:#a1a1aa;font-size:13px;margin:0 0 12px;">В период заморозки занятия участнику не списываются.</p>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">С <span style="color:#ef4444">*</span></label>
                        <input type="date" name="freeze_from" id="freezeFrom" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">По <span style="color:#ef4444">*</span></label>
                        <input type="date" name="freeze_until" id="freezeUntil" class="form-input" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Заметка</label>
                    <input type="text" name="note" class="form-input" maxlength="255" placeholder="Напр.: отпуск, травма">
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('freezeModal').style.display='none'">Отмена</button>
                <button type="submit" class="btn-save">Заморозить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модал редактирования абонемента участника -->
<div id="editMemberModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="modal-header-row">
            <h5 class="modal-title-text">Абонемент участника</h5>
            <button type="button" class="modal-close-btn" onclick="document.getElementById('editMemberModal').style.display='none'">&#10005;</button>
        </div>
        <form id="editMemberForm" method="POST" action="">
            @csrf
            @method('PUT')
            <div class="modal-body-area">
                <div class="form-group">
                    <label class="form-label">Дата окончания абонемента</label>
                    <input type="date" name="subscription_ends_at" id="editMemberEndsAt" class="form-input">
                    <small style="color:#71717a;font-size:12px;">Необязательно. Оставьте пустым, чтобы убрать дату.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Способ оплаты</label>
                    <input type="hidden" name="payment_method" id="editMemberPayMethod" value="">
                    <div class="pm-grid" id="editMemberPayGrid">
                        @foreach($pmOptions as [$pv, $pl, $pi])
                        <button type="button" class="pm-chip" data-v="{{ $pv }}" onclick="pmPick('editMember', this)">
                            <i class="bi {{ $pi }}"></i><span>{{ $pl }}</span>
                        </button>
                        @endforeach
                    </div>
                    <small style="color:#71717a;font-size:12px;">Метод последнего пакета участника.</small>
                </div>
            </div>
            <div class="modal-footer-row">
                <button type="button" class="btn-cancel" onclick="document.getElementById('editMemberModal').style.display='none'">Отмена</button>
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
                        <label class="form-label">Цена занятия для клиента (₸)</label>
                        <input type="number" name="price_per_session" class="form-input" min="0" step="1"
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

    var freezeRoutes = {
        @foreach($group->members->where('status', 'active') as $member)
        {{ $member->id }}: "{{ route('club.groups.members.freeze', [$group, $member]) }}",
        @endforeach
    };
    function openFreezeModal(memberId) {
        var form = document.getElementById('freezeForm');
        form.action = freezeRoutes[memberId] || '';
        document.getElementById('freezeModal').style.display = 'flex';
    }

    function openEnrollModal(memberId) {
        var form = document.getElementById('enrollForm');
        form.action = enrollRoutes[memberId] || '';
        pmReset('enroll');
        document.getElementById('enrollModal').style.display = 'flex';
    }

    // Компактный селектор способа оплаты (переиспользует коды из брони кортов).
    function pmPick(prefix, el) {
        var grid = document.getElementById(prefix + 'PayGrid');
        if (grid) grid.querySelectorAll('.pm-chip').forEach(function (b) { b.classList.remove('active'); });
        el.classList.add('active');
        var inp = document.getElementById(prefix + 'PayMethod');
        if (inp) inp.value = el.getAttribute('data-v');
    }
    function pmReset(prefix) {
        var grid = document.getElementById(prefix + 'PayGrid');
        if (grid) grid.querySelectorAll('.pm-chip').forEach(function (b) { b.classList.remove('active'); });
        var inp = document.getElementById(prefix + 'PayMethod');
        if (inp) inp.value = '';
    }
    function pmSet(prefix, value) {
        var grid = document.getElementById(prefix + 'PayGrid');
        if (grid) grid.querySelectorAll('.pm-chip').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-v') === value);
        });
        var inp = document.getElementById(prefix + 'PayMethod');
        if (inp) inp.value = value || '';
    }

    var memberEditData = {
        @foreach($group->members->where('status', 'active') as $member)
        {{ $member->id }}: {
            url: "{{ route('club.groups.members.update', [$group, $member]) }}",
            date: "{{ $member->subscription_ends_at ? $member->subscription_ends_at->format('Y-m-d') : '' }}",
            pm: "{{ optional($member->enrollments->sortByDesc('id')->first())->payment_method ?? '' }}"
        },
        @endforeach
    };
    function openEditMemberModal(memberId) {
        var d = memberEditData[memberId] || {};
        document.getElementById('editMemberForm').action = d.url || '';
        document.getElementById('editMemberEndsAt').value = d.date || '';
        pmSet('editMember', d.pm || '');
        document.getElementById('editMemberModal').style.display = 'flex';
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
    /* === Дизайн под страницу расписания === */
    .group-show-container { max-width: 1000px; margin: 0 auto; padding: 12px 8px 40px; }
    .group-show-header { display: flex; align-items: center; justify-content: space-between; margin: 8px 0 22px; flex-wrap: wrap; gap: 14px; }
    .group-show-title-block { display: flex; flex-direction: column; gap: 5px; }
    .back-link { font-size: 13px; color: #6b7278; text-decoration: none; font-weight: 600; }
    .back-link:hover { color: #a1a1aa; }
    .group-show-title { font-size: 24px; font-weight: 800; letter-spacing: -0.4px; color: #f4f6f7; margin: 2px 0 0; }
    .group-show-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 5px; font-size: 13.5px; margin-top: 2px; }
    .meta-item { color: #8b9298; font-weight: 500; }
    .meta-price { color: #34d17f; font-weight: 600; }
    .meta-sep { color: #3f4449; }
    .badge-active { display: inline-flex; align-items: center; padding: 3px 10px; background: rgba(34,197,94,0.14); color: #34d17f; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: .3px; }
    .badge-archived { display: inline-flex; align-items: center; padding: 3px 10px; background: rgba(139,146,152,0.14); color: #8b9298; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: .3px; }
    .btn-edit, .btn-delete-group, .btn-archive-group, .btn-unarchive-group {
        display: inline-flex; align-items: center; gap: 7px; background: #15181A; color: #cfd3d6;
        border: 1px solid rgba(255,255,255,0.08); padding: 9px 15px; border-radius: 10px;
        font-size: 13px; font-weight: 700; cursor: pointer; transition: .15s;
    }
    .btn-edit:hover { border-color: #4d8ff0; color: #6aa4f5; }
    .btn-delete-group:hover { border-color: #e5564e; color: #ef7a73; }
    .btn-archive-group:hover { border-color: #eab34e; color: #edbf63; }
    .btn-unarchive-group:hover { border-color: #22c55e; color: #34d17f; }

    .flash-message { padding: 13px 18px; border-radius: 12px; font-size: 14px; font-weight: 600; margin-bottom: 18px; }
    .flash-success { background: rgba(34,197,94,0.14); color: #34d17f; }
    .flash-error { background: rgba(229,86,78,0.14); color: #ef7a73; }

    .note-card { display: flex; align-items: flex-start; gap: 12px; background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; }
    .note-icon { font-size: 17px; color: #34d17f; flex-shrink: 0; }
    .note-text { font-size: 14px; color: #9aa1a7; font-weight: 500; line-height: 1.5; }

    .two-col-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }
    .section-card { background: #15181A; border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; overflow: hidden; }
    .section-card-header { display: flex; align-items: center; justify-content: space-between; padding: 15px 16px; border-bottom: 1px solid rgba(255,255,255,0.05); }
    .section-title { font-size: 12px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #6b7278; margin: 0; display: flex; align-items: center; gap: 8px; }
    .sessions-count { font-size: 11px; color: #8b9298; font-weight: 700; background: rgba(255,255,255,0.05); border-radius: 999px; padding: 2px 8px; letter-spacing: 0; }
    .btn-add-small { background: rgba(34,197,94,0.14); color: #34d17f; border: 1px solid rgba(34,197,94,0.30); padding: 7px 13px; border-radius: 9px; font-size: 12.5px; font-weight: 700; cursor: pointer; }
    .btn-add-small:hover { background: rgba(34,197,94,0.22); }
    .btn-schedule-link { font-size: 12.5px; font-weight: 700; color: #34d17f; text-decoration: none; }
    .btn-schedule-link:hover { color: #22c55e; }

    .member-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.04); }
    .member-row:last-child { border-bottom: none; }
    .member-name { font-size: 14px; font-weight: 600; color: #e6e9eb; min-width: 0; }
    .member-right { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .rem-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; padding: 4px 9px; border-radius: 8px; font-size: 12.5px; font-weight: 700; }
    .rem-ok { background: rgba(34,197,94,0.13); color: #34d17f; }
    .rem-low { background: rgba(255,255,255,0.05); color: #8b9298; }
    .action-btn { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; cursor: pointer; color: #9aa1a7; font-size: 14px; transition: all 0.15s; font-weight: 700; }
    .action-renew:hover { border-color: #22c55e; color: #34d17f; }
    .action-remove:hover { border-color: #e5564e; color: #ef7a73; }
    .action-freeze:hover { border-color: #4d8ff0; color: #6aa4f5; }
    .action-edit:hover { border-color: #eab34e; color: #edbf63; }

    /* Занятия — карточки как в расписании */
    .s-date { flex-shrink: 0; text-align: center; width: 42px; }
    .s-date .d { display: block; font-size: 19px; font-weight: 800; color: #f4f6f7; line-height: 1; }
    .s-date .m { display: block; font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: #7c848a; margin-top: 3px; }
    .s-info { flex: 1; min-width: 0; }
    .s-r1 { font-size: 14px; font-weight: 700; color: #eef1f2; }
    .s-r2 { font-size: 12.5px; color: #8b9298; margin-top: 2px; }
    .s-pill { flex-shrink: 0; font-size: 10.5px; font-weight: 800; letter-spacing: .3px; text-transform: uppercase; padding: 5px 10px; border-radius: 999px; }
    .pill-held { background: rgba(34,197,94,0.14); color: #34d17f; }
    .pill-planned { background: rgba(77,143,240,0.14); color: #6aa4f5; }
    .pill-cancelled { background: rgba(229,86,78,0.14); color: #ef7a73; }
    .pm-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
    .pm-chip { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 8px 4px; background: #16161a; border: 1px solid #27272a; border-radius: 8px; color: #a1a1aa; font-size: 10.5px; line-height: 1.15; text-align: center; cursor: pointer; transition: border-color .15s, color .15s, background .15s; }
    .pm-chip i { font-size: 15px; }
    .pm-chip:hover { border-color: #3f3f46; color: #d4d4d8; }
    .pm-chip.active { border-color: #22c55e; color: #22c55e; background: rgba(34,197,94,0.08); }
    .freeze-badge { display: inline-block; margin-left: 8px; font-size: 11px; font-weight: 700; color: #38bdf8; background: rgba(56,189,248,.12); border-radius: 6px; padding: 2px 7px; }
    .member-freezes { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 16px 10px 16px; }
    .freeze-chip { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; color: #93c5fd; background: rgba(56,189,248,.08); border: 1px solid rgba(56,189,248,.25); border-radius: 999px; padding: 2px 4px 2px 10px; }
    .freeze-chip-x { background: none; border: none; color: #71717a; cursor: pointer; font-size: 11px; padding: 0 4px; }
    .freeze-chip-x:hover { color: #ef4444; }

    .session-row { display: flex; align-items: center; gap: 12px; padding: 11px 16px; border-bottom: 1px solid rgba(255,255,255,0.04); text-decoration: none; transition: background 0.15s; }
    .session-row:last-child { border-bottom: none; }
    .session-row:hover { background: rgba(255,255,255,0.025); }

    .empty-state-small { padding: 32px 20px; text-align: center; color: #6b7278; font-size: 14px; }

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

@extends('layouts.app')
@section('title', $group->name)

@section('content')

<div class="page-header">
    <div>
        <div style="margin-bottom:6px;">
            <a href="{{ route('club.groups.index') }}" style="color:var(--text-muted);text-decoration:none;font-size:0.9rem;">
                <i class="bi bi-arrow-left me-1"></i>Группы
            </a>
        </div>
        <h2><i class="bi bi-people me-2" style="color:var(--accent)"></i>{{ $group->name }}</h2>
        <p style="color:var(--text-secondary)">
            @if($group->coach)
                Тренер: {{ $group->coach->name }} &nbsp;·&nbsp;
            @endif
            @if($group->price_per_session > 0)
                {{ number_format($group->price_per_session, 0, '.', ' ') }} ₸/занятие &nbsp;·&nbsp;
            @endif
            @if($group->status === 'active')
                <span class="badge-success-custom">Активна</span>
            @else
                <span class="badge-secondary-custom">Архив</span>
            @endif
        </p>
    </div>
    <button class="btn-outline-custom" onclick="document.getElementById('editGroupModal').style.display='flex'">
        <i class="bi bi-pencil"></i> Редактировать
    </button>
</div>

@if(session('success'))
    <div class="alert-success-custom mb-4">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert-danger-custom mb-4">{{ session('error') }}</div>
@endif

@if($group->note)
    <div class="card-dark mb-4">
        <div class="card-body" style="color:var(--text-secondary);">
            <i class="bi bi-info-circle me-2" style="color:var(--accent)"></i>{{ $group->note }}
        </div>
    </div>
@endif

<div class="row g-4">
    <!-- Участники -->
    <div class="col-12 col-lg-6">
        <div class="card-dark h-100">
            <div class="card-header">
                <h5><i class="bi bi-person-check"></i> Участники</h5>
                <button class="btn-primary-custom" onclick="document.getElementById('addMemberModal').style.display='flex'">
                    <i class="bi bi-plus-lg"></i> Добавить
                </button>
            </div>
            <div class="card-body p-0">
                @php $activeMembers = $group->members->where('status', 'active'); @endphp
                @if($activeMembers->isEmpty())
                    <div class="text-center py-5" style="color:var(--text-muted)">
                        <i class="bi bi-person-x" style="font-size:2.5rem;"></i>
                        <p class="mt-3">Участников пока нет.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-dark-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Клиент</th>
                                    <th>Остаток занятий</th>
                                    <th style="width:100px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($activeMembers as $member)
                                    <tr>
                                        <td style="font-weight:500;">
                                            {{ optional($member->client)->name ?? '—' }}
                                        </td>
                                        <td>
                                            @php $rem = $member->remaining; @endphp
                                            <span class="{{ $rem > 0 ? 'badge-success-custom' : 'badge-danger-custom' }}">
                                                {{ $rem }}
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1 justify-content-end">
                                                <button class="btn-outline-custom" style="padding:4px 8px;font-size:0.8rem;"
                                                        onclick="openEnrollModal({{ $member->id }})">
                                                    <i class="bi bi-plus-circle"></i> Продлить
                                                </button>
                                                <form method="POST"
                                                      action="{{ route('club.groups.members.destroy', [$group, $member]) }}"
                                                      onsubmit="return confirm('Убрать участника из группы?')"
                                                      style="display:inline;">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn-danger-custom" style="padding:4px 8px;font-size:0.8rem;">
                                                        <i class="bi bi-person-dash"></i> Убрать
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Занятия группы -->
    <div class="col-12 col-lg-6">
        <div class="card-dark h-100">
            <div class="card-header">
                <h5><i class="bi bi-calendar3"></i> Занятия группы</h5>
                <span style="color:var(--text-muted);font-size:0.9rem;">{{ $sessions->count() }} {{ $sessions->count() === 1 ? 'занятие' : ($sessions->count() < 5 ? 'занятия' : 'занятий') }}</span>
            </div>
            <div class="card-body p-0">
                @if($sessions->isEmpty())
                    <div class="text-center py-5" style="color:var(--text-muted)">
                        <i class="bi bi-calendar-x" style="font-size:2.5rem;"></i>
                        <p class="mt-3">Занятий пока нет.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-dark-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Дата</th>
                                    <th>Время</th>
                                    <th>Корт</th>
                                    <th>Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sessions as $session)
                                    <tr>
                                        <td style="font-weight:500;">
                                            {{ $session->date ? $session->date->format('d.m.Y') : '—' }}
                                        </td>
                                        <td style="color:var(--text-secondary);">
                                            {{ $session->start_time ? substr($session->start_time, 0, 5) : '—' }}
                                            @if($session->end_time)
                                                – {{ substr($session->end_time, 0, 5) }}
                                            @endif
                                        </td>
                                        <td style="color:var(--text-secondary);">
                                            {{ optional($session->court)->name ?? '—' }}
                                        </td>
                                        <td>
                                            @if($session->status === 'held')
                                                <span class="badge-success-custom">Проведено</span>
                                            @elseif($session->status === 'cancelled')
                                                <span class="badge-danger-custom">Отменено</span>
                                            @else
                                                <span class="badge-secondary-custom">Запланировано</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@endsection

@section('modals')
<!-- Модал добавления участника -->
<div id="addMemberModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="card-dark" style="width:100%;max-width:480px;max-height:90vh;overflow-y:auto;margin:20px;">
        <div class="card-header">
            <h5><i class="bi bi-person-plus"></i> Добавить участника</h5>
            <button type="button" style="background:none;border:none;color:var(--text-muted);font-size:1.3rem;cursor:pointer;"
                    onclick="document.getElementById('addMemberModal').style.display='none'">
                <i class="bi bi-x"></i>
            </button>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('club.groups.members.store', $group) }}">
                @csrf

                <div class="mb-3">
                    <label class="form-label">Клиент <span style="color:#ef4444">*</span></label>
                    <select name="client_id" class="form-select" required>
                        <option value="">— выберите клиента —</option>
                        @foreach($clients as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label">Занятий в пакете <span style="color:#ef4444">*</span></label>
                        <input type="number" name="sessions" class="form-control" min="1" max="200" required
                               value="{{ old('sessions', 8) }}">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Сумма (₸)</label>
                        <input type="number" name="amount" class="form-control" min="0" step="100"
                               value="{{ old('amount', $group->price_per_session * 8) }}">
                    </div>
                </div>

                <div class="mb-4">
                    <div class="form-check">
                        <input type="checkbox" name="is_paid" value="1" id="addIsPaid" class="form-check-input" checked>
                        <label class="form-check-label" for="addIsPaid">Оплачено</label>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn-primary-custom flex-grow-1">
                        <i class="bi bi-person-check"></i> Добавить
                    </button>
                    <button type="button" class="btn-outline-custom"
                            onclick="document.getElementById('addMemberModal').style.display='none'">
                        Отмена
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Модал продления пакета -->
<div id="enrollModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="card-dark" style="width:100%;max-width:420px;max-height:90vh;overflow-y:auto;margin:20px;">
        <div class="card-header">
            <h5><i class="bi bi-plus-circle"></i> Добавить пакет занятий</h5>
            <button type="button" style="background:none;border:none;color:var(--text-muted);font-size:1.3rem;cursor:pointer;"
                    onclick="document.getElementById('enrollModal').style.display='none'">
                <i class="bi bi-x"></i>
            </button>
        </div>
        <div class="card-body">
            <form id="enrollForm" method="POST" action="">
                @csrf

                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label">Занятий <span style="color:#ef4444">*</span></label>
                        <input type="number" name="sessions" class="form-control" min="1" max="200" required value="8">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Сумма (₸)</label>
                        <input type="number" name="amount" class="form-control" min="0" step="100" value="0">
                    </div>
                </div>

                <div class="mb-4">
                    <div class="form-check">
                        <input type="checkbox" name="is_paid" value="1" id="enrollIsPaid" class="form-check-input" checked>
                        <label class="form-check-label" for="enrollIsPaid">Оплачено</label>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn-primary-custom flex-grow-1">
                        <i class="bi bi-check-lg"></i> Сохранить
                    </button>
                    <button type="button" class="btn-outline-custom"
                            onclick="document.getElementById('enrollModal').style.display='none'">
                        Отмена
                    </button>
                </div>
            </form>
        </div>
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
</script>

<!-- Модал редактирования группы -->
<div id="editGroupModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="card-dark" style="width:100%;max-width:520px;max-height:90vh;overflow-y:auto;margin:20px;">
        <div class="card-header">
            <h5><i class="bi bi-pencil-square"></i> Редактировать группу</h5>
            <button type="button" style="background:none;border:none;color:var(--text-muted);font-size:1.3rem;cursor:pointer;"
                    onclick="document.getElementById('editGroupModal').style.display='none'">
                <i class="bi bi-x"></i>
            </button>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('club.groups.update', $group) }}">
                @csrf
                @method('PUT')

                <div class="mb-3">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" name="name" class="form-control" required
                           value="{{ old('name', $group->name) }}">
                </div>

                <div class="mb-3">
                    <label class="form-label">Тренер</label>
                    <select name="coach_id" class="form-select">
                        <option value="">— без тренера —</option>
                        @foreach($coaches as $coach)
                            <option value="{{ $coach->user_id }}"
                                {{ old('coach_id', $group->coach_id) == $coach->user_id ? 'selected' : '' }}>
                                {{ $coach->user->name ?? 'Тренер #'.$coach->user_id }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label">Цена за занятие (₸)</label>
                        <input type="number" name="price_per_session" class="form-control" min="0" step="100"
                               value="{{ old('price_per_session', $group->price_per_session) }}">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Макс. участников</label>
                        <input type="number" name="capacity" class="form-control" min="1" max="100"
                               value="{{ old('capacity', $group->capacity) }}" placeholder="Не ограничено">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Статус</label>
                    <select name="status" class="form-select">
                        <option value="active" {{ old('status', $group->status) === 'active' ? 'selected' : '' }}>Активна</option>
                        <option value="archived" {{ old('status', $group->status) === 'archived' ? 'selected' : '' }}>Архив</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label">Заметка</label>
                    <textarea name="note" class="form-control" rows="3"
                              placeholder="Дополнительная информация о группе...">{{ old('note', $group->note) }}</textarea>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn-primary-custom flex-grow-1">
                        <i class="bi bi-check-lg"></i> Сохранить
                    </button>
                    <button type="button" class="btn-outline-custom"
                            onclick="document.getElementById('editGroupModal').style.display='none'">
                        Отмена
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

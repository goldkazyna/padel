@extends('layouts.app')
@section('title', 'Группы')

@section('content')

<div class="page-header">
    <div>
        <h2><i class="bi bi-people me-2" style="color:var(--accent)"></i>Группы</h2>
        <p style="color:var(--text-secondary)">Управление групповыми занятиями клуба</p>
    </div>
    <button class="btn-primary-custom" onclick="document.getElementById('createGroupModal').style.display='flex'">
        <i class="bi bi-plus-lg"></i> Создать группу
    </button>
</div>

@if(session('success'))
    <div class="alert-success-custom mb-4">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert-danger-custom mb-4">{{ session('error') }}</div>
@endif

<div class="card-dark">
    <div class="card-header">
        <h5><i class="bi bi-people"></i> Список групп</h5>
        <span style="color:var(--text-muted);font-size:0.9rem;">{{ $groups->count() }} {{ $groups->count() === 1 ? 'группа' : ($groups->count() < 5 ? 'группы' : 'групп') }}</span>
    </div>
    <div class="card-body p-0">
        @if($groups->isEmpty())
            <div class="text-center py-5" style="color:var(--text-muted)">
                <i class="bi bi-people" style="font-size:3rem;"></i>
                <p class="mt-3">Групп пока нет. Создайте первую группу.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-dark-custom mb-0">
                    <thead>
                        <tr>
                            <th>Название</th>
                            <th>Тренер</th>
                            <th>Участников</th>
                            <th>Цена / занятие</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($groups as $group)
                            <tr>
                                <td>
                                    <a href="{{ route('club.groups.show', $group) }}"
                                       style="color:var(--accent);text-decoration:none;font-weight:600;">
                                        {{ $group->name }}
                                    </a>
                                    @if($group->capacity)
                                        <span style="color:var(--text-muted);font-size:0.85rem;margin-left:6px;">(макс. {{ $group->capacity }})</span>
                                    @endif
                                </td>
                                <td style="color:var(--text-secondary)">
                                    {{ optional($group->coach)->name ?? '—' }}
                                </td>
                                <td>
                                    <span style="font-weight:600;">{{ $group->active_members_count }}</span>
                                    @if($group->capacity)
                                        <span style="color:var(--text-muted);font-size:0.85rem;">/ {{ $group->capacity }}</span>
                                    @endif
                                </td>
                                <td style="color:var(--text-secondary)">
                                    @if($group->price_per_session > 0)
                                        {{ number_format($group->price_per_session, 0, '.', ' ') }} ₸
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if($group->status === 'active')
                                        <span class="badge-success-custom">Активна</span>
                                    @else
                                        <span class="badge-secondary-custom">Архив</span>
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

@endsection

@section('modals')
<!-- Модал создания группы -->
<div id="createGroupModal"
     style="display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);"
     onclick="if(event.target===this)this.style.display='none'">
    <div class="card-dark" style="width:100%;max-width:520px;max-height:90vh;overflow-y:auto;margin:20px;">
        <div class="card-header">
            <h5><i class="bi bi-plus-circle"></i> Создать группу</h5>
            <button type="button" style="background:none;border:none;color:var(--text-muted);font-size:1.3rem;cursor:pointer;"
                    onclick="document.getElementById('createGroupModal').style.display='none'">
                <i class="bi bi-x"></i>
            </button>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('club.groups.store') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label">Название <span style="color:#ef4444">*</span></label>
                    <input type="text" name="name" class="form-control" required
                           placeholder="Например: Утренняя группа" value="{{ old('name') }}">
                </div>

                <div class="mb-3">
                    <label class="form-label">Тренер</label>
                    <select name="coach_id" class="form-select">
                        <option value="">— без тренера —</option>
                        @foreach($coaches as $coach)
                            <option value="{{ $coach->user_id }}" {{ old('coach_id') == $coach->user_id ? 'selected' : '' }}>
                                {{ $coach->user->name ?? 'Тренер #'.$coach->user_id }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <label class="form-label">Цена за занятие (₸)</label>
                        <input type="number" name="price_per_session" class="form-control" min="0" step="100"
                               placeholder="0" value="{{ old('price_per_session') }}">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Макс. участников</label>
                        <input type="number" name="capacity" class="form-control" min="1" max="100"
                               placeholder="Не ограничено" value="{{ old('capacity') }}">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Заметка</label>
                    <textarea name="note" class="form-control" rows="3"
                              placeholder="Дополнительная информация о группе...">{{ old('note') }}</textarea>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn-primary-custom flex-grow-1">
                        <i class="bi bi-check-lg"></i> Создать
                    </button>
                    <button type="button" class="btn-outline-custom"
                            onclick="document.getElementById('createGroupModal').style.display='none'">
                        Отмена
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

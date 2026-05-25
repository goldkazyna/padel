@extends('layouts.app')
@section('title', 'Журнал занятий')
@section('content')

<div class="container-fluid px-4 py-3">

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-journal-check fs-4 text-primary"></i>
            <h1 class="h4 mb-0">Журнал занятий</h1>
        </div>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createSessionModal">
            <i class="bi bi-plus-lg me-1"></i>Создать занятие
        </button>
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Filter form --}}
    <form method="GET" action="{{ route('club.groupSessions.index') }}" class="row g-2 mb-3">
        <div class="col-auto">
            <select name="group_id" class="form-select form-select-sm">
                <option value="">Все группы</option>
                @foreach($groups as $g)
                    <option value="{{ $g->id }}" @selected(request('group_id') == $g->id)>{{ $g->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="status" class="form-select form-select-sm">
                <option value="">Все статусы</option>
                <option value="planned" @selected(request('status') === 'planned')>Запланировано</option>
                <option value="held" @selected(request('status') === 'held')>Проведено</option>
                <option value="cancelled" @selected(request('status') === 'cancelled')>Отменено</option>
            </select>
        </div>
        <div class="col-auto">
            <input type="date" name="date" class="form-control form-control-sm" value="{{ request('date') }}">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-secondary btn-sm">Применить</button>
            <a href="{{ route('club.groupSessions.index') }}" class="btn btn-outline-secondary btn-sm">Сбросить</a>
        </div>
    </form>

    {{-- Table --}}
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Дата</th>
                            <th>Время</th>
                            <th>Группа</th>
                            <th>Тренер</th>
                            <th>Корт</th>
                            <th>Статус</th>
                            <th>Пришло</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sessions as $s)
                            <tr style="cursor:pointer" onclick="window.location='{{ route('club.groupSessions.show', $s) }}'">
                                <td>{{ $s->date->format('d.m.Y') }}</td>
                                <td>{{ $s->start_time }}–{{ $s->end_time }}</td>
                                <td>
                                    <a href="{{ route('club.groupSessions.show', $s) }}" class="text-decoration-none fw-semibold">
                                        {{ $s->group->name }}
                                    </a>
                                </td>
                                <td>{{ $s->coach?->name ?? '—' }}</td>
                                <td>{{ $s->court->name }}</td>
                                <td>
                                    @php
                                        $badgeClass = match($s->status) {
                                            'held' => 'bg-success',
                                            'cancelled' => 'bg-danger',
                                            default => 'bg-secondary',
                                        };
                                        $statusLabel = match($s->status) {
                                            'planned' => 'Запланировано',
                                            'held' => 'Проведено',
                                            'cancelled' => 'Отменено',
                                            default => $s->status,
                                        };
                                    @endphp
                                    <span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span>
                                </td>
                                <td>{{ $s->attended_count ?? 0 }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="bi bi-journal-x fs-3 d-block mb-2"></i>
                                    Занятий пока нет
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Pagination --}}
    @if($sessions->hasPages())
        <div class="mt-3">
            {{ $sessions->links() }}
        </div>
    @endif

</div>

{{-- Create session modal --}}
<div class="modal fade" id="createSessionModal" tabindex="-1" aria-labelledby="createSessionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('club.groupSessions.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="createSessionModalLabel">
                        <i class="bi bi-journal-plus me-2"></i>Создать занятие
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Группа <span class="text-danger">*</span></label>
                        <select name="group_id" class="form-select" required>
                            <option value="">— выберите группу —</option>
                            @foreach($groups as $g)
                                <option value="{{ $g->id }}">{{ $g->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Корт <span class="text-danger">*</span></label>
                        <select name="court_id" class="form-select" required>
                            <option value="">— выберите корт —</option>
                            @foreach($courts as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Дата <span class="text-danger">*</span></label>
                        <input type="date" name="date" class="form-control" required value="{{ now()->format('Y-m-d') }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Начало <span class="text-danger">*</span></label>
                        <input type="time" name="start_time" class="form-control" required value="10:00">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Слотов (продолжительность) <span class="text-danger">*</span></label>
                        <input type="number" name="slots" class="form-control" min="1" max="8" value="1" required>
                        <div class="form-text">1 слот = длительность слота корта (обычно 60 мин)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Тренер</label>
                        <select name="coach_id" class="form-select">
                            <option value="">— из группы или без тренера —</option>
                            @foreach($coaches as $cc)
                                @if($cc->user)
                                    <option value="{{ $cc->user_id }}">{{ $cc->user->name }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Создать
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

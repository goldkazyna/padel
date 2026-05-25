@extends('layouts.app')
@section('title', 'Занятие: ' . $session->group->name)
@section('content')

<div class="container-fluid px-4 py-3">

    {{-- Header --}}
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('club.groupSessions.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i>
        </a>
        <i class="bi bi-journal-check fs-4 text-primary"></i>
        <h1 class="h4 mb-0">{{ $session->group->name }}</h1>
        @php
            $badgeClass = match($session->status) {
                'held'      => 'bg-success',
                'cancelled' => 'bg-danger',
                default     => 'bg-secondary',
            };
            $statusLabel = match($session->status) {
                'planned'   => 'Запланировано',
                'held'      => 'Проведено',
                'cancelled' => 'Отменено',
                default     => $session->status,
            };
        @endphp
        <span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span>
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

    {{-- Session meta --}}
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-auto">
                    <i class="bi bi-calendar3 me-1 text-muted"></i>
                    <strong>{{ $session->date->format('d.m.Y') }}</strong>
                </div>
                <div class="col-auto">
                    <i class="bi bi-clock me-1 text-muted"></i>
                    {{ $session->start_time }}–{{ $session->end_time }}
                </div>
                <div class="col-auto">
                    <i class="bi bi-geo-alt me-1 text-muted"></i>
                    {{ $session->court->name }}
                </div>
                @if($session->coach)
                    <div class="col-auto">
                        <i class="bi bi-person-badge me-1 text-muted"></i>
                        {{ $session->coach->name }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if($session->status === 'planned')
        {{-- Conduct form --}}
        <form method="POST" action="{{ route('club.groupSessions.conduct', $session) }}">
            @csrf
            <div class="card shadow-sm mb-3">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-people me-2"></i>Отметить посещаемость</h6>
                </div>
                <div class="card-body p-0">
                    @if($members->isEmpty())
                        <p class="text-muted p-3 mb-0">В группе нет активных участников.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Участник</th>
                                        <th class="text-center">Остаток занятий</th>
                                        <th class="text-center">Пришёл</th>
                                        <th class="text-center">Списать</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($members as $m)
                                        @php $rem = $m->remaining; @endphp
                                        <tr class="{{ $rem <= 0 ? 'table-warning' : '' }}">
                                            <td>
                                                {{ $m->client->name }}
                                                @if($rem <= 0)
                                                    <span class="badge bg-warning text-dark ms-2">нужно продлить</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold {{ $rem <= 0 ? 'text-danger' : 'text-success' }}">{{ $rem }}</span>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox"
                                                    class="form-check-input"
                                                    name="attendance[{{ $m->id }}][attended]"
                                                    value="1"
                                                    id="att_{{ $m->id }}">
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox"
                                                    class="form-check-input"
                                                    name="attendance[{{ $m->id }}][charged]"
                                                    value="1"
                                                    id="chg_{{ $m->id }}"
                                                    {{ $rem <= 0 ? 'disabled' : '' }}>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>Провести занятие
                </button>
            </div>
        </form>

        {{-- Cancel form (separate) --}}
        <form method="POST" action="{{ route('club.groupSessions.cancel', $session) }}" class="mt-2"
              onsubmit="return confirm('Отменить занятие и освободить корт?')">
            @csrf
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-x-circle me-1"></i>Отменить занятие
            </button>
        </form>

    @elseif($session->status === 'held')
        {{-- Read-only attendance --}}
        <div class="card shadow-sm">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-people-fill me-2"></i>Посещаемость</h6>
            </div>
            <div class="card-body p-0">
                @if($members->isEmpty())
                    <p class="text-muted p-3 mb-0">Нет участников.</p>
                @else
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Участник</th>
                                    <th class="text-center">Пришёл</th>
                                    <th class="text-center">Списано</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($members as $m)
                                    @php $rec = $existing->get($m->id); @endphp
                                    <tr>
                                        <td>{{ $m->client->name }}</td>
                                        <td class="text-center">
                                            @if($rec && $rec->attended)
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                            @else
                                                <i class="bi bi-x-circle text-muted"></i>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($rec && $rec->charged)
                                                <i class="bi bi-check-circle-fill text-warning"></i>
                                            @else
                                                <span class="text-muted">—</span>
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

    @else
        {{-- Cancelled --}}
        <div class="alert alert-secondary">
            <i class="bi bi-slash-circle me-2"></i>Занятие отменено.
        </div>
    @endif

</div>

@endsection

@extends('layouts.app')
@section('title', $tournament->name)
@section('content')

<div class="container-fluid">
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <a href="{{ route('moderator.tournaments.index') }}" class="btn btn-outline-secondary btn-sm mb-2">
                <i class="bi bi-arrow-left me-1"></i>Назад
            </a>
            <h1>{{ $tournament->name }}</h1>
            <p class="text-muted">{{ $tournament->start_date->format('d.m.Y H:i') }}</p>
        </div>
    </div>

    @php
        $pending = $tournament->participants()->wherePivot('status', 'pending')->get();
        $registered = $tournament->participants()->wherePivot('status', 'registered')->get();
    @endphp

    {{-- Заявки на модерации --}}
    @if($pending->count() > 0)
    <div class="card-dark mb-4">
        <div class="card-header-dark">
            <h5 class="mb-0">
                <i class="bi bi-hourglass-split me-2 text-warning"></i>
                На модерации ({{ $pending->count() }})
            </h5>
        </div>
        <div class="card-body-dark">
            <div class="table-responsive">
                <table class="table table-dark">
                    <thead>
                        <tr>
                            <th>Игрок</th>
                            <th>Телефон</th>
                            <th>Уровень</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pending as $participant)
                        <tr>
                            <td>{{ $participant->full_name }}</td>
                            <td>{{ $participant->phone ? '+' . preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $participant->phone) : '—' }}</td>
                            <td>{{ $participant->level }}</td>
                            <td>
                                <form action="{{ route('moderator.tournaments.participants.approve', [$tournament, $participant->id]) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm" title="Одобрить">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <form action="{{ route('moderator.tournaments.participants.reject', [$tournament, $participant->id]) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm" title="Отклонить">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- Зарегистрированные --}}
    <div class="card-dark">
        <div class="card-header-dark">
            <h5 class="mb-0">
                <i class="bi bi-people me-2 text-success"></i>
                Зарегистрированы ({{ $registered->count() }}/{{ $tournament->max_participants }})
            </h5>
        </div>
        <div class="card-body-dark">
            @if($registered->count() > 0)
            <div class="table-responsive">
                <table class="table table-dark">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Игрок</th>
                            <th>Телефон</th>
                            <th>Уровень</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($registered as $index => $participant)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $participant->full_name }}</td>
                            <td>{{ $participant->phone ? '+' . preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $participant->phone) : '—' }}</td>
                            <td>{{ $participant->level }}</td>
                            <td>
                                <form action="{{ route('moderator.tournaments.participants.remove', [$tournament, $participant]) }}" method="POST" onsubmit="return confirm('Удалить участника?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Удалить">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <p class="text-muted text-center py-4">Пока нет зарегистрированных участников</p>
            @endif
        </div>
    </div>
</div>

@endsection
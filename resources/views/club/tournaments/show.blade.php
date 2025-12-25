@extends('layouts.app')

@section('title', $tournament->name)

@section('content')
<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club->name }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('club.tournaments.edit', $tournament) }}" class="btn-outline-custom">
            <i class="bi bi-pencil"></i> Редактировать
        </a>
        <a href="{{ route('club.tournaments.index') }}" class="btn-outline-custom">
            <i class="bi bi-arrow-left"></i> Назад
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card-dark">
            <div class="card-header">
                <h5><i class="bi bi-info-circle"></i> Информация</h5>
                <span class="badge-{{ $tournament->status_color }}-custom">{{ $tournament->status_name }}</span>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="text-secondary small">Дата</div>
                    <div>{{ $tournament->start_date->format('d.m.Y H:i') }}</div>
                </div>
                <div class="mb-3">
                    <div class="text-secondary small">Дедлайн</div>
                    <div>{{ $tournament->registration_deadline->format('d.m.Y H:i') }}</div>
                </div>
                <div class="mb-3">
                    <div class="text-secondary small">Уровень</div>
                    <div>{{ $tournament->min_level }} — {{ $tournament->max_level }}</div>
                </div>
                <div class="mb-3">
                    <div class="text-secondary small">Стоимость</div>
                    <div>{{ $tournament->price > 0 ? number_format($tournament->price, 0) . ' ₸' : 'Бесплатно' }}</div>
                </div>
                <div>
                    <div class="text-secondary small">Участников</div>
                    <div>{{ $tournament->participants->count() }} / {{ $tournament->max_participants }}</div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-8">
        <div class="card-dark">
            <div class="card-header">
                <h5><i class="bi bi-people"></i> Участники ({{ $tournament->participants->count() }})</h5>
            </div>
            <div class="card-body p-0">
                @if($tournament->participants->count() > 0)
                    <table class="table table-dark-custom mb-0">
                        <thead>
                            <tr>
                                <th>Игрок</th>
                                <th>Уровень</th>
                                <th>Рейтинг</th>
                                <th>Дата записи</th>
                                <th width="80"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tournament->participants as $participant)
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="user-avatar" style="width:36px;height:36px;font-size:0.85rem;">
                                                {{ strtoupper(substr($participant->first_name, 0, 1) . substr($participant->last_name, 0, 1)) }}
                                            </div>
                                            <div>
                                                <div class="fw-medium">{{ $participant->full_name }}</div>
                                                <small class="text-secondary">{{ $participant->email }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $participant->level }}</td>
                                    <td>{{ $participant->rating }}</td>
                                    <td class="text-secondary">{{ $participant->pivot->created_at->format('d.m.Y') }}</td>
                                    <td>
                                        <form action="{{ route('club.tournaments.participants.remove', [$tournament, $participant->id]) }}" 
                                              method="POST" onsubmit="return confirm('Удалить участника?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn-danger-custom btn-sm"><i class="bi bi-x"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="text-center text-secondary py-5">
                        <i class="bi bi-people fs-1 d-block mb-3 opacity-50"></i>
                        Пока нет участников
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
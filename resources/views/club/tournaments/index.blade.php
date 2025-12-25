@extends('layouts.app')

@section('title', 'Управление турнирами')

@section('content')
<div class="page-header">
    <div>
        <h2>Турниры {{ $club ? '— ' . $club->name : '' }}</h2>
        <p>Управление турнирами клуба</p>
    </div>
    <a href="{{ route('club.tournaments.create') }}" class="btn-primary-custom">
        <i class="bi bi-plus-circle"></i>
        <span>Создать турнир</span>
    </a>
</div>

<div class="card-dark">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark-custom mb-0">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Дата</th>
                        <th>Участники</th>
                        <th>Уровень</th>
                        <th>Статус</th>
                        <th width="150">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tournaments as $tournament)
                        <tr>
                            <td>
                                <div class="fw-medium">{{ $tournament->name }}</div>
                                @if(!$club)
                                    <small class="text-secondary">{{ $tournament->club->name }}</small>
                                @endif
                            </td>
                            <td class="text-secondary">{{ $tournament->start_date->format('d.m.Y H:i') }}</td>
                            <td>{{ $tournament->participants()->count() }}/{{ $tournament->max_participants }}</td>
                            <td class="text-secondary">{{ $tournament->min_level }}–{{ $tournament->max_level }}</td>
                            <td><span class="badge-{{ $tournament->status_color }}-custom">{{ $tournament->status_name }}</span></td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="{{ route('club.tournaments.show', $tournament) }}" class="btn-outline-custom btn-sm" title="Просмотр">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="{{ route('club.tournaments.edit', $tournament) }}" class="btn-outline-custom btn-sm" title="Редактировать">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    @if($tournament->status === 'draft')
                                        <form action="{{ route('club.tournaments.destroy', $tournament) }}" method="POST" onsubmit="return confirm('Удалить турнир?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn-danger-custom btn-sm" title="Удалить">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-5">
                                <i class="bi bi-trophy fs-1 d-block mb-3 opacity-50"></i>
                                Турниров пока нет
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
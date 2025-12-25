@extends('layouts.app')

@section('title', $tournament->name)

@section('content')
<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club->name }}</p>
    </div>
    <a href="{{ route('tournaments.index') }}" class="btn-outline-custom">
        <i class="bi bi-arrow-left"></i> Назад
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card-dark mb-4">
            <div class="card-header">
                <h5><i class="bi bi-info-circle"></i> Информация</h5>
                <span class="badge-{{ $tournament->status_color }}-custom">{{ $tournament->status_name }}</span>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="text-secondary mb-1">Дата и время</div>
                        <div>{{ $tournament->start_date->format('d.m.Y H:i') }}</div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-secondary mb-1">Дедлайн регистрации</div>
                        <div>{{ $tournament->registration_deadline->format('d.m.Y H:i') }}</div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-secondary mb-1">Место</div>
                        <div>{{ $tournament->club->address }}</div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-secondary mb-1">Стоимость</div>
                        <div>{{ $tournament->price > 0 ? number_format($tournament->price, 0) . ' ₸' : 'Бесплатно' }}</div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-secondary mb-1">Уровень</div>
                        <div>{{ $tournament->min_level }} — {{ $tournament->max_level }}</div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-secondary mb-1">Участников</div>
                        <div>{{ $tournament->participants->count() }} / {{ $tournament->max_participants }}</div>
                    </div>
                </div>
                
                @if($tournament->description)
                    <hr class="my-4" style="border-color: var(--border);">
                    <div class="text-secondary mb-1">Описание</div>
                    <div>{{ $tournament->description }}</div>
                @endif
            </div>
        </div>
        
        <!-- Участники -->
        <div class="card-dark">
            <div class="card-header">
                <h5><i class="bi bi-people"></i> Участники ({{ $tournament->participants->count() }})</h5>
            </div>
            <div class="card-body">
                @if($tournament->participants->count() > 0)
                    <div class="row g-3">
                        @foreach($tournament->participants as $participant)
                            <div class="col-sm-6">
                                <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background: var(--bg-secondary);">
                                    <div class="user-avatar">{{ strtoupper(substr($participant->first_name, 0, 1) . substr($participant->last_name, 0, 1)) }}</div>
                                    <div>
                                        <div class="fw-medium">{{ $participant->full_name }}</div>
                                        <small class="text-secondary">Уровень: {{ $participant->level }}</small>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-secondary mb-0">Пока нет участников</p>
                @endif
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card-dark">
            <div class="card-body">
                @if($tournament->isRegistered(auth()->user()))
                    <div class="text-center mb-3">
                        <i class="bi bi-check-circle fs-1 text-success"></i>
                        <div class="mt-2 fw-medium">Вы записаны!</div>
                    </div>
                    @if(!in_array($tournament->status, ['in_progress', 'completed']))
                        <form action="{{ route('tournaments.cancel', $tournament) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button class="btn-danger-custom w-100" onclick="return confirm('Отменить регистрацию?')">
                                Отменить запись
                            </button>
                        </form>
                    @endif
                @elseif($tournament->canRegister(auth()->user()))
                    <form action="{{ route('tournaments.register', $tournament) }}" method="POST">
                        @csrf
                        <button class="btn-primary-custom w-100">
                            <i class="bi bi-plus-circle me-2"></i>Записаться
                        </button>
                    </form>
                @else
                    <div class="text-center text-secondary">
                        <i class="bi bi-lock fs-1"></i>
                        <div class="mt-2">Регистрация недоступна</div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
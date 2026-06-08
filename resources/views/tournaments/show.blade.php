@extends('layouts.app')

@section('title', $tournament->name)

@section('content')
<div class="page-header">
    <div>
        <h2>{{ $tournament->name }}</h2>
        <p>{{ $tournament->club?->name ?? 'Личный турнир' }}</p>
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
                        <div class="text-secondary mb-1">Формат</div>
                        <div>{{ $tournament->type_name }}</div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-secondary mb-1">Место</div>
                        <div>{{ $tournament->club?->address ?? '—' }}</div>
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
			<div class="card-body-dark">
				@php
					$user = auth()->user();
					$participantStatus = $tournament->getParticipantStatus($user);
				@endphp

				{{-- Статус: Одобрен --}}
				@if($participantStatus === 'registered')
					<div class="status-card approved">
						<div class="status-icon">
							<i class="bi bi-check-circle-fill"></i>
						</div>
						<div class="status-title">Вы записаны!</div>
						<div class="status-text">Ваша заявка одобрена организатором</div>
					</div>
					@if(!in_array($tournament->status, ['in_progress', 'completed']))
						<form action="{{ route('tournaments.cancel', $tournament) }}" method="POST" class="mt-3">
							@csrf
							@method('DELETE')
							<button class="btn-danger-custom w-100" onclick="return confirm('Отменить регистрацию?')">
								<i class="bi bi-x-circle"></i> Отменить регистрацию
							</button>
						</form>
					@endif

				{{-- Статус: На модерации --}}
				@elseif($participantStatus === 'pending')
					<div class="status-card pending">
						<div class="status-icon">
							<i class="bi bi-hourglass-split"></i>
						</div>
						<div class="status-title">Заявка на модерации</div>
						<div class="status-text">Ожидайте подтверждения от организатора</div>
					</div>
					<form action="{{ route('tournaments.cancel', $tournament) }}" method="POST" class="mt-3">
						@csrf
						@method('DELETE')
						<button class="btn-outline-custom w-100" onclick="return confirm('Отменить заявку?')">
							<i class="bi bi-x-circle"></i> Отменить заявку
						</button>
					</form>

				{{-- Можно записаться --}}
				@elseif($tournament->canRegister($user))
					<div class="status-card available">
						<div class="status-icon">
							<i class="bi bi-calendar-check"></i>
						</div>
						<div class="status-title">Запись открыта</div>
						<div class="status-text">Осталось мест: {{ $tournament->max_participants - $tournament->approvedParticipantsCount() }}</div>
					</div>
					<form action="{{ route('tournaments.register', $tournament) }}" method="POST" class="mt-3">
						@csrf
						<button class="btn-primary-custom w-100">
							<i class="bi bi-send"></i> Подать заявку
						</button>
					</form>
					<p class="text-secondary text-center mt-2 mb-0" style="font-size: 0.85rem;">
						Заявка будет рассмотрена организатором
					</p>

				{{-- Нельзя записаться --}}
				@else
					@php $blockReason = $tournament->getRegistrationBlockReason($user); @endphp
					<div class="status-card blocked">
						<div class="status-icon">
							<i class="bi bi-slash-circle"></i>
						</div>
						<div class="status-title">Запись недоступна</div>
						<div class="status-text">{{ $blockReason ?? 'Регистрация закрыта' }}</div>
					</div>
				@endif
			</div>
		</div>
        
        <!-- Ваш уровень -->
        <div class="card-dark mt-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-secondary">Ваш уровень:</span>
                    <span class="badge-success-custom">{{ auth()->user()->level }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span class="text-secondary">Требуется:</span>
                    <span>{{ $tournament->min_level }} — {{ $tournament->max_level }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
.status-card {
    text-align: center;
    padding: 24px;
    border-radius: 12px;
}

.status-card .status-icon {
    font-size: 3rem;
    margin-bottom: 12px;
}

.status-card .status-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 4px;
}

.status-card .status-text {
    font-size: 0.9rem;
    color: var(--text-secondary);
}

.status-card.approved {
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
}

.status-card.approved .status-icon,
.status-card.approved .status-title {
    color: #22c55e;
}

.status-card.pending {
    background: rgba(234, 179, 8, 0.1);
    border: 1px solid rgba(234, 179, 8, 0.3);
}

.status-card.pending .status-icon,
.status-card.pending .status-title {
    color: #eab308;
}

.status-card.available {
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.3);
}

.status-card.available .status-icon {
    color: #3b82f6;
}

.status-card.blocked {
    background: rgba(107, 114, 128, 0.1);
    border: 1px solid rgba(107, 114, 128, 0.3);
}

.status-card.blocked .status-icon {
    color: #6b7280;
}
</style>
@endsection
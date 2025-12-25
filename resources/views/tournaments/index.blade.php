@extends('layouts.app')

@section('title', 'Турниры')

@section('content')
<div class="page-header">
    <div>
        <h2>Турниры</h2>
        <p>Найди турнир и запишись</p>
    </div>
</div>

<div class="row g-4">
    @forelse($tournaments as $tournament)
        <div class="col-lg-6">
            <div class="card-dark h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 class="mb-1">{{ $tournament->name }}</h5>
                            <small class="text-secondary">{{ $tournament->club->name }}</small>
                        </div>
                        <span class="badge-{{ $tournament->status_color }}-custom">{{ $tournament->status_name }}</span>
                    </div>
                    
                    <div class="mb-3 text-secondary">
                        <div class="mb-1"><i class="bi bi-calendar3 me-2"></i>{{ $tournament->start_date->format('d.m.Y H:i') }}</div>
                        <div class="mb-1"><i class="bi bi-geo-alt me-2"></i>{{ $tournament->club->address }}</div>
                        <div class="mb-1"><i class="bi bi-people me-2"></i>{{ $tournament->participants->count() }}/{{ $tournament->max_participants }} участников</div>
                        <div><i class="bi bi-bar-chart me-2"></i>Уровень: {{ $tournament->min_level }} — {{ $tournament->max_level }}</div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <a href="{{ route('tournaments.show', $tournament) }}" class="btn-outline-custom">Подробнее</a>
                        @if($tournament->isRegistered(auth()->user()))
                            <span class="badge-success-custom d-flex align-items-center">Вы записаны</span>
                        @elseif($tournament->canRegister(auth()->user()))
                            <form action="{{ route('tournaments.register', $tournament) }}" method="POST">
                                @csrf
                                <button class="btn-primary-custom">Записаться</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="card-dark">
                <div class="card-body text-center py-5">
                    <i class="bi bi-trophy fs-1 text-secondary mb-3"></i>
                    <p class="text-secondary mb-0">Пока нет доступных турниров</p>
                </div>
            </div>
        </div>
    @endforelse
</div>
@endsection